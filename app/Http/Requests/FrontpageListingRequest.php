<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FrontpageListingRequest extends FormRequest
{
    /** Tham số nhận nhiều giá trị. */
    private const LIST_PARAMS = [
        'city', 'province', 'venue_type', 'screen_type',
        'orientation', 'network', 'owner', 'district', 'region',
        'price_status',
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
            /*
             * Trạng thái giá: đã báo giá hay chưa.
             *
             * Khác `min_price`/`max_price` về bản chất. Hai tham số kia hỏi
             * "giá nằm trong khoảng nào" — một câu hỏi chỉ trả lời được khi
             * có giá. Đo trên production ngày 18/10/2026: **1 trong 50** bản
             * ghi có giá, và `/api/v2/filters` trả `price_range {0, 0}`, tức
             * thanh lọc giá không lọc được gì.
             *
             * Nên tham số này hỏi câu trả lời được: *đã có giá, hay chưa?*
             */
            'price_status'   => 'nullable|array|max:2',
            'price_status.*' => 'in:quoted,unquoted',

            'site'          => 'nullable|string|max:50',
            'group'         => 'nullable|in:screen,network,site',
            'min_price'     => 'nullable|numeric|min:0',
            'max_price'     => 'nullable|numeric|min:0',
            'sort'          => 'nullable|in:price_asc,price_desc,newest,distance',
            'page'          => 'nullable|integer|min:1',

            /*
             * Toạ độ để sắp theo khoảng cách (22/10/2026).
             *
             * `required_if:sort,distance` chứ không lặng lẽ bỏ qua: một yêu
             * cầu `sort=distance` thiếu toạ độ mà vẫn trả 200 sẽ trả về danh
             * sách theo thứ tự mặc định, và bên gọi tin rằng nó đã được sắp
             * theo khoảng cách. Một 422 nói thẳng còn hơn một danh sách sai
             * thứ tự mà trông bình thường.
             *
             * `required_with` hai chiều: một toạ độ nửa vời (chỉ lat, không
             * lng) không chỉ vô dụng mà còn là dấu hiệu bên gọi đang dựng URL
             * sai.
             *
             * Hai giá trị này là **vị trí của một người**. Chúng đi trong
             * thân yêu cầu API cùng nguồn gốc, KHÔNG nằm trong URL của trang
             * — URL rơi vào log máy chủ, header `Referer` và nút chia sẻ.
             */
            'near_lat'      => 'nullable|numeric|between:-90,90|required_if:sort,distance|required_with:near_lng',
            'near_lng'      => 'nullable|numeric|between:-180,180|required_if:sort,distance|required_with:near_lat',
        ];
    }
}
