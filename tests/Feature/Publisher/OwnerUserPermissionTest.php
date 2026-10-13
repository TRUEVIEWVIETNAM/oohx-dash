<?php

namespace Tests\Feature\Publisher;

use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OwnerUserPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;
    private User $superAdmin;
    private User $tenantOwner;
    private User $manager;
    private User $readOnly;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'publisher', 'guard_name' => 'web']);

        $this->owner = Owner::factory()->create(['status' => 'active']);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super_admin');

        $this->tenantOwner = $this->makeMember('owner');
        $this->manager     = $this->makeMember('manager');
        $this->readOnly    = $this->makeMember('read_only');
    }

    private function makeMember(string $role): User
    {
        $user = User::factory()->create(['current_owner_id' => $this->owner->id]);
        $user->assignRole('publisher');

        OwnerUser::create([
            'owner_id' => $this->owner->id,
            'user_id'  => $user->id,
            'role'     => $role,
        ]);

        return $user;
    }

    public function test_assignable_roles_excludes_owner_for_non_super_admin(): void
    {
        $rolesForOwner = OwnerUser::assignableRolesFor($this->tenantOwner);
        $this->assertArrayNotHasKey('owner', $rolesForOwner);
        $this->assertArrayHasKey('manager', $rolesForOwner);
        $this->assertArrayHasKey('read_only', $rolesForOwner);
        $this->assertCount(5, $rolesForOwner);

        $rolesForSuperAdmin = OwnerUser::assignableRolesFor($this->superAdmin);
        $this->assertArrayHasKey('owner', $rolesForSuperAdmin);
        $this->assertCount(6, $rolesForSuperAdmin);
    }

    public function test_super_admin_can_create_owner_user(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(Gate::allows('create', OwnerUser::class));
    }

    public function test_tenant_owner_can_create_owner_user(): void
    {
        $this->actingAs($this->tenantOwner);
        $this->assertTrue(Gate::allows('create', OwnerUser::class));
    }

    public function test_manager_cannot_create_owner_user(): void
    {
        $this->actingAs($this->manager);
        $this->assertFalse(Gate::allows('create', OwnerUser::class));
    }

    public function test_read_only_cannot_view_team_list(): void
    {
        $this->actingAs($this->readOnly);
        $this->assertFalse(Gate::allows('viewAny', OwnerUser::class));
    }

    public function test_manager_cannot_view_team_list(): void
    {
        $this->actingAs($this->manager);
        $this->assertFalse(Gate::allows('viewAny', OwnerUser::class));
    }

    public function test_tenant_owner_can_view_team_list(): void
    {
        $this->actingAs($this->tenantOwner);
        $this->assertTrue(Gate::allows('viewAny', OwnerUser::class));
    }

    public function test_tenant_owner_cannot_update_a_record_with_role_owner(): void
    {
        $coOwner = $this->makeMember('owner');
        $coOwnerPivot = OwnerUser::where('user_id', $coOwner->id)->where('owner_id', $this->owner->id)->firstOrFail();

        $this->actingAs($this->tenantOwner);
        $this->assertFalse(Gate::allows('update', $coOwnerPivot));
        $this->assertFalse(Gate::allows('delete', $coOwnerPivot));
    }

    public function test_tenant_owner_can_update_manager_record(): void
    {
        $managerPivot = OwnerUser::where('user_id', $this->manager->id)->where('owner_id', $this->owner->id)->firstOrFail();

        $this->actingAs($this->tenantOwner);
        $this->assertTrue(Gate::allows('update', $managerPivot));
        $this->assertTrue(Gate::allows('delete', $managerPivot));
    }

    public function test_tenant_owner_cannot_update_self(): void
    {
        $selfPivot = OwnerUser::where('user_id', $this->tenantOwner->id)->where('owner_id', $this->owner->id)->firstOrFail();

        $this->actingAs($this->tenantOwner);
        $this->assertFalse(Gate::allows('update', $selfPivot));
        $this->assertFalse(Gate::allows('delete', $selfPivot));
    }

    public function test_super_admin_can_update_record_with_role_owner(): void
    {
        $coOwner = $this->makeMember('owner');
        $coOwnerPivot = OwnerUser::where('user_id', $coOwner->id)->where('owner_id', $this->owner->id)->firstOrFail();

        $this->actingAs($this->superAdmin);
        $this->assertTrue(Gate::allows('update', $coOwnerPivot));
        $this->assertTrue(Gate::allows('delete', $coOwnerPivot));
    }

    // ── Gom về `manage_users` (13/10/2026) ──────────────────────────────────

    /**
     * Phép gác đi theo `PERMISSIONS`, không theo tên vai trò.
     *
     * Bản trước so `actorRole(…) === 'owner'`, trong khi `PERMISSIONS` khai
     * `'manage_users' => ['owner']` và trang `PermissionMatrix` in bảng đó ra
     * cho người dùng đọc. Hai bộ luật, hôm nay đồng ý nhau **chỉ vì** danh
     * sách đúng có một phần tử.
     *
     * Ca này lấy kỳ vọng từ chính `PERMISSIONS` và đi qua **cả sáu** vai trò.
     * Giới hạn của nó, nói thẳng: hôm nay chỉ `owner` có `manage_users` và
     * cũng chỉ `owner` tên là `'owner'`, nên nó không phân biệt được hai cách
     * viết. Giá trị của nó là ngày ai đó cho `manager` quyền này — lúc đó nó
     * tự đòi đúng điều bảng quyền khai.
     */
    public function test_phep_gac_di_theo_bang_quyen_khong_theo_ten_vai_tro(): void
    {
        foreach (array_keys(OwnerUser::ROLE_LABELS) as $vaiTro) {
            $nguoi = $this->makeMember($vaiTro);
            $mong  = in_array($vaiTro, OwnerUser::PERMISSIONS['manage_users'], true);

            $this->actingAs($nguoi);

            $this->assertSame(
                $mong,
                Gate::allows('viewAny', OwnerUser::class),
                "viewAny của '{$vaiTro}' phải khớp manage_users trong PERMISSIONS",
            );
            $this->assertSame(
                $mong,
                Gate::allows('create', OwnerUser::class),
                "create của '{$vaiTro}' phải khớp manage_users trong PERMISSIONS",
            );
        }
    }

    /**
     * Media owner **tạm ngưng** không quản lý thành viên được — hành vi đổi.
     *
     * `actorRole()` cũ không kiểm trạng thái owner, trong khi
     * `TenantPermission` đã đòi điều đó từ vòng Codex T1/F2 — ở đó owner bị
     * tạm ngưng mà thành viên vẫn ghi được inventory qua API. Policy là lớp
     * cuối, và nó nên trả lời giống hai lớp trước nó (panel và menu).
     */
    public function test_owner_tam_ngung_thi_khong_quan_ly_duoc_thanh_vien(): void
    {
        $managerPivot = OwnerUser::where('user_id', $this->manager->id)
            ->where('owner_id', $this->owner->id)
            ->firstOrFail();

        $this->actingAs($this->tenantOwner);
        $this->assertTrue(Gate::allows('viewAny', OwnerUser::class));
        $this->assertTrue(Gate::allows('update', $managerPivot));

        $this->owner->update(['status' => 'suspended']);

        $this->actingAs($this->tenantOwner);
        $this->assertFalse(Gate::allows('viewAny', OwnerUser::class));
        $this->assertFalse(Gate::allows('create', OwnerUser::class));
        $this->assertFalse(Gate::allows('update', $managerPivot->fresh()));
        $this->assertFalse(Gate::allows('delete', $managerPivot->fresh()));
        $this->assertFalse(Gate::allows('view', $managerPivot->fresh()));
    }

    /**
     * Xem một bản ghi thành viên là quyền của **mọi** thành viên.
     *
     * Ranh giới này không đổi trong lần gom — `PERMISSIONS` không có quyền nào
     * cho việc "xem danh sách người", nên câu hỏi ở đây là tư cách thành viên.
     * Ca này tồn tại để `view` không bị siết thành "chỉ `manage_users`" một
     * cách vô tình: `viewAny` (danh sách) và `view` (một bản ghi) vạch ranh
     * giới ở hai chỗ khác nhau.
     */
    public function test_moi_thanh_vien_xem_duoc_ban_ghi_thanh_vien(): void
    {
        $ownerPivot = OwnerUser::where('user_id', $this->tenantOwner->id)
            ->where('owner_id', $this->owner->id)
            ->firstOrFail();

        foreach ([$this->manager, $this->readOnly] as $nguoi) {
            $this->actingAs($nguoi);
            $this->assertTrue(Gate::allows('view', $ownerPivot));
        }

        $nguoiNgoai = User::factory()->create();
        $nguoiNgoai->assignRole('publisher');
        $this->actingAs($nguoiNgoai);
        $this->assertFalse(Gate::allows('view', $ownerPivot));
    }

    /**
     * `manager` không đổi và không gỡ được thành viên.
     *
     * Bộ ca cũ canh `viewAny`/`create` cho `manager` nhưng bỏ trống hai đường
     * **ghi**, và chúng đi qua một nhánh mã khác (`duocChamVao()`).
     */
    public function test_manager_khong_doi_va_khong_go_duoc_thanh_vien(): void
    {
        $readOnlyPivot = OwnerUser::where('user_id', $this->readOnly->id)
            ->where('owner_id', $this->owner->id)
            ->firstOrFail();

        $this->actingAs($this->manager);
        $this->assertFalse(Gate::allows('update', $readOnlyPivot));
        $this->assertFalse(Gate::allows('delete', $readOnlyPivot));
    }

    /**
     * Chủ sở hữu của owner KHÁC không chạm được, dù họ là `owner` ở chỗ họ.
     *
     * `update`/`delete` kiểm theo `$target->owner_id`, không theo
     * `current_owner_id` — cột sau là đầu vào client đổi được.
     */
    public function test_owner_cua_media_owner_khac_khong_cham_duoc(): void
    {
        $ownerKhac = Owner::factory()->create(['status' => 'active']);
        $nguoi     = User::factory()->create(['current_owner_id' => $ownerKhac->id]);
        $nguoi->assignRole('publisher');
        OwnerUser::create([
            'owner_id' => $ownerKhac->id,
            'user_id'  => $nguoi->id,
            'role'     => OwnerUser::ROLE_OWNER,
        ]);

        $managerPivot = OwnerUser::where('user_id', $this->manager->id)
            ->where('owner_id', $this->owner->id)
            ->firstOrFail();

        $this->actingAs($nguoi);
        $this->assertFalse(Gate::allows('update', $managerPivot));
        $this->assertFalse(Gate::allows('delete', $managerPivot));
        $this->assertFalse(Gate::allows('view', $managerPivot));

        // Đổi `current_owner_id` sang owner của người khác cũng không mở được
        // gì: phép kiểm đòi có bản ghi pivot thật ở đó.
        $nguoi->update(['current_owner_id' => $this->owner->id]);
        $this->actingAs($nguoi->fresh());
        $this->assertFalse(Gate::allows('viewAny', OwnerUser::class));
        $this->assertFalse(Gate::allows('create', OwnerUser::class));
    }
}
