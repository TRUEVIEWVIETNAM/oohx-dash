<?php

namespace Tests\Feature\Buyer;

use App\Models\BookingLine;
use App\Models\BookingLineBundle;
use App\Models\Cart;
use App\Models\InventoryHold;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Gói chưa gắn màn hình nào — chốt `cho phép add product dù chưa có màn hình`,
 * 06/11/2026.
 *
 * ══ Việc được báo ══
 *
 * Nút "Thêm vào kế hoạch" vừa nối vào `/api/v2/cart/items` trả 422 *"Sản phẩm
 * ... chưa có màn hình nào để bán"* trên một gói đang bán công khai. Đo thật:
 * `GET /api/v2/products/man-hinh-quang-cao-aeon-mall-tan-phu` trả
 * `quantity.total_units: 4` nhưng `screens: []` — `total_units` là cột khai
 * tay trên `products`, còn quan hệ `screens` thì trống. Máy chủ từ chối đúng;
 * con số "4 vị trí" trang in ra mới là thứ không có gì đứng sau.
 *
 * Chủ dự án chốt **cho thêm vào giỏ**, sau khi nghe cái giá của nó.
 *
 * ══ Vì sao hàng rào phải chuyển chứ không biến mất ══
 *
 * `BundleExpander::productTotal()` ở nhánh `package` trả thẳng `floor_price`
 * và **không đọc màn hình nào**. Nên một gói rỗng vào giỏ mang đủ số tiền của
 * gói trong khi không giữ chỗ ở đâu cả, và nếu để nguyên thì nó đi thẳng tới
 * hóa đơn:
 *
 *  - guard "giá vừa thay đổi" ở `createProductLines` so `productTotal` với
 *    `estimated_cost` — cả hai đều là `floor_price` nên **khớp**;
 *  - `splitCost()` gọi `splitVnd($total, [])`, mà hàm đó trả `[]` cho mảng
 *    rỗng chứ không chia cho 0 — **không ném**;
 *  - vòng lặp tạo dòng không chạy lần nào.
 *
 * Kết quả là một `BookingLineBundle` ghi đủ tiền, `snapshot.screens = []`, và
 * **không một `BookingLine` nào**. Một đơn hàng không có vị trí nào để phát
 * sóng, không ngoại lệ nào để lần ra. Ba ca dưới đây đo đúng ba mắt xích ấy.
 *
 * Giỏ là chỗ chứa dự định; chốt đơn là chỗ tiền đổi chủ. Hàng rào ở chỗ thứ
 * hai, và đó cũng là chỗ duy nhất biết chắc tập màn hình lúc trả tiền — giữa
 * hai thời điểm owner có thể đã gắn thêm hoặc gỡ hết màn hình khỏi gói.
 */
class GoiChuaCoManHinhTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['buyer', 'publisher', 'super_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->org   = Organization::factory()->create(['status' => 'active']);
        $this->buyer = User::factory()->create(['current_organization_id' => $this->org->id]);
        $this->buyer->assignRole('buyer');

        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->buyer->id,
            'role'            => 'admin',
        ]);

        $this->actingAs($this->buyer);
    }

    // ── Dựng dữ liệu ────────────────────────────────────────────────────────

    private function screen(int $ioRate = 1_000_000, ?Owner $owner = null): Screen
    {
        $owner  = $owner ?: Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenInventory::create([
            'screen_id'     => $screen->id,
            'pricing_model' => 'io',
            'io_rate'       => $ioRate,
            'io_rate_unit'  => 'month',
            'spot_length'   => 15,
        ]);

        return $screen->fresh('inventory');
    }

    /**
     * Một gói đúng hình dạng của gói đang lỗi trên production: `total_units`
     * khai tay, quan hệ `screens` trống.
     *
     * @param  array<int, Screen>  $screens
     */
    private function product(array $screens, string $listingMode = 'both', int $floorPrice = 250_000_000, int $totalUnits = 4): Product
    {
        $owner = $screens === []
            ? Owner::factory()->create(['status' => 'active'])
            : $screens[0]->owner;

        $product = Product::create([
            'owner_id'     => $owner->id,
            'name'         => 'Màn Hình Quảng Cáo Aeon Mall Tân Phú',
            'slug'         => 'goi-rong-' . Str::random(6),
            'type'         => 'package',
            'category'     => 'led',
            'listing_mode' => $listingMode,
            'floor_price'  => $floorPrice,
            'min_quantity' => 1,
            // Cố ý KHÔNG bằng số màn hình thật — đây là chính cái lệch đã gây
            // ra việc này.
            'total_units'  => $totalUnits,
            'status'       => 'active',
        ]);

        foreach ($screens as $i => $screen) {
            $product->screens()->attach($screen->id, ['sort_order' => $i, 'is_primary' => $i === 0]);
        }

        return $product->fresh();
    }

    private function cart(): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $this->buyer->id, 'status' => 'active'],
            ['organization_id' => $this->org->id, 'name' => 'My Plan'],
        );
    }

    /** @return array{start_date: string, end_date: string} */
    private function dates(): array
    {
        $start = now()->addYear()->startOfYear();

        return [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
        ];
    }

    // ── Giỏ: gói rỗng vào được ──────────────────────────────────────────────

    public function test_goi_chua_co_man_hinh_van_them_vao_gio_duoc(): void
    {
        $product = $this->product([]);

        $item = app(CartService::class)->addProduct(
            $this->cart(),
            $product->id,
            $this->dates() + ['buy_mode' => 'package'],
        );

        $this->assertNotNull($item->id);
        $this->assertSame('package', $item->buy_mode);
        $this->assertNull($item->screen_id, 'Không có màn hình nào để trỏ tới — cột này nullable từ 13/04.');
        $this->assertSame(1, (int) $item->quantity);
    }

    public function test_dong_gio_cua_goi_rong_mang_du_so_tien_cua_goi(): void
    {
        // Con số này chính là cái giá của quyết định: `productTotal()` nhánh
        // `package` trả `floor_price` không đọc màn hình nào. Ghim nó để lần
        // sau ai đọc cũng thấy giỏ đang hứa 250 triệu cho không vị trí nào.
        $product = $this->product([], floorPrice: 250_000_000);

        $item = app(CartService::class)->addProduct(
            $this->cart(),
            $product->id,
            $this->dates() + ['buy_mode' => 'package'],
        );

        $this->assertSame(250_000_000, (int) round((float) $item->estimated_cost));
    }

    public function test_goi_rong_khong_giu_cho_o_dau_ca(): void
    {
        $product = $this->product([]);

        app(CartService::class)->addProduct(
            $this->cart(),
            $product->id,
            $this->dates() + ['buy_mode' => 'package'],
        );

        $this->assertSame(0, InventoryHold::count(), 'Không màn hình nào để giữ chỗ.');
    }

    public function test_mua_le_tren_goi_rong_van_bi_chan_ngay_tai_gio(): void
    {
        // Mua lẻ là người mua tự chọn từng màn hình, nên một gói trống là ngõ
        // cụt ngay tại chỗ — không phải thứ để họ phát hiện ở bước thanh toán.
        $product = $this->product([], listingMode: 'individual_only');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('chưa có màn hình nào để bán');

        app(CartService::class)->addProduct(
            $this->cart(),
            $product->id,
            $this->dates() + ['buy_mode' => 'individual', 'selected_screen_ids' => []],
        );
    }

    // ── Chốt đơn: hàng rào ──────────────────────────────────────────────────

    public function test_chot_don_tu_choi_goi_khong_mo_ra_duoc_dong_nao(): void
    {
        $product = $this->product([]);
        $cart = $this->cart();

        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + ['buy_mode' => 'package']);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('chưa có vị trí nào');

        app(CampaignService::class)->createFromCart(
            $this->org,
            $this->buyer,
            $cart->fresh(),
            ['name' => 'Chiến dịch'],
        );
    }

    public function test_khong_bundle_nao_va_khong_dong_nao_sot_lai_sau_khi_bi_tu_choi(): void
    {
        // Phép kiểm thật của hàng rào. Thiếu nó, `BookingLineBundle` vẫn được
        // tạo với `price_total` đủ tiền và `snapshot.screens = []`, trong khi
        // `BookingLine` thì không có cái nào — và không ngoại lệ nào bay ra để
        // ai đó đi tìm.
        $product = $this->product([]);
        $cart = $this->cart();

        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + ['buy_mode' => 'package']);

        try {
            app(CampaignService::class)->createFromCart(
                $this->org,
                $this->buyer,
                $cart->fresh(),
                ['name' => 'Chiến dịch'],
            );

            $this->fail('Chốt đơn một gói rỗng phải bị từ chối.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode(), '409 nói "giá vừa đổi, xem lại giỏ" — xem lại giỏ không sửa được gì ở đây.');
        }

        $this->assertSame(0, BookingLineBundle::count(), 'Một bundle ghi đủ tiền mà không dòng nào là một hóa đơn không có vị trí nào để phát sóng.');
        $this->assertSame(0, BookingLine::count());
    }

    public function test_mot_goi_rong_lam_hong_ca_don_chu_khong_de_lai_nua_voi(): void
    {
        // Giỏ có một màn hình lẻ hợp lệ **và** một gói rỗng. Đơn phải hỏng
        // trọn vẹn: một campaign chỉ có nửa số dòng là thứ người mua trả tiền
        // mà không biết mình thiếu gì.
        $manHinh = $this->screen();
        $goiRong = $this->product([]);
        $cart = $this->cart();

        app(CartService::class)->addItem($cart, $manHinh->id, $this->dates());
        app(CartService::class)->addProduct($cart, $goiRong->id, $this->dates() + ['buy_mode' => 'package']);

        try {
            app(CampaignService::class)->createFromCart(
                $this->org,
                $this->buyer,
                $cart->fresh(),
                ['name' => 'Chiến dịch'],
            );

            $this->fail('Phải bị từ chối.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, BookingLine::count(), 'Dòng của màn hình lẻ cũng không được nằm lại.');
    }

    // ── Không hồi quy: gói có màn hình vẫn chốt đơn bình thường ─────────────

    public function test_goi_co_man_hinh_van_chot_don_binh_thuong(): void
    {
        $screens = [$this->screen(), $this->screen()];
        $product = $this->product($screens, floorPrice: 30_000_000, totalUnits: 2);
        $cart = $this->cart();

        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + ['buy_mode' => 'package']);

        $campaign = app(CampaignService::class)->createFromCart(
            $this->org,
            $this->buyer,
            $cart->fresh(),
            ['name' => 'Chiến dịch'],
        );

        $this->assertSame(2, BookingLine::where('campaign_id', $campaign->id)->count());
        $this->assertSame(1, BookingLineBundle::where('campaign_id', $campaign->id)->count());
    }
}
