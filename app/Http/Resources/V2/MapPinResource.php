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
            //
            // Trả kèm `unit` vì hai con số này không cùng thang đo, và kèm
            // `currency` vì `floor_cpm` có thể lưu bằng USD. Bản trước gọi nó
            // là `amount_vnd` cho cả hai nhánh, nên một màn hình CPM tính
            // bằng USD ra ngoài với nhãn VND (Codex R39).
            'price' => $this->priceOf(),

            'photo_url' => $this->display_photo,
        ];
    }

    /**
     * Giá hiển thị kèm đơn vị tiền đúng của nhánh đang dùng.
     *
     * `io_rate` không có cột currency nên là VND theo định nghĩa; `floor_cpm`
     * có `floor_cpm_currency` và phải đi theo cột đó.
     *
     * @return array{amount: int|float|null, currency: string, unit: string|null}
     */
    private function priceOf(): array
    {
        $inventory = $this->inventory;

        if (! $inventory) {
            return ['amount' => null, 'currency' => 'VND', 'unit' => null];
        }

        $usesIoRate = $inventory->allowsIo() && $inventory->io_rate > 0;

        $amount = (float) $inventory->display_price;

        return [
            // Giá CPM có thể là số lẻ (2,50 USD) nên không làm tròn về nguyên
            // ở nhánh đó — làm tròn là làm sai dữ liệu, không phải làm gọn.
            'amount'   => $usesIoRate ? (int) round($amount) : $this->decimalOrNull($amount),
            'currency' => $usesIoRate ? 'VND' : ($inventory->floor_cpm_currency ?: 'VND'),
            'unit'     => $inventory->display_price_unit,
        ];
    }

    private function decimalOrNull($value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $number = round((float) $value, 4);

        return $number === floor($number) ? (int) $number : $number;
    }
}
