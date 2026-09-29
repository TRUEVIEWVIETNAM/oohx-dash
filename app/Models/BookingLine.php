<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BookingLine extends Model
{
    use HasUlids;

    protected $fillable = [
        'campaign_id', 'product_id', 'bundle_id', 'screen_id', 'owner_id',
        'start_date', 'end_date', 'spot_length',
        'share_of_voice_pct',
        'floor_cpm_at_booking', 'negotiated_cpm',
        'estimated_impressions', 'estimated_cost',
        'actual_impressions', 'actual_cost',
        'status', 'approved_by', 'approved_at',
        'rejected_reason', 'notes',
        // Pricing model fields
        'pricing_model', 'io_rate_at_booking', 'io_rate_unit',
        'kpi_spots_per_day', 'booked_cpms', 'screen_count', 'actual_spots',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'spot_length' => 'integer',
        'share_of_voice_pct' => 'integer',
        'floor_cpm_at_booking' => 'decimal:2',
        'negotiated_cpm' => 'decimal:2',
        'io_rate_at_booking' => 'decimal:2',
        'kpi_spots_per_day' => 'integer',
        'booked_cpms' => 'integer',
        'screen_count' => 'integer',
        'actual_spots' => 'integer',
        'estimated_impressions' => 'integer',
        'estimated_cost' => 'decimal:2',
        'actual_impressions' => 'integer',
        'actual_cost' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    // ── Relationships ──

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Gói mà dòng này được mở ra từ, kèm bản chụp thành phần gói lúc mua. */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(BookingLineBundle::class, 'bundle_id');
    }

    public function screen(): BelongsTo
    {
        // Bỏ owner_scope: dòng booking đã xác định ngữ cảnh rồi. Người mua không có
        // tenant nên nếu để scope, quan hệ này trả null và hỏng cả trang chiến dịch.
        return $this->belongsTo(Screen::class)->withoutGlobalScope('owner_scope');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creatives(): BelongsToMany
    {
        return $this->belongsToMany(Creative::class, 'booking_line_creatives')
            ->using(BookingLineCreative::class)
            ->withPivot('weight', 'start_date', 'end_date');
    }

    // ── Computed ──

    public function getEffectiveCpmAttribute(): float
    {
        return $this->negotiated_cpm ?? $this->floor_cpm_at_booking;
    }

    public function getDurationDaysAttribute(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    // ── Scopes ──

    public function scopeForOwner($query, string $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
