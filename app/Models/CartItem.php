<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id', 'screen_id', 'product_id',
        'start_date', 'end_date', 'spot_length',
        'quantity', 'selected_screen_ids', 'selected_region',
        'share_of_voice_pct', 'estimated_impressions', 'estimated_cost',
        'notes',
        // Pricing model fields
        'pricing_model', 'booked_cpms', 'screen_count',
        'duration_units', 'duration_unit', 'unit_price',
        // Giá đã chụp lúc thêm vào giỏ, để phát hiện giá đổi trước khi chốt đơn.
        'rate_captured_at', 'rate_snapshot',
    ];

    protected $casts = [
        'rate_captured_at' => 'datetime',
        'rate_snapshot' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'spot_length' => 'integer',
        'quantity' => 'integer',
        'selected_screen_ids' => 'array',
        'share_of_voice_pct' => 'integer',
        'estimated_impressions' => 'integer',
        'estimated_cost' => 'decimal:2',
        'booked_cpms' => 'integer',
        'screen_count' => 'integer',
        'duration_units' => 'integer',
        'unit_price' => 'decimal:2',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function screen(): BelongsTo
    {
        // Bỏ owner_scope, lý do như BookingLine::screen().
        return $this->belongsTo(Screen::class)->withoutGlobalScope('owner_scope');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
