<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một dòng lịch sử hoạt động của chiến dịch — DTO danh sách trắng.
 *
 * ══ `metadata` KHÔNG ra ngoài, và đây là chỗ quan trọng nhất của tệp này ══
 *
 * `campaign_activities.metadata` là cột tự do, do tầng trong ghi vào. Nó đã
 * chứa — chứ không "có thể chứa" — những thứ không được ra ngoài:
 *
 * - `remittance_details_viewed` ghi `ip` và `user_agent` của người đọc thông
 *   tin nhận tiền (xem `Api\V2\PaymentRecipientController`). Đó là nhật ký
 *   truy cập, tồn tại để đối soát khi có tranh chấp, không phải nội dung cho
 *   client đọc lại.
 * - `CancellationService` và `CreativeGate` ghi số tiền phân bổ và lý do nội
 *   bộ vào đây.
 *
 * Trả một cột tự do ra ngoài là hứa một hợp đồng mà không ai kiểm được: lần
 * sau có người ghi thêm một khóa vào `metadata`, nó ra ngoài ngay, và không
 * test nào đỏ. Cùng lý lẽ với `PaymentResource`.
 *
 * `user_id` cũng không ra ngoài — chỉ `user.name`. Client cần biết *ai làm*,
 * không cần khóa nội bộ của người đó.
 */
class CampaignActivityResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'action' => $this->action,

            // Chuỗi do service viết, đã là tiếng Việt cho người đọc. Client
            // hiển thị lại, không dịch — dịch ở client là có hai bộ chữ cho
            // cùng một việc.
            'description' => $this->description,

            // `null` nghĩa là hệ thống tự làm (job, lệnh artisan), không phải
            // "không biết ai". Client hiện "Hệ thống".
            'actor' => $this->user ? ['name' => $this->user->name] : null,

            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
