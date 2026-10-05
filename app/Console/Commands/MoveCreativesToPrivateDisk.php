<?php

namespace App\Console\Commands;

use App\Models\Creative;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Chuyển tệp nội dung quảng cáo từ disk `public` sang disk riêng.
 *
 * Chạy MỘT LẦN sau khi deploy bản đổi chỗ lưu. Trước đó
 * `Buyer\BookingController::uploadCreative()` lưu vào `public`, nên các tệp đã
 * tải lên vẫn nằm dưới `storage/app/public/creatives/` và vẫn tải được qua
 * `/storage/...` không cần đăng nhập — đổi code không tự di chuyển chúng.
 *
 *     php artisan oohx:creatives-to-private            # chỉ xem, không đổi gì
 *     php artisan oohx:creatives-to-private --force    # chuyển thật
 *
 * Mặc định là **chỉ xem**, không phải `--dry-run`: lệnh này xoá tệp ở chỗ cũ,
 * và với một lệnh như vậy thì an toàn phải là hành vi khi không gõ thêm gì.
 *
 * Chạy lại được an toàn: tệp nào đã ở disk riêng thì bỏ qua.
 */
class MoveCreativesToPrivateDisk extends Command
{
    protected $signature = 'oohx:creatives-to-private
                            {--force : Chuyển thật. Không có cờ này thì chỉ in ra dự định.}';

    protected $description = 'Chuyển tệp nội dung quảng cáo từ disk public sang disk riêng';

    public function handle(): int
    {
        $thatSu = (bool) $this->option('force');
        $tuDisk = 'public';
        $denDisk = config('creatives.disk');

        if ($tuDisk === $denDisk) {
            $this->error("config('creatives.disk') đang là '{$denDisk}' — không có gì để chuyển.");
            $this->line('  Đặt CREATIVES_DISK=private rồi chạy lại.');

            return self::FAILURE;
        }

        $tu  = Storage::disk($tuDisk);
        $den = Storage::disk($denDisk);

        $daChuyen = $daCo = $khongThayTep = $hong = 0;

        if (! $thatSu) {
            $this->warn('CHỈ XEM — không đổi gì. Thêm --force để chuyển thật.');
            $this->newLine();
        }

        Creative::query()
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->chunkById(100, function ($creatives) use (
                $tu, $den, $thatSu, $tuDisk, $denDisk,
                &$daChuyen, &$daCo, &$khongThayTep, &$hong
            ) {
                foreach ($creatives as $creative) {
                    $path = $creative->file_path;

                    if ($den->exists($path)) {
                        $daCo++;

                        // Còn bản sao ở disk công khai thì vẫn phải xoá: để lại
                        // nghĩa là lỗ hổng vẫn mở, chỉ là có thêm một bản ở chỗ
                        // đúng.
                        if ($tu->exists($path)) {
                            $this->line("  <comment>còn bản ở {$tuDisk}</comment>  {$path}");
                            if ($thatSu) {
                                $tu->delete($path);
                            }
                        }

                        continue;
                    }

                    if (! $tu->exists($path)) {
                        $khongThayTep++;
                        $this->line("  <fg=red>không thấy tệp</>  {$path}");

                        continue;
                    }

                    $this->line("  {$tuDisk} → {$denDisk}  {$path}");

                    if (! $thatSu) {
                        $daChuyen++;

                        continue;
                    }

                    // Đọc theo luồng: nội dung quảng cáo có thể là video 50MB,
                    // và `get()` nạp cả tệp vào bộ nhớ.
                    $stream = $tu->readStream($path);

                    if (! $stream || ! $den->writeStream($path, $stream)) {
                        $hong++;
                        $this->error("  ghi thất bại: {$path}");
                        if (is_resource($stream)) {
                            fclose($stream);
                        }

                        continue;
                    }

                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    // Chỉ xoá bản cũ SAU KHI đã xác nhận bản mới có thật và
                    // cùng kích thước. Xoá trước khi kiểm là cách mất tệp gốc
                    // vì một lần ghi hỏng im lặng.
                    if (! $den->exists($path) || $den->size($path) !== $tu->size($path)) {
                        $hong++;
                        $this->error("  bản mới không khớp kích thước, giữ nguyên bản cũ: {$path}");

                        continue;
                    }

                    $tu->delete($path);
                    $daChuyen++;
                }
            });

        $this->newLine();
        $this->table(
            ['Kết quả', 'Số lượng'],
            [
                [$thatSu ? 'Đã chuyển' : 'Sẽ chuyển', $daChuyen],
                ['Đã ở disk riêng từ trước', $daCo],
                ['Không thấy tệp trên đĩa', $khongThayTep],
                ['Hỏng', $hong],
            ],
        );

        if ($khongThayTep > 0) {
            $this->warn("{$khongThayTep} bản ghi trỏ vào tệp không tồn tại. Bản ghi vẫn giữ — xoá tệp không có thật thì không xoá được gì, mà xoá bản ghi là mất lịch sử.");
        }

        if ($thatSu && $hong === 0) {
            $this->newLine();
            $this->info('Kiểm lại từ ngoài: /storage/creatives/... phải trả 404.');
        }

        return $hong > 0 ? self::FAILURE : self::SUCCESS;
    }
}
