<?php

namespace App\Models;

use App\Traits\HasOwnerScope;
use App\Traits\HasSlug;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Screen — core marketplace entity (digital display unit).
 *
 * Marketplace fields (Phase 1):
 * @property string      $name
 * @property string      $external_id
 * @property string      $uuid
 * @property bool        $active
 * @property string      $status         online|offline|maintenance
 * @property string      $description
 *
 * Inventory Intelligence (Phase 1):
 * @property string|null $placement_zone           entrance|checkout|escalator|food_court|facade|lobby|parking|other
 * @property string|null $orientation              landscape|portrait|square (auto-derived if null)
 * @property int|null    $daily_footfall
 * @property int|null    $monthly_reach
 * @property array|null  $audience_profile         {male_pct, female_pct, age_18_24_pct, ...}
 * @property array|null  $time_performance         {peak_hour_start, peak_hour_end, best_day, morning_pct, ...}
 * @property array|null  $nearby_context           {brands:[], landmarks:[], highlights:""}
 * @property string|null $traffic_methodology_note
 *
 * @internal AdOps / device fields (Phase 2 — hidden from UI by default):
 * @property string|null $unit_id
 * @property string|null $internal_notes
 * @property string|null $site_external_id
 * @property string|null $network_code
 * @property string|null $location_district
 * @property string|null $location_district_code
 * @property string|null $player_type
 * @property string|null $player_version
 * @property string|null $device_token
 * @property \Carbon\Carbon|null $last_heartbeat_at
 */
class Screen extends Model
{
    use HasFactory, HasUlids, HasOwnerScope, HasSlug, SoftDeletes;

    /**
     * device_token là bí mật của thiết bị phát — dùng để xác thực player.
     * Nó từng đi theo mọi response serialize Screen, kể cả quan hệ lồng nhau
     * (Codex review T1, F3). Ẩn ở tầng model để bịt mọi đường ra cùng lúc.
     */
    protected $hidden = ['device_token'];

    protected $fillable = [
        // Marketplace
        'site_id', 'owner_id', 'external_id', 'uuid',
        'name', 'slug', 'description', 'active', 'status',

        // Inventory Intelligence (Phase 1)
        'placement_zone', 'orientation',
        'daily_footfall', 'monthly_reach',
        'audience_profile', 'time_performance', 'nearby_context',
        'traffic_methodology_note',

        // AdOps / device (Phase 2)
        'unit_id', 'internal_notes',
        'site_external_id', 'network_code', 'location_district', 'location_district_code',
        'player_type', 'player_version',
        'last_heartbeat_at', 'device_token',
    ];

    protected $casts = [
        'active'            => 'boolean',
        'last_heartbeat_at' => 'datetime',
        'audience_profile'  => 'array',
        'time_performance'  => 'array',
        'nearby_context'    => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Screen $screen) {
            if (empty($screen->uuid)) {
                $screen->uuid = (string) Str::uuid();
            }
        });

        // Clear frontpage caches when screen data changes
        $clearCache = function () {
            Cache::forget('fp:locations');
            Cache::forget('fp:filters');
            Cache::forget('fp:hero_stats');
            Cache::forget('fp:featured_screens');
        };
        static::created($clearCache);
        static::updated($clearCache);
        static::deleted($clearCache);

        static::deleting(function (Screen $screen) {
            if (! $screen->isForceDeleting()) {
                $screen->spec?->delete();
                $screen->inventory?->delete();
                $screen->multipliers()->delete();
                $screen->externalIds()->delete();
            }
        });
    }

    // ── Relationships ──────────────────────────────────────

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_screens')
            ->using(ProductScreen::class)
            ->withPivot('is_primary', 'sort_order');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Mạng lưới của màn hình — **đi qua địa điểm**, một đường duy nhất.
     *
     * Trước đây quan hệ này trỏ thẳng `screens.network_code → networks.code`,
     * trong khi trang công khai đếm theo `sites.network_id` và Filament đếm
     * theo `screen_inventory.network_id`. Ba đường cho ba câu trả lời khác
     * nhau cho cùng một câu hỏi, và không ai biết con số nào đúng (F-12).
     *
     * Nay chỉ còn một: mạng lưới là chuỗi địa điểm, màn hình thừa hưởng mạng
     * lưới của nơi nó đứng. Xem migration `unify_screen_network_relation`.
     */
    public function network(): HasOneThrough
    {
        return $this->hasOneThrough(
            Network::class,
            Site::class,
            'id',          // sites.id
            'id',          // networks.id
            'site_id',     // screens.site_id
            'network_id',  // sites.network_id
        );
    }

    /**
     * @deprecated Dùng `network` — hai tên cho cùng một thứ là cách F-12 bắt đầu.
     */
    public function getNetworkViaAttribute(): ?Network
    {
        return $this->site?->network;
    }

    /**
     * Display photo with fallback chain:
     * 1. Screen spec photo (first of photos[] or photo_url)
     * 2. Site banner
     * 3. Network banner
     * 4. Owner cover
     * 5. Venue category thumb
     * Returns null if all fallbacks miss — view should use placeholder.
     */
    public function getDisplayPhotoAttribute(): ?string
    {
        // 1. Screen spec photo (already formatted as full URL by spec accessor)
        $specPhoto = $this->spec?->photo;
        if ($specPhoto) return $specPhoto;

        // 2. Site banner
        if ($this->site?->banner) {
            return asset('storage/' . $this->site->banner);
        }

        // 3. Network banner (via site)
        if ($this->site?->network?->banner) {
            return asset('storage/' . $this->site->network->banner);
        }

        // 4. Owner cover
        if ($this->owner?->cover_url) {
            return asset('storage/' . $this->owner->cover_url);
        }

        // 5. Venue category thumb
        if ($this->inventory?->vnCategory?->thumb) {
            return asset('storage/' . $this->inventory->vnCategory->thumb);
        }

        return null;
    }

    public function spec(): HasOne
    {
        return $this->hasOne(ScreenSpec::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(ScreenInventory::class);
    }

    public function multipliers(): HasMany
    {
        return $this->hasMany(ScreenImpressionMultiplier::class);
    }

    public function externalIds(): HasMany
    {
        return $this->hasMany(ScreenExternalId::class);
    }

    public function impressionLogs(): HasMany
    {
        return $this->hasMany(ImpressionLog::class);
    }

    // ── Scopes ─────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * The screens the public marketplace is allowed to show.
     *
     * `active` is the media owner's own on/off switch — it says nothing about
     * whether the sàn has approved that owner to trade. Both must hold, so no
     * public read should use `active` on its own.
     */
    public function scopePubliclyVisible($query)
    {
        return Owner::gateActive(
            $query->withoutGlobalScope('owner_scope')->where('screens.active', true)
        );
    }

    public function scopeOnline($query)
    {
        return $query->where('status', 'online');
    }

    public function scopeProgrammatic($query)
    {
        return $query->whereHas('inventory', fn($q) =>
            $q->where('programmatic_enabled', true)
        );
    }

    public function scopeByVenueType($query, string $venueType)
    {
        return $query->whereHas('inventory', fn($q) =>
            $q->where('venue_type', $venueType)
        );
    }

    // ── Helpers ────────────────────────────────────────────

    /** Lấy multiplier cho giờ hiện tại */
    public function getCurrentMultiplier(): float
    {
        $now = now()->setTimezone($this->inventory?->timezone ?? 'Asia/Ho_Chi_Minh');
        $dow = $now->dayOfWeek === 0 ? 6 : $now->dayOfWeek - 1; // Laravel: 0=Sun, Hivestack: 0=Mon

        return (float) ($this->multipliers()
            ->where('day_of_week', $dow)
            ->where('hour_of_day', $now->hour)
            ->value('multiplier') ?? 1.0);
    }

    /** Kiểm tra screen có đang trong giờ hoạt động không */
    public function isOperating(): bool
    {
        $hours = $this->inventory?->operating_hours;
        if (empty($hours)) return true;

        $tz  = $this->inventory?->timezone ?? 'Asia/Ho_Chi_Minh';
        $now = now()->setTimezone($tz);
        $day = strtolower($now->format('D')); // mon, tue...

        $dayHours = $hours[$day] ?? null;
        if ($dayHours === 'closed' || empty($dayHours)) return false;

        $open  = $now->copy()->setTimeFromTimeString($dayHours['open']);
        $close = $now->copy()->setTimeFromTimeString($dayHours['close']);

        return $now->between($open, $close);
    }

    public function getIsOnlineAttribute(): bool
    {
        if (! $this->last_heartbeat_at) return false;
        return $this->last_heartbeat_at->diffInMinutes(now()) < 5;
    }

    // ── Phase 1 Intelligence accessors ─────────────────────

    /**
     * Orientation — returns DB value if set, otherwise derives from spec dimensions.
     * Tolerance of 10% width/height difference treated as 'square'.
     */
    public function getOrientationAttribute($value): ?string
    {
        if ($value) return $value;

        $w = (float) ($this->spec?->width_cm ?? 0);
        $h = (float) ($this->spec?->height_cm ?? 0);
        if ($w <= 0 || $h <= 0) return null;

        $delta = abs($w - $h) / max($w, $h);
        if ($delta < 0.10) return 'square';
        return $w > $h ? 'landscape' : 'portrait';
    }

    /**
     * Estimated daily impressions:
     *   - daily_footfall × 0.6 (heuristic: 60% of footfall actually sees the screen)
     *   - or fallback to inventory.weekly_impressions / 7
     */
    public function getDailyImpressionsAttribute(): ?int
    {
        if ($this->daily_footfall) {
            return (int) round($this->daily_footfall * 0.6);
        }
        $weekly = (int) ($this->inventory?->weekly_impressions ?? 0);
        return $weekly > 0 ? (int) round($weekly / 7) : null;
    }

    /**
     * Has any Phase 1 intelligence data — used by detail page to hide tab when empty.
     */
    public function getHasInsightsAttribute(): bool
    {
        return (bool) (
            $this->daily_footfall
            || $this->monthly_reach
            || $this->placement_zone
            || ! empty($this->audience_profile)
            || ! empty($this->time_performance)
            || ! empty($this->nearby_context)
        );
    }

    /**
     * Lookup Data Engine estimate cho screen này, cached 5 phút per uuid.
     *
     * Sử dụng ở cả Insights (stat row) và listing (batch fetch preferred).
     * Silent fail nếu tunnel down — return null để display graceful fallback.
     */
    public function getTrafficEstimateAttribute(): ?object
    {
        if (! $this->uuid) return null;

        return \Cache::remember(
            "oohx:estimate:{$this->uuid}",
            now()->addMinutes(5),
            function () {
                try {
                    return app(\App\Services\OohxDataEngine::class)
                        ->getEstimateByExternalId($this->uuid);
                } catch (\Throwable) {
                    return null;
                }
            },
        );
    }
}
