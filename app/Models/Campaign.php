<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organization_id', 'created_by', 'code',
        'name', 'brand_name', 'category', 'objectives',
        'start_date', 'end_date',
        'total_budget', 'currency',
        'total_screens', 'total_impressions_estimated',
        'status', 'submitted_at', 'approved_at', 'rejected_at',
        'rejection_reason', 'activated_at', 'completed_at', 'notes',
    ];

    protected $casts = [
        'objectives' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'total_budget' => 'decimal:2',
        'total_screens' => 'integer',
        'total_impressions_estimated' => 'integer',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'activated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ── Status constants ──

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    // ── Relationships ──

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookingLines(): HasMany
    {
        return $this->hasMany(BookingLine::class);
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(Creative::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Nghĩa vụ hoàn tiền sinh ra từ các dòng đặt chỗ bị hủy. */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderByDesc('created_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CampaignActivity::class)->orderByDesc('created_at');
    }

    // ── Computed ──

    public function getTotalEstimatedCostAttribute(): float
    {
        return $this->bookingLines->sum('estimated_cost');
    }

    public function getTotalActualCostAttribute(): float
    {
        return $this->bookingLines->sum('actual_cost');
    }

    public function getTotalActualImpressionsAttribute(): int
    {
        return $this->bookingLines->sum('actual_impressions');
    }

    public function getDeliveryRateAttribute(): float
    {
        if ($this->total_impressions_estimated <= 0) {
            return 0;
        }

        return round($this->total_actual_impressions / $this->total_impressions_estimated * 100, 1);
    }

    public function getTotalPaidAttribute(): float
    {
        return $this->payments()->where('status', 'completed')->sum('amount');
    }

    // ── Scopes ──

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeForOrganization($query, string $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForOwner($query, string $ownerId)
    {
        return $query->whereHas('bookingLines', fn ($q) => $q->where('owner_id', $ownerId));
    }

    // ── Helpers ──

    public function isDraft(): bool { return $this->status === self::STATUS_DRAFT; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isActive(): bool { return $this->status === self::STATUS_ACTIVE; }
    public function isCompleted(): bool { return $this->status === self::STATUS_COMPLETED; }
}
