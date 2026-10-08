<?php

namespace App\Http\Resources\V2;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một campaign của người mua — DTO danh sách trắng.
 *
 * ══ `code` ra ngoài, `id` cũng ra ngoài ══
 *
 * Khác các DTO công khai (dùng slug), ở đây `id` ra ngoài vì client phải gọi
 * được `POST /campaigns/{id}/submit` và `/payments` lên đúng bản ghi. Campaign
 * là dữ liệu riêng của tổ chức người mua, và `CampaignPolicy` canh từng lần
 * chạm — nên id không phải thứ cần che.
 *
 * ══ Không ra ngoài ══
 *
 * - `organization_id`, `created_by`: id nội bộ, client đã biết mình là ai.
 * - `rejection_reason` **thì có ra** — đó là lý do đơn của chính họ bị từ chối.
 * - `notes` ra ngoài vì là ghi chú do chính người mua viết.
 */
class CampaignResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'code' => $this->code,
            'name' => $this->name,

            'brand_name' => $this->brand_name,
            'category'   => $this->category,
            'objectives' => $this->objectives ?? [],

            'period' => [
                'start_date' => $this->start_date?->toDateString(),
                'end_date'   => $this->end_date?->toDateString(),
            ],

            'status' => $this->status,

            // Chữ do MÁY CHỦ sở hữu, màu do client sở hữu.
            //
            // Bảng chữ ở `Campaign::STATUS_LABELS` — một định nghĩa. Trước đây
            // nó được chép vào từng khối `@php` của mỗi trang Blade, nên cùng
            // một chiến dịch có thể hiện hai chữ khác nhau ở hai trang và
            // không ai biết chữ nào đúng. Màu thì vẫn ở client: nó là việc
            // trình bày và đổi theo chủ đề.
            'status_label' => Campaign::STATUS_LABELS[$this->status] ?? $this->status,

            'timeline' => [
                'submitted_at' => $this->submitted_at?->toJSON(),
                'approved_at'  => $this->approved_at?->toJSON(),
                'rejected_at'  => $this->rejected_at?->toJSON(),
                'activated_at' => $this->activated_at?->toJSON(),
                'completed_at' => $this->completed_at?->toJSON(),
            ],

            'rejection_reason' => $this->rejection_reason,

            'totals' => [
                'currency' => 'VND',

                // Ngân sách người mua TỰ ghi để theo dõi. Không phải số tiền
                // phải trả — số đó ở `GET /campaigns/{id}/payments`, do
                // `PaymentService` tính, và là chỗ duy nhất cộng VAT.
                'budget'      => $this->total_budget !== null ? (int) round((float) $this->total_budget) : null,
                'screens'     => $this->total_screens !== null ? (int) $this->total_screens : null,
                'impressions' => $this->total_impressions_estimated !== null ? (int) $this->total_impressions_estimated : null,
            ],

            'notes' => $this->notes,
        ];
    }
}
