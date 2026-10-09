<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    public const TYPE_AGENCY = 'agency';

    public const TYPE_BRAND = 'brand';

    public const TYPE_CLIENT = 'client';

    /**
     * Chữ tiếng Việt cho loại hình tổ chức — **một định nghĩa**.
     *
     * ══ Lỗi bảng này sửa ══
     *
     * Cột `organizations.type` là `enum('agency','client','brand')` và trước
     * đây **không có bảng chữ nào**. Bốn chỗ hiện nó, bốn kiểu sai khác nhau:
     *
     *  1. Trang đầu khu người mua in `ucfirst($org->type)` — "Agency",
     *     "Brand", "Client" — tiếng Anh, ngay dưới tên người dùng, trên trang
     *     họ thấy đầu tiên sau khi đăng nhập.
     *  2. Cột `type` ở bảng quản trị sàn có `->badge()` và màu, nhưng **không
     *     có nhãn chữ**, nên nó hiện thẳng mã `agency`.
     *  3. Khối thông tin (infolist) cũng vậy.
     *  4. Ô chọn của form và của bộ lọc có chữ, nhưng là tiếng Anh, và là
     *     **hai bản chép** rời nhau.
     *
     * Đúng hình dạng của lỗi trạng thái chiến dịch ở `Campaign::STATUS_LABELS`:
     * mỗi chỗ tự quyết chữ, nên cùng một giá trị hiện ba kiểu.
     *
     * ══ Vì sao "Đại lý" ══
     *
     * Trang công khai đã gọi `/agency` là "Đại lý" trong thanh điều hướng. Một
     * từ khác ở khu người mua là hai từ cho cùng một thứ.
     *
     * Màu thì **không** nằm ở đây: Filament dùng từ vựng riêng
     * (`info`/`success`/`gray`) và khu người mua dùng class CSS. Máy chủ sở hữu
     * chữ, client sở hữu màu.
     */
    public const TYPE_LABELS = [
        self::TYPE_AGENCY => 'Đại lý',
        self::TYPE_BRAND  => 'Thương hiệu',
        self::TYPE_CLIENT => 'Khách hàng',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Chữ tiếng Việt cho trạng thái tổ chức — **một định nghĩa**.
     *
     * ══ Lỗi bảng này sửa ══
     *
     * `organizations.status` là `enum('active','suspended')` và không có bảng
     * chữ. Bốn chỗ ở `OrganizationResource`:
     *
     *  - cột bảng và khối thông tin: `->badge()` có màu, **không có nhãn chữ**,
     *    nên Filament in thẳng `active` / `suspended`;
     *  - ô chọn của form và của bộ lọc: có chữ, nhưng tiếng Anh, và là **hai
     *    bản chép** rời nhau.
     *
     * Đây là **lần thứ năm** cùng một lỗi ở một cột khác: `campaigns.status`
     * (#41), `creatives.status` (#43), `organizations.type` (#47),
     * `creatives.type` (#48).
     *
     * ══ Trạng thái này có hiệu lực thật, không chỉ là một nhãn ══
     *
     * `CampaignService::tuCachXemChienDich()` đòi tổ chức còn `active`; một tổ
     * chức `suspended` thì người của nó thấy danh sách chiến dịch **rỗng**.
     * Nên chữ ở đây là chữ mà người quản trị đọc trước khi quyết định tạm ngưng
     * ai — không phải một nhãn trang trí.
     *
     * "Tạm ngưng" chứ không "Tạm dừng": `Campaign::STATUS_PAUSED` đã dùng "Tạm
     * dừng", và hai trạng thái khác nhau ở hai bảng khác nhau không nên đọc
     * giống nhau.
     */
    public const STATUS_LABELS = [
        self::STATUS_ACTIVE    => 'Đang hoạt động',
        self::STATUS_SUSPENDED => 'Tạm ngưng',
    ];

    protected $fillable = [
        'name', 'slug', 'type', 'tax_id',
        'billing_address', 'billing_email', 'billing_phone',
        'logo_url', 'website', 'status',
        'credit_limit', 'payment_terms_days',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'payment_terms_days' => 'integer',
    ];

    // ── Relationships ──

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function organizationUsers(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(Creative::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
