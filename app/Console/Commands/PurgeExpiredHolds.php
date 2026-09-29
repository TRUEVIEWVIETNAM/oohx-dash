<?php

namespace App\Console\Commands;

use App\Services\InventoryHoldService;
use Illuminate\Console\Command;

/**
 * Dọn giữ chỗ đã hết hạn.
 *
 * Đây là việc dọn nhà, **không** phải cơ chế bảo vệ: truy vấn sức chứa đã tự
 * loại giữ chỗ hết hạn theo `expires_at`, nên lệnh này chạy trễ hay không chạy
 * cũng không giam kho của ai. Nó chỉ để bảng không phình ra và để trạng thái
 * trong CSDL khớp với thực tế khi có người đọc trực tiếp.
 */
class PurgeExpiredHolds extends Command
{
    protected $signature = 'inventory:purge-holds';

    protected $description = 'Đánh dấu các giữ chỗ đã hết hạn là đã nhả';

    public function handle(InventoryHoldService $holds): int
    {
        $count = $holds->purgeExpired();

        $this->info("Đã nhả {$count} giữ chỗ hết hạn.");

        return self::SUCCESS;
    }
}
