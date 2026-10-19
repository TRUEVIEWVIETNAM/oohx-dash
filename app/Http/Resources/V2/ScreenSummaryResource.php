<?php

namespace App\Http\Resources\V2;

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
     * Nhãn người đọc cho `screen_type`.
     *
     * Đặt ở đây chứ không trên model, vì `screen_type` **không phải một cột**
     * — nó suy ra từ kích thước và `venue_type` ngay dưới, trong
     * `resolveScreenType()`. Nhãn phải nằm cạnh chỗ sinh ra mã, nếu không thì
     * thêm một nhánh ở đó mà quên thêm nhãn sẽ ra một mã thô trên trang.
     *
     * Vì sao API trả nhãn thay vì để Next tự map: nhãn là một bảng chữ, và
     * bảng chữ chép sang client là bản thứ hai của cùng một enum — đúng thứ
     * `NhanEnumMotNoiTest` tồn tại để chống. Ngày 18/10/2026 thẻ màn hình
     * trên production in `lcd` ra mặt người dùng vì không ai có bảng này.
     */
    public const SCREEN_TYPE_LABELS = [
        'billboard' => 'Billboard',
        'led'       => 'LED',
        'lcd'       => 'LCD',
    ];

    /**
     * Từ cạnh này trở lên thì gọi là billboard, tính bằng **xăng-ti-mét**.
     *
     * Hằng số công khai vì `FrontpageService::applyScreenTypeFilter()` phải
     * dựng **cùng** ngưỡng bằng SQL. Hai con số 300 viết rời ở hai tệp thì
     * sửa một chỗ là đủ để nhãn trên thẻ và bộ lọc nói hai chuyện khác nhau
     * — và không có gì đỏ lên, vì mỗi bên tự nhất quán với chính nó.
     */
    public const NGUONG_BILLBOARD_CM = 300;

    /** Giá trị `venue_type` tách LED khỏi LCD. Cùng lý do như hằng số trên. */
    public const VENUE_NGOAI_TROI = 'outdoor';

    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $inventory = $this->inventory;
        $spec      = $this->spec;
        $loai      = $this->resolveScreenType();

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

            'size' => [
                'width_m'  => $spec?->width_cm ? round($spec->width_cm / 100, 2) : null,
                'height_m' => $spec?->height_cm ? round($spec->height_cm / 100, 2) : null,
            ],

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

            'photo_url' => $this->display_photo,
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

    /**
     * Ba nhóm, và **mọi** màn hình rơi vào đúng một nhóm.
     *
     * Không có dòng `screen_specs` thì hai cạnh là 0, nên nó không phải
     * billboard — nó vẫn là LED hoặc LCD, không phải "không phân loại được".
     * Đó là hành vi cố ý: 20/104 bản ghi trên production không có spec, và
     * một thẻ không nói nổi mình là loại gì thì vô dụng với người mua.
     *
     * `FrontpageService::applyScreenTypeFilter()` phải nhận đúng ba tập này.
     * Ngày 19/10/2026 nó không: cả ba nhánh của nó đều đòi có spec, nên 20
     * bản ghi kia mang nhãn "LCD" trên thẻ trong khi bộ lọc LCD trả 0.
     */
    private function resolveScreenType(): string
    {
        $widthCm  = $this->spec?->width_cm ?? 0;
        $heightCm = $this->spec?->height_cm ?? 0;

        if ($widthCm >= self::NGUONG_BILLBOARD_CM || $heightCm >= self::NGUONG_BILLBOARD_CM) {
            return 'billboard';
        }

        return ($this->inventory?->venue_type === self::VENUE_NGOAI_TROI) ? 'led' : 'lcd';
    }
}
