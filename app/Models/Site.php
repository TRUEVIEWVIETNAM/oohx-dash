<?php

namespace App\Models;

use App\Services\GioiHanNetwork;
use App\Traits\HasOwnerScope;
use App\Traits\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Site extends Model
{
    use HasFactory, HasUlids, HasOwnerScope, HasSlug, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CLOSED = 'closed';

    /**
     * Chữ tiếng Việt cho trạng thái địa điểm.
     *
     * `closed` là "Đã đóng" — địa điểm ngừng hẳn, khác `paused` là tạm. Phân
     * biệt đó có hệ quả: một địa điểm `closed` thì màn hình của nó không bao
     * giờ quay lại, còn `paused` thì có.
     */
    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Đang hoạt động',
        self::STATUS_PAUSED => 'Tạm dừng',
        self::STATUS_CLOSED => 'Đã đóng',
    ];

    protected $fillable = [
        'owner_id', 'network_id', 'external_id',
        'name', 'slug', 'description', 'logo', 'banner', 'lat', 'lon',
        'address', 'city', 'region', 'country', 'status',
        'province_id', 'commune_id',
    ];

    protected $casts = [
        'lat' => 'decimal:7',
        'lon' => 'decimal:7',
    ];

    protected static function booted(): void
    {
        // Clear frontpage location caches when site data changes
        $clearCache = function () {
            \Illuminate\Support\Facades\Cache::forget('fp:locations');
            \Illuminate\Support\Facades\Cache::forget('fp:filters');
        };
        static::created($clearCache);
        static::updated($clearCache);
        static::deleted($clearCache);

        // ── Hàng rào mạng lưới, đường GHI ────────────────────────────────────
        //
        // Global scope lo đường **đọc**; nó không nói gì về việc một thành viên
        // bị giới hạn đặt một địa điểm **vào** mạng lưới nào. Không có chốt
        // này thì một `operator` bị fence vào mạng lưới A vẫn tạo được địa
        // điểm trong mạng lưới B — và sau khi lưu, chính anh ta không thấy nó
        // nữa, nên hàng rào trông như vẫn kín trong khi kho đã bị ghi thêm.
        //
        // Đặt ở `saving` của model, không ở biểu mẫu: `sites.network_id` được
        // ghi từ bốn đường (biểu mẫu Filament, `/api/v1/sites`,
        // `SiteImportService`, `ScreenWriter`), và chặn ở model là chặn cả bốn
        // bằng một luật. Biểu mẫu vẫn thu hẹp ô chọn — đó là chuyện lịch sự
        // với người dùng, không phải chuyện an ninh.
        static::saving(function (Site $site) {
            $duocPhep = GioiHanNetwork::hienTai();

            if ($duocPhep === null) {
                return;
            }

            // Khi SỬA: chỉ xét lúc cột thực sự đổi. Một thành viên bị giới hạn
            // vẫn phải sửa được tên hay địa chỉ của địa điểm trong hàng rào
            // của mình.
            //
            // Khi TẠO: xét luôn, kể cả khi `network_id` không được đặt. Một
            // hàng mới không đặt cột đó thì `isDirty('network_id')` là false,
            // nên nếu chỉ dựa vào `isDirty` thì người bị giới hạn tạo được
            // địa điểm **không thuộc mạng lưới nào** — rồi chính anh ta không
            // thấy nó nữa (hàng rào loại `network_id` null), và kho của tổ
            // chức có thêm những địa điểm vô chủ không ai biết từ đâu ra.
            if ($site->exists && ! $site->isDirty('network_id')) {
                return;
            }

            if ($site->network_id !== null && in_array((int) $site->network_id, $duocPhep, true)) {
                return;
            }

            // `HttpException(403)` theo đúng tiền lệ duy nhất đang có cho loại
            // chốt này — cổng quyền giá ở `ScreenInventoryObserver`. Nó ra 403
            // ở cả Filament lẫn API, không cần lớp ngoại lệ mới.
            throw new HttpException(403, 'Bạn chỉ được đặt địa điểm vào những mạng lưới đã được giao.');
        });

        // Khi Site đổi owner → đồng bộ owner_id cho tất cả Screens con
        static::updating(function (Site $site) {
            if ($site->isDirty('owner_id') && $site->owner_id) {
                $site->screens()
                    ->withoutGlobalScope('owner_scope')
                    ->where('owner_id', '!=', $site->owner_id)
                    ->update(['owner_id' => $site->owner_id]);
            }
        });

        static::deleting(function (Site $site) {
            if (! $site->isForceDeleting()) {
                $site->screens()->each(fn (Screen $s) => $s->delete());
            }
        });
    }

    // ── Relationships ──────────────────────────────────────

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * Hàng rào theo mạng lưới — `sites.network_id` là nguồn sự thật duy nhất
     * về việc một địa điểm thuộc mạng lưới nào (xem F-12 ở `Screen::network()`).
     *
     * `network_id` **null** rơi ra ngoài, có chủ ý: một địa điểm chưa gán mạng
     * lưới không nằm trong tập được phép, nên người bị giới hạn không thấy nó.
     * Hàng rào phải đóng khi không biết, không mở.
     *
     * @param list<int> $networkIds
     */
    protected static function locTheoNetwork(Builder $query, array $networkIds, User $thanhVien): void
    {
        $query->whereIn($query->getModel()->getTable() . '.network_id', $networkIds);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(VietnamProvince::class, 'province_id');
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(VietnamCommune::class, 'commune_id');
    }

    public function screens(): HasMany
    {
        return $this->hasMany(Screen::class);
    }

    // ── Scopes ─────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
