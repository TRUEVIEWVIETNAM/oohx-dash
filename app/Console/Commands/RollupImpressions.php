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

        // Mặc định phủ TRỌN cửa sổ nhận muộn, không phải chỉ hôm qua.
        //
        // API nhận lượt phát báo muộn tới 7 ngày, nhưng lịch định kỳ chỉ tổng
        // hợp hôm qua và hôm nay — nên một lượt phát cách đây ba ngày gửi về
        // hôm nay sẽ không bao giờ vào bảng tổng hợp, và báo cáo thiếu nó cho
        // tới khi có người chạy bù bằng tay (Codex R18).
        $lateDays = (int) config('pricing.impression_late_days', 7);

        $from = $this->option('from') ? Carbon::parse($this->option('from')) : now()->subDays($lateDays);
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
