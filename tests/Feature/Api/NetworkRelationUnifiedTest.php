<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\Network;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Giai đoạn 3 — một mạng lưới, một con số.
 *
 * F-12 không phải lỗi đếm sai; nó là lỗi **có ba cách trả lời cùng một câu
 * hỏi**: `sites.network_id` (trang công khai), `screen_inventory.network_id`
 * (năm chỗ trong Filament), và `screens.network_code` (quan hệ trên model).
 * Hệ quả: cùng một mạng lưới, trang quản trị và trang công khai báo hai con số
 * màn hình khác nhau, và không ai biết con số nào đúng.
 *
 * Nên test ở đây không kiểm "đếm có đúng không" mà kiểm **hai đường có cho
 * cùng một đáp số không**. Đó mới là thứ hỏng, và là thứ sẽ hỏng lại nếu ai đó
 * thêm một đường thứ tư.
 */
class NetworkRelationUnifiedTest extends TestCase
{
    use RefreshDatabase;

    private string $token;
    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $client = ApiClient::create([
            'client_id'     => 'partner-' . uniqid(),
            'client_secret' => bcrypt('secret'),
            'name'          => 'Đối tác',
            'scopes'        => ['inventory'],
            'active'        => true,
        ]);

        $this->token = $client->createToken('t', ['inventory'])->plainTextToken;
        $this->owner = Owner::factory()->create(['status' => 'active']);

        Cache::flush();
    }

    /** Một màn hình tại một địa điểm thuộc mạng lưới đã cho. */
    private function screenInNetwork(Network $network): Screen
    {
        $site = Site::factory()->create([
            'owner_id'   => $this->owner->id,
            'network_id' => $network->id,
        ]);

        $screen = Screen::factory()->create([
            'owner_id' => $this->owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenInventory::factory()->create(['screen_id' => $screen->id]);

        return $screen;
    }

    private function apiCount(string $code): int
    {
        Cache::forget('inventory:networks');

        $rows = $this->withToken($this->token)
            ->getJson('/api/v1/inventory/networks')
            ->assertOk()
            ->json('data');

        foreach ($rows as $row) {
            if ($row['code'] === $code) {
                return (int) $row['screen_count'];
            }
        }

        return 0;
    }

    /** Đúng phép đếm mà Filament dùng sau giai đoạn 3. */
    private function adminCount(Network $network): int
    {
        return Screen::whereHas('site', fn ($q) => $q->where('network_id', $network->id))->count();
    }

    public function test_trang_quan_tri_va_trang_cong_khai_cho_cung_mot_con_so(): void
    {
        $network = Network::factory()->create(['code' => 'winmart', 'name' => 'Winmart+', 'owner_id' => $this->owner->id]);

        $this->screenInNetwork($network);
        $this->screenInNetwork($network);
        $this->screenInNetwork($network);

        $this->assertSame(3, $this->adminCount($network));
        $this->assertSame(
            $this->adminCount($network),
            $this->apiCount('winmart'),
            'Hai nơi đếm cùng một mạng lưới phải ra cùng một số. Lệch nhau là F-12 quay lại.'
        );
    }

    public function test_man_hinh_doi_dia_diem_thi_ca_hai_noi_cung_doi_theo(): void
    {
        $a = Network::factory()->create(['code' => 'net-a', 'name' => 'Net A', 'owner_id' => $this->owner->id]);
        $b = Network::factory()->create(['code' => 'net-b', 'name' => 'Net B', 'owner_id' => $this->owner->id]);

        $screen = $this->screenInNetwork($a);

        $siteOfB = Site::factory()->create(['owner_id' => $this->owner->id, 'network_id' => $b->id]);
        $screen->update(['site_id' => $siteOfB->id]);

        $this->assertSame(0, $this->adminCount($a));
        $this->assertSame(1, $this->adminCount($b));
        $this->assertSame(0, $this->apiCount('net-a'));
        $this->assertSame(1, $this->apiCount('net-b'));
    }

    public function test_quan_he_tren_model_di_qua_dia_diem(): void
    {
        $network = Network::factory()->create(['code' => 'chuoi-x', 'name' => 'Chuỗi X', 'owner_id' => $this->owner->id]);
        $screen  = $this->screenInNetwork($network);

        $this->assertSame(
            $network->id,
            $screen->fresh()->network?->id,
            'Screen::network() phải trả về mạng lưới của địa điểm, không đọc cột network_code.'
        );
    }

    public function test_cot_network_code_khong_con_quyet_dinh_gi(): void
    {
        $real = Network::factory()->create(['code' => 'that', 'name' => 'Thật', 'owner_id' => $this->owner->id]);
        Network::factory()->create(['code' => 'gia', 'name' => 'Giả', 'owner_id' => $this->owner->id]);

        $screen = $this->screenInNetwork($real);

        // Cột cũ mang giá trị mâu thuẫn: không được phép ảnh hưởng tới kết quả.
        $screen->update(['network_code' => 'gia']);

        $this->assertSame($real->id, $screen->fresh()->network?->id);
        $this->assertSame(1, $this->apiCount('that'));
        $this->assertSame(0, $this->apiCount('gia'));
    }

    public function test_dia_diem_khong_thuoc_mang_luoi_nao_thi_khong_bi_dem(): void
    {
        $network = Network::factory()->create(['code' => 'co-mang', 'name' => 'Có mạng', 'owner_id' => $this->owner->id]);
        $this->screenInNetwork($network);

        $orphanSite = Site::factory()->create(['owner_id' => $this->owner->id, 'network_id' => null]);
        Screen::factory()->create(['owner_id' => $this->owner->id, 'site_id' => $orphanSite->id, 'active' => true]);

        $this->assertSame(1, $this->apiCount('co-mang'));
        $this->assertSame(1, $this->adminCount($network));
    }
}
