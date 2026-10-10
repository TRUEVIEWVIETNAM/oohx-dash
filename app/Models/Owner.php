<?php

namespace App\Models;

use App\Traits\HasSlug;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Owner extends Model
{
    use HasFactory, HasUlids, HasSlug, SoftDeletes;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Chữ tiếng Việt cho trạng thái media owner.
     *
     * ══ Ba chỗ tự quyết chữ, và không chỗ nào dịch ══
     *
     *  1. Cột bảng (`BadgeColumn::make('status')`) không khai chữ → in `pending`,
     *     `active`, `suspended` nguyên văn.
     *  2. Khối thông tin (`TextEntry::make('status')->badge()`) cũng vậy.
     *  3. Ô chọn của form và của bộ lọc CÓ chữ, nhưng là tiếng Anh và là **hai
     *     bản chép** rời nhau: `['pending'=>'Pending', …]` viết hai lần.
     *
     * Đúng hình dạng lỗi đã sửa ở `Organization::STATUS_LABELS` — và hai bảng đó
     * là hai phía của cùng một sàn, nên chữ phải giống nhau: "Đang hoạt động",
     * "Tạm ngưng".
     *
     * "Tạm ngưng" chứ không "Tạm dừng", có chủ ý: `Network::STATUS_LABELS` và
     * `Site::STATUS_LABELS` đã dùng "Tạm dừng" cho `paused`. `suspended` là một
     * quyết định của sàn với media owner, `paused` là media owner tự tắt một
     * mạng lưới — hai việc khác nhau không nên đọc giống nhau.
     *
     * `pending` là "Chờ duyệt", không phải "Đang chờ": nó chờ sàn rà xong hồ sơ
     * pháp lý, và chính ô chọn của form nói thế trong `helperText`.
     */
    public const STATUS_LABELS = [
        self::STATUS_PENDING   => 'Chờ duyệt',
        self::STATUS_ACTIVE    => 'Đang hoạt động',
        self::STATUS_SUSPENDED => 'Tạm ngưng',
    ];

    public const TYPE_RETAILER    = 'retailer';
    public const TYPE_MEDIA_OWNER = 'media_owner';
    public const TYPE_SELF        = 'self';

    /**
     * Chữ tiếng Việt cho loại đơn vị sở hữu điểm phát.
     *
     * Cùng ba chỗ, cùng hai bản chép tiếng Anh như `status` ở trên
     * (`['retailer'=>'Retailer','media_owner'=>'Media Owner','self'=>'Self']`),
     * và bộ lọc của bảng còn thiếu hẳn `self`.
     *
     * `self` là "Tự khai thác": đơn vị tự dựng điểm phát và tự bán, không qua
     * một chủ sở hữu khác. "Self" dịch thẳng thành "Bản thân" thì vô nghĩa ở
     * đây, nên chữ nói việc chứ không dịch từ.
     */
    public const TYPE_LABELS = [
        self::TYPE_RETAILER    => 'Nhà bán lẻ',
        self::TYPE_MEDIA_OWNER => 'Chủ điểm quảng cáo',
        self::TYPE_SELF        => 'Tự khai thác',
    ];

    protected $fillable = [
        'name', 'slug', 'type', 'onboard_method',
        'revenue_share_pct', 'status', 'billing_info', 'notes',
        'tagline', 'about', 'logo_url', 'cover_url',
        'website', 'email', 'phone', 'address', 'city', 'district',
        'province_id', 'commune_id',
        'founded', 'featured', 'verified', 'headquarters_lat', 'headquarters_lng',
        // Hồ sơ pháp lý (yêu cầu đăng ký sàn TMĐT)
        'legal_name', 'tax_code', 'tax_code_issued_on', 'tax_code_issued_by',
        'legal_representative', 'business_license_path',
        'verified_at', 'verified_by_user_id',
        // Tài khoản nhận tiền — người mua chuyển thẳng cho media owner
        'bank_name', 'bank_account_number', 'bank_account_name', 'bank_branch',
    ];

    protected $casts = [
        'billing_info'       => 'array',
        'revenue_share_pct'  => 'decimal:2',
        'featured'           => 'boolean',
        'verified'           => 'boolean',
        'founded'            => 'integer',
        'headquarters_lat'   => 'decimal:7',
        'headquarters_lng'   => 'decimal:7',
        'tax_code_issued_on' => 'date',
        'verified_at'        => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────

    public function province(): BelongsTo
    {
        return $this->belongsTo(VietnamProvince::class, 'province_id');
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(VietnamCommune::class, 'commune_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function networks(): HasMany
    {
        return $this->hasMany(Network::class);
    }

    public function screens(): HasMany
    {
        return $this->hasMany(Screen::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(OwnerUser::class);
    }

    public function impressionLogs(): HasMany
    {
        return $this->hasMany(ImpressionLog::class);
    }

    // ── Scopes ─────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Restrict a query to rows belonging to an owner the sàn has approved.
     *
     * An owner sits at status='pending' until an admin has checked their legal
     * papers, and nothing of theirs may be shown publicly before that. This is
     * the gate; every public query must pass through it.
     *
     * It lives here, taking the query rather than being a scope, because the
     * public reads are spread over ~20 call sites and half of them are raw
     * query-builder joins that no Eloquent scope can reach. Spelling the rule
     * out at each site is exactly how it came to be absent from all of them.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $ownerIdColumn  qualified column holding the owner id
     */
    public static function gateActive($query, string $ownerIdColumn = 'screens.owner_id')
    {
        return $query->whereExists(function ($q) use ($ownerIdColumn) {
            $q->select(DB::raw(1))
                ->from('owners')
                ->whereColumn('owners.id', $ownerIdColumn)
                ->where('owners.status', 'active')
                ->whereNull('owners.deleted_at');
        });
    }

    // ── Helpers ────────────────────────────────────────────
    // Note: Use withCount(['screens as screen_count' => ...]) at query time
    // instead of computed accessors to avoid N+1 queries.

    /**
     * Đã khai đủ tài khoản để người mua chuyển tiền tới hay chưa.
     *
     * Sàn không thu hộ, nên thiếu ba trường này là người mua không có chỗ nào để
     * trả — trang thanh toán phải nói thẳng ra thay vì hiện một khối trống.
     */
    public function hasBankDetails(): bool
    {
        return filled($this->bank_name)
            && filled($this->bank_account_number)
            && filled($this->bank_account_name);
    }

    /**
     * Đã có đủ hồ sơ pháp lý tối thiểu theo yêu cầu đăng ký sàn TMĐT.
     *
     * Không tự động khoá việc duyệt: admin vẫn là người quyết định. Đây là thứ để
     * hiển thị cho admin biết còn thiếu gì trước khi bấm duyệt.
     */
    public function hasCompleteLegalProfile(): bool
    {
        return filled($this->legal_name)
            && filled($this->tax_code)
            && filled($this->tax_code_issued_on)
            && filled($this->tax_code_issued_by)
            && filled($this->legal_representative)
            && filled($this->email)
            && filled($this->phone)
            && filled($this->business_license_path);
    }
}
