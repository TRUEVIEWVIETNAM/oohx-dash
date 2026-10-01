<?php

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Luật kiểm cho việc sửa một dòng giỏ — dùng chung Blade và `/api/v2`.
 *
 * Không có trường tiền nào ở đây, và đó là cố ý: `estimated_cost` do
 * `CartService::updateItem()` tính lại từ cấu hình kho. Số tiền do máy chủ
 * tính (CLAUDE.md mục 5), nên một trường `estimated_cost` nhận từ client là
 * một cửa hậu dù có validate kiểu dữ liệu hay không.
 */
class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxRange = 'before_or_equal:' . now()->addDays((int) config('pricing.max_range_days', 365))->toDateString();

        return [
            'start_date'         => ['nullable', 'date', 'after_or_equal:today'],
            'end_date'           => ['nullable', 'date', 'after_or_equal:start_date', $maxRange],
            'share_of_voice_pct' => ['nullable', 'integer', 'min:1', 'max:100'],
            'spot_length'        => ['nullable', 'integer', 'min:5', 'max:60'],
        ];
    }
}
