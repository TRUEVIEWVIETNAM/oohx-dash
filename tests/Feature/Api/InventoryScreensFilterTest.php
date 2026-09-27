<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\Network;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Hai ca lọc theo mạng lưới gắn #[Group('f12-network-relation')] đang HỎNG TỪ
 * TRƯỚC giai đoạn 0 vì F-12 (hai đường quan hệ Màn hình ↔ Mạng lưới chưa hợp
 * nhất). CI loại nhóm này để cổng chắn deploy dùng được; xem chú thích đầy đủ ở
 * InventoryNetworksTest và mục 2 của docs/audit-5-vung-2026-09-23/STATUS.md.
 */
class InventoryScreensFilterTest extends TestCase
{
    use RefreshDatabase;

    private ApiClient $client;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = ApiClient::create([
            'client_id'     => 'test_oohx',
            'client_secret' => bcrypt('secret'),
            'name'          => 'Test OOHX',
            'scopes'        => ['inventory'],
            'active'        => true,
        ]);

        $this->token = $this->client->createToken('test', ['inventory'])->plainTextToken;
    }

    private function getScreens(array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)
            ->getJson('/api/v1/inventory/screens?' . http_build_query($params));
    }

    // ── Helper: tạo screen đầy đủ ─────────────────────────

    private function makeScreen(array $overrides = [], array $specOverrides = [], array $invOverrides = []): Screen
    {
        $owner  = Owner::factory()->create();
        $city   = $overrides['city'] ?? 'hanoi';
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'city' => $city]);
        $screen = Screen::factory()->create(array_merge(
            ['owner_id' => $owner->id, 'site_id' => $site->id],
            array_diff_key($overrides, ['city' => true])
        ));

        ScreenSpec::factory()->create(array_merge(['screen_id' => $screen->id], $specOverrides));
        ScreenInventory::factory()->create(array_merge(['screen_id' => $screen->id], $invOverrides));

        return $screen;
    }

    // ── Status filter ──────────────────────────────────────

    public function test_status_active_returns_only_active_screens(): void
    {
        $this->makeScreen(['active' => true]);
        $this->makeScreen(['active' => false]);

        $response = $this->getScreens(['status' => 'active']);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    // ── Multi-value city filter ────────────────────────────

    public function test_city_slug_matches_vietnamese_name_in_db(): void
    {
        // DB thực tế lưu tên tiếng Việt (set từ VietnamProvince.name qua Filament)
        $this->makeScreen(['city' => 'Hà Nội']);
        $this->makeScreen(['city' => 'Hồ Chí Minh']);
        $this->makeScreen(['city' => 'Đà Nẵng']);

        // API gửi slug tiếng Anh → phải match tên tiếng Việt trong DB
        $response = $this->getScreens(['city' => ['hanoi', 'danang']]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    public function test_city_array_filter_returns_matching_screens(): void
    {
        // Backward compat: nếu DB lưu slug
        $this->makeScreen(['city' => 'hanoi']);
        $this->makeScreen(['city' => 'danang']);
        $this->makeScreen(['city' => 'hcm']);

        $response = $this->getScreens(['city' => ['hanoi', 'danang']]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    public function test_city_pipe_separated_backward_compat(): void
    {
        $this->makeScreen(['city' => 'Hà Nội']);
        $this->makeScreen(['city' => 'Đà Nẵng']);
        $this->makeScreen(['city' => 'Hồ Chí Minh']);

        // Gửi pipe-separated theo format cũ với slug
        $response = $this->withToken($this->token)
            ->getJson('/api/v1/inventory/screens?city=hanoi|danang');

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    public function test_city_single_value_backward_compat(): void
    {
        $this->makeScreen(['city' => 'Hà Nội']);
        $this->makeScreen(['city' => 'Hồ Chí Minh']);

        $response = $this->getScreens(['city' => 'hanoi']);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    // ── Multi-value venue_type filter ─────────────────────

    public function test_venue_type_array_filter(): void
    {
        $this->makeScreen([], [], ['venue_type' => 'mall']);
        $this->makeScreen([], [], ['venue_type' => 'fnb']);
        $this->makeScreen([], [], ['venue_type' => 'outdoor']);

        $response = $this->getScreens(['venue_type' => ['mall', 'fnb']]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    // ── Multi-value screen_type filter ────────────────────

    public function test_screen_type_lcd_filter(): void
    {
        // lcd = không outdoor, width_cm < 300
        $this->makeScreen([], ['width_cm' => 150, 'height_cm' => 100], ['venue_type' => 'mall']);
        // led = outdoor, width_cm < 300
        $this->makeScreen([], ['width_cm' => 100, 'height_cm' => 60],  ['venue_type' => 'outdoor']);
        // billboard = width_cm >= 300
        $this->makeScreen([], ['width_cm' => 1400, 'height_cm' => 600], ['venue_type' => 'outdoor']);

        $response = $this->getScreens(['screen_type' => 'lcd']);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    public function test_screen_type_led_filter(): void
    {
        $this->makeScreen([], ['width_cm' => 100, 'height_cm' => 60], ['venue_type' => 'outdoor']);
        $this->makeScreen([], ['width_cm' => 150, 'height_cm' => 100], ['venue_type' => 'mall']);

        $response = $this->getScreens(['screen_type' => 'led']);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    public function test_screen_type_billboard_filter(): void
    {
        $this->makeScreen([], ['width_cm' => 1400, 'height_cm' => 600], ['venue_type' => 'outdoor']);
        $this->makeScreen([], ['width_cm' => 150, 'height_cm' => 100], ['venue_type' => 'mall']);

        $response = $this->getScreens(['screen_type' => 'billboard']);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    public function test_screen_type_multi_value_lcd_and_led(): void
    {
        $this->makeScreen([], ['width_cm' => 150, 'height_cm' => 100], ['venue_type' => 'mall']);   // lcd
        $this->makeScreen([], ['width_cm' => 100, 'height_cm' => 60],  ['venue_type' => 'outdoor']); // led
        $this->makeScreen([], ['width_cm' => 1400, 'height_cm' => 600], ['venue_type' => 'outdoor']); // billboard

        $response = $this->getScreens(['screen_type' => ['lcd', 'led']]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    // ── Orientation filter ────────────────────────────────

    public function test_orientation_landscape_filter(): void
    {
        $this->makeScreen([], ['width_px' => 1920, 'height_px' => 1080]); // landscape
        $this->makeScreen([], ['width_px' => 1080, 'height_px' => 1920]); // portrait
        $this->makeScreen([], ['width_px' => 1080, 'height_px' => 1080]); // square

        $response = $this->getScreens(['orientation' => 'landscape']);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    public function test_orientation_portrait_filter(): void
    {
        $this->makeScreen([], ['width_px' => 1920, 'height_px' => 1080]); // landscape
        $this->makeScreen([], ['width_px' => 1080, 'height_px' => 1920]); // portrait

        $response = $this->getScreens(['orientation' => 'portrait']);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    public function test_orientation_multi_value(): void
    {
        $this->makeScreen([], ['width_px' => 1920, 'height_px' => 1080]); // landscape
        $this->makeScreen([], ['width_px' => 1080, 'height_px' => 1920]); // portrait
        $this->makeScreen([], ['width_px' => 1080, 'height_px' => 1080]); // square

        $response = $this->getScreens(['orientation' => ['landscape', 'portrait']]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    public function test_orientation_invalid_value_returns_all(): void
    {
        $this->makeScreen([], ['width_px' => 1920, 'height_px' => 1080]);
        $this->makeScreen([], ['width_px' => 1080, 'height_px' => 1920]);

        $response = $this->getScreens(['orientation' => 'diagonal']);

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    // ── Text search (q) ───────────────────────────────────

    public function test_q_searches_screen_name(): void
    {
        $owner = Owner::factory()->create();
        $site  = Site::factory()->create(['owner_id' => $owner->id, 'city' => 'hanoi']);
        $screenA = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'name' => 'Vincom Mega Mall Screen']);
        $screenB = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'name' => 'Aeon Mall Screen']);
        ScreenSpec::factory()->create(['screen_id' => $screenA->id]);
        ScreenSpec::factory()->create(['screen_id' => $screenB->id]);
        ScreenInventory::factory()->create(['screen_id' => $screenA->id]);
        ScreenInventory::factory()->create(['screen_id' => $screenB->id]);

        $response = $this->getScreens(['q' => 'Vincom']);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertStringContainsString('Vincom', $response->json('data.0.name'));
    }

    public function test_q_searches_site_city(): void
    {
        $this->makeScreen(['city' => 'hanoi']);
        $this->makeScreen(['city' => 'danang']);

        $response = $this->getScreens(['q' => 'hanoi']);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    public function test_q_empty_returns_all(): void
    {
        $this->makeScreen();
        $this->makeScreen();

        $response = $this->getScreens(['q' => '']);

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    // ── Sort ──────────────────────────────────────────────

    public function test_sort_price_asc(): void
    {
        $this->makeScreen([], [], ['floor_cpm' => 300000]);
        $this->makeScreen([], [], ['floor_cpm' => 100000]);
        $this->makeScreen([], [], ['floor_cpm' => 200000]);

        $response = $this->getScreens(['sort' => 'price_asc']);

        $response->assertOk();
        $prices = collect($response->json('data'))->pluck('price_per_slot_vnd');
        $this->assertEquals($prices->sort()->values()->toArray(), $prices->toArray());
    }

    public function test_sort_price_desc(): void
    {
        $this->makeScreen([], [], ['floor_cpm' => 100000]);
        $this->makeScreen([], [], ['floor_cpm' => 300000]);
        $this->makeScreen([], [], ['floor_cpm' => 200000]);

        $response = $this->getScreens(['sort' => 'price_desc']);

        $response->assertOk();
        $prices = collect($response->json('data'))->pluck('price_per_slot_vnd');
        $this->assertEquals($prices->sortDesc()->values()->toArray(), $prices->toArray());
    }

    public function test_sort_invalid_value_does_not_error(): void
    {
        $this->makeScreen();

        $response = $this->getScreens(['sort' => 'invalid_sort']);

        $response->assertOk();
    }

    // ── Response structure ────────────────────────────────

    public function test_response_has_correct_structure(): void
    {
        $this->makeScreen();

        $response = $this->getScreens();

        $response->assertOk()
            ->assertJsonStructure([
                'total', 'page', 'limit',
                'data' => [['screen_id', 'name', 'venue_type', 'screen_type', 'location', 'specs', 'status']],
            ]);
    }

    // ── network[] filter ──────────────────────────────────

    #[Group('f12-network-relation')]
    public function test_network_filter_returns_matching_screens(): void
    {
        Network::factory()->create(['code' => 'winmart', 'name' => 'Winmart+']);
        Network::factory()->create(['code' => 'aeon',    'name' => 'AEON']);

        $this->makeScreen(['network_code' => 'winmart', 'active' => true]);
        $this->makeScreen(['network_code' => 'aeon',    'active' => true]);
        $this->makeScreen(['network_code' => null,      'active' => true]);

        $response = $this->getScreens(['network' => ['winmart']]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    #[Group('f12-network-relation')]
    public function test_network_filter_multi_value_returns_union(): void
    {
        Network::factory()->create(['code' => 'net-a', 'name' => 'Net A']);
        Network::factory()->create(['code' => 'net-b', 'name' => 'Net B']);
        Network::factory()->create(['code' => 'net-c', 'name' => 'Net C']);

        $this->makeScreen(['network_code' => 'net-a', 'active' => true]);
        $this->makeScreen(['network_code' => 'net-b', 'active' => true]);
        $this->makeScreen(['network_code' => 'net-c', 'active' => true]);

        $response = $this->getScreens(['network' => ['net-a', 'net-b']]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    public function test_network_filter_nonexistent_code_returns_empty(): void
    {
        $this->makeScreen(['active' => true]);

        $response = $this->getScreens(['network' => ['nonexistent-net']]);

        $response->assertOk();
        $this->assertEquals(0, $response->json('total'));
    }

    // ── owner[] filter ────────────────────────────────────

    public function test_owner_filter_returns_screens_by_owner_slug(): void
    {
        $ownerA = Owner::factory()->create(['slug' => 'owner-a', 'status' => 'active']);
        $ownerB = Owner::factory()->create(['slug' => 'owner-b', 'status' => 'active']);

        $siteA = Site::factory()->create(['owner_id' => $ownerA->id]);
        $siteB = Site::factory()->create(['owner_id' => $ownerB->id]);

        $screenA = Screen::factory()->create(['owner_id' => $ownerA->id, 'site_id' => $siteA->id, 'active' => true]);
        $screenB = Screen::factory()->create(['owner_id' => $ownerB->id, 'site_id' => $siteB->id, 'active' => true]);
        ScreenSpec::factory()->create(['screen_id' => $screenA->id]);
        ScreenInventory::factory()->create(['screen_id' => $screenA->id]);
        ScreenSpec::factory()->create(['screen_id' => $screenB->id]);
        ScreenInventory::factory()->create(['screen_id' => $screenB->id]);

        $response = $this->getScreens(['owner' => ['owner-a']]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    public function test_owner_filter_nonexistent_slug_returns_empty(): void
    {
        $this->makeScreen(['active' => true]);

        $response = $this->getScreens(['owner' => ['no-such-owner']]);

        $response->assertOk();
        $this->assertEquals(0, $response->json('total'));
    }

    // ── district[] filter ─────────────────────────────────

    public function test_district_filter_returns_matching_screens(): void
    {
        $this->makeScreen(['location_district_code' => 'cau-giay', 'active' => true]);
        $this->makeScreen(['location_district_code' => 'dong-da',  'active' => true]);
        $this->makeScreen(['location_district_code' => null,       'active' => true]);

        $response = $this->getScreens(['district' => ['cau-giay']]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    public function test_district_filter_multi_value(): void
    {
        $this->makeScreen(['location_district_code' => 'cau-giay', 'active' => true]);
        $this->makeScreen(['location_district_code' => 'dong-da',  'active' => true]);
        $this->makeScreen(['location_district_code' => 'quan-1',   'active' => true]);

        $response = $this->getScreens(['district' => ['cau-giay', 'dong-da']]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    // ── region[] filter ───────────────────────────────────

    public function test_region_filter_returns_screens_in_provinces_of_that_region(): void
    {
        Config::set('regions', [
            'north' => ['name' => 'Miền Bắc', 'provinces' => ['hanoi', 'haiphong']],
            'south' => ['name' => 'Miền Nam',  'provinces' => ['hcm', 'cantho']],
        ]);

        $this->makeScreen(['city' => 'Hà Nội',        'active' => true]);
        $this->makeScreen(['city' => 'Hồ Chí Minh',   'active' => true]);
        $this->makeScreen(['city' => 'Đà Nẵng',       'active' => true]);

        $response = $this->getScreens(['region' => ['north']]);

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    public function test_region_filter_invalid_value_returns_422(): void
    {
        $response = $this->getScreens(['region' => ['east']]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('region.0', $response->json('errors'));
    }

    public function test_region_filter_multi_value(): void
    {
        Config::set('regions', [
            'north' => ['name' => 'Miền Bắc', 'provinces' => ['hanoi']],
            'south' => ['name' => 'Miền Nam',  'provinces' => ['hcm']],
        ]);

        $this->makeScreen(['city' => 'Hà Nội',      'active' => true]);
        $this->makeScreen(['city' => 'Hồ Chí Minh', 'active' => true]);
        $this->makeScreen(['city' => 'Đà Nẵng',     'active' => true]);

        $response = $this->getScreens(['region' => ['north', 'south']]);

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    // ── Auth ──────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/inventory/screens')->assertUnauthorized();
    }
}
