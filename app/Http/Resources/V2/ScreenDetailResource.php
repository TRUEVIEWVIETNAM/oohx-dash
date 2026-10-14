<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;

/**
 * Màn hình ở trang chi tiết — DTO **danh sách trắng**, mở rộng từ
 * `ScreenSummaryResource`.
 *
 * Vì sao một resource riêng chứ không thêm trường vào resource danh sách:
 * `/api/v2/screens` trả 24 bản ghi mỗi trang. Thêm `photo_urls`, thông số
 * chi tiết, insights và chân dung khán giả vào đó là nhân payload danh sách
 * lên nhiều lần cho dữ liệu mà lưới thẻ không in ra. Hai DTO khác nhau vì hai
 * nơi cần khác nhau — không phải vì chúng tình cờ khác nhau.
 *
 * ══ Thừa kế có chủ ý ══
 *
 * Kế thừa `ScreenSummaryResource` để các trường chung (`pricing`, `size`,
 * `location`, `screen_type`) có **đúng một** định nghĩa. Nếu chép lại ở đây
 * thì sớm muộn một bên sửa `pricing` mà bên kia không — và đó đúng là lỗi R38:
 * hai đường truy vấn cho cùng một màn hình trả dữ liệu khác nhau.
 *
 * ══ Không trường nào ở đây lộ thứ bị cấm ══
 *
 * `device_token`, `internal_notes`, `revenue_share_pct`, `billing_info`,
 * `bank_*`, `tax_code`, `business_license_path`, giá sàn nội bộ của gói:
 * không trường nào. `floor_cpm` **không** phải giá sàn nội bộ — nó là con số
 * trang công khai đang hiển thị.
 *
 * `owner.phone` và `owner.email` thì CÓ, và đó là chủ ý: trang chi tiết Blade
 * in đúng hai thứ đó trong khối "Liên hệ {owner}", công khai, không cần đăng
 * nhập. Sàn không thu hộ (hồ sơ Bộ Công Thương), nên người mua phải liên hệ
 * được media owner; bỏ chúng đi là bỏ đường liên hệ duy nhất trên trang.
 */
class ScreenDetailResource extends ScreenSummaryResource
{
    public static $wrap = null;

    /** Nhãn vị trí trong venue — cùng bảng bản Blade dùng ở `screen-insights`. */
    private const PLACEMENT_LABELS = [
        'entrance'   => 'Lối vào',
        'checkout'   => 'Quầy thanh toán',
        'escalator'  => 'Cạnh thang cuốn',
        'food_court' => 'Khu food court',
        'facade'     => 'Mặt tiền tòa nhà',
        'lobby'      => 'Sảnh',
        'parking'    => 'Bãi đậu xe',
        'other'      => 'Khác',
    ];

    /** Khóa được phép của `audience_profile` — cột là JSON, nên phải lọc. */
    private const AUDIENCE_KEYS = [
        'male_pct', 'female_pct',
        'age_18_24_pct', 'age_25_34_pct', 'age_35_44_pct', 'age_45_plus_pct',
        'income_tier', 'lifestyle_tags', 'source_note',
    ];

    /** Khóa được phép của `time_performance`. */
    private const TIME_KEYS = [
        'peak_hour_start', 'peak_hour_end', 'best_day',
        'morning_pct', 'afternoon_pct', 'evening_pct', 'rationale',
    ];

    /** Khóa được phép của `nearby_context`. */
    private const NEARBY_KEYS = ['brands', 'landmarks', 'highlights'];

    /**
     * @param  array<string, string>  $venueLabels  nhãn loại điểm đặt theo id
     */
    public function __construct(
        $resource,
        private readonly array $venueLabels = [],
        private readonly array $similar = [],
        private readonly array $nearbyPois = [],
        private readonly ?array $availability = null,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $spec      = $this->spec;
        $inventory = $this->inventory;
        $site      = $this->site;

        // `array_merge`, KHÔNG toán tử `+`.
        //
        // `+` giữ lại giá trị của mảng BÊN TRÁI khi khóa trùng, nên `owner`
        // của resource cha (chỉ `slug` + `name`) sẽ thắng và `phone`/`email`
        // không bao giờ ra ngoài — một khối "Liên hệ media owner" rỗng mà
        // không có lỗi nào. Test `test_owner_co_duong_lien_he_vi_san_khong_thu_ho`
        // bắt đúng chuyện này.
        return array_merge(parent::toArray($request), [
            'description' => $this->description,

            // Thư viện ảnh. `photo_urls` là mảng ĐẦY ĐỦ; `photo_url` (từ
            // resource cha) vẫn là một ảnh đại diện, nên bên gọi cũ không vỡ.
            //
            // Chuỗi dự phòng giữ đúng thứ tự bản Blade: ảnh trong
            // `spec.photos[]` → `spec.photo_url` → `display_photo` (ảnh của
            // site / network / owner / loại điểm đặt). Không có ảnh nào thì
            // mảng rỗng — KHÔNG trả một URL placehold.co, vì một URL ảnh
            // ngoài trong payload API là bắt mọi client phải tin một dịch vụ
            // thứ ba mà không ai khai.
            'photo_urls' => $this->photoUrls(),

            'specs' => [
                'width_px'      => $spec?->width_px,
                'height_px'     => $spec?->height_px,
                'allow_image'   => (bool) ($spec?->allow_image ?? false),
                'allow_video'   => (bool) ($spec?->allow_video ?? false),
                'spot_length_s' => $inventory?->spot_length !== null ? (int) $inventory->spot_length : null,
                'orientation'   => $this->orientation,

                // Nhãn, không phải `vn_category_id`. Id của `venue_categories`
                // là khóa nội bộ: client không có cách nào tra nó, và trả id
                // ra ngoài là mời người ta đoán không gian id của hệ thống.
                'venue_label'   => $this->venueLabels[$inventory?->vn_category_id] ?? null,
                'venue_type'    => $inventory?->venue_type,
                'sov_max_pct'   => $inventory?->share_of_voice_max_pct !== null
                    ? (int) $inventory->share_of_voice_max_pct
                    : null,
                'io_kpi_spots_per_day' => $inventory?->io_kpi_spots_per_day !== null
                    ? (int) $inventory->io_kpi_spots_per_day
                    : null,
            ],

            'site' => $site ? [
                'name'    => $site->name,
                'address' => $site->address,
                'city'    => $site->city,
                // Toạ độ: trang chi tiết Blade vẽ bản đồ vị trí từ đúng hai
                // cột này, nên chúng đã công khai. Thiếu chúng thì khối bản đồ
                // không dựng lại được.
                'lat'     => $site->lat !== null ? (float) $site->lat : null,
                'lng'     => $site->lon !== null ? (float) $site->lon : null,
            ] : null,

            // Owner kèm đường liên hệ — xem docblock ở đầu lớp.
            'owner' => [
                'slug'     => $this->owner?->slug,
                'name'     => $this->owner?->name,
                'logo_url' => $this->owner?->logo_url ? asset('storage/' . $this->owner->logo_url) : null,
                'verified' => (bool) ($this->owner?->verified ?? false),
                'phone'    => $this->owner?->phone,
                'email'    => $this->owner?->email,
            ],

            'insights'     => $this->insights(),
            'nearby_pois'  => $this->nearbyPois,
            'similar'      => $this->similar,
            'availability' => $this->availability,
        ]);
    }

    /** @return string[] */
    private function photoUrls(): array
    {
        $photos = collect($this->spec?->photos ?? [])
            ->filter(fn ($p) => is_string($p) && $p !== '')
            ->map(fn ($p) => asset('storage/' . $p))
            ->values()
            ->all();

        if ($photos === [] && $this->spec?->photo_url) {
            $photos = [asset('storage/' . $this->spec->photo_url)];
        }

        if ($photos === [] && $this->display_photo) {
            $photos = [$this->display_photo];
        }

        return $photos;
    }

    /**
     * Khối Insights, hoặc `null` khi màn hình không có dữ liệu nào.
     *
     * `null` chứ không phải một đối tượng toàn trường rỗng: trang chi tiết ẩn
     * hẳn tab Insights khi không có gì (`$screen->has_insights` ở bản Blade),
     * và một đối tượng rỗng buộc mỗi client tự định nghĩa lại "rỗng là thế
     * nào".
     *
     * ══ KHÔNG gọi Data Engine ở đây ══
     *
     * Bản Blade đọc `$screen->traffic_estimate` — một accessor gọi HTTP sang
     * tunnel nội bộ. Endpoint này là đường công khai có giới hạn tần suất; để
     * nó chặn trên một lời gọi mạng ra ngoài là đổi một trang tĩnh thành một
     * trang phụ thuộc dịch vụ khác còn sống. Nên số liệu ở đây là cột Phase 1
     * do owner cung cấp, và `source` nói rõ thế.
     */
    private function insights(): ?array
    {
        if (! $this->has_insights) {
            return null;
        }

        $audience = $this->filterKeys($this->audience_profile, self::AUDIENCE_KEYS);
        $time     = $this->filterKeys($this->time_performance, self::TIME_KEYS);
        $nearby   = $this->filterKeys($this->nearby_context, self::NEARBY_KEYS);

        return [
            'placement_zone'  => $this->placement_zone,
            'placement_label' => self::PLACEMENT_LABELS[$this->placement_zone] ?? $this->placement_zone,
            'daily_footfall'  => $this->daily_footfall !== null ? (int) $this->daily_footfall : null,
            'monthly_reach'   => $this->monthly_reach !== null ? (int) $this->monthly_reach : null,

            // Ước tính, và nói ra là ước tính: accessor nhân `daily_footfall`
            // với 0,6 — một hệ số kinh nghiệm, không phải một phép đo.
            'daily_impressions_est' => $this->daily_impressions,

            'audience_profile' => $audience ?: null,
            'time_performance' => $time ?: null,
            'nearby_context'   => $nearby ?: null,
            'methodology_note' => $this->traffic_methodology_note,

            // Nguồn của mọi con số trên: cột do media owner khai. Trang in
            // badge theo giá trị này, nên nó phải đến từ API chứ không phải
            // một giả định ở tầng hiển thị.
            'source' => 'owner',
        ];
    }

    private function filterKeys($value, array $keys): array
    {
        if (! is_array($value)) {
            return [];
        }

        $loc = [];

        foreach ($keys as $key) {
            $v = $value[$key] ?? null;

            if ($v === null || $v === '' || $v === []) {
                continue;
            }

            $loc[$key] = $v;
        }

        return $loc;
    }
}
