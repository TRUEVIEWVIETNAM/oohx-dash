<?php

namespace App\Http\Requests\Booking;

use App\Models\Campaign;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Xác nhận một khoản thanh toán — bước cuối của đặt chỗ.
 *
 * **Dùng chung** giữa trang Blade (`Buyer\PaymentController::process`) và
 * `/api/v2` (`Api\V2\PaymentController::store`).
 *
 * ══ `amount` là đề nghị, không phải quyết định ══
 *
 * Trường này có mặt vì người mua được trả từng phần. Nhưng số tiền thật do
 * `PaymentService::createPayment()` quyết: không gửi thì nó lấy đúng công nợ
 * còn lại, gửi vượt công nợ thì nó trả 422. Nên client **không** đặt được số
 * tiền mình muốn — đúng CLAUDE.md mục 5.
 *
 * `min:1000` ở đây chỉ chặn rác ngay cửa; phép chặn thật nằm ở service, nơi
 * biết công nợ là bao nhiêu.
 *
 * ══ Chỉ `bank_transfer` ══
 *
 * Enum cố tình chỉ có một giá trị. VNPay và MoMo chưa có đường chạy nào — bản
 * cũ cho chúng qua validate rồi mới từ chối trong controller, tức đặc tả nói
 * có ba cách trả tiền trong khi chỉ một cách hoạt động. CLAUDE.md mục 8: đừng
 * gọi một tính năng là hoàn chỉnh chỉ vì có enum.
 */
class StorePaymentRequest extends FormRequest
{
    /**
     * Hai cách gọi tên, một bộ ràng buộc.
     *
     * Trang Blade gửi `owner_id` (khóa nội bộ, vì nó dựng form từ model sẵn
     * trong tay). API không được bắt client đoán khóa nội bộ nên nhận
     * `owner_slug` và quy đổi ở đây — đúng cách `Cart\AddToCartRequest` làm
     * với `screen_slug`/`product_slug`.
     *
     * Quy đổi chỉ tìm trong **các owner có màn hình trong chính campaign này**.
     * Tra toàn bảng rồi để `after()` bắt lỗi cũng ra cùng kết quả, nhưng hẹp
     * hơn thì không cần biết owner nào tồn tại trên sàn mới quy đổi được.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('owner_id') || ! $this->filled('owner_slug')) {
            return;
        }

        $campaign = $this->route('campaign');

        if (! $campaign instanceof Campaign) {
            return;
        }

        $ownerId = $campaign->bookingLines()
            ->join('owners', 'owners.id', '=', 'booking_lines.owner_id')
            ->where('owners.slug', $this->input('owner_slug'))
            ->value('booking_lines.owner_id');

        // Quy đổi hỏng (slug không tồn tại, hoặc owner không có màn hình trong
        // campaign này) thì KHÔNG gán gì: `owner_id` thiếu và luật `required`
        // báo lỗi. Gán `null` là biến nó thành lỗi `string`, nói sai nguyên
        // nhân.
        if ($ownerId) {
            $this->merge(['owner_id' => $ownerId]);
        }
    }

    public function authorize(): bool
    {
        // Quyền `pay` do controller gọi trên chính campaign —
        // `CampaignPolicy::pay()` là quyền riêng (`manage_payments`), không
        // phải quyền xem.
        return true;
    }

    public function rules(): array
    {
        return [
            'method'   => ['required', 'in:bank_transfer'],
            'amount'   => ['nullable', 'numeric', 'min:1000'],

            // Người mua chuyển thẳng cho từng media owner, nên mỗi lần xác nhận
            // phải nói rõ đã trả cho ai.
            'owner_id' => ['required', 'string', 'exists:owners,id'],

            // Mã chống trùng của chính LẦN gửi này, không phải token phiên.
            // Token phiên không đổi giữa các lần trả, nên trả một phần rồi quay
            // lại trả nốt sẽ nhận lại khoản cũ đã hoàn tất và không tạo được
            // khoản mới (Codex R07).
            'payment_nonce' => ['nullable', 'string', 'max:64'],

            'accept_terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'method.in'             => 'Hiện chỉ hỗ trợ chuyển khoản ngân hàng. VNPay và MoMo chưa được kết nối.',
            'accept_terms.accepted' => 'Bạn cần đồng ý với Quy chế hoạt động để xác nhận thanh toán.',
            'owner_id.required'     => 'Không xác định được media owner nhận khoản thanh toán này.',
            'owner_id.exists'       => 'Không tìm thấy media owner này.',
        ];
    }

    /**
     * Owner phải thật sự có màn hình trong campaign này.
     *
     * Nằm ở tầng kiểm chứ không phải `abort(422)` trong controller, vì cả hai
     * bên gọi tới đây đều cần đúng phép kiểm này, và vì nó là lỗi của **một
     * trường cụ thể** — người gửi cần biết `owner_id` sai, không phải nhận một
     * trang lỗi 422 không nói rõ chỗ nào.
     *
     * `exists:owners,id` ở trên chỉ nói owner tồn tại trên sàn. Thiếu phép kiểm
     * này thì một người mua gán khoản tiền của mình cho một owner bất kỳ, và
     * công nợ của hai campaign khác nhau lẫn vào nhau.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $campaign = $this->route('campaign');

                if (! $campaign instanceof Campaign || $validator->errors()->has('owner_id')) {
                    return;
                }

                $ownerId = $this->input('owner_id');

                if (! $campaign->bookingLines()->where('owner_id', $ownerId)->exists()) {
                    $validator->errors()->add(
                        'owner_id',
                        'Media owner này không có màn hình nào trong campaign.',
                    );
                }
            },
        ];
    }

    /**
     * Khóa chống trùng, dựng từ mã riêng của lần gửi này.
     *
     * Không có mã (gọi bằng script, hoặc biểu mẫu cũ còn mở) thì trả `null` và
     * vẫn chạy: tầng service đã có phép dùng lại khoản đang chờ của cùng owner.
     */
    public function idempotencyKey(Campaign $campaign): ?string
    {
        $nonce = $this->input('payment_nonce');

        if (! $nonce) {
            return null;
        }

        return hash('sha256', implode('|', [
            $campaign->id,
            (string) $this->input('owner_id'),
            (string) $this->user()?->id,
            (string) $nonce,
        ]));
    }
}
