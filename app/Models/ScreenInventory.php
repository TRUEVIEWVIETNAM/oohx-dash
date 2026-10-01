<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Screen inventory — marketplace + adops metadata.
 *
 * Marketplace fields (Phase 1):
 * @property string      $venue_type
 * @property float       $floor_cpm
 * @property string      $floor_cpm_currency
 * @property int|null    $weekly_impressions
 * @property array|null  $operating_hours
 * @property string      $timezone
 * @property int         $spot_length
 * @property bool        $programmatic_enabled
 *
 * @internal AdOps fields (Phase 2 — hidden from UI by default):
 * @property int         $max_spot_length
 * @property int         $min_spot_length
 * @property int|null    $loop_length
 * @property float|null  $floor_cpm_usd
 * @property bool        $pmp_only
 * @property bool        $ad_server_enabled
 * @property bool        $deals_enabled
 * @property int         $share_of_voice_max_pct
 * @property int|null    $screen_count_override
 * @property int         $frequency_cap
 * @property int         $category_frequency_cap
 * @property bool        $strict_frequency_capping
 */
class ScreenInventory extends Model
{
    use HasFactory;

    protected $table = 'screen_inventory';

    protected $fillable = [
        // Marketplace
        'screen_id', 'network_id', 'network_name',
        'venue_type', 'vn_category_id',
        'spot_length', 'floor_cpm', 'floor_cpm_currency',
        'weekly_impressions', 'operating_hours', 'timezone',
        'programmatic_enabled',
        // Pricing model
        'pricing_model', 'io_rate', 'io_rate_unit', 'io_kpi_spots_per_day',
        'duration_discounts',

        // AdOps (Phase 2)
        'max_spot_length', 'min_spot_length', 'loop_length',
        'floor_cpm_usd',
        'pmp_only', 'ad_server_enabled', 'deals_enabled',
        'share_of_voice_max_pct',
        'screen_count_override',
        'frequency_cap', 'category_frequency_cap', 'strict_frequency_capping',
    ];

    protected $casts = [
        'operating_hours'          => 'array',
        'programmatic_enabled'     => 'boolean',
        'pmp_only'                 => 'boolean',
        'ad_server_enabled'        => 'boolean',
        'deals_enabled'            => 'boolean',
        'strict_frequency_capping' => 'boolean',
        'floor_cpm'                => 'decimal:2',
        'floor_cpm_usd'            => 'decimal:4',
        'io_rate'                  => 'decimal:2',
        'io_kpi_spots_per_day'     => 'integer',
        'duration_discounts'       => 'array',
    ];

    // ── Relationships ───────────────────────────────────────

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function vnCategory(): BelongsTo
    {
        return $this->belongsTo(VenueCategory::class, 'vn_category_id');
    }

    // ── Helpers ─────────────────────────────────────────────

    /**
     * Tỷ giá lấy từ `config('pricing.usd_vnd_rate')`.
     *
     * Trước đây nó là tham số mặc định `float $rate = 25000` trong chữ ký hàm
     * này — tức một chính sách giá không ai duyệt, nằm ẩn ở nơi không ai đọc.
     * Người gọi vẫn truyền được tỷ giá riêng, nhưng mặc định nay là một con số
     * nhìn thấy được.
     */
    public function computeFloorCpmUsd(?float $rate = null): float
    {
        $rate = $rate ?: (float) config('pricing.usd_vnd_rate', 25000);

        if (! $this->floor_cpm) {
            return 0;
        }
        if ($this->floor_cpm_currency === 'USD') {
            return (float) $this->floor_cpm;
        }

        return round($this->floor_cpm / $rate, 4);
    }

    public function getDailyImpressionsAttribute(): ?int
    {
        return $this->weekly_impressions
            ? (int) round($this->weekly_impressions / 7)
            : null;
    }

    public function allowsCpm(): bool
    {
        return in_array($this->pricing_model, ['cpm', 'both']);
    }

    public function allowsIo(): bool
    {
        return in_array($this->pricing_model ?? 'io', ['io', 'both']);
    }

    public function isBoth(): bool
    {
        return $this->pricing_model === 'both';
    }

    /**
     * Get display price for frontpage (uses I/O rate as primary if available).
     * both: show I/O rate as headline, CPM as secondary
     * cpm-only: show floor_cpm
     * io-only: show io_rate
     */
    public function getDisplayPriceAttribute(): float
    {
        if ($this->allowsIo() && $this->io_rate > 0) {
            return (float) $this->io_rate;
        }
        return (float) ($this->floor_cpm ?? 0);
    }

    public function getDisplayPriceUnitAttribute(): string
    {
        if ($this->allowsIo() && $this->io_rate > 0) {
            return $this->io_rate_unit === 'week' ? 'màn hình/tuần' : 'màn hình/tháng';
        }
        return 'CPM';
    }

    /**
     * Đơn vị tiền của `display_price`.
     *
     * `io_rate` **không có** cột currency trong CSDL nên là VND theo định
     * nghĩa; `floor_cpm` thì đi theo `floor_cpm_currency`, và cột đó có hàng
     * USD trong dữ liệu thật.
     *
     * Thiếu accessor này thì mọi chỗ in giá đều dán "₫" cứng, và một màn hình
     * niêm yết 2,50 USD hiện ra là "2 ₫" (Codex R39).
     */
    public function getDisplayCurrencyAttribute(): string
    {
        if ($this->allowsIo() && $this->io_rate > 0) {
            return 'VND';
        }

        return $this->floor_cpm_currency ?: 'VND';
    }

    /**
     * Giá hiển thị kèm đơn vị tiền, đã định dạng.
     *
     * Một chỗ duy nhất quyết định cách in, để ba view không in ba kiểu. Số
     * thập phân chỉ hiện khi đơn vị không phải VND: 2,50 USD làm tròn thành 3
     * là làm sai dữ liệu, còn 50.000,00 ₫ thì chỉ là rườm rà.
     */
    public function getDisplayPriceFormattedAttribute(): string
    {
        $amount   = (float) $this->display_price;
        $currency = $this->display_currency;

        if ($currency === 'VND') {
            return number_format($amount, 0, ',', '.') . ' ₫';
        }

        return number_format($amount, 2, ',', '.') . ' ' . $currency;
    }

    /**
     * Giá CPM quy đổi về VND, **chỉ để so sánh và sắp xếp**.
     *
     * Không dùng để hiển thị hay tính tiền: quy đổi là một phép xấp xỉ, giá
     * niêm yết là một cam kết. Xem `config('pricing.usd_vnd_rate')`.
     */
    public function getFloorCpmVndEquivalentAttribute(): float
    {
        $amount = (float) ($this->floor_cpm ?? 0);

        if ($amount <= 0) {
            return 0;
        }

        return ($this->floor_cpm_currency === 'USD')
            ? $amount * (float) config('pricing.usd_vnd_rate', 25000)
            : $amount;
    }

    /** @internal AdOps — số màn hình thực tế (override hoặc mặc định 1) */
    public function getEffectiveScreenCountAttribute(): int
    {
        return ($this->screen_count_override && $this->screen_count_override > 0)
            ? (int) $this->screen_count_override
            : 1;
    }
}
