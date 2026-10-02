<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FrontpageListingRequest extends FormRequest
{
    /** Tham số nhận nhiều giá trị. */
    private const LIST_PARAMS = [
        'city', 'province', 'venue_type', 'screen_type',
        'orientation', 'network', 'owner', 'district', 'region',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Cho phép gửi nhiều giá trị dưới dạng **một khóa, phân tách bằng dấu
     * phẩy** — ngoài dạng `city[]=` đang dùng (Codex R37).
     *
     * Vì sao: đặc tả OpenAPI của tôi khai `style: form, explode: true`, tức
     * client sinh ra `city=hanoi&city=hcm`. PHP **không** ghép khóa lặp thành
     * mảng — nó lấy giá trị cuối và bỏ các giá trị trước. Luật `array` ở dưới
     * liền trả 422, nên bộ lọc khai trong tài liệu không dùng được. Phép kiểm
     * hai chiều đặc tả ↔ route vẫn xanh vì nó không kiểm cách serialize.
     *
     * `city=hanoi,hcm` là dạng OpenAPI chuẩn (`explode: false`), một khóa,
     * không mất phần tử nào, và PHP đọc được nguyên vẹn. Dạng `city[]=` cũ
     * vẫn chạy nên trang Blade không đổi. Dấu `|` giữ lại vì
     * `FrontpageService::resolveArrayParam()` đã hỗ trợ từ trước.
     */
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (self::LIST_PARAMS as $key) {
            $value = $this->input($key);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $parts = preg_split('/[,|]/', $value) ?: [];
            $parts = array_values(array_filter(array_map('trim', $parts), fn ($v) => $v !== ''));

            if ($parts !== []) {
                $normalized[$key] = $parts;
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'q'             => 'nullable|string|max:100',
            'city'          => 'nullable|array|max:10',
            'city.*'        => 'string|max:50',
            'province'      => 'nullable|array|max:10',
            'province.*'    => 'string|max:50',
            'venue_type'    => 'nullable|array|max:10',
            'venue_type.*'  => 'string|max:100',
            'screen_type'   => 'nullable|array|max:5',
            'screen_type.*' => 'in:billboard,led,lcd',
            'orientation'   => 'nullable|array|max:3',
            'orientation.*' => 'in:landscape,portrait,square',
            'network'       => 'nullable|array|max:10',
            'network.*'     => 'string|max:100',
            'owner'         => 'nullable|array|max:10',
            'owner.*'       => 'string|max:100',
            'district'      => 'nullable|array|max:20',
            'district.*'    => 'string|max:50',
            'region'        => 'nullable|array|max:3',
            'region.*'      => 'in:north,central,south',
            'site'          => 'nullable|string|max:50',
            'group'         => 'nullable|in:screen,network,site',
            'min_price'     => 'nullable|numeric|min:0',
            'max_price'     => 'nullable|numeric|min:0',
            'sort'          => 'nullable|in:price_asc,price_desc,newest',
            'page'          => 'nullable|integer|min:1',
        ];
    }
}
