<?php

namespace App\Http\Resources\V2;

use App\Http\Resources\V2\Concerns\SuyRaLoaiManHinh;
use App\Services\AnhDanXuatService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Màn hình trong danh sách công khai — DTO **danh sách trắng**.
 *
 * Chỉ những trường liệt kê ở đây ra ngoài. Không bao giờ trả thẳng model:
 * `Screen` và các quan hệ của nó mang `device_token`, `internal_notes`, còn
 * `Owner` mang `revenue_share_pct`, `billing_info`, `bank_*`, `tax_code`,
 * `business_license_path`. Một lần `toArray()` trên model là lộ hết.
 *
 * Về giá: trường tên đúng nghĩa. `/api/v1` có `price_per_slot_vnd` trả giá CPM
 * dưới cái tên "giá một slot" và không sửa được nữa vì đối tác đang đọc (F09);
 * v2 không lặp lại — `floor_cpm_vnd` là giá cho 1.000 lượt hiển thị, `io_rate`
 * là giá thuê theo kỳ, và kỳ được nói rõ ở `io_rate_unit`.
 */
class ScreenSummaryResource extends JsonResource
{
    /**
     * `SCREEN_TYPE_LABELS`, `NGUONG_BILLBOARD_CM`, `VENUE_NGOAI_TROI` và
     * `resolveScreenType()` đến từ đây.
     *
     * Chuyển sang trait ngày 22/10/2026 để `MapPinResource` dùng **cùng** phép
     * suy, thay vì chép ngưỡng 300cm sang tệp thứ hai. Hằng số của trait vẫn
     * gọi được qua tên lớp này, nên mọi chỗ đang viết
     * `ScreenSummaryResource::SCREEN_TYPE_LABELS` không đổi.
     */
    use SuyRaLoaiManHinh;

    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $inventory = $this->inventory;
        $loai      = $this->resolveScreenType();

        // **Một** lượt đi chuỗi dự phòng cho cả URL và đường dẫn. Gọi
        // `$this->display_photo` rồi gọi lại `displayPhotoWithSource()` để lấy
        // đường dẫn là đi hết năm bậc hai lần cho mỗi hàng của một trang 24
        // hàng — và chuỗi đó chạm `site->network`, `owner`,
        // `inventory->vnCategory`.
        $anh = $this->displayPhotoWithSource();

        return [
            'slug'        => $this->slug,
            'name'        => $this->name,
            'screen_type' => $loai,

            // Mã ở trên để lọc và so khớp; nhãn ở đây để hiển thị. Giao diện
            // không được tự hoa hoá mã — xem `SCREEN_TYPE_LABELS`.
            'screen_type_label' => self::SCREEN_TYPE_LABELS[$loai] ?? $loai,

            'owner' => [
                'slug' => $this->owner?->slug,
                'name' => $this->owner?->name,
            ],

            'location' => [
                'site'     => $this->site?->name,
                'city'     => $this->site?->city,
                'district' => $this->location_district,
            ],

            'network' => $this->site?->network ? [
                'code' => $this->site->network->code,
                'name' => $this->site->network->name,
            ] : null,

            'size' => $this->kichThuocMet(),

            // Giá công khai, **kèm đơn vị tiền**.
            //
            // Bản trước trả `floor_cpm_vnd` bằng cách làm tròn `floor_cpm` và
            // bỏ qua `floor_cpm_currency` — cột đó nhận VND hoặc USD (xem
            // `ScreenImport\FieldCatalog`, và Filament có
            // `default_floor_cpm_currency`). Một hàng USD 2,50 ra ngoài thành
            // "3 VND": sai đơn vị, lệch nhiều bậc, và client không còn cách
            // nào tự sửa vì đã mất currency gốc (Codex R39).
            //
            // Không tự quy đổi ở đây: quy đổi cần một chính sách tỷ giá có
            // người chốt, không phải một hằng số lẻn vào DTO.
            'pricing' => [
                'model'     => $inventory?->pricing_model,
                'floor_cpm' => [
                    'amount'   => $this->decimalOrNull($inventory?->floor_cpm),
                    'currency' => $inventory?->floor_cpm_currency ?: 'VND',
                ],
                'io_rate' => [
                    'amount' => $inventory?->io_rate !== null ? (int) round((float) $inventory->io_rate) : null,
                    // `io_rate` không có cột currency trong CSDL, nên nó là
                    // VND theo định nghĩa. Nói ra ở đây để giả định đó nhìn
                    // thấy được trong phản hồi, chứ không nằm im trong đầu ai.
                    'currency' => 'VND',
                    'unit'     => $inventory?->io_rate_unit,
                ],
            ],

            'photo_url' => $anh['url'],

            /*
             * Bản hẹp hơn của cùng ảnh, để trình duyệt chọn — lát 4, 25/10/2026.
             *
             * `null` khi chưa có bản dẫn xuất nào, và giao diện phải **bỏ hẳn**
             * thuộc tính `srcset` khi đó. Không trả chuỗi rỗng: `srcset=""`
             * vẫn là một thuộc tính trình duyệt phải đọc.
             *
             * Máy chủ dựng cả chuỗi, không trả riêng từng bề rộng cho client
             * tự ghép: client ghép là client dựng đường dẫn tệp, và lát 1 vừa
             * gộp ba bản của phép dựng URL thành một. Thêm một bản ở phía
             * TypeScript là mở lại đúng cái khoảng đó — lần này ở nơi không
             * test PHP nào nhìn thấy.
             */
            'photo_srcset' => AnhDanXuatService::srcsetCho($anh['path']),

            /*
             * Khoảng cách tới toạ độ người gọi gửi lên, km — thêm 22/10/2026.
             *
             * `whenNotNull` chứ không luôn có mặt: cột này chỉ tồn tại khi
             * truy vấn chạy với `sort=distance`, và trả `null` ở mọi lượt
             * khác là thêm một trường rỗng vào **mọi** phản hồi danh sách.
             *
             * Nó là khoảng cách đường chim bay từ toạ độ người dùng tới
             * **site**, không phải quãng đường đi. Giao diện gọi nó là "cách
             * X km", không phải "X km lái xe".
             */
            'distance_km' => $this->whenNotNull(
                isset($this->khoang_cach_km) ? (float) $this->khoang_cach_km : null,
            ),
        ];
    }

    /**
     * Số tiền giữ đúng độ chính xác của dữ liệu.
     *
     * Không làm tròn về số nguyên: `floor_cpm` có thể là 2,50 USD, và làm
     * tròn thành 3 là làm sai dữ liệu chứ không phải làm gọn. Với VND thì
     * giá trị vốn đã nguyên nên trả về số nguyên.
     */
    private function decimalOrNull($value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $number = round((float) $value, 4);

        return $number === floor($number) ? (int) $number : $number;
    }

}
