<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một yêu cầu báo giá của người mua (lát 5).
 *
 * @property string      $code
 * @property string      $status  pending|approved|rejected|closed
 * @property string|null $approved_at
 */
class QuoteRequest extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CLOSED   = 'closed';

    /** Một bảng chữ, dùng cho cả `/admin` lẫn `/publisher`. */
    public const STATUS_LABELS = [
        self::STATUS_PENDING  => 'Chờ duyệt',
        self::STATUS_APPROVED => 'Đã duyệt — owner thấy liên hệ',
        self::STATUS_REJECTED => 'Từ chối',
        self::STATUS_CLOSED   => 'Đã xong',
    ];

    protected $fillable = [
        'code', 'organization_id',
        'contact_name', 'contact_email', 'contact_phone', 'contact_company',
        'campaign_start', 'campaign_end', 'budget_vnd', 'notes',
        'status', 'approved_at', 'approved_by_user_id', 'internal_notes',
        'submitted_ip', 'user_agent',
    ];

    /**
     * Dữ liệu cá nhân của người gửi, ẩn khỏi mọi lần serialize.
     *
     * Cùng lý do như `PublicReflection`: một cú `->toJson()` ở đâu đó không
     * được đẩy email và điện thoại ra ngoài. DTO nào cần chúng thì phải lấy
     * tường minh — và chỉ `/admin` cùng `/publisher` **sau khi duyệt** mới
     * được phép.
     */
    protected $hidden = [
        'contact_name', 'contact_email', 'contact_phone',
        'internal_notes', 'submitted_ip', 'user_agent',
    ];

    protected $casts = [
        'campaign_start' => 'date',
        'campaign_end'   => 'date',
        'approved_at'    => 'datetime',
        'budget_vnd'     => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QuoteRequestItem::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * Media owner đã được phép thấy liên hệ người mua chưa.
     *
     * Một hàm, không phải một phép so sánh rải rác: ranh giới này xuất hiện ở
     * DTO, ở Filament và ở phép kiểm, và ba bản của cùng một điều kiện sẽ
     * trôi khỏi nhau — lần trôi đó là một lần rò dữ liệu cá nhân.
     */
    public function lienHeDaMo(): bool
    {
        return $this->approved_at !== null;
    }
}
