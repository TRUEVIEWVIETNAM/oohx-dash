<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tẩy dữ liệu nhạy cảm khỏi một bản sao CSDL production — bước 6 của lát 4
 * trong `docs/PLAN-MOI-TRUONG-DEV.md`.
 *
 * ══ Vì sao là một lệnh, không phải mấy câu SQL gõ tay ══
 *
 * Bước 6 của plan viết gọn: "Sao từ production rồi **tẩy**: `bank_*`,
 * `tax_code`, `business_license_path`, email người mua." Bốn thứ. Đếm trong
 * schema thì ra **ba mươi lăm cột trên bảy bảng, cộng tám bảng phải dọn
 * sạch** — và ba trong số đó không nằm ở chỗ ai cũng nghĩ tới:
 *
 *   · `public_reflections.contact_*` là liên hệ của người dân thật đã gửi phản
 *     ánh. Họ không phải khách hàng, không đăng ký gì, và không ai hỏi họ về
 *     việc dữ liệu của họ được sao sang môi trường thử.
 *   · `quote_requests.contact_*` đúng là "email người mua" mà plan nói — nhưng
 *     `contact_email` là NOT NULL, nên `SET NULL` sẽ đổ ngay giữa đường và để
 *     lại một CSDL tẩy nửa vời.
 *   · `failed_jobs.payload` chứa toàn bộ dữ liệu của job đã hỏng, kể cả thân
 *     email. Nó là một kho rò rỉ nằm im.
 *
 * Gõ tay ba mươi lăm câu UPDATE là ba mươi lăm dịp bỏ sót một cột, và cái bỏ
 * sót ấy không ai thấy — CSDL dev vẫn chạy y nguyên khi còn nguyên
 * `bank_account_number`.
 *
 * ══ Lệnh này tự kiểm, và đỏ khi tẩy chưa sạch ══
 *
 * Sau khi ghi, nó đếm lại từng cột. Còn một hàng bẩn thì thoát khác 0. Không
 * tin vào "lệnh đã chạy xong nên chắc là xong".
 */
class TayDuLieuDev extends Command
{
    protected $signature = 'oohx:tay-du-lieu-dev
                            {--dem : Chỉ đếm số hàng còn bẩn, không ghi gì}
                            {--mo-khoa= : Email THẬT của một tài khoản; sau khi tẩy, lệnh đặt lại mật khẩu cho tài khoản đó và in ra một lần}';

    protected $description = 'Tẩy dữ liệu nhạy cảm khỏi bản sao CSDL dev (lát 4 của PLAN-MOI-TRUONG-DEV)';

    /** Host được coi là cục bộ. Giống `GiaLapKho` để hai lệnh không lệch luật. */
    private const HOST_CHO_PHEP = ['127.0.0.1', 'localhost', '::1', 'host.docker.internal', 'mysql'];

    /**
     * Danh sách TRẮNG tên CSDL. Chặn theo chiều "chỉ cho phép cái đã biết",
     * không theo chiều "cấm cái đã biết là xấu": thêm một môi trường mới thì
     * phải sửa dòng này, còn quên sửa thì lệnh từ chối chạy — hỏng về phía an
     * toàn.
     */
    private const CSDL_CHO_PHEP = ['dev_oohx_net', 'oohx_test', 'oohx_ci', ':memory:'];

    /**
     * Tám bảng dọn sạch thay vì tẩy từng cột.
     *
     * Chúng là bảng phù du: phiên, token, lời mời, job. Không một hàng nào
     * trong đó cần thiết để dev chạy, và mỗi hàng đều mang hoặc một bí mật
     * (token), hoặc một dấu vết người thật (IP, user agent, email), hoặc cả
     * hai (`failed_jobs.payload`).
     */
    private const BANG_DON = [
        'sessions',
        'password_reset_tokens',
        'personal_access_tokens',
        'user_invitations',
        'failed_jobs',
        'jobs',
        'cache',
        'cache_locks',
    ];

    public function handle(): int
    {
        if (($ma = $this->chanProduction()) !== 0) {
            return $ma;
        }

        $chi_dem = (bool) $this->option('dem');
        $mo_khoa = (string) ($this->option('mo-khoa') ?? '');

        // Phải tra TRƯỚC khi tẩy: sau khi tẩy thì email thật không còn trong
        // bảng để tra nữa.
        $id_mo_khoa = null;

        if ($mo_khoa !== '') {
            $id_mo_khoa = DB::table('users')->where('email', $mo_khoa)->value('id');

            if ($id_mo_khoa === null) {
                $this->error("Không có tài khoản nào với email '{$mo_khoa}'.");

                return self::FAILURE;
            }
        }

        $this->line('CSDL : ' . $this->tenCsdl() . ' @ ' . $this->host());
        $this->newLine();

        $ban_dau = $this->demBan();

        if ($chi_dem) {
            $this->bangDem($ban_dau, 'Số hàng còn bẩn (chưa ghi gì)');

            return self::SUCCESS;
        }

        $this->tay();
        $this->don();

        $con_lai = $this->demBan();

        $this->bangSoSanh($ban_dau, $con_lai);

        $tong_con = array_sum($con_lai);

        if ($tong_con > 0) {
            $this->error("Tẩy CHƯA SẠCH: còn {$tong_con} hàng bẩn. Xem bảng trên.");

            return self::FAILURE;
        }

        $this->info('Tẩy sạch: mọi cột đã kiểm lại và trả 0.');

        if ($id_mo_khoa !== null) {
            $this->moKhoa((int) $id_mo_khoa);
        }

        return self::SUCCESS;
    }

    /**
     * Ba lớp chặn, và không lớp nào là một câu hỏi cho người gõ.
     *
     * `.env` của dự án trỏ vào CSDL production. Một lệnh tẩy chạy nhầm ở đó
     * không phải "mất dữ liệu thử" — nó là xoá sổ ngân hàng của mọi media
     * owner trên sàn, và bản sao lưu gần nhất là chỗ duy nhất lấy lại được.
     */
    private function chanProduction(): int
    {
        if (app()->environment('production')) {
            $this->error('APP_ENV=production. Lệnh này không chạy ở đó.');
            $this->line('Môi trường dev phải khai APP_ENV khác "production" trong `.env` của nó.');

            return self::FAILURE;
        }

        $csdl = $this->tenCsdl();

        if (! in_array($csdl, self::CSDL_CHO_PHEP, true) && ! str_starts_with($csdl, 'oohx_test')) {
            $this->error("DB_DATABASE = '{$csdl}' không nằm trong danh sách trắng.");
            $this->line('Chỉ tẩy trên: ' . implode(', ', self::CSDL_CHO_PHEP) . ', hoặc tên bắt đầu bằng "oohx_test".');

            return self::FAILURE;
        }

        // Chặn riêng theo tên production, dù lớp trên đã phủ. Danh sách trắng
        // có thể bị ai đó nới ra vì một lý do nghe hợp lý; dòng này thì chỉ có
        // một nghĩa.
        if (str_contains($csdl, 'dash_oohx')) {
            $this->error("DB_DATABASE = '{$csdl}' là tên CSDL production.");

            return self::FAILURE;
        }

        $host = $this->host();

        if (! in_array($host, self::HOST_CHO_PHEP, true) && ! str_starts_with($host, 'oohx-test-')) {
            $this->error("DB_HOST = '{$host}' không phải CSDL cục bộ.");
            $this->line('Bản sao dev phải nằm trên cùng máy với lệnh này.');

            return self::FAILURE;
        }

        return 0;
    }

    /**
     * Kế hoạch tẩy: bảng → cột → giá trị đặt vào, và cách đếm "còn bẩn".
     *
     * `ban` là điều kiện của một hàng CHƯA được tẩy. Mặc định là "cột khác
     * NULL". Cột NOT NULL thì không đặt NULL được, nên nó có điều kiện riêng.
     *
     * @return list<array{nhan: string, bang: string, cot: array<string, array{dat: mixed, ban?: callable}>}>
     */
    protected function keHoach(): array
    {
        return [
            [
                // Mười cột trong danh sách "không bao giờ lộ" của CLAUDE.md,
                // cộng `billing_info`. Đặt NULL hết: dev không cần một con số
                // tài khoản nào để chạy đúng.
                'nhan' => 'owners · pháp lý + ngân hàng',
                'bang' => 'owners',
                'cot'  => [
                    'tax_code'              => ['dat' => null],
                    'tax_code_issued_on'    => ['dat' => null],
                    'tax_code_issued_by'    => ['dat' => null],
                    'legal_representative'  => ['dat' => null],
                    'business_license_path' => ['dat' => null],
                    'bank_name'             => ['dat' => null],
                    'bank_account_number'   => ['dat' => null],
                    'bank_account_name'     => ['dat' => null],
                    'bank_branch'           => ['dat' => null],
                    'billing_info'          => ['dat' => null],
                ],
            ],
            [
                // Tên và email của người thật. `email` là UNIQUE nên không
                // đặt chung một giá trị được — `CONCAT` theo `id` vừa giữ
                // được ràng buộc, vừa cho phép tra ngược "hàng này là user
                // nào" khi gỡ lỗi ở dev.
                //
                // `.invalid` là TLD được RFC 2606 giữ riêng cho việc này: nó
                // không bao giờ phân giải, nên một lần gửi mail nhầm ở dev
                // cũng không tới được ai.
                'nhan' => 'users · người thật',
                'bang' => 'users',
                'cot'  => [
                    'email' => [
                        'dat' => DB::raw("CONCAT('u', id, '@dev.invalid')"),
                        'ban' => fn (Builder $q) => $q->where('email', 'not like', '%@dev.invalid'),
                    ],
                    'name' => [
                        'dat' => DB::raw("CONCAT('Người dùng ', id)"),
                        'ban' => fn (Builder $q) => $q->where('name', 'not like', 'Người dùng %'),
                    ],
                    'remember_token' => ['dat' => null],
                ],
            ],
            [
                // Tổ chức người mua: mã số thuế và địa chỉ xuất hoá đơn là
                // cặp song sinh phía người mua của `owners.tax_code`.
                // `credit_limit` và `payment_terms_days` KHÔNG tẩy: chúng là
                // điều khoản thương mại mà dev cần để chạy đúng, không phải
                // danh tính của ai.
                'nhan' => 'organizations · hoá đơn',
                'bang' => 'organizations',
                'cot'  => [
                    'tax_id'          => ['dat' => null],
                    'billing_address' => ['dat' => null],
                    'billing_email'   => ['dat' => null],
                    'billing_phone'   => ['dat' => null],
                ],
            ],
            [
                // Người dân gửi phản ánh. `subject`, `content` và
                // `resolution` KHÔNG tẩy — chúng được công khai cùng phản ánh,
                // nên chúng không phải một chỗ lộ của riêng dev.
                // `internal_notes` thì ngược lại: nội bộ, và là chỗ người xử
                // lý ghi lại những thứ không bao giờ định công khai.
                'nhan' => 'public_reflections · người gửi',
                'bang' => 'public_reflections',
                'cot'  => [
                    'contact_name'   => ['dat' => null],
                    'contact_email'  => ['dat' => null],
                    'contact_phone'  => ['dat' => null],
                    'internal_notes' => ['dat' => null],
                    'submitted_ip'   => ['dat' => null],
                ],
            ],
            [
                // Yêu cầu báo giá — đây đúng là "email người mua" của plan.
                // `contact_name` và `contact_email` là NOT NULL, nên hai cột
                // này nhận giá trị thay thế chứ không nhận NULL.
                //
                // `notes` là lời tự do của người gửi. Tẩy luôn, vì đó là chỗ
                // số điện thoại và tên người hay nằm mà không ai ngờ — và một
                // bản brief chiến dịch thật thì dev không cần.
                'nhan' => 'quote_requests · người mua',
                'bang' => 'quote_requests',
                'cot'  => [
                    'contact_name' => [
                        'dat' => DB::raw("CONCAT('Khách ', code)"),
                        'ban' => fn (Builder $q) => $q->where('contact_name', 'not like', 'Khách %'),
                    ],
                    'contact_email' => [
                        'dat' => DB::raw("CONCAT('ycb-', id, '@dev.invalid')"),
                        'ban' => fn (Builder $q) => $q->where('contact_email', 'not like', '%@dev.invalid'),
                    ],
                    'contact_phone'   => ['dat' => null],
                    'contact_company' => ['dat' => null],
                    'notes'           => ['dat' => null],
                    'internal_notes'  => ['dat' => null],
                    'submitted_ip'    => ['dat' => null],
                    'user_agent'      => ['dat' => null],
                ],
            ],
            [
                // Bí mật của thiết bị, và ghi chú nội bộ về vị trí.
                //
                // Một `device_token` còn sống trong CSDL dev là một đường gửi
                // impression giả vào đúng màn hình THẬT, nếu ai đó lấy được
                // nó — dev và production nói chuyện với cùng một đội thiết bị
                // ngoài đường.
                //
                // `internal_notes` là ô tự do mà người vận hành ghi vào, nên
                // nó là chỗ tên và số điện thoại của chủ mặt bằng hay nằm.
                'nhan' => 'screens · bí mật thiết bị',
                'bang' => 'screens',
                'cot'  => [
                    'device_token'   => ['dat' => null],
                    'internal_notes' => ['dat' => null],
                ],
            ],
            [
                // Bằng chứng chấp thuận chính sách: hàng ở lại, dấu vết đi.
                //
                // KHÔNG xoá hàng. `policy_consents` là bằng chứng pháp lý
                // (ai, bản nào, lúc nào) và `/explore` lẫn luồng đặt chỗ đọc
                // nó để biết có phải hỏi lại hay không — xoá đi là dev chạy
                // một luồng khác production.
                //
                // Nhưng `ip` và `user_agent` thì chỉ là dấu vết của người
                // thật, không một dòng mã nào đọc tới.
                'nhan' => 'policy_consents · dấu vết',
                'bang' => 'policy_consents',
                'cot'  => [
                    'ip'         => ['dat' => null],
                    'user_agent' => ['dat' => null],
                ],
            ],
        ];
    }

    /**
     * Đếm số hàng còn bẩn, theo từng cột.
     *
     * Khoá là `bảng.cột` để bảng kết quả chỉ đúng chỗ còn sót, không chỉ nói
     * "còn 3 hàng" ở đâu đó.
     *
     * @return array<string, int>
     */
    private function demBan(): array
    {
        $dem = [];

        foreach ($this->keHoach() as $nhom) {
            if (! Schema::hasTable($nhom['bang'])) {
                continue;
            }

            foreach ($nhom['cot'] as $cot => $luat) {
                if (! Schema::hasColumn($nhom['bang'], $cot)) {
                    continue;
                }

                $q = DB::table($nhom['bang']);

                if (isset($luat['ban'])) {
                    $luat['ban']($q);
                } else {
                    $q->whereNotNull($cot);
                }

                $dem[$nhom['bang'] . '.' . $cot] = $q->count();
            }
        }

        foreach (self::BANG_DON as $bang) {
            if (Schema::hasTable($bang)) {
                $dem[$bang . ' (dọn sạch)'] = DB::table($bang)->count();
            }
        }

        return $dem;
    }

    /**
     * Ghi: một UPDATE cho mỗi bảng, mọi cột của bảng đó cùng lúc.
     *
     * `protected`, không `private`, và đó là cố ý: `TayDuLieuDevTest` phủ nó
     * bằng một lớp con rỗng để chứng minh phép tự kiểm ở cuối `handle()` thật
     * sự đỏ khi tẩy chưa sạch. Không có khe đó thì lời hứa "lệnh tự kiểm" chỉ
     * là một lời hứa.
     */
    protected function tay(): void
    {
        foreach ($this->keHoach() as $nhom) {
            if (! Schema::hasTable($nhom['bang'])) {
                $this->line("   bỏ qua {$nhom['nhan']}: bảng không tồn tại.");

                continue;
            }

            $dat = [];

            foreach ($nhom['cot'] as $cot => $luat) {
                if (Schema::hasColumn($nhom['bang'], $cot)) {
                    $dat[$cot] = $luat['dat'];
                }
            }

            if ($dat === []) {
                continue;
            }

            DB::table($nhom['bang'])->update($dat);
        }

        // Mật khẩu: KHÔNG đặt một hash biết trước, vì một hash biết trước là
        // một cửa sau có thật — ai đọc được repo này là đăng nhập được vào mọi
        // tài khoản trên dev. Băm một chuỗi ngẫu nhiên rồi bỏ đi: không ai,
        // kể cả người chạy lệnh, biết mật khẩu nào khớp.
        //
        // Hash production để trên một máy canh nhẹ hơn là vật liệu để dò
        // offline mật khẩu THẬT của người dùng thật — những mật khẩu họ rất có
        // thể dùng lại ở nơi khác.
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'password')) {
            DB::table('users')->update(['password' => Hash::make(Str::random(64))]);
        }
    }

    /** Dọn: `delete` chứ không `truncate`, để khoá ngoại được tôn trọng. */
    private function don(): void
    {
        foreach (self::BANG_DON as $bang) {
            if (Schema::hasTable($bang)) {
                DB::table($bang)->delete();
            }
        }
    }

    /**
     * Mở lại một đường đăng nhập, vì sau khi tẩy thì KHÔNG CÒN đường nào.
     *
     * Mật khẩu sinh tại đây và in ra đúng một lần. Nó không được ghi vào tệp
     * nào, không vào log, không vào repo.
     */
    private function moKhoa(int $id): void
    {
        $email = 'admin@dev.invalid';
        $mat   = Str::password(20, symbols: false);

        DB::table('users')->where('id', $id)->update([
            'email'    => $email,
            'password' => Hash::make($mat),
        ]);

        $this->newLine();
        $this->line('── Đường đăng nhập của dev ──────────────────────────────');
        $this->line("   email       : {$email}");
        $this->line("   mật khẩu    : {$mat}");
        $this->line('   In một lần. Không ghi ở đâu cả — chạy lại lệnh với');
        $this->line('   --mo-khoa nếu mất.');
        $this->line('─────────────────────────────────────────────────────────');
    }

    /** @param array<string, int> $dem */
    private function bangDem(array $dem, string $tieu_de): void
    {
        $this->line($tieu_de);
        $this->newLine();

        foreach ($dem as $cot => $so) {
            $this->line(sprintf('  %-42s %s', $cot, number_format($so)));
        }

        $this->newLine();
        $this->line(sprintf('  %-42s %s', 'TỔNG', number_format(array_sum($dem))));
    }

    /**
     * @param array<string, int> $truoc
     * @param array<string, int> $sau
     */
    private function bangSoSanh(array $truoc, array $sau): void
    {
        $this->line(sprintf('  %-42s %8s %8s', 'cột', 'trước', 'sau'));
        $this->line('  ' . str_repeat('─', 60));

        foreach ($truoc as $cot => $so) {
            $con = $sau[$cot] ?? 0;
            $this->line(sprintf(
                '  %-42s %8s %8s%s',
                $cot,
                number_format($so),
                number_format($con),
                $con > 0 ? '  ← CÒN BẨN' : '',
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            '  %-42s %8s %8s',
            'TỔNG',
            number_format(array_sum($truoc)),
            number_format(array_sum($sau)),
        ));
        $this->newLine();
    }

    private function tenCsdl(): string
    {
        return (string) config('database.connections.' . config('database.default') . '.database');
    }

    private function host(): string
    {
        return (string) config('database.connections.' . config('database.default') . '.host');
    }
}
