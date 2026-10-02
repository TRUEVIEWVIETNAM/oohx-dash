<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;

/**
 * Chi tiết sản phẩm — mở rộng DTO danh sách.
 *
 * Thêm mô tả dài, ảnh, thông số, các gói chọn được, và danh sách màn hình
 * thuộc sản phẩm. Màn hình dùng đúng `ScreenSummaryResource` như mọi endpoint
 * khác, nên bên tiêu thụ chỉ cần một kiểu dữ liệu cho màn hình.
 */
class ProductDetailResource extends ProductSummaryResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'description' => $this->description,
            'photo_urls'  => $this->photo_urls,

            // `specs` là JSON tự do do quản trị nhập, đã hiển thị công khai
            // trên trang sản phẩm. Trả nguyên vẹn, và nói rõ hệ quả: **bất cứ
            // gì nhập vào đây đều là công khai.** Không whitelist được theo
            // khóa vì bộ khóa đổi theo từng loại sản phẩm — nên chỗ phải chặn
            // là lúc nhập, không phải lúc trả.
            'specs' => $this->specs ?: null,

            // Các gói chọn được. **Whitelist theo khóa**, không trả nguyên blob
            // JSON: thêm một trường mới vào repeater ở Filament là nó tự động
            // ra ngoài nếu trả nguyên blob.
            'package_options' => collect($this->package_options ?? [])
                ->map(fn (array $option) => [
                    'name'     => $option['name'] ?? null,
                    'quantity' => isset($option['quantity']) ? (int) $option['quantity'] : null,
                    'price'    => $this->amountOrNull($option['price'] ?? null),
                ])
                ->values()
                ->all(),

            'screens' => ScreenSummaryResource::collection($this->screens)->resolve(),

            'seo' => [
                'meta_title'       => $this->meta_title,
                'meta_description' => $this->meta_description,
            ],
        ];
    }
}
