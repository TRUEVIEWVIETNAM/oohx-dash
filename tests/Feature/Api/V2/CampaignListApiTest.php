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
 * `GET /api/v2/campaigns` — danh sách, 08/10/2026.
 *
 * Mở đường cho `/my/campaigns`, trang danh sách của khu người mua.
 *
 * Những thứ tệp này canh, và vì sao từng thứ:
 *
 *  1. **Phạm vi là một phép phân quyền.** Chỉ chiến dịch của tổ chức đang
 *     chọn, và chỉ khi tư cách thành viên còn hiệu lực — thành viên, tổ chức
 *     còn `active`, vai trò có `view_campaigns`. Không có tư cách thì trang
 *     **rỗng**, không phải mọi chiến dịch của sàn.
 *  2. **Không tin `current_organization_id` một mình.** Cột đó client đổi được.
 *     Trỏ sang tổ chức mình đã bị gỡ khỏi, hoặc tổ chức đã tạm ngưng, đều phải
 *     ra rỗng.
 *  3. **Giới hạn cứng** `max_per_page`, và nói ra mức chặn trong `meta`.
 *  4. **`status` sai thì 422**, không phải danh sách rỗng — client cần phân
 *     biệt "không có gì ở trạng thái này" với "tôi gõ sai tên trạng thái".
 *  5. **Tìm theo tên/mã không vượt ra ngoài tổ chức.** Ngoặc quanh nhóm
 *     `orWhere` là thứ duy nhất ngăn điều đó.
 *  6. **Không có `cancel_quotes`** — nó chạy một lượt đọc bảng tiền cho mỗi
 *     dòng đặt chỗ, nhân với 20 chiến dịch một trang.
 */
class CampaignListApiTest extends TestCase
{
    use RefreshDatabase;

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

    private function campaign(array $ghiDe = [], ?Organization $org = null): Campaign
    {
        return Campaign::create(array_merge([
            'organization_id' => ($org ?? $this->org)->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'Chiến dịch thử',
            'start_date'      => now()->addMonth(),
            'end_date'        => now()->addMonths(2),
            'status'          => Campaign::STATUS_APPROVED,
        ], $ghiDe));
    }

    private const URL = '/api/v2/campaigns';

    // ── Cửa vào ─────────────────────────────────────────────────────────────

    public function test_chua_dang_nhap_thi_khong_doc_duoc(): void
    {
        $this->campaign();

        $this->getJson(self::URL)
            ->assertStatus(401)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    // ── Phạm vi ─────────────────────────────────────────────────────────────

    public function test_chi_tra_chien_dich_cua_to_chuc_dang_chon(): void
    {
        $cuaMinh = $this->campaign(['name' => 'Của tôi']);

        $toChucKhac = Organization::factory()->create(['status' => 'active']);
        $cuaNguoiKhac = $this->campaign(['name' => 'Của người khác'], $toChucKhac);

        $than = $this->actingAs($this->buyer)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cuaMinh->id)
            ->getContent();

        $this->assertStringNotContainsString($cuaNguoiKhac->id, $than);
        $this->assertStringNotContainsString($cuaNguoiKhac->code, $than);
    }

    /**
     * Bị gỡ khỏi **mọi** tổ chức: middleware `buyer` trả lời trước.
     *
     * Không còn tổ chức nào thì người này không còn là người mua, và
     * `EnsureBuyerAuth` chặn ở cửa với envelope `organization_required` — câu
     * trả lời đúng hơn một danh sách rỗng, vì nó nói ra nguyên nhân.
     */
    public function test_bi_go_khoi_moi_to_chuc_thi_middleware_chan(): void
    {
        $this->campaign();

        OrganizationUser::where('user_id', $this->buyer->id)->delete();

        $this->actingAs($this->buyer)
            ->getJson(self::URL)
            ->assertStatus(403)
            ->assertJsonPath('error', 'organization_required');
    }

    /**
     * Đây là khe mà lớp scope của service đóng, và middleware thì không.
     *
     * Người này **vẫn** thuộc một tổ chức (nên middleware cho qua), nhưng
     * `current_organization_id` trỏ sang một tổ chức khác mà họ **không** là
     * thành viên. Bản cũ của trang dùng
     * `$request->user()->currentOrganization->campaigns()` — tin thẳng vào cột
     * đó, nên nó trả về toàn bộ danh sách chiến dịch của một tổ chức người này
     * không có quyền gì.
     *
     * Cột đó client đổi được (có bộ chuyển tổ chức), nên nó là **đầu vào**,
     * không phải một sự thật.
     */
    public function test_current_organization_tro_sang_to_chuc_khong_la_thanh_vien_thi_rong(): void
    {
        $toChucKhac   = Organization::factory()->create(['status' => 'active']);
        $cuaNguoiKhac = $this->campaign(['name' => 'Bí mật'], $toChucKhac);

        // Vẫn là thành viên tổ chức của mình, nhưng trỏ sang tổ chức khác.
        $this->buyer->update(['current_organization_id' => $toChucKhac->id]);

        $than = $this->actingAs($this->buyer)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->getContent();

        $this->assertStringNotContainsString($cuaNguoiKhac->id, $than);
    }

    /**
     * Tạm ngưng một tổ chức phải có hiệu lực ở mọi cửa, kể cả cửa đọc.
     *
     * `CampaignPolicy::membership()` kiểm điều này; phép so `organization_id`
     * bằng tay mà trang cũ dùng thì không.
     */
    public function test_to_chuc_bi_tam_ngung_thi_danh_sach_rong(): void
    {
        $this->campaign();

        $this->org->update(['status' => 'suspended']);

        $this->actingAs($this->buyer)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Vai trò `viewer` CÓ `view_campaigns`, nên vẫn đọc được danh sách.
     *
     * Nửa này quan trọng không kém: chặn cả `viewer` là chặn quá tay, và một
     * người được mời vào để xem báo cáo sẽ thấy một trang trống.
     */
    public function test_vai_chi_xem_van_doc_duoc_danh_sach(): void
    {
        $this->campaign();

        $viewer = $this->makeMember($this->org, OrganizationUser::ROLE_VIEWER);

        $this->actingAs($viewer)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── Phân trang ──────────────────────────────────────────────────────────

    public function test_phan_trang_mac_dinh_20_moi_trang(): void
    {
        for ($i = 0; $i < 23; $i++) {
            $this->campaign(['name' => 'CD ' . $i]);
        }

        $this->actingAs($this->buyer)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 23)
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($this->buyer)
            ->getJson(self::URL . '?page=2')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.page', 2);
    }

    /**
     * Giới hạn cứng, và **nói ra** mức chặn.
     *
     * CLAUDE.md mục 2. `max_per_page` ra ngoài để client biết mức chặn thay vì
     * phải đoán từ việc kết quả ngắn hơn mình xin — và một client đoán sai sẽ
     * tưởng mình đã lấy hết dữ liệu.
     */
    public function test_per_page_bi_kep_va_muc_chan_duoc_noi_ra(): void
    {
        $this->campaign();

        $this->actingAs($this->buyer)
            ->getJson(self::URL . '?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.max_per_page', 100);
    }

    public function test_per_page_khong_hop_le_thi_422(): void
    {
        $this->campaign();

        $this->actingAs($this->buyer)
            ->getJson(self::URL . '?per_page=0')
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    public function test_sap_moi_nhat_truoc(): void
    {
        // `forceFill` chứ không `update`: `created_at` không nằm trong
        // `$fillable` của `Campaign`, nên gán hàng loạt bỏ qua nó im lặng — và
        // hai bản ghi tạo trong cùng một giây sẽ xếp theo thứ tự tuỳ ý, làm
        // test này xanh hoặc đỏ ngẫu nhiên.
        $cu = $this->campaign(['name' => 'Cũ']);
        $cu->forceFill(['created_at' => now()->subDays(5)])->save();

        $moi = $this->campaign(['name' => 'Mới']);
        $moi->forceFill(['created_at' => now()])->save();

        $this->assertTrue(
            $cu->fresh()->created_at->lt($moi->fresh()->created_at),
            'mốc thời gian không được ghi — test sắp xếp sẽ vô nghĩa',
        );

        $this->actingAs($this->buyer)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Mới')
            ->assertJsonPath('data.1.name', 'Cũ');
    }

    // ── Bộ lọc ──────────────────────────────────────────────────────────────

    public function test_loc_theo_trang_thai(): void
    {
        $this->campaign(['name' => 'Nháp', 'status' => Campaign::STATUS_DRAFT]);
        $this->campaign(['name' => 'Đang chạy', 'status' => Campaign::STATUS_ACTIVE]);

        $this->actingAs($this->buyer)
            ->getJson(self::URL . '?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Đang chạy');
    }

    /**
     * Trạng thái sai thì **422 kèm danh sách hợp lệ**, không phải danh sách rỗng.
     *
     * Trả rỗng không rò dữ liệu — phạm vi đã hẹp về một tổ chức. Nhưng nó để
     * client không phân biệt được "không có chiến dịch nào ở trạng thái này"
     * với "tôi gõ sai tên trạng thái", và cả hai đều dẫn tới một màn hình
     * trống không giải thích gì.
     */
    public function test_trang_thai_khong_co_trong_enum_thi_422(): void
    {
        $this->campaign();

        $this->actingAs($this->buyer)
            ->getJson(self::URL . '?status=khong-ton-tai')
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'message', 'code', 'details'])
            ->assertJsonPath('details.0.field', 'status');
    }

    public function test_tim_theo_ten_hoac_ma(): void
    {
        $this->campaign(['name' => 'Chiến dịch Tết 2027', 'code' => 'CPN-TET27']);
        $this->campaign(['name' => 'Chiến dịch Hè', 'code' => 'CPN-HE27']);

        $this->actingAs($this->buyer)
            ->getJson(self::URL . '?q=Tết')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'CPN-TET27');

        $this->actingAs($this->buyer)
            ->getJson(self::URL . '?q=CPN-HE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Chiến dịch Hè');
    }

    /**
     * Tìm kiếm **không** vượt ra ngoài tổ chức.
     *
     * Ngoặc quanh nhóm `orWhere` là thứ duy nhất ngăn điều đó: thiếu nó thì
     * `organization_id = X AND name LIKE … OR code LIKE …` đọc thành
     * `(… AND …) OR (code LIKE …)`, tức một mã trùng ở tổ chức khác cũng ra —
     * đúng kiểu rò rỉ mà `InventoryController` và `FrontpageService` từng mắc.
     */
    public function test_tim_kiem_khong_vuot_ra_ngoai_to_chuc(): void
    {
        $toChucKhac = Organization::factory()->create(['status' => 'active']);
        $cuaNguoiKhac = $this->campaign(
            ['name' => 'Bí mật của người khác', 'code' => 'CPN-BIMAT'],
            $toChucKhac,
        );

        $this->actingAs($this->buyer)
            ->getJson(self::URL . '?q=CPN-BIMAT')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->buyer)
            ->getJson(self::URL . '?q=Bí mật')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertStringNotContainsString(
            $cuaNguoiKhac->id,
            $this->actingAs($this->buyer)->getJson(self::URL . '?q=CPN')->getContent(),
        );
    }

    /**
     * `%` và `_` trong từ khóa là chữ, không phải ký tự đại diện.
     *
     * Không thoát thì `q=%` khớp **mọi** chiến dịch, và người dùng tưởng mình
     * tìm được thứ mình gõ.
     */
    public function test_ky_tu_dai_dien_trong_tu_khoa_duoc_coi_la_chu(): void
    {
        $this->campaign(['name' => 'Chiến dịch A']);
        $this->campaign(['name' => 'Giảm 50% mùa hè']);

        $this->actingAs($this->buyer)
            ->getJson(self::URL . '?q=' . urlencode('50%'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Giảm 50% mùa hè');
    }

    public function test_tu_khoa_qua_dai_thi_422(): void
    {
        $this->campaign();

        $this->actingAs($this->buyer)
            ->getJson(self::URL . '?q=' . str_repeat('a', 101))
            ->assertStatus(422)
            ->assertJsonPath('details.0.field', 'q');
    }

    // ── DTO ─────────────────────────────────────────────────────────────────

    public function test_nhan_trang_thai_do_may_chu_tra_ve(): void
    {
        $this->campaign(['status' => Campaign::STATUS_PENDING]);

        $this->actingAs($this->buyer)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.status', 'pending_approval')
            // Một định nghĩa cho cả hệ thống. Client tự dịch là hai bộ chữ cho
            // cùng một trạng thái, và chúng lệch nhau ngay lần đổi đầu tiên.
            ->assertJsonPath('data.0.status_label', Campaign::STATUS_LABELS['pending_approval']);
    }

    /**
     * Danh sách KHÔNG mang theo sáu khối của trang chi tiết.
     *
     * `cancel_quotes` chạy một lượt đọc bảng tiền cho mỗi dòng đặt chỗ. Nhân
     * với 20 chiến dịch một trang là hàng trăm lượt đọc cho một màn hình không
     * có nút hủy nào.
     */
    public function test_danh_sach_khong_mang_theo_khoi_cua_trang_chi_tiet(): void
    {
        $this->campaign();

        $than = $this->actingAs($this->buyer)
            ->getJson(self::URL)
            ->assertOk()
            ->getContent();

        foreach (['cancel_quotes', 'refund_policy', 'activities', 'my_reviews', 'reviewable_owners', 'conflicts'] as $khoi) {
            $this->assertStringNotContainsString(
                $khoi,
                $than,
                "Danh sách mang theo \"{$khoi}\" — khối đó thuộc `GET campaigns/{campaign}`.",
            );
        }
    }

    public function test_khong_lo_khoa_noi_bo_cua_to_chuc(): void
    {
        $this->campaign();

        $than = $this->actingAs($this->buyer)
            ->getJson(self::URL)
            ->assertOk()
            ->getContent();

        foreach (['organization_id', 'created_by', 'billing_info', 'credit_limit'] as $camKy) {
            $this->assertStringNotContainsString($camKy, $than, "Danh sách để lộ \"{$camKy}\".");
        }
    }
}
