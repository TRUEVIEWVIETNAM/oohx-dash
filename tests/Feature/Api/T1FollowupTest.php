<?php

namespace Tests\Feature\Api;

use App\Models\Cart;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Product;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Hồi quy cho vòng follow-up review T1 của Codex.
 *
 * Bốn đường vượt quyền / sai số liệu mà 18 test trước KHÔNG bắt được:
 *  1. Tạo màn hình kèm giá (store không kiểm quyền giá).
 *  2. Vá inventory làm reset programmatic và tiền tệ qua giá trị mặc định.
 *  3. stats đếm theo tenant đang chọn thay vì owner trong URL.
 *  4. Mua lẻ một sản phẩm chỉ bán trọn gói, và các lỗ khác của cổng mua.
 */
class T1FollowupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'publisher', 'buyer'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function member(Owner $owner, string $role): User
    {
        $user = User::factory()->create(['current_owner_id' => $owner->id]);
        $user->assignRole('publisher');
        OwnerUser::create(['owner_id' => $owner->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function activeOwner(): Owner
    {
        return Owner::factory()->create(['status' => 'active']);
    }

    // ── 1. Tạo màn hình kèm giá ──────────────────────────────────────────────

    public function test_operator_khong_tao_duoc_man_hinh_kem_gia(): void
    {
        $owner = $this->activeOwner();
        $site  = Site::factory()->create(['owner_id' => $owner->id, 'external_id' => 'SITE-1']);

        Sanctum::actingAs($this->member($owner, 'operator'), ['manage']);

        $this->postJson('/api/v1/screens', [
            'site_external_id' => 'SITE-1',
            'external_id'      => 'SCR-NEW',
            'name'             => 'Màn hình mới',
            'inventory'        => ['floor_cpm' => 999000, 'programmatic_enabled' => true],
        ])->assertStatus(403);

        // Quyền phải chặn TRƯỚC khi ghi, không được tạo dở dang.
        $this->assertDatabaseMissing('screens', ['external_id' => 'SCR-NEW']);
        $this->assertSame(0, ScreenInventory::count());
    }

    public function test_manager_van_tao_duoc_man_hinh_kem_gia(): void
    {
        $owner = $this->activeOwner();
        Site::factory()->create(['owner_id' => $owner->id, 'external_id' => 'SITE-1']);

        Sanctum::actingAs($this->member($owner, 'manager'), ['manage']);

        $this->postJson('/api/v1/screens', [
            'site_external_id' => 'SITE-1',
            'external_id'      => 'SCR-OK',
            'name'             => 'Màn hình hợp lệ',
            'inventory'        => ['floor_cpm' => 500000],
        ])->assertStatus(201);

        $this->assertDatabaseHas('screens', ['external_id' => 'SCR-OK']);
    }

    public function test_operator_van_tao_duoc_man_hinh_khong_kem_gia(): void
    {
        $owner = $this->activeOwner();
        Site::factory()->create(['owner_id' => $owner->id, 'external_id' => 'SITE-1']);

        Sanctum::actingAs($this->member($owner, 'operator'), ['manage']);

        $this->postJson('/api/v1/screens', [
            'site_external_id' => 'SITE-1',
            'external_id'      => 'SCR-NOPRICE',
            'name'             => 'Màn hình không giá',
        ])->assertStatus(201);
    }

    // ── 2. Vá inventory không được đụng tới giá ──────────────────────────────

    public function test_operator_va_inventory_khong_lam_reset_programmatic_va_tien_te(): void
    {
        $owner  = $this->activeOwner();
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id]);

        ScreenInventory::create([
            'screen_id'            => $screen->id,
            'floor_cpm'            => 300000,
            'floor_cpm_currency'   => 'USD',
            'programmatic_enabled' => true,
            'spot_length'          => 15,
            'weekly_impressions'   => 100,
        ]);

        Sanctum::actingAs($this->member($owner, 'operator'), ['manage']);

        // Payload không chạm trường giá nào ⇒ được phép.
        $this->putJson("/api/v1/screens/{$screen->id}", [
            'inventory' => ['weekly_impressions' => 5000],
        ])->assertOk();

        $inv = ScreenInventory::where('screen_id', $screen->id)->first();

        $this->assertSame(5000, (int) $inv->weekly_impressions, 'Trường được gửi phải được ghi.');
        $this->assertTrue((bool) $inv->programmatic_enabled, 'Không được tắt programmatic qua giá trị mặc định.');
        $this->assertSame('USD', $inv->floor_cpm_currency, 'Không được reset tiền tệ về VND.');
        $this->assertEquals(300000, (float) $inv->floor_cpm, 'Giá sàn phải giữ nguyên.');
    }

    // ── 3. stats theo owner trong URL ────────────────────────────────────────

    public function test_stats_dem_theo_owner_trong_url_khong_theo_tenant_dang_chon(): void
    {
        $ownerA = $this->activeOwner();
        $ownerB = $this->activeOwner();

        $siteB = Site::factory()->create(['owner_id' => $ownerB->id]);
        Screen::factory()->count(3)->create(['owner_id' => $ownerB->id, 'site_id' => $siteB->id]);

        // Thành viên của cả A và B, đang chọn A.
        $user = $this->member($ownerA, 'owner');
        OwnerUser::create(['owner_id' => $ownerB->id, 'user_id' => $user->id, 'role' => 'owner']);

        Sanctum::actingAs($user, ['manage']);

        $this->getJson("/api/v1/owners/{$ownerB->id}/stats")
            ->assertOk()
            ->assertJsonPath('total_screens', 3);
    }

    // ── 4. Cổng mua sản phẩm ─────────────────────────────────────────────────

    private function buyer(): User
    {
        $org  = Organization::factory()->create(['status' => 'active']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $user->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $user->id,
            'role'            => 'admin',
        ]);

        return $user;
    }

    private function productWithScreens(Owner $owner, int $count, array $attrs = []): array
    {
        $site    = Site::factory()->create(['owner_id' => $owner->id]);
        $screens = Screen::factory()->count($count)
            ->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        $product = Product::create(array_merge([
            'owner_id'     => $owner->id,
            'name'         => 'Gói thử nghiệm',
            'slug'         => 'goi-thu-nghiem-' . uniqid(),
            'type'         => 'package',
            'category'     => 'billboard',
            'listing_mode' => 'both',
            'floor_price'  => 10_000_000,
            'currency'     => 'VND',
            'price_unit'   => 'month',
            'status'       => 'active',
        ], $attrs));

        $product->screens()->attach($screens->pluck('id'));

        return [$product, $screens];
    }

    private function addProduct(User $user, Product $product, array $data): void
    {
        $cart = Cart::create([
            'user_id'         => $user->id,
            'organization_id' => $user->current_organization_id,
            'status'          => 'active',
            'name'            => 'My Plan',
        ]);

        app(CartService::class)->addProduct($cart, $product->id, $data);
    }

    public function test_khong_mua_le_duoc_san_pham_chi_ban_tron_goi(): void
    {
        [$product, $screens] = $this->productWithScreens($this->activeOwner(), 3, [
            'listing_mode' => 'package_only',
        ]);

        $this->actingAs($user = $this->buyer());

        $this->expectException(HttpException::class);
        $this->addProduct($user, $product, [
            'buy_mode'            => 'individual',
            'selected_screen_ids' => [$screens->first()->id],
        ]);
    }

    public function test_khong_chon_duoc_man_hinh_ngoai_san_pham(): void
    {
        $owner = $this->activeOwner();
        [$product] = $this->productWithScreens($owner, 2);

        $outsiderSite   = Site::factory()->create(['owner_id' => $owner->id]);
        $outsiderScreen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $outsiderSite->id]);

        $this->actingAs($user = $this->buyer());

        $this->expectException(HttpException::class);
        $this->addProduct($user, $product, [
            'buy_mode'            => 'individual',
            'selected_screen_ids' => [$outsiderScreen->id],
        ]);
    }

    public function test_khong_mua_duoc_man_hinh_dang_tat_trong_san_pham(): void
    {
        [$product, $screens] = $this->productWithScreens($this->activeOwner(), 2);
        $screens->first()->update(['active' => false]);

        $this->actingAs($user = $this->buyer());

        $this->expectException(HttpException::class);
        $this->addProduct($user, $product, [
            'buy_mode'            => 'individual',
            'selected_screen_ids' => [$screens->first()->id],
        ]);
    }

    public function test_nguoi_vua_la_publisher_vua_la_buyer_van_mua_duoc_goi_cua_owner_khac(): void
    {
        $sellerOwner = $this->activeOwner();
        [$product, $screens] = $this->productWithScreens($sellerOwner, 3);

        // Người mua đồng thời là publisher của một owner khác — trước đây owner_scope
        // lọc mất màn hình của gói và cart nhận screen_id null.
        $otherOwner = $this->activeOwner();
        $user       = $this->buyer();
        $org        = $user->current_organization_id;
        OwnerUser::create(['owner_id' => $otherOwner->id, 'user_id' => $user->id, 'role' => 'owner']);
        $user->update(['current_owner_id' => $otherOwner->id, 'current_organization_id' => $org]);

        $this->actingAs($user);

        $cart = Cart::create([
            'user_id'         => $user->id,
            'organization_id' => $org,
            'status'          => 'active',
            'name'            => 'My Plan',
        ]);

        $item = app(CartService::class)->addProduct($cart, $product->id, ['buy_mode' => 'package']);

        $this->assertNotNull($item->screen_id, 'Gói phải gắn được màn hình, không để null.');
        $this->assertTrue(
            $screens->pluck('id')->contains($item->screen_id),
            'Màn hình gắn vào giỏ phải thuộc gói.'
        );
    }

    public function test_mua_le_ghi_dung_tap_man_hinh_da_kiem(): void
    {
        [$product, $screens] = $this->productWithScreens($this->activeOwner(), 3);
        $chosen = $screens->take(2)->pluck('id')->all();

        $this->actingAs($user = $this->buyer());

        $cart = Cart::create([
            'user_id'         => $user->id,
            'organization_id' => $user->current_organization_id,
            'status'          => 'active',
            'name'            => 'My Plan',
        ]);

        $item = app(CartService::class)->addProduct($cart, $product->id, [
            'buy_mode'            => 'individual',
            'selected_screen_ids' => $chosen,
        ]);

        $this->assertEqualsCanonicalizing($chosen, $item->selected_screen_ids);
        $this->assertSame(2, (int) $item->quantity);
    }
}
