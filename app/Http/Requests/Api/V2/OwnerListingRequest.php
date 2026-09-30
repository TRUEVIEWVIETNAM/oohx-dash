<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bộ luật kiểm cho `/api/v2/owners` (Codex R40).
 *
 * Bản trước endpoint này nhận `Request` trần, nên `q` đi thẳng vào phép nối
 * `'%' . $q . '%'` trong `FrontpageService::getOwnersPaginated()`. Gửi
 * `?q[]=x` là một mảng: PHP báo "Array to string conversion", Laravel biến
 * warning thành ErrorException, và endpoint công khai trả **500**.
 *
 * Hai điều học được, không chỉ một:
 *
 * 1. Endpoint nào cũng phải có bộ luật kiểm — "dữ liệu công khai, chỉ đọc"
 *    không miễn cho nó.
 * 2. Tuyên bố "mọi lỗi v2 có định dạng thống nhất" của tôi **chưa đúng**:
 *    hai renderer chỉ bắt `ValidationException` và `HttpExceptionInterface`,
 *    còn lỗi hệ thống đi ngoài cả hai. Xem thêm renderer `Throwable` trong
 *    `bootstrap/app.php`.
 */
class OwnerListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q'        => 'nullable|string|max:100',
            'type'     => 'nullable|string|max:100',
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'q.string'    => 'Từ khóa tìm kiếm phải là một chuỗi.',
            'type.string' => 'Loại điểm đặt phải là một chuỗi.',
        ];
    }
}
