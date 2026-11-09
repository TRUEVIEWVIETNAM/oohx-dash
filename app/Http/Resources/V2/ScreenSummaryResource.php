<?php

namespace App\Http\Resources\V2;

use App\Http\Resources\V2\Concerns\SuyRaLoaiManHinh;
use App\Models\ScreenSpec;
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
     * `SCREEN_TYPE_LABELS`, `NGUONG_BILLBOARD_CM` và `resolveScreenType()`
     * đến từ đây.
     *
     * `VENUE_NGOAI_TROI` đã **bỏ** ngày 08/11/2026: nó là chuỗi `'outdoor'`,
     * một mã không có trong taxonomy và trỏ vào một cột NULL trên toàn bộ
     * production. Kiến thức "danh mục nào là ngoài trời" nay sống ở
     * `VenueCategory::SLUG_NGOAI_TROI`, cạnh chính bảng taxonomy.
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

            // Công nghệ màn hình — lát 1 của `docs/PLAN-CONG-NGHE-MAN-HINH.md`.
            //
            // Có mặt ở DTO **danh sách** chứ không chỉ ở chi tiết, vì cột lọc
            // của `/explore` vẽ chip "LED / LCD / In bạt" ngay trên lưới thẻ:
            // không có trường này thì thẻ không nói nổi nó thuộc chip nào, và
            // người dùng lọc xong vẫn không thấy vì sao một thẻ lọt vào.
            //
            // Hai khoá `null` nghĩa là **chưa khai** — thẻ in "chưa khai" chứ
            // không bỏ trống, cùng lý do như trang chi tiết.
            'cong_nghe'       => $this->spec?->cong_nghe,
            'cong_nghe_label' => $this->spec?->cong_nghe !== null
                ? (ScreenSpec::CONG_NGHE_LABELS[$this->spec->cong_nghe] ?? $this->spec->cong_nghe)
                : null,

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
             * ══ Tấm ảnh này là ảnh của BIỂN, hay một ảnh thay thế? ══
             *
             * Thêm 09/11/2026 — lát 3 của `docs/PLAN-GHEP-MOCKUP.md`.
             *
             * Trường này đã có ở DTO **chi tiết** từ 24/10/2026, với lý do ghi
             * trong `ScreenDetailResource`: chuỗi ảnh dự phòng có năm bậc và
             * chỉ bậc đầu là ảnh của chính màn hình; bốn bậc còn lại cho người
             * mua xem một thứ KHÔNG phải mặt biển họ sắp thuê.
             *
             * Nhưng DTO **danh sách** thì không có nó, nên trang `/explore` —
             * nơi người mua sàng kho — không có cách nào nói ra. Đo trên
             * production: cả 104 vị trí dùng đúng hai tấm ảnh, và cả hai là ảnh
             * bìa owner. Lưới 104 thẻ hiện ra như 104 vị trí đều có ảnh.
             *
             * Không thêm truy vấn nào: `$anh = $this->displayPhotoWithSource()`
             * ở đầu hàm đã trả về cả `source`, và tới hôm nay nó bị bỏ đi.
             *
             * ── Vì sao đây KHÔNG phải một bản sao như `cong_nghe` đã từng ──
             *
             * `ScreenDetailResource` cũng trả `photo_source`, và vì DTO chi
             * tiết thừa kế DTO này nên khoá bị ghi đè — con thắng, chỗ đứng
             * của khoá thì theo cha. Hai giá trị ấy trả lời hai câu hỏi khác
             * nhau về hai phạm vi khác nhau:
             *
             *   · ở đây: nguồn của MỘT ảnh hiển thị trên thẻ;
             *   · ở chi tiết: nguồn của cả THƯ VIỆN `photo_urls`.
             *
             * Khác với `cong_nghe` — nơi hai nhánh tra cùng một bảng chữ cho
             * cùng một cột, tức một phép tính viết hai lần. Ở đây không có
             * bảng chữ nào để lệch; nhãn hiển thị sống một chỗ, ở
             * `NHAN_NGUON_ANH` của `ScreenCard`.
             */
            'photo_source' => $anh['source'],

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
