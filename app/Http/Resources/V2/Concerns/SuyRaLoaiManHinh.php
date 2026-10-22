<?php

namespace App\Http\Resources\V2\Concerns;

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

    /** Giá trị `venue_type` tách LED khỏi LCD. Cùng lý do như hằng số trên. */
    public const VENUE_NGOAI_TROI = 'outdoor';

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
     * danh sách, nên không sinh truy vấn thêm.
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
