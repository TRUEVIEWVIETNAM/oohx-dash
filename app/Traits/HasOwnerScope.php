<?php

namespace App\Traits;

use App\Models\ApiClient;
use App\Models\User;
use App\Services\GioiHanNetwork;
use Illuminate\Database\Eloquent\Builder;

trait HasOwnerScope
{
    /**
     * Thu hẹp truy vấn về đúng những mạng lưới thành viên được phép chạm.
     *
     * **Trừu tượng, có chủ ý.** Mỗi model nối tới `networks` bằng một đường
     * khác nhau — `networks.id`, `sites.network_id`, qua địa điểm, hoặc không
     * nối tới đâu cả — nên không có một mặc định nào đúng cho tất cả. Một
     * mặc định "không làm gì" sẽ biến mỗi model thêm vào sau này thành một cửa
     * sau **im lặng**: nó vẫn được scope theo tenant, và lặng lẽ không được
     * scope theo mạng lưới.
     *
     * Khai ở đây là `abstract`, nên PHP từ chối nạp class ngay lúc biên dịch
     * nếu ai dùng trait mà quên khai. Một lỗi ồn ào vào đúng lúc viết mã, thay
     * cho một hàng rào rò vài tháng sau.
     *
     * `GioiHanMangLuoiTest` canh thêm chiều còn lại: một thân hàm **rỗng** vẫn
     * làm PHP vui lòng, nên ca test đòi mỗi model thật sự thu hẹp truy vấn.
     *
     * @param list<int> $networkIds luôn khác rỗng
     */
    abstract protected static function locTheoNetwork(Builder $query, array $networkIds, User $thanhVien): void;

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

                // Hàng rào trong lòng tenant: một thành viên bị giới hạn vào
                // vài mạng lưới chỉ thấy kho của những mạng lưới đó.
                //
                // Nằm ở đây, sau mọi nhánh thoát bên trên, nên khách, token
                // đối tác và super_admin không bao giờ đi qua — và nó chỉ áp
                // khi đã biết chắc tenant đang chọn.
                $networkIds = app(GioiHanNetwork::class)->cho($user, (string) $user->current_owner_id);

                if ($networkIds !== null) {
                    static::locTheoNetwork($query, $networkIds, $user);
                }

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
