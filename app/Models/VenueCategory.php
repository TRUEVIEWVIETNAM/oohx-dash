<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class VenueCategory extends Model
{
    protected $table = 'venue_categories';

    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'slug', 'name_vi', 'description', 'icon', 'thumb', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function venueTypes(): HasMany
    {
        return $this->hasMany(VenueType::class, 'vn_category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Những danh mục điểm đặt **ngoài trời**, theo slug.
     *
     * ══ Vì sao kiến thức này sống ở đây ══
     *
     * Đây là model của chính bảng taxonomy, nên nó là chỗ duy nhất biết từ
     * vựng thật. `VenueCategorySeeder` dựng 12 danh mục và đúng **một** cái là
     * ngoài trời: `roadside`, nhãn tiếng Việt "Ngoài trời", mô tả *"Billboard,
     * LED ngoài trời ven đường"*.
     *
     * Trước 08/11/2026 kiến thức này nằm ở `SuyRaLoaiManHinh::VENUE_NGOAI_TROI`
     * dưới dạng chuỗi `'outdoor'` — một giá trị **không có trong taxonomy**, và
     * cũng không có trong dữ liệu. Hệ quả ở docblock của
     * `SuyRaLoaiManHinh::resolveScreenType()`.
     *
     * `VenueTypeNgoaiTroiTest` chốt mọi slug ở đây đều có thật trong seeder:
     * một hằng số gọi tên một mã không tồn tại là đúng loại lỗi đã xảy ra, và
     * nó không làm gì đỏ cả.
     */
    public const SLUG_NGOAI_TROI = ['roadside'];

    /**
     * Id của những danh mục ngoài trời.
     *
     * ══ Vì sao trả id, không trả slug ══
     *
     * Hai phía phải so **cùng một thứ**: `SuyRaLoaiManHinh::resolveScreenType()`
     * đọc `screen_inventory.vn_category_id` của bản ghi đang bọc, và
     * `FrontpageService::applyScreenTypeFilter()` dựng cùng điều kiện bằng SQL.
     * Trả id thì cả hai so trên đúng một danh sách, nên chúng **không thể**
     * trôi khỏi nhau — và "nhãn trên thẻ nói LED, bộ lọc LED trả 0" là đúng
     * chỗ lệch đã có trên production ngày 19/10/2026.
     *
     * Phía đọc slug sẽ phải nạp quan hệ `inventory.vnCategory` ở **chín** chỗ
     * dựng DTO; thiếu một chỗ là một truy vấn cho mỗi hàng. `vn_category_id`
     * là cột vô hướng đã nằm trong mọi `with(['inventory:…'])` sẵn có.
     *
     * Cache vì hàm này được gọi **cho từng bản ghi** trong phép suy loại hình;
     * không có nó thì mỗi thẻ trên trang là một câu truy vấn. Cùng kho và cùng
     * nhịp với `FrontpageService::getVenueTypesWithCounts()`, vốn cache đúng
     * bảng này 1800 giây.
     *
     * Trả về `[]` khi chưa có danh mục nào — và đó là hành vi đúng: phép suy
     * trả `lcd`, bộ lọc `led` trả rỗng, hai bên vẫn khớp nhau.
     *
     * @return list<int>
     */
    public static function idNgoaiTroi(): array
    {
        return Cache::remember('venue:id_ngoai_troi', 1800, function () {
            return self::query()
                ->whereIn('slug', self::SLUG_NGOAI_TROI)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        });
    }

    /**
     * Get all string_value patterns for this category's venue types.
     */
    public function getStringValues(): array
    {
        return $this->venueTypes()->pluck('string_value')->filter()->all();
    }
}
