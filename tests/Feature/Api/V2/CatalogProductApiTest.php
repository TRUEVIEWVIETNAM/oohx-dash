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


    // ── Gói tương tự trên trang chi tiết (lát 7) ────────────────────────────

    public function test_chi_tiet_goi_tra_goi_tuong_tu(): void
    {
        $owner = $this->owner();
        $goi   = $this->product($owner, ['category' => 'led', 'city' => 'Hà Nội']);

        // Cùng danh mục → tương tự.
        $this->product($owner, ['category' => 'led', 'city' => 'Đà Nẵng']);
        // Cùng thành phố → cũng tương tự.
        $this->product($owner, ['category' => 'billboard', 'city' => 'Hà Nội']);

        $body = $this->getJson('/api/v2/products/' . $goi->slug)->assertOk()->json();

        $this->assertCount(2, $body['similar']);

        // Không tự trỏ về chính nó — một mục "tương tự" dẫn về trang đang mở
        // là một liên kết chết đối với người đọc.
        foreach ($body['similar'] as $item) {
            $this->assertNotSame($goi->slug, $item['slug']);
        }
    }

    public function test_goi_tuong_tu_dung_dto_cua_danh_sach(): void
    {
        $owner = $this->owner();
        $goi   = $this->product($owner, ['category' => 'led']);
        $this->product($owner, ['category' => 'led']);

        $item = $this->getJson('/api/v2/products/' . $goi->slug)->assertOk()->json('similar.0');

        // `ProductSummary`, không phải `ProductDetail`: khối này là lưới thẻ,
        // và trả cả `specs`/`screens` cho bốn gói phụ là nhân payload lên cho
        // dữ liệu không ai in.
        $this->assertArrayHasKey('pricing', $item);
        $this->assertArrayHasKey('listing_mode', $item);
        $this->assertArrayNotHasKey('screens', $item);
        $this->assertArrayNotHasKey('specs', $item);
        $this->assertArrayNotHasKey('package_options', $item);
    }

    public function test_goi_tuong_tu_chi_lay_goi_cong_khai(): void
    {
        $owner = $this->owner();
        $goi   = $this->product($owner, ['category' => 'led']);
        $this->product($owner, ['category' => 'led', 'status' => 'draft']);

        $body = $this->getJson('/api/v2/products/' . $goi->slug)->assertOk()->json();

        // Một bản nháp lọt vào khối "tương tự" là công bố một gói chưa ai
        // duyệt — và nó dẫn tới một trang 404.
        $this->assertSame([], $body['similar']);
    }

    public function test_goi_tuong_tu_khong_lo_truong_nhay_cam(): void
    {
        $owner = $this->owner();
        $goi   = $this->product($owner, ['category' => 'led']);
        $this->product($owner, ['category' => 'led']);

        $this->getJson('/api/v2/products/' . $goi->slug)
            ->assertOk()
            ->assertDontSee('revenue_share_pct')
            ->assertDontSee('floor_price')
            ->assertDontSee('63.17');
    }

    // ── Bộ lọc của sidebar /products (lát 3) ────────────────────────────────

    public function test_bo_loc_san_pham_tra_ba_nhom_co_so_dem(): void
    {
        $owner = $this->owner();
        $this->product($owner, ['category' => 'led', 'type' => Product::TYPE_PACKAGE, 'city' => 'Hà Nội']);
        $this->product($owner, ['category' => 'led', 'type' => Product::TYPE_PACKAGE, 'city' => 'Hà Nội']);
        $this->product($owner, ['category' => 'billboard', 'type' => Product::TYPE_SINGLE, 'city' => 'Đà Nẵng']);

        $body = $this->getJson('/api/v2/products/filters')->assertOk()->json('data');

        $categories = collect($body['categories']);
        $this->assertSame(2, $categories->firstWhere('slug', 'led')['count']);
        $this->assertSame(1, $categories->firstWhere('slug', 'billboard')['count']);

        // Nhãn tiếng Việt đến từ `Product::CATEGORIES`, không phải mã thô: đó
        // là chữ sidebar in ra, và bắt trình duyệt tự dịch mã là dựng bảng
        // nhãn thứ hai ở chỗ không ai bảo trì.
        $this->assertSame(Product::CATEGORIES['led'], $categories->firstWhere('slug', 'led')['label']);

        $types = collect($body['types']);
        $this->assertSame(2, $types->firstWhere('slug', Product::TYPE_PACKAGE)['count']);
        $this->assertSame('Gói', $types->firstWhere('slug', Product::TYPE_PACKAGE)['label']);

        $cities = collect($body['cities']);
        $this->assertSame(2, $cities->firstWhere('name', 'Hà Nội')['count']);
        $this->assertSame(1, $cities->firstWhere('name', 'Đà Nẵng')['count']);
    }

    public function test_bo_loc_san_pham_chi_dem_san_pham_cong_khai(): void
    {
        $owner = $this->owner();
        $this->product($owner, ['category' => 'led', 'city' => 'Hà Nội']);
        $this->product($owner, ['category' => 'led', 'city' => 'Hà Nội', 'status' => 'draft']);

        $data = $this->getJson('/api/v2/products/filters')->assertOk()->json('data');

        // Một bộ lọc đếm cả bản nháp là nói với người dùng rằng có 2 gói ở Hà
        // Nội, rồi trả về 1 khi họ bấm vào — và không có gì báo lỗi.
        $this->assertSame(1, collect($data['categories'])->firstWhere('slug', 'led')['count']);
        $this->assertSame(1, collect($data['cities'])->firstWhere('name', 'Hà Nội')['count']);
    }

    public function test_bo_loc_san_pham_chi_tra_khoa_da_liet_ke(): void
    {
        $this->product(null, ['category' => 'led', 'city' => 'Hà Nội']);

        $data = $this->getJson('/api/v2/products/filters')->assertOk()->json('data');

        $this->assertSame(['categories', 'types', 'cities'], array_keys($data));
        $this->assertSame(['slug', 'label', 'count'], array_keys($data['categories'][0]));
        $this->assertSame(['slug', 'label', 'count'], array_keys($data['types'][0]));
        $this->assertSame(['name', 'count'], array_keys($data['cities'][0]));
    }

    /**
     * `products/filters` phải khớp TRƯỚC `products/{slug}`.
     *
     * Đổi thứ tự hai route thì Laravel coi "filters" là một slug, không tìm
     * thấy sản phẩm nào, và trả 404 — một 404 trông hoàn toàn hợp lệ. Cùng
     * cái bẫy `screens/map` đã mắc một lần.
     */
    public function test_duong_bo_loc_khong_bi_khop_vao_slug(): void
    {
        $this->product(null, ['slug' => 'filters']);

        $data = $this->getJson('/api/v2/products/filters')->assertOk()->json('data');

        $this->assertArrayHasKey('categories', $data);
    }

    public function test_bo_loc_san_pham_khong_lo_truong_nhay_cam(): void
    {
        $this->product(null, ['category' => 'led', 'city' => 'Hà Nội']);

        $this->getJson('/api/v2/products/filters')
            ->assertOk()
            ->assertJsonMissing(['floor_price' => 30_000_000])
            ->assertDontSee('revenue_share_pct')
            ->assertDontSee('floor_price');
    }

    // ── Hai ca về trang Blade /products: GỠ ─────────────────────────────
    //
    // `test_trang_products_blade_van_chay_binh_thuong` và
    // `test_trang_products_blade_tu_choi_q_dang_mang` canh rằng thêm
    // `/api/v2/products` không làm hỏng trang Blade đang chạy. Đúng cho giai
    // đoạn hai bản song song.
    //
    // Giai đoạn 7 (07/10/2026) gỡ trang Blade đó; Next.js phục vụ `/products`
    // và nó lấy dữ liệu từ CHÍNH endpoint này. Quan hệ đã lật: không còn hai
    // bản để giữ cho khỏi đá nhau, chỉ còn một đường — và mọi ca khác trong
    // lớp này canh đúng đường đó.
    //
    // ══ Ca thứ hai còn một lý do riêng để gỡ ══
    //
    // Nó đòi `/products?q[]=x` trả **302**. Sau khi route bị gỡ, app có
    // `Route::fallback()` đẩy mọi đường lạ về trang chủ — cũng **302**. Tức
    // nó vẫn xanh, nhưng xanh vì fallback chứ không vì input sai bị từ chối.
    //
    // Một ca xanh vì lý do sai thì tệ hơn không có ca: nó báo rằng một bảo
    // đảm đang được canh trong khi nó không còn được canh.
    //
    // Bảo đảm thật — `q` dạng mảng bị từ chối thay vì trả 500 — vẫn được canh
    // ở `test_tu_choi_q_dang_mang_thay_vi_tra_500` của chính lớp này, trên
    // endpoint API.
}
