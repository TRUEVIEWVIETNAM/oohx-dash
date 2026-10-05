<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tạo campaign từ giỏ hàng — bước 1 của đặt chỗ.
 *
 * **Dùng chung** giữa trang Blade (`Buyer\BookingController::store`) và
 * `/api/v2` (`Api\V2\BookingController::store`). Một bộ luật kiểm, như
 * `App\Http\Requests\Cart\*` đã làm cho giỏ hàng.
 *
 * Viết hai bộ luật là mở một cửa mà bên kia không có — và đường này tạo
 * `booking_lines`, tức tạo nghĩa vụ tiền.
 *
 * **Không có trường tiền nào ở đây.** `total_budget` là ngân sách dự kiến do
 * người mua tự ghi để theo dõi, không phải số tiền phải trả: giá từng dòng do
 * `CampaignService::createFromCart()` tính lại từ giỏ, và tổng phải trả do
 * `PaymentService` tính. Nhận `total_budget` không làm đổi một đồng nào trong
 * `booking_lines`.
 */
class StoreCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Tư cách người mua do middleware `buyer` kiểm; quyền trên campaign
        // chưa tồn tại nên không có bản ghi nào để gọi policy lên.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:255'],
            'brand_name'   => ['nullable', 'string', 'max:255'],
            'category'     => ['nullable', 'string', 'max:100'],
            'objectives'   => ['nullable', 'array'],
            'objectives.*' => ['string', 'max:100'],
            'total_budget' => ['nullable', 'numeric', 'min:0'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Campaign cần có tên.',
        ];
    }
}
