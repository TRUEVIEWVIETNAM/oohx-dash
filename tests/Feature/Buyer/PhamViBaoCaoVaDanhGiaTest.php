<?php

namespace Tests\Feature\Buyer;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Báo cáo và đánh giá đi qua **policy**, không so `current_organization_id`.
 *
 * ══ Lỗ hổng tệp này chống ══
 *
 * Hai controller tự so:
 *
 *     $campaign->organization_id === $request->user()->current_organization_id
 *
 * Cột đó client đổi được (có bộ chuyển tổ chức) và **không chỗ nào dọn** khi
 * một người bị gỡ khỏi tổ chức. Hệ quả, cả hai đều có thật:
 *
 *  1. Người bị **gỡ khỏi tổ chức** vẫn đọc được báo cáo phát sóng của tổ chức
 *     ấy, và vẫn **ghi** được nhận xét công khai về đối tác, đứng tên tổ chức đó.
 *  2. Tổ chức bị **tạm ngưng** cũng vậy.
 *
 * Đúng khe mà `listForUser()` đóng cho `/my/campaigns` (#40) và
 * `tuCachXemChienDich()` đóng cho trang đầu (#46). Hai chỗ này là hai chỗ cuối
 * còn so thẳng vào cột đó.
 *
 * ══ Cách so sánh đáng tin ở đây ══
 *
 * Mỗi ca dựng **hai** tổ chức thật và một người là thành viên hợp lệ của tổ
 * chức sở hữu chiến dịch, rồi lấy quyền đi theo đúng một đường: đổi tư cách
 * thành viên (xoá, hoặc tạm ngưng tổ chức) và đòi 403. Không ca nào dựa vào
 * việc `current_organization_id` trỏ đâu — vì đó chính là thứ không đáng tin.
 */
class PhamViBaoCaoVaDanhGiaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $buyer;
    private Campaign $campaign;
    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);

        $this->org   = Organization::factory()->create(['status' => 'active']);
        $this->buyer = User::factory()->create(['current_organization_id' => $this->org->id]);
        $this->buyer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        $this->owner = Owner::factory()->create(['status' => 'active']);

        $site = Site::factory()->create(['owner_id' => $this->owner->id]);
        // `screens.status` là `enum('online','offline','maintenance')` — không
        // phải `active`. Để factory tự đặt (`online`).
        $screen = Screen::factory()->create([
            'owner_id' => $this->owner->id,
            'site_id'  => $site->id,
        ]);

        // Báo cáo chỉ mở cho chiến dịch đang chạy / tạm dừng / hoàn thành.
        $this->campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'CD báo cáo',
            'start_date'      => now()->subDays(5),
            'end_date'        => now()->addDays(5),
            'status'          => Campaign::STATUS_ACTIVE,
        ]);

        BookingLine::create([
            'campaign_id'    => $this->campaign->id,
            'screen_id'      => $screen->id,
            'owner_id'       => $this->owner->id,
            'start_date'     => now()->subDays(5),
            'end_date'       => now()->addDays(5),
            'spot_length'    => 10,
            'status'         => BookingLine::STATUS_ACTIVE,
            'estimated_cost' => 1_000_000,
        ]);
    }

    private function urlBaoCao(): string
    {
        return '/my/campaigns/' . $this->campaign->id . '/report';
    }

    private function urlDanhGia(): string
    {
        return '/my/campaigns/' . $this->campaign->id . '/reviews';
    }

    private function duLieuDanhGia(): array
    {
        return ['owner_id' => $this->owner->id, 'rating' => 5, 'comment' => 'Tốt'];
    }

    // ── Đường cơ sở: thành viên hợp lệ vào được ────────────────────────────

    public function test_thanh_vien_hop_le_xem_duoc_bao_cao(): void
    {
        $this->actingAs($this->buyer)->get($this->urlBaoCao())->assertOk();
    }

    public function test_thanh_vien_hop_le_danh_gia_duoc(): void
    {
        $this->actingAs($this->buyer)
            ->post($this->urlDanhGia(), $this->duLieuDanhGia())
            ->assertRedirect();
    }

    // ── Bị gỡ khỏi tổ chức ────────────────────────────────────────────────

    /**
     * Người bị gỡ khỏi tổ chức sở hữu chiến dịch: 403 ở **cả hai** đường.
     *
     * Họ vẫn thuộc một tổ chức khác, nên middleware `buyer` cho qua — chỉ
     * policy mới chặn được. Và `current_organization_id` vẫn trỏ vào tổ chức
     * cũ, đúng như khi bị xoá thật: không chỗ nào dọn cột đó.
     */
    public function test_bi_go_khoi_to_chuc_thi_het_xem_bao_cao_va_het_danh_gia(): void
    {
        $toChucKhac = Organization::factory()->create(['status' => 'active']);
        OrganizationUser::create([
            'organization_id' => $toChucKhac->id,
            'user_id'         => $this->buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        OrganizationUser::where('organization_id', $this->org->id)
            ->where('user_id', $this->buyer->id)
            ->delete();

        $this->assertSame(
            $this->org->id,
            $this->buyer->fresh()->current_organization_id,
            'cột vẫn trỏ vào tổ chức cũ — đó là tiền đề của ca này',
        );

        $this->actingAs($this->buyer)->get($this->urlBaoCao())->assertForbidden();
        $this->actingAs($this->buyer)
            ->post($this->urlDanhGia(), $this->duLieuDanhGia())
            ->assertForbidden();
    }

    // ── Tổ chức bị tạm ngưng ──────────────────────────────────────────────

    public function test_to_chuc_tam_ngung_thi_het_xem_bao_cao_va_het_danh_gia(): void
    {
        $this->org->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->actingAs($this->buyer)->get($this->urlBaoCao())->assertForbidden();
        $this->actingAs($this->buyer)
            ->post($this->urlDanhGia(), $this->duLieuDanhGia())
            ->assertForbidden();
    }

    // ── Chiến dịch của tổ chức khác ───────────────────────────────────────

    public function test_chien_dich_cua_to_chuc_khac_thi_403(): void
    {
        $toChucKhac = Organization::factory()->create(['status' => 'active']);
        $cuaNguoiKhac = Campaign::create([
            'organization_id' => $toChucKhac->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'CD của người khác',
            'start_date'      => now()->subDays(5),
            'end_date'        => now()->addDays(5),
            'status'          => Campaign::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->buyer)
            ->get('/my/campaigns/' . $cuaNguoiKhac->id . '/report')
            ->assertForbidden();

        $this->actingAs($this->buyer)
            ->post('/my/campaigns/' . $cuaNguoiKhac->id . '/reviews', $this->duLieuDanhGia())
            ->assertForbidden();
    }

    /**
     * Đổi `current_organization_id` sang tổ chức sở hữu chiến dịch **không**
     * mở được cửa.
     *
     * Đây là phép thử trực diện với cách kiểm cũ: nó so đúng cột này, nên chỉ
     * cần trỏ cột sang là nó cho qua. Policy thì hỏi tư cách thành viên.
     */
    public function test_tro_cot_to_chuc_dang_chon_sang_khong_mo_duoc_cua(): void
    {
        $toChucKhac = Organization::factory()->create(['status' => 'active']);
        $nguoiNgoai = User::factory()->create(['current_organization_id' => $toChucKhac->id]);
        $nguoiNgoai->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $toChucKhac->id,
            'user_id'         => $nguoiNgoai->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        // Trỏ sang tổ chức sở hữu chiến dịch, nhưng KHÔNG là thành viên.
        $nguoiNgoai->update(['current_organization_id' => $this->org->id]);

        $this->actingAs($nguoiNgoai)->get($this->urlBaoCao())->assertForbidden();
        $this->actingAs($nguoiNgoai)
            ->post($this->urlDanhGia(), $this->duLieuDanhGia())
            ->assertForbidden();
    }
}
