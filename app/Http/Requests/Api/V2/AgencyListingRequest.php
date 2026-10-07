<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tham số của `GET /api/v2/agencies`.
 *
 * Kiểu phải khai, không để controller tự đoán: `?q[]=x` gửi một MẢNG vào chỗ
 * chờ chuỗi, và nếu không chặn ở đây thì nó đi tới `LIKE '%Array%'` hoặc ném
 * lỗi kiểu — tức 500 cho một yêu cầu sai, thay vì 422 nói rõ sai ở đâu. Đó là
 * đúng lớp lỗi R40 đã gặp ở `/products`.
 */
class AgencyListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q'        => 'nullable|string|max:100',
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
        ];
    }
}
