<?php

namespace Tests\Feature\Buyer;

use App\Models\BookingLine;
use App\Models\BookingLineBundle;
use App\Models\Campaign;
use App\Models\Cart;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\Booking\BundleExpander;
use App\Services\CampaignService;
use App\Services\CartService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Giai đoạn 1.2 — mua gói phải sinh MỘT DÒNG CHO MỖI MÀN HÌNH.
 *
 * Trước sửa: `CartService::addProduct` lưu `screen_id` = màn hình đầu tiên của
 * gói, và `createFromCart` tạo đúng một dòng booking từ đó. Gói 3 màn hình của
 * 3 owner khác nhau thu về một dòng trên một màn hình: SOV chỉ bị trừ ở một chỗ
 * nên hai màn còn lại vẫn báo trống và bán tiếp, còn tiền thì ghi hết cho owner
 * của màn hình đầu — hai owner kia không có dòng công nợ nào (Codex R05).
 */
class BundleExpansionTest extends TestCase
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

    /** Một màn hình bán được, kèm giá niêm yết I/O theo tháng. */
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

    /** @param array<int, Screen> $screens */
    private function product(array $screens, string $listingMode, int $floorPrice, ?int $individualPrice = null): Product
    {
        $product = Product::create([
            'owner_id'         => $screens[0]->owner_id,
            'name'             => 'Gói thử nghiệm',
            'slug'             => 'goi-thu-nghiem-' . Str::random(6),
            'type'             => 'package',
            'category'         => 'led',
            'listing_mode'     => $listingMode,
            'floor_price'      => $floorPrice,
            'individual_price' => $individualPrice,
            'min_quantity'     => 1,
            'total_units'      => count($screens),
            'status'           => 'active',
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
            ['organization_id' => $this->org->id, 'name' => 'My Plan']
        );
    }

    private function dates(): array
    {
        $start = now()->addYear()->startOfYear();

        return [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
        ];
    }

    private function checkout(Cart $cart): Campaign
    {
        return app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'Chiến dịch']);
    }

    // ── Mở gói ───────────────────────────────────────────────────────────────

    public function test_mua_ca_goi_sinh_mot_dong_cho_moi_man_hinh(): void
    {
        $screens = [$this->screen(), $this->screen(), $this->screen()];
        $product = $this->product($screens, 'package_only', 30_000_000);

        $cart = $this->cart();
        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + ['buy_mode' => 'package']);

        $campaign = $this->checkout($cart);
        $lines    = $campaign->bookingLines()->get();

        $this->assertCount(3, $lines, 'Gói 3 màn hình phải thành 3 dòng đặt chỗ.');
        $this->assertEqualsCanonicalizing(
            collect($screens)->pluck('id')->all(),
            $lines->pluck('screen_id')->all(),
            'Mỗi màn hình trong gói phải có dòng riêng, không chỉ màn hình đầu tiên.'
        );
        $this->assertSame(3, (int) $campaign->fresh()->total_screens);
    }

    public function test_moi_dong_mang_dung_owner_cua_man_hinh_do(): void
    {
        $screens = [$this->screen(), $this->screen(), $this->screen()];
        $product = $this->product($screens, 'package_only', 30_000_000);

        $cart = $this->cart();
        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + ['buy_mode' => 'package']);

        $campaign = $this->checkout($cart);

        $ownersOnLines = $campaign->bookingLines()->pluck('owner_id')->unique()->sort()->values()->all();
        $ownersOfScreens = collect($screens)->pluck('owner_id')->unique()->sort()->values()->all();

        $this->assertSame($ownersOfScreens, $ownersOnLines, 'Ba owner phải có ba dòng, không dồn về owner của màn hình đầu.');
    }

    public function test_tong_tien_cac_dong_bang_dung_gia_goi(): void
    {
        // Giá gói lẻ và trọng số lệch nhau để lộ lỗi làm tròn nếu có.
        $screens = [$this->screen(1_000_000), $this->screen(2_000_000), $this->screen(3_000_000)];
        $product = $this->product($screens, 'package_only', 10_000_001);

        $cart = $this->cart();
        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + ['buy_mode' => 'package']);

        $campaign = $this->checkout($cart);

        $this->assertSame(
            10_000_001,
            (int) round((float) $campaign->bookingLines()->sum('estimated_cost')),
            'Tổng tiền các dòng phải bằng đúng giá gói, không lệch một đồng vì làm tròn.'
        );
    }

    public function test_man_hinh_dat_hon_ganh_phan_tien_lon_hon(): void
    {
        $cheap = $this->screen(1_000_000);
        $rich  = $this->screen(3_000_000);
        $product = $this->product([$cheap, $rich], 'package_only', 8_000_000);

        $cart = $this->cart();
        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + ['buy_mode' => 'package']);

        $campaign = $this->checkout($cart);

        $costCheap = (int) $campaign->bookingLines()->where('screen_id', $cheap->id)->value('estimated_cost');
        $costRich  = (int) $campaign->bookingLines()->where('screen_id', $rich->id)->value('estimated_cost');

        $this->assertSame(2_000_000, $costCheap);
        $this->assertSame(6_000_000, $costRich, 'Chia theo giá niêm yết: 1 triệu so 3 triệu thì tỉ lệ 1:3.');
    }

    public function test_khong_co_gia_niem_yet_thi_chia_deu(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $a = $this->screen(0, $owner);
        $b = $this->screen(0, $owner);
        $product = $this->product([$a, $b], 'package_only', 5_000_000);

        $cart = $this->cart();
        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + ['buy_mode' => 'package']);

        $campaign = $this->checkout($cart);
        $costs = $campaign->bookingLines()->pluck('estimated_cost')->map(fn ($c) => (int) $c)->sort()->values()->all();

        $this->assertSame([2_500_000, 2_500_000], $costs);
        $this->assertSame(
            BundleExpander::SPLIT_EQUALLY,
            $campaign->bookingLines()->first()->bundle->snapshot['split_method'],
            'Bản chụp phải nói rõ là đã chia đều, để đối soát không phải đoán.'
        );
    }

    // ── Bản chụp thành phần gói ──────────────────────────────────────────────

    public function test_ban_chup_ghi_lai_thanh_phan_goi_luc_mua(): void
    {
        $screens = [$this->screen(), $this->screen()];
        $product = $this->product($screens, 'package_only', 4_000_000);

        $cart = $this->cart();
        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + ['buy_mode' => 'package']);

        $campaign = $this->checkout($cart);
        $bundle   = BookingLineBundle::where('campaign_id', $campaign->id)->firstOrFail();

        $this->assertSame($product->id, $bundle->product_id);
        $this->assertSame('package', $bundle->buy_mode);
        $this->assertSame(4_000_000, (int) round((float) $bundle->price_total));
        $this->assertCount(2, $bundle->snapshot['screens']);
        $this->assertEqualsCanonicalizing(
            collect($screens)->pluck('id')->all(),
            collect($bundle->snapshot['screens'])->pluck('screen_id')->all()
        );

        // Owner đổi thành phần gói SAU khi bán: bản chụp không được đổi theo.
        $product->screens()->detach($screens[1]->id);

        $this->assertCount(
            2,
            $bundle->fresh()->snapshot['screens'],
            'Bản chụp là bằng chứng của đơn đã bán, owner sửa gói không được làm nó trôi theo.'
        );
    }

    public function test_man_hinh_bi_go_khoi_goi_truoc_khi_chot_don_thi_bi_chan(): void
    {
        $screens = [$this->screen(), $this->screen()];
        $product = $this->product($screens, 'package_only', 4_000_000);

        $cart = $this->cart();
        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + ['buy_mode' => 'package']);

        // Owner ngưng bán một màn hình trong gói sau khi khách đã bỏ vào giỏ.
        $screens[1]->update(['active' => false]);

        try {
            $this->checkout($cart);
            $this->fail('Phải chặn chốt đơn khi một màn hình trong gói không còn bán được.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, Campaign::count(), 'Không được để lại chiến dịch dở dang.');
        $this->assertSame(0, BookingLine::count());
        $this->assertSame(0, BookingLineBundle::count());
    }

    // ── Mua lẻ từng màn hình ─────────────────────────────────────────────────

    public function test_mua_le_hai_man_hinh_sinh_hai_dong_theo_don_gia(): void
    {
        $screens = [$this->screen(), $this->screen(), $this->screen()];
        $product = $this->product($screens, 'both', 9_000_000, 1_500_000);

        $cart = $this->cart();
        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + [
            'buy_mode'            => 'individual',
            'selected_screen_ids' => [$screens[0]->id, $screens[2]->id],
        ]);

        $campaign = $this->checkout($cart);
        $lines    = $campaign->bookingLines()->get();

        $this->assertCount(2, $lines);
        $this->assertEqualsCanonicalizing(
            [$screens[0]->id, $screens[2]->id],
            $lines->pluck('screen_id')->all(),
            'Chỉ hai màn hình được chọn, không phải cả gói.'
        );
        $this->assertSame([1_500_000, 1_500_000], $lines->pluck('estimated_cost')->map(fn ($c) => (int) $c)->all());
        $this->assertSame('individual', BookingLineBundle::firstOrFail()->buy_mode);
    }

    // ── Hệ quả xuống công nợ từng owner ──────────────────────────────────────

    public function test_cong_no_chia_dung_cho_tung_owner_trong_goi(): void
    {
        $a = $this->screen(1_000_000);
        $b = $this->screen(3_000_000);
        $product = $this->product([$a, $b], 'package_only', 8_000_000);

        $cart = $this->cart();
        app(CartService::class)->addProduct($cart, $product->id, $this->dates() + ['buy_mode' => 'package']);

        $campaign = $this->checkout($cart);
        $campaign->bookingLines()->update(['status' => 'approved']);

        $breakdown = app(PaymentService::class)->breakdownByOwner($campaign->fresh());

        $this->assertCount(2, $breakdown, 'Hai owner phải có hai dòng công nợ.');
        $this->assertEqualsCanonicalizing(
            [2_000_000, 6_000_000],
            $breakdown->pluck('cost')->map(fn ($c) => (int) $c)->all()
        );
    }
}
