<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một đánh giá media owner **đã duyệt**, nhìn từ phía người lạ.
 *
 * ══ Vì sao không dùng lại `OwnerReviewResource` ══
 *
 * DTO kia viết cho **tác giả** của nhận xét, trên đường
 * `GET campaigns/{campaign}`: nó trả `id`, `status`, `status_label` và
 * `owner.id`. Ba thứ đó không thuộc một trang công khai —
 *
 *  - `status`: người lạ không cần biết sàn đang xử lý nhận xét nào, và
 *    `publishedFor()` vốn chỉ trả bản đã đăng nên trường đó luôn là một giá
 *    trị duy nhất;
 *  - `id` và `owner.id`: khóa nội bộ, không giúp người đọc trang làm gì, và
 *    trả chúng là mời người ngoài đoán không gian id của hệ thống;
 *  - `created_at`: thời điểm **viết**, có thể cách thời điểm **đăng** nhiều
 *    ngày vì nhận xét đi qua kiểm duyệt. Trang in ngày đăng, nên chỉ cần ngày
 *    đăng.
 *
 * Dùng lại DTO kia rồi lọc ở tầng hiển thị thì mặt tiếp xúc của API vẫn rộng
 * như cũ — và lần sau có người gọi nó từ chỗ khác mà không lọc.
 *
 * ══ Tên tổ chức, không tên người ══
 *
 * Bản Blade in `$rv->organization->name`. Giữ đúng thế: người viết là một
 * nhân viên của một agency, và tên cá nhân họ không phải thông tin trang này
 * cần công bố.
 */
class OwnerPublicReviewResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'organization' => $this->organization?->name,
            'rating'       => (int) $this->rating,
            'comment'      => $this->comment,
            'published_at' => $this->published_at?->toDateString(),
        ];
    }
}
