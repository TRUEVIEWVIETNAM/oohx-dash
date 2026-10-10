<?php

namespace App\Models;

use App\Traits\HasOwnerScope;
use App\Traits\HasSlug;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasUlids, SoftDeletes, HasOwnerScope, HasSlug;

    // Type constants
    public const TYPE_SINGLE  = 'single';
    public const TYPE_PACKAGE = 'package';
    public const TYPE_CUSTOM  = 'custom';

    /**
     * Chữ tiếng Việt cho loại sản phẩm.
     *
     * Biểu mẫu đã có bản tiếng Việt viết tay (`'single' => 'Đơn lẻ'`), nhưng
     * cột `type` trong bảng thì khai `->badge()` mà không có `formatStateUsing`
     * — nên nó in `single`, `package`, `custom` nguyên văn. Hai chỗ nói hai
     * thứ khác nhau về cùng một giá trị, và chỗ sai là chỗ người đọc nhiều hơn.
     *
     * Đây là hai trong số các món nợ `TheEnumPhaiCoChuTest` đã ghi lại; danh
     * sách nợ đó được gạch tương ứng trong cùng lượt.
     */
    public const TYPE_LABELS = [
        self::TYPE_SINGLE  => 'Đơn lẻ',
        self::TYPE_PACKAGE => 'Gói',
        self::TYPE_CUSTOM  => 'Tuỳ chỉnh',
    ];

    public const STATUS_DRAFT    = 'draft';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_PAUSED   = 'paused';
    public const STATUS_SOLD_OUT = 'sold_out';

    /**
     * Chữ tiếng Việt cho trạng thái sản phẩm.
     *
     * Hai ô chọn trong `ProductResource` trộn tiếng Việt với tiếng Anh ở ngay
     * cạnh nhau — `['active' => 'Active', 'draft' => 'Nháp']` — nên cùng một
     * danh sách vừa dịch vừa không. `active` là "Đang bán", không phải "Đang
     * hoạt động": với một sản phẩm thì trạng thái đó nói nó còn bán được.
     */
    public const STATUS_LABELS = [
        self::STATUS_DRAFT    => 'Nháp',
        self::STATUS_ACTIVE   => 'Đang bán',
        self::STATUS_PAUSED   => 'Tạm dừng',
        self::STATUS_SOLD_OUT => 'Hết chỗ',
    ];

    // Listing mode constants
    public const LISTING_PACKAGE_ONLY    = 'package_only';
    public const LISTING_INDIVIDUAL_ONLY = 'individual_only';
    public const LISTING_BOTH            = 'both';

    public const LISTING_MODES = [
        self::LISTING_PACKAGE_ONLY    => 'Chỉ bán nguyên gói',
        self::LISTING_INDIVIDUAL_ONLY => 'Chỉ bán lẻ từng màn hình',
        self::LISTING_BOTH            => 'Bán gói hoặc bán lẻ',
    ];

    // Category constants
    public const CATEGORY_BILLBOARD      = 'billboard';
    public const CATEGORY_LED            = 'led';
    public const CATEGORY_LCD            = 'lcd';
    public const CATEGORY_POSM           = 'posm';
    public const CATEGORY_STANDEE        = 'standee';
    public const CATEGORY_ESCALATOR_WRAP = 'escalator_wrap';
    public const CATEGORY_CEILING_HANG   = 'ceiling_hang';
    public const CATEGORY_LIGHTBOX       = 'lightbox';

    public const CATEGORIES = [
        self::CATEGORY_BILLBOARD      => 'Billboard',
        self::CATEGORY_LED            => 'LED Digital',
        self::CATEGORY_LCD            => 'LCD',
        self::CATEGORY_POSM           => 'POSM',
        self::CATEGORY_STANDEE        => 'Standee',
        self::CATEGORY_ESCALATOR_WRAP => 'Dán thang cuốn',
        self::CATEGORY_CEILING_HANG   => 'Thả trần',
        self::CATEGORY_LIGHTBOX       => 'Lightbox',
    ];

    protected $fillable = [
        'owner_id', 'network_id', 'site_id',
        'name', 'slug', 'type', 'category', 'listing_mode',
        'min_quantity', 'max_quantity', 'total_units',
        'floor_price', 'individual_price', 'package_discount_pct',
        'currency', 'price_unit',
        'description', 'short_description', 'cover_photo', 'photos', 'specs',
        'city', 'region', 'package_options',
        'status', 'featured', 'sort_order',
        'meta_title', 'meta_description',
    ];

    protected $casts = [
        'photos'               => 'array',
        'specs'                => 'array',
        'package_options'      => 'array',
        'floor_price'          => 'decimal:2',
        'individual_price'     => 'decimal:2',
        'package_discount_pct' => 'integer',
        'min_quantity'         => 'integer',
        'max_quantity'         => 'integer',
        'total_units'          => 'integer',
        'featured'             => 'boolean',
        'sort_order'           => 'integer',
    ];

    // ── Relationships ──

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function screens(): BelongsToMany
    {
        return $this->belongsToMany(Screen::class, 'product_screens')
            ->using(ProductScreen::class)
            ->withPivot('is_primary', 'sort_order')
            ->orderByPivot('sort_order');
    }

    public function primaryScreen(): BelongsToMany
    {
        return $this->screens()->wherePivot('is_primary', true);
    }

    public function bookingLines(): HasMany
    {
        return $this->hasMany(BookingLine::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    // ── Accessors ──

    public function getCoverUrlAttribute(): ?string
    {
        if ($this->cover_photo) {
            return asset('storage/' . $this->cover_photo);
        }
        // Fallback: first photo
        $first = collect($this->photos)->first();
        return $first ? asset('storage/' . $first) : null;
    }

    public function getPhotoUrlsAttribute(): array
    {
        return collect($this->photos ?? [])
            ->map(fn ($p) => asset('storage/' . $p))
            ->toArray();
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getPriceDisplayAttribute(): string
    {
        // Đơn vị tiền lấy từ cột `currency`, không dán "₫" cứng. Bảng
        // `products` có cột đó và dữ liệu thật có hàng không phải VND, nên in
        // "₫" cạnh một số USD là nói sai với người mua (Codex R39).
        $currency = $this->currency ?: 'VND';
        $symbol   = $currency === 'VND' ? ' ₫' : ' ' . $currency;

        $price = $currency === 'VND'
            ? number_format($this->floor_price, 0, ',', '.')
            : number_format($this->floor_price, 2, ',', '.');

        $unit = match ($this->price_unit) {
            'month'    => '/tháng',
            'week'     => '/tuần',
            'day'      => '/ngày',
            'campaign' => '/campaign',
            default    => '',
        };

        if ($this->type === self::TYPE_PACKAGE && $this->min_quantity > 1) {
            return $price . $symbol . $unit . '/' . $this->min_quantity . ' vị trí';
        }

        return $price . $symbol . $unit;
    }

    public function getScreenCountAttribute(): int
    {
        return $this->screens()->count();
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * The products the public marketplace is allowed to show.
     *
     * status='active' is the media owner's own listing switch; it does not mean
     * the sàn has approved the owner behind it. Both must hold.
     */
    public function scopePubliclyVisible($query)
    {
        return Owner::gateActive(
            $query->withoutGlobalScope('owner_scope')->where('products.status', 'active'),
            'products.owner_id'
        );
    }

    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByCity($query, string $city)
    {
        return $query->where('city', 'like', '%' . $city . '%');
    }

    // ── Helpers ──

    public function isSingle(): bool  { return $this->type === self::TYPE_SINGLE; }
    public function isPackage(): bool  { return $this->type === self::TYPE_PACKAGE; }
    public function isCustom(): bool   { return $this->type === self::TYPE_CUSTOM; }

    public function allowsIndividual(): bool
    {
        return in_array($this->listing_mode, [self::LISTING_INDIVIDUAL_ONLY, self::LISTING_BOTH]);
    }

    public function allowsPackage(): bool
    {
        return in_array($this->listing_mode, [self::LISTING_PACKAGE_ONLY, self::LISTING_BOTH]);
    }

    /** Giá lẻ per screen — dùng individual_price nếu có, fallback floor_price */
    public function getIndividualPriceDisplayAttribute(): string
    {
        $price = $this->individual_price ?: $this->floor_price;
        $unit = match ($this->price_unit) {
            'month'    => '/tháng',
            'week'     => '/tuần',
            'day'      => '/ngày',
            'campaign' => '/campaign',
            default    => '',
        };
        return number_format($price, 0, ',', '.') . ' ₫' . $unit;
    }

    public function getListingModeLabelAttribute(): string
    {
        return self::LISTING_MODES[$this->listing_mode] ?? $this->listing_mode;
    }
}
