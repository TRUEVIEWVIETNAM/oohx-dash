<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\User;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cấm cross-panel login giữa /admin, /publisher, /buyer.
 *
 * Trước fix: User không implement FilamentUser → Filament cho mọi authenticated
 * user vào TẤT CẢ panel. Publisher login được /admin, super_admin login được
 * /publisher.
 *
 * Sau fix: User implements FilamentUser + canAccessPanel() check kép
 * (Spatie role AND tenant membership active).
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin', 'publisher', 'buyer'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    // ── Regression: contract implemented ─────────────────────────────────────

    public function test_user_model_implements_filament_user_contract(): void
    {
        $this->assertInstanceOf(
            FilamentUser::class,
            new User(),
            'User phải implement FilamentUser, nếu không canAccessPanel() bị Filament ignore.'
        );
    }

    // ── /admin ────────────────────────────────────────────────────────────────

    public function test_super_admin_can_access_admin_panel(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $this->assertTrue($this->canAccess($user, 'admin'));
    }

    public function test_publisher_cannot_access_admin_panel(): void
    {
        $user = $this->makePublisherWithActiveOwner();

        $this->assertFalse($this->canAccess($user, 'admin'));
    }

    public function test_buyer_cannot_access_admin_panel(): void
    {
        $user = $this->makeBuyerWithActiveOrg();

        $this->assertFalse($this->canAccess($user, 'admin'));
    }

    // ── /publisher ───────────────────────────────────────────────────────────

    public function test_publisher_with_active_owner_can_access_publisher_panel(): void
    {
        $user = $this->makePublisherWithActiveOwner();

        $this->assertTrue($this->canAccess($user, 'publisher'));
    }

    public function test_publisher_without_owner_record_cannot_access_publisher_panel(): void
    {
        $user = User::factory()->create();
        $user->assignRole('publisher');

        $this->assertFalse($this->canAccess($user, 'publisher'));
    }

    public function test_publisher_with_inactive_owner_cannot_access_publisher_panel(): void
    {
        $owner = Owner::factory()->create(['status' => 'suspended']);
        $user  = User::factory()->create();
        $user->assignRole('publisher');
        OwnerUser::create([
            'owner_id' => $owner->id,
            'user_id'  => $user->id,
            'role'     => 'owner',
        ]);

        $this->assertFalse($this->canAccess($user, 'publisher'));
    }

    public function test_super_admin_cannot_access_publisher_panel(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        // Cả khi có owner_users record, super_admin vẫn không vào /publisher —
        // strict separation. Muốn impersonate phải dùng switch-identity flow riêng.
        $owner = Owner::factory()->create(['status' => 'active']);
        OwnerUser::create([
            'owner_id' => $owner->id,
            'user_id'  => $user->id,
            'role'     => 'owner',
        ]);

        $this->assertFalse($this->canAccess($user, 'publisher'));
    }

    public function test_buyer_cannot_access_publisher_panel(): void
    {
        $user = $this->makeBuyerWithActiveOrg();

        $this->assertFalse($this->canAccess($user, 'publisher'));
    }

    // ── /buyer ───────────────────────────────────────────────────────────────

    public function test_buyer_with_active_org_can_access_buyer_panel(): void
    {
        $user = $this->makeBuyerWithActiveOrg();

        $this->assertTrue($this->canAccess($user, 'buyer'));
    }

    public function test_buyer_without_org_record_cannot_access_buyer_panel(): void
    {
        $user = User::factory()->create();
        $user->assignRole('buyer');

        $this->assertFalse($this->canAccess($user, 'buyer'));
    }

    public function test_publisher_cannot_access_buyer_panel(): void
    {
        $user = $this->makePublisherWithActiveOwner();

        $this->assertFalse($this->canAccess($user, 'buyer'));
    }

    // ── No role / unknown panel ──────────────────────────────────────────────

    public function test_user_with_no_role_cannot_access_any_panel(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->canAccess($user, 'admin'));
        $this->assertFalse($this->canAccess($user, 'publisher'));
        $this->assertFalse($this->canAccess($user, 'buyer'));
    }

    public function test_unknown_panel_id_always_denied(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $this->assertFalse($this->canAccess($user, 'something-else'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makePublisherWithActiveOwner(): User
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $user  = User::factory()->create();
        $user->assignRole('publisher');
        OwnerUser::create([
            'owner_id' => $owner->id,
            'user_id'  => $user->id,
            'role'     => 'owner',
        ]);

        return $user;
    }

    private function makeBuyerWithActiveOrg(): User
    {
        $org  = Organization::factory()->create(['status' => 'active']);
        $user = User::factory()->create();
        $user->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $user->id,
            'role'            => 'admin',
        ]);

        return $user;
    }

    private function canAccess(User $user, string $panelId): bool
    {
        // Build a minimal Panel with the desired id — không phụ thuộc vào
        // discovery của 3 PanelProvider để test cô lập, deterministic.
        $panel = (new Panel())->id($panelId);
        return $user->canAccessPanel($panel);
    }
}
