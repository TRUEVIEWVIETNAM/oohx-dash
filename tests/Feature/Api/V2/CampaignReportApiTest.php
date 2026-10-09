<?php

namespace Tests\Feature\Api\V2;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `GET /api/v2/campaigns/{campaign}/report` — báo cáo phát sóng.
 *
 * Mở đường cho trang Blade cuối cùng còn **nhúng** dữ liệu vào HTML: bản cũ
 * viết `@json($dailyData)` thẳng vào khối script.
 *
 * Bốn thứ tệp này canh:
 *
 *  1. **Phạm vi là một phép phân quyền** — `viewReports`, tức tư cách thành
 *     viên của tổ chức *sở hữu chiến dịch*, không phải
 *     `current_organization_id`.
 *  2. **404 là câu trả lời nghiệp vụ**, kèm envelope thống nhất: chiến dịch
 *     chưa chạy thì chưa có báo cáo.
 *  3. **Danh sách trắng hẹp hơn cái service trả.** Bốn trường cố ý không ra
 *     ngoài, và một ca đòi chúng vắng mặt — vì "không ai dùng" là lý do bỏ
 *     chúng, và lý do đó chỉ bền nếu có người canh.
 *  4. **Tiền là số nguyên.** Service trả `float`.
 */
class CampaignReportApiTest extends TestCase
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
        $this->buyer = User::factory()->create(['current_organization_id' => $this->org->id]);
        $this->buyer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);
    }

    private function campaign(string $status = Campaign::STATUS_ACTIVE): Campaign
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'city' => 'Hà Nội']);
        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'name'     => 'Màn hình Nguyễn Trãi',
        ]);

        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'CD báo cáo',
            'start_date'      => now()->subDays(4),
            'end_date'        => now()->addDays(5),
            'status'          => $status,
        ]);

        BookingLine::create([
            'campaign_id'           => $campaign->id,
            'screen_id'             => $screen->id,
            'owner_id'              => $owner->id,
            'start_date'            => now()->subDays(4),
            'end_date'              => now()->addDays(5),
            'spot_length'           => 10,
            'status'                => BookingLine::STATUS_ACTIVE,
            'estimated_cost'        => 10_000_000,
            'actual_cost'           => 8_400_000.75,
            'estimated_impressions' => 500_000,
            'actual_impressions'    => 420_000,
        ]);

        return $campaign;
    }

    private function url(Campaign $campaign): string
    {
        return '/api/v2/campaigns/' . $campaign->id . '/report';
    }

    // ── Cửa vào ─────────────────────────────────────────────────────────────

    public function test_chua_dang_nhap_thi_khong_doc_duoc(): void
    {
        $this->getJson($this->url($this->campaign()))
            ->assertStatus(401)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    public function test_bi_go_khoi_to_chuc_thi_403(): void
    {
        $campaign = $this->campaign();

        $toChucKhac = Organization::factory()->create(['status' => 'active']);
        OrganizationUser::create([
            'organization_id' => $toChucKhac->id,
            'user_id'         => $this->buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        OrganizationUser::where('organization_id', $this->org->id)
            ->where('user_id', $this->buyer->id)
            ->delete();

        $this->actingAs($this->buyer)->getJson($this->url($campaign))->assertStatus(403);
    }

    public function test_to_chuc_tam_ngung_thi_403(): void
    {
        $campaign = $this->campaign();

        $this->org->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->actingAs($this->buyer)->getJson($this->url($campaign))->assertStatus(403);
    }

    // ── 404 là câu trả lời nghiệp vụ ────────────────────────────────────────

    /**
     * Chiến dịch chưa chạy thì chưa có báo cáo — và nói đúng câu đó.
     *
     * 404 ở đây không phải "không có đường". Nên nó phải mang envelope thống
     * nhất với mã riêng, để client phân biệt được với một 404 route.
     */
    public function test_trang_thai_khong_co_bao_cao_thi_404_kem_envelope(): void
    {
        foreach ([Campaign::STATUS_DRAFT, Campaign::STATUS_PENDING, Campaign::STATUS_APPROVED] as $ma) {
            $this->actingAs($this->buyer)
                ->getJson($this->url($this->campaign($ma)))
                ->assertStatus(404)
                ->assertJsonStructure(['error', 'message', 'code', 'details'])
                ->assertJsonPath('error', 'report_not_available');
        }
    }

    public function test_ba_trang_thai_co_bao_cao_deu_tra_200(): void
    {
        foreach (
            [Campaign::STATUS_ACTIVE, Campaign::STATUS_PAUSED, Campaign::STATUS_COMPLETED] as $ma
        ) {
            $this->actingAs($this->buyer)
                ->getJson($this->url($this->campaign($ma)))
                ->assertOk()
                ->assertJsonStructure(['data' => ['overview', 'daily', 'breakdown']]);
        }
    }

    // ── Hình dạng ───────────────────────────────────────────────────────────

    public function test_tien_la_so_nguyen_o_moi_truong(): void
    {
        $than = $this->actingAs($this->buyer)
            ->getJson($this->url($this->campaign()))
            ->assertOk()
            ->json('data');

        foreach (['total_estimated_cost', 'total_actual_cost'] as $khoa) {
            $this->assertIsInt($than['overview'][$khoa], "overview.{$khoa} phải là số nguyên");
        }

        // `actual_cost` của dòng là 8.400.000,75 — làm tròn ở bước cuối.
        $this->assertIsInt($than['breakdown'][0]['actual_cost']);
        $this->assertSame(8_400_001, $than['breakdown'][0]['actual_cost']);
    }

    /**
     * Bốn trường cố ý **không** ra ngoài.
     *
     * `daily.revenue` là doanh thu gộp theo ngày; biểu đồ chỉ vẽ impressions.
     * `breakdown[].estimated_cost` và `.status` không có cột nào trong bảng.
     * `overview.total_paid` thì trang không hiện — số đã trả nằm ở
     * `GET campaigns/{campaign}/payments`.
     *
     * Ca này tồn tại vì "không ai dùng" là lý do bỏ chúng, và lý do đó chỉ bền
     * nếu có người canh: nới DTO cho tiện là cách mọi danh sách trắng rộng ra.
     */
    public function test_khong_phat_truong_khong_ai_dung(): void
    {
        $campaign = $this->campaign();

        Payment::create([
            'campaign_id'     => $campaign->id,
            'organization_id' => $this->org->id,
            'amount'          => 5_000_000,
            'currency'        => 'VND',
            // Chuỗi rời, không `Payment::METHOD_BANK_TRANSFER`: hằng đó nằm ở
            // PR #51 và nhánh này không phụ thuộc vào nó.
            'method'          => 'bank_transfer',
            'status'          => Payment::STATUS_COMPLETED,
            'idempotency_key' => Str::random(16),
        ]);

        $than = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('total_paid', $than['overview']);
        $this->assertArrayNotHasKey('revenue', $than['daily']);
        $this->assertArrayNotHasKey('estimated_cost', $than['breakdown'][0]);
        $this->assertArrayNotHasKey('status', $than['breakdown'][0]);
    }

    public function test_chi_tiet_man_hinh_mang_du_truong_trang_can(): void
    {
        $than = $this->actingAs($this->buyer)
            ->getJson($this->url($this->campaign()))
            ->assertOk()
            ->json('data.breakdown.0');

        $this->assertSame([
            'screen_name', 'owner_name', 'city', 'dates',
            'estimated_impressions', 'actual_impressions', 'delivery_rate', 'actual_cost',
        ], array_keys($than));

        $this->assertSame('Màn hình Nguyễn Trãi', $than['screen_name']);
        $this->assertSame('Hà Nội', $than['city']);
    }

    /**
     * `labels` và `impressions` phải **cùng độ dài**.
     *
     * Biểu đồ ghép hai mảng theo chỉ số. Lệch một phần tử thì mọi cột lệch một
     * ngày, và không có gì trên trang nói ra.
     */
    public function test_chuoi_theo_ngay_hai_mang_cung_do_dai(): void
    {
        $than = $this->actingAs($this->buyer)
            ->getJson($this->url($this->campaign()))
            ->assertOk()
            ->json('data.daily');

        $this->assertNotEmpty($than['labels']);
        $this->assertSameSize($than['labels'], $than['impressions']);

        foreach ($than['impressions'] as $n) {
            $this->assertIsInt($n);
        }
    }
}
