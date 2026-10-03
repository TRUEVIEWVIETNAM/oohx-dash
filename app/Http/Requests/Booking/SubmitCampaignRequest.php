<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Gửi campaign chờ duyệt — bước 3 của đặt chỗ.
 *
 * **Dùng chung** giữa trang Blade và `/api/v2`.
 *
 * Hai ô xác nhận không phải thủ tục giấy tờ: `PolicyConsentService` ghi lại
 * việc đồng ý kèm IP và thời điểm, và với một sàn đang nộp hồ sơ TMĐT thì bản
 * ghi đó là bằng chứng. Nên luật kiểm phải nằm MỘT chỗ — nếu API bỏ qua
 * `accept_terms` thì có một đường tạo booking không để lại bằng chứng đồng ý,
 * và không ai thấy cho tới khi có tranh chấp.
 *
 * `accepted` nhận `true`, `1`, `"1"`, `"yes"`, `"on"` — nên client JSON gửi
 * `true` là hợp lệ, không cần biết trang web gửi `"on"`.
 */
class SubmitCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Quyền `submit` do controller gọi trên chính bản ghi campaign —
        // `CampaignPolicy::submit()` phân biệt vai trò `viewer` với vai trò
        // được phép gửi, và FormRequest không có campaign trong tay ở đây.
        return true;
    }

    public function rules(): array
    {
        return [
            'confirm_accuracy' => ['accepted'],
            'accept_terms'     => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirm_accuracy.accepted' => 'Bạn cần xác nhận thông tin booking là chính xác.',
            'accept_terms.accepted'     => 'Bạn cần đồng ý với Quy chế hoạt động và Chính sách bảo mật để gửi booking.',
        ];
    }
}
