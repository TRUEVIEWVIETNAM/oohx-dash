<?php

namespace Tests\Feature\Console;

use App\Console\Commands\TayDuLieuDev;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `oohx:tay-du-lieu-dev` — bước 6 của lát 4 (`docs/PLAN-MOI-TRUONG-DEV.md`).
 *
 * Hai nửa, và nửa thứ hai mới là nửa khó.
 *
 * **Nửa chặn** giống `GiaLapKhoChanProductionTest`: `.env` của dự án trỏ vào
 * CSDL production, nên một lệnh tẩy chạy nhầm ở đó là xoá sổ ngân hàng của
 * mọi media owner trên sàn. Khác một chỗ: lệnh này chặn theo **danh sách
 * trắng** tên CSDL, không theo danh sách đen. Ca
 * `test_ten_csdl_la_bi_chan_du_vo_hai` canh đúng chiều đó — một tên không
 * giống production chút nào vẫn bị từ chối, vì "không giống production" không
 * phải là "đã biết là an toàn".
 *
 * **Nửa tẩy** canh từng cột một, và canh cả hai chiều: cột nào phải sạch, và
 * cột nào phải CÒN NGUYÊN. Chiều thứ hai dễ bị bỏ quên, nhưng một lệnh tẩy
 * quá tay cũng là một lệnh hỏng: `public_reflections.content` bị xoá là dev
 * không còn phản ánh nào để kiểm trang công khai, và
 * `policy_consents` bị xoá hàng là dev chạy một luồng đặt chỗ khác
 * production.
 */
class TayDuLieuDevTest extends TestCase
{
    use RefreshDatabase;

    // ══════════════════════════════════════════════════════════════════════
    // Nửa một: ba lớp chặn
    // ══════════════════════════════════════════════════════════════════════

    public function test_ten_csdl_production_bi_chan(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'dash_oohx_net');

        $owner = Owner::factory()->create(['bank_account_number' => '0123456789']);

        $this->artisan('oohx:tay-du-lieu-dev')
            ->expectsOutputToContain('không nằm trong danh sách trắng')
            ->assertFailed();

        $this->assertSame('0123456789', DB::table('owners')->where('id', $owner->id)->value('bank_account_number'));
    }

    /**
     * Danh sách TRẮNG, không phải danh sách đen.
     *
     * `oohx_khac` không giống tên production chút nào. Nó vẫn bị từ chối, và
     * đó là điểm: thêm một môi trường mới thì phải sửa hằng số trong lệnh —
     * quên sửa thì lệnh không chạy, chứ không phải lệnh chạy ở chỗ lạ.
     */
    public function test_ten_csdl_la_bi_chan_du_vo_hai(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'oohx_khac');

        $this->artisan('oohx:tay-du-lieu-dev')
            ->expectsOutputToContain('không nằm trong danh sách trắng')
            ->assertFailed();
    }

    public function test_host_khong_cuc_bo_bi_chan(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', '172.104.188.62');

        $owner = Owner::factory()->create(['tax_code' => '0101234567']);

        $this->artisan('oohx:tay-du-lieu-dev')
            ->expectsOutputToContain('không phải CSDL cục bộ')
            ->assertFailed();

        $this->assertSame('0101234567', DB::table('owners')->where('id', $owner->id)->value('tax_code'));
    }

    /**
     * `--dem` phải đọc mà không ghi.
     *
     * Đây là lối duy nhất để xem trước một CSDL lạ, nên nó phải an toàn tuyệt
     * đối: người gõ sẽ dùng nó đúng vào lúc chưa biết mình đang ở đâu.
     */
    public function test_dem_khong_ghi_gi(): void
    {
        $owner = Owner::factory()->create([
            'bank_account_number' => '0123456789',
            'tax_code'            => '0101234567',
        ]);

        $this->artisan('oohx:tay-du-lieu-dev', ['--dem' => true])
            ->assertSuccessful();

        $hang = DB::table('owners')->where('id', $owner->id)->first();

        $this->assertSame('0123456789', $hang->bank_account_number);
        $this->assertSame('0101234567', $hang->tax_code);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Nửa hai: tẩy đúng cột, và chỉ đúng cột đó
    // ══════════════════════════════════════════════════════════════════════

    public function test_owner_sach_ca_muoi_cot_phap_ly_va_ngan_hang(): void
    {
        $owner = Owner::factory()->create([
            'tax_code'              => '0101234567',
            'tax_code_issued_on'    => '2020-01-15',
            'tax_code_issued_by'    => 'Cục Thuế Hà Nội',
            'legal_representative'  => 'Nguyễn Văn A',
            'business_license_path' => 'licenses/gpkd-that.pdf',
            'bank_name'             => 'Vietcombank',
            'bank_account_number'   => '0123456789',
            'bank_account_name'     => 'CONG TY TNHH ABC',
            'bank_branch'           => 'Chi nhánh Ba Đình',
            'billing_info'          => ['dia_chi' => '12 Phố Thật, Hà Nội'],
        ]);

        $this->artisan('oohx:tay-du-lieu-dev')->assertSuccessful();

        $hang = (array) DB::table('owners')->where('id', $owner->id)->first();

        foreach ([
            'tax_code', 'tax_code_issued_on', 'tax_code_issued_by',
            'legal_representative', 'business_license_path',
            'bank_name', 'bank_account_number', 'bank_account_name',
            'bank_branch', 'billing_info',
        ] as $cot) {
            $this->assertNull($hang[$cot], "owners.{$cot} còn dữ liệu sau khi tẩy");
        }

        // Tên và slug của owner KHÔNG tẩy: đó là doanh nghiệp công khai, đã
        // có trang riêng trên sàn, và dev cần chúng để nhận ra mình đang xem
        // vị trí của ai.
        $this->assertNotNull($hang['name']);
        $this->assertNotNull($hang['slug']);
    }

    public function test_email_va_ten_nguoi_dung_bi_doi(): void
    {
        $u = User::factory()->create([
            'name'           => 'Trần Thị Thật',
            'email'          => 'nguoi.that@gmail.com',
            'remember_token' => Str::random(60),
        ]);

        // Người thứ hai không phải để cho đủ: `users.email` là UNIQUE, nên
        // đặt cùng một giá trị cho mọi hàng là một lệnh đổ giữa đường. Hai
        // hàng là số ít nhất chứng minh được điều đó.
        $v = User::factory()->create(['email' => 'nguoi.khac@gmail.com']);

        $this->artisan('oohx:tay-du-lieu-dev')->assertSuccessful();

        $hang = DB::table('users')->where('id', $u->id)->first();

        $this->assertSame("u{$u->id}@dev.invalid", $hang->email);
        $this->assertSame("Người dùng {$u->id}", $hang->name);
        $this->assertNull($hang->remember_token);

        $khac = DB::table('users')->where('id', $v->id)->value('email');

        $this->assertSame("u{$v->id}@dev.invalid", $khac);
        $this->assertNotSame($hang->email, $khac);
    }

    /**
     * Hash production là vật liệu dò mật khẩu THẬT của người thật — những mật
     * khẩu họ rất có thể dùng lại ở nơi khác. Nên nó phải đi, và cái thay vào
     * KHÔNG được là một giá trị biết trước: một hash biết trước trong repo là
     * một cửa sau vào mọi tài khoản trên dev.
     */
    public function test_hash_mat_khau_production_khong_con_khop(): void
    {
        $u = User::factory()->create(['password' => Hash::make('mat-khau-that-cua-nguoi-ta')]);

        $truoc = DB::table('users')->where('id', $u->id)->value('password');

        $this->artisan('oohx:tay-du-lieu-dev')->assertSuccessful();

        $sau = DB::table('users')->where('id', $u->id)->value('password');

        $this->assertNotSame($truoc, $sau);
        $this->assertFalse(Hash::check('mat-khau-that-cua-nguoi-ta', $sau));

        // Và cái thay vào không phải một giá trị biết trước. Không ca test
        // nào chứng minh được "không ai đoán được" cho mọi chuỗi, nhưng hai
        // chuỗi dưới đây là hai thứ người ta thực sự đặt vào chỗ này: mật
        // khẩu mặc định của factory, và rỗng.
        $this->assertFalse(Hash::check('password', $sau));
        $this->assertFalse(Hash::check('', $sau));
    }

    public function test_to_chuc_nguoi_mua_sach_ma_so_thue_va_hoa_don(): void
    {
        $t = Organization::factory()->create([
            'tax_id'          => '0312345678',
            'billing_address' => '99 Nguyễn Huệ, Q1',
            'billing_email'   => 'ketoan@congtythat.vn',
            'billing_phone'   => '0909123456',
        ]);

        $this->artisan('oohx:tay-du-lieu-dev')->assertSuccessful();

        $hang = DB::table('organizations')->where('id', $t->id)->first();

        $this->assertNull($hang->tax_id);
        $this->assertNull($hang->billing_address);
        $this->assertNull($hang->billing_email);
        $this->assertNull($hang->billing_phone);

        // Điều khoản thương mại ở lại: dev cần chúng để chạy đúng luồng, và
        // chúng không phải danh tính của ai.
        $this->assertNotNull($hang->payment_terms_days);
    }

    public function test_man_hinh_sach_device_token_va_ghi_chu_noi_bo(): void
    {
        $s = Screen::factory()->create([
            'device_token'   => 'tok_' . Str::random(40),
            'internal_notes' => 'Liên hệ bảo vệ: anh Hùng 0912345678',
        ]);

        $this->artisan('oohx:tay-du-lieu-dev')->assertSuccessful();

        $hang = DB::table('screens')->where('id', $s->id)->first();

        $this->assertNull($hang->device_token);
        $this->assertNull($hang->internal_notes);
        $this->assertNotNull($hang->name);
    }

    /**
     * Phản ánh của người dân: liên hệ đi, nội dung ở lại.
     *
     * `subject`, `content` và `resolution` được công khai cùng phản ánh, nên
     * chúng không phải một chỗ lộ của riêng dev — và xoá chúng là làm trang
     * `/phan-anh-to-chuc-xa-hoi` trên dev trống rỗng, tức không kiểm được.
     */
    public function test_phan_anh_mat_lien_he_nhung_giu_noi_dung(): void
    {
        $id = (string) Str::ulid();

        DB::table('public_reflections')->insert([
            'id'                => $id,
            'code'              => 'PA-202611-0001',
            'organization_name' => 'Hội Bảo vệ người tiêu dùng',
            'subject'           => 'Biển quảng cáo che tầm nhìn',
            'content'           => 'Nội dung phản ánh công khai, phải còn nguyên.',
            'received_at'       => now(),
            'contact_name'      => 'Lê Văn Dân',
            'contact_email'     => 'dan.that@yahoo.com',
            'contact_phone'     => '0988777666',
            'internal_notes'    => 'Đã gọi cho anh Dân số 0988777666',
            'submitted_ip'      => '113.23.45.67',
            'status'            => 'pending',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->artisan('oohx:tay-du-lieu-dev')->assertSuccessful();

        $hang = DB::table('public_reflections')->where('id', $id)->first();

        $this->assertNull($hang->contact_name);
        $this->assertNull($hang->contact_email);
        $this->assertNull($hang->contact_phone);
        $this->assertNull($hang->internal_notes);
        $this->assertNull($hang->submitted_ip);

        $this->assertSame('Biển quảng cáo che tầm nhìn', $hang->subject);
        $this->assertSame('Nội dung phản ánh công khai, phải còn nguyên.', $hang->content);
        $this->assertSame('PA-202611-0001', $hang->code);
    }

    /**
     * Yêu cầu báo giá là "email người mua" mà plan nói tới.
     *
     * `contact_name` và `contact_email` là **NOT NULL**, nên chúng nhận giá
     * trị thay thế chứ không nhận NULL — và đó chính là chỗ một câu
     * `UPDATE ... SET contact_email = NULL` gõ tay sẽ đổ giữa đường, để lại
     * một CSDL tẩy nửa vời mà không ai biết là nửa vời.
     */
    public function test_yeu_cau_bao_gia_mat_lien_he_nguoi_mua(): void
    {
        $id = (string) Str::ulid();

        DB::table('quote_requests')->insert([
            'id'              => $id,
            'code'            => 'YCB-202611-0001',
            'contact_name'    => 'Phạm Minh Mua',
            'contact_email'   => 'mua.that@brand.com.vn',
            'contact_phone'   => '0977666555',
            'contact_company' => 'Brand Việt Nam',
            'notes'           => 'Gọi em số 0977666555 nhé',
            'internal_notes'  => 'Khách quen của anh Tuấn',
            'submitted_ip'    => '171.224.1.2',
            'user_agent'      => 'Mozilla/5.0 (iPhone)',
            'status'          => 'pending',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->artisan('oohx:tay-du-lieu-dev')->assertSuccessful();

        $hang = DB::table('quote_requests')->where('id', $id)->first();

        $this->assertSame('Khách YCB-202611-0001', $hang->contact_name);
        $this->assertSame("ycb-{$id}@dev.invalid", $hang->contact_email);
        $this->assertNull($hang->contact_phone);
        $this->assertNull($hang->contact_company);
        $this->assertNull($hang->notes);
        $this->assertNull($hang->internal_notes);
        $this->assertNull($hang->submitted_ip);
        $this->assertNull($hang->user_agent);

        // Mã và trạng thái ở lại: owner tra theo mã, và `/publisher` lọc theo
        // trạng thái.
        $this->assertSame('YCB-202611-0001', $hang->code);
        $this->assertSame('pending', $hang->status);
    }

    /**
     * Chấp thuận chính sách: HÀNG ở lại, dấu vết đi.
     *
     * Xoá hàng là dev chạy một luồng khác production — luồng đặt chỗ đọc bảng
     * này để biết có phải hỏi lại hay không.
     */
    public function test_chap_thuan_chinh_sach_giu_hang_mat_dau_vet(): void
    {
        $u  = User::factory()->create();
        $id = (string) Str::ulid();

        DB::table('policy_consents')->insert([
            'id'             => $id,
            'user_id'        => $u->id,
            'policy_key'     => 'terms',
            'policy_version' => '2026-10-01',
            'context'        => 'register',
            'consented_at'   => now(),
            'ip'             => '113.23.45.67',
            'user_agent'     => 'Mozilla/5.0 (Windows NT 10.0)',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->artisan('oohx:tay-du-lieu-dev')->assertSuccessful();

        $hang = DB::table('policy_consents')->where('id', $id)->first();

        $this->assertNotNull($hang, 'hàng chấp thuận bị xoá — đó là bằng chứng pháp lý, không phải dấu vết');
        $this->assertSame('terms', $hang->policy_key);
        $this->assertSame('2026-10-01', $hang->policy_version);
        $this->assertNull($hang->ip);
        $this->assertNull($hang->user_agent);
    }

    public function test_bang_phu_du_bi_don_sach(): void
    {
        $u = User::factory()->create();

        DB::table('sessions')->insert([
            'id'            => Str::random(40),
            'user_id'       => $u->id,
            'ip_address'    => '113.23.45.67',
            'user_agent'    => 'Mozilla/5.0',
            'payload'       => base64_encode('phiên thật'),
            'last_activity' => time(),
        ]);

        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id'   => $u->id,
            'name'           => 'player',
            'token'          => hash('sha256', 'bi-mat-that'),
            'abilities'      => '["*"]',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        DB::table('password_reset_tokens')->insert([
            'email'      => 'nguoi.that@gmail.com',
            'token'      => hash('sha256', 'token-dat-lai'),
            'created_at' => now(),
        ]);

        DB::table('user_invitations')->insert([
            'id'                  => (string) Str::ulid(),
            'email'               => 'moi.that@congty.vn',
            'tenant_type'         => 'owner',
            'tenant_id'           => (string) Str::ulid(),
            'role'                => 'manager',
            'token'               => Str::random(64),
            'invited_by_user_id'  => $u->id,
            'expires_at'          => now()->addDays(7),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $this->artisan('oohx:tay-du-lieu-dev')->assertSuccessful();

        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame(0, DB::table('user_invitations')->count());

        // Người dùng thì KHÔNG bị xoá — chỉ phiên và token của họ.
        $this->assertSame(1, DB::table('users')->count());
    }

    /**
     * Lệnh tự kiểm lại sau khi ghi, và nói ra kết quả.
     *
     * Không phải "lệnh chạy xong nên chắc là xong": nó đếm lại từng cột và
     * thoát khác 0 nếu còn một hàng bẩn.
     */
    public function test_lenh_tu_kiem_lai_va_bao_sach(): void
    {
        Owner::factory()->create(['bank_account_number' => '0123456789']);
        User::factory()->create(['email' => 'nguoi.that@gmail.com']);

        $this->artisan('oohx:tay-du-lieu-dev')
            ->expectsOutputToContain('Tẩy sạch')
            ->assertSuccessful();
    }

    /**
     * Và phép tự kiểm phải ĐỎ khi tẩy chưa sạch — không chỉ in ra một dòng.
     *
     * Ca này là lý do `tay()` của lệnh là `protected`: lớp con dưới cuối tệp
     * phủ nó bằng một thân rỗng, nên không cột nào được ghi. Nếu bỏ nhánh
     * `return self::FAILURE` ở cuối `handle()` thì lệnh vẫn in bảng, vẫn thoát
     * 0, và một CSDL còn nguyên số tài khoản ngân hàng sẽ đi qua như đã sạch.
     * Đó là chỗ hỏng im lặng duy nhất của lệnh này, và đây là ca canh nó.
     */
    public function test_tu_kiem_do_khi_tay_chua_sach(): void
    {
        $this->app[\Illuminate\Contracts\Console\Kernel::class]
            ->registerCommand(new TayDuLieuDevKhongGhi());

        Owner::factory()->create(['bank_account_number' => '0123456789']);

        $this->artisan('oohx:tay-du-lieu-dev-khong-ghi')
            ->expectsOutputToContain('CHƯA SẠCH')
            ->assertFailed();
    }

    // ══════════════════════════════════════════════════════════════════════
    // --mo-khoa: sau khi tẩy thì KHÔNG CÒN đường đăng nhập nào
    // ══════════════════════════════════════════════════════════════════════

    public function test_mo_khoa_email_khong_ton_tai_thi_do_truoc_khi_tay(): void
    {
        $owner = Owner::factory()->create(['bank_account_number' => '0123456789']);

        $this->artisan('oohx:tay-du-lieu-dev', ['--mo-khoa' => 'khong.co@dau.ca'])
            ->expectsOutputToContain('Không có tài khoản nào')
            ->assertFailed();

        // Quan trọng: đỏ **trước** khi tẩy, không phải sau. Gõ sai email mà
        // CSDL đã bị tẩy là mất luôn đường vào.
        $this->assertSame('0123456789', DB::table('owners')->where('id', $owner->id)->value('bank_account_number'));
    }

    public function test_mo_khoa_tao_lai_duong_dang_nhap(): void
    {
        $u = User::factory()->create(['email' => 'tuan@oohx.net']);

        $ket_qua = $this->artisan('oohx:tay-du-lieu-dev', ['--mo-khoa' => 'tuan@oohx.net'])
            ->expectsOutputToContain('admin@dev.invalid')
            ->assertSuccessful();

        $ket_qua->run();

        $hang = DB::table('users')->where('id', $u->id)->first();

        // Tài khoản này là ngoại lệ duy nhất: email của nó KHÔNG theo mẫu
        // `u<id>@dev.invalid`, vì người gõ phải đánh được nó vào ô đăng nhập.
        $this->assertSame('admin@dev.invalid', $hang->email);

        // Và mật khẩu của nó không phải mật khẩu production cũ.
        $this->assertFalse(Hash::check('password', $hang->password));
    }
}

/**
 * Lệnh giả dùng cho đúng một ca: giống `oohx:tay-du-lieu-dev` ở mọi chỗ, chỉ
 * KHÔNG ghi gì. Ba lớp chặn, phép đếm trước, phép đếm lại sau và nhánh thoát
 * đều là mã thật của lớp cha — nên ca dùng nó đo đúng cái cần đo.
 *
 * Chữ ký phải khai lại CẢ HAI tuỳ chọn: `handle()` của lớp cha đọc chúng, và
 * một tuỳ chọn không khai là một ngoại lệ ngay dòng đầu.
 */
class TayDuLieuDevKhongGhi extends TayDuLieuDev
{
    protected $signature = 'oohx:tay-du-lieu-dev-khong-ghi
                            {--dem}
                            {--mo-khoa=}';

    protected function tay(): void
    {
        // Cố ý rỗng.
    }
}
