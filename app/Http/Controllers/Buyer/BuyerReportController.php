<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Api\V2\CampaignReportController as V2CampaignReportController;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BuyerReportController extends Controller
{
    public function show(Request $request, Campaign $campaign): View
    {
        // Policy, KHÔNG so `current_organization_id`. Xem
        // `CampaignPolicy::viewReports()` để biết khe cũ là gì.
        //
        // `->can()` chứ không `$this->authorize()`: lớp `Controller` gốc của dự
        // án không dùng trait `AuthorizesRequests`, và các controller khu người
        // mua đều gọi theo cách này.
        abort_unless($request->user()?->can('viewReports', $campaign) ?? false, 403);

        // Danh sách trạng thái nằm ở `Api\V2\CampaignReportController` — một
        // định nghĩa cho cả hai cửa. Hai bản trùng giá trị hôm nay là hai bản
        // sẽ lệch ngày có người thêm một trạng thái.
        abort_unless(
            in_array($campaign->status, V2CampaignReportController::REPORTABLE_STATUSES, true),
            404,
            'Báo cáo chỉ khả dụng cho campaign đang chạy hoặc đã hoàn thành'
        );

        // Không nạp dữ liệu báo cáo ở đây nữa: trang đọc
        // `GET /api/v2/campaigns/{campaign}/report` từ trình duyệt. Bản cũ nhúng
        // cả chuỗi impressions theo ngày vào HTML bằng `@json($dailyData)`.
        return view('buyer.dashboard.report', ['campaign' => $campaign]);
    }
}
