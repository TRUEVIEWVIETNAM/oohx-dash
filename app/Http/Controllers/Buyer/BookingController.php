<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ba bước đặt chỗ — từ 10/10/2026 chỉ còn ĐỌC, và chỉ trả khung trang.
 *
 * ══ Ba phương thức ghi đã gỡ ══
 *
 * `store()`, `uploadCreative()` và `submit()` từng nhận ba `POST` của ba
 * trang Blade. Ba trang đó nay gửi qua `POST /api/v2/campaigns`,
 * `.../creatives` và `.../submit`.
 *
 * Gỡ chứ không để lại, vì ba phương thức đó và ba endpoint kia làm **cùng một
 * việc ghi**: cùng `StoreCampaignRequest` / `UploadCreativeRequest` /
 * `SubmitCampaignRequest`, cùng `CampaignService` / `CreativeService`, cùng
 * `PolicyConsentService::record()` với cùng `CONTEXT_BOOKING`. Hai đường cho
 * một luật là hai nơi phải sửa khi luật đổi (CLAUDE.md §1), và đường không ai
 * gọi là đường không ai sửa.
 *
 * Trước khi gỡ, thứ đáng kiểm nhất không phải luật kiểm mà là **bản ghi đồng
 * ý**: `submit()` cũ ghi lại việc người mua đồng ý Quy chế và Chính sách bảo
 * mật kèm IP và thời điểm, và đó là bằng chứng khi có tranh chấp. Endpoint
 * `/api/v2/.../submit` gọi đúng `$this->consents->record(['terms', 'privacy'],
 * PolicyConsent::CONTEXT_BOOKING, …)` với cùng `subjectId` là mã chiến dịch,
 * nên bản ghi đó không mất.
 *
 * ══ Vì sao `create()` vẫn còn một truy vấn ══
 *
 * Phép kiểm "giỏ trống" ở lại máy chủ. Để nó sang JS là hiện một biểu mẫu tạo
 * chiến dịch rồi mới đẩy người dùng về giỏ — họ đã gõ xong tên chiến dịch khi
 * biết mình không có gì để đặt.
 */
class BookingController extends Controller
{
    public function __construct(
        private CartService $cartService,
    ) {}

    /**
     * Bước 1: GET /booking/create — khung biểu mẫu thông tin chiến dịch.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $cart = $this->cartService->getOrCreateCart($request->user());

        if (! $cart->items()->exists()) {
            return redirect()->route('buyer.cart')->withErrors(['cart' => 'Plan trống. Thêm màn hình trước khi tạo campaign.']);
        }

        // Không truyền giỏ vào view: thanh bên đọc `GET /api/v2/cart` từ trình
        // duyệt. Chỉ cần biết giỏ **có món hay không**, nên `exists()` thay cho
        // lượt nạp cả giỏ kèm `screen.spec`, `screen.inventory`, `screen.owner`
        // — ba quan hệ mà view không còn đọc.
        return view('buyer.booking.create');
    }

    /**
     * Bước 2: GET /booking/{campaign}/creative — khung trang tải nội dung.
     */
    public function creative(Request $request, Campaign $campaign): View
    {
        $this->authorizeCampaign($request, $campaign);

        // Trang đọc `GET /api/v2/campaigns/{campaign}` từ trình duyệt —
        // endpoint đó đã trả `campaign`, `lines` và `creatives`. `$campaign`
        // vẫn truyền, nhưng CHỈ để sinh đường liên kết; nó đã nằm trong URL.
        return view('buyer.booking.creative', ['campaign' => $campaign]);
    }

    /**
     * Bước 3: GET /booking/{campaign}/review — khung trang xác nhận.
     */
    public function review(Request $request, Campaign $campaign): View
    {
        $this->authorizeCampaign($request, $campaign);

        // Cùng một endpoint với bước 2, và nó trả luôn `conflicts` cùng
        // `summary` đã tính VAT ở MỘT chỗ. Bản cũ tự nhân `vat_rate` trong
        // view — đúng hình dạng lỗi "VAT nhân hai lần" của trang giỏ.
        return view('buyer.booking.review', ['campaign' => $campaign]);
    }

    /**
     * Quyền đi qua CampaignPolicy, không so `organization_id` bằng tay.
     *
     * Phép so cũ chỉ trả lời "có cùng tổ chức không", nên một thành viên vai trò
     * `viewer` — người được mời chỉ để xem — vẫn gửi được booking và xác nhận
     * được thanh toán, dù `OrganizationUser::PERMISSIONS` đã nói rõ là không.
     */
    private function authorizeCampaign(Request $request, Campaign $campaign, string $ability = 'view'): void
    {
        abort_unless(
            $request->user()?->can($ability, $campaign) ?? false,
            403,
            'Bạn không có quyền thực hiện việc này trên campaign.'
        );
    }
}
