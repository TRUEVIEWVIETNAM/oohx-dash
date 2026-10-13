<?php

namespace Tests\Feature\Buyer;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrgUserPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $superAdmin;
    private User $orgAdmin;
    private User $planner;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);

        $this->org = Organization::factory()->create(['status' => 'active']);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super_admin');

        $this->orgAdmin = $this->makeMember('admin');
        $this->planner  = $this->makeMember('planner');
        $this->viewer   = $this->makeMember('viewer');
    }

    private function makeMember(string $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $this->org->id]);
        $user->assignRole('buyer');

        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $user->id,
            'role'            => $role,
        ]);

        return $user;
    }

    public function test_assignable_roles_excludes_admin_for_non_super_admin(): void
    {
        $rolesForAdmin = OrganizationUser::assignableRolesFor($this->orgAdmin);
        $this->assertArrayNotHasKey('admin', $rolesForAdmin);
        $this->assertArrayHasKey('planner', $rolesForAdmin);
        $this->assertCount(2, $rolesForAdmin);

        $rolesForSuperAdmin = OrganizationUser::assignableRolesFor($this->superAdmin);
        $this->assertArrayHasKey('admin', $rolesForSuperAdmin);
        $this->assertCount(3, $rolesForSuperAdmin);
    }

    public function test_org_admin_can_view_and_create(): void
    {
        $this->actingAs($this->orgAdmin);
        $this->assertTrue(Gate::allows('viewAny', OrganizationUser::class));
        $this->assertTrue(Gate::allows('create', OrganizationUser::class));
    }

    public function test_planner_cannot_view_or_create(): void
    {
        $this->actingAs($this->planner);
        $this->assertFalse(Gate::allows('viewAny', OrganizationUser::class));
        $this->assertFalse(Gate::allows('create', OrganizationUser::class));
    }

    public function test_viewer_cannot_view(): void
    {
        $this->actingAs($this->viewer);
        $this->assertFalse(Gate::allows('viewAny', OrganizationUser::class));
    }

    public function test_org_admin_cannot_update_record_with_role_admin(): void
    {
        $coAdmin = $this->makeMember('admin');
        $coAdminPivot = OrganizationUser::where('user_id', $coAdmin->id)->where('organization_id', $this->org->id)->firstOrFail();

        $this->actingAs($this->orgAdmin);
        $this->assertFalse(Gate::allows('update', $coAdminPivot));
        $this->assertFalse(Gate::allows('delete', $coAdminPivot));
    }

    public function test_org_admin_can_update_planner_record(): void
    {
        $plannerPivot = OrganizationUser::where('user_id', $this->planner->id)->where('organization_id', $this->org->id)->firstOrFail();

        $this->actingAs($this->orgAdmin);
        $this->assertTrue(Gate::allows('update', $plannerPivot));
        $this->assertTrue(Gate::allows('delete', $plannerPivot));
    }

    public function test_org_admin_cannot_update_self(): void
    {
        $selfPivot = OrganizationUser::where('user_id', $this->orgAdmin->id)->where('organization_id', $this->org->id)->firstOrFail();

        $this->actingAs($this->orgAdmin);
        $this->assertFalse(Gate::allows('update', $selfPivot));
    }

    public function test_super_admin_can_update_admin_record(): void
    {
        $coAdmin = $this->makeMember('admin');
        $coAdminPivot = OrganizationUser::where('user_id', $coAdmin->id)->where('organization_id', $this->org->id)->firstOrFail();

        $this->actingAs($this->superAdmin);
        $this->assertTrue(Gate::allows('update', $coAdminPivot));
    }

    // ── Gom về `manage_team` (13/10/2026) ───────────────────────────────────

    /**
     * Phép gác đi theo `PERMISSIONS`, không theo tên vai trò.
     *
     * Bản trước so `actorRole(…) === 'admin'`. Hai bộ luật cho một câu hỏi, và
     * hệ quả thật: thêm một vai trò mới có `manage_team` sẽ **không** cho nó
     * mời ai, vì phép gác thật không đọc `PERMISSIONS`.
     *
     * Ca này lấy kỳ vọng **từ chính `PERMISSIONS`**. Nói thẳng giới hạn của
     * nó: với ba vai trò hiện có, nó không phân biệt được hai cách viết — chỉ
     * `admin` có `manage_team` và cũng chỉ `admin` tên là `'admin'`. Giá trị
     * của nó là **ngày có vai trò thứ tư**: lúc đó nó tự đòi đúng điều
     * `PERMISSIONS` khai, không phải chờ ai nhớ ra mà sửa.
     */
    public function test_phep_gac_di_theo_bang_quyen_khong_theo_ten_vai_tro(): void
    {
        foreach (array_keys(OrganizationUser::PERMISSIONS) as $vaiTro) {
            $nguoi = $this->makeMember($vaiTro);
            $mong  = in_array('manage_team', OrganizationUser::PERMISSIONS[$vaiTro], true);

            $this->actingAs($nguoi);

            $this->assertSame(
                $mong,
                Gate::allows('viewAny', OrganizationUser::class),
                "viewAny của '{$vaiTro}' phải khớp manage_team trong PERMISSIONS",
            );
            $this->assertSame(
                $mong,
                Gate::allows('create', OrganizationUser::class),
                "create của '{$vaiTro}' phải khớp manage_team trong PERMISSIONS",
            );
        }
    }

    /**
     * Tổ chức **tạm ngưng** không quản lý thành viên được — hành vi đổi, có chủ ý.
     *
     * `actorRole()` cũ không kiểm trạng thái tổ chức, trong khi
     * `OrganizationPolicy` đã đòi điều đó từ #55. Để hai policy của cùng một
     * tổ chức trả lời khác nhau về cùng một tổ chức là cách sinh ra lỗ tiếp
     * theo.
     *
     * Đi qua giao diện thì không ai thấy khác: panel `buyer` đã chặn bằng
     * `User::hasActiveTenant()`. Đây là lớp phòng thủ thứ hai, cho ngày có một
     * endpoint `/api/v2` cho việc quản lý thành viên.
     */
    public function test_to_chuc_tam_ngung_thi_khong_quan_ly_duoc_thanh_vien(): void
    {
        $plannerPivot = OrganizationUser::where('user_id', $this->planner->id)
            ->where('organization_id', $this->org->id)
            ->firstOrFail();

        // Trước khi tạm ngưng: admin làm được.
        $this->actingAs($this->orgAdmin);
        $this->assertTrue(Gate::allows('viewAny', OrganizationUser::class));
        $this->assertTrue(Gate::allows('update', $plannerPivot));

        $this->org->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->actingAs($this->orgAdmin);
        $this->assertFalse(Gate::allows('viewAny', OrganizationUser::class));
        $this->assertFalse(Gate::allows('create', OrganizationUser::class));
        $this->assertFalse(Gate::allows('update', $plannerPivot->fresh()));
        $this->assertFalse(Gate::allows('delete', $plannerPivot->fresh()));

        // Và cả phép xem một bản ghi thành viên.
        $this->assertFalse(Gate::allows('view', $plannerPivot->fresh()));
    }

    /**
     * Xem bản ghi thành viên là quyền của **mọi** thành viên, không đòi
     * `manage_team`.
     *
     * Ranh giới này không đổi trong lần gom, và ca này tồn tại để nó không bị
     * siết thành "chỉ admin" một cách vô tình: `viewAny` (danh sách) và `view`
     * (một bản ghi) vạch ranh giới ở hai chỗ khác nhau, nên một bản sửa gộp cả
     * hai về cùng một quyền sẽ làm ca này đỏ.
     */
    public function test_moi_thanh_vien_xem_duoc_ban_ghi_thanh_vien(): void
    {
        $adminPivot = OrganizationUser::where('user_id', $this->orgAdmin->id)
            ->where('organization_id', $this->org->id)
            ->firstOrFail();

        foreach ([$this->planner, $this->viewer] as $nguoi) {
            $this->actingAs($nguoi);
            $this->assertTrue(Gate::allows('view', $adminPivot));
        }

        // Người ngoài tổ chức thì không.
        $nguoiNgoai = User::factory()->create();
        $nguoiNgoai->assignRole('buyer');
        $this->actingAs($nguoiNgoai);
        $this->assertFalse(Gate::allows('view', $adminPivot));
    }

    /**
     * `planner` không đổi và không gỡ được thành viên.
     *
     * Bộ ca cũ canh `viewAny`/`create` cho `planner`, nhưng **không** canh hai
     * đường ghi thật — và chúng đi qua một nhánh mã khác (`duocChamVao()`).
     */
    public function test_planner_khong_doi_va_khong_go_duoc_thanh_vien(): void
    {
        $viewerPivot = OrganizationUser::where('user_id', $this->viewer->id)
            ->where('organization_id', $this->org->id)
            ->firstOrFail();

        $this->actingAs($this->planner);
        $this->assertFalse(Gate::allows('update', $viewerPivot));
        $this->assertFalse(Gate::allows('delete', $viewerPivot));
    }

    /**
     * Thành viên của tổ chức KHÁC không chạm được, dù họ là admin ở chỗ họ.
     *
     * `update`/`delete` kiểm theo `$target->organization_id`, không theo
     * `current_organization_id` — cột sau là đầu vào client đổi được.
     */
    public function test_admin_cua_to_chuc_khac_khong_cham_duoc(): void
    {
        $toChucKhac = Organization::factory()->create(['status' => 'active']);
        $nguoi      = User::factory()->create(['current_organization_id' => $toChucKhac->id]);
        $nguoi->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $toChucKhac->id,
            'user_id'         => $nguoi->id,
            'role'            => 'admin',
        ]);

        $plannerPivot = OrganizationUser::where('user_id', $this->planner->id)
            ->where('organization_id', $this->org->id)
            ->firstOrFail();

        $this->actingAs($nguoi);
        $this->assertFalse(Gate::allows('update', $plannerPivot));
        $this->assertFalse(Gate::allows('delete', $plannerPivot));
        $this->assertFalse(Gate::allows('view', $plannerPivot));

        // Đổi `current_organization_id` sang tổ chức của người khác cũng không
        // mở được gì: `thanhVien()` đòi có bản ghi pivot thật ở đó.
        $nguoi->update(['current_organization_id' => $this->org->id]);
        $this->actingAs($nguoi->fresh());
        $this->assertFalse(Gate::allows('viewAny', OrganizationUser::class));
        $this->assertFalse(Gate::allows('create', OrganizationUser::class));
    }
}
