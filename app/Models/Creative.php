<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\URL;

class Creative extends Model
{
    use HasUlids;

    protected $fillable = [
        'campaign_id', 'organization_id',
        'name', 'type', 'file_path', 'file_size',
        'width_px', 'height_px', 'duration_sec',
        'vast_tag_url', 'status',
        'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'width_px' => 'integer',
        'height_px' => 'integer',
        'duration_sec' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function bookingLines(): BelongsToMany
    {
        return $this->belongsToMany(BookingLine::class, 'booking_line_creatives')
            ->using(BookingLineCreative::class)
            ->withPivot('weight', 'start_date', 'end_date');
    }

    /**
     * URL ký hạn tới tệp nội dung, hoặc `null` nếu chưa có tệp.
     *
     * Bản cũ trả `asset('storage/' . $file_path)` — một URL **công khai** trên
     * disk `public`, tải được không cần đăng nhập. Nội dung quảng cáo nằm
     * trong nhóm tệp nhạy cảm mà CLAUDE.md mục 5 yêu cầu để disk riêng và
     * truy cập qua URL ký hạn.
     *
     * Không chỗ nào trong view đang gọi accessor này lúc đổi, nên việc đổi
     * không làm vỡ gì — nhưng nó là cái bẫy đã nạp sẵn: chỉ cần một người viết
     * `{{ $creative->file_url }}` vào một trang công khai là nội dung chưa duyệt
     * của người mua ra ngoài, và không ai thấy gì bất thường khi đọc dòng đó.
     *
     * URL sinh ra chỉ là một tấm vé: route `creatives.file` vẫn kiểm
     * `CreativePolicy`, nên nó không cấp quyền cho ai chưa có.
     */
    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return URL::temporarySignedRoute(
            'creatives.file',
            now()->addMinutes((int) config('creatives.url_ttl_minutes', 60)),
            ['creative' => $this->getKey()],
        );
    }

    public function getDimensionsAttribute(): string
    {
        if ($this->width_px && $this->height_px) {
            return $this->width_px . 'x' . $this->height_px;
        }
        return '—';
    }
}
