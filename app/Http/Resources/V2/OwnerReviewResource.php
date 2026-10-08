<?php

namespace App\Http\Resources\V2;

use App\Models\OwnerReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một đánh giá media owner **do chính tổ chức này viết** — DTO danh sách trắng.
 *
 * Chỉ dùng cho `GET campaigns/{campaign}` của khu người mua, nơi người xem là
 * tác giả. Đánh giá công khai của một owner đi qua đường khác
 * (`OwnerReviewService::publishedFor()`), và đường đó chỉ trả bản đã duyệt.
 *
 * ══ `moderation_note` KHÔNG ra ngoài ══
 *
 * Đó là ghi chú của người kiểm duyệt trên sàn, viết cho nội bộ: lý do thật vì
 * sao một nhận xét không được đăng. Trả nó ra là biến một ghi chú nội bộ thành
 * câu trả lời chính thức gửi cho khách — và người viết nó không biết mình đang
 * viết cho khách.
 *
 * `user_id` và `organization_id` cũng không ra ngoài: người xem đã biết mình
 * là ai, và khóa nội bộ không giúp họ làm gì thêm.
 *
 * ══ `status` thì CÓ ra ngoài ══
 *
 * Người mua đã bỏ công viết thì phải biết nhận xét của mình đang chờ duyệt, đã
 * đăng, hay bị từ chối. Không nói gì là để họ tưởng hệ thống mất bài viết.
 */
class OwnerReviewResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'owner' => $this->owner ? [
                'id'   => $this->owner->id,
                'name' => $this->owner->name,
            ] : null,

            'rating'  => (int) $this->rating,
            'comment' => $this->comment,

            'status' => $this->status,

            // Nhãn lấy từ `OwnerReview::STATUS_LABELS` — cùng bảng chữ mà khu
            // quản trị dùng. Client tự dịch `status` là có hai bộ chữ cho cùng
            // một trạng thái, và chúng lệch nhau ngay lần đổi đầu tiên.
            'status_label' => OwnerReview::STATUS_LABELS[$this->status] ?? $this->status,

            'published_at' => $this->published_at?->toJSON(),
            'created_at'   => $this->created_at?->toJSON(),
        ];
    }
}
