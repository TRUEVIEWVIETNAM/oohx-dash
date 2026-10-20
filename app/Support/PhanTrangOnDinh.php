<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Phân trang **có thứ tự xác định** — đường duy nhất gọi `paginate()` trong
 * các service danh sách.
 *
 * ══ Lỗi đo được trên production ngày 19/10/2026 ══
 *
 * Đọc hết ba trang của `GET /api/v2/screens` (104 bản ghi, 50 một trang) thì
 * được 104 dòng nhưng chỉ **75 slug duy nhất**: trang 1 và trang 2 trùng nhau
 * 29 vị trí, và 29 vị trí khác **không xuất hiện ở trang nào**. Với
 * `sort=price_asc` còn nặng hơn: 54/104.
 *
 * ══ Vì sao ══
 *
 * `LIMIT ... OFFSET ...` **không** cam kết thứ tự khi khoá sắp xếp có hàng
 * bằng nhau; cơ sở dữ liệu được tự do trả thứ tự nào rẻ nhất cho **từng lần
 * chạy**. Hai truy vấn khác `OFFSET` vì thế chồng lấn được mà không có gì sai
 * về mặt SQL.
 *
 * Và mọi danh sách công khai ở đây sắp theo đúng một cột không duy nhất:
 * `created_at` (màn hình, phản ánh, chiến dịch), giá quy đổi, `screen_count`
 * (mạng lưới, địa điểm), `name` (agency). Kho thật nhập theo lô nên
 * `created_at` trùng hàng loạt, và 103/104 bản ghi chưa có giá nên khoá sắp
 * theo giá là NULL cho gần hết — điều kiện hoàn hảo để lỗi lộ ra.
 *
 * ══ Khoá phụ là khoá chính của chính bảng đó ══
 *
 * Luôn duy nhất, nên thứ tự là toàn phần. ULID còn mang tiền tố thời gian,
 * nên trong một nhóm bằng nhau nó xếp theo lúc tạo chứ không ngẫu nhiên.
 *
 * Gọi `paginate()` thẳng ở chỗ khác là bỏ qua lớp này, nên có hai chốt tĩnh
 * canh rằng không nơi nào dùng trait còn lời gọi `paginate()` riêng:
 * `PhanTrangThuTuXacDinhTest` cho bốn service, `PhanTrangV1Test` cho bốn
 * controller `/api/v1`.
 *
 * ══ `/api/v1` dùng trait này từ ngày 20/10/2026 ══
 *
 * Bốn controller v1 mang đúng lỗi trên. Hoãn ở #130 vì `/api/v1` là hợp đồng
 * với đối tác (CLAUDE.md mục 1) và đổi thứ tự trả về là đổi hành vi. Chủ dự
 * án chốt sửa ngày 20/10.
 *
 * Điều làm nó an toàn: tài liệu hợp đồng (`docs/tapon_api_list.md`,
 * `docs/api_tapon_browse_filters_v1.md`) công bố **những khoá sắp xếp nào
 * tồn tại**, không ở đâu công bố thứ tự **trong một nhóm bằng nhau** — và
 * đó là tất cả những gì khoá phụ chạm tới. Một đối tác đang lật trang không
 * thể đã dựa vào thứ tự cũ, vì thứ tự cũ không tồn tại: nó đổi giữa các lần
 * chạy. Nên thay đổi quan sát được là từ *không xác định* sang *ổn định*,
 * không phải từ thứ tự này sang thứ tự khác.
 *
 * Một ngoại lệ đáng nói riêng: ba endpoint quản lý (`/owners`, `/sites`,
 * `/screens`) **không có `ORDER BY` nào** khi đối tác không gửi `sort`, nên
 * ở đó khoá phụ trở thành khoá sắp duy nhất và danh sách nay đi theo ULID
 * (≈ cũ nhất trước). Đây là chỗ duy nhất một đối tác có thể thấy khác trước,
 * và cái họ thấy trước đó là thứ tự MySQL thấy rẻ nhất hôm ấy.
 */
trait PhanTrangOnDinh
{
    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Spatie\QueryBuilder\QueryBuilder  $query
     *
     * `QueryBuilder` của Spatie không phải Builder, nhưng nó forward mọi lời
     * gọi lạ sang builder bên dưới nên `getModel()`, `orderBy()` và
     * `paginate()` đều chạy. Quan trọng: `allowedSorts()` nạp sort của
     * request vào query **ngay trong thân nó**, nên `orderBy()` gọi sau đó
     * là khoá phụ, không phải khoá chính.
     */
    protected function phanTrang($query, int $perPage): LengthAwarePaginator
    {
        return $query
            ->orderBy($query->getModel()->getQualifiedKeyName())
            ->paginate($perPage);
    }
}
