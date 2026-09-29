<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lượt phát đã diễn ra trên một màn hình.
 *
 * `HasUlids` ở đây không phải chuyện gọn gàng: khóa chính là `(id, played_at)`
 * và cột `id` kiểu `char(26)` **không có giá trị mặc định**. Thiếu trait thì
 * mọi lần chèn hỏng với SQLSTATE 1364 — đó chính là tình trạng của bảng này
 * cho tới 29/09/2026, nghĩa là sàn chưa từng ghi được một bằng chứng nào.
 */
class ImpressionLog extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'impression_logs';

    protected $fillable = [
        'id', 'screen_id', 'owner_id', 'campaign_id', 'booking_line_id', 'creative_id',
        'event_id', 'played_at', 'duration_sec', 'multiplier_applied', 'imp_count',
        'deal_type', 'cpm_charged', 'revenue_gross', 'revenue_owner',
        'proof_url', 'source', 'created_at',
    ];

    protected $casts = [
        'played_at'          => 'datetime',
        'created_at'         => 'datetime',
        'imp_count'          => 'decimal:2',
        'multiplier_applied' => 'decimal:2',
        'cpm_charged'        => 'decimal:4',
        'revenue_gross'      => 'decimal:4',
        'revenue_owner'      => 'decimal:4',
    ];

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function bookingLine(): BelongsTo
    {
        return $this->belongsTo(BookingLine::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
