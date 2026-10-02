<?php

namespace Tests\Feature\Buyer;

use App\Models\Cart;
use App\Models\InventoryHold;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\CampaignService;
use App\Services\CartService;
use App\Services\InventoryHoldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Giai đoạn 1.1 — giữ chỗ phải nguyên tử và có hạn.
 *
 * Trước sửa: `AvailabilityService` chỉ đọc rồi so sánh. Hai người mua cùng một
 * suất, chạy cùng lúc, thì cả hai đều thấy còn trống và cả hai đều ghi được —
 * không có gì chặn ai (audit F-02 / Codex F05).
 *
 * Bằng chứng khóa thật nằm ở `HoldLockingTest`: test này chạy một luồng nên chỉ
 * chứng minh được phép tính sức chứa, không chứng minh được chuyện xếp hàng.
 */
class InventoryHoldTest extends TestCase
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
        $this->buyer = $this->makeBuyer();
        $this->actingAs($this->buyer);
    }

    private function makeBuyer(): User
    {
        $user = User::factory()->create(['current_organization_id' => $this->org->id]);
        $user->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $user->id,
            'role'            => 'admin',
        ]);

        return $user;
    }

    private function screen(int $maxSov = 100): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'spot_length'            => 15,
            'share_of_voice_max_pct' => $maxSov,
        ]);

        return $screen->fresh('inventory');
    }

    private function cartFor(User $user): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $user->id, 'status' => 'active'],
            ['organization_id' => $this->org->id, 'name' => 'Plan ' . $user->id]
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

    // ── Giữ chỗ được tạo và trừ vào kho ──────────────────────────────────────

    public function test_bo_vao_gio_la_giu_cho_ngay(): void
    {
        $screen = $this->screen();
        $item   = app(CartService::class)->addItem($this->cartFor($this->buyer), $screen->id, $this->dates() + ['share_of_voice_pct' => 40]);

        $hold = InventoryHold::where('cart_item_id', $item->id)->firstOrFail();

        $this->assertSame($screen->id, $hold->screen_id);
        $this->assertSame(40, $hold->sov_pct);
        $this->assertSame(InventoryHold::STATUS_ACTIVE, $hold->status);
        $this->assertNotNull($hold->expires_at, 'Giữ chỗ trong giỏ phải có hạn, nếu không một giỏ bỏ dở giam kho vĩnh viễn.');
    }

    public function test_trang_con_trong_tru_ca_suat_dang_co_nguoi_giu(): void
    {
        $screen = $this->screen();
        $dates  = $this->dates();

        app(CartService::class)->addItem($this->cartFor($this->buyer), $screen->id, $dates + ['share_of_voice_pct' => 60]);

        $remaining = app(AvailabilityService::class)
            ->getRemainingSOV($screen->id, $dates['start_date'], $dates['end_date']);

        $this->assertSame(40, $remaining, 'Còn trống phải trừ suất người khác đang giữ, không chỉ trừ đơn đã chốt.');
    }

    public function test_nguoi_thu_hai_khong_lay_duoc_suat_da_co_nguoi_giu(): void
    {
        $screen = $this->screen();
        $dates  = $this->dates();

        app(CartService::class)->addItem($this->cartFor($this->buyer), $screen->id, $dates + ['share_of_voice_pct' => 70]);

        $other = $this->makeBuyer();
        $this->actingAs($other);

        try {
            app(CartService::class)->addItem($this->cartFor($other), $screen->id, $dates + ['share_of_voice_pct' => 70]);
            $this->fail('Người thứ hai không được lấy suất đã có người giữ.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('30%', $e->getMessage(), 'Thông báo phải nói rõ còn bao nhiêu.');
        }

        $this->assertSame(1, InventoryHold::effective()->count());
    }

    public function test_hai_nguoi_cong_lai_vua_du_thi_ca_hai_deu_duoc(): void
    {
        $screen = $this->screen();
        $dates  = $this->dates();

        app(CartService::class)->addItem($this->cartFor($this->buyer), $screen->id, $dates + ['share_of_voice_pct' => 60]);

        $other = $this->makeBuyer();
        $this->actingAs($other);
        app(CartService::class)->addItem($this->cartFor($other), $screen->id, $dates + ['share_of_voice_pct' => 40]);

        $this->assertSame(2, InventoryHold::effective()->count());
        $this->assertSame(0, app(AvailabilityService::class)->getRemainingSOV($screen->id, $dates['start_date'], $dates['end_date']));
    }

    public function test_khoang_ngay_khong_giao_nhau_thi_khong_tranh_nhau(): void
    {
        $screen = $this->screen();
        $start  = now()->addYear()->startOfYear();

        app(CartService::class)->addItem($this->cartFor($this->buyer), $screen->id, [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
            'share_of_voice_pct' => 100,
        ]);

        $other = $this->makeBuyer();
        $this->actingAs($other);

        // Tháng sau: cùng màn hình nhưng không chồng ngày.
        $item = app(CartService::class)->addItem($this->cartFor($other), $screen->id, [
            'start_date' => $start->copy()->addMonthNoOverflow()->toDateString(),
            'end_date'   => $start->copy()->addMonthsNoOverflow(2)->subDay()->toDateString(),
            'share_of_voice_pct' => 100,
        ]);

        $this->assertNotNull($item->id);
        $this->assertSame(2, InventoryHold::effective()->count());
    }

    // ── Hết hạn thì nhả kho ──────────────────────────────────────────────────

    public function test_giu_cho_het_han_khong_con_chan_ai(): void
    {
        $screen = $this->screen();
        $dates  = $this->dates();

        $item = app(CartService::class)->addItem($this->cartFor($this->buyer), $screen->id, $dates + ['share_of_voice_pct' => 100]);

        // Giả lập giỏ bị bỏ dở quá hạn.
        InventoryHold::where('cart_item_id', $item->id)->update(['expires_at' => now()->subMinute()]);

        $other = $this->makeBuyer();
        $this->actingAs($other);

        $newItem = app(CartService::class)->addItem($this->cartFor($other), $screen->id, $dates + ['share_of_voice_pct' => 100]);

        $this->assertNotNull($newItem->id, 'Giữ chỗ quá hạn phải tự mất tác dụng, không chờ job dọn.');
    }

    public function test_lenh_don_danh_dau_giu_cho_het_han_la_da_nha(): void
    {
        $screen = $this->screen();
        $item   = app(CartService::class)->addItem($this->cartFor($this->buyer), $screen->id, $this->dates());

        InventoryHold::where('cart_item_id', $item->id)->update(['expires_at' => now()->subMinute()]);

        $this->artisan('inventory:purge-holds')->assertSuccessful();

        $this->assertSame(
            InventoryHold::STATUS_RELEASED,
            InventoryHold::where('cart_item_id', $item->id)->value('status')
        );
    }

    public function test_bo_khoi_gio_thi_nha_suat_ngay(): void
    {
        $screen = $this->screen();
        $dates  = $this->dates();

        $item = app(CartService::class)->addItem($this->cartFor($this->buyer), $screen->id, $dates + ['share_of_voice_pct' => 100]);
        app(CartService::class)->removeItem($item);

        $this->assertSame(100, app(AvailabilityService::class)->getRemainingSOV($screen->id, $dates['start_date'], $dates['end_date']));
    }

    // ── Sửa giỏ ──────────────────────────────────────────────────────────────

    public function test_nguoi_mua_khong_tu_chan_chinh_minh_khi_sua_gio(): void
    {
        $screen = $this->screen();
        $item   = app(CartService::class)->addItem($this->cartFor($this->buyer), $screen->id, $this->dates() + ['share_of_voice_pct' => 100]);

        // Vẫn 100% trên cùng khoảng ngày: giữ chỗ cũ của chính mình không được
        // tính là chướng ngại.
        $updated = app(CartService::class)->updateItem($item, ['share_of_voice_pct' => 100]);

        $this->assertSame(100, (int) $updated->share_of_voice_pct);
        $this->assertSame(1, InventoryHold::effective()->count(), 'Sửa giỏ không được để lại giữ chỗ mồ côi.');
    }

    public function test_doi_sang_khoang_ngay_da_co_nguoi_lay_thi_bi_chan_va_gio_khong_doi(): void
    {
        $screen = $this->screen();
        $start  = now()->addYear()->startOfYear();

        $thisMonth = [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
        ];
        $nextMonth = [
            'start_date' => $start->copy()->addMonthNoOverflow()->toDateString(),
            'end_date'   => $start->copy()->addMonthsNoOverflow(2)->subDay()->toDateString(),
        ];

        $item = app(CartService::class)->addItem($this->cartFor($this->buyer), $screen->id, $thisMonth + ['share_of_voice_pct' => 100]);

        // Người khác lấy trọn tháng sau.
        $other = $this->makeBuyer();
        $this->actingAs($other);
        app(CartService::class)->addItem($this->cartFor($other), $screen->id, $nextMonth + ['share_of_voice_pct' => 100]);

        $this->actingAs($this->buyer);

        try {
            app(CartService::class)->updateItem($item, $nextMonth);
            $this->fail('Phải chặn khi đổi sang khoảng ngày đã có người lấy.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(
            $thisMonth['start_date'],
            $item->fresh()->start_date->toDateString(),
            'Chặn giữa đường thì dòng giỏ phải giữ nguyên, không được đổi ngày một nửa.'
        );
    }

    // ── Chốt đơn ─────────────────────────────────────────────────────────────

    public function test_chot_don_bien_giu_cho_thanh_suat_vinh_vien(): void
    {
        $screen = $this->screen();
        $cart   = $this->cartFor($this->buyer);
        app(CartService::class)->addItem($cart, $screen->id, $this->dates() + ['share_of_voice_pct' => 80]);

        $campaign = app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);
        $line     = $campaign->bookingLines()->firstOrFail();

        $hold = InventoryHold::where('booking_line_id', $line->id)->firstOrFail();

        $this->assertSame(InventoryHold::STATUS_CONSUMED, $hold->status);
        $this->assertNull($hold->expires_at, 'Suất đã chốt thì không hết hạn nữa.');
    }

    public function test_khong_cong_hai_lan_sau_khi_chot_don(): void
    {
        $screen = $this->screen();
        $dates  = $this->dates();
        $cart   = $this->cartFor($this->buyer);
        app(CartService::class)->addItem($cart, $screen->id, $dates + ['share_of_voice_pct' => 60]);

        app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);

        // 60% đã chốt: còn 40, không phải 100 cũng không phải -20 vì đếm hai lần.
        $this->assertSame(
            40,
            app(AvailabilityService::class)->getRemainingSOV($screen->id, $dates['start_date'], $dates['end_date'])
        );
    }

    public function test_giu_cho_het_han_truoc_khi_chot_va_nguoi_khac_da_lay_thi_chan_ca_don(): void
    {
        $screen = $this->screen();
        $dates  = $this->dates();
        $cart   = $this->cartFor($this->buyer);

        $item = app(CartService::class)->addItem($cart, $screen->id, $dates + ['share_of_voice_pct' => 100]);

        // Giỏ nằm quá hạn, người khác lấy trọn suất.
        InventoryHold::where('cart_item_id', $item->id)->update(['expires_at' => now()->subMinute()]);
        $other = $this->makeBuyer();
        $this->actingAs($other);
        app(CartService::class)->addItem($this->cartFor($other), $screen->id, $dates + ['share_of_voice_pct' => 100]);

        $this->actingAs($this->buyer);

        try {
            app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);
            $this->fail('Giữ chỗ đã hết hạn và suất đã có người khác lấy thì không được chốt đơn.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, \App\Models\Campaign::count(), 'Không được để lại chiến dịch dở dang.');
    }

    public function test_mua_goi_giu_cho_tren_moi_man_hinh(): void
    {
        $a = $this->screen();
        $b = $this->screen();

        $product = \App\Models\Product::create([
            'owner_id'     => $a->owner_id,
            'name'         => 'Gói hai màn',
            'slug'         => 'goi-hai-man-' . \Illuminate\Support\Str::random(6),
            'type'         => 'package',
            'category'     => 'led',
            'listing_mode' => 'package_only',
            'floor_price'  => 4_000_000,
            'min_quantity' => 1,
            'total_units'  => 2,
            'status'       => 'active',
        ]);
        $product->screens()->attach([$a->id, $b->id]);

        app(CartService::class)->addProduct($this->cartFor($this->buyer), $product->id, $this->dates() + [
            'buy_mode'           => 'package',
            'share_of_voice_pct' => 100,
        ]);

        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            InventoryHold::effective()->pluck('screen_id')->all(),
            'Gói phải giữ chỗ trên mọi màn hình của nó, không chỉ màn hình đầu tiên.'
        );
    }

    public function test_remaining_sov_bo_qua_giu_cho_cua_chinh_minh(): void
    {
        $screen = $this->screen();
        $dates  = $this->dates();

        $item = app(CartService::class)->addItem($this->cartFor($this->buyer), $screen->id, $dates + ['share_of_voice_pct' => 100]);

        $remaining = app(InventoryHoldService::class)->remainingSov(
            $screen->fresh('inventory'),
            $dates['start_date'],
            $dates['end_date'],
            ignoreCartItemId: $item->id,
        );

        $this->assertSame(100, $remaining);
    }
}
