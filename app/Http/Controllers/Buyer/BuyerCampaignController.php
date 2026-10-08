<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BuyerCampaignController extends Controller
{
    // `OwnerReviewService` không còn được tiêm vào đây: danh sách owner còn
    // đánh giá được đã chuyển sang `detailPayload()` của
    // `Api\V2\BookingController`. Tiêm một service rồi không dùng là nói sai về
    // việc controller này làm gì.

    public function index(Request $request): View
    {
        $org = $request->user()->currentOrganization;

        $campaigns = $org->campaigns()
            ->latest()
            ->paginate(20);

        return view('buyer.dashboard.campaigns', [
            'campaigns' => $campaigns,
        ]);
    }

    /**
     * GET /my/campaigns/{campaign} — trang chi tiết chiến dịch.
     *
     * ══ Không nạp gì nữa ══
     *
     * View tự gọi `GET /api/v2/campaigns/{campaign}` từ trình duyệt. `$campaign`
     * vẫn truyền vào, và chỉ để dựng vỏ trang: thẻ tiêu đề, tên và mã ở đầu
     * trang, các URL của route. Model đã nằm trong tay do route binding nên
     * không tốn truy vấn nào.
     *
     * Bốn thứ từng dựng ở đây — báo giá hoàn tiền, owner còn đánh giá được,
     * đánh giá đã viết, lịch sử hoạt động — nay ở `detailPayload()` của
     * `Api\V2\BookingController`. Giữ lại cả hai bản là hai đường tính cùng một
     * con số tiền hoàn cho một lần xem trang, và là chỗ để chúng trôi khỏi nhau
     * mà không ai thấy.
     *
     * ══ Quyền: qua policy, không so tay ══
     *
     * Bản cũ so `$campaign->organization_id === $request->user()->current_organization_id`.
     * Đó **đúng là** phép so mà `CampaignPolicy` được viết ra để thay, và
     * chú thích đầu policy đó nêu đúng ba vấn đề của nó. Hai vấn đề có thật ở
     * chính chỗ này:
     *
     * - Nó kiểm theo **tổ chức đang chọn**, không theo tổ chức của chiến dịch.
     *   Một người thuộc hai tổ chức, mở link chiến dịch của tổ chức A trong khi
     *   đang chọn tổ chức B, nhận 403 cho chiến dịch của chính mình.
     * - Nó **không kiểm tổ chức còn hoạt động hay không**, trong khi
     *   `CampaignPolicy::membership()` có kiểm. Tạm ngưng một tổ chức phải có
     *   hiệu lực ở mọi cửa, kể cả cửa đọc.
     *
     * Và quan trọng hơn cả hai: đường API phục vụ đúng trang này dùng policy.
     * Để hai bộ luật cho một màn hình là cách chắc chắn nhất để một ngày nào đó
     * trang mở được mà dữ liệu thì 404, hoặc ngược lại.
     */
    public function show(Request $request, Campaign $campaign): View
    {
        abort_unless($request->user()?->can('view', $campaign) ?? false, 403);

        return view('buyer.dashboard.campaign-detail', [
            'campaign' => $campaign,
        ]);
    }
}
