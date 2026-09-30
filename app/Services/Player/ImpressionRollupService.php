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
        // Giành khóa của NGÀY trước khi đọc nguồn, và giữ tới hết transaction.
        //
        // Thứ tự ở đây là toàn bộ giá trị của hàm. Bản trước tính tập nguồn
        // TRƯỚC transaction, nên hai lần chạy cùng ngày có thể đọc hai tập
        // khác nhau rồi ghi theo thứ tự ngược: lần chạy mang ảnh chụp cũ xóa
        // cả những nhóm mà lần chạy kia vừa tạo, và báo cáo bị lùi dữ liệu
        // (Codex R35).
        //
        // Khóa nằm ở tầng service nên MỌI người gọi đều đi qua — kể cả lệnh
        // `impressions:rollup --date` do người vận hành gọi tay, thứ không
        // dùng chung `withoutOverlapping` với lịch định kỳ.
        $this->ensureLockRow($day);

        return DB::transaction(function () use ($day) {
            DB::table('impression_rollup_locks')
                ->where('day', $day->toDateString())
                ->lockForUpdate()
                ->first();

            return $this->replaceDay($day);
        });
    }

    /**
     * Hàng khóa phải tồn tại trước khi khóa nó. Chèn ngoài transaction để hai
     * người gọi cùng lúc không khóa chéo nhau ngay ở bước tạo hàng.
     */
    private function ensureLockRow(Carbon $day): void
    {
        DB::table('impression_rollup_locks')->upsert(
            [['day' => $day->toDateString(), 'updated_at' => now()]],
            ['day'],
            ['updated_at'],
        );
    }

    /**
     * Thay thế toàn bộ dữ liệu tổng hợp của một ngày. Chạy khi đã giữ khóa ngày.
     */
    private function replaceDay(Carbon $day): int
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
            // Đọc CÓ KHÓA, không phải đọc thường.
            //
            // Khóa hàng ngày bắt các lần chạy xếp hàng, nhưng dưới REPEATABLE
            // READ thì phép đọc thường vẫn dùng ảnh chụp lập từ lần đọc đầu
            // của transaction — người chờ xong sẽ tính trên dữ liệu cũ. Đây
            // đúng là bài học R04, lặp lại ở một chỗ khác.
            ->lockForUpdate()
            ->get();

        // Đồng bộ TRỌN ngày: xóa nhóm cũ rồi ghi lại nhóm hợp lệ.
        //
        // Chỉ `upsert` thì nhóm đã tồn tại mà nay không còn nguồn hợp lệ vẫn ở
        // lại vĩnh viễn. Cụ thể: một ngày từng được tổng hợp trước khi có bộ
        // lọc lượt-bị-kẹp sẽ giữ nguyên dòng báo cáo sai, và chạy lại bao nhiêu
        // lần cũng không dọn — kể cả khi truy vấn trả về rỗng thì hàm cũ
        // `return` ngay (Codex R32).
        //
        // Đã ở trong transaction của `rollupDay` và đang giữ khóa ngày, nên
        // xóa rồi ghi ở đây là một bước nguyên tử với mọi người gọi khác.
        DB::table('impression_daily_rollups')->where('day', $day->toDateString())->delete();

        if ($rows->isEmpty()) {
            return 0;
        }

        return $this->writeRollups($rows);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    private function writeRollups($rows): int
    {
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
