<?php

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phân quyền cho API quản lý (audit 23/09, F01 + Codex R02).
 *
 * Trước sửa: nhóm route CRUD chỉ có auth:sanctum. Bất kỳ ai có token đều đọc,
 * sửa, xoá được media owner của người khác; token đối tác chỉ được cấp quyền đọc
 * inventory cũng ghi được; và người mua (không có tenant) đọc được toàn bộ màn
 * hình kèm giá sàn vì owner scope tự tắt khi không có current_owner_id.
 */
class ApiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Owner $ownerA;
    private Owner $ownerB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'publisher', 'buyer'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ownerA = Owner::factory()->create(['status' => 'active', 'revenue_share_pct' => 70]);
        $this->ownerB = Owner::factory()->create(['status' => 'active', 'revenue_share_pct' => 65]);
    }

    // ── Người dùng mẫu ───────────────────────────────────────────────────────

    private function publisherOf(Owner $owner, string $role = 'owner'): User
    {
        $user = User::factory()->create(['current_owner_id' => $owner->id]);
        $user->assignRole('publisher');
        OwnerUser::create(['owner_id' => $owner->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

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

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    private function screenOf(Owner $owner): Screen
    {
        $site = Site::factory()->create(['owner_id' => $owner->id]);

        return Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id]);
    }

    /** Đăng nhập bằng token có ability chỉ định. */
    private function actingWithAbilities(User $user, array $abilities): void
    {
        Sanctum::actingAs($user, $abilities);
    }

    // ── Token đối tác: đọc được, ghi thì không ───────────────────────────────

    public function test_token_chi_co_quyen_doc_khong_ghi_duoc_man_hinh(): void
    {
        $screen = $this->screenOf($this->ownerA);
        $this->actingWithAbilities($this->publisherOf($this->ownerA), ['inventory']);

        $this->putJson("/api/v1/screens/{$screen->id}", ['name' => 'Tên bị đổi'])
            ->assertStatus(403);

        $this->assertSame($screen->name, $screen->fresh()->name);
    }

    public function test_api_client_khong_vao_duoc_nhom_quan_ly(): void
    {
        $client = ApiClient::create([
            'client_id'     => 'partner-test',
            'client_secret' => bcrypt('secret'),
            'name'          => 'Đối tác thử nghiệm',
            'scopes'        => ['inventory'],
            'active'        => true,
        ]);

        // ApiClient không phải Authenticatable nên không dùng Sanctum::actingAs được;
        // phát token thật rồi gọi như đối tác vẫn gọi.
        $token = $client->createToken('test', ['inventory'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/owners')->assertStatus(403);
    }

    // ── Cách tenant ──────────────────────────────────────────────────────────

    public function test_publisher_khong_sua_duoc_man_hinh_cua_owner_khac(): void
    {
        $screenB = $this->screenOf($this->ownerB);
        $this->actingWithAbilities($this->publisherOf($this->ownerA), ['manage']);

        $response = $this->putJson("/api/v1/screens/{$screenB->id}", ['name' => 'Chiếm quyền']);

        $this->assertContains($response->status(), [403, 404], 'Phải bị chặn, không được cho sửa.');
        $this->assertSame($screenB->name, $screenB->fresh()->name);
    }

    public function test_publisher_khong_tao_duoc_du_lieu_cho_owner_khac(): void
    {
        $this->actingWithAbilities($this->publisherOf($this->ownerA), ['manage']);

        $this->postJson('/api/v1/sites', [
            'owner_id'    => $this->ownerB->id,   // cố tình chỉ định owner khác
            'external_id' => 'SITE-X',
            'name'        => 'Địa điểm lạ',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('sites', ['external_id' => 'SITE-X']);
    }

    public function test_site_duoc_tao_cho_dung_owner_cua_nguoi_goi(): void
    {
        $this->actingWithAbilities($this->publisherOf($this->ownerA), ['manage']);

        $this->postJson('/api/v1/sites', [
            'external_id' => 'SITE-OK',
            'name'        => 'Địa điểm hợp lệ',
        ])->assertStatus(201);

        $this->assertDatabaseHas('sites', [
            'external_id' => 'SITE-OK',
            'owner_id'    => $this->ownerA->id,
        ]);
    }

    // ── Người mua không có tenant ────────────────────────────────────────────

    public function test_nguoi_mua_khong_liet_ke_duoc_man_hinh_qua_api_quan_ly(): void
    {
        $this->screenOf($this->ownerA);
        $this->screenOf($this->ownerB);

        $this->actingWithAbilities($this->buyer(), ['manage']);

        // Dù token có ability 'manage', policy vẫn chặn vì không thuộc media owner nào.
        $this->getJson('/api/v1/screens')->assertStatus(403);
    }

    public function test_scope_chan_mac_dinh_khi_thanh_vien_mat_tenant_dang_chon(): void
    {
        $this->screenOf($this->ownerA);
        $this->screenOf($this->ownerB);

        // Thành viên của owner A nhưng context bị bỏ trống (bị thu hồi, phiên cũ).
        $user = $this->publisherOf($this->ownerA);
        $user->update(['current_owner_id' => null]);

        $this->actingAs($user);

        $this->assertSame(
            0,
            Screen::count(),
            'Không có tenant đang chọn thì không được thấy màn hình nào.'
        );
    }

    // ── Trường nhạy cảm ──────────────────────────────────────────────────────

    public function test_api_owner_khong_lo_truong_tai_chinh_cho_nguoi_thuong(): void
    {
        $this->actingWithAbilities($this->publisherOf($this->ownerA), ['manage']);

        $response = $this->getJson('/api/v1/owners')->assertOk();

        $body = $response->getContent();
        foreach (['revenue_share_pct', 'billing_info', 'bank_account_number', 'tax_code'] as $field) {
            $this->assertStringNotContainsString($field, $body, "Không được lộ {$field}");
        }
    }

    public function test_super_admin_van_xem_duoc_truong_noi_bo(): void
    {
        $this->actingWithAbilities($this->superAdmin(), ['manage']);

        $this->getJson("/api/v1/owners/{$this->ownerA->id}")
            ->assertOk()
            ->assertJsonPath('data.internal.revenue_share_pct', fn ($v) => $v !== null);
    }

    public function test_danh_sach_owner_chi_gom_owner_minh_la_thanh_vien(): void
    {
        $this->actingWithAbilities($this->publisherOf($this->ownerA), ['manage']);

        $this->getJson('/api/v1/owners')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->ownerA->id);
    }

    // ── Hồi quy cho super_admin ──────────────────────────────────────────────

    public function test_super_admin_van_sua_duoc_moi_owner(): void
    {
        $this->actingWithAbilities($this->superAdmin(), ['manage']);

        $this->putJson("/api/v1/owners/{$this->ownerB->id}", ['status' => 'suspended'])
            ->assertOk();

        $this->assertSame('suspended', $this->ownerB->fresh()->status);
    }
}
