<?php

namespace App\Http\Controllers\Buyer;

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

        // Danh sách trạng thái nằm ở `Campaign::REPORTABLE_STATUSES` — một định
        // nghĩa cho cả hai cửa. Hai bản trùng giá trị hôm nay là hai bản sẽ
        // lệch ngày có người thêm một trạng thái.
        //
        // Trước đây hằng này đọc sang `Api\V2\CampaignReportController`: đúng
        // tinh thần "một định nghĩa", sai chỗ đặt. Nó là tính chất của chiến
        // dịch, không phải của một trong hai controller.
        //
        // Chữ trong câu 404 phải kể đủ ba trạng thái. Bản cũ viết "đang chạy
        // hoặc đã hoàn thành" trong khi danh sách có cả `paused`, nên một chiến
        // dịch tạm dừng mà vào được báo cáo lại mâu thuẫn với câu trang này nói
        // khi nó KHÔNG vào được.
        abort_unless(
            in_array($campaign->status, Campaign::REPORTABLE_STATUSES, true),
            404,
            'Báo cáo chỉ khả dụng cho campaign đang chạy, tạm dừng hoặc đã hoàn thành'
        );

        // Không nạp dữ liệu báo cáo ở đây nữa: trang đọc
        // `GET /api/v2/campaigns/{campaign}/report` từ trình duyệt. Bản cũ nhúng
        // cả chuỗi impressions theo ngày vào HTML bằng `@json($dailyData)`.
        return view('buyer.dashboard.report', ['campaign' => $campaign]);
    }
}
