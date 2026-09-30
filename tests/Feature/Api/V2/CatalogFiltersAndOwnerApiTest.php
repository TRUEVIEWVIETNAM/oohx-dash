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
 * Giai đoạn 5, mốc 2 — `/api/v2/filters` và `/api/v2/owners/{slug}`.
 *
 * Chi tiết owner là endpoint đáng lo nhất trong cả mốc: bảng `owners` mang
 * `revenue_share_pct`, `billing_info`, `bank_*`, `tax_code`,
 * `business_license_path`, `legal_*`, và `getOwnerBySlug()` nạp **cả model**
 * vào cache. Thứ duy nhất chặn chúng ra ngoài là danh sách trắng trong DTO,
 * nên ở đây kiểm cả tên trường lẫn **giá trị**: một trường có thể lọt ra dưới
 * một cái tên khác.
 */
class CatalogFiltersAndOwnerApiTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_KEYS = [
        'revenue_share_pct', 'billing_info',
        'bank_name', 'bank_account_number', 'bank_account_name', 'bank_branch',
        'tax_code', 'tax_code_issued_on', 'tax_code_issued_by',
        'legal_name', 'legal_representative', 'business_license_path',
        'verified_by_user_id', 'notes', 'device_token', 'internal_notes',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function screen(?Owner $owner = null): Screen
    {
        $owner  = $owner ?: Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => 'Hà Nội',
            'status'   => 'active',
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

    /** Quét đệ quy mọi khóa trong JSON — không chỉ tầng ngoài. */
    private function assertNoForbiddenKeys(array $payload, string $endpoint): void
    {
        $walk = function ($node, string $path) use (&$walk, $endpoint) {
            if (! is_array($node)) {
                return;
            }

            foreach ($node as $key => $value) {
                if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                    $this->fail("{$endpoint} trả về trường nhạy cảm \"{$key}\" tại {$path}.{$key}");
                }

                $walk($value, is_string($key) ? "{$path}.{$key}" : $path . '[]');
            }
        };

        $walk($payload, '$');
    }

    // ── /api/v2/filters ─────────────────────────────────────────────────────

    public function test_bo_loc_tra_dung_hinh_dang(): void
    {
        $this->screen();

        $this->getJson('/api/v2/filters')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'cities', 'venue_types', 'networks', 'owners',
                    'price_range' => ['currency', 'min', 'max'],
                ],
            ]);
    }

    public function test_bo_loc_khong_lo_truong_nhay_cam(): void
    {
        $owner = Owner::factory()->create(['status' => 'active', 'revenue_share_pct' => 63.17]);
        $this->screen($owner);

        $response = $this->getJson('/api/v2/filters')->assertOk();

        $this->assertNoForbiddenKeys($response->json(), 'GET /api/v2/filters');
        $this->assertStringNotContainsString('63.17', $response->getContent());
    }

    public function test_bo_loc_khong_lo_id_noi_bo(): void
    {
        $this->screen();

        // Bộ lọc đi ra ngoài thì client lọc bằng `code`/`slug`, không bằng ID
        // nội bộ. Trả ID là mời người ngoài đoán không gian ID của hệ thống.
        $response = $this->getJson('/api/v2/filters')->assertOk();

        foreach (['cities', 'venue_types', 'networks', 'owners'] as $facet) {
            foreach ($response->json("data.{$facet}") ?? [] as $row) {
                $this->assertArrayNotHasKey('id', $row, "data.{$facet} đang trả ID nội bộ.");
            }
        }
    }

    public function test_khoang_gia_la_so_nguyen_vnd(): void
    {
        $this->screen();

        $response = $this->getJson('/api/v2/filters')->assertOk();

        $this->assertIsInt($response->json('data.price_range.min'));
        $this->assertIsInt($response->json('data.price_range.max'));
        $this->assertSame('VND', $response->json('data.price_range.currency'));
    }

    public function test_khoang_gia_khong_bi_hang_usd_keo_lech(): void
    {
        $this->screen();   // floor_cpm 50.000 VND

        // Một màn hình niêm yết CPM bằng USD. Trước đây MIN/MAX chạy trên toàn
        // bộ `floor_cpm` bất kể đơn vị, nên 2,50 USD kéo `min` xuống 2 và
        // thanh lọc giá thành vô nghĩa (Codex R39).
        $usdScreen = $this->screen();
        ScreenInventory::where('screen_id', $usdScreen->id)->update([
            'floor_cpm'          => 2.50,
            'floor_cpm_currency' => 'USD',
        ]);
        Cache::flush();

        $response = $this->getJson('/api/v2/filters')->assertOk();

        $this->assertSame(
            50_000,
            $response->json('data.price_range.min'),
            'Hàng USD đang bị trộn vào khoảng giá VND.'
        );
    }

    // ── /api/v2/owners/{slug} ───────────────────────────────────────────────

    public function test_chi_tiet_owner_khong_lo_truong_nhay_cam(): void
    {
        $owner = Owner::factory()->create([
            'status'                => 'active',
            'slug'                  => 'owner-can-kiem',
            'revenue_share_pct'     => 63.17,
            'tax_code'              => 'MST-LO-4821',
            'bank_account_number'   => 'STK-LO-7393',
            'business_license_path' => 'private/gpkd/khong-duoc-lo.pdf',
        ]);
        $this->screen($owner);

        $response = $this->getJson('/api/v2/owners/owner-can-kiem')->assertOk();

        $this->assertNoForbiddenKeys($response->json(), 'GET /api/v2/owners/{slug}');

        // Kiểm cả giá trị, với chuỗi đủ lạ để không trùng ngẫu nhiên: một
        // trường có thể lọt ra ngoài dưới một cái tên khác.
        $body = $response->getContent();
        foreach (['63.17', 'MST-LO-4821', 'STK-LO-7393', 'private/gpkd'] as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $body,
                "Giá trị nhạy cảm \"{$secret}\" lọt ra ngoài dù tên trường đã bị loại."
            );
        }
    }

    public function test_chi_tiet_owner_tra_so_lieu_that(): void
    {
        $owner = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-co-hai-man']);
        $this->screen($owner);
        $this->screen($owner);

        $this->getJson('/api/v2/owners/owner-co-hai-man')
            ->assertOk()
            ->assertJsonPath('data.slug', 'owner-co-hai-man')
            ->assertJsonPath('data.stats.screen_count', 2)
            ->assertJsonPath('screens.meta.total', 2);
    }

    public function test_man_hinh_cua_owner_co_gioi_han_cung(): void
    {
        $owner = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-gioi-han']);
        $this->screen($owner);

        $this->getJson('/api/v2/owners/owner-gioi-han?per_page=10000')
            ->assertOk()
            ->assertJsonPath('screens.meta.per_page', 50)
            ->assertJsonPath('screens.meta.max_per_page', 50);
    }

    public function test_owner_khong_ton_tai_tra_dinh_dang_loi_thong_nhat(): void
    {
        $this->getJson('/api/v2/owners/khong-co-owner-nay')
            ->assertStatus(404)
            ->assertJson([
                'error'   => 'not_found',
                'code'    => 404,
                'details' => [],
            ])
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    public function test_owner_chua_duyet_thi_khong_tra_ve(): void
    {
        $owner = Owner::factory()->create(['status' => 'pending', 'slug' => 'owner-chua-duyet']);
        $this->screen($owner);

        $this->getJson('/api/v2/owners/owner-chua-duyet')->assertStatus(404);
    }

    public function test_man_hinh_cua_owner_dung_chung_hinh_dang_voi_danh_sach(): void
    {
        $owner = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-hinh-dang']);
        $this->screen($owner);

        // Cùng một kiểu dữ liệu cho màn hình ở mọi endpoint, nếu không bên
        // tiêu thụ phải viết hai kiểu gần giống nhau và tự đoán chỗ khác.
        $this->getJson('/api/v2/owners/owner-hinh-dang')
            ->assertOk()
            ->assertJsonStructure([
                'screens' => ['data' => [['slug', 'name', 'screen_type', 'owner', 'location', 'size', 'pricing']]],
            ])
            ->assertJsonPath('screens.data.0.pricing.io_rate.amount', 1_000_000);
    }

    // ── Codex R40: endpoint owners phải có bộ luật kiểm ─────────────────────

    public function test_owners_tu_choi_input_sai_kieu_thay_vi_tra_500(): void
    {
        $this->screen();

        // `?q[]=x` từng làm phép nối chuỗi trong service báo "Array to string
        // conversion" → ErrorException → 500 trên một endpoint công khai.
        $this->getJson('/api/v2/owners?q[]=x')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('code', 422)
            ->assertJsonStructure(['error', 'message', 'code', 'details' => [['field', 'message']]]);
    }

    public function test_owners_tu_choi_type_sai_kieu(): void
    {
        $this->screen();

        $this->getJson('/api/v2/owners?type[]=x')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_owners_van_nhan_q_dang_chuoi(): void
    {
        $owner = Owner::factory()->create(['status' => 'active', 'name' => 'Kim Ngân ADV', 'slug' => 'kim-ngan-adv']);
        $this->screen($owner);
        $this->screen();

        $response = $this->getJson('/api/v2/owners?q=Kim')->assertOk();

        $this->assertSame(
            ['kim-ngan-adv'],
            collect($response->json('data'))->pluck('slug')->all()
        );
    }
}
