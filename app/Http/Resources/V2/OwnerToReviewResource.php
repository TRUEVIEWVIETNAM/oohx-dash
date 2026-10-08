<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Media owner mà chiến dịch này **còn được phép đánh giá** — DTO danh sách trắng.
 *
 * Hẹp hơn `OwnerSummaryResource` ở nội dung, nhưng rộng hơn một trường: `id`.
 * Lý do là đường ghi — `POST /my/campaigns/{campaign}/reviews` nhận `owner_id`,
 * nên client phải có khóa đó mới gửi được biểu mẫu. Cùng lý lẽ với
 * `CampaignResource`: id của dữ liệu riêng không phải thứ cần che, policy canh
 * từng lần chạm.
 *
 * Không mang `revenue_share_pct`, `bank_*`, `tax_code`, `billing_info` —
 * danh sách trắng, không danh sách loại trừ.
 */
class OwnerToReviewResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'slug'     => $this->slug,
            'name'     => $this->name,
            'logo_url' => $this->logo_url,
        ];
    }
}
