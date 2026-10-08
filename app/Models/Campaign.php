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

    /**
     * Chữ tiếng Việt cho từng trạng thái — **một định nghĩa**.
     *
     * Bảng chữ này từng được chép lại ở mỗi nơi cần nó: hai khối `@php` trong
     * `buyer/dashboard/campaign-detail.blade.php` và `campaigns.blade.php`, và
     * ba bộ `options()` trong Filament. Chép là để chúng lệch nhau — và khi
     * lệch thì cùng một chiến dịch hiện hai chữ khác nhau ở hai trang, không
     * ai biết chữ nào đúng.
     *
     * Hai trang khu người mua đọc chữ này **qua API** (`status_label` của
     * `CampaignResource`): máy chủ sở hữu chữ, client sở hữu màu. Màu là việc
     * trình bày và nó đổi theo chủ đề; chữ là việc nghiệp vụ.
     *
     * Filament dùng nó qua `statusLabels()` — xem hàm đó về việc vì sao mỗi
     * bảng khai phạm vi trạng thái của riêng nó thay vì lấy cả tám.
     */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT     => 'Nháp',
        self::STATUS_PENDING   => 'Chờ duyệt',
        self::STATUS_APPROVED  => 'Đã duyệt',
        self::STATUS_REJECTED  => 'Từ chối',
        self::STATUS_ACTIVE    => 'Đang chạy',
        self::STATUS_PAUSED    => 'Tạm dừng',
        self::STATUS_COMPLETED => 'Hoàn thành',
        self::STATUS_CANCELLED => 'Đã hủy',
    ];

    /**
     * Nhãn cho một **tập con** trạng thái, giữ đúng thứ tự của `STATUS_LABELS`.
     *
     * ══ Vì sao cần tập con, không phải cứ lấy cả tám ══
     *
     * Mỗi bảng chỉ *với tới* được một phần trạng thái. Hộp thư đặt chỗ của
     * media owner lọc sẵn sáu trạng thái trong `getEloquentQuery()` — một
     * chiến dịch `draft` hay `cancelled` không bao giờ hiện ở đó. Đưa `draft`
     * vào bộ lọc của bảng ấy là thêm một lựa chọn **luôn** trả về rỗng, và một
     * bộ lọc nói dối thì tệ hơn một bộ lọc thiếu.
     *
     * Nên mỗi bảng truyền vào **chính hằng mà truy vấn của nó dùng**. Hai danh
     * sách đó trước đây được giữ riêng và đã lệch nhau thật: truy vấn gồm
     * `paused`, bộ lọc thì không.
     *
     * Gọi không tham số thì trả cả tám — đúng cho bảng quản trị sàn, nơi không
     * có phép lọc trạng thái nào ở tầng truy vấn.
     *
     * @param  array<int, string>|null  $chi
     * @return array<string, string>
     */
    public static function statusLabels(?array $chi = null): array
    {
        if ($chi === null) {
            return self::STATUS_LABELS;
        }

        // Lọc theo `STATUS_LABELS` chứ không `array_map` trên `$chi`: cách này
        // giữ thứ tự chuẩn (nháp → chờ duyệt → … → đã hủy) bất kể người gọi
        // xếp mảng của họ thế nào, và một mã lạ trong `$chi` bị bỏ chứ không
        // thành một mục không có chữ.
        return array_filter(
            self::STATUS_LABELS,
            fn (string $ma) => in_array($ma, $chi, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

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
