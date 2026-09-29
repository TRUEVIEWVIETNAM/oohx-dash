<?php

namespace App\Console\Commands;

use App\Services\Player\ImpressionRollupService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Tổng hợp lượt phát theo ngày.
 *
 * Mặc định chạy cho **hôm nay và hôm qua**: thiết bị mất mạng gửi bù sau nửa
 * đêm là chuyện thường, nên chỉ tổng hợp hôm nay sẽ bỏ sót phần gửi muộn của
 * hôm trước.
 */
class RollupImpressions extends Command
{
    protected $signature = 'impressions:rollup
                            {--date= : Ngày cụ thể (YYYY-MM-DD)}
                            {--from= : Từ ngày}
                            {--to=   : Đến ngày}';

    protected $description = 'Tổng hợp lượt phát theo ngày vào bảng impression_daily_rollups';

    public function handle(ImpressionRollupService $rollups): int
    {
        if ($date = $this->option('date')) {
            $count = $rollups->rollupDay(Carbon::parse($date));
            $this->info("Đã tổng hợp {$count} dòng cho ngày {$date}.");

            return self::SUCCESS;
        }

        $from = $this->option('from') ? Carbon::parse($this->option('from')) : now()->subDay();
        $to   = $this->option('to') ? Carbon::parse($this->option('to')) : now();

        $count = $rollups->rollupRange($from, $to);

        $this->info(sprintf(
            'Đã tổng hợp %d dòng từ %s tới %s.',
            $count,
            $from->toDateString(),
            $to->toDateString(),
        ));

        return self::SUCCESS;
    }
}
