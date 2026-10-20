<?php

namespace App\Console\Commands;

use App\Services\SoatViTriService;
use Illuminate\Console\Command;

/**
 * Soát dữ liệu vị trí của kho màn hình công khai.
 *
 * Cùng một `SoatViTriService` với trang báo cáo trong `/admin` (CLAUDE.md mục
 * 1). Lệnh này để chạy trên VPS và để đưa vào cron khi cần; trang kia để
 * người đọc.
 */
class SoatViTri extends Command
{
    protected $signature = 'oohx:soat-vi-tri
                            {--json : In JSON thay vì bảng, để đưa vào công cụ khác}
                            {--loai= : Chỉ in một mã lỗi}';

    protected $description = 'Soát dữ liệu vị trí: thiếu toạ độ, toạ độ lệch, khoá ghép lệch điểm đặt';

    public function handle(SoatViTriService $soat): int
    {
        $kq = $soat->soat();

        if ($this->option('json')) {
            $this->line((string) json_encode($kq, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $loc = (string) $this->option('loai');
        $loi = $loc === ''
            ? $kq['loi']
            : array_values(array_filter($kq['loi'], fn (array $l) => $l['loai'] === $loc));

        $this->newLine();
        $this->info(sprintf(
            'Soát %d màn hình công khai lúc %s',
            $kq['tong_man_hinh'],
            $kq['luc']->format('d/m/Y H:i'),
        ));
        $this->newLine();

        // Bảng tổng hợp **luôn** in, kể cả khi không có lỗi nào: cột "đối
        // chiếu được" là thứ phân biệt "kho sạch" với "phép đo không chạy
        // được trên bản ghi nào".
        $this->table(
            ['Phép đo', 'Mức', 'Lỗi', 'Đối chiếu được'],
            collect($kq['theo_loai'])->map(fn (array $t, string $loai) => [
                SoatViTriService::NHAN[$loai] ?? $loai,
                SoatViTriService::MUC_NHAN[$t['muc']] ?? $t['muc'],
                $t['so'] > 0 ? (string) $t['so'] : '—',
                $t['do_phu'] . '/' . $kq['tong_man_hinh'],
            ])->values()->all(),
        );

        if ($loi === []) {
            $this->newLine();
            $this->info('Không có phát hiện nào trong phạm vi các phép đo trên.');

            return self::SUCCESS;
        }

        foreach (collect($loi)->groupBy('loai') as $loai => $nhom) {
            $this->newLine();
            $this->line(sprintf(
                '<options=bold>%s</> — %d bản ghi',
                SoatViTriService::NHAN[$loai] ?? $loai,
                $nhom->count(),
            ));
            $this->line('<fg=gray>' . (SoatViTriService::HE_QUA[$loai] ?? '') . '</>');

            foreach ($nhom as $l) {
                $this->line(sprintf(
                    '  • %s  <fg=gray>[%s]</>',
                    $l['screen_name'],
                    $l['screen_slug'],
                ));
                $this->line(sprintf(
                    '    điểm đặt: %s · tỉnh: %s · owner: %s',
                    $l['site_name'] ?: '(trống)',
                    $l['city'] ?: '(trống)',
                    trim((string) $l['owner_name']) ?: '(trống)',
                ));
                $this->line('    <fg=yellow>' . $l['chi_tiet'] . '</>');
            }
        }

        $this->newLine();

        // Nói ra thứ KHÔNG được soát. Một báo cáo im lặng về phạm vi của mình
        // bị đọc như một báo cáo đầy đủ.
        $this->line('<fg=gray>Không soát ở đây: giá, ảnh thực địa, kích thước, màn hình chưa công khai.</>');

        return self::SUCCESS;
    }
}
