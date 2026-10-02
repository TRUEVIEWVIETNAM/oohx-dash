<?php

namespace Tests\Feature;

use App\Filament\Publisher\Resources\RefundResource as PublisherRefundResource;
use App\Filament\Resources\RefundResource as AdminRefundResource;
use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Refund;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use App\Services\Booking\CancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Ai thấy được và ai khai được "đã hoàn tiền".
 *
 * Sàn không giữ tiền: người mua chuyển thẳng cho media owner, nên owner là
 * người phải hoàn lại. Vì thế `Refund` là một bảng có **tên người mua và số
 * tiền của từng owner** — đúng loại bảng mà một lần quên scope là rò dữ liệu
 * giữa hai media owner cạnh tranh nhau.
 *
 * `Refund` **không** mang `HasOwnerScope`, nên việc chặn nằm ở
 * `getEloquentQuery()` của từng panel. File này kiểm chính chỗ đó, chứ không
 * tin vào việc "panel publisher thì đương nhiên đã scope".
 */
class RefundAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);
    }

    private function refundFor(Owner $owner, ?Organization $org = null): Refund
    {
        $org = $org ?: Organization::factory()->create(['status' => 'active']);

        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        $campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => User::factory()->create()->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch thử',
            'start_date'      => now()->addDays(30)->toDateString(),
            'end_date'        => now()->addDays(60)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_APPROVED,
        ]);

        $line = BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $owner->id,
            'start_date'         => now()->addDays(30)->toDateString(),
            'end_date'           => now()->addDays(60)->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => 1_000_000,
            'status'             => 'approved',
            'pricing_model'      => 'io',
        ]);

        return Refund::create([
            'campaign_id'       => $campaign->id,
            'booking_line_id'   => $line->id,
            'owner_id'          => $owner->id,
            'organization_id'   => $org->id,
            'paid_amount'       => 1_000_000,
            'amount'            => 1_000_000,
            'refund_pct'        => 100,
            'days_before_start' => 30,
            'status'            => Refund::STATUS_PENDING,
        ]);
    }

    private function memberOf(Owner $owner, string $role): User
    {
        $user = User::factory()->create(['current_owner_id' => $owner->id]);

        OwnerUser::create([
            'owner_id' => $owner->id,
            'user_id'  => $user->id,
            'role'     => $role,
        ]);

        return $user;
    }

    // ── Ai khai được "đã hoàn" ──────────────────────────────────────────────

    public function test_chu_owner_khai_duoc_da_hoan(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $refund = $this->refundFor($owner);
        $user   = $this->memberOf($owner, 'owner');

        $this->assertTrue($user->can('settle', $refund));
    }

    public function test_sales_manager_khong_khai_duoc_da_hoan(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $refund = $this->refundFor($owner);
        $user   = $this->memberOf($owner, 'sales_manager');

        // `sales_manager` chốt được đơn (`manage_bookings`) nhưng khai "tiền đã
        // đi" là việc khác hẳn. Một bộ quyền gộp hai việc này lại là một bộ
        // quyền không phân biệt được bán hàng với kế toán.
        $this->assertTrue($user->can('viewAny', Refund::class));
        $this->assertFalse($user->can('settle', $refund));
    }

    public function test_thanh_vien_owner_khac_khong_khai_duoc(): void
    {
        $ownerA = Owner::factory()->create(['status' => 'active']);
        $ownerB = Owner::factory()->create(['status' => 'active']);

        $refundA = $this->refundFor($ownerA);
        $userB   = $this->memberOf($ownerB, 'owner');

        $this->assertFalse(
            $userB->can('settle', $refundA),
            'Chủ owner B khai được nghĩa vụ hoàn tiền của owner A.'
        );
    }

    public function test_kiem_theo_owner_cua_khoan_hoan_khong_theo_owner_dang_chon(): void
    {
        $ownerA = Owner::factory()->create(['status' => 'active']);
        $ownerB = Owner::factory()->create(['status' => 'active']);

        $refundA = $this->refundFor($ownerA);

        // Người này là `read_only` ở owner A nhưng `owner` ở owner B, và đang
        // chọn owner B trong phiên. Kiểm theo phiên thì cho qua sai.
        $user = User::factory()->create(['current_owner_id' => $ownerB->id]);
        OwnerUser::create(['owner_id' => $ownerA->id, 'user_id' => $user->id, 'role' => 'read_only']);
        OwnerUser::create(['owner_id' => $ownerB->id, 'user_id' => $user->id, 'role' => 'owner']);

        $this->assertFalse(
            $user->can('settle', $refundA),
            'Đổi owner đang chọn là mở được quyền trên khoản hoàn tiền của owner khác.'
        );
    }

    public function test_owner_bi_tam_ngung_thi_khong_khai_duoc(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $refund = $this->refundFor($owner);
        $user   = $this->memberOf($owner, 'owner');

        $owner->update(['status' => 'suspended']);

        // Tạm ngưng một media owner phải có hiệu lực ở mọi cửa, kể cả cửa khai
        // tiền.
        $this->assertFalse($user->can('settle', $refund->fresh()));
    }

    public function test_quan_tri_san_khai_ho_duoc(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $refund = $this->refundFor($owner);

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->assertTrue($admin->can('settle', $refund));
    }

    public function test_nguoi_mua_xem_duoc_nhung_khong_khai_duoc(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $org    = Organization::factory()->create(['status' => 'active']);
        $refund = $this->refundFor($owner, $org);

        $buyer = User::factory()->create(['current_organization_id' => $org->id]);
        $buyer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        // Người nhận tiền tự khai mình đã nhận thì con số không còn nghĩa gì
        // với bên phải trả.
        $this->assertTrue($buyer->can('view', $refund));
        $this->assertFalse($buyer->can('settle', $refund));
    }

    public function test_khoan_da_hoan_thi_khong_khai_lai_duoc(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $refund = $this->refundFor($owner);
        $user   = $this->memberOf($owner, 'owner');

        app(CancellationService::class)->settle($refund, $user);

        $this->assertFalse($user->can('settle', $refund->fresh()));

        // Và lớp dưới cũng tự chặn, không chỉ dựa vào việc giao diện ẩn nút.
        $this->expectException(HttpException::class);
        app(CancellationService::class)->settle($refund->fresh(), $user);
    }

    // ── Phạm vi dữ liệu của từng panel ──────────────────────────────────────

    public function test_panel_publisher_chi_thay_khoan_cua_owner_dang_chon(): void
    {
        $ownerA = Owner::factory()->create(['status' => 'active']);
        $ownerB = Owner::factory()->create(['status' => 'active']);

        $refundA = $this->refundFor($ownerA);
        $refundB = $this->refundFor($ownerB);

        $userA = $this->memberOf($ownerA, 'owner');

        $this->actingAs($userA);

        $ids = PublisherRefundResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($refundA->id, $ids);
        $this->assertNotContains(
            $refundB->id,
            $ids,
            'Owner A thấy nghĩa vụ hoàn tiền của owner B — tức thấy người mua và số tiền của đối thủ.'
        );
    }

    public function test_panel_publisher_chan_mac_dinh_khi_khong_xac_dinh_duoc_owner(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $this->refundFor($owner);

        // Không chọn owner nào. `HasOwnerScope` chặn mặc định khi không xác
        // định được tenant, và bảng này phải theo đúng nguyên tắc đó.
        $user = User::factory()->create(['current_owner_id' => null]);
        $this->actingAs($user);

        $this->assertSame(0, PublisherRefundResource::getEloquentQuery()->count());
    }

    public function test_panel_admin_cho_quan_tri_san_thay_moi_khoan(): void
    {
        $refundA = $this->refundFor(Owner::factory()->create(['status' => 'active']));
        $refundB = $this->refundFor(Owner::factory()->create(['status' => 'active']));

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        $ids = AdminRefundResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($refundA->id, $ids);
        $this->assertContains($refundB->id, $ids);
    }

    public function test_panel_admin_khong_phai_quan_tri_san_thi_van_bi_gioi_han(): void
    {
        $ownerA = Owner::factory()->create(['status' => 'active']);
        $ownerB = Owner::factory()->create(['status' => 'active']);

        $refundA = $this->refundFor($ownerA);
        $refundB = $this->refundFor($ownerB);

        // Cổng vào `/admin` đã chặn người ngoài, nhưng phạm vi dữ liệu không
        // nên phụ thuộc vào một lớp duy nhất.
        $userA = $this->memberOf($ownerA, 'owner');
        $this->actingAs($userA);

        $ids = AdminRefundResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($refundA->id, $ids);
        $this->assertNotContains($refundB->id, $ids);
    }

    // ── Không có đường nhập số tiền bằng tay ────────────────────────────────

    public function test_khong_tao_va_khong_sua_khoan_hoan_bang_tay(): void
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $refund = $this->refundFor($owner);

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        // Số tiền do máy chủ tính (CLAUDE.md mục 5). Một form sửa `amount` là
        // đúng cái cửa mà nguyên tắc đó đóng lại.
        foreach ([AdminRefundResource::class, PublisherRefundResource::class] as $resource) {
            $this->assertFalse($resource::canCreate(), $resource . ' cho tạo khoản hoàn tiền bằng tay.');
            $this->assertFalse($resource::canEdit($refund), $resource . ' cho sửa khoản hoàn tiền bằng tay.');
            $this->assertFalse($resource::canDelete($refund), $resource . ' cho xóa khoản hoàn tiền.');
        }
    }
}
