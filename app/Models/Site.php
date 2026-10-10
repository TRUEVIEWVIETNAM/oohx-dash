<?php

namespace App\Models;

use App\Traits\HasOwnerScope;
use App\Traits\HasSlug;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
