<?php

namespace App\Services\Player;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tổng hợp lượt phát theo ngày.
 *
 * Báo cáo đọc bảng tổng hợp, không quét bảng thô: `impression_logs` là bảng
 * ghi liên tục từ mọi màn hình, và mỗi lần mở trang báo cáo lại quét nó là
 * cách chắc chắn nhất để trang báo cáo chết khi số màn hình tăng lên.
 *
 * Phép tổng hợp **chạy lại được**: dòng cũ của cùng một ngày bị ghi đè chứ
 * không cộng dồn. Nhờ vậy chạy bù cho quá khứ hay chạy hai lần cùng lúc cũng
 * không nhân đôi số liệu.
 */
class ImpressionRollupService
{
    /**
     * Tổng hợp một ngày. Trả về số dòng đã ghi.
     */
    public function rollupDay(Carbon $day): int
    {
        $start = $day->copy()->startOfDay();
        $end   = $day->copy()->endOfDay();

        $rows = DB::table('impression_logs')
            ->selectRaw("
                DATE(played_at) as day,
                screen_id,
                owner_id,
                COALESCE(campaign_id, '') as campaign_id,
                COALESCE(booking_line_id, '') as booking_line_id,
                COUNT(*) as plays,
                SUM(imp_count) as impressions,
                SUM(duration_sec) as duration_sec_total,
                COALESCE(SUM(revenue_gross), 0) as revenue_gross
            ")
            ->whereBetween('played_at', [$start, $end])
            // Lượt phát có mốc thời gian bị kẹp KHÔNG vào báo cáo.
            //
            // Mốc của nó đã bị dịch để không rơi vào phân vùng tùy ý, nên đưa
            // vào tổng hợp là cộng nó vào một ngày mà nó không thuộc về — số
            // liệu ngày đó tăng lên không có thật (Codex R26). Bằng chứng vẫn
            // được giữ trong `impression_logs` kèm `reported_played_at`, chờ
            // chính sách đối soát.
            ->where('played_at_clamped', false)
            ->groupBy('day', 'screen_id', 'owner_id', 'campaign_id', 'booking_line_id')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $now = now();

        $payload = $rows->map(fn ($r) => [
            'day'                => $r->day,
            'screen_id'          => $r->screen_id,
            'owner_id'           => $r->owner_id,
            'campaign_id'        => $r->campaign_id,
            'booking_line_id'    => $r->booking_line_id,
            'plays'              => (int) $r->plays,
            'impressions'        => (float) $r->impressions,
            'duration_sec_total' => (int) $r->duration_sec_total,
            'revenue_gross'      => (float) $r->revenue_gross,
            'created_at'         => $now,
            'updated_at'         => $now,
        ])->all();

        // upsert theo đúng khóa unique của bảng: chạy lại thì cập nhật, không
        // chèn thêm.
        DB::table('impression_daily_rollups')->upsert(
            $payload,
            ['day', 'screen_id', 'campaign_id', 'booking_line_id'],
            ['owner_id', 'plays', 'impressions', 'duration_sec_total', 'revenue_gross', 'updated_at'],
        );

        return count($payload);
    }

    /**
     * Tổng hợp một khoảng ngày, tính cả hai đầu.
     */
    public function rollupRange(Carbon $from, Carbon $to): int
    {
        $total = 0;

        for ($day = $from->copy()->startOfDay(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $total += $this->rollupDay($day);
        }

        return $total;
    }
}
