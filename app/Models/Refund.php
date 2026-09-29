<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nghĩa vụ hoàn tiền của một media owner với một người mua.
 *
 * Sàn không giữ tiền nên đây là bản ghi nghĩa vụ, không phải giao dịch:
 * `settled_at` đánh dấu hai bên đã xử lý xong ở ngoài hệ thống.
 */
class Refund extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_WAIVED  = 'waived';

    protected $fillable = [
        'campaign_id', 'booking_line_id', 'owner_id', 'organization_id',
        'paid_amount', 'amount', 'refund_pct', 'days_before_start',
        'policy_snapshot', 'reason', 'status', 'requested_by', 'settled_at',
    ];

    protected $casts = [
        'paid_amount'       => 'decimal:2',
        'amount'            => 'decimal:2',
        'refund_pct'        => 'integer',
        'days_before_start' => 'integer',
        'policy_snapshot'   => 'array',
        'settled_at'        => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function bookingLine(): BelongsTo
    {
        return $this->belongsTo(BookingLine::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }
}
