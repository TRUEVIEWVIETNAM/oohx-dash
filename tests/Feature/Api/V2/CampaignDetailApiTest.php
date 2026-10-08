<?php

namespace Tests\Feature\Api\V2;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerReview;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sáu khối mở rộng của `GET /api/v2/campaigns/{campaign}` — 08/10/2026.
 *
 * `stats`, `cancel_quotes`, `refund_policy`, `reviewable_owners`,
 * `my_reviews`, `activities` + `activity_count`. Chúng mở đường cho
 * `/my/campaigns/{campaign}` — **action đọc cuối cùng** của khu người mua còn
 * dựng dữ liệu từ model.
 *
 * Những thứ tệp này canh, và vì sao từng thứ:
 *
 *  1. **Sáu khối chỉ có ở đường ĐỌC.** Ba đường ghi cùng trả `BookingReview`,
 *     và nếu sáu khối kia lọt vào đó thì mỗi lần tải một tệp quảng cáo sẽ chạy
 *     lại toàn bộ báo giá hoàn tiền.
 *  2. **`cancel_quotes` rỗng khi thiếu quyền `manage_payments`.** Vai trò
 *     `viewer` xem được chiến dịch nhưng không được dẫn tới một nút hủy.
 *  3. **Giới hạn cứng 50 dòng lịch sử**, kèm tổng số — không im lặng cắt.
 *  4. **`metadata` của lịch sử không ra ngoài.** Nó đã chứa IP và user agent
 *     của người đọc thông tin nhận tiền.
 *  5. **`moderation_note` của đánh giá không ra ngoài.** Ghi chú nội bộ của
 *     người kiểm duyệt, viết cho nội bộ.
 *  6. **Số liệu và chính sách đến từ máy chủ**, không để client cộng lại hay
 *     chép cứng phần trăm.
 */
class CampaignDetailApiTest extends TestCase
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

    private function owner(string $name = 'Kim Ngân ADV'): Owner
    {
        return Owner::factory()->create([
            'name'   => $name,
            'slug'   => Str::slug($name) . '-' . uniqid(),
            'status' => 'active',
            // Đặt sẵn những thứ KHÔNG được ra ngoài, để phép kiểm danh sách
            // trắng có cái để bắt.
            'revenue_share_pct'     => 63.17,
            'tax_code'              => '0101234567',
            'bank_account_number'   => '0011001234567',
            'business_license_path' => 'giay-phep/x.pdf',
        ]);
    }

    /**
     * Chiến dịch với các dòng đặt chỗ.
     *
     * Ngày bắt đầu đặt **xa trong tương lai** theo mặc định: `quote()` tính
     * theo số ngày còn lại tới ngày chạy, nên một chiến dịch bắt đầu hôm nay
     * luôn rơi vào mốc 0% và không phân biệt được các mốc chính sách.
     *
     * @param array<string, int> $ownerCosts
     */
    private function campaign(
        array $ownerCosts,
        string $status = Campaign::STATUS_APPROVED,
        string $lineStatus = 'approved',
        int $ngayNua = 30,
    ): Campaign {
        $start = now()->addDays($ngayNua);

        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'Chiến dịch thử',
            'start_date'      => $start,
            'end_date'        => $start->copy()->addMonth(),
            'status'          => $status,
            'total_impressions_estimated' => 1_000_000,
        ]);

        foreach ($ownerCosts as $ownerId => $cost) {
            $site   = Site::factory()->create(['owner_id' => $ownerId]);
            $screen = Screen::factory()->create([
                'owner_id'     => $ownerId,
                'site_id'      => $site->id,
                'device_token' => 'bi-mat-cua-thiet-bi',
            ]);

            BookingLine::create([
                'campaign_id'           => $campaign->id,
                'screen_id'             => $screen->id,
                'owner_id'              => $ownerId,
                'start_date'            => $start,
                'end_date'              => $start->copy()->addMonth(),
                'status'                => $lineStatus,
                'estimated_cost'        => $cost,
                'estimated_impressions' => 500_000,
                'actual_impressions'    => 250_000,
                'floor_cpm_at_booking'  => 50_000,
            ]);
        }

        return $campaign->fresh();
    }

    private function url(Campaign $campaign): string
    {
        return '/api/v2/campaigns/' . $campaign->id;
    }

    // ── Hình dạng ───────────────────────────────────────────────────────────

    public function test_sau_khoi_moi_deu_co_mat(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    // Khối dùng chung vẫn còn nguyên — mở rộng không được làm
                    // mất thứ ba đường ghi đang dựa vào.
                    'campaign', 'lines', 'creatives', 'conflicts', 'summary',

                    'stats' => ['currency', 'line_count', 'estimated_cost', 'actual_impressions', 'delivery_rate_pct'],
                    'cancel_quotes',
                    'refund_policy',
                    'reviewable_owners',
                    'my_reviews',
                    'activities',
                    'activity_count',
                ],
            ]);
    }

    /**
     * Sáu khối KHÔNG được lọt vào phản hồi của đường ghi.
     *
     * `POST campaigns` trả `BookingReview`. Nếu `detailPayload()` bị gộp vào
     * phần dùng chung thì mỗi lần tạo chiến dịch và mỗi lần tải một tệp quảng
     * cáo sẽ chạy `CancellationService::quote()` cho từng dòng — một phép tính
     * không ai hỏi, trên đường người dùng đang chờ.
     */
    public function test_duong_ghi_khong_mang_theo_sau_khoi_doc(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000], Campaign::STATUS_DRAFT, 'pending');

        $than = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns/' . $campaign->id . '/submit', [
                'accept_terms'     => true,
                'confirm_accuracy' => true,
            ])
            ->assertOk()
            ->json('data');

        foreach (['stats', 'cancel_quotes', 'refund_policy', 'reviewable_owners', 'my_reviews', 'activities', 'activity_count'] as $khoi) {
            $this->assertArrayNotHasKey(
                $khoi,
                $than,
                "Đường ghi mang theo \"{$khoi}\" — khối đó chỉ thuộc đường đọc.",
            );
        }

        // Và vẫn phải trả đủ khối dùng chung.
        $this->assertArrayHasKey('summary', $than);
    }

    // ── Số liệu ─────────────────────────────────────────────────────────────

    public function test_so_lieu_tinh_o_may_chu(): void
    {
        $a = $this->owner('A');
        $b = $this->owner('B');
        $campaign = $this->campaign([$a->id => 10_000_000, $b->id => 7_000_000]);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonPath('data.stats.currency', 'VND')
            ->assertJsonPath('data.stats.line_count', 2)
            ->assertJsonPath('data.stats.estimated_cost', 17_000_000)
            ->assertJsonPath('data.stats.actual_impressions', 500_000)
            // 500.000 / 1.000.000 = 50%. JSON tuần tự hoá `50.0` thành `50`,
            // nên so với số nguyên — phần thập phân có test riêng bên dưới.
            ->assertJsonPath('data.stats.delivery_rate_pct', 50);
    }

    /**
     * `delivery_rate_pct` giữ một chữ số thập phân, không bị cắt về số nguyên.
     *
     * Đây là **phần trăm**, không phải tiền, nên luật "VND số nguyên" của
     * CLAUDE.md mục 6 không áp vào nó. Ép về `int` ở biên — phản xạ đúng với
     * mọi trường tiền khác trong các DTO này — sẽ biến 35,7% thành 35%, và một
     * báo cáo giao nhận sai gần một phần trăm là sai.
     */
    public function test_delivery_rate_giu_phan_thap_phan(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        // 250.000 / 700.000 = 35,714…% → làm tròn một chữ số = 35,7
        $campaign->update(['total_impressions_estimated' => 700_000]);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonPath('data.stats.delivery_rate_pct', 35.7);
    }

    /**
     * `estimated_cost` là tổng CHƯA gồm VAT.
     *
     * VAT cộng một chỗ duy nhất: `PaymentService::withVat()`, lúc tính công
     * nợ. Hai chỗ làm tròn khác nhau là cách sinh ra "công nợ bằng 0 nhưng
     * chưa trả đủ" (Codex R09).
     */
    public function test_so_lieu_chua_gom_vat(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonPath('data.stats.estimated_cost', 10_000_000);

        // Và đúng con số đó, cộng VAT, mới là số phải trả.
        $this->assertSame(
            app(PaymentService::class)->withVat(10_000_000),
            (int) round($this->actingAs($this->buyer)
                ->getJson('/api/v2/campaigns/' . $campaign->id . '/payments')
                ->json('data.summary.total_cost_vat')),
        );
    }

    // ── Báo giá hủy ─────────────────────────────────────────────────────────

    public function test_bao_gia_huy_co_cho_dong_con_huy_duoc(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000], ngayNua: 30);

        $bao = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->json('data.cancel_quotes');

        $this->assertCount(1, $bao);
        $this->assertSame($campaign->bookingLines->first()->id, $bao[0]['booking_line_id']);
        $this->assertSame('VND', $bao[0]['currency']);
        $this->assertSame(30, $bao[0]['days_before']);
        $this->assertSame(100, $bao[0]['refund_pct'], 'còn 30 ngày thì mốc 14 ngày áp dụng');

        // Máy chủ tự nói đây là ảnh chụp, để client không phải tự biết.
        $this->assertTrue($bao[0]['is_estimate']);
    }

    public function test_bao_gia_huy_bo_qua_dong_da_huy_da_xong_bi_tu_choi(): void
    {
        foreach (['cancelled', 'completed', 'rejected'] as $trangThai) {
            $owner    = $this->owner('O-' . $trangThai);
            $campaign = $this->campaign([$owner->id => 10_000_000], lineStatus: $trangThai);

            $this->actingAs($this->buyer)
                ->getJson($this->url($campaign))
                ->assertOk()
                ->assertJsonCount(0, 'data.cancel_quotes');
        }
    }

    /**
     * Vai trò `viewer` xem được chiến dịch nhưng KHÔNG nhận báo giá hủy.
     *
     * Quyền `cancel` xếp cùng `manage_payments` vì hủy kéo theo nghĩa vụ hoàn
     * tiền. Mảng rỗng ở đây là gợi ý cho giao diện — việc chặn thật ở đường
     * hủy — nhưng vẽ nút cho người không có quyền là dẫn họ tới một hành động
     * sẽ bị từ chối, trên đường có hệ quả tiền.
     */
    public function test_viewer_xem_duoc_nhung_khong_nhan_bao_gia_huy(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $viewer = $this->makeMember($this->org, OrganizationUser::ROLE_VIEWER);

        $this->actingAs($viewer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonCount(0, 'data.cancel_quotes');

        // Người có quyền thì vẫn nhận — nếu không thì đây là chặn quá tay chứ
        // không phải siết đúng chỗ.
        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonCount(1, 'data.cancel_quotes');
    }

    // ── Chính sách hủy ──────────────────────────────────────────────────────

    public function test_chinh_sach_huy_lay_tu_cung_cau_hinh_ma_service_doc(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $chinhSach = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->json('data.refund_policy');

        $this->assertSame(
            array_map(fn ($t) => [
                'min_days_before' => (int) $t['min_days_before'],
                'refund_pct'      => (int) $t['refund_pct'],
            ], config('pricing.refund_tiers')),
            $chinhSach,
            'ra ngoài để client KHÔNG chép cứng phần trăm — nên nó phải khớp cấu hình',
        );
    }

    // ── Đánh giá ────────────────────────────────────────────────────────────

    public function test_campaign_chua_chay_thi_khong_co_owner_nao_de_danh_gia(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000], Campaign::STATUS_APPROVED);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonCount(0, 'data.reviewable_owners');
    }

    public function test_campaign_da_chay_thi_owner_ra_danh_sach_danh_gia(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000], Campaign::STATUS_ACTIVE, 'active');

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonCount(1, 'data.reviewable_owners')
            ->assertJsonPath('data.reviewable_owners.0.id', $owner->id)
            ->assertJsonPath('data.reviewable_owners.0.name', 'Kim Ngân ADV');
    }

    public function test_owner_da_duoc_danh_gia_thi_khong_con_trong_danh_sach(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000], Campaign::STATUS_ACTIVE, 'active');

        OwnerReview::create([
            'campaign_id'     => $campaign->id,
            'owner_id'        => $owner->id,
            'organization_id' => $this->org->id,
            'user_id'         => $this->buyer->id,
            'rating'          => 4,
            'comment'         => 'Đúng hẹn.',
            'status'          => OwnerReview::STATUS_PENDING,
            'moderation_note' => 'GHI CHÚ NỘI BỘ CỦA NGƯỜI KIỂM DUYỆT',
        ]);

        $than = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonCount(0, 'data.reviewable_owners')
            ->assertJsonCount(1, 'data.my_reviews')
            ->assertJsonPath('data.my_reviews.0.rating', 4)
            ->assertJsonPath('data.my_reviews.0.owner.name', 'Kim Ngân ADV')
            ->assertJsonPath('data.my_reviews.0.status', 'pending')
            // Nhãn đến từ máy chủ, cùng bảng chữ mà khu quản trị dùng — client
            // tự dịch là có hai bộ chữ cho một trạng thái.
            ->assertJsonPath('data.my_reviews.0.status_label', OwnerReview::STATUS_LABELS['pending'])
            ->getContent();

        // Ghi chú kiểm duyệt viết cho nội bộ. Trả nó ra là biến một ghi chú nội
        // bộ thành câu trả lời chính thức gửi cho khách, và người viết nó không
        // biết mình đang viết cho khách.
        $this->assertStringNotContainsString('moderation_note', $than);
        $this->assertStringNotContainsString('GHI CHÚ NỘI BỘ', $than);
    }

    // ── Lịch sử hoạt động ───────────────────────────────────────────────────

    public function test_lich_su_ra_ngoai_kem_nguoi_lam(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        CampaignActivity::log($campaign, 'approved', 'Campaign được duyệt', $this->buyer->id);
        CampaignActivity::log($campaign, 'cancelled', 'Hệ thống tự hủy', null);

        $than = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $than['activity_count']);
        $this->assertCount(2, $than['activities']);

        $theoViec = collect($than['activities'])->keyBy('action');

        $this->assertSame($this->buyer->name, $theoViec['approved']['actor']['name']);

        // `null` nghĩa là hệ thống tự làm, không phải "không biết ai" — client
        // hiện "Hệ thống".
        $this->assertNull($theoViec['cancelled']['actor']);
    }

    /**
     * `metadata` không ra ngoài.
     *
     * Cột tự do, do tầng trong ghi vào, và nó **đã** chứa IP với user agent của
     * người đọc thông tin nhận tiền (`remittance_details_viewed`). Trả một cột
     * tự do ra ngoài là hứa một hợp đồng mà không ai kiểm được: lần sau có
     * người ghi thêm một khóa, nó ra ngoài ngay và không test nào đỏ.
     */
    public function test_metadata_cua_lich_su_khong_ra_ngoai(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        CampaignActivity::log(
            $campaign,
            'remittance_details_viewed',
            'Xem thông tin nhận tiền của 1 media owner',
            $this->buyer->id,
            ['owner_ids' => [$owner->id], 'ip' => '203.0.113.9', 'user_agent' => 'Mozilla/5.0 BI-MAT'],
        );

        $response = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk();

        // Các khóa và giá trị cấm đều là ASCII, nên tìm trên chuỗi thô là
        // chính xác. Phần tiếng Việt thì KHÔNG: `json_encode` của Laravel
        // escape nó thành `\uXXXX`, nên một phép tìm chuỗi thô sẽ luôn thất
        // bại dù dữ liệu đúng — tìm trên mảng đã giải mã.
        $tho = $response->getContent();

        foreach (['metadata', '203.0.113.9', 'BI-MAT', 'user_agent', 'owner_ids'] as $camKy) {
            $this->assertStringNotContainsString(
                $camKy,
                $tho,
                "Lịch sử để lộ \"{$camKy}\" — `metadata` là cột tự do, không phải hợp đồng.",
            );
        }

        // Nhưng dòng đó vẫn phải HIỆN RA: nó là nhật ký truy cập của chính
        // người mua, và họ có quyền biết ai đã xem thông tin nhận tiền của
        // chiến dịch mình.
        $this->assertSame(
            'Xem thông tin nhận tiền của 1 media owner',
            $response->json('data.activities.0.description'),
        );
        $this->assertSame('remittance_details_viewed', $response->json('data.activities.0.action'));
    }

    /**
     * Giới hạn cứng, và nói ra là mình đã cắt.
     *
     * CLAUDE.md mục 2 đòi mọi danh sách có giới hạn cứng. Lịch sử mọc theo
     * **lượt đọc** từ 08/10/2026, nên không chặn là trả về một phản hồi lớn
     * dần mà không ai để ý.
     */
    public function test_lich_su_cat_o_50_dong_va_noi_ra_tong_so(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        for ($i = 1; $i <= 57; $i++) {
            CampaignActivity::log($campaign, 'x', 'Việc số ' . $i, $this->buyer->id);
        }

        $than = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->json('data');

        $this->assertCount(50, $than['activities'], 'giới hạn cứng 50 dòng');
        $this->assertSame(57, $than['activity_count'], 'tổng số phải nói ra, không im lặng cắt');
    }

    public function test_lich_su_lay_dong_moi_nhat_chu_khong_phai_khuc_tuy_y(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        // Ghi lùi về quá khứ để thứ tự không phụ thuộc vào độ phân giải của
        // `now()` trong một vòng lặp chạy nhanh.
        for ($i = 1; $i <= 55; $i++) {
            CampaignActivity::create([
                'campaign_id' => $campaign->id,
                'user_id'     => $this->buyer->id,
                'action'      => 'x',
                'description' => 'Việc số ' . $i,
                'created_at'  => now()->subMinutes(100 - $i),
            ]);
        }

        $moTa = collect($this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->json('data.activities'))->pluck('description');

        $this->assertSame('Việc số 55', $moTa->first(), 'dòng mới nhất phải ở đầu');
        $this->assertSame('Việc số 6', $moTa->last(), '50 dòng mới nhất là từ 55 xuống 6');
        $this->assertFalse($moTa->contains('Việc số 5'), 'dòng cũ hơn phải bị cắt');
    }

    // ── DTO danh sách trắng ─────────────────────────────────────────────────

    public function test_khoi_mo_rong_khong_lo_du_lieu_rieng_cua_owner(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000], Campaign::STATUS_ACTIVE, 'active');

        $than = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->getContent();

        foreach ([
            'revenue_share_pct', '63.17',
            'business_license_path', 'giay-phep/',
            'bank_account_number', '0011001234567',
            'tax_code', '0101234567',
            'billing_info', 'device_token', 'bi-mat-cua-thiet-bi',
            'floor_cpm_at_booking',
        ] as $camKy) {
            $this->assertStringNotContainsString(
                $camKy,
                $than,
                "Khối mở rộng để lộ \"{$camKy}\". `OwnerToReview` là danh sách TRẮNG.",
            );
        }
    }

    // ── Cửa vào ─────────────────────────────────────────────────────────────

    public function test_nguoi_to_chuc_khac_nhan_404(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $nguoiLa = $this->makeMember(
            Organization::factory()->create(['status' => 'active']),
            OrganizationUser::ROLE_ADMIN,
        );

        $this->actingAs($nguoiLa)->getJson($this->url($campaign))->assertStatus(404);
    }

    public function test_chua_dang_nhap_thi_khong_doc_duoc(): void
    {
        $owner    = $this->owner();
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->getJson($this->url($campaign))
            ->assertStatus(401)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }
}
