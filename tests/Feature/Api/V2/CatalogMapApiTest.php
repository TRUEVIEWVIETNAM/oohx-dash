<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Giai đoạn 5, mốc 2 — endpoint bản đồ.
 *
 * Điều khoản đang canh ở đây, từ CLAUDE.md mục 2: **"Endpoint bản đồ bắt buộc
 * có khung nhìn."** Không có khung nhìn thì "lấy pin bản đồ" nghĩa là lấy mọi
 * màn hình có toạ độ — một lần xuất toàn bộ kho dưới cái tên vô hại.
 *
 * Bốn thứ test này canh:
 *
 *  1. Thiếu khung nhìn thì **hỏng ngay**, không chạy được một cách âm thầm.
 *  2. Khung nhìn **lọc thật**, không phải tham số trang trí được nhận rồi bỏ.
 *  3. Giới hạn cứng số pin, và khi bị cắt thì **nói ra** chứ không trả thiếu
 *     trong im lặng.
 *  4. Cắt có thứ tự xác định — kéo bản đồ qua lại không ra tập pin khác nhau.
 */
class CatalogMapApiTest extends TestCase
{
    use RefreshDatabase;

    /** Khung nhìn trùm Hà Nội, dùng cho phần lớn ca test. */
    private const HANOI = 'north=21.2&south=20.9&east=105.9&west=105.6';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function screenAt(float $lat, float $lon, ?Owner $owner = null): Screen
    {
        $owner  = $owner ?: Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => 'Hà Nội',
            'status'   => 'active',
            'lat'      => $lat,
            'lon'      => $lon,
        ]);
        $screen = Screen::factory()->create([
            'owner_id'     => $owner->id,
            'site_id'      => $site->id,
            'active'       => true,
            'device_token' => 'bi-mat-cua-thiet-bi',
        ]);

        ScreenSpec::factory()->create(['screen_id' => $screen->id, 'width_cm' => 400, 'height_cm' => 200]);
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

    // ── Khung nhìn bắt buộc ─────────────────────────────────────────────────

    public function test_thieu_khung_nhin_thi_tu_choi(): void
    {
        $this->screenAt(21.02, 105.80);

        $this->getJson('/api/v2/screens/map')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('code', 422);
    }

    public function test_thieu_mot_canh_cung_bi_tu_choi(): void
    {
        $this->screenAt(21.02, 105.80);

        // Ba cạnh vẫn không thành khung nhìn. Nhận thiếu một cạnh rồi tự đoán
        // là cách âm thầm biến endpoint có giới hạn thành endpoint không.
        foreach (['north', 'south', 'east', 'west'] as $missing) {
            $params = collect(['north' => 21.2, 'south' => 20.9, 'east' => 105.9, 'west' => 105.6])
                ->except($missing)
                ->map(fn ($v, $k) => "{$k}={$v}")
                ->implode('&');

            $response = $this->getJson('/api/v2/screens/map?' . $params);

            $response->assertStatus(422);
            $this->assertContains(
                $missing,
                collect($response->json('details'))->pluck('field')->all(),
                "Thiếu cạnh \"{$missing}\" mà lỗi trả về không nhắc tới nó."
            );
        }
    }

    public function test_khung_nhin_lon_nguoc_thi_tu_choi(): void
    {
        $this->screenAt(21.02, 105.80);

        // north <= south là khung nhìn rỗng. Nhận nó thì API trả 0 pin và
        // trông y hệt "khu vực này không có màn hình nào".
        $this->getJson('/api/v2/screens/map?north=20.9&south=21.2&east=105.9&west=105.6')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_toa_do_ngoai_dai_hop_le_thi_tu_choi(): void
    {
        $this->getJson('/api/v2/screens/map?north=95&south=20.9&east=105.9&west=105.6')
            ->assertStatus(422);
    }

    // ── Khung nhìn lọc thật ─────────────────────────────────────────────────

    public function test_pin_ngoai_khung_nhin_khong_duoc_tra_ve(): void
    {
        $trong  = $this->screenAt(21.02, 105.80);   // Hà Nội
        $ngoai  = $this->screenAt(10.77, 106.70);   // TP.HCM

        $response = $this->getJson('/api/v2/screens/map?' . self::HANOI)->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains($trong->slug, $slugs);
        $this->assertNotContains(
            $ngoai->slug,
            $slugs,
            'Màn hình ngoài khung nhìn vẫn lọt ra — khung nhìn đang là tham số trang trí.'
        );
    }

    public function test_man_hinh_khong_cong_khai_khong_len_ban_do(): void
    {
        $an = $this->screenAt(21.02, 105.80);
        $an->update(['active' => false]);

        $this->getJson('/api/v2/screens/map?' . self::HANOI)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_bo_loc_cua_ban_do_giong_bo_loc_cua_danh_sach(): void
    {
        $ownerA = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-a']);
        $ownerB = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-b']);

        $a = $this->screenAt(21.02, 105.80, $ownerA);
        $b = $this->screenAt(21.03, 105.81, $ownerB);

        $response = $this->getJson('/api/v2/screens/map?' . self::HANOI . '&owner[]=owner-a')->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains($a->slug, $slugs);
        $this->assertNotContains($b->slug, $slugs);
    }

    // ── Giới hạn cứng và nói thật khi bị cắt ────────────────────────────────

    public function test_gioi_han_pin_bi_kep_ve_muc_cung(): void
    {
        $this->screenAt(21.02, 105.80);

        $this->getJson('/api/v2/screens/map?' . self::HANOI . '&limit=10000')
            ->assertOk()
            ->assertJsonPath('meta.limit', 500)
            ->assertJsonPath('meta.max_limit', 500);
    }

    public function test_bi_cat_thi_noi_ra_chu_khong_tra_thieu_trong_im_lang(): void
    {
        $this->screenAt(21.02, 105.80);
        $this->screenAt(21.03, 105.81);
        $this->screenAt(21.04, 105.82);

        $response = $this->getJson('/api/v2/screens/map?' . self::HANOI . '&limit=1')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.returned', 1)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.truncated', true);
    }

    public function test_khong_bi_cat_thi_khong_bao_la_bi_cat(): void
    {
        $this->screenAt(21.02, 105.80);
        $this->screenAt(21.03, 105.81);

        $this->getJson('/api/v2/screens/map?' . self::HANOI)
            ->assertOk()
            ->assertJsonPath('meta.returned', 2)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.truncated', false);
    }

    public function test_tong_so_dem_theo_dung_khung_nhin_dang_hoi(): void
    {
        $this->screenAt(21.02, 105.80);
        $this->screenAt(21.03, 105.81);
        $this->screenAt(10.77, 106.70);   // ngoài khung

        // `meta.total` là tổng TRONG khung nhìn, không phải tổng cả kho. Đếm
        // cả kho rồi bảo "bị cắt" là đẩy client đi thu nhỏ khung nhìn mãi mà
        // không bao giờ hết.
        $this->getJson('/api/v2/screens/map?' . self::HANOI . '&limit=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_cat_co_thu_tu_xac_dinh(): void
    {
        foreach ([[21.02, 105.80], [21.03, 105.81], [21.04, 105.82], [21.05, 105.83]] as [$lat, $lon]) {
            $this->screenAt($lat, $lon);
        }

        $lan1 = $this->getJson('/api/v2/screens/map?' . self::HANOI . '&limit=2')->assertOk();
        $lan2 = $this->getJson('/api/v2/screens/map?' . self::HANOI . '&limit=2')->assertOk();

        $this->assertSame(
            collect($lan1->json('data'))->pluck('slug')->all(),
            collect($lan2->json('data'))->pluck('slug')->all(),
            'Hai lần gọi giống hệt nhau ra hai tập pin khác nhau — pin bị cắt đang không có thứ tự.'
        );
    }

    // ── Danh sách trắng ─────────────────────────────────────────────────────

    public function test_pin_khong_lo_truong_nhay_cam(): void
    {
        $owner = Owner::factory()->create(['status' => 'active', 'revenue_share_pct' => 63.17]);
        $this->screenAt(21.02, 105.80, $owner);

        $response = $this->getJson('/api/v2/screens/map?' . self::HANOI)->assertOk();

        $this->assertStringNotContainsString('device_token', $response->getContent());
        $this->assertStringNotContainsString('revenue_share_pct', $response->getContent());
        $this->assertStringNotContainsString('63.17', $response->getContent());
    }

    public function test_pin_co_toa_do_that(): void
    {
        $this->screenAt(21.0245, 105.8412);

        $this->getJson('/api/v2/screens/map?' . self::HANOI)
            ->assertOk()
            ->assertJsonPath('data.0.lat', 21.0245)
            ->assertJsonPath('data.0.lng', 105.8412);
    }

    public function test_meta_tra_lai_dung_khung_nhin_da_hoi(): void
    {
        $this->screenAt(21.02, 105.80);

        $this->getJson('/api/v2/screens/map?' . self::HANOI)
            ->assertOk()
            ->assertJsonPath('meta.viewport.north', 21.2)
            ->assertJsonPath('meta.viewport.south', 20.9)
            ->assertJsonPath('meta.viewport.east', 105.9)
            ->assertJsonPath('meta.viewport.west', 105.6);
    }
}
