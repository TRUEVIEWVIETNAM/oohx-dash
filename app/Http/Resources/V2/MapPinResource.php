<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một pin trên bản đồ — DTO danh sách trắng, **cố tình gọn**.
 *
 * Bản đồ trả về hàng trăm bản ghi một lúc, nên mỗi trường thừa phải nhân lên
 * hàng trăm lần. Ở đây chỉ có thứ cần để vẽ pin và mở popup; muốn đầy đủ thì
 * gọi `/api/v2/screens/{slug}`.
 *
 * Giữ nguyên nguyên tắc của `ScreenSummaryResource`: liệt kê trường muốn trả,
 * không loại trường muốn ẩn.
 */
class MapPinResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,

            // Toạ độ nằm ở site, không ở screen. Pin không có toạ độ đã bị
            // truy vấn loại từ trước, nên tới đây luôn có số thật.
            'lat' => (float) $this->site?->lat,
            'lng' => (float) $this->site?->lon,

            'city'     => $this->site?->city,
            'address'  => $this->site?->address,
            'owner'    => [
                'slug' => $this->owner?->slug,
                'name' => $this->owner?->name,
            ],

            // Giá hiển thị: `io_rate` nếu bán theo kỳ, ngược lại là giá CPM.
            // Trả kèm đơn vị vì hai con số này không cùng thang đo — thiếu
            // đơn vị là mời người đọc so sánh nhầm.
            'price' => [
                'amount_vnd' => $this->inventory
                    ? (int) round((float) $this->inventory->display_price)
                    : null,
                'unit' => $this->inventory?->display_price_unit,
            ],

            'photo_url' => $this->display_photo,
        ];
    }
}
