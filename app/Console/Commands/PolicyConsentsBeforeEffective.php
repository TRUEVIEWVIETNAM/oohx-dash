<?php

namespace App\Console\Commands;

use App\Models\PolicyConsent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ai đã đồng ý một bản chính sách **trước ngày bản đó có hiệu lực**.
 *
 * ══ Vì sao cần đếm ══
 *
 * Ba trang chính sách chỉ được ban hành ngày 09/10/2026 (`effective_from`), còn
 * `PolicyConsentService` đã đóng dấu `version` lên từng bản ghi chấp thuận từ
 * trước đó. Nên tồn tại khả năng một người dùng đã bấm đồng ý với bản `1.0`
 * khi bản `1.0` còn là **bản nháp** — chưa ban hành. Với hồ sơ TMĐT, "ai đồng ý
 * phiên bản nào, lúc nào" là câu phải trả lời được, và câu trả lời hiện nay là
 * "không biết bao nhiêu người".
 *
 * Lệnh này biến cái không biết thành một con số. Nó **chỉ đọc** — không gửi
 * thư, không sửa bản ghi, không đòi lại chấp thuận. Phải làm gì với danh sách
 * là một quyết định pháp lý, không phải một lần sửa mã.
 *
 * ══ Cách xác định, và nó chính xác chứ không phỏng đoán ══
 *
 * `policy_consents.consented_at` là mốc thật, `effective_from` trong
 * `config/policies.php` là ngày ban hành. `consented_at < effective_from` nghĩa
 * là người đó đồng ý khi bản ấy chưa có hiệu lực. Không cần lịch sử
 * `effective_from` vì điều đáng biết là so với ngày ban hành **hiện tại**: đó
 * là bản văn đang áp dụng cho họ.
 *
 * Trang chưa có `effective_from` thì **mọi** chấp thuận của nó đều là chấp
 * thuận với bản nháp.
 *
 * ══ Một cái bẫy ngày tháng, có ca test riêng ══
 *
 * `effective_from` viết `dd/mm/yyyy`. `Carbon::parse('09/10/2026')` đọc chuỗi
 * đó theo kiểu Mỹ và trả ra **ngày 10 tháng 9**, lệch một tháng về phía trước —
 * tức lệnh sẽ báo thiếu người. Nên bắt buộc `createFromFormat('d/m/Y', …)`.
 *
 * Ví dụ:
 *   php artisan policies:consents-before-effective
 *   php artisan policies:consents-before-effective --chi-tiet
 */
class PolicyConsentsBeforeEffective extends Command
{
    protected $signature = 'policies:consents-before-effective
                            {--chi-tiet : Liệt kê từng người dùng thay vì chỉ đếm}';

    protected $description = 'Đếm các lần chấp thuận chính sách xảy ra TRƯỚC ngày bản đó có hiệu lực (chỉ đọc).';

    public function handle(): int
    {
        $hang  = [];
        $tong  = 0;

        foreach (config('policies.pages') as $slug => $page) {
            $hieuLuc = $this->ngayHieuLuc($page['effective_from'] ?? null);

            $truyVan = PolicyConsent::query()
                ->where('policy_key', $page['key'])
                ->where('policy_version', $page['version']);

            // Chưa ban hành: cả bản là bản nháp, nên không lọc theo mốc nào.
            if ($hieuLuc !== null) {
                $truyVan->where('consented_at', '<', $hieuLuc);
            }

            $soLuong = (clone $truyVan)->count();
            $tong += $soLuong;

            $hang[] = [
                $slug,
                $page['version'],
                $hieuLuc?->format('d/m/Y') ?? 'CHƯA BAN HÀNH',
                $soLuong,
            ];

            if ($soLuong > 0 && $this->option('chi-tiet')) {
                $this->chiTiet($slug, $truyVan);
            }
        }

        $this->table(['Trang', 'Phiên bản', 'Hiệu lực từ', 'Chấp thuận trước đó'], $hang);

        if ($tong === 0) {
            $this->info('Không có lần chấp thuận nào xảy ra trước ngày hiệu lực.');

            return self::SUCCESS;
        }

        $this->warn("Tổng {$tong} lần chấp thuận xảy ra TRƯỚC ngày bản chính sách có hiệu lực.");
        $this->line('Lệnh này không gửi thư và không đòi lại chấp thuận — xem docblock của lớp.');

        return self::SUCCESS;
    }

    /**
     * Đọc `effective_from` theo ĐÚNG định dạng `dd/mm/yyyy`.
     *
     * `createFromFormat` chứ không `parse`: xem docblock của lớp về chỗ lệch
     * một tháng. `startOfDay()` vì ban hành tính theo ngày, không theo giờ —
     * một chấp thuận lúc 09:00 ngày ban hành là **sau** khi ban hành.
     */
    private function ngayHieuLuc(?string $chu): ?Carbon
    {
        if (empty($chu)) {
            return null;
        }

        $ngay = Carbon::createFromFormat('d/m/Y', $chu);

        if ($ngay === false) {
            $this->error("Ngày hiệu lực '{$chu}' không đúng định dạng dd/mm/yyyy.");

            return null;
        }

        return $ngay->startOfDay();
    }

    private function chiTiet(string $slug, $truyVan): void
    {
        $this->line('');
        $this->line("── {$slug} ──");

        $truyVan->with('user:id,email')
            ->orderBy('consented_at')
            ->chunk(200, function ($cac) {
                foreach ($cac as $c) {
                    $this->line(sprintf(
                        '  %s  user=%s  %s  ctx=%s',
                        $c->consented_at?->format('d/m/Y H:i') ?? '—',
                        $c->user_id ?? '—',
                        $c->user?->email ?? '(người dùng đã xóa)',
                        $c->context,
                    ));
                }
            });
    }
}
