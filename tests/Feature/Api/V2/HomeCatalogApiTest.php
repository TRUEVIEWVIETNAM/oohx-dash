<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bốn endpoint `/api/v2` cho trang chủ.
 *
 *   GET screens/featured   màn hình nổi bật, KÈM suất còn lại
 *   GET owners/featured    media owner nổi bật
 *   GET screens/pins       pin bản đồ của MỘT thành phố
 *   GET locations          tỉnh thành nhóm theo vùng
 *
 * Những thứ test này canh, theo thứ tự quan trọng:
 *
 *  1. **`city` lạ phải là 422, không phải pin của cả nước.**
 *     `resolveCityName()` trả `null` cho slug không nhận ra, và truy vấn khi
 *     đó KHÔNG áp filter thành phố nào. `required` một mình không đóng được
 *     cửa đó.
 *  2. **Thứ tự route.** `screens/featured` phải khớp action của nó, không rơi
 *     vào `screens/{slug}` rồi trả 404.
 *  3. **`availability` là `null` khi không tính được, không phải 0.**
 *  4. **Giới hạn cứng** — `limit` vượt mức bị kẹp, không trả cả kho.
 *  5. **`/locations` trả mảng có `code`**, không trả object khoá bằng tên
 *     vùng tiếng Việt.
 */
class HomeCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Màn hình đủ điều kiện "nổi bật": công khai, **có ảnh**, **có giá sàn > 0**.
     *
     * Thiếu một trong hai điều kiện sau thì `getFeaturedScreens()` bỏ qua —
     * nên fixture phải có cả hai, nếu không test xanh với danh sách rỗng và
     * không đo gì.
     */
    private function featurableScreen(?Owner $owner = null, string $city = 'Hà Nội'): Screen
    {
        $owner  = $owner ?: Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => $city,
            'status'   => 'active',
            'lat'      => 21.028,
            'lon'      => 105.834,
        ]);
        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenSpec::factory()->create([
            'screen_id' => $screen->id,
            'photo_url' => 'screens/anh-that.jpg',
            'width_cm'  => 400,
            'height_cm' => 200,
        ]);

        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'floor_cpm'              => 50_000,
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        return $screen->fresh(['inventory', 'spec', 'site']);
    }

    // ── screens/pins: phạm vi bắt buộc ──────────────────────────────────────

    public function test_thieu_city_thi_422_chu_khong_tra_ca_nuoc(): void
    {
        $this->featurableScreen();

        $this->getJson('/api/v2/screens/pins')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('details.0.field', 'city');
    }

    public function test_city_la_thi_422_chu_khong_bo_qua_filter(): void
    {
        // Đây là phép kiểm quan trọng nhất của cả bốn endpoint.
        //
        // `resolveCityName('xyz')` trả `null`, và `getCityMapPins()` khi đó
        // KHÔNG áp filter thành phố nào — tức `?city=xyz` cho ra pin của cả
        // nước. `required` một mình không chặn được; phải là `in:` theo danh
        // sách thật.
        $this->featurableScreen(city: 'Hà Nội');
        $this->featurableScreen(city: 'Hồ Chí Minh');

        $this->getJson('/api/v2/screens/pins?city=khong-ton-tai')
            ->assertStatus(422)
            ->assertJsonPath('details.0.field', 'city');
    }

    public function test_city_hop_le_chi_tra_pin_cua_thanh_pho_do(): void
    {
        $hanoi = $this->featurableScreen(city: 'Hà Nội');
        $hcm   = $this->featurableScreen(city: 'Hồ Chí Minh');

        $slugs = collect(
            $this->getJson('/api/v2/screens/pins?city=hanoi')->assertOk()->json('data')
        )->pluck('slug');

        $this->assertContains($hanoi->slug, $slugs);
        $this->assertNotContains($hcm->slug, $slugs);
    }

    public function test_pin_mang_don_vi_tien_khong_phai_so_tran(): void
    {
        $this->featurableScreen();

        $pin = $this->getJson('/api/v2/screens/pins?city=hanoi')
            ->assertOk()
            ->json('data.0');

        // `getHomepageMapPins()` của bản Blade trả `price` là số trần không
        // kèm đơn vị tiền — đúng lỗi R39. Endpoint này dùng `MapPinResource`,
        // nên đơn vị đi cùng số.
        $this->assertArrayHasKey('currency', $pin['price']);
        $this->assertSame('VND', $pin['price']['currency']);
    }

    public function test_pin_co_gioi_han_cung(): void
    {
        $this->featurableScreen();

        $this->getJson('/api/v2/screens/pins?city=hanoi&limit=99999')
            ->assertOk()
            ->assertJsonPath('meta.limit', 100)
            ->assertJsonPath('meta.max_limit', 100);
    }

    // ── screens/featured ────────────────────────────────────────────────────

    public function test_screens_featured_khop_action_cua_no_khong_roi_vao_slug(): void
    {
        $this->featurableScreen();

        // Thứ tự route: `screens/featured` phải đứng TRƯỚC `screens/{slug}`.
        // Đặt sau thì `screen('featured')` chạy, không tìm thấy màn hình nào
        // có slug đó, và trả 404 — một lỗi đọc log không ra nguyên nhân, vì
        // 404 là câu trả lời hợp lệ của endpoint kia.
        $this->getJson('/api/v2/screens/featured')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['returned', 'limit', 'max_limit']]);
    }

    public function test_screens_featured_tra_suat_con_lai(): void
    {
        $this->featurableScreen();

        $data = $this->getJson('/api/v2/screens/featured')->assertOk()->json('data.0');

        // `/screens` không trả suất; endpoint này có. Thiếu nó thì trang chủ
        // không in được badge "Còn trống", hoặc in bừa — audit F-15.
        $this->assertArrayHasKey('availability', $data);
        $this->assertSame(30, $data['availability']['window_days']);
        $this->assertSame(100, $data['availability']['remaining_sov_pct']);
        $this->assertTrue($data['availability']['has_capacity']);
    }

    public function test_screens_featured_co_gioi_han_cung(): void
    {
        $this->featurableScreen();

        $this->getJson('/api/v2/screens/featured?limit=99999')
            ->assertOk()
            ->assertJsonPath('meta.limit', 12)
            ->assertJsonPath('meta.max_limit', 12);
    }

    public function test_man_hinh_khong_co_anh_khong_duoc_coi_la_noi_bat(): void
    {
        // Không ảnh thì `getFeaturedScreens()` bỏ qua. Ghi lại thành test vì
        // đây là chỗ khiến một fixture thiếu ảnh cho ra danh sách rỗng, và
        // test khác sẽ xanh mà không đo gì.
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);
        ScreenSpec::factory()->create(['screen_id' => $screen->id, 'photo_url' => null, 'photos' => null]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'floor_cpm'              => 50_000,
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        $this->getJson('/api/v2/screens/featured')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ── owners/featured ─────────────────────────────────────────────────────

    public function test_owners_featured_khop_action_cua_no(): void
    {
        $this->featurableScreen();

        $this->getJson('/api/v2/owners/featured')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['returned', 'limit', 'max_limit']]);
    }

    public function test_owners_featured_lui_ve_owner_dang_hoat_dong_khi_chua_ai_duoc_danh_dau(): void
    {
        $owner = Owner::factory()->create(['status' => 'active', 'featured' => false]);
        $this->featurableScreen($owner);

        // Lùi về có chủ ý: một trang chủ trống vì thiếu một cờ quản trị là lỗi
        // khó đoán nguyên nhân.
        $slugs = collect($this->getJson('/api/v2/owners/featured')->assertOk()->json('data'))
            ->pluck('slug');

        $this->assertContains($owner->slug, $slugs);
    }

    public function test_owners_featured_khong_lo_du_lieu_rieng_cua_owner(): void
    {
        $owner = Owner::factory()->create([
            'status'            => 'active',
            'revenue_share_pct' => 63.17,
            'tax_code'          => '0101234567',
            'bank_account_number' => '0011001234567',
        ]);
        $this->featurableScreen($owner);

        $body = $this->getJson('/api/v2/owners/featured')->assertOk()->getContent();

        foreach (['revenue_share_pct', 'tax_code', 'bank_account_number', '0011001234567'] as $camKy) {
            $this->assertStringNotContainsString($camKy, $body, "owners/featured để lộ \"{$camKy}\".");
        }
    }

    // ── locations ───────────────────────────────────────────────────────────

    public function test_locations_tra_mang_co_code_khong_tra_object_khoa_bang_ten(): void
    {
        $this->featurableScreen(city: 'Hà Nội');

        $data = $this->getJson('/api/v2/locations')->assertOk()->json('data');

        // Hợp đồng là MẢNG. `getLocationsByRegion()` trả object khoá bằng tên
        // vùng tiếng Việt, và dùng hình dạng đó thì đổi tên vùng trong
        // `config/regions.php` là đổi khoá của response — một thay đổi hiển
        // thị làm vỡ bên tiêu thụ.
        $this->assertIsList($data);

        if ($data === []) {
            $this->fail('Dựng một màn hình ở Hà Nội mà /locations trả rỗng — fixture hoặc config/regions.php lệch.');
        }

        $this->assertSame(['code', 'name', 'provinces'], array_keys($data[0]));
        $this->assertIsList($data[0]['provinces']);
        $this->assertSame(['code', 'name', 'count'], array_keys($data[0]['provinces'][0]));
    }

    public function test_locations_dem_so_man_hinh_thuc_te(): void
    {
        $this->featurableScreen(city: 'Hà Nội');
        $this->featurableScreen(city: 'Hà Nội');

        $data = $this->getJson('/api/v2/locations')->assertOk()->json('data');

        $tong = collect($data)
            ->flatMap(fn (array $r) => $r['provinces'])
            ->sum('count');

        $this->assertSame(2, $tong);
    }

    // ── Hợp đồng ────────────────────────────────────────────────────────────

    public function test_bon_endpoint_deu_khong_can_dang_nhap(): void
    {
        $this->featurableScreen();

        foreach ([
            '/api/v2/screens/featured',
            '/api/v2/owners/featured',
            '/api/v2/screens/pins?city=hanoi',
            '/api/v2/locations',
        ] as $url) {
            $this->getJson($url)->assertOk();
        }
    }
}
