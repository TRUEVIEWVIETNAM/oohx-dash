<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bản chụp một gói hàng tại thời điểm mua, cùng các dòng đặt chỗ sinh ra từ nó.
 *
 * Đọc bảng này để trả lời "gói này gồm những màn hình nào lúc khách mua" — câu
 * hỏi mà `products` không trả lời được, vì owner sửa thành phần gói bất cứ lúc nào.
 */
class BookingLineBundle extends Model
{
    use HasUlids;

    protected $fillable = [
        'campaign_id', 'product_id', 'buy_mode', 'price_total', 'snapshot',
    ];

    protected $casts = [
        'price_total' => 'decimal:2',
        'snapshot'    => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function bookingLines(): HasMany
    {
        return $this->hasMany(BookingLine::class, 'bundle_id');
    }
}
