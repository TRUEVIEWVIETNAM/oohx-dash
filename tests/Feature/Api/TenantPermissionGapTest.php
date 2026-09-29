<?php

namespace Tests\Feature\Api;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Giai đoạn 1b — những lỗ phân quyền giai đoạn 0 chưa bịt.
 *
 * Bốn chỗ, mỗi chỗ là một kiểu nhầm khác nhau:
 *
 *  1. `approveLines` lọc theo id dòng trong cùng chiến dịch mà **không scope
 *     owner** — thành viên của owner A duyệt được dòng của owner B.
 *  2. Duyệt / từ chối đặt chỗ **không kiểm quyền nào**, chỉ dựa vào việc giao
 *     diện có hiện nút.
 *  3. Quyền sửa giá chỉ thể hiện bằng `->visible()` trong form Filament.
 *  4. `canAccessPanel` kiểm "có owner nào đó còn hoạt động" thay vì owner đang
 *     chọn.
 */
class TenantPermissionGapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['buyer', 'publisher', 'super_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function screen(Owner $owner): Screen
    {
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

    private function publisher(Owner $owner, string $role): User
    {
        $user = User::factory()->create(['current_owner_id' => $owner->id]);
        $user->assignRole('publisher');
        OwnerUser::create(['owner_id' => $owner->id, 'user_id' => $user->id, 'role' => $role]);

        return $user->fresh();
    }

    private function campaignWithLines(array $screens): Campaign
    {
        $org  = Organization::factory()->create(['status' => 'active']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);

        $campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch',
            'start_date'      => now()->addMonth()->toDateString(),
            'end_date'        => now()->addMonths(2)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_PENDING,
        ]);

        foreach ($screens as $screen) {
            BookingLine::create([
                'campaign_id'        => $campaign->id,
                'screen_id'          => $screen->id,
                'owner_id'           => $screen->owner_id,
                'start_date'         => now()->addMonth()->toDateString(),
                'end_date'           => now()->addMonths(2)->toDateString(),
                'spot_length'        => 15,
                'share_of_voice_pct' => 100,
                'estimated_cost'     => 1_000_000,
                'status'             => 'pending',
                'pricing_model'      => 'io',
            ]);
        }

        return $campaign->fresh();
    }

    // ── 1. Scope owner khi duyệt từng dòng ───────────────────────────────────

    public function test_khong_duyet_duoc_dong_cua_owner_khac(): void
    {
        $ownerA = Owner::factory()->create(['status' => 'active']);
        $ownerB = Owner::factory()->create(['status' => 'active']);

        $screenA = $this->screen($ownerA);
        $screenB = $this->screen($ownerB);

        $campaign = $this->campaignWithLines([$screenA, $screenB]);
        $lineB    = $campaign->bookingLines()->where('owner_id', $ownerB->id)->firstOrFail();

        $userA = $this->publisher($ownerA, 'owner');
        $this->actingAs($userA);

        try {
            app(CampaignService::class)->approveLines($campaign, [$lineB->id], $userA);
            $this->fail('Thành viên của owner A không được duyệt dòng của owner B.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame('pending', $lineB->fresh()->status, 'Dòng của owner B phải còn nguyên.');
    }

    public function test_duyet_duoc_dong_cua_chinh_minh(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $screen = $this->screen($owner);

        $campaign = $this->campaignWithLines([$screen]);
        $line     = $campaign->bookingLines()->firstOrFail();

        $user = $this->publisher($owner, 'owner');
        $this->actingAs($user);

        app(CampaignService::class)->approveLines($campaign, [$line->id], $user);

        $this->assertSame('approved', $line->fresh()->status);
    }

    // ── 2. Quyền duyệt đặt chỗ ───────────────────────────────────────────────

    public function test_vai_tro_scheduler_khong_duyet_duoc_dat_cho(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $screen = $this->screen($owner);

        $campaign = $this->campaignWithLines([$screen]);

        // scheduler quản lý kho nhưng không phải người quyết định thương mại.
        $user = $this->publisher($owner, 'scheduler');
        $this->actingAs($user);

        try {
            app(CampaignService::class)->approveAllForOwner($campaign, $owner->id, $user);
            $this->fail('scheduler không có quyền manage_bookings.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_vai_tro_sales_manager_duyet_duoc(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $screen = $this->screen($owner);

        $campaign = $this->campaignWithLines([$screen]);
        $user     = $this->publisher($owner, 'sales_manager');
        $this->actingAs($user);

        $count = app(CampaignService::class)->approveAllForOwner($campaign, $owner->id, $user);

        $this->assertSame(1, $count);
    }

    // ── 3. Quyền sửa giá ép ở tầng lưu ───────────────────────────────────────

    public function test_khong_co_quyen_gia_thi_khong_ghi_duoc_gia(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $screen = $this->screen($owner);

        // scheduler có manage_inventory nhưng không có manage_pricing.
        $user = $this->publisher($owner, 'scheduler');
        $this->actingAs($user);

        try {
            $screen->inventory->update(['io_rate' => 9_000_000]);
            $this->fail('Form ẩn trường giá không phải cơ chế bảo vệ — tầng lưu phải chặn.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(1_000_000, (int) round((float) $screen->fresh('inventory')->inventory->io_rate));
    }

    public function test_scheduler_van_sua_duoc_thu_khong_phai_gia(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $screen = $this->screen($owner);

        $user = $this->publisher($owner, 'scheduler');
        $this->actingAs($user);

        $screen->inventory->update(['weekly_impressions' => 700_000]);

        $this->assertSame(700_000, (int) $screen->fresh('inventory')->inventory->weekly_impressions);
    }

    public function test_manager_sua_duoc_gia(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $screen = $this->screen($owner);

        $user = $this->publisher($owner, 'manager');
        $this->actingAs($user);

        $screen->inventory->update(['io_rate' => 2_000_000]);

        $this->assertSame(2_000_000, (int) round((float) $screen->fresh('inventory')->inventory->io_rate));
    }

    // ── 4. canAccessPanel theo owner đang chọn ───────────────────────────────

    public function test_owner_dang_chon_bi_tam_ngung_thi_khong_vao_duoc_panel(): void
    {
        $active    = Owner::factory()->create(['status' => 'active']);
        $suspended = Owner::factory()->create(['status' => 'suspended']);

        $user = User::factory()->create(['current_owner_id' => $suspended->id]);
        $user->assignRole('publisher');
        OwnerUser::create(['owner_id' => $active->id,    'user_id' => $user->id, 'role' => 'owner']);
        OwnerUser::create(['owner_id' => $suspended->id, 'user_id' => $user->id, 'role' => 'owner']);

        $panel = \Filament\Facades\Filament::getPanel('publisher');

        $this->assertFalse(
            $user->fresh()->canAccessPanel($panel),
            'Owner đang chọn bị tạm ngưng thì không được vào, dù người này còn thuộc một owner khác còn hoạt động.'
        );
    }

    public function test_owner_dang_chon_con_hoat_dong_thi_vao_duoc(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $user  = $this->publisher($owner, 'owner');

        $this->assertTrue($user->canAccessPanel(\Filament\Facades\Filament::getPanel('publisher')));
    }

    // ── Quyền của người mua theo vai trò ─────────────────────────────────────

    public function test_vai_tro_viewer_khong_gui_duoc_booking(): void
    {
        $org      = Organization::factory()->create(['status' => 'active']);
        $owner    = Owner::factory()->create(['status' => 'active']);
        $screen   = $this->screen($owner);
        $campaign = $this->campaignWithLines([$screen]);
        $campaign->update(['organization_id' => $org->id, 'status' => Campaign::STATUS_DRAFT]);

        $viewer = User::factory()->create(['current_organization_id' => $org->id]);
        $viewer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $viewer->id,
            'role'            => OrganizationUser::ROLE_VIEWER,
        ]);

        $this->assertFalse($viewer->can('submit', $campaign->fresh()), 'viewer chỉ được xem, không được gửi booking.');
        $this->assertFalse($viewer->can('pay', $campaign->fresh()), 'viewer không được xác nhận thanh toán.');
        $this->assertTrue($viewer->can('view', $campaign->fresh()));
    }

    public function test_vai_tro_planner_gui_duoc_booking_nhung_khong_xac_nhan_thanh_toan(): void
    {
        $org      = Organization::factory()->create(['status' => 'active']);
        $owner    = Owner::factory()->create(['status' => 'active']);
        $screen   = $this->screen($owner);
        $campaign = $this->campaignWithLines([$screen]);
        $campaign->update(['organization_id' => $org->id, 'status' => Campaign::STATUS_DRAFT]);

        $planner = User::factory()->create(['current_organization_id' => $org->id]);
        $planner->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $planner->id,
            'role'            => OrganizationUser::ROLE_PLANNER,
        ]);

        $this->assertTrue($planner->can('submit', $campaign->fresh()));
        $this->assertFalse(
            $planner->can('pay', $campaign->fresh()),
            'PERMISSIONS đã ghi planner không có manage_payments — policy phải đọc đúng bảng đó.'
        );
    }

    public function test_to_chuc_bi_tam_ngung_thi_khong_con_quyen_gi(): void
    {
        $org      = Organization::factory()->create(['status' => 'suspended']);
        $owner    = Owner::factory()->create(['status' => 'active']);
        $screen   = $this->screen($owner);
        $campaign = $this->campaignWithLines([$screen]);
        $campaign->update(['organization_id' => $org->id]);

        $admin = User::factory()->create(['current_organization_id' => $org->id]);
        $admin->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $admin->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        $this->assertFalse($admin->can('view', $campaign->fresh()));
        $this->assertFalse($admin->can('submit', $campaign->fresh()));
    }
}
