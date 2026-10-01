<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\Product;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Giai đoạn 5, mốc 3 — `/api/v2/products` và `/api/v2/products/{slug}`.
 *
 * Bốn thứ test này canh:
 *
 *  1. **Không lộ khóa nội bộ.** Trang Blade dùng `$product->id` cho form giỏ
 *     hàng; API dùng `slug`. Một endpoint công khai trả id là mời người ngoài
 *     đoán không gian id của hệ thống.
 *  2. **Đơn vị tiền đi cùng số tiền.** `products.currency` là cột thật, nên
 *     không được giả định VND — đúng bài học R39.
 *  3. **Có bộ luật kiểm.** `?q[]=x` từng cho 500 ở cả API lẫn trang Blade.
 *  4. **`package_options` whitelist theo khóa**, không trả nguyên blob JSON.
 */
class CatalogProductApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function owner(string $slug = 'owner-a'): Owner
    {
        return Owner::factory()->create([
            'status'            => 'active',
            'slug'              => $slug,
            'revenue_share_pct' => 63.17,
        ]);
    }

    private function product(?Owner $owner = null, array $attributes = []): Product
    {
        $owner = $owner ?: $this->owner();

        return Product::create(array_merge([
            'owner_id'         => $owner->id,
            'name'             => 'Gói LED Hà Nội',
            'slug'             => 'goi-led-ha-noi-' . uniqid(),
            'type'             => Product::TYPE_PACKAGE,
            'category'         => 'led',
            'listing_mode'     => Product::LISTING_BOTH,
            'min_quantity'     => 3,
            'max_quantity'     => 10,
            'total_units'      => 5,
            'floor_price'      => 30_000_000,
            'individual_price' => 12_000_000,
            'currency'         => 'VND',
            'price_unit'       => 'month',
            'city'             => 'Hà Nội',
            'status'           => 'active',
        ], $attributes));
    }

    private function screenFor(Product $product): Screen
    {
        $site   = Site::factory()->create(['owner_id' => $product->owner_id, 'city' => 'Hà Nội', 'status' => 'active']);
        $screen = Screen::factory()->create([
            'owner_id'     => $product->owner_id,
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

        $product->screens()->attach($screen->id, ['is_primary' => true, 'sort_order' => 1]);

        return $screen->fresh(['inventory', 'spec', 'site']);
    }

    // ── Không lộ khóa nội bộ, không lộ trường nhạy cảm ──────────────────────

    public function test_danh_sach_khong_tra_khoa_noi_bo(): void
    {
        $this->product();

        $response = $this->getJson('/api/v2/products')->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertArrayNotHasKey('id', $row, 'Danh sách sản phẩm đang trả id nội bộ.');
            $this->assertArrayNotHasKey('owner_id', $row);
            $this->assertArrayNotHasKey('network_id', $row);
            $this->assertArrayNotHasKey('site_id', $row);
        }
    }

    public function test_khong_lo_truong_nhay_cam_cua_owner(): void
    {
        $owner = $this->owner();
        $this->product($owner);

        $body = $this->getJson('/api/v2/products')->assertOk()->getContent();

        $this->assertStringNotContainsString('revenue_share_pct', $body);
        $this->assertStringNotContainsString('63.17', $body);
    }

    public function test_chi_tiet_khong_lo_device_token_cua_man_hinh(): void
    {
        $product = $this->product();
        $this->screenFor($product);

        $body = $this->getJson('/api/v2/products/' . $product->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('device_token', $body);
        $this->assertStringNotContainsString('bi-mat-cua-thiet-bi', $body);
    }

    // ── Giá: tên đúng nghĩa, kèm đơn vị tiền ────────────────────────────────

    public function test_gia_goi_va_gia_le_dat_ten_dung_nghia(): void
    {
        $product = $this->product();

        // Cột trong CSDL tên `floor_price` nhưng với sản phẩm gói đó là giá cả
        // gói. API không chép lại cái tên gây hiểu sai.
        $this->getJson('/api/v2/products/' . $product->slug)
            ->assertOk()
            ->assertJsonPath('data.pricing.package', 30_000_000)
            ->assertJsonPath('data.pricing.per_screen', 12_000_000)
            ->assertJsonPath('data.pricing.currency', 'VND')
            ->assertJsonPath('data.pricing.unit', 'month')
            ->assertJsonMissingPath('data.pricing.floor_price')
            ->assertJsonMissingPath('data.floor_price');
    }

    public function test_don_vi_tien_lay_tu_cot_chu_khong_gia_dinh_vnd(): void
    {
        $product = $this->product(null, [
            'currency'         => 'USD',
            'floor_price'      => 1_250.50,
            'individual_price' => 500.25,
        ]);

        $this->getJson('/api/v2/products/' . $product->slug)
            ->assertOk()
            ->assertJsonPath('data.pricing.currency', 'USD')
            ->assertJsonPath('data.pricing.package', 1250.5)
            ->assertJsonPath('data.pricing.per_screen', 500.25);
    }

    // ── package_options: whitelist theo khóa ────────────────────────────────

    public function test_package_options_chi_tra_khoa_da_liet_ke(): void
    {
        $product = $this->product(null, [
            'package_options' => [
                [
                    'name'     => 'Gói 3 vị trí',
                    'quantity' => 3,
                    'price'    => 30_000_000,
                    // Một trường do ai đó thêm vào repeater ở Filament sau này.
                    // Trả nguyên blob JSON là để nó tự động ra ngoài.
                    'internal_margin_pct' => 42,
                ],
            ],
        ]);

        $response = $this->getJson('/api/v2/products/' . $product->slug)->assertOk();

        // So khớp TOÀN BỘ mảng, không chỉ vài khóa: đó mới là phép kiểm
        // whitelist. Kiểm "không có khóa X" chỉ chặn được khóa mình nghĩ ra.
        $this->assertSame(
            ['name' => 'Gói 3 vị trí', 'quantity' => 3, 'price' => 30_000_000],
            $response->json('data.package_options.0'),
        );
        $this->assertStringNotContainsString('internal_margin_pct', $response->getContent());
    }

    // ── Màn hình dùng chung một kiểu dữ liệu ────────────────────────────────

    public function test_man_hinh_trong_san_pham_dung_chung_hinh_dang(): void
    {
        $product = $this->product();
        $this->screenFor($product);

        $this->getJson('/api/v2/products/' . $product->slug)
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['screens' => [['slug', 'name', 'screen_type', 'owner', 'location', 'size', 'pricing']]],
            ])
            ->assertJsonPath('data.screens.0.pricing.io_rate.amount', 1_000_000);
    }

    public function test_man_hinh_trong_san_pham_co_network_code(): void
    {
        $product = $this->product();
        $screen  = $this->screenFor($product);

        $network = \App\Models\Network::factory()->create([
            'owner_id' => $product->owner_id,
            'code'     => 'net-a',
            'name'     => 'Mạng A',
            'status'   => 'active',
        ]);
        Site::withoutGlobalScopes()->whereKey($screen->site_id)->update(['network_id' => $network->id]);
        Cache::flush();

        // Cùng lớp lỗi R38: eager load của `getProductBySlug()` không select
        // `network_id`, nên quan hệ site→network luôn null và `network` biến
        // mất khỏi DTO màn hình — chỉ ở endpoint này.
        $this->getJson('/api/v2/products/' . $product->slug)
            ->assertOk()
            ->assertJsonPath('data.screens.0.network.code', 'net-a');
    }

    // ── Bộ lọc và phân trang ────────────────────────────────────────────────

    public function test_phan_trang_co_gioi_han_cung(): void
    {
        $this->product();

        $this->getJson('/api/v2/products?per_page=10000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.max_per_page', 50);
    }

    public function test_loc_theo_owner_dung_slug_khong_dung_id(): void
    {
        $ownerA = $this->owner('owner-a');
        $ownerB = $this->owner('owner-b');

        $a = $this->product($ownerA);
        $this->product($ownerB);

        $response = $this->getJson('/api/v2/products?owner=owner-a')->assertOk();

        $this->assertSame([$a->slug], collect($response->json('data'))->pluck('slug')->all());
    }

    public function test_owner_slug_khong_ton_tai_thi_tra_rong_chu_khong_tra_ca_kho(): void
    {
        $this->product();

        // Bỏ qua một bộ lọc không nhận ra là cách âm thầm trả nhiều hơn người
        // gọi yêu cầu.
        $this->getJson('/api/v2/products?owner=khong-co-owner-nay')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_loc_nhieu_danh_muc_bang_dau_phay(): void
    {
        $led = $this->product(null, ['category' => 'led']);
        $lcd = $this->product(null, ['category' => 'lcd']);
        $bb  = $this->product(null, ['category' => 'billboard']);

        $slugs = collect(
            $this->getJson('/api/v2/products?category=led,lcd')->assertOk()->json('data')
        )->pluck('slug')->all();

        $this->assertCount(2, $slugs);
        $this->assertContains($led->fresh()->slug, $slugs);
        $this->assertContains($lcd->fresh()->slug, $slugs);
        $this->assertNotContains($bb->fresh()->slug, $slugs);
    }

    // ── Bộ luật kiểm (cùng lớp lỗi R40) ─────────────────────────────────────

    public function test_tu_choi_q_dang_mang_thay_vi_tra_500(): void
    {
        $this->product();

        $this->getJson('/api/v2/products?q[]=x')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['error', 'message', 'code', 'details' => [['field', 'message']]]);
    }

    public function test_tu_choi_danh_muc_la(): void
    {
        $this->product();

        $this->getJson('/api/v2/products?category=khong-co-danh-muc-nay')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_san_pham_khong_cong_khai_thi_khong_tra_ve(): void
    {
        $product = $this->product(null, ['status' => 'draft']);

        $this->getJson('/api/v2/products/' . $product->slug)->assertStatus(404);
        $this->getJson('/api/v2/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_khong_tim_thay_tra_dinh_dang_loi_thong_nhat(): void
    {
        $this->getJson('/api/v2/products/khong-ton-tai')
            ->assertStatus(404)
            ->assertJson(['error' => 'not_found', 'code' => 404, 'details' => []]);
    }

    // ── Trang Blade cũng phải thôi trả 500 ──────────────────────────────────

    public function test_trang_products_blade_tu_choi_q_dang_mang(): void
    {
        $this->product();

        // Cùng lớp lỗi R40, chỉ khác chỗ. Sửa một nửa rồi để nửa kia nguyên là
        // biết lỗi mà bỏ đó.
        $this->get('http://' . config('domains.frontpage', 'oohx.net') . '/products?q[]=x')
            ->assertStatus(302);
    }

    public function test_trang_products_blade_van_chay_binh_thuong(): void
    {
        $this->product();

        $this->get('http://' . config('domains.frontpage', 'oohx.net') . '/products')
            ->assertOk();
    }
}
