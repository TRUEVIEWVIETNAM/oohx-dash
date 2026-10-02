<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một dòng giỏ hàng — DTO danh sách trắng.
 *
 * **Số tiền ở đây là của máy chủ.** `estimated_cost` do
 * `CartService::estimateCost()` tính từ cấu hình kho; API trả nó ra để hiển
 * thị, và **không** nhận lại nó ở chiều ngược lại (xem
 * `UpdateCartItemRequest`, không có trường tiền nào).
 *
 * `rate_snapshot` **không** ra ngoài. Đó là ảnh chụp cấu hình giá của media
 * owner tại lúc thêm giỏ — dùng để phát hiện giá đổi trước khi chốt đơn — và
 * nó mang nguyên cấu hình kho, không phải thứ người mua cần thấy. Thứ người
 * mua cần biết là *giá có đổi hay không*, và đó là việc của bước chốt đơn.
 */
class CartItemResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            // `id` của dòng giỏ RA ngoài, khác với các DTO công khai.
            //
            // Cố ý: client phải gọi được PATCH/DELETE lên đúng dòng này, và
            // dòng giỏ là dữ liệu riêng của chính người đang đăng nhập — nó
            // không phải khóa của tài nguyên dùng chung. Không có slug cho nó.
            'id' => $this->id,

            'screen'  => $this->screen ? (new ScreenSummaryResource($this->screen))->resolve() : null,
            'product' => $this->product ? [
                'slug' => $this->product->slug,
                'name' => $this->product->name,
            ] : null,

            'buy_mode' => $this->buy_mode,

            'period' => [
                'start_date' => $this->start_date?->toDateString(),
                'end_date'   => $this->end_date?->toDateString(),
            ],

            'delivery' => [
                'pricing_model'      => $this->pricing_model,
                'share_of_voice_pct' => $this->share_of_voice_pct !== null ? (int) $this->share_of_voice_pct : null,
                'spot_length'        => $this->spot_length !== null ? (int) $this->spot_length : null,
                'booked_cpms'        => $this->booked_cpms !== null ? (int) $this->booked_cpms : null,
                'duration_units'     => $this->duration_units !== null ? (int) $this->duration_units : null,
                'duration_unit'      => $this->duration_unit,
            ],

            'estimate' => [
                'currency'    => 'VND',
                'cost'        => $this->estimated_cost !== null ? (int) round((float) $this->estimated_cost) : null,
                'impressions' => $this->estimated_impressions !== null ? (int) $this->estimated_impressions : null,
                'duration_discount_pct' => $this->duration_discount_pct !== null ? (int) $this->duration_discount_pct : null,
            ],

            'notes' => $this->notes,
        ];
    }
}
