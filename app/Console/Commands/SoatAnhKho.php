<?php

namespace App\Console\Commands;

use App\Services\SoatAnhKhoService;
use Illuminate\Console\Command;

/**
 * Soát ảnh của kho màn hình công khai — lát 0 của `docs/PLAN-ANH-VI-TRI.md`.
 *
 * Cùng một `SoatAnhKhoService` cho mọi bên gọi (CLAUDE.md mục 1). Lệnh này để
 * chạy trên VPS; nếu sau này `/admin` có bảng ảnh thì nó gọi cùng service, nên
 * hai chỗ không bao giờ nói hai con số.
 *
 * ══ Lệnh này sẽ được dán vào chat ══
 *
 * Nên nó chỉ in thứ an toàn khi lộ: tên chủ sở hữu, slug màn hình, đường dẫn
 * ảnh. Không `device_token`, không `revenue_share_pct`, không `billing_info` —
 * `CLAUDE.md` mục 2. Có một ca test canh đúng điều đó, vì một cột mới thêm vào
 * service về sau sẽ đi thẳng ra đây.
 */
class SoatAnhKho extends Command
{
    protected $signature = 'oohx:soat-anh-kho
                            {--json : In JSON thay vì bảng, để đưa vào công cụ khác}
                            {--bo-qua-dia : Không đi bộ qua thư mục ảnh (10.000 vị trí × 10 ảnh là 100.000 lần stat)}';

    protected $description = 'Soát ảnh kho: vị trí nào có ảnh của chính nó, vị trí nào mượn ảnh thay thế, vị trí nào không có gì';

    public function handle(SoatAnhKhoService $soat): int
    {
        $kq = $soat->soat(! $this->option('bo-qua-dia'));

        if ($this->option('json')) {
            $this->line((string) json_encode($kq, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $tong = max(1, $kq['tong_man_hinh']);

        $this->newLine();
        $this->info(sprintf(
            'Soát ảnh %d màn hình công khai lúc %s',
            $kq['tong_man_hinh'],
            $kq['luc']->format('d/m/Y H:i'),
        ));

        if ($kq['ngoai_pham_vi'] > 0) {
            $this->line(sprintf(
                '<fg=gray>%d màn hình ngoài phạm vi (đã tắt, hoặc chủ sở hữu không còn hoạt động) — không ai thấy ảnh của chúng.</>',
                $kq['ngoai_pham_vi'],
            ));
        }

        $this->newLine();

        $this->table(
            ['Trạng thái ảnh', 'Số vị trí', 'Phần'],
            collect(SoatAnhKhoService::NHAN)->map(fn (string $nhan, string $ma) => [
                $nhan,
                (string) $kq['tong'][$ma],
                sprintf('%d%%', (int) round($kq['tong'][$ma] / $tong * 100)),
            ])->values()->all(),
        );

        // Nhóm "mượn ảnh thay thế" tách theo bậc: bốn bậc dẫn tới bốn việc
        // khác nhau. Banner địa điểm còn cho người mua thấy đúng khu vực; ảnh
        // mặc định của loại điểm đặt thì không cho thấy gì cả.
        if ($kq['theo_nguon'] !== []) {
            $this->newLine();
            $this->line('<options=bold>Ảnh thay thế đến từ đâu</>');

            $this->table(
                ['Bậc dự phòng', 'Số vị trí'],
                collect($kq['theo_nguon'])
                    ->sortDesc()
                    ->map(fn (int $so, string $nguon) => [
                        SoatAnhKhoService::NHAN_NGUON[$nguon] ?? $nguon,
                        (string) $so,
                    ])->values()->all(),
            );
        }

        $this->newLine();
        $this->line('<options=bold>Theo chủ sở hữu</>');

        $this->table(
            ['Chủ sở hữu', 'Vị trí', 'Có ảnh thật', 'Mượn ảnh', 'Không có', 'Bậc đang mượn'],
            collect($kq['theo_owner'])
                ->sortByDesc('tong')
                ->map(fn (array $o) => [
                    $o['owner_name'] ?: '(trống)',
                    (string) $o['tong'],
                    (string) $o[SoatAnhKhoService::CO_ANH],
                    (string) $o[SoatAnhKhoService::THAY_THE],
                    (string) $o[SoatAnhKhoService::KHONG_CO],
                    collect($o['theo_nguon'])
                        ->map(fn (int $so, string $n) => (SoatAnhKhoService::NHAN_NGUON[$n] ?? $n) . ' ×' . $so)
                        ->join(', ') ?: '—',
                ])->values()->all(),
        );

        $sa = $kq['so_anh'];

        $this->newLine();
        $this->line(sprintf(
            '<options=bold>Ảnh thật:</> %d vị trí, %d ảnh, trung vị %s ảnh/vị trí, nhiều nhất %s',
            $sa['so_vi_tri_co_anh'],
            $sa['tong_phan_tu'],
            $sa['trung_vi'] !== null ? (string) $sa['trung_vi'] : '—',
            $sa['lon_nhat'] !== null ? (string) $sa['lon_nhat'] : '—',
        ));

        // ── Hai con số là lỗi, không phải thống kê ────────────────────────

        $un = $kq['url_ngoai'];

        if ($un['so_man_hinh'] > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d vị trí (%d phần tử) có ảnh là URL đầy đủ, không phải đường dẫn trong disk.',
                $un['so_man_hinh'],
                $un['so_phan_tu'],
            ));
            $this->line('<fg=gray>Những hàng này hiện bình thường ở thẻ danh sách và VỠ ở trang chi tiết:');
            $this->line('<fg=gray>`ScreenDetailResource` bọc mọi phần tử bằng asset(\'storage/\' . $p). Lát 1 của plan sửa.</>');

            foreach ($un['vi_du'] as $v) {
                $this->line(sprintf('  • %s  <fg=gray>%s</>', $v['slug'], $v['duong']));
            }
        }

        if ($kq['tep_mat'] !== null && $kq['tep_mat']['so_man_hinh'] > 0) {
            $this->newLine();
            $this->error(sprintf(
                '%d vị trí (%d phần tử) khai có ảnh mà tệp không còn trên đĩa.',
                $kq['tep_mat']['so_man_hinh'],
                $kq['tep_mat']['so_phan_tu'],
            ));
            $this->line('<fg=gray>Tệ hơn một vị trí không ảnh: trang dựng <img> trỏ vào 404 và không khối nào nói gì.</>');

            foreach ($kq['tep_mat']['vi_du'] as $v) {
                $this->line(sprintf('  • %s  <fg=gray>%s</>', $v['slug'], $v['duong']));
            }
        }

        if ($kq['dia'] !== null) {
            $d = $kq['dia'];

            $this->newLine();

            if (! $d['ton_tai']) {
                $this->line(sprintf(
                    '<fg=gray>Thư mục %s chưa tồn tại — chưa một ảnh màn hình nào được tải lên.</>',
                    $d['thu_muc'],
                ));
            } else {
                $this->line(sprintf(
                    '<options=bold>Đĩa:</> %s — %d tệp, %s%s',
                    $d['thu_muc'],
                    $d['so_tep'],
                    $this->co($d['bytes']),
                    $d['lon_nhat'] !== null
                        ? sprintf(', lớn nhất %s (%s)', $this->co($d['lon_nhat']['bytes']), $d['lon_nhat']['duong'])
                        : '',
                ));
            }
        }

        $this->newLine();

        // Nói ra thứ KHÔNG được soát. Lệnh này đếm sự CÓ MẶT của ảnh; nó không
        // biết ảnh đó chụp đúng mặt biển hay chụp bầu trời.
        $this->line('<fg=gray>Không soát ở đây: ảnh chụp có đúng mặt biển hay không, chất lượng ảnh, ảnh của gói sản phẩm, ảnh bìa chủ sở hữu ở /owners.</>');

        return self::SUCCESS;
    }

    private function co(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $don = ['KB', 'MB', 'GB', 'TB'];
        $so  = $bytes / 1024;
        $i   = 0;

        while ($so >= 1024 && $i < count($don) - 1) {
            $so /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $so, $don[$i]);
    }
}
