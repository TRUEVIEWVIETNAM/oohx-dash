<?php

namespace App\Http\Requests;

use App\Models\Owner;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bộ luật kiểm cho danh sách sản phẩm — dùng chung `/products` và
 * `/api/v2/products`.
 *
 * Dùng chung chứ không chỉ cho API, vì trang Blade `/products` đang có **đúng
 * lỗi R40**: `ProductService::getProductsPaginated()` nối thẳng
 * `"%{$q}%"`, nên `?q[]=x` cho "Array to string conversion" và một trang công
 * khai trả 500. Sửa một nửa rồi để nửa kia nguyên là biết lỗi mà bỏ đó.
 *
 * `owner` nhận **slug**, không nhận id. Service lọc theo `owner_id` nên request
 * này quy đổi; API không được bắt client đoán id nội bộ, và chính test của tôi
 * đã chốt điều đó cho endpoint bộ lọc.
 */
class ProductListingRequest extends FormRequest
{
    /** Tham số nhận nhiều giá trị, cho phép dạng `a,b` hoặc `a|b` hoặc `a[]=`. */
    private const LIST_PARAMS = ['category', 'city'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (self::LIST_PARAMS as $key) {
            $value = $this->input($key);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $parts = array_values(array_filter(
                array_map('trim', preg_split('/[,|]/', $value) ?: []),
                fn ($v) => $v !== '',
            ));

            if ($parts !== []) {
                $merge[$key] = $parts;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'q'          => 'nullable|string|max:100',
            'category'   => 'nullable|array|max:10',
            'category.*' => 'string|in:' . implode(',', array_keys(Product::CATEGORIES)),
            'type'       => 'nullable|string|in:' . implode(',', [Product::TYPE_SINGLE, Product::TYPE_PACKAGE, Product::TYPE_CUSTOM]),
            'city'       => 'nullable|array|max:10',
            'city.*'     => 'string|max:50',
            'owner'      => 'nullable|string|max:100',
            'min_price'  => 'nullable|numeric|min:0',
            'max_price'  => 'nullable|numeric|min:0',
            'sort'       => 'nullable|in:price_asc,price_desc,newest',
            'page'       => 'nullable|integer|min:1',
            'per_page'   => 'nullable|integer|min:1',
        ];
    }

    /**
     * Request để chuyển cho service: `owner` đã quy đổi từ slug sang id.
     *
     * Slug không tồn tại thì đặt một id không thể khớp, để kết quả là **rỗng**
     * chứ không phải "bỏ qua bộ lọc và trả cả kho" — bỏ qua một bộ lọc không
     * nhận ra là cách âm thầm trả nhiều hơn người gọi yêu cầu.
     */
    public function forService(): self
    {
        $slug = $this->input('owner');

        if (is_string($slug) && $slug !== '') {
            $ownerId = Owner::where('slug', $slug)->value('id');
            $this->merge(['owner' => $ownerId ?: '~khong-ton-tai~']);
        }

        return $this;
    }
}
