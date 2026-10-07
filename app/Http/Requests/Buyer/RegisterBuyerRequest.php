<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Bộ luật kiểm cho đăng ký người mua — dùng chung giữa trang Blade và
 * `/api/v2/auth/register`.
 *
 * Viết lại bộ luật thứ hai cho API là mở một cửa vào mà trang web không có.
 * Đây đúng là bài học của `StorePublicReflectionRequest`, và ở đây nó nặng hơn:
 * một trong các luật là `accept_privacy => accepted`, tức cổng kiểm chứng
 * người dùng đã đồng ý Chính sách bảo mật. Bỏ sót nó ở một đường vào nghĩa là
 * có tài khoản tạo ra mà không có bằng chứng chấp thuận.
 */
class RegisterBuyerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => ['required', 'string', 'max:255'],
            'email'             => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'          => ['required', 'confirmed', Password::min(8)],
            'organization_name' => ['required', 'string', 'max:255'],
            'organization_type' => ['required', 'in:agency,client,brand'],

            // 'accepted' chứ không phải 'required': một checkbox không tick thì
            // trình duyệt không gửi gì cả, mà 'required' lại chỉ chặn giá trị
            // rỗng khi trường CÓ mặt. Thuộc tính `required` trên thẻ input là
            // gợi ý cho người dùng, không phải cổng kiểm soát.
            //
            // Với bản JSON từ Next thì lý do còn rõ hơn: `false` là một giá trị
            // "có mặt", nên `required` sẽ cho qua một người chưa tick.
            'accept_privacy'    => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'accept_privacy.accepted' => 'Bạn cần đồng ý với Chính sách bảo mật thông tin để tạo tài khoản.',
        ];
    }
}
