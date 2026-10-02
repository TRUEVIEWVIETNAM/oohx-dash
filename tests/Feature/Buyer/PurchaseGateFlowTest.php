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
 * T3 — cổng bán hàng phải chặn ở MỌI chuyển trạng thái, không chỉ lúc thêm giỏ.
 *
 * Giữa lúc bỏ vào giỏ và lúc gửi booking có thể cách nhau nhiều ngày, đủ để owner
 * bị tạm ngưng hoặc màn hình bị gỡ bán (audit F10, Codex R05).
 */
class PurchaseGateFlowTest extends TestCase
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

    private function sellableScreen(): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenInventory::create([
            'screen_id'     => $screen->id,
            'pricing_model' => 'io',
            'io_rate'       => 1_000_000,
            'io_rate_unit'  => 'month',
            'spot_length'   => 15,
        ]);

        return $screen->fresh('inventory');
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
        $start = now()->addYears(1)->startOfYear();

        return [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
        ];
    }

    // ── Sửa dòng giỏ ─────────────────────────────────────────────────────────

    public function test_sua_dong_gio_sau_khi_owner_bi_tam_ngung_thi_bi_chan(): void
    {
        $screen = $this->sellableScreen();
        $item   = app(CartService::class)->addItem($this->cart(), $screen->id, $this->dates());

        $screen->owner->update(['status' => 'suspended']);

        $this->expectException(HttpException::class);
        app(CartService::class)->updateItem($item, ['share_of_voice_pct' => 50]);
    }

    // ── Chốt đơn ─────────────────────────────────────────────────────────────

    public function test_chot_don_sau_khi_man_hinh_bi_tat_thi_bi_chan(): void
    {
        $screen = $this->sellableScreen();
        $cart   = $this->cart();
        app(CartService::class)->addItem($cart, $screen->id, $this->dates());

        $screen->update(['active' => false]);

        try {
            app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);
            $this->fail('Phải chặn chốt đơn khi màn hình đã bị gỡ bán.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, \App\Models\Campaign::count(), 'Không được tạo chiến dịch dở dang.');
    }

    // ── Gửi booking ──────────────────────────────────────────────────────────

    public function test_gui_booking_sau_khi_owner_bi_tam_ngung_thi_bi_chan(): void
    {
        $screen = $this->sellableScreen();
        $cart   = $this->cart();
        app(CartService::class)->addItem($cart, $screen->id, $this->dates());

        $campaign = app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);

        // Owner bị tạm ngưng SAU khi tạo nháp, trước khi gửi.
        $screen->owner->update(['status' => 'suspended']);

        try {
            app(CampaignService::class)->submit($campaign->fresh(), $this->buyer);
            $this->fail('Phải chặn gửi booking khi owner đã bị tạm ngưng.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertTrue($campaign->fresh()->isDraft(), 'Chiến dịch phải giữ nguyên trạng thái nháp.');
    }

    public function test_luong_binh_thuong_van_chay_tron_ven(): void
    {
        $screen = $this->sellableScreen();
        $cart   = $this->cart();
        app(CartService::class)->addItem($cart, $screen->id, $this->dates());

        $campaign = app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);
        $campaign = app(CampaignService::class)->submit($campaign, $this->buyer);

        $this->assertSame('pending_approval', $campaign->status);
        $this->assertSame(1, $campaign->bookingLines()->count());
    }
}
