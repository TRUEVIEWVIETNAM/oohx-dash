<?php

namespace App\Http\Requests\Api\V2;

use App\Services\QuoteRequestService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Gửi yêu cầu báo giá — `POST /api/v2/quote-requests` (lát 5).
 *
 * Đường **ghi, mở cho người lạ**: chủ dự án chốt ngày 19/10/2026 rằng khách
 * chưa đăng nhập vẫn gửi được, vì bắt đăng nhập trước khi người ta kịp hỏi
 * giá là mất họ ở bước rẻ nhất. Nên mọi lớp chống lạm dụng phải đứng được
 * một mình: hạn mức tần suất ở route, ô mồi ẩn ở đây, IP và user agent ghi
 * lại ở service.
 */
class StoreQuoteRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'slugs'   => ['required', 'array', 'min:1', 'max:' . QuoteRequestService::TOI_DA_MUC],
            'slugs.*' => ['string', 'max:191'],

            'contact_name'    => ['required', 'string', 'max:255'],
            'contact_email'   => ['required', 'email', 'max:255'],
            'contact_phone'   => ['nullable', 'string', 'max:30'],
            'contact_company' => ['nullable', 'string', 'max:255'],

            'campaign_start' => ['nullable', 'date'],
            'campaign_end'   => ['nullable', 'date', 'after_or_equal:campaign_start'],

            // VND số nguyên (CLAUDE.md mục 6). Trần 1.000 tỷ: không phải một
            // giới hạn nghiệp vụ mà là chặn ngón tay trượt phím — một con số
            // mười sáu chữ số làm tràn cột và làm owner đọc nhầm quy mô.
            'budget_vnd' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],

            'notes' => ['nullable', 'string', 'max:2000'],

            // Bắt buộc đồng ý chính sách bảo mật. Người gửi để lại email và
            // điện thoại, nên đây là điều kiện để thu thập chúng, không phải
            // một ô tick cho đẹp.
            'dong_y_chinh_sach' => ['accepted'],

            // Ô mồi ẩn: người thật không bao giờ điền. Cùng công thức với
            // `StorePublicReflectionRequest`.
            'website' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return [
            'slugs'             => 'danh sách vị trí',
            'contact_name'      => 'họ tên',
            'contact_email'     => 'email',
            'contact_phone'     => 'số điện thoại',
            'contact_company'   => 'tên đơn vị',
            'campaign_start'    => 'ngày bắt đầu',
            'campaign_end'      => 'ngày kết thúc',
            'budget_vnd'        => 'ngân sách dự kiến',
            'notes'             => 'ghi chú',
            'dong_y_chinh_sach' => 'đồng ý chính sách bảo mật',
        ];
    }

    public function messages(): array
    {
        return [
            'slugs.required' => 'Chưa chọn vị trí nào để hỏi giá.',
            'slugs.max'      => 'Một yêu cầu nhận tối đa :max vị trí.',
            'contact_email.required' => 'Cần email để media owner gửi báo giá lại cho bạn.',
            'campaign_end.after_or_equal' => 'Ngày kết thúc không thể trước ngày bắt đầu.',
            'dong_y_chinh_sach.accepted'  => 'Cần đồng ý chính sách bảo mật để gửi yêu cầu.',
            'website.prohibited' => 'Yêu cầu không hợp lệ.',
        ];
    }

    /**
     * Một cửa sổ chiến dịch chỉ có ngày kết thúc là một cửa sổ không đọc
     * được: owner không biết nó bắt đầu từ bao giờ, và luật `after_or_equal`
     * ở trên bỏ qua khi `campaign_start` trống.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('campaign_end') && ! $this->filled('campaign_start')) {
                    $validator->errors()->add(
                        'campaign_start',
                        'Đã có ngày kết thúc thì cần cả ngày bắt đầu.',
                    );
                }
            },
        ];
    }
}
