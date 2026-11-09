<?php

namespace App\Http\Resources\V2\Concerns;

use App\Models\VenueCategory;

/**
 * Suy ra loại màn hình, và bảng chữ của nó.
 *
 * ══ Vì sao là một trait, không phải chép sang DTO thứ hai ══
 *
 * `screen_type` **không phải một cột**: nó suy ra từ kích thước và
 * `venue_type`. Trước 22/10/2026 phép suy đó sống trong
 * `ScreenSummaryResource` và chỉ `GET /api/v2/screens` trả nó. Lát 1 của
 * `docs/PLAN-GOP-EXPLORE-BAN-DO.md` cần đúng nhãn ấy trên pin bản đồ: sau khi
 * người dùng bấm "Tìm trong khu vực này", thẻ trong panel dựng lại từ pin, và
 * thiếu nhãn thì danh sách **nghèo đi** đúng lúc người dùng thao tác.
 *
 * Chép ngưỡng 300cm sang tệp thứ hai là cách chắc chắn nhất để hai trang nói
 * hai chuyện khác nhau về cùng một màn hình, mà không có gì đỏ lên — mỗi bên
 * vẫn tự nhất quán với chính nó. Đó đúng là cái bẫy docblock cũ của
 * `NGUONG_BILLBOARD_CM` đã cảnh báo, nên phép suy chuyển về đây và cả hai DTO
 * dùng chung.
 *
 * Hằng số khai trong trait (PHP 8.2+) nên `ScreenSummaryResource::
 * SCREEN_TYPE_LABELS` vẫn gọi được y như cũ — `FrontpageService`,
 * `CatalogController` và `NhanLoaiManHinhTest` không phải đổi một dòng nào.
 */
trait SuyRaLoaiManHinh
{
    /**
     * Nhãn người đọc cho `screen_type`.
     *
     * Nhãn nằm cạnh chỗ sinh ra mã: thêm một nhánh ở `resolveScreenType()` mà
     * quên thêm nhãn sẽ in một mã thô ra trang, và `NhanLoaiManHinhTest` đỏ.
     *
     * Vì sao API trả nhãn thay vì để Next tự map: bảng chữ chép sang client là
     * bản thứ hai của cùng một enum — đúng thứ `NhanEnumMotNoiTest` tồn tại để
     * chống. Ngày 18/10/2026 thẻ màn hình trên production in `lcd` ra mặt
     * người dùng vì không ai có bảng này.
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
     * dựng **cùng** ngưỡng bằng SQL. Hai con số 300 viết rời ở hai tệp thì sửa
     * một chỗ là đủ để nhãn trên thẻ và bộ lọc nói hai chuyện khác nhau.
     */
    public const NGUONG_BILLBOARD_CM = 300;

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
     *
     * Đọc `spec` và `inventory` của chính bản ghi đang bọc. Cả hai quan hệ đã
     * nằm trong `with()` của `FrontpageService::getMapPins()` và của truy vấn
     * danh sách, nên không sinh truy vấn thêm. `vn_category_id` là cột vô
     * hướng của `inventory`, đã nằm trong mọi `with(['inventory:…'])` sẵn có —
     * nên nhánh ngoài trời cũng không nạp thêm quan hệ nào.
     *
     * ══ Nhánh `led` từng là mã CHẾT trên production — sửa 08/11/2026 ══
     *
     * Bản trước so `$this->inventory?->venue_type === 'outdoor'`. Hai điều
     * cùng sai ở một dòng:
     *
     *  · `'outdoor'` **không có trong taxonomy**. `VenueCategorySeeder` dựng
     *    12 danh mục và mã ngoài trời là `roadside`;
     *  · cột `screen_inventory.venue_type` là **NULL trên cả 104 bản ghi**
     *    production — nó là cột tự do thời `/api/v1`, còn taxonomy thật của
     *    sàn là khoá ngoại `vn_category_id` → `venue_categories`.
     *
     * Nên điều kiện ấy **không bao giờ đúng**, và mọi màn hình không đạt ngưỡng
     * billboard đều rơi vào `lcd`. Đo trên production 08/11/2026:
     * `billboard 84 · led 0 · lcd 20` — trong đó **19 trong 20** bản ghi mang
     * nhãn "LCD" có tên là `OOHX_LED_<tỉnh>`, và bản ghi thứ 20 là màn hình
     * trong Aeon Mall (danh mục `retail`), thứ duy nhất thật sự không phải LED.
     *
     * Bộ lọc cột trái của `/explore` in **LED 0** và hiện mờ không bấm được,
     * nên cả một loại hình của sàn biến mất khỏi trang tìm kiếm.
     *
     * ══ Vì sao bộ test KHÔNG bắt được ══
     *
     * `LoaiHinhNhanKhopBoLocTest` dựng fixture với `venue_type: 'outdoor'` và
     * `'mall'` — một từ vựng **không tồn tại trong dữ liệu thật**. Hai phía
     * (nhãn và bộ lọc) đọc cùng một hằng số sai, nên chúng khớp nhau hoàn hảo
     * và ca test xanh, trong khi production trả 0. Fixture nay dùng
     * `vn_category_id` của danh mục thật, và có một ca dựng đúng trạng thái
     * production: `venue_type` NULL, danh mục `roadside`.
     *
     * ══ Chỗ phép suy này VẪN không biết, nói ra ══
     *
     * Nó không trả lời được "màn hình này là LED, LCD hay in bạt", vì **không
     * cột nào trong CSDL ghi công nghệ**. Nó suy từ *kích thước* và *loại điểm
     * đặt* — hai câu hỏi khác. Hệ quả cụ thể còn lại sau lần sửa này:
     * `OOHX_LED_CTO_CAN_THO` là một màn LED nhưng khai 23,04 × 28,8 m nên vượt
     * ngưỡng và mang nhãn "Billboard"; và 19 bản ghi kia ra `led` **chỉ vì
     * chúng chưa khai kích thước** — khai đúng kích thước thật thì chúng thành
     * `billboard` và LED về 0 lần nữa.
     *
     * ══ 08/11/2026: `spec.cong_nghe` vào, và nó đi TRƯỚC phép đoán ══
     *
     * Cột `screen_specs.cong_nghe` (`led` / `lcd` / `in_bat`, `null` = chưa
     * khai) là trường đầu tiên trên sàn **ghi** công nghệ thay vì suy nó. Thứ
     * tự dưới đây là thứ tự tin cậy, và mỗi bậc có một lý do:
     *
     *  1. **`in_bat` đã khai ⇒ `billboard`.** Một tấm in bạt không phải màn
     *     hình, bất kể cỡ nào. Bậc này đứng trước cả ngưỡng kích thước vì nó
     *     sửa một ca sai thật: một pa-nô in bạt nhỏ hơn 3m, nếu để rơi xuống
     *     phép đoán, sẽ ra `led` hoặc `lcd` — tức sàn gọi một tấm bạt là một
     *     màn hình.
     *  2. **Cạnh ≥ 3m ⇒ `billboard`.** Giữ nguyên, và vẫn đứng trên hai bậc
     *     dưới: nhãn `billboard` của `screen_type` nói về **hình thức**, nên
     *     một màn LED 6m vẫn là một billboard. Loại hình LED của nó đọc ở
     *     trường `cong_nghe`, không ở đây.
     *  3. **`led` / `lcd` đã khai ⇒ đúng thứ đã khai.** Bậc này là cả điểm
     *     của cột mới: với màn hình không đạt ngưỡng, `screen_type` thôi đoán.
     *  4. **Chưa khai ⇒ đoán theo loại điểm đặt.** Nhánh cũ, giữ lại cho 104
     *     bản ghi hiện có. Nó sẽ teo dần theo số vị trí được khai, và đó là
     *     hình dạng đúng: một phép đoán chỉ chạy ở chỗ chưa có câu trả lời.
     *
     * `screen_type` vẫn **không** trả `in_bat` — nó chỉ có ba mã. Đó là lý do
     * bộ lọc công nghệ là một bộ lọc riêng (`?cong_nghe=`) chứ không nhồi thêm
     * mã vào đây: hai câu hỏi khác nhau thì hai trường, và một trường gộp hai
     * câu là đúng cái sai mà cột mới tồn tại để sửa.
     */
    private function resolveScreenType(): string
    {
        $congNghe = $this->spec?->cong_nghe;

        // Bậc 1 — xem docblock. `in_bat` là câu trả lời duy nhất mà một tấm
        // nhỏ vẫn phải ra `billboard`.
        if ($congNghe === 'in_bat') {
            return 'billboard';
        }

        $widthCm  = $this->spec?->width_cm ?? 0;
        $heightCm = $this->spec?->height_cm ?? 0;

        if ($widthCm >= self::NGUONG_BILLBOARD_CM || $heightCm >= self::NGUONG_BILLBOARD_CM) {
            return 'billboard';
        }

        // Bậc 3 — đã khai thì không đoán nữa.
        if ($congNghe === 'led' || $congNghe === 'lcd') {
            return $congNghe;
        }

        // `(int)` chứ không so lỏng: khoá ngoại có thể về dạng chuỗi tuỳ driver,
        // và `in_array` lỏng coi `0 == 'roadside'` là đúng ở PHP cũ. `null`
        // thành `0`, không khớp id nào — đúng ý "chưa khai thì không phải LED".
        $danhMuc = (int) ($this->inventory?->vn_category_id ?? 0);

        return in_array($danhMuc, VenueCategory::idNgoaiTroi(), true) ? 'led' : 'lcd';
    }

    /**
     * Kích thước vật lý tính bằng **mét**, làm tròn hai chữ số.
     *
     * Cột lưu bằng xăng-ti-mét; mọi nơi hiển thị đều đổi sang mét, nên phép
     * đổi ở đây chứ không lặp ở từng DTO. `null` khi chưa đo — khác `0`, vốn
     * có nghĩa là đã đo và bằng không.
     *
     * @return array{width_m: float|null, height_m: float|null}
     */
    private function kichThuocMet(): array
    {
        $spec = $this->spec;

        return [
            'width_m'  => $spec?->width_cm ? round($spec->width_cm / 100, 2) : null,
            'height_m' => $spec?->height_cm ? round($spec->height_cm / 100, 2) : null,
        ];
    }
}
