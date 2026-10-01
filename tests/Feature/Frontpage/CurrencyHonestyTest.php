<?php

namespace Tests\Feature\Frontpage;

use App\Models\Owner;
use App\Models\Product;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Services\FrontpageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Giá niêm yết bằng USD không được in ra kèm "₫", và không được so trực tiếp
 * với giá VND.
 *
 * Bối cảnh: `screen_inventory.floor_cpm_currency` và `products.currency` nhận
 * VND hoặc USD, và **dữ liệu thật có hàng USD** (chốt 01/10/2026). Trước loạt
 * sửa này:
 *
 *  - Trang danh sách, bản đồ và chi tiết đều dán "₫" cứng, nên 2,50 USD hiện
 *    ra là "2 đ" — sai đơn vị, lệch bốn bậc.
 *  - Lọc theo khoảng giá và sắp xếp theo giá so trực tiếp số thô, nên 2,50 USD
 *    lọt vào khoảng "dưới 1.000 ₫" và xếp trước mọi màn hình VND.
 *  - Khoảng giá của bộ lọc: bản sửa ĐẦU của tôi loại hàng USD ra, tức làm
 *    chúng vô hình với thanh lọc giá. Sai hướng khác.
 *
 * Nguyên tắc chốt lại: **giá hiển thị giữ nguyên đơn vị gốc; quy đổi chỉ dùng
 * để so sánh và sắp xếp**, với tỷ giá nằm ở `config('pricing.usd_vnd_rate')`.
 * Quy đổi là một phép xấp xỉ, giá niêm yết là một cam kết.
 */
class CurrencyHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('pricing.usd_vnd_rate', 25000);
    }

    private function url(string $path = '/'): string
    {
        return 'http://' . config('domains.frontpage', 'oohx.net') . $path;
    }

    private function cpmScreen(float $floorCpm, string $currency): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'city' => 'Hà Nội', 'status' => 'active']);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenSpec::factory()->create(['screen_id' => $screen->id, 'width_cm' => 400, 'height_cm' => 200]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            // CPM-only: `display_price` khi đó là `floor_cpm`, tức con số mang
            // đơn vị tiền. Màn hình bán theo kỳ thì `io_rate` luôn là VND.
            'pricing_model'          => 'cpm',
            'floor_cpm'              => $floorCpm,
            'floor_cpm_currency'     => $currency,
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        return $screen->fresh(['inventory', 'spec', 'site']);
    }

    // ── Hiển thị: không dán sai đơn vị ──────────────────────────────────────

    public function test_accessor_tra_dung_don_vi_tien(): void
    {
        $usd = $this->cpmScreen(2.50, 'USD');
        $vnd = $this->cpmScreen(50_000, 'VND');

        $this->assertSame('USD', $usd->inventory->display_currency);
        $this->assertSame('VND', $vnd->inventory->display_currency);

        $this->assertStringContainsString('USD', $usd->inventory->display_price_formatted);
        $this->assertStringNotContainsString('₫', $usd->inventory->display_price_formatted);
        $this->assertStringContainsString('₫', $vnd->inventory->display_price_formatted);
    }

    public function test_man_hinh_ban_theo_ky_luon_la_vnd(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);
        ScreenInventory::create([
            'screen_id'          => $screen->id,
            'pricing_model'      => 'io',
            'io_rate'            => 1_000_000,
            'io_rate_unit'       => 'month',
            'floor_cpm'          => 2.50,
            // Dù cột này là USD, giá hiển thị là `io_rate` — và `io_rate`
            // không có cột currency nên là VND theo định nghĩa.
            'floor_cpm_currency' => 'USD',
            'spot_length'        => 15,
        ]);

        $this->assertSame('VND', $screen->fresh('inventory')->inventory->display_currency);
    }

    public function test_trang_chi_tiet_khong_in_dong_canh_so_usd(): void
    {
        $screen = $this->cpmScreen(2.50, 'USD');

        $response = $this->get($this->url('/explore/' . $screen->slug))->assertOk();

        $response->assertSee('2,50 USD', false);
        $response->assertDontSee('2 ₫', false);
    }

    public function test_the_man_hinh_tren_danh_sach_in_dung_don_vi(): void
    {
        $screen = $this->cpmScreen(2.50, 'USD');

        $this->get($this->url('/explore'))
            ->assertOk()
            ->assertSee('USD', false);
    }

    public function test_san_pham_in_dung_don_vi_tien(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);

        $vnd = Product::create([
            'owner_id' => $owner->id, 'name' => 'Gói VND', 'slug' => 'goi-vnd-' . uniqid(),
            'type' => Product::TYPE_SINGLE, 'category' => 'led', 'listing_mode' => Product::LISTING_BOTH,
            'floor_price' => 30_000_000, 'currency' => 'VND', 'price_unit' => 'month', 'status' => 'active',
        ]);
        $usd = Product::create([
            'owner_id' => $owner->id, 'name' => 'Gói USD', 'slug' => 'goi-usd-' . uniqid(),
            'type' => Product::TYPE_SINGLE, 'category' => 'led', 'listing_mode' => Product::LISTING_BOTH,
            'floor_price' => 1_250.50, 'currency' => 'USD', 'price_unit' => 'month', 'status' => 'active',
        ]);

        $this->assertStringContainsString('₫', $vnd->price_display);
        $this->assertStringContainsString('USD', $usd->price_display);
        $this->assertStringNotContainsString('₫', $usd->price_display);
    }

    // ── So sánh: quy đổi trước khi so ───────────────────────────────────────

    public function test_loc_khoang_gia_quy_doi_truoc_khi_so(): void
    {
        // 2,50 USD × 25.000 = 62.500 ₫. Với số thô thì 2,50 lọt vào mọi
        // khoảng giá, kể cả "dưới 1.000".
        $usd = $this->cpmScreen(2.50, 'USD');
        $vnd = $this->cpmScreen(50_000, 'VND');

        $duoiNghin = $this->paginate(['max_price' => 1_000]);
        $this->assertNotContains($usd->id, $duoiNghin, 'Màn hình 2,50 USD lọt vào khoảng dưới 1.000 ₫.');
        $this->assertNotContains($vnd->id, $duoiNghin);

        $tren60k = $this->paginate(['min_price' => 60_000]);
        $this->assertContains($usd->id, $tren60k, '2,50 USD = 62.500 ₫ phải nằm trong khoảng trên 60.000 ₫.');
        $this->assertNotContains($vnd->id, $tren60k, '50.000 ₫ không nằm trong khoảng trên 60.000 ₫.');
    }

    public function test_sap_xep_theo_gia_quy_doi_truoc_khi_so(): void
    {
        $usd = $this->cpmScreen(2.50, 'USD');     // 62.500 ₫
        $vnd = $this->cpmScreen(50_000, 'VND');   // 50.000 ₫

        // Số thô thì 2,50 < 50.000 nên USD luôn xếp trước. Quy đổi thì ngược.
        $this->assertSame(
            [$vnd->id, $usd->id],
            $this->paginate(['sort' => 'price_asc']),
            'Sắp xếp tăng dần đang so số thô, không so giá quy đổi.'
        );
        $this->assertSame(
            [$usd->id, $vnd->id],
            $this->paginate(['sort' => 'price_desc']),
        );
    }

    public function test_khoang_gia_bo_loc_gom_ca_hang_usd(): void
    {
        $this->cpmScreen(2.50, 'USD');     // 62.500 ₫
        $this->cpmScreen(50_000, 'VND');
        Cache::flush();

        $aggregates = app(FrontpageService::class)->getFilterAggregates();

        // Bản sửa đầu của tôi loại hàng USD ra, nên `max_price` sẽ là 50.000 và
        // người mua kéo thanh giá sẽ không bao giờ thấy màn hình USD đó.
        $this->assertSame(50_000.0, (float) $aggregates['min_price']);
        $this->assertSame(62_500.0, (float) $aggregates['max_price'], 'Khoảng giá đang bỏ rơi hàng niêm yết bằng USD.');
        $this->assertSame('VND', $aggregates['price_currency']);
    }

    public function test_doi_ty_gia_thi_thu_tu_doi_theo(): void
    {
        $usd = $this->cpmScreen(2.50, 'USD');
        $vnd = $this->cpmScreen(50_000, 'VND');

        // Tỷ giá là tham số nghiệp vụ, không phải hằng số ẩn trong chữ ký hàm.
        // Ở mức 10.000 thì 2,50 USD = 25.000 ₫, xếp trước 50.000 ₫.
        Config::set('pricing.usd_vnd_rate', 10_000);
        Cache::flush();

        $this->assertSame([$usd->id, $vnd->id], $this->paginate(['sort' => 'price_asc']));
    }

    public function test_quy_doi_khong_lam_doi_gia_hien_thi(): void
    {
        $screen = $this->cpmScreen(2.50, 'USD');

        Config::set('pricing.usd_vnd_rate', 99_999);

        // Quy đổi chỉ để so sánh. Giá niêm yết là một cam kết, không được đổi
        // theo một tham số cấu hình.
        $this->assertStringContainsString('2,50 USD', $screen->inventory->display_price_formatted);
        $this->assertSame(2.5, (float) $screen->inventory->display_price);
    }

    // ── Đường tiền: chặn thay vì tự quy đổi ─────────────────────────────────

    public function test_khong_dat_duoc_man_hinh_cpm_niem_yet_bang_usd(): void
    {
        $screen = $this->cpmScreen(2.50, 'USD');

        $org   = \App\Models\Organization::factory()->create(['status' => 'active']);
        $buyer = \App\Models\User::factory()->create(['current_organization_id' => $org->id]);
        \App\Models\OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $buyer->id,
            'role'            => \App\Models\OrganizationUser::ROLE_ADMIN,
        ]);

        $cart = \App\Models\Cart::create([
            'user_id'         => $buyer->id,
            'organization_id' => $org->id,
            'status'          => 'active',
            'name'            => 'Giỏ thử',
        ]);

        // Đường tiền KHÔNG mang đơn vị: `cart_items.estimated_cost` và
        // `booking_lines.estimated_cost` không có cột currency. Nhánh CPM tính
        // `floor_cpm × booked_cpms` trên số thô, nên 2,50 USD × 1.000 CPM ra
        // `2.500` và mọi bước sau coi là **2.500 ₫** — thấp hơn giá thật
        // khoảng 25.000 lần.
        //
        // Chặn làm mất một lượt bán; tự quy đổi sai làm mất tiền và tạo một
        // hóa đơn sai mà không ai thấy.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->expectExceptionMessage('USD');

        app(\App\Services\CartService::class)->addItem($cart, $screen->id, [
            'start_date'     => now()->addMonth()->toDateString(),
            'end_date'       => now()->addMonths(2)->toDateString(),
            'pricing_model'  => 'cpm',
        ]);
    }

    public function test_van_dat_duoc_man_hinh_ban_theo_ky_du_cot_cpm_la_usd(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'floor_cpm'              => 2.50,
            'floor_cpm_currency'     => 'USD',
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        $org   = \App\Models\Organization::factory()->create(['status' => 'active']);
        $buyer = \App\Models\User::factory()->create(['current_organization_id' => $org->id]);
        \App\Models\OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $buyer->id,
            'role'            => \App\Models\OrganizationUser::ROLE_ADMIN,
        ]);
        $cart = \App\Models\Cart::create([
            'user_id'         => $buyer->id,
            'organization_id' => $org->id,
            'status'          => 'active',
            'name'            => 'Giỏ thử',
        ]);

        // Không chặn quá tay: `io_rate` không có cột currency nên là VND theo
        // định nghĩa, và việc cột CPM là USD không liên quan tới lượt mua này.
        $item = app(\App\Services\CartService::class)->addItem($cart, $screen->id, [
            'start_date' => now()->addYear()->startOfYear()->toDateString(),
            'end_date'   => now()->addYear()->startOfYear()->addMonthNoOverflow()->subDay()->toDateString(),
        ]);

        $this->assertSame(1_000_000, (int) round((float) $item->estimated_cost));
    }

    /** @return array<int, string> id màn hình theo đúng thứ tự trả về */
    private function paginate(array $params): array
    {
        $request = Request::create('/explore', 'GET', $params);

        return app(FrontpageService::class)
            ->getScreensPaginated($request, 50)
            ->getCollection()
            ->pluck('id')
            ->all();
    }
}
