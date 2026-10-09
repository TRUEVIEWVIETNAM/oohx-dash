<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\CampaignReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v2/campaigns/{campaign}/report` — báo cáo phát sóng.
 *
 * Mở đường cho `/my/campaigns/{campaign}/report`, trang Blade cuối cùng của
 * khu người mua còn nhúng dữ liệu vào HTML (`@json($dailyData)`).
 *
 * ══ Controller riêng, một action ══
 *
 * Báo cáo không phải một phần của luồng đặt chỗ: nó đọc bảng tổng hợp theo
 * ngày và không chạm vào giỏ, giữ chỗ hay tiền. Nhét vào `BookingController`
 * là làm một lớp đã dài thêm một việc không liên quan.
 *
 * ══ Danh sách trắng HẸP HƠN cái service trả ══
 *
 * `CampaignReportService` trả ba mảng, và không phải trường nào trong đó cũng
 * có người dùng. Ba trường bị bỏ lại có chủ ý:
 *
 *  - `daily.revenue` — doanh thu gộp theo ngày từ bảng rollup. Biểu đồ chỉ vẽ
 *    impressions; phát một chuỗi tiền theo ngày mà không ai hiện là mời bên
 *    tiêu thụ sau dùng nó rồi trang thành nơi công bố số liệu tiền.
 *  - `breakdown[].estimated_cost` và `breakdown[].status` — bảng chi tiết
 *    không có cột nào cho hai thứ đó.
 *  - `overview.total_paid` — trang không hiện; số đã trả nằm ở
 *    `GET campaigns/{campaign}/payments`, nơi `PaymentService` tính.
 *
 * CLAUDE.md mục 2: DTO danh sách trắng. Một trường phát ra mà không ai dùng là
 * một trường phải nghĩ lại khi có người hỏi "ai được thấy cái này".
 *
 * ══ Tiền ra ngoài là SỐ NGUYÊN ══
 *
 * Service trả `(float)`. VND không có phần thập phân, và một số thực đi qua
 * JSON rồi qua `toLocaleString` là một chỗ làm tròn thứ hai không ai kiểm.
 */
class CampaignReportController extends Controller
{
    /** Trạng thái chiến dịch có báo cáo. Trùng danh sách của trang Blade. */
    public const REPORTABLE_STATUSES = [
        Campaign::STATUS_ACTIVE,
        Campaign::STATUS_COMPLETED,
        Campaign::STATUS_PAUSED,
    ];

    public function __construct(private readonly CampaignReportService $reports) {}

    public function __invoke(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($request->user()?->can('viewReports', $campaign) ?? false, 403);

        if (! in_array($campaign->status, self::REPORTABLE_STATUSES, true)) {
            return response()->json([
                'error'   => 'report_not_available',
                'message' => 'Báo cáo chỉ khả dụng cho campaign đang chạy, tạm dừng hoặc đã hoàn thành.',
                'code'    => 404,
                'details' => [],
            ], 404);
        }

        $tongQuan = $this->reports->getOverview($campaign);
        $theoNgay = $this->reports->getDailyImpressions($campaign);

        return response()->json([
            'data' => [
                'overview' => [
                    'total_screens'               => (int) $tongQuan['total_screens'],
                    'total_estimated_impressions' => (int) $tongQuan['total_estimated_impressions'],
                    'total_actual_impressions'    => (int) $tongQuan['total_actual_impressions'],
                    'delivery_rate'               => (float) $tongQuan['delivery_rate'],
                    'total_estimated_cost'        => (int) round((float) $tongQuan['total_estimated_cost']),
                    'total_actual_cost'           => (int) round((float) $tongQuan['total_actual_cost']),
                    'days_total'                  => (int) $tongQuan['days_total'],
                    'days_elapsed'                => (int) $tongQuan['days_elapsed'],
                    'days_remaining'              => (int) $tongQuan['days_remaining'],
                    'progress_pct'                => (int) $tongQuan['progress_pct'],
                ],

                'daily' => [
                    'labels'      => array_values($theoNgay['labels']),
                    'impressions' => array_map('intval', array_values($theoNgay['impressions'])),
                ],

                'breakdown' => array_map(fn (array $d): array => [
                    'screen_name'           => $d['screen_name'],
                    'owner_name'            => $d['owner_name'],
                    'city'                  => $d['city'],
                    'dates'                 => $d['dates'],
                    'estimated_impressions' => (int) $d['estimated_impressions'],
                    'actual_impressions'    => (int) $d['actual_impressions'],
                    'delivery_rate'         => (float) $d['delivery_rate'],
                    'actual_cost'           => (int) round((float) $d['actual_cost']),
                ], $this->reports->getScreenBreakdown($campaign)),
            ],
        ]);
    }
}
