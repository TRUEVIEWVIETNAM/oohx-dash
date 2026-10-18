<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Soát nguồn ảnh nền bản đồ: tải một tile đã biết và so **kích thước**.
 *
 * ══ Vì sao đo byte, không đo mã trạng thái ══
 *
 * Ngày 18/10/2026 nguồn tile hỏng hai lần và hai kiểu khác hẳn nhau:
 *
 *  - Sáng: `tile.openstreetmap.org` trả **503**. Kiểu này ai cũng bắt được —
 *    mã trạng thái nói thẳng, và `themLopNen()` bên Next tự nhảy sang nguồn
 *    dự phòng.
 *  - Trưa: `basemaps.cartocdn.com` trả **200** kèm một PNG 2.049 byte in chữ
 *    "API KEY REQUIRED". Không mã lỗi, không ngoại lệ, không `tileerror`,
 *    không dòng log nào. Mười hai bản đồ lên production với chữ đó trên nền
 *    và chỉ con mắt mới thấy.
 *
 * Nên phép kiểm đúng không hỏi "máy chủ có trả lời không" mà hỏi "thứ trả về
 * có phải một tấm bản đồ không". Một tile thành phố có hàng chục KB chi tiết;
 * một tile watermark hay một tile rỗng chỉ có vài KB. Ngưỡng nằm giữa.
 *
 * Lệnh này không chạy trong CI — nó gọi mạng ra ngoài, và một phép kiểm đỏ vì
 * Wi-Fi là một phép kiểm sẽ bị tắt. Nó dành cho hai lúc: ngay sau khi đổi
 * nguồn tile, và theo lịch (cron) để một nguồn chết âm thầm không sống hàng
 * tuần như lần vừa rồi.
 *
 *   php artisan map:kiem-tile
 *   php artisan map:kiem-tile --url=https://…/{z}/{y}/{x}
 */
class KiemNguonTile extends Command
{
    protected $signature = 'map:kiem-tile
                            {--url= : Soát một URL cụ thể thay vì nguồn trong config}
                            {--toi-thieu= : Ngưỡng byte, mặc định lấy từ config}';

    protected $description = 'Tải một tile đã biết và kiểm nó là bản đồ thật, không phải watermark';

    public function handle(): int
    {
        $toiThieu = (int) ($this->option('toi-thieu') ?: config('map.kiem_tile_toi_thieu'));
        $mau = config('map.kiem_tile_mau');

        $nguon = $this->option('url')
            ? ['--url' => $this->option('url')]
            : [
                'nguồn chính' => config('map.tile_url'),
                'dự phòng' => config('map.tile_fallback_url'),
            ];

        $hong = 0;

        foreach ($nguon as $ten => $mauUrl) {
            $url = $this->dungUrl($mauUrl, $mau);
            [$ok, $noiDung] = $this->soat($url, $toiThieu);

            $this->line(($ok ? '<info>  ok  </info>' : '<error> HỎNG </error>')
                . " {$ten}: {$noiDung}");
            $this->line('       ' . $this->giauKhoa($url));

            if (! $ok) {
                $hong++;
            }
        }

        if ($hong > 0) {
            $this->newLine();
            $this->error(
                "{$hong} nguồn không trả về bản đồ. Nguồn sống ở `config/map.php` "
                . '(Blade) và `webapp/lib/ban-do.ts` (Next) — đổi là sửa cả hai.',
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** Thay các chỗ `{z}` `{x}` `{y}` `{s}` `{r}` `{key}` bằng tile mẫu. */
    private function dungUrl(string $mauUrl, array $mau): string
    {
        return str_replace(
            ['{z}', '{x}', '{y}', '{s}', '{r}', '{key}'],
            [
                (string) $mau['z'],
                (string) $mau['x'],
                (string) $mau['y'],
                substr((string) config('map.tile_subdomains'), 0, 1),
                '',
                (string) config('map.tile_key'),
            ],
            $mauUrl,
        );
    }

    /**
     * Che khóa trước khi in.
     *
     * Khóa MapTiler hiện ra trong trình duyệt của khách dù muốn hay không,
     * nhưng đó không phải lý do để nó rơi vào log CI, ảnh chụp màn hình hay
     * một issue ai đó dán lên. In chỗ nào thì che chỗ đó.
     */
    private function giauKhoa(string $url): string
    {
        $khoa = (string) config('map.tile_key');

        return $khoa === '' ? $url : str_replace($khoa, '***', $url);
    }

    /** @return array{0: bool, 1: string} */
    private function soat(string $url, int $toiThieu): array
    {
        try {
            $r = Http::timeout(20)
                ->withHeaders([
                    'User-Agent' => 'oohx.net map:kiem-tile',

                    // Bắt buộc từ khi MapTiler bật giới hạn origin: một yêu
                    // cầu KHÔNG có `Origin` hay `Referer` bị họ từ chối ngay
                    // khi danh sách origin có một dòng. Trình duyệt tự gửi
                    // header này cho mỗi ô ảnh; `curl` và lệnh này thì không,
                    // nên không gửi là tự báo "HỎNG" cho một nguồn đang sống.
                    'Referer' => rtrim((string) config('app.url'), '/') . '/',
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            return [false, 'không nối được — ' . $e->getMessage()];
        }

        if (! $r->successful()) {
            return [false, "HTTP {$r->status()}"];
        }

        $kieu = (string) $r->header('Content-Type');

        if (! str_starts_with($kieu, 'image/')) {
            return [false, "HTTP 200 nhưng trả `{$kieu}`, không phải ảnh"];
        }

        $byte = strlen($r->body());

        if ($byte < $toiThieu) {
            return [false, sprintf(
                'HTTP 200 nhưng ảnh chỉ %s byte (< %s) — nhiều khả năng là '
                . 'watermark đòi API key hoặc ô trống, không phải bản đồ',
                number_format($byte),
                number_format($toiThieu),
            )];
        }

        return [true, sprintf('%s, %s byte', $kieu, number_format($byte))];
    }
}
