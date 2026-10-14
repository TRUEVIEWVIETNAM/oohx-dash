<?php

namespace Tests\Feature\Api\V2;

use App\Models\Network;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
                    'cities', 'venue_types', 'networks', 'owners', 'categories_networks',
                    'price_range' => ['currency', 'min', 'max'],
                ],
            ]);
    }

    /**
     * `categories_networks` — loại hình có network lồng bên trong (14/10/2026).
     *
     * Vì sao nó phải có ở API thay vì ghép ở trình duyệt: `networks` phẳng
     * **không mang khóa loại hình nào**, nên quan hệ cha–con không suy ra được
     * từ phản hồi cũ. Ô tìm kiếm lớn của bản Blade vẽ đúng cây này.
     *
     * Kiểm luôn cả việc `thumb` **không** lọt ra: nhóm loại hình trong service
     * mang thêm một đường ảnh, và nó không thuộc về một endpoint bộ lọc.
     */
    public function test_bo_loc_tra_cay_loai_hinh_co_network(): void
    {
        // Fixture phải có nhóm điểm đặt VÀ network, nếu không cây rỗng một cách
        // hợp lệ: `getVenueTypesWithCounts()` join `screen_inventory` với
        // `venue_categories`, nên một màn hình không gán `vn_category_id` thì
        // không thuộc nhóm nào. Helper `screen()` không gán — đó là lý do ca
        // này dựng fixture riêng chứ không dùng nó.
        $nhomId = 7;
        DB::table('venue_categories')->insert([
            'id'         => $nhomId,
            'name'       => 'Mall',
            'slug'       => 'mall',
            'name_vi'    => 'Trung tâm thương mại',
            'icon'       => 'storefront',
            'sort_order' => 1,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $owner   = Owner::factory()->create(['status' => 'active']);
        $network = Network::factory()->create([
            'owner_id'       => $owner->id,
            'status'         => 'active',
            'vn_category_id' => $nhomId,
        ]);
        $site = Site::factory()->create([
            'owner_id'   => $owner->id,
            'network_id' => $network->id,
            'status'     => 'active',
        ]);
        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'vn_category_id'         => $nhomId,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        $cay = $this->getJson('/api/v2/filters')->assertOk()->json('data.categories_networks');

        $this->assertNotEmpty($cay, 'Có màn hình trong một nhóm điểm đặt thì cây không được rỗng.');
        $this->assertSame('mall', $cay[0]['code']);
        $this->assertSame('Trung tâm thương mại', $cay[0]['name']);
        $this->assertNotEmpty(
            $cay[0]['networks'],
            'Network của nhóm phải lồng bên trong — đó là cả lý do trường này tồn tại.'
        );
        $this->assertSame($network->code, $cay[0]['networks'][0]['code']);

        foreach ($cay as $nhom) {
            $this->assertSame(
                ['code', 'name', 'icon', 'count', 'networks'],
                array_keys($nhom),
                'Danh sách trắng theo khóa — thêm khóa lạ nghĩa là trả nguyên phần tử của service.'
            );
            $this->assertIsArray($nhom['networks']);
            $this->assertArrayNotHasKey('thumb', $nhom);

            foreach ($nhom['networks'] as $net) {
                $this->assertSame(['code', 'name', 'count'], array_keys($net));
            }
        }
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

    // ── Ảnh và số liệu của thẻ owner (14/10/2026) ───────────────────────────

    /**
     * `logo_url` và `cover_url` phải là URL dùng được, không phải đường dẫn
     * trong disk.
     *
     * Lỗi thật: cột lưu `owners/cover/abc.jpg`, DTO trả thẳng giá trị đó, và
     * `<img src="owners/cover/abc.jpg">` trên `/owners` được trình duyệt giải
     * **tương đối với trang đang mở** — thành `/owners/owners/cover/abc.jpg`.
     * Mọi ảnh bìa trên trang danh sách là ảnh hỏng, và không test nào đỏ.
     *
     * Kiểm bằng tiền tố `http`, không bằng chuỗi URL đầy đủ: `asset()` dựng
     * theo `APP_URL`, nên so cả chuỗi là ghim test vào cấu hình môi trường.
     */
    public function test_anh_owner_tra_url_dung_duoc_khong_phai_duong_dan_trong_disk(): void
    {
        Owner::factory()->create([
            'status'    => 'active',
            'slug'      => 'owner-co-anh',
            'logo_url'  => 'owners/logo/kim-ngan.png',
            'cover_url' => 'owners/cover/kim-ngan.jpg',
        ]);
        $this->screen(Owner::where('slug', 'owner-co-anh')->first());

        foreach ([
            'danh sách' => ['/api/v2/owners', 'data.0'],
            'chi tiết'  => ['/api/v2/owners/owner-co-anh', 'data'],
        ] as $ten => [$url, $goc]) {
            $body = $this->getJson($url)->assertOk();

            foreach (['logo_url' => 'owners/logo/kim-ngan.png', 'cover_url' => 'owners/cover/kim-ngan.jpg'] as $truong => $duongDan) {
                $gia_tri = $body->json("{$goc}.{$truong}");

                $this->assertIsString($gia_tri, "{$ten}: {$truong} phải có giá trị");
                $this->assertStringStartsWith(
                    'http',
                    $gia_tri,
                    "{$ten}: {$truong} trả \"{$gia_tri}\" — đó là đường dẫn trong disk, "
                    . 'trình duyệt sẽ giải nó tương đối với trang đang mở và ảnh là ảnh hỏng.'
                );
                $this->assertStringEndsWith('/storage/' . $duongDan, $gia_tri);
            }
        }
    }

    /** Không có ảnh thì trả `null`, không trả một URL trỏ vào `/storage/`. */
    public function test_owner_khong_co_anh_thi_tra_null_chu_khong_tra_url_rong(): void
    {
        Owner::factory()->create([
            'status'    => 'active',
            'slug'      => 'owner-khong-anh',
            'logo_url'  => null,
            'cover_url' => null,
        ]);
        $this->screen(Owner::where('slug', 'owner-khong-anh')->first());

        $this->getJson('/api/v2/owners')
            ->assertOk()
            ->assertJsonPath('data.0.logo_url', null)
            ->assertJsonPath('data.0.cover_url', null);
    }

    /**
     * Thẻ owner của bản Blade in số tỉnh thành và nhãn loại hình.
     * `loadOwnerExtras()` đã tính cả hai cho cùng truy vấn — DTO chỉ không
     * mang ra, nên bản Next vẽ một thẻ nghèo hơn bản nó thay.
     *
     * `city_count` null khác 0: null là **không đếm**, 0 là đếm rồi và không
     * có tỉnh nào. Ở đường này service luôn tính, nên phải là số.
     */
    public function test_danh_sach_owner_tra_so_tinh_thanh_va_nhan_loai_hinh(): void
    {
        $owner = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-hai-tinh']);

        foreach (['Hà Nội', 'Đà Nẵng'] as $thanhPho) {
            $site = Site::factory()->create([
                'owner_id' => $owner->id,
                'city'     => $thanhPho,
                'status'   => 'active',
            ]);
            Screen::factory()->create([
                'owner_id' => $owner->id,
                'site_id'  => $site->id,
                'active'   => true,
            ]);
        }

        $hang = collect($this->getJson('/api/v2/owners')->assertOk()->json('data'))
            ->firstWhere('slug', 'owner-hai-tinh');

        $this->assertSame(2, $hang['city_count'], 'Hai địa điểm ở hai tỉnh thì city_count phải là 2');
        $this->assertIsArray($hang['venue_types']);
        $this->assertArrayHasKey('verified', $hang);
        $this->assertArrayHasKey('featured', $hang);
    }

    /** Cờ `verified`/`featured` đọc đúng cột, và là boolean chứ không phải 0/1. */
    public function test_co_verified_va_featured_la_boolean_doc_dung_cot(): void
    {
        $owner = Owner::factory()->create([
            'status'   => 'active',
            'slug'     => 'owner-da-xac-minh',
            'verified' => true,
            'featured' => false,
        ]);
        $this->screen($owner);

        $hang = collect($this->getJson('/api/v2/owners')->assertOk()->json('data'))
            ->firstWhere('slug', 'owner-da-xac-minh');

        $this->assertTrue($hang['verified']);
        $this->assertFalse($hang['featured']);
    }
}
