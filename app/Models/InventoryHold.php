<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một suất đang được giữ trên một màn hình trong một khoảng ngày.
 *
 * Xem `App\Services\InventoryHoldService` để biết cách giành giữ chỗ an toàn khi
 * hai người mua tranh nhau cùng một suất.
 */
class InventoryHold extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CONSUMED = 'consumed';

    protected $fillable = [
        'screen_id', 'cart_item_id', 'booking_line_id',
        'start_date', 'end_date', 'sov_pct',
        'expires_at', 'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'sov_pct'    => 'integer',
        'expires_at' => 'datetime',
    ];

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    public function cartItem(): BelongsTo
    {
        return $this->belongsTo(CartItem::class);
    }

    public function bookingLine(): BelongsTo
    {
        return $this->belongsTo(BookingLine::class);
    }

    /**
     * Giữ chỗ còn hiệu lực: đang active và chưa hết hạn.
     *
     * Hết hạn được tính ngay trong truy vấn, không dựa vào job dọn đã chạy chưa.
     * Nếu chỉ lọc theo status thì kho bị giam thêm đúng bằng khoảng trễ của job
     * — và vào lúc job hỏng thì giam vĩnh viễn.
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function scopeOverlapping(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate);
    }
}
