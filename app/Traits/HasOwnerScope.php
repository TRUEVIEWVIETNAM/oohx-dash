<?php

namespace App\Traits;

use App\Models\ApiClient;
use Illuminate\Database\Eloquent\Builder;

trait HasOwnerScope
{
    protected static function bootHasOwnerScope(): void
    {
        static::addGlobalScope('owner_scope', function (Builder $query) {
            $user = auth()->user();

            // Khách chưa đăng nhập: truy vấn công khai luôn tự bỏ scope này và đi qua
            // publiclyVisible(), nên không chặn ở đây.
            if (! $user) return;

            // Token đối tác (OOHX/TapON): đọc toàn bộ inventory theo hợp đồng API v1.
            // Quyền GHI của token này bị chặn ở tầng route (ability:manage) và policy.
            if ($user instanceof ApiClient) return;

            if ($user->hasRole('super_admin')) return;

            if ($user->current_owner_id) {
                $query->where($query->getModel()->getTable() . '.owner_id', $user->current_owner_id);
                return;
            }

            // Fail-closed: user thuộc một media owner nào đó nhưng không có tenant đang
            // chọn (context cũ, membership bị thu hồi) thì KHÔNG thấy gì.
            // Trước đây nhánh này trả về tất cả — token của người mua đọc được cả giá sàn
            // của mọi owner. Xem audit 23/09, F01/F03.
            if ($user->relationLoaded('owners') ? $user->owners->isNotEmpty() : $user->owners()->exists()) {
                $query->whereRaw('1 = 0');
                return;
            }

            // User không thuộc media owner nào (người mua): scope theo tenant không áp
            // dụng được. Quyền của họ do policy và PurchaseEligibilityService quyết định,
            // còn dữ liệu công khai đi qua publiclyVisible().
        });
    }

    public function scopeForOwner(Builder $query, string $ownerId): Builder
    {
        return $query->withoutGlobalScope('owner_scope')
                     ->where('owner_id', $ownerId);
    }
}
