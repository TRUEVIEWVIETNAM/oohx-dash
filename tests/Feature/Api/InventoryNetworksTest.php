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
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Năm ca trong file này hỏng suốt từ baseline `112e2aa` vì F-12: hệ thống có
 * BA cách nói "màn hình thuộc mạng lưới nào" và chúng trả lời khác nhau. Test
 * dựng dữ liệu theo `screens.network_code`, còn endpoint đếm theo
 * `sites.network_id`, nên luôn ra rỗng.
 *
 * Giai đoạn 3 (29/09/2026) chốt **`sites.network_id` là nguồn sự thật duy
 * nhất** và đưa mọi đường đọc về đó. Nhãn `#[Group('f12-network-relation')]`
 * cùng cờ `--exclude-group` trong CI đã được gỡ.
 */
class InventoryNetworksTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $client = ApiClient::create([
            'client_id'     => 'test_networks',
            'client_secret' => bcrypt('secret'),
            'name'          => 'Test Networks',
            'scopes'        => ['inventory'],
            'active'        => true,
        ]);
        $this->token = $client->createToken('test', ['inventory'])->plainTextToken;
    }

    private function makeActiveScreenWithNetwork(string $networkCode): Screen
    {
        $owner = Owner::factory()->create(['status' => 'active']);

        // Gán mạng lưới cho ĐỊA ĐIỂM — nguồn sự thật duy nhất từ giai đoạn 3.
        $network = Network::firstOrCreate(
            ['code' => $networkCode],
            ['name' => $networkCode, 'owner_id' => $owner->id],
        );

        $site = Site::factory()->create([
            'owner_id'   => $owner->id,
            'network_id' => $network->id,
        ]);

        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);
        ScreenSpec::factory()->create(['screen_id' => $screen->id]);
        ScreenInventory::factory()->create(['screen_id' => $screen->id]);
        return $screen;
    }

    private function getNetworks(): \Illuminate\Testing\TestResponse
    {
        return $this->withToken($this->token)->getJson('/api/v1/inventory/networks');
    }

    public function test_returns_correct_structure(): void
    {
        $network = Network::factory()->create(['code' => 'winmart', 'name' => 'Winmart+']);
        $this->makeActiveScreenWithNetwork('winmart');
        Cache::forget('inventory:networks');

        $response = $this->getNetworks();

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['code', 'name', 'screen_count']],
            ]);
    }

    public function test_returns_empty_when_no_networks(): void
    {
        Cache::forget('inventory:networks');
        $response = $this->getNetworks();

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    public function test_only_networks_with_active_screens_are_returned(): void
    {
        $n1 = Network::factory()->create(['code' => 'net-a', 'name' => 'Net A']);
        $n2 = Network::factory()->create(['code' => 'net-b', 'name' => 'Net B']);

        // n1 có active screen, n2 thì không
        $this->makeActiveScreenWithNetwork('net-a');

        // Inactive screen cho n2
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        Screen::factory()->create([
            'owner_id'     => $owner->id,
            'site_id'      => $site->id,
            'active'       => false,
            'network_code' => 'net-b',
        ]);

        Cache::forget('inventory:networks');
        $response = $this->getNetworks();

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertContains('net-a', $codes->toArray());
        $this->assertNotContains('net-b', $codes->toArray());
    }

    public function test_screen_count_is_correct(): void
    {
        Network::factory()->create(['code' => 'aeon', 'name' => 'AEON Mall']);
        $this->makeActiveScreenWithNetwork('aeon');
        $this->makeActiveScreenWithNetwork('aeon');

        Cache::forget('inventory:networks');
        $response = $this->getNetworks();

        $response->assertOk();
        $item = collect($response->json('data'))->firstWhere('code', 'aeon');
        $this->assertEquals(2, $item['screen_count']);
    }

    public function test_sorted_by_screen_count_desc(): void
    {
        Network::factory()->create(['code' => 'small-net', 'name' => 'Small']);
        Network::factory()->create(['code' => 'big-net',   'name' => 'Big']);

        $this->makeActiveScreenWithNetwork('big-net');
        $this->makeActiveScreenWithNetwork('big-net');
        $this->makeActiveScreenWithNetwork('small-net');

        Cache::forget('inventory:networks');
        $response = $this->getNetworks();

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->toArray();
        $this->assertEquals('big-net', $codes[0]);
        $this->assertEquals('small-net', $codes[1]);
    }

    public function test_result_is_cached(): void
    {
        Network::factory()->create(['code' => 'cached-net', 'name' => 'Cached']);
        $this->makeActiveScreenWithNetwork('cached-net');
        Cache::forget('inventory:networks');

        $this->getNetworks()->assertOk();
        $this->assertTrue(Cache::has('inventory:networks'));

        // Thêm màn hình mới nhưng không clear cache — count vẫn là 1
        $this->makeActiveScreenWithNetwork('cached-net');
        $response = $this->getNetworks();
        $item = collect($response->json('data'))->firstWhere('code', 'cached-net');
        $this->assertEquals(1, $item['screen_count']);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/inventory/networks')->assertUnauthorized();
    }
}
