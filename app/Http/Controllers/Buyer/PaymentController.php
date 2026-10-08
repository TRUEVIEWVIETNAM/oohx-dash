<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StorePaymentRequest;
use App\Models\Campaign;
use App\Models\PolicyConsent;
use App\Services\PaymentService;
use App\Services\PolicyConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $paymentService,
        private PolicyConsentService $consents,
    ) {}

    /**
     * GET /booking/{campaign}/payment — Show payment page
     */
    public function show(Request $request, Campaign $campaign): View
    {
        $this->authorize($request, $campaign, 'view');

        abort_unless(
            // Cùng danh sách với đường v2, lấy từ service — không viết lại.
            in_array($campaign->status, PaymentService::PAYABLE_STATUSES, true),
            403,
            'Campaign chưa được duyệt'
        );

        // Không nạp tiền, không nạp nơi nhận tiền. View tự gọi HAI đường của
        // `/api/v2` từ trình duyệt — xem chú thích đầu `buyer/booking/payment.blade.php`.
        //
        // `$campaign` vẫn truyền vào, và chỉ để dựng vỏ trang: thẻ tiêu đề,
        // tên và mã ở đầu trang, các URL của route. Model đã nằm trong tay do
        // route binding nên không tốn truy vấn nào; mọi thứ cần một truy vấn —
        // công nợ, lịch sử, số màn hình, nơi nhận tiền — đều đi qua API.
        //
        // Quan trọng: **không** gọi `getSummary()` hay `breakdownByOwner()` ở
        // đây nữa. Giữ lại là hai lần tính cùng một con số cho một lần xem
        // trang, và là chỗ để bản render và bản API trôi khỏi nhau mà không ai
        // thấy — đúng kiểu lỗi `InventoryController` và `FrontpageService`.
        return view('buyer.booking.payment', [
            'campaign' => $campaign,
        ]);
    }

    /**
     * POST /booking/{campaign}/payment — Create payment (bank transfer)
     */
    public function process(StorePaymentRequest $request, Campaign $campaign): RedirectResponse
    {
        $this->authorize($request, $campaign);

        // Luật kiểm nằm ở `StorePaymentRequest`, dùng chung với
        // `Api\V2\PaymentController`. Gồm cả phép kiểm "owner có màn hình
        // trong campaign này" — trước đây là `abort(422)` ở đây, nay là lỗi
        // của trường `owner_id`.
        //
        // Đổi hình dạng có chủ ý: 422 trần không nói trường nào sai, mà đây là
        // lỗi của ĐÚNG MỘT trường. Và `/api/v2` cần nó ở dạng
        // `details[].field` để khớp định dạng lỗi thống nhất (CLAUDE.md mục 2)
        // — hai bên không nên trả hai hình dạng cho cùng một phép kiểm.
        //
        // VNPay và MoMo cũng chuyển vào đó: bản cũ cho chúng qua validate rồi
        // mới từ chối ở đây, tức enum nói có ba cách trả tiền trong khi chỉ
        // một cách hoạt động (CLAUDE.md mục 8).
        $data = $request->validated();

        $payment = $this->paymentService->createPayment(
            $campaign,
            'bank_transfer',
            $data['amount'] ?? null,
            $data['owner_id'],
            idempotencyKey: $request->idempotencyKey($campaign),
        );

        $this->consents->record(
            ['terms'],
            PolicyConsent::CONTEXT_PAYMENT,
            $request,
            subjectId: $campaign->id,
        );

        return redirect()->route('buyer.payment.success', [
            'campaign' => $campaign,
            'payment'  => $payment,
        ]);
    }

    /**
     * GET /booking/{campaign}/payment/success — Payment submitted confirmation
     */
    public function success(Request $request, Campaign $campaign): View
    {
        $this->authorize($request, $campaign, 'view');

        $payment = $campaign->payments()->latest()->first();

        return view('buyer.booking.payment-success', [
            'campaign' => $campaign,
            'payment'  => $payment,
        ]);
    }

    /**
     * Xác nhận thanh toán là quyền riêng (`manage_payments`), không phải quyền
     * xem. Phép so `organization_id` cũ cho cả vai trò `viewer` làm việc này.
     */
    private function authorize(Request $request, Campaign $campaign, string $ability = 'pay'): void
    {
        abort_unless(
            $request->user()?->can($ability, $campaign) ?? false,
            403,
            'Bạn không có quyền xác nhận thanh toán cho campaign này.'
        );
    }
}
