<?php

namespace App\Http\Resources\V2;

use App\Http\Resources\V2\Concerns\SuyRaLoaiManHinh;
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
    use SuyRaLoaiManHinh;

    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $loai = $this->resolveScreenType();

        return [
            'slug' => $this->slug,
            'name' => $this->name,

            // Toạ độ nằm ở site, không ở screen. Pin không có toạ độ đã bị
            // truy vấn loại từ trước, nên tới đây luôn có số thật.
            'lat' => (float) $this->site?->lat,
            'lng' => (float) $this->site?->lon,

            'city'     => $this->site?->city,
            'address'  => $this->site?->address,

            // Ba trường thêm 17/10/2026 cho trang `/map` (lát 6).
            //
            // `site.name`: bản đồ bản Blade gom pin **theo site** ở máy chủ —
            // một marker cho một toà nhà, có badge số màn hình. Bản Next gom ở
            // trình duyệt, và không có tên site thì không gom được: hai màn
            // hình cùng toà nhà trùng toạ độ và chồng lên nhau thành một chấm.
            //
            // `network` và `venue_type`: nhãn trên pin và **màu** pin đọc hai
            // thứ này (`OOHXMap.pinColor`). Thiếu chúng thì mọi pin một màu và
            // không mang chữ gì — đúng tình trạng bản Next hôm nay.
            //
            // Ba chuỗi ngắn, và DTO này vẫn cố tình gọn: không thêm thông số,
            // không thêm ảnh thứ hai, không thêm owner logo (pin rich dùng chữ
            // cái đầu khi thiếu logo).
            'site' => $this->site ? ['name' => $this->site->name] : null,

            'network' => $this->site?->network ? [
                'code' => $this->site->network->code,
                'name' => $this->site->network->name,
            ] : null,

            'venue_type' => $this->inventory?->venue_type,

            // Hai trường thêm 22/10/2026 — lát 1 của
            // `docs/PLAN-GOP-EXPLORE-BAN-DO.md`.
            //
            // Trang gộp dựng thẻ trong panel **từ pin** sau mỗi lần người dùng
            // bấm "Tìm trong khu vực này". Thẻ hôm nay in loại hình và kích
            // thước; thiếu hai trường này thì danh sách nghèo đi đúng lúc
            // người dùng vừa thao tác — ngược chiều kỳ vọng, và không có lỗi
            // nào để lần ra.
            //
            // Giá phải trả: hai trường × tối đa 500 pin. Chấp nhận được vì cả
            // hai đọc từ `spec`, vốn **đã** nằm trong `with()` của
            // `getMapPins()` kèm đúng hai cột `width_cm, height_cm` — không
            // thêm truy vấn, không thêm cột.
            //
            // Cố ý **không** thêm `screen_type` (mã): không chỗ nào hiển thị
            // mã, lọc thì lọc ở máy chủ. DTO này vẫn gọn.
            'screen_type_label' => self::SCREEN_TYPE_LABELS[$loai] ?? $loai,

            'size' => $this->kichThuocMet(),

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
