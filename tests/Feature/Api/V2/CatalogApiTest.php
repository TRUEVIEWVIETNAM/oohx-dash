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
use Tests\TestCase;

/**
 * Giai đoạn 5 — API v2 cho danh mục công khai.
 *
 * Bốn thứ test này canh, theo đúng CLAUDE.md mục 2:
 *
 *  1. **DTO danh sách trắng.** Không trường nhạy cảm nào ra ngoài. Kiểm bằng
 *     cách quét toàn bộ JSON trả về, không chỉ kiểm vài trường đã biết.
 *  2. **Giới hạn cứng phân trang.** Client đòi 10.000 bản ghi thì bị kẹp.
 *  3. **Định dạng lỗi thống nhất** `{error, message, code, details[]}`.
 *  4. **Không đổi hành vi v1.** Định dạng lỗi mới chỉ áp cho v2.
 */
class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Những trường không bao giờ được xuất hiện trong phản hồi công khai.
     *
     * Lấy theo tên cột thật của `owners` và `screens`, không theo trí nhớ:
     * `bank_account` không phải tên cột nào cả, cột thật là
     * `bank_account_number` và `bank_account_name`. Kiểm sai tên là kiểm một
     * thứ không tồn tại và luôn xanh.
     */
    private const FORBIDDEN_KEYS = [
        'revenue_share_pct', 'billing_info',
        'bank_name', 'bank_account_number', 'bank_account_name', 'bank_branch',
        'tax_code', 'tax_code_issued_on', 'tax_code_issued_by',
        'legal_name', 'legal_representative', 'business_license_path',
        'verified_by_user_id', 'notes',
        'device_token', 'internal_notes', 'price_per_slot_vnd',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function screen(?Owner $owner = null): Screen
    {
        $owner  = $owner ?: Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'city' => 'Hà Nội', 'status' => 'active']);
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

                $walk($value, is_string($key) ? "{$path}.{$key}" : $path . "[]");
            }
        };

        $walk($payload, '$');
    }

    // ── DTO danh sách trắng ─────────────────────────────────────────────────

    public function test_danh_sach_man_hinh_khong_lo_truong_nhay_cam(): void
    {
        $this->screen();

        $response = $this->getJson('/api/v2/screens')->assertOk();

        $this->assertNoForbiddenKeys($response->json(), 'GET /api/v2/screens');
    }

    public function test_chi_tiet_man_hinh_khong_lo_truong_nhay_cam(): void
    {
        $screen = $this->screen();

        $response = $this->getJson('/api/v2/screens/' . $screen->slug)->assertOk();

        $this->assertNoForbiddenKeys($response->json(), 'GET /api/v2/screens/{slug}');
    }

    public function test_danh_sach_owner_khong_lo_truong_nhay_cam(): void
    {
        // Giá trị đủ lạ để nếu nó lọt ra thì không thể nhầm với con số khác
        // trong phản hồi (số màn hình, toạ độ…). Kiểm tên trường thôi là chưa
        // đủ: một ngày nào đó nó có thể ra ngoài dưới cái tên khác.
        $owner = Owner::factory()->create([
            'status'            => 'active',
            'revenue_share_pct' => 63.17,
        ]);
        $this->screen($owner);

        $response = $this->getJson('/api/v2/owners')->assertOk();

        $this->assertNoForbiddenKeys($response->json(), 'GET /api/v2/owners');
        $this->assertStringNotContainsString('63.17', $response->getContent());
    }

    public function test_gia_duoc_dat_ten_dung_nghia(): void
    {
        $screen = $this->screen();

        $this->getJson('/api/v2/screens/' . $screen->slug)
            ->assertOk()
            ->assertJsonPath('data.pricing.io_rate.amount', 1_000_000)
            ->assertJsonPath('data.pricing.io_rate.currency', 'VND')
            ->assertJsonPath('data.pricing.io_rate.unit', 'month')
            ->assertJsonPath('data.pricing.floor_cpm.amount', 50_000)
            ->assertJsonPath('data.pricing.floor_cpm.currency', 'VND')
            // v1 gọi giá CPM là "giá một slot" và không sửa được nữa vì đối tác
            // đang đọc (F09). v2 không lặp lại cái tên đó.
            ->assertJsonMissingPath('data.pricing.price_per_slot_vnd')
            // Và không lặp lại cái nhãn `_vnd` gắn cho số có thể không phải
            // VND (Codex R39).
            ->assertJsonMissingPath('data.pricing.floor_cpm_vnd');
    }

    // ── Giới hạn cứng phân trang ────────────────────────────────────────────

    public function test_phan_trang_co_gioi_han_cung(): void
    {
        foreach (range(1, 3) as $i) {
            $this->screen();
        }

        $response = $this->getJson('/api/v2/screens?per_page=10000')->assertOk();

        $this->assertSame(
            50,
            $response->json('meta.per_page'),
            'Client đòi 10.000 bản ghi thì phải bị kẹp về giới hạn cứng, không trả cả kho.'
        );
        $this->assertSame(50, $response->json('meta.max_per_page'));
    }

    public function test_per_page_qua_nho_van_hop_le(): void
    {
        $this->screen();

        $this->getJson('/api/v2/screens?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);
    }

    // ── Định dạng lỗi thống nhất ────────────────────────────────────────────

    public function test_khong_tim_thay_tra_dinh_dang_loi_thong_nhat(): void
    {
        $this->getJson('/api/v2/screens/khong-ton-tai')
            ->assertStatus(404)
            ->assertJson([
                'error'   => 'not_found',
                'code'    => 404,
                'details' => [],
            ])
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    public function test_loi_validate_tra_dinh_dang_loi_thong_nhat(): void
    {
        // `region` có bộ giá trị cố định; gửi giá trị lạ để kích hoạt validate.
        $response = $this->getJson('/api/v2/screens?region=khong-co-vung-nay');

        $response->assertStatus(422)
            ->assertJsonStructure(['error', 'message', 'code', 'details' => [['field', 'message']]])
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('code', 422);
    }

    // ── Không đổi hành vi v1 ────────────────────────────────────────────────

    public function test_dinh_dang_loi_moi_khong_ap_cho_v1(): void
    {
        // v1 đòi token; gọi không token phải vẫn là hình dạng lỗi CŨ của v1,
        // không có `code` và `details` — đó là hợp đồng với đối tác.
        $response = $this->getJson('/api/v1/inventory/screens')->assertStatus(401);

        $response->assertJsonStructure(['error', 'message']);
        $response->assertJsonMissingPath('code');
        $response->assertJsonMissingPath('details');
    }

    // ── Codex R38: network.code phải có ở MỌI endpoint ──────────────────────

    public function test_network_code_co_mat_o_danh_sach_va_chi_tiet(): void
    {
        $owner   = Owner::factory()->create(['status' => 'active']);
        $network = Network::factory()->create(['owner_id' => $owner->id, 'code' => 'net-a', 'name' => 'Mạng A', 'status' => 'active']);

        $site   = Site::factory()->create(['owner_id' => $owner->id, 'network_id' => $network->id, 'city' => 'Hà Nội', 'status' => 'active']);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);
        ScreenSpec::factory()->create(['screen_id' => $screen->id, 'width_cm' => 400, 'height_cm' => 200]);
        ScreenInventory::create([
            'screen_id' => $screen->id, 'pricing_model' => 'io', 'io_rate' => 1_000_000,
            'io_rate_unit' => 'month', 'floor_cpm' => 50_000, 'spot_length' => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        // Danh sách: eager load từng thiếu cột `code`, nên `code` về null
        // trong khi `name` có — cùng một màn hình trả dữ liệu khác nhau tùy
        // endpoint, và client mất khóa để nối với facet network.
        $this->getJson('/api/v2/screens')
            ->assertOk()
            ->assertJsonPath('data.0.network.code', 'net-a')
            ->assertJsonPath('data.0.network.name', 'Mạng A');

        $this->getJson('/api/v2/screens/' . $screen->slug)
            ->assertOk()
            ->assertJsonPath('data.network.code', 'net-a');

        $this->getJson('/api/v2/owners/' . $owner->slug)
            ->assertOk()
            ->assertJsonPath('screens.data.0.network.code', 'net-a');
    }

    // ── Codex R37: cách gửi tham số nhiều giá trị ───────────────────────────

    public function test_bo_loc_nhan_mot_gia_tri_dang_scalar(): void
    {
        $this->screen();

        // Đặc tả từng khai `explode: true`, tức client sinh `city=hanoi`. PHP
        // đọc đó là scalar, và luật `array` trả 422 — bộ lọc khai trong tài
        // liệu không dùng được. Phép kiểm route hai chiều vẫn xanh vì nó không
        // kiểm cách serialize.
        $this->getJson('/api/v2/screens?city=' . urlencode('Hà Nội'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_bo_loc_nhan_nhieu_gia_tri_phan_tach_bang_dau_phay(): void
    {
        $ownerA = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-a']);
        $ownerB = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-b']);
        $ownerC = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-c']);

        $this->screen($ownerA);
        $this->screen($ownerB);
        $this->screen($ownerC);

        // Dạng OpenAPI chuẩn `explode: false`: một khóa, không mất phần tử.
        $slugs = collect(
            $this->getJson('/api/v2/screens?owner=owner-a,owner-b')->assertOk()->json('data')
        )->pluck('owner.slug')->sort()->values()->all();

        $this->assertSame(['owner-a', 'owner-b'], $slugs);
    }

    public function test_bo_loc_van_nhan_dang_mang_cu(): void
    {
        $ownerA = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-a']);
        $this->screen($ownerA);
        $this->screen();

        // Trang Blade đang gửi dạng này; đổi hợp đồng không được làm nó vỡ.
        $this->getJson('/api/v2/screens?owner[]=owner-a')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.owner.slug', 'owner-a');
    }

    public function test_ban_do_cung_nhan_dang_dau_phay(): void
    {
        $ownerA = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-a']);
        $ownerB = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-b']);

        foreach ([$ownerA, $ownerB] as $owner) {
            $site   = Site::factory()->create(['owner_id' => $owner->id, 'city' => 'Hà Nội', 'status' => 'active', 'lat' => 21.02, 'lon' => 105.80]);
            $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);
            ScreenSpec::factory()->create(['screen_id' => $screen->id, 'width_cm' => 400, 'height_cm' => 200]);
            ScreenInventory::create([
                'screen_id' => $screen->id, 'pricing_model' => 'io', 'io_rate' => 1_000_000,
                'io_rate_unit' => 'month', 'floor_cpm' => 50_000, 'spot_length' => 15,
                'share_of_voice_max_pct' => 100,
            ]);
        }

        $this->getJson('/api/v2/screens/map?north=21.2&south=20.9&east=105.9&west=105.6&owner=owner-a')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.owner.slug', 'owner-a');
    }

    // ── Codex R39: đơn vị tiền đi cùng số tiền ──────────────────────────────

    public function test_gia_cpm_bang_usd_khong_bi_gan_nhan_vnd(): void
    {
        $screen = $this->screen();

        ScreenInventory::where('screen_id', $screen->id)->update([
            'pricing_model'      => 'cpm',
            'io_rate'            => null,
            'floor_cpm'          => 2.50,
            'floor_cpm_currency' => 'USD',
        ]);
        Cache::flush();

        // 2,50 USD từng ra ngoài thành `floor_cpm_vnd: 3`: sai đơn vị, lệch
        // nhiều bậc, và client mất currency gốc nên không tự sửa được.
        $this->getJson('/api/v2/screens/' . $screen->slug)
            ->assertOk()
            ->assertJsonPath('data.pricing.floor_cpm.amount', 2.5)
            ->assertJsonPath('data.pricing.floor_cpm.currency', 'USD');

        $this->getJson('/api/v2/screens')
            ->assertOk()
            ->assertJsonPath('data.0.pricing.floor_cpm.currency', 'USD');
    }

    public function test_pin_ban_do_mang_dung_don_vi_tien(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'city' => 'Hà Nội', 'status' => 'active', 'lat' => 21.02, 'lon' => 105.80]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);
        ScreenSpec::factory()->create(['screen_id' => $screen->id, 'width_cm' => 400, 'height_cm' => 200]);
        ScreenInventory::create([
            'screen_id' => $screen->id, 'pricing_model' => 'cpm', 'floor_cpm' => 2.50,
            'floor_cpm_currency' => 'USD', 'spot_length' => 15, 'share_of_voice_max_pct' => 100,
        ]);

        $this->getJson('/api/v2/screens/map?north=21.2&south=20.9&east=105.9&west=105.6')
            ->assertOk()
            ->assertJsonPath('data.0.price.amount', 2.5)
            ->assertJsonPath('data.0.price.currency', 'USD')
            ->assertJsonMissingPath('data.0.price.amount_vnd');
    }

    // ── Codex R41: đổi body không được phá header giao thức ─────────────────

    public function test_loi_405_van_giu_header_allow(): void
    {
        // `MethodNotAllowedHttpException` mang `Allow`. Renderer tạo
        // JsonResponse mới chỉ với body và status đã làm nó biến mất.
        $response = $this->postJson('/api/v2/stats');

        $response->assertStatus(405)
            ->assertJsonPath('error', 'error')
            ->assertJsonStructure(['error', 'message', 'code', 'details']);

        $this->assertNotEmpty(
            $response->headers->get('Allow'),
            'Lỗi 405 của v2 mất header Allow.'
        );
    }

    // ── Suất còn lại là con số thật ─────────────────────────────────────────

    public function test_chi_tiet_tra_suat_con_lai_that(): void
    {
        $screen = $this->screen();

        $this->getJson('/api/v2/screens/' . $screen->slug)
            ->assertOk()
            ->assertJsonPath('data.availability.remaining_sov_pct', 100)
            ->assertJsonPath('data.availability.has_capacity', true)
            ->assertJsonPath('data.availability.window_days', 30);
    }

    public function test_man_hinh_khong_cong_khai_thi_khong_tra_ve(): void
    {
        $screen = $this->screen();
        $screen->update(['active' => false]);

        $this->getJson('/api/v2/screens/' . $screen->slug)->assertStatus(404);
    }
}
