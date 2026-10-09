<?php

namespace Tests\Feature\Buyer;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Trang cài đặt: ai **xem** và ai **sửa** được thông tin tổ chức.
 *
 * ══ Lỗ hổng tệp này chống ══
 *
 * `updateOrganization()` chạy `$user->currentOrganization->update($data)` mà
 * không policy, không kiểm tư cách thành viên, không kiểm vai trò. `$data`
 * gồm `name`, `billing_email`, `billing_phone`, **`tax_id`**, `website`.
 *
 * Ba hệ quả:
 *
 *  1. Vai trò `viewer` ghi lại được mã số thuế và thông tin thanh toán — trong
 *     khi mô tả vai trò **trong chính mã** nói "Chỉ xem campaigns và reports,
 *     không chỉnh sửa".
 *  2. Người đã bị **gỡ khỏi tổ chức** mà `current_organization_id` vẫn trỏ ở
 *     đó ghi lại được thông tin của tổ chức ấy.
 *  3. Tổ chức bị **tạm ngưng** vẫn sửa được.
 *
 * Đường ĐỌC cũng đi qua cột đó, nên người đã bị gỡ còn xem được email thanh
 * toán, số điện thoại và mã số thuế của tổ chức cũ.
 *
 * ══ Ca nào là thay đổi hành vi, nói rõ ══
 *
 * `test_planner_khong_sua_duoc` và `test_viewer_khong_sua_duoc` **siết** quyền
 * so với hiện trạng: trước PR này cả hai vai trò sửa được. Lý do siết nằm ở
 * `OrganizationPolicy` — mô tả vai trò đã có trong mã nói đúng điều đó.
 */
class CaiDatToChucTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);

        $this->org = Organization::factory()->create([
            'status'        => Organization::STATUS_ACTIVE,
            'name'          => 'Tổ chức gốc',
            'tax_id'        => '0100000001',
            'billing_email' => 'ketoan@goc.vn',
        ]);
    }

    private function thanhVien(string $role, ?Organization $org = null): User
    {
        $org  = $org ?? $this->org;
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $user->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $user->id,
            'role'            => $role,
        ]);

        return $user;
    }

    private function duLieu(array $ghiDe = []): array
    {
        return array_merge([
            'name'          => 'Tên mới',
            'billing_email' => 'moi@example.com',
            'billing_phone' => '0240000000',
            'tax_id'        => '9999999999',
            'website'       => 'https://moi.example.com',
        ], $ghiDe);
    }

    private const URL = '/my/settings';
    private const URL_GHI = '/my/settings/organization';

    // ── Đọc ─────────────────────────────────────────────────────────────────

    public function test_thanh_vien_xem_duoc_trang_cai_dat(): void
    {
        $this->actingAs($this->thanhVien(OrganizationUser::ROLE_VIEWER))
            ->get(self::URL)
            ->assertOk()
            ->assertSee('0100000001');
    }

    /**
     * Người bị gỡ khỏi tổ chức **không** còn xem được dữ liệu của nó.
     *
     * Họ vẫn thuộc một tổ chức khác nên middleware `buyer` cho qua, và
     * `current_organization_id` vẫn trỏ vào tổ chức cũ — không chỗ nào dọn cột
     * đó. Trước PR này trang hiện mã số thuế và email thanh toán của tổ chức cũ.
     */
    public function test_bi_go_khoi_to_chuc_thi_khong_xem_duoc_nua(): void
    {
        $user = $this->thanhVien(OrganizationUser::ROLE_ADMIN);

        $toChucKhac = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);
        OrganizationUser::create([
            'organization_id' => $toChucKhac->id,
            'user_id'         => $user->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        OrganizationUser::where('organization_id', $this->org->id)
            ->where('user_id', $user->id)
            ->delete();

        $this->assertSame($this->org->id, $user->fresh()->current_organization_id);

        $this->actingAs($user)->get(self::URL)->assertForbidden();
    }

    public function test_to_chuc_tam_ngung_thi_khong_xem_duoc(): void
    {
        $user = $this->thanhVien(OrganizationUser::ROLE_ADMIN);

        $this->org->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->actingAs($user)->get(self::URL)->assertForbidden();
    }

    // ── Ghi ─────────────────────────────────────────────────────────────────

    public function test_admin_sua_duoc_thong_tin_to_chuc(): void
    {
        $this->actingAs($this->thanhVien(OrganizationUser::ROLE_ADMIN))
            ->put(self::URL_GHI, $this->duLieu())
            ->assertRedirect();

        $this->assertSame('9999999999', $this->org->fresh()->tax_id);
    }

    /**
     * `planner` **mất** quyền sửa — thay đổi hành vi, có chủ ý.
     *
     * Mô tả vai trò trong mã: "Tạo campaign, submit booking, upload creative,
     * xem reports. **Không quản lý team**." Thông tin pháp lý và thanh toán
     * của tổ chức thuộc việc quản trị, không thuộc việc lập kế hoạch.
     */
    public function test_planner_khong_sua_duoc(): void
    {
        $this->actingAs($this->thanhVien(OrganizationUser::ROLE_PLANNER))
            ->put(self::URL_GHI, $this->duLieu())
            ->assertForbidden();

        $this->assertSame('0100000001', $this->org->fresh()->tax_id);
    }

    /**
     * `viewer` **mất** quyền sửa — đây là ca đáng tiền nhất của tệp.
     *
     * Mô tả vai trò: "Chỉ xem campaigns và reports, **không chỉnh sửa**." Vậy
     * mà trước PR này nó ghi lại được mã số thuế của tổ chức.
     */
    public function test_viewer_khong_sua_duoc(): void
    {
        $this->actingAs($this->thanhVien(OrganizationUser::ROLE_VIEWER))
            ->put(self::URL_GHI, $this->duLieu())
            ->assertForbidden();

        $this->assertSame('0100000001', $this->org->fresh()->tax_id);
        $this->assertSame('ketoan@goc.vn', $this->org->fresh()->billing_email);
    }

    public function test_bi_go_khoi_to_chuc_thi_khong_sua_duoc_nua(): void
    {
        $user = $this->thanhVien(OrganizationUser::ROLE_ADMIN);

        $toChucKhac = Organization::factory()->create(['status' => Organization::STATUS_ACTIVE]);
        OrganizationUser::create([
            'organization_id' => $toChucKhac->id,
            'user_id'         => $user->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        OrganizationUser::where('organization_id', $this->org->id)
            ->where('user_id', $user->id)
            ->delete();

        $this->actingAs($user)->put(self::URL_GHI, $this->duLieu())->assertForbidden();

        $this->assertSame('0100000001', $this->org->fresh()->tax_id);
    }

    public function test_to_chuc_tam_ngung_thi_khong_sua_duoc(): void
    {
        $user = $this->thanhVien(OrganizationUser::ROLE_ADMIN);

        $this->org->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->actingAs($user)->put(self::URL_GHI, $this->duLieu())->assertForbidden();

        $this->assertSame('0100000001', $this->org->fresh()->tax_id);
    }

    // ── Hồ sơ cá nhân vẫn sửa được ở mọi vai trò ────────────────────────────

    /**
     * Siết quyền tổ chức **không** được kéo theo hồ sơ cá nhân.
     *
     * Tên và email của chính mình không phải dữ liệu của tổ chức, nên mọi vai
     * trò vẫn sửa được. Ca này là lưới chống việc siết quá tay.
     */
    public function test_moi_vai_tro_van_sua_duoc_ho_so_ca_nhan(): void
    {
        foreach (
            [OrganizationUser::ROLE_ADMIN, OrganizationUser::ROLE_PLANNER, OrganizationUser::ROLE_VIEWER]
            as $role
        ) {
            $user = $this->thanhVien($role);

            $this->actingAs($user)
                ->put('/my/settings/profile', [
                    'name'  => 'Tên ' . $role,
                    'email' => $role . '@example.com',
                ])
                ->assertRedirect();

            $this->assertSame('Tên ' . $role, $user->fresh()->name);
        }
    }
}
