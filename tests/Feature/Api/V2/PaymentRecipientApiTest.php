<?php

namespace Tests\Feature\Api\V2;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\CampaignActivity;
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
 * `GET campaigns/{campaign}/payment-recipients` — nơi chuyển tiền tới.
 *
 * Đây là đường **duy nhất** của cả hệ thống mang `bank_*` và `tax_code` ra
 * ngoài. Ngoại lệ của CLAUDE.md mục 2, được duyệt 08/10/2026 vì sàn không thu
 * hộ: người mua chuyển thẳng cho từng media owner nên không thấy nơi nhận tiền
 * thì không trả được.
 *
 * Một endpoint phơi dữ liệu rộng hơn một trang render phía máy chủ — nó cache
 * được, gọi lại bằng script được, nằm trong lịch sử của client. Nên mỗi lớp bù
 * lại phải có một test đứng canh, và đó là nội dung của tệp này:
 *
 *  1. Chỉ owner **CÓ dòng còn hiệu lực** trong campaign này ra ngoài.
 *  2. Cần quyền `manage_payments`, **không phải** quyền xem — vai trò `viewer`
 *     xem được công nợ nhưng nhận 403 ở đây. Đây là siết chặt so với trang
 *     Blade cũ, nên nó cần test riêng chứ không chỉ một dòng chú thích.
 *  3. Campaign chưa duyệt thì chưa có gì để trả, nên chưa có gì để đọc.
 *  4. Phản hồi không được cache ở bất kỳ tầng nào.
 *  5. Mỗi lần đọc để lại một dòng nhật ký — và không để lại hàng chục dòng.
 *  6. **Không có số tiền ở đây.** Tiền ở `GET payments`, và hai đường không
 *     được mang hai bản sao của cùng phép tính.
 */
class PaymentRecipientApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        // Cửa chống lụt nhật ký nằm ở cache. Không dọn thì test thứ hai trở đi
        // thấy cửa đang đóng và kết luận sai là "không ghi nhật ký".
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

    /**
     * Owner khai ĐỦ hồ sơ, gồm cả những trường vẫn phải nằm trong nhà.
     *
     * `revenue_share_pct` và `business_license_path` đặt sẵn có chủ ý: endpoint
     * này được phép trả nơi nhận tiền, và chính vì vậy phải có test chứng minh
     * nó **không** kéo theo phần ăn chia của sàn và đường dẫn tệp giấy phép.
     */
    private function owner(string $name, bool $khaiDu = true, bool $khaiMotNua = false): Owner
    {
        return Owner::factory()->create([
            'name'   => $name,
            'slug'   => Str::slug($name) . '-' . uniqid(),
            'status' => 'active',

            'legal_name' => 'CÔNG TY TNHH ' . mb_strtoupper($name),
            'tax_code'   => '0101234567',

            'revenue_share_pct'     => 63.17,
            'business_license_path' => 'giay-phep/' . uniqid() . '.pdf',

            'bank_name'           => ($khaiDu || $khaiMotNua) ? 'Vietcombank (VCB)' : null,
            'bank_account_number' => $khaiDu ? '0011001234567' : null,
            'bank_account_name'   => $khaiDu ? mb_strtoupper($name) : null,
            'bank_branch'         => $khaiDu ? 'Chi nhánh Hà Nội' : null,
        ]);
    }

    /** @param array<string, int> $ownerCosts */
    private function campaign(
        array $ownerCosts,
        string $status = Campaign::STATUS_APPROVED,
        string $lineStatus = 'approved',
    ): Campaign {
        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'Chiến dịch thử',
            'start_date'      => now(),
            'end_date'        => now()->addMonth(),
            'status'          => $status,
        ]);

        foreach ($ownerCosts as $ownerId => $cost) {
            $site   = Site::factory()->create(['owner_id' => $ownerId]);
            $screen = Screen::factory()->create([
                'owner_id'     => $ownerId,
                'site_id'      => $site->id,
                'device_token' => 'bi-mat-cua-thiet-bi',
            ]);

            BookingLine::create([
                'campaign_id'          => $campaign->id,
                'screen_id'            => $screen->id,
                'owner_id'             => $ownerId,
                'start_date'           => now(),
                'end_date'             => now()->addMonth(),
                'status'               => $lineStatus,
                'estimated_cost'       => $cost,
                'floor_cpm_at_booking' => 50_000,
            ]);
        }

        return $campaign;
    }

    private function url(Campaign $campaign): string
    {
        return '/api/v2/campaigns/' . $campaign->id . '/payment-recipients';
    }

    private function urlTien(Campaign $campaign): string
    {
        return '/api/v2/campaigns/' . $campaign->id . '/payments';
    }

    // ── Cửa vào ─────────────────────────────────────────────────────────────

    public function test_chua_dang_nhap_thi_khong_doc_duoc(): void
    {
        $owner = $this->owner('Kim Ngân ADV');

        $this->getJson($this->url($this->campaign([$owner->id => 10_000_000])))
            ->assertStatus(401)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    public function test_nguoi_to_chuc_khac_nhan_404_chu_khong_phai_403(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $nguoiLa = $this->makeMember(
            Organization::factory()->create(['status' => 'active']),
            OrganizationUser::ROLE_ADMIN,
        );

        // 404, không 403: campaign thuộc tổ chức khác, và việc nó có tồn tại
        // hay không cũng là thông tin riêng của tổ chức đó.
        $this->actingAs($nguoiLa)->getJson($this->url($campaign))->assertStatus(404);
    }

    /**
     * Lớp chặn số 2, và là thay đổi hành vi so với trang Blade cũ.
     *
     * Trang `/booking/{campaign}/payment` chỉ gọi quyền `view`, nên vai trò
     * `viewer` đang đọc được số tài khoản của media owner. Người cần số tài
     * khoản là người đi chuyển tiền, và đó là `manage_payments`.
     *
     * Test này canh cả hai nửa cùng lúc — `viewer` vẫn phải xem được công nợ,
     * nếu không thì đây là chặn quá tay chứ không phải siết đúng chỗ.
     */
    public function test_viewer_xem_duoc_cong_no_nhung_khong_xem_duoc_noi_nhan_tien(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $viewer = $this->makeMember($this->org, OrganizationUser::ROLE_VIEWER);

        $this->actingAs($viewer)->getJson($this->urlTien($campaign))->assertOk();

        $this->actingAs($viewer)->getJson($this->url($campaign))->assertStatus(403);
    }

    public function test_campaign_chua_duyet_thi_chua_co_gi_de_doc(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000], Campaign::STATUS_DRAFT);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    // ── Phạm vi: chỉ owner trong campaign này ───────────────────────────────

    public function test_tra_ve_noi_nhan_tien_cua_owner_trong_campaign(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonPath('data.campaign.code', $campaign->code)

            // Nội dung chuyển khoản do máy chủ đưa ra: đối soát dựa vào đúng
            // chuỗi này, nên hai client không được ghép hai kiểu.
            ->assertJsonPath('data.transfer_note', $campaign->code)

            ->assertJsonCount(1, 'data.recipients')
            ->assertJsonPath('data.recipients.0.id', $owner->id)
            ->assertJsonPath('data.recipients.0.legal_name', $owner->legal_name)
            ->assertJsonPath('data.recipients.0.tax_code', '0101234567')
            ->assertJsonPath('data.recipients.0.has_bank_details', true)
            ->assertJsonPath('data.recipients.0.bank_name', 'Vietcombank (VCB)')
            ->assertJsonPath('data.recipients.0.bank_account_number', '0011001234567')
            ->assertJsonPath('data.recipients.0.bank_branch', 'Chi nhánh Hà Nội');
    }

    public function test_owner_khong_co_man_hinh_trong_campaign_khong_ra_ngoai(): void
    {
        $trong    = $this->owner('Kim Ngân ADV');
        $nguoiLa  = $this->owner('Người Lạ Media');
        $campaign = $this->campaign([$trong->id => 10_000_000]);

        // Owner "người lạ" có hồ sơ đầy đủ và có màn hình trên sàn, chỉ không
        // có dòng nào trong campaign này. Endpoint không nhận tham số owner
        // nào, nên phạm vi là do cấu trúc — test này canh đúng điều đó.
        $this->campaign([$nguoiLa->id => 7_000_000]);

        $body = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonCount(1, 'data.recipients')
            ->getContent();

        $this->assertStringNotContainsString($nguoiLa->id, $body);
        $this->assertStringNotContainsString('Người Lạ Media', $body);
    }

    /**
     * Dòng đã hủy không còn là nghĩa vụ trả tiền.
     *
     * Cùng bộ lọc trạng thái với `PaymentService::breakdownByOwner()`. Lệch
     * nhau là lộ nơi nhận tiền của một owner mà người mua không còn nợ đồng
     * nào — hoặc ngược lại, nợ mà không tra ra chỗ chuyển.
     */
    public function test_owner_chi_con_dong_da_huy_thi_khong_ra_noi_nhan_tien(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000], lineStatus: 'cancelled');

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonCount(0, 'data.recipients');
    }

    public function test_danh_sach_nguoi_nhan_khop_voi_danh_sach_cong_no(): void
    {
        $a = $this->owner('Kim Ngân ADV');
        $b = $this->owner('Đại Phát Media');
        $campaign = $this->campaign([$a->id => 10_000_000, $b->id => 20_000_000]);

        $nhan = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))->assertOk()
            ->json('data.recipients');

        $no = $this->actingAs($this->buyer)
            ->getJson($this->urlTien($campaign))->assertOk()
            ->json('data.by_owner');

        $idNhan = collect($nhan)->pluck('id')->sort()->values()->all();
        $idNo   = collect($no)->pluck('owner.id')->sort()->values()->all();

        // Client ghép hai phản hồi theo `id`. Lệch một phần tử là một khối
        // owner vẽ ra mà không có chỗ chuyển tiền, hoặc một khoản nợ không
        // hiện ra ở đâu.
        $this->assertSame($idNo, $idNhan, 'hai danh sách phải dẫn từ cùng một nguồn');
    }

    // ── Khai một nửa thì coi như chưa khai ──────────────────────────────────

    public function test_khai_mot_nua_thi_bon_truong_bank_deu_null(): void
    {
        $owner    = $this->owner('Chưa Khai Xong', khaiDu: false, khaiMotNua: true);
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        // `bank_name` CÓ trong CSDL nhưng thiếu số tài khoản và chủ tài khoản.
        // Nửa bộ thông tin tệ hơn không có gì: người mua sẽ thử chuyển và tiền
        // đi sai chỗ. Nên máy chủ trả `null` sạch và nói thẳng `false`.
        $this->assertSame('Vietcombank (VCB)', $owner->fresh()->bank_name);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonPath('data.recipients.0.has_bank_details', false)
            ->assertJsonPath('data.recipients.0.bank_name', null)
            ->assertJsonPath('data.recipients.0.bank_account_number', null)
            ->assertJsonPath('data.recipients.0.bank_account_name', null)
            ->assertJsonPath('data.recipients.0.bank_branch', null);
    }

    /**
     * Chưa khai gì thì nói thẳng là chưa khai.
     *
     * Trước 08/10/2026 câu này là một đoạn chữ render phía máy chủ và có test
     * `assertSee` canh nó. Nay nó là chữ trong JS, nên bảo đảm thật chuyển về
     * đây: máy chủ phải trả `has_bank_details = false` để client biết **không
     * được vẽ nút trả tiền**. Nút đó vẽ ra là người mua bấm vào một khoản tiền
     * không có chỗ nào để chuyển tới.
     */
    public function test_owner_chua_khai_gi_thi_may_chu_noi_thang_la_chua_khai(): void
    {
        $owner    = $this->owner('Chưa Khai TK', khaiDu: false);
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))
            ->assertOk()
            ->assertJsonCount(1, 'data.recipients')
            ->assertJsonPath('data.recipients.0.name', 'Chưa Khai TK')
            ->assertJsonPath('data.recipients.0.has_bank_details', false)
            ->assertJsonPath('data.recipients.0.bank_name', null)
            ->assertJsonPath('data.recipients.0.bank_account_number', null);
    }

    // ── DTO danh sách trắng ─────────────────────────────────────────────────

    public function test_duoc_tra_noi_nhan_tien_nhung_khong_duoc_tra_nhung_thu_khac(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $body = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))->assertOk()->getContent();

        foreach ([
            // Ăn chia của sàn với owner — không phải việc của người mua, và
            // biết nó là biết giá vốn của sàn.
            'revenue_share_pct',
            '63.17',
            // Tệp trên disk riêng, chỉ được truy cập qua URL ký hạn.
            'business_license_path',
            'giay-phep/',
            // Vẫn cấm tuyệt đối, kể cả ở đường đã được mở cho `bank_*`.
            'billing_info',
            'device_token',
            // Giá sàn nội bộ.
            'floor_cpm_at_booking',
            'floor_cpm',
        ] as $camKy) {
            $this->assertStringNotContainsString(
                $camKy,
                $body,
                "Đường nhận tiền để lộ \"{$camKy}\" — danh sách trắng đã bị nới.",
            );
        }
    }

    /**
     * Không có số tiền ở đây.
     *
     * Tiền ở `GET payments`. Lý do tách: một bản sao thứ hai của phép tính
     * tiền là một chỗ để trôi khỏi bản gốc, và đường nhạy cảm càng hẹp càng
     * dễ canh. Test này giữ đúng ranh giới đó khỏi bị nới dần.
     */
    public function test_khong_mang_so_tien(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $body = $this->actingAs($this->buyer)
            ->getJson($this->url($campaign))->assertOk()->getContent();

        foreach (['"cost"', '"vat"', '"total"', '"remaining"', '"paid"', '"is_paid"', '10000000'] as $camKy) {
            $this->assertStringNotContainsString(
                $camKy,
                $body,
                "Đường nhận tiền mang theo {$camKy} — tiền phải ở `GET payments`.",
            );
        }
    }

    // ── Chặn cache ──────────────────────────────────────────────────────────

    public function test_chan_cache_o_moi_tang(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $response = $this->actingAs($this->buyer)->getJson($this->url($campaign))->assertOk();

        $cc = $response->headers->get('Cache-Control');

        // `no-store` là cái chính — nó cấm *lưu*, trong khi `no-cache` chỉ bắt
        // kiểm lại trước khi dùng. `private` chặn thêm tầng dùng chung
        // (Cloudflare, proxy công ty).
        $this->assertStringContainsString('no-store', $cc);
        $this->assertStringContainsString('private', $cc);
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('0', $response->headers->get('Expires'));
    }

    // ── Nhật ký truy cập ────────────────────────────────────────────────────

    public function test_ghi_lai_ai_doc_noi_nhan_tien_va_luc_nao(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $this->actingAs($this->buyer)->getJson($this->url($campaign))->assertOk();

        $dong = CampaignActivity::where('campaign_id', $campaign->id)
            ->where('action', 'remittance_details_viewed')
            ->first();

        $this->assertNotNull($dong, 'đọc nơi nhận tiền mà không để lại dấu nào');
        $this->assertSame($this->buyer->id, $dong->user_id);
        $this->assertNotNull($dong->created_at);
        $this->assertSame([$owner->id], $dong->metadata['owner_ids']);
        $this->assertArrayHasKey('ip', $dong->metadata);
    }

    /**
     * Cửa chống lụt.
     *
     * `CampaignActivity` hiện nguyên trên `/my/campaigns/{campaign}` và không
     * phân trang. Ghi một dòng mỗi lần tải trang là nhấn chìm lịch sử thật —
     * duyệt, gửi, thanh toán — dưới hàng chục dòng "đã xem".
     */
    public function test_tai_lai_trang_nhieu_lan_khong_lam_lut_nhat_ky(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($this->buyer)->getJson($this->url($campaign))->assertOk();
        }

        $this->assertSame(
            1,
            CampaignActivity::where('campaign_id', $campaign->id)
                ->where('action', 'remittance_details_viewed')
                ->count(),
            'bốn lần đọc trong cùng cửa 10 phút chỉ được để lại một dòng',
        );
    }

    public function test_bi_tu_choi_thi_khong_ghi_nhat_ky(): void
    {
        $owner    = $this->owner('Kim Ngân ADV');
        $campaign = $this->campaign([$owner->id => 10_000_000]);

        $viewer = $this->makeMember($this->org, OrganizationUser::ROLE_VIEWER);

        $this->actingAs($viewer)->getJson($this->url($campaign))->assertStatus(403);

        // Nhật ký là bản ghi "người này ĐÃ NHÌN THẤY nơi nhận tiền". Ghi cả
        // lần bị từ chối là nói sai điều đó, và làm nhật ký mất giá trị đúng
        // lúc cần nó nhất — khi có tranh chấp đối soát.
        $this->assertSame(
            0,
            CampaignActivity::where('campaign_id', $campaign->id)
                ->where('action', 'remittance_details_viewed')
                ->count(),
        );
    }
}
