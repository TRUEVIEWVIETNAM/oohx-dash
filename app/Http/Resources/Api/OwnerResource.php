<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DTO danh sách trắng cho Owner.
 *
 * Trước đây API trả nguyên model, lộ tỷ lệ chia doanh thu, thông tin thanh toán,
 * tài khoản ngân hàng, mã số thuế và đường dẫn giấy phép kinh doanh cho bất kỳ ai
 * có token (audit 23/09, F01). Chỉ super_admin mới thấy nhóm trường nhạy cảm.
 */
class OwnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id'             => $this->id,
            'name'           => $this->name,
            'slug'           => $this->slug,
            'type'           => $this->type,
            'status'         => $this->status,
            'onboard_method' => $this->onboard_method,
            'verified'       => (bool) $this->verified,
            'logo_url'       => $this->logo_url,
            'cover_url'      => $this->cover_url,
            'website'        => $this->website,
            'city'           => $this->city,
            'created_at'     => $this->created_at?->toIso8601String(),
            'sites_count'    => $this->whenCounted('sites'),
            'screens_count'  => $this->whenCounted('screens'),
        ];

        if ($request->user()?->hasRole('super_admin')) {
            $data['internal'] = [
                'revenue_share_pct'    => $this->revenue_share_pct,
                'billing_info'         => $this->billing_info,
                'legal_name'           => $this->legal_name,
                'tax_code'             => $this->tax_code,
                'legal_representative' => $this->legal_representative,
                'email'                => $this->email,
                'phone'                => $this->phone,
                'notes'                => $this->notes,
            ];
        }

        return $data;
    }
}
