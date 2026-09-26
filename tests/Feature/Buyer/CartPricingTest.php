<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * T2 — giá do máy chủ quyết định.
 *
 * Lỗ hổng gốc (audit F-01, Codex F04, có probe tái hiện): `booked_cpms` và
 * `duration_units` do client gửi được nhân thẳng vào đơn giá mà không đối chiếu
 * với khoảng ngày. Đặt 6 tháng, gửi `duration_units=1`, trả tiền 1 tháng.
 */
class CartPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['buyer', 'publisher', 'super_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $org = Organization::factory()->create(['status' => 'active']);
        $this->buyer = User::factory()->create(['current_organization_id' => $org->id]);
        $this->buyer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $this->buyer->id,
            'role'            => 'admin',
        ]);

        $this->actingAs($this->buyer);
    }

    private function screenWithIoRate(float $rate, string $unit = 'month'): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenInventory::create([
            'screen_id'     => $screen->id,
            'pricing_model' => 'io',
            'io_rate'       => $rate,
            'io_rate_unit'  => $unit,
            'spot_length'   => 15,
        ]);

        return $screen->fresh('inventory');
    }

    private function screenWithCpm(float $floorCpm, int $weeklyImpressions): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenInventory::create([
            'screen_id'          => $screen->id,
            'pricing_model'      => 'cpm',
            'floor_cpm'          => $floorCpm,
            'weekly_impressions' => $weeklyImpressions,
            'spot_length'        => 15,
        ]);

        return $screen->fresh('inventory');
    }

    private function cart(): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $this->buyer->id, 'status' => 'active'],
            ['organization_id' => $this->buyer->current_organization_id, 'name' => 'My Plan']
        );
    }

    private function dates(int $days): array
    {
        $start = now()->addDays(7)->startOfDay();

        return [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addDays($days - 1)->toDateString(),
        ];
    }

    // ── Chống hồi quy cho đúng lỗ hổng ───────────────────────────────────────

    public function test_gui_duration_units_thap_hon_thuc_te_bi_tu_choi(): void
    {
        $screen = $this->screenWithIoRate(1_000_000);

        try {
            app(CartService::class)->addItem($this->cart(), $screen->id, $this->dates(181) + [
                "duration_units" => 1,   // 181 ngày = 7 kỳ, cố tình khai 1
            ]);
            $this->fail("Phải từ chối số kỳ thấp hơn thực tế.");
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString("7 kỳ", $e->getMessage());
        }
    }

    public function test_khong_gui_duration_units_thi_may_chu_tu_tinh(): void
    {
        $screen = $this->screenWithIoRate(1_000_000);

        $item = app(CartService::class)->addItem($this->cart(), $screen->id, $this->dates(181));

        $this->assertSame(7, (int) $item->duration_units);
        $this->assertEquals(7_000_000, (float) $item->estimated_cost);
    }

    public function test_mua_them_ky_van_duoc_chap_nhan(): void
    {
        $screen = $this->screenWithIoRate(1_000_000);

        $item = app(CartService::class)->addItem($this->cart(), $screen->id, $this->dates(30) + [
            'duration_units' => 3,   // mua dư, hợp lệ
        ]);

        $this->assertSame(3, (int) $item->duration_units);
        $this->assertEquals(3_000_000, (float) $item->estimated_cost);
    }

    public function test_gui_booked_cpms_thap_hon_muc_toi_thieu_bi_tu_choi(): void
    {
        // 70.000 hiển thị/tuần = 10.000/ngày; 30 ngày, SOV 100% ⇒ 300.000 ⇒ 300 CPM
        $screen = $this->screenWithCpm(50_000, 70_000);

        $this->expectException(HttpException::class);

        app(CartService::class)->addItem($this->cart(), $screen->id, $this->dates(30) + [
            'pricing_model' => 'cpm',
            'booked_cpms'   => 1,
        ]);
    }

    public function test_doi_ngay_dai_them_thi_gia_tang_theo(): void
    {
        $screen = $this->screenWithIoRate(1_000_000);
        $cartService = app(CartService::class);

        $item = $cartService->addItem($this->cart(), $screen->id, $this->dates(30));
        $this->assertEquals(1_000_000, (float) $item->estimated_cost);

        // Kéo dài thành 90 ngày: phải thành 3 kỳ, không giữ số kỳ cũ.
        $item = $cartService->updateItem($item, $this->dates(90));

        $this->assertSame(3, (int) $item->duration_units);
        $this->assertEquals(3_000_000, (float) $item->estimated_cost);
    }

    // ── Đóng băng giá ────────────────────────────────────────────────────────

    public function test_gia_doi_sau_khi_bo_vao_gio_thi_chan_chot_don(): void
    {
        $screen = $this->screenWithIoRate(1_000_000);
        $cart   = $this->cart();

        app(CartService::class)->addItem($cart, $screen->id, $this->dates(30));

        // Media owner đổi giá trong lúc giỏ còn nằm đó.
        $screen->inventory->update(['io_rate' => 2_000_000]);

        try {
            app(CampaignService::class)->createFromCart(
                Organization::find($this->buyer->current_organization_id),
                $this->buyer,
                $cart->fresh(),
                ['name' => 'Chiến dịch thử']
            );
            $this->fail('Phải chặn chốt đơn khi giá đã đổi.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertStringContainsString('vừa thay đổi', $e->getMessage());
        }
    }

    public function test_gia_khong_doi_thi_chot_don_binh_thuong(): void
    {
        $screen = $this->screenWithIoRate(1_000_000);
        $cart   = $this->cart();

        app(CartService::class)->addItem($cart, $screen->id, $this->dates(30));

        $campaign = app(CampaignService::class)->createFromCart(
            Organization::find($this->buyer->current_organization_id),
            $this->buyer,
            $cart->fresh(),
            ['name' => 'Chiến dịch thử']
        );

        $this->assertSame(1, $campaign->bookingLines()->count());
        $this->assertEquals(1_000_000, (float) $campaign->bookingLines()->first()->estimated_cost);
    }

    public function test_anh_chup_gia_duoc_ghi_lai_khi_them_vao_gio(): void
    {
        $screen = $this->screenWithIoRate(1_000_000);

        $item = app(CartService::class)->addItem($this->cart(), $screen->id, $this->dates(30));

        $this->assertNotNull($item->rate_captured_at);
        $this->assertSame('1000000.00', $item->rate_snapshot['io_rate']);
        $this->assertSame('month', $item->rate_snapshot['io_rate_unit']);
    }

    // ── Ràng buộc đầu vào ────────────────────────────────────────────────────

    public function test_ngay_ket_thuc_truoc_ngay_bat_dau_bi_tu_choi(): void
    {
        $screen = $this->screenWithIoRate(1_000_000);

        $this->postJson(route('buyer.cart.add'), [
            'screen_id'  => $screen->id,
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date'   => now()->addDays(10)->toDateString(),
        ])->assertStatus(422);
    }

    public function test_khoang_ngay_vuot_gioi_han_bi_tu_choi(): void
    {
        $screen = $this->screenWithIoRate(1_000_000);

        $this->postJson(route('buyer.cart.add'), [
            'screen_id'  => $screen->id,
            'start_date' => now()->addDays(7)->toDateString(),
            'end_date'   => now()->addDays(400)->toDateString(),
        ])->assertStatus(422);
    }

    public function test_client_khong_con_dat_duoc_screen_count(): void
    {
        $screen = $this->screenWithIoRate(1_000_000);

        $item = app(CartService::class)->addItem($this->cart(), $screen->id, $this->dates(30) + [
            'screen_count' => 50,   // cố tình gửi, phải bị bỏ qua
        ]);

        $this->assertSame(1, (int) $item->screen_count);
        $this->assertEquals(1_000_000, (float) $item->estimated_cost);
    }
}
