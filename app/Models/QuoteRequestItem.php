<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một vị trí trong yêu cầu báo giá (lát 5).
 *
 * `owner_id`, `screen_slug`, `screen_name`, `city` là **ảnh chụp lúc gửi**.
 * Màn hình đổi tên hay đổi chủ sau đó thì yêu cầu đã gửi vẫn đọc được đúng
 * thứ người mua đã chọn — và `owner_id` ở đây là owner phải trả lời, kể cả
 * khi màn hình sang tay người khác hôm sau.
 */
class QuoteRequestItem extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'quote_request_id', 'owner_id', 'screen_id',
        'screen_slug', 'screen_name', 'city',
    ];

    public function quoteRequest(): BelongsTo
    {
        return $this->belongsTo(QuoteRequest::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }
}
