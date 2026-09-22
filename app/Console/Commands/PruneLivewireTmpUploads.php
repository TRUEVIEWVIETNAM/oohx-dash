<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * Dọn file upload tạm của Livewire quá hạn.
 *
 * Livewire chỉ tự dọn livewire-tmp trong _finishUpload(), tức là khi có người
 * hoàn tất một lượt upload qua component. Ai gọi thẳng endpoint upload rồi bỏ đi
 * thì file nằm lại vĩnh viễn — webshell thu được trên production (08–09/2026)
 * đều thuộc loại này. Lệnh này không phụ thuộc vào luồng upload nào.
 */
class PruneLivewireTmpUploads extends Command
{
    protected $signature = 'uploads:prune-livewire-tmp {--hours=24 : Xoá file cũ hơn N giờ}';
    protected $description = 'Xoá file upload tạm của Livewire cũ hơn N giờ.';

    public function handle(): int
    {
        if (FileUploadConfiguration::isUsingS3()) {
            $this->info('Upload tạm đang dùng S3 — dọn bằng lifecycle rule của bucket.');
            return self::SUCCESS;
        }

        $storage = FileUploadConfiguration::storage();
        $cutoff  = now()->subHours((int) $this->option('hours'))->timestamp;
        $count   = 0;

        foreach ($storage->allFiles(FileUploadConfiguration::path()) as $path) {
            // Upload khác có thể vừa xoá file này giữa chừng.
            if (! $storage->exists($path)) continue;

            if ($storage->lastModified($path) < $cutoff) {
                $storage->delete($path);
                $count++;
            }
        }

        $this->info("Đã xoá {$count} file upload tạm.");
        return self::SUCCESS;
    }
}
