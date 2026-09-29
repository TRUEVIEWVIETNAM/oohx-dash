<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một phiên bản bảng giá của màn hình, có hiệu lực từ một mốc thời gian.
 *
 * Bảng **chỉ ghi thêm**. Một phiên bản đã phát hành là sự thật lịch sử: nó trả
 * lời câu "ngày 3 tháng 5 màn hình này niêm yết bao nhiêu", câu bắt buộc phải
 * trả lời được khi có tranh chấp về một đơn cũ.
 */
class ScreenRateVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'screen_id', 'effective_from',
        'pricing_model', 'floor_cpm', 'io_rate', 'io_rate_unit',
        'duration_discounts', 'changed_by',
    ];

    protected $casts = [
        'effective_from'     => 'datetime',
        'floor_cpm'          => 'decimal:2',
        'io_rate'            => 'decimal:2',
        'duration_discounts' => 'array',
    ];

    public function screen(): BelongsTo
    {
        return $this->belongsTo(Screen::class);
    }

    /** Bảng giá có hiệu lực tại một thời điểm. */
    public static function effectiveAt(string $screenId, \DateTimeInterface $at): ?self
    {
        return static::where('screen_id', $screenId)
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')
            ->first();
    }
}
