<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StorePaymentRequest;
use App\Http\Resources\V2\PaymentResource;
use App\Models\Campaign;
use App\Models\PolicyConsent;
use App\Services\PaymentService;
use App\Services\PolicyConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thanh toán — nhóm **cần quyền** của `/api/v2`, mốc 3 giai đoạn 5.
 *
 *   GET   campaigns/{campaign}/payments   công nợ theo từng owner + các khoản đã tạo
 *   POST  campaigns/{campaign}/payments   xác nhận một khoản chuyển khoản
 *
 * ══ Số tiền do máy chủ quyết, và đây là chỗ phải nói rõ ══
 *
 * `amount` trong request là **đề nghị**, không phải quyết định.
 * `PaymentService::createPayment()` là nơi quyết:
 *
 * - không gửi `amount` thì nó lấy đúng công nợ còn lại của owner đó;
 * - gửi vượt công nợ thì nó trả 422 kèm số đúng;
 * - công nợ đã bằng 0 thì nó từ chối tạo thêm.
 *
 * Nên không có đường nào để client tự đặt số tiền mình muốn trả — CLAUDE.md
 * mục 5. Controller này **không** làm phép tính tiền nào; nó chỉ chuyển tiếp.
 *
 * ══ Công nợ theo từng owner, không phải tổng campaign ══
 *
 * Người mua chuyển thẳng cho từng media owner, nên "đã trả đủ" phải đúng với
 * **mọi** owner, không phải tổng thu bằng tổng phải thu. Đó là F07 trong
 * `FINDINGS.md`: tổng tiền che khuất công nợ từng owner, và một chiến dịch có
 * thể trông như đã trả đủ trong khi một owner chưa nhận đồng nào.
 * `PaymentService::breakdownByOwner()` là chỗ duy nhất tính việc đó.
 *
 * ══ Số tài khoản không đi qua đây ══
 *
 * DTO chỉ trả `owner.id` và `owner.name`. CLAUDE.md mục 2 cấm lộ `bank_*` và
 * `billing_info`, nên thông tin chuyển khoản là việc của một đường riêng có
 * phân quyền riêng — chưa dựng, và không được lén đưa vào đây.
 */
class PaymentController extends Controller
{
    /** Trạng thái campaign cho phép xác nhận thanh toán. */
    // Danh sách trạng thái nằm ở `PaymentService::PAYABLE_STATUSES` — một
    // định nghĩa cho cả đường v2 lẫn đường Blade. Alias ở đây chỉ để câu lệnh
    // bên dưới đọc được, không phải một bản thứ hai.
    private const PAYABLE_STATUSES = PaymentService::PAYABLE_STATUSES;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PolicyConsentService $consents,
    ) {}

    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeCampaign($request, $campaign, 'view');

        return response()->json(['data' => $this->paymentsPayload($campaign)]);
    }

    public function store(StorePaymentRequest $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeCampaign($request, $campaign, 'pay');

        abort_unless(
            in_array($campaign->status, self::PAYABLE_STATUSES, true),
            422,
            'Campaign chưa được duyệt nên chưa thể xác nhận thanh toán.',
        );

        $data = $request->validated();

        // Service tự quyết số tiền. Truyền `null` khi client không gửi
        // `amount` là cố ý: để nó lấy đúng công nợ còn lại chứ không để
        // controller đoán.
        $payment = $this->payments->createPayment(
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

        return response()->json([
            'data' => [
                'payment' => (new PaymentResource($payment->load('owner:id,name')))->resolve(),
            ] + $this->paymentsPayload($campaign->fresh()),
        ], 201);
    }

    /**
     * 404 khi không được xem, 403 khi được xem nhưng không được trả.
     *
     * Cùng lý lẽ như `Api\V2\BookingController` — và ở đây nó quan trọng hơn:
     * `CampaignPolicy::pay()` là quyền riêng (`manage_payments`), không phải
     * quyền xem. Phép so `organization_id` mà controller Blade từng dùng cho
     * cả vai trò `viewer` xác nhận thanh toán.
     */
    private function authorizeCampaign(Request $request, Campaign $campaign, string $ability): void
    {
        $user = $request->user();

        abort_unless(
            $user?->can('view', $campaign) ?? false,
            404,
            'Không tìm thấy campaign này.',
        );

        if ($ability === 'view') {
            return;
        }

        abort_unless(
            $user->can($ability, $campaign),
            403,
            'Bạn không có quyền xác nhận thanh toán cho campaign này.',
        );
    }

    private function paymentsPayload(Campaign $campaign): array
    {
        $summary = $this->payments->getSummary($campaign);

        $rows = $this->payments->breakdownByOwner($campaign);

        return [
            'campaign' => [
                'id'     => $campaign->id,
                'code'   => $campaign->code,
                'status' => $campaign->status,
            ],

            // Tiền bằng VND nguyên ở mọi chỗ. `getSummary()` trả `float` vì
            // lịch sử; ép về `int` ngay tại biên để bên tiêu thụ không bao giờ
            // thấy số lẻ — một giá 1.000.001 đồng từng làm công nợ về 0 trong
            // khi `is_paid` vẫn false, và chiến dịch kẹt vĩnh viễn (Codex R09).
            'summary' => [
                'currency'       => 'VND',
                'total_cost'     => (int) round($summary['total_cost']),
                'vat'            => (int) round($summary['vat']),
                'total_cost_vat' => (int) round($summary['total_cost_vat']),
                'total_paid'     => (int) round($summary['total_paid']),
                'refunded'       => (int) round($summary['refunded']),
                'pending'        => (int) round($summary['pending']),
                'remaining'      => (int) round($summary['remaining']),
                'is_fully_paid'  => (bool) $summary['is_fully_paid'],
            ],

            'by_owner' => $rows->map(fn (array $row) => [
                'owner' => [
                    'id'   => $row['owner']->id,
                    'name' => $row['owner']->name,
                ],
                'cost'      => (int) round($row['cost']),
                'vat'       => (int) round($row['vat']),
                'total'     => (int) round($row['total']),
                'paid'      => (int) round($row['paid']),
                'refunded'  => (int) round($row['refunded']),
                'pending'   => (int) round($row['pending']),
                'remaining' => (int) round($row['remaining']),
                'is_paid'   => (bool) $row['is_paid'],
            ])->values()->all(),

            'payments' => PaymentResource::collection(
                $campaign->payments()->with('owner:id,name')->latest()->get()
            )->resolve(),

            // Trả được hay không do máy chủ trả lời, giống `can_submit` ở
            // nhóm đặt chỗ.
            'can_pay' => in_array($campaign->status, self::PAYABLE_STATUSES, true)
                && ! (bool) $summary['is_fully_paid'],
        ];
    }
}
