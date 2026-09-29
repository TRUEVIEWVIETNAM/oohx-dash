<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
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
            in_array($campaign->status, ['approved', 'active']),
            403,
            'Campaign chưa được duyệt'
        );

        $summary = $this->paymentService->getSummary($campaign);
        $payments = $campaign->payments()->with('owner:id,name')->latest()->get();

        return view('buyer.booking.payment', [
            'campaign' => $campaign,
            'summary'  => $summary,
            'payments' => $payments,
            'byOwner'  => $this->paymentService->breakdownByOwner($campaign),
        ]);
    }

    /**
     * POST /booking/{campaign}/payment — Create payment (bank transfer)
     */
    public function process(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorize($request, $campaign);

        $data = $request->validate([
            'method'   => ['required', 'in:bank_transfer,vnpay,momo'],
            'amount'   => ['nullable', 'numeric', 'min:1000'],
            // Người mua chuyển thẳng cho từng media owner, nên mỗi lần xác nhận
            // phải nói rõ là đã trả cho ai. exists+booking_lines: chỉ chấp nhận
            // owner thật sự có màn hình trong campaign này.
            'owner_id' => ['required', 'string', 'exists:owners,id'],

            'accept_terms' => ['accepted'],
        ], [
            'accept_terms.accepted' => 'Bạn cần đồng ý với Quy chế hoạt động để xác nhận thanh toán.',
            'owner_id.required'     => 'Không xác định được media owner nhận khoản thanh toán này.',
        ]);

        abort_unless(
            $campaign->bookingLines()->where('owner_id', $data['owner_id'])->exists(),
            422,
            'Media owner này không có màn hình nào trong campaign'
        );

        $method = $data['method'];

        if ($method === 'vnpay') {
            // TODO: Phase 6.5 — VNPay integration
            return back()->withErrors(['method' => 'VNPay chưa được hỗ trợ. Vui lòng chọn chuyển khoản.']);
        }

        if ($method === 'momo') {
            return back()->withErrors(['method' => 'MoMo chưa được hỗ trợ. Vui lòng chọn chuyển khoản.']);
        }

        // Bank transfer — create pending payment.
        //
        // Khóa chống trùng dựng từ chiến dịch + owner + token của chính lần gửi
        // biểu mẫu này: bấm nút hai lần gửi lại cùng token nên nhận lại đúng
        // khoản đã tạo, thay vì sinh thêm một dòng công nợ ma.
        $payment = $this->paymentService->createPayment(
            $campaign,
            'bank_transfer',
            $data['amount'] ?? null,
            $data['owner_id'],
            idempotencyKey: hash('sha256', implode('|', [
                $campaign->id,
                $data['owner_id'],
                (string) $request->user()?->id,
                (string) $request->session()->token(),
            ])),
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
