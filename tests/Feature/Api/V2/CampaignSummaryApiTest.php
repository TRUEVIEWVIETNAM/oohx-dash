<?php

namespace Tests\Feature\Api\V2;

use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `GET /api/v2/campaigns/summary` — bảng đếm cho trang đầu khu người mua.
 *
 * ══ Lỗ hổng đường này đóng ══
 *
 * `BuyerDashboardController` đếm bằng `$org->campaigns()` với
 * `$org = $user->currentOrganization`, và `currentOrganization` là một
 * `belongsTo` thuần trên `current_organization_id` — **không** kiểm tư cách
 * thành viên. Ba tình huống đọc được dữ liệu của tổ chức khác, và tình huống
 * đầu có thật chứ không phải giả thiết:
 *
 *  1. Người bị **gỡ khỏi tổ chức** nhưng cột đó vẫn trỏ ở đó. Khu quản trị tổ
 *     chức xoá được thành viên, và không chỗ nào dọn cột. Họ vẫn thấy số đếm
 *     và năm chiến dịch gần nhất kèm tên, mã, kỳ chạy.
 *  2. Tổ chức bị **tạm ngưng**: vẫn đọc.
 *
 * `listForUser()` đóng cả hai cho `/my/campaigns` từ PR #40. Trang đầu thì
 * chưa — nên cùng một người thấy 0 ở danh sách và 12 ở ô thống kê.
 *
 * ══ Một nhánh của cổng KHÔNG có test, và nói ra vì sao ══
 *
 * Cổng còn đòi vai trò có quyền `view_campaigns`. Nhánh đó **không chặn ai**
 * hiện nay: cả ba vai trò (`admin`, `planner`, `viewer`) đều có quyền đó, và
 * cột `organization_users.role` là `enum('admin','planner','viewer')` nên
 * không thể dựng một vai trò thứ tư để thử — `update` bị CSDL từ chối.
 *
 * Nên nhánh đó là lưới cho một vai trò chưa tồn tại, và một ca test giả vờ
 * chứng minh nó thì chỉ chứng minh được rằng CSDL chặn giá trị lạ. Ghi lại ở
 * đây thay vì để một khoảng trống không ai biết: thêm vai trò thứ tư thì thêm
 * ca test cùng lượt.
 *
 * ══ Điều đáng canh nhất ở đây ══
 *
 * Không phải từng con số, mà việc **hai đường cùng một phạm vi**. Số đếm và
 * danh sách trả lời hai câu hỏi về cùng một tập; nếu hai cổng phân quyền khác
 * nhau thì chúng sẽ lệch, và người dùng không có cách nào biết số nào đúng.
 * `test_dem_khop_voi_danh_sach` canh đúng điều đó.
 */
class CampaignSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v2/campaigns/summary';

    private Organization $org;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);

        $this->org   = Organization::factory()->create(['status' => 'active']);
        $this->buyer = $this->makeMember($this->org, OrganizationUser::ROLE_ADMIN);
    }

    private function makeMember(Organization $org, string $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $user->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $user->id,
            'role'            => $role,
        ]);

        return $user;
    }

    private function campaign(string $trangThai, ?Organization $org = null): Campaign
    {
        return Campaign::create([
            'organization_id' => ($org ?? $this->org)->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'CD ' . $trangThai,
            'start_date'      => now()->addMonth(),
            'end_date'        => now()->addMonths(2),
            'status'          => $trangThai,
        ]);
    }

    /** @return array<string, int> `status` → `count`, từ phản hồi */
    private function demTheoMa(array $than): array
    {
        $ra = [];

        foreach ($than['data']['by_status'] as $muc) {
            $ra[$muc['status']] = $muc['count'];
        }

        return $ra;
    }

    // ── Cửa vào ─────────────────────────────────────────────────────────────

    public function test_chua_dang_nhap_thi_khong_doc_duoc(): void
    {
        $this->campaign(Campaign::STATUS_DRAFT);

        $this->getJson(self::URL)
            ->assertStatus(401)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    // ── Hình dạng ───────────────────────────────────────────────────────────

    /**
     * Đủ **mọi** mã, đúng thứ tự của `Campaign::STATUS_LABELS`, kèm chữ.
     *
     * Thiếu mã đếm được 0 thì mỗi bên tiêu thụ phải tự nhớ `?? 0`, và chỗ nào
     * quên thì ô thống kê hiện `undefined` thay vì số không.
     *
     * Ca này cũng là ca chứng minh **thứ tự route** còn đúng: nếu
     * `campaigns/{campaign}` bị khai trước `campaigns/summary` thì chuỗi
     * "summary" bị hút vào tham số, ràng buộc model không tìm thấy ULID, và
     * đường này trả 404 — một 404 trông y như "chưa deploy".
     */
    public function test_tra_du_moi_ma_dung_thu_tu_va_kem_chu(): void
    {
        $than = $this->actingAs($this->buyer)
            ->getJson(self::URL)
            ->assertOk()
            ->json();

        $this->assertSame(
            array_keys(Campaign::STATUS_LABELS),
            array_column($than['data']['by_status'], 'status'),
            'Bảng đếm phải có đủ mọi mã, theo đúng thứ tự của STATUS_LABELS.',
        );

        $this->assertSame(
            array_values(Campaign::STATUS_LABELS),
            array_column($than['data']['by_status'], 'label'),
            'Chữ phải đến từ STATUS_LABELS, không phải do client tự dịch.',
        );
    }

    public function test_dem_dung_tung_trang_thai(): void
    {
        $this->campaign(Campaign::STATUS_DRAFT);
        $this->campaign(Campaign::STATUS_DRAFT);
        $this->campaign(Campaign::STATUS_ACTIVE);

        $than = $this->actingAs($this->buyer)->getJson(self::URL)->assertOk()->json();
        $dem  = $this->demTheoMa($than);

        $this->assertSame(2, $dem[Campaign::STATUS_DRAFT]);
        $this->assertSame(1, $dem[Campaign::STATUS_ACTIVE]);
        $this->assertSame(0, $dem[Campaign::STATUS_CANCELLED]);
        $this->assertSame(3, $than['data']['total']);
    }

    /**
     * Tổng bằng tổng các phần — con số người dùng cộng nhẩm được.
     *
     * Hai số này đến từ cùng một truy vấn, nên chúng chỉ lệch nhau khi CSDL có
     * một mã trạng thái không có chữ. Lúc đó `NhanTrangThaiMotNoiTest` đỏ ở
     * chỗ nói đúng nguyên nhân, còn ca này đỏ ở chỗ người dùng nhìn thấy.
     */
    public function test_tong_bang_tong_cua_cac_phan(): void
    {
        foreach ([Campaign::STATUS_DRAFT, Campaign::STATUS_ACTIVE, Campaign::STATUS_COMPLETED] as $ma) {
            $this->campaign($ma);
        }

        $than = $this->actingAs($this->buyer)->getJson(self::URL)->assertOk()->json();

        $this->assertSame(
            $than['data']['total'],
            array_sum(array_column($than['data']['by_status'], 'count')),
        );
    }

    // ── Phạm vi ─────────────────────────────────────────────────────────────

    public function test_khong_dem_chien_dich_cua_to_chuc_khac(): void
    {
        $this->campaign(Campaign::STATUS_ACTIVE);

        $toChucKhac = Organization::factory()->create(['status' => 'active']);
        $this->campaign(Campaign::STATUS_ACTIVE, $toChucKhac);
        $this->campaign(Campaign::STATUS_DRAFT, $toChucKhac);

        $than = $this->actingAs($this->buyer)->getJson(self::URL)->assertOk()->json();

        $this->assertSame(1, $than['data']['total'], 'Số đếm vượt ra ngoài tổ chức đang chọn.');
    }

    /**
     * `current_organization_id` trỏ sang tổ chức họ **không** là thành viên.
     *
     * Đây là tình huống của người bị gỡ khỏi tổ chức: cột vẫn trỏ ở đó. Bản cũ
     * của trang đầu đếm đúng tổ chức đó và hiện năm chiến dịch gần nhất của nó.
     */
    public function test_tro_sang_to_chuc_khong_phai_thanh_vien_thi_dem_bang_khong(): void
    {
        $toChucKhac = Organization::factory()->create(['status' => 'active']);
        $this->campaign(Campaign::STATUS_ACTIVE, $toChucKhac);
        $this->campaign(Campaign::STATUS_DRAFT, $toChucKhac);

        $this->buyer->update(['current_organization_id' => $toChucKhac->id]);

        $than = $this->actingAs($this->buyer)->getJson(self::URL)->assertOk()->json();

        $this->assertSame(0, $than['data']['total']);
        $this->assertSame(
            array_keys(Campaign::STATUS_LABELS),
            array_column($than['data']['by_status'], 'status'),
            'Nhánh chặn vẫn phải trả đủ hình dạng, không phải một mảng rỗng — '
            . 'bên tiêu thụ không được phải có nhánh riêng cho việc bị chặn.',
        );
    }

    public function test_to_chuc_tam_ngung_thi_dem_bang_khong(): void
    {
        $this->campaign(Campaign::STATUS_ACTIVE);

        $this->org->update(['status' => 'suspended']);

        $than = $this->actingAs($this->buyer)->getJson(self::URL)->assertOk()->json();

        $this->assertSame(0, $than['data']['total']);
    }

    /**
     * Số đếm và danh sách **không được lệch nhau**.
     *
     * Đây là ca đáng tiền nhất của tệp. Hai đường trả lời hai câu hỏi về cùng
     * một tập, và chúng dùng chung `tuCachXemChienDich()` đúng để không thể có
     * chuyện ô thống kê đếm 12 mà danh sách trả 0. Nếu ai tách hai cổng ra —
     * ví dụ cho số đếm đi thẳng `$org->campaigns()` cho nhanh — ca này đỏ.
     */
    public function test_dem_khop_voi_danh_sach(): void
    {
        foreach ([Campaign::STATUS_DRAFT, Campaign::STATUS_ACTIVE, Campaign::STATUS_PENDING] as $ma) {
            $this->campaign($ma);
        }

        $toChucKhac = Organization::factory()->create(['status' => 'active']);
        $this->campaign(Campaign::STATUS_ACTIVE, $toChucKhac);

        $tong = $this->actingAs($this->buyer)
            ->getJson(self::URL)
            ->assertOk()
            ->json('data.total');

        $metaTong = $this->actingAs($this->buyer)
            ->getJson('/api/v2/campaigns')
            ->assertOk()
            ->json('meta.total');

        $this->assertSame(
            $metaTong,
            $tong,
            'Số đếm của trang đầu lệch khỏi tổng của danh sách — hai phạm vi đã rời nhau.',
        );
    }
}
