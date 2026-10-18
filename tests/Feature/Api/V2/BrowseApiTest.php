<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Models\VenueCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * `/api/v2/browse` — hai lưới thẻ "duyệt" của trang chủ.
 *
 * ══ Vì sao có endpoint này ══
 *
 * Bản Blade có hai lưới thẻ: loại địa điểm (ảnh + icon + tên + số vị trí) và
 * tỉnh thành (ảnh trên nền gradient + tên + số vị trí). Khi chuyển sang Next
 * chúng rút xuống một hàng chip chữ và một danh sách liên kết kiểu chân
 * trang — không phải vì ai quyết định thế, mà vì `/filters` và `/locations`
 * là hai endpoint duy nhất có sẵn và **cả hai đều không mang ảnh**.
 *
 * `/filters` cố ý không mang: chú thích ở đó nói rõ `thumb` là một đường ảnh
 * và "không thuộc về một endpoint bộ lọc". Lý do đó vẫn đúng, nên đường ra
 * cho ảnh là một endpoint riêng chứ không phải nới lỏng cái cũ.
 *
 * CSS của hai lưới chưa bao giờ bị xoá khỏi `frontpage.css` — thiếu đúng một
 * đường dữ liệu.
 */
class BrowseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** Số thứ tự tự cấp: `venue_categories.id` là tinyint, không auto-increment. */
    private int $soLoai = 0;

    private function loaiDiaDiem(array $thuocTinh = []): VenueCategory
    {
        $this->soLoai++;

        return VenueCategory::create(array_merge([
            'id'         => $this->soLoai,
            'name'       => "Loai {$this->soLoai}",
            'slug'       => "loai-{$this->soLoai}",
            'name_vi'    => "Loại {$this->soLoai}",
            'icon'       => 'storefront',
            'thumb'      => null,
            'sort_order' => $this->soLoai,
            'is_active'  => true,
        ], $thuocTinh));
    }

    private function manHinh(?string $thanhPho = 'Hà Nội', ?VenueCategory $loai = null): Screen
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $site  = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => $thanhPho,
            'status'   => 'active',
        ]);

        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenSpec::factory()->create([
            'screen_id' => $screen->id,
            'width_cm'  => 400,
            'height_cm' => 200,
        ]);

        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'vn_category_id'         => $loai?->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        return $screen;
    }

    public function test_tra_ve_hai_nhom_voi_du_khoa_cua_the(): void
    {
        $loai = $this->loaiDiaDiem(['thumb' => 'venue/ban-le.jpg']);

        $this->manHinh('Hà Nội', $loai);

        $r = $this->getJson('/api/v2/browse');

        $r->assertOk()->assertJsonStructure([
            'data' => [
                'venue_types' => [['code', 'name', 'count', 'icon', 'thumb']],
                'cities'      => [['code', 'name', 'count', 'photo']],
            ],
        ]);
    }

    public function test_thumb_thanh_duong_dan_day_du(): void
    {
        // Thẻ nằm trên một trang do Next phục vụ, ở một origin khác lúc chạy
        // local. Một đường tương đối sẽ được trình duyệt giải theo trang đang
        // mở và ra ảnh hỏng — đúng lỗi đã xảy ra với `cover_url` của owner ở
        // lát 0 (ghi trong lộ trình 17/10/2026).
        $loai = $this->loaiDiaDiem(['thumb' => 'venue/ban-le.jpg']);

        $this->manHinh('Hà Nội', $loai);

        $thumb = $this->getJson('/api/v2/browse')->json('data.venue_types.0.thumb');

        $this->assertIsString($thumb);
        $this->assertStringStartsWith('http', $thumb, 'Đường ảnh phải tuyệt đối.');
    }

    public function test_thieu_anh_van_tra_ve_the(): void
    {
        // Trường hợp phổ biến của kho thật, không phải ngoại lệ: `thumb` và
        // `photo_url` đều cho phép null. Thẻ vẫn phải ra — icon và dải
        // gradient là lớp nền, ảnh chỉ là lớp trên.
        $loai = $this->loaiDiaDiem(['icon' => null, 'thumb' => null]);

        $this->manHinh('Sơn La', $loai);

        $data = $this->getJson('/api/v2/browse')->assertOk()->json('data');

        $this->assertNotEmpty($data['venue_types'], 'Thiếu ảnh không được làm mất thẻ.');
        $this->assertNull($data['venue_types'][0]['thumb']);

        // `icon` có mặc định, vì thẻ không ảnh mà cũng không icon là một ô xám.
        $this->assertSame('tv', $data['venue_types'][0]['icon']);
    }

    public function test_khong_qua_nam_loai_va_tam_tinh(): void
    {
        // Lưới `cat-grid` là một hàng năm cột trên desktop; thẻ thứ sáu rơi
        // xuống hàng hai một mình. Giới hạn nằm ở API để bên gọi không phải
        // nhớ cắt.
        for ($i = 0; $i < 7; $i++) {
            $this->manHinh(
                "Tỉnh {$i}",
                $this->loaiDiaDiem(),
            );
        }

        $data = $this->getJson('/api/v2/browse')->assertOk()->json('data');

        $this->assertLessThanOrEqual(5, count($data['venue_types']));
        $this->assertLessThanOrEqual(8, count($data['cities']));
    }

    public function test_khong_lo_truong_nhay_cam(): void
    {
        $this->manHinh('Hà Nội', $this->loaiDiaDiem());

        $tho = $this->getJson('/api/v2/browse')->content();

        foreach ([
            'revenue_share_pct', 'billing_info', 'bank_name', 'bank_account',
            'tax_code', 'business_license_path', 'device_token', 'floor_cpm',
        ] as $cam) {
            $this->assertStringNotContainsString(
                $cam,
                $tho,
                "`/api/v2/browse` trả trường nhạy cảm `{$cam}`.",
            );
        }
    }
}
