<?php

namespace Tests\Feature\Api\V2;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\PolicyConsent;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Giai đoạn 5, mốc 3 — nhóm cần quyền: **đặt chỗ**.
 *
 * Đây là nhóm `/api/v2` đầu tiên tạo **nghĩa vụ tiền**: một lần gọi
 * `POST /campaigns` biến giỏ hàng thành `booking_lines`. Nên những thứ test
 * này canh nặng hơn nhóm giỏ hàng:
 *
 *  1. Chưa đăng nhập, hoặc đã đăng nhập mà chưa có tổ chức → envelope đúng
 *     định dạng, **không phải redirect**.
 *  2. **404 khi không được xem, 403 khi được xem mà không được làm.** Hai câu
 *     trả lời khác nhau cho hai câu hỏi khác nhau.
 *  3. **Số tiền do máy chủ tính.** `total_budget` client gửi không đổi một
 *     đồng nào trong `booking_lines`.
 *  4. **Giá sàn nội bộ không ra ngoài** (CLAUDE.md mục 2).
 *  5. Bản ghi đồng ý vẫn được ghi — đó là bằng chứng khi có tranh chấp.
 *  6. Xung đột SOV kiểm lại **ngay trước khi gửi**, không tin lần kiểm trước.
 */
class BookingApiTest extends TestCase
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

    private function screen(?Owner $owner = null): Screen
    {
        $owner  = $owner ?: Owner::factory()->create([
            'status'            => 'active',
            'revenue_share_pct' => 63.17,
            'tax_code'          => '0101234567',
        ]);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'city' => 'Hà Nội', 'status' => 'active']);
        $screen = Screen::factory()->create([
            'owner_id'     => $owner->id,
            'site_id'      => $site->id,
            'active'       => true,
            'device_token' => 'bi-mat-cua-thiet-bi',
        ]);

        ScreenSpec::factory()->create(['screen_id' => $screen->id, 'width_cm' => 400, 'height_cm' => 200]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'floor_cpm'              => 50_000,
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
            'weekly_impressions'     => 70_000,
        ]);

        return $screen->fresh(['inventory', 'spec', 'site']);
    }

    /** @return array{start_date: string, end_date: string} */
    private function dates(): array
    {
        $start = now()->addYear()->startOfYear();

        return [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
        ];
    }

    private function fillCart(?Screen $screen = null, int $sov = 100): Screen
    {
        $screen = $screen ?: $this->screen();
        $carts  = app(CartService::class);
        $cart   = $carts->getOrCreateCart($this->buyer);

        $carts->addItem($cart, $screen->id, $this->dates() + ['share_of_voice_pct' => $sov]);

        return $screen;
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['name' => 'Chiến dịch thử'], $extra);
    }

    // ── Cần đăng nhập, và cần là người mua ──────────────────────────────────

    public function test_chua_dang_nhap_thi_khong_tao_duoc(): void
    {
        $this->postJson('/api/v2/campaigns', $this->payload())
            ->assertStatus(401)
            ->assertJsonPath('code', 401)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);

        $this->assertSame(0, Campaign::count());
    }

    public function test_dang_nhap_nhung_khong_co_to_chuc_thi_nhan_envelope_khong_phai_redirect(): void
    {
        // `EnsureBuyerAuth` bản cũ luôn `redirect('/register')`. Với client JSON
        // thì đó là 302 sang một trang HTML, và lỗi hiện ra dưới dạng "JSON
        // parse error" — không liên quan gì tới nguyên nhân thật.
        $khongToChuc = User::factory()->create(['current_organization_id' => null]);

        $this->actingAs($khongToChuc)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('error', 'organization_required')
            ->assertJsonPath('code', 403)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    // ── Tạo campaign từ giỏ ─────────────────────────────────────────────────

    public function test_gio_trong_thi_khong_tao_duoc(): void
    {
        $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('code', 422);

        $this->assertSame(0, Campaign::count());
    }

    public function test_tao_campaign_tu_gio_va_sinh_dong_dat_cho(): void
    {
        $screen = $this->fillCart();

        $response = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload(['brand_name' => 'Nhãn thử']))
            ->assertStatus(201)
            ->assertJsonPath('data.campaign.status', 'draft')
            ->assertJsonPath('data.summary.line_count', 1);

        // Có đường chạy thật tới CSDL, không chỉ có response đẹp (CLAUDE.md
        // mục 8).
        $campaign = Campaign::firstOrFail();
        $this->assertSame($this->org->id, $campaign->organization_id);
        $this->assertSame(1, $campaign->bookingLines()->count());
        $this->assertSame($screen->id, $campaign->bookingLines()->first()->screen_id);

        $this->assertSame($campaign->id, $response->json('data.campaign.id'));
    }

    public function test_ngan_sach_client_gui_khong_doi_duoc_gia_tung_dong(): void
    {
        $this->fillCart();

        // 1 đồng ngân sách. Nếu nó chảy xuống `booking_lines` thì giá dòng
        // thành 1 và cả đường tiền sai.
        $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload(['total_budget' => 1]))
            ->assertStatus(201);

        $line = BookingLine::firstOrFail();

        $this->assertGreaterThan(
            1,
            (float) $line->estimated_cost,
            'Giá dòng phải do máy chủ tính từ cấu hình kho, không lấy theo ngân sách client gửi.',
        );
    }

    public function test_khong_nhan_so_tien_cho_tung_dong_tu_client(): void
    {
        $this->fillCart();

        $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload([
                'estimated_cost' => 1,
                'total_cost'     => 1,
            ]))
            ->assertStatus(201);

        $this->assertGreaterThan(1, (float) BookingLine::firstOrFail()->estimated_cost);
    }

    // ── DTO danh sách trắng ─────────────────────────────────────────────────

    public function test_khong_lo_gia_san_noi_bo_va_du_lieu_rieng_cua_owner(): void
    {
        $this->fillCart();

        $body = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->assertStatus(201)
            ->getContent();

        foreach ([
            // Ảnh chụp cấu hình giá lúc đặt. KHÔNG canh chuỗi `floor_cpm`
            // trần: giá niêm yết của màn hình ra ngoài bình thường ở
            // `screen.pricing.floor_cpm`, và canh chuỗi trần sẽ đỏ vì đúng
            // cái lẽ ra phải có.
            'floor_cpm_at_booking',
            'io_rate_at_booking',
            'negotiated_cpm',
            // Nhóm CLAUDE.md mục 2 cấm tuyệt đối.
            'revenue_share_pct',
            'tax_code',
            'device_token',
            'bi-mat-cua-thiet-bi',
            // Id người duyệt bên trong sàn.
            'approved_by',
        ] as $camKy) {
            $this->assertStringNotContainsString(
                $camKy,
                $body,
                "Nhóm đặt chỗ để lộ \"{$camKy}\".",
            );
        }
    }

    public function test_khong_lo_duong_dan_tep_noi_dung_quang_cao(): void
    {
        $this->fillCart();
        $campaignId = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->json('data.campaign.id');

        \App\Models\Creative::create([
            'campaign_id'     => $campaignId,
            'organization_id' => $this->org->id,
            'name'            => 'Banner thử',
            'type'            => 'image',
            'file_path'       => 'creatives/' . $campaignId . '/bi-mat.jpg',
            'file_size'       => 1024,
            'status'          => 'pending_review',
        ]);

        $response = $this->actingAs($this->buyer)
            ->getJson('/api/v2/campaigns/' . $campaignId)
            ->assertOk();

        $body = $response->getContent();

        // Nội dung quảng cáo đang nằm trên disk CÔNG KHAI (xem
        // `CreativeResource`). Phát đường dẫn ra đây là biến một lỗi lưu trữ
        // thành một lỗi lộ dữ liệu.
        $this->assertStringNotContainsString('file_path', $body);
        $this->assertStringNotContainsString('bi-mat.jpg', $body);

        // Khẳng định chiều ngược lại đi qua `assertJsonPath`, không qua
        // `assertStringContainsString`: Laravel escape ký tự ngoài ASCII trong
        // JSON, nên "Banner thử" nằm trong body dưới dạng `Banner thử` và
        // phép so chuỗi thô luôn trượt — trượt vì lý do không liên quan gì tới
        // điều đang kiểm.
        $response->assertJsonPath('data.creatives.0.name', 'Banner thử');
    }

    // ── Tải nội dung quảng cáo lên ──────────────────────────────────────────

    public function test_tai_noi_dung_len_qua_api_va_tep_khong_nam_tren_disk_cong_khai(): void
    {
        Storage::fake('public');
        Storage::fake(config('creatives.disk'));

        $this->fillCart();
        $campaignId = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->json('data.campaign.id');

        $response = $this->actingAs($this->buyer)
            ->post('/api/v2/campaigns/' . $campaignId . '/creatives', [
                'file' => UploadedFile::fake()->image('banner.png', 400, 200),
                'name' => 'Banner thử',
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.creative.name', 'Banner thử')
            ->assertJsonPath('data.creative.status', 'pending_review')
            ->assertJsonPath('data.creative.status_label', 'Chờ duyệt');

        $creative = \App\Models\Creative::firstOrFail();

        Storage::disk(config('creatives.disk'))->assertExists($creative->file_path);
        Storage::disk('public')->assertMissing($creative->file_path);

        // `download_url` là URL ký hạn, không phải đường dẫn trên đĩa.
        $url = $response->json('data.creative.download_url');
        $this->assertNotNull($url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString($creative->file_path, $url);
    }

    public function test_viewer_khong_tai_noi_dung_len_duoc(): void
    {
        Storage::fake(config('creatives.disk'));

        $this->fillCart();
        $campaignId = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->json('data.campaign.id');

        $viewer = $this->makeMember($this->org, OrganizationUser::ROLE_VIEWER);

        $this->actingAs($viewer)
            ->post('/api/v2/campaigns/' . $campaignId . '/creatives', [
                'file' => UploadedFile::fake()->image('banner.png'),
            ])
            ->assertStatus(403);

        $this->assertSame(0, \App\Models\Creative::count());
    }

    // ── Phân quyền: 404 với người ngoài, 403 với người trong ────────────────

    public function test_campaign_cua_to_chuc_khac_tra_404_chu_khong_403(): void
    {
        $this->fillCart();
        $campaignId = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->json('data.campaign.id');

        $toChucKhac = Organization::factory()->create(['status' => 'active']);
        $nguoiLa    = $this->makeMember($toChucKhac, OrganizationUser::ROLE_ADMIN);

        // 403 là xác nhận "bản ghi này có thật" cho người ngoài tổ chức. Sự
        // tồn tại của một đơn hàng cũng là thông tin riêng.
        $this->actingAs($nguoiLa)
            ->getJson('/api/v2/campaigns/' . $campaignId)
            ->assertStatus(404);
    }

    public function test_vai_tro_viewer_xem_duoc_nhung_khong_gui_duoc(): void
    {
        $this->fillCart();
        $campaignId = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->json('data.campaign.id');

        $viewer = $this->makeMember($this->org, OrganizationUser::ROLE_VIEWER);

        // Xem được: 200, không phải 404 — họ đang nhìn đúng campaign đó.
        $this->actingAs($viewer)
            ->getJson('/api/v2/campaigns/' . $campaignId)
            ->assertOk();

        // Gửi thì 403: thứ thiếu là quyền, và họ cần biết đúng điều đó.
        $this->actingAs($viewer)
            ->postJson('/api/v2/campaigns/' . $campaignId . '/submit', [
                'confirm_accuracy' => true,
                'accept_terms'     => true,
            ])
            ->assertStatus(403);

        $this->assertSame('draft', Campaign::findOrFail($campaignId)->status);
    }

    // ── Gửi chờ duyệt ───────────────────────────────────────────────────────

    public function test_thieu_o_dong_y_thi_khong_gui_duoc(): void
    {
        $this->fillCart();
        $campaignId = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->json('data.campaign.id');

        $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns/' . $campaignId . '/submit', ['confirm_accuracy' => true])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['error', 'message', 'code', 'details' => [['field', 'message']]]);

        $this->assertSame('draft', Campaign::findOrFail($campaignId)->status);
        $this->assertSame(0, PolicyConsent::count());
    }

    public function test_gui_duoc_va_ghi_lai_ban_ghi_dong_y(): void
    {
        $this->fillCart();
        $campaignId = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->json('data.campaign.id');

        $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns/' . $campaignId . '/submit', [
                'confirm_accuracy' => true,
                'accept_terms'     => true,
            ])
            ->assertOk();

        $this->assertNotSame('draft', Campaign::findOrFail($campaignId)->status);

        // Bỏ bản ghi đồng ý ở API là mở một đường tạo booking không để lại
        // bằng chứng, và không ai thấy cho tới khi có tranh chấp.
        $this->assertGreaterThan(
            0,
            PolicyConsent::where('subject_id', $campaignId)->count(),
            'Gửi booking qua API phải ghi bản ghi đồng ý như trang Blade.',
        );
    }

    public function test_xung_dot_sov_chan_viec_gui_va_noi_ro_tung_man_hinh(): void
    {
        $screen = $this->fillCart(sov: 100);

        $campaignId = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->json('data.campaign.id');

        // Một campaign KHÁC chiếm nốt suất sau khi campaign trên đã dựng xong.
        // Đây là lý do phải kiểm lại ngay trước khi gửi, chứ không tin vào lần
        // kiểm ở bước xem lại.
        $khac = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'Chiếm suất',
            'start_date'      => $this->dates()['start_date'],
            'end_date'        => $this->dates()['end_date'],
            'status'          => Campaign::STATUS_ACTIVE,
        ]);
        BookingLine::create([
            'campaign_id'        => $khac->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'start_date'         => $this->dates()['start_date'],
            'end_date'           => $this->dates()['end_date'],
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => 1_000_000,
            'status'             => 'active',
            'pricing_model'      => 'io',
        ]);

        $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns/' . $campaignId . '/submit', [
                'confirm_accuracy' => true,
                'accept_terms'     => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'sov_conflict')
            ->assertJsonPath('code', 422)
            ->assertJsonStructure(['error', 'message', 'code', 'details' => [['field', 'message']]]);

        $this->assertSame('draft', Campaign::findOrFail($campaignId)->status);
    }

    public function test_can_submit_do_may_chu_tra_loi(): void
    {
        $screen = $this->fillCart(sov: 100);
        $campaignId = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->assertJsonPath('data.summary.can_submit', true)
            ->json('data.campaign.id');

        $khac = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'Chiếm suất',
            'start_date'      => $this->dates()['start_date'],
            'end_date'        => $this->dates()['end_date'],
            'status'          => Campaign::STATUS_ACTIVE,
        ]);
        BookingLine::create([
            'campaign_id'        => $khac->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'start_date'         => $this->dates()['start_date'],
            'end_date'           => $this->dates()['end_date'],
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => 1_000_000,
            'status'             => 'active',
            'pricing_model'      => 'io',
        ]);

        // Client không phải tự suy từ `status` và `conflicts`. Giao diện tự
        // đoán điều kiện thì lệch là chắc chắn.
        $this->actingAs($this->buyer)
            ->getJson('/api/v2/campaigns/' . $campaignId)
            ->assertOk()
            ->assertJsonPath('data.summary.can_submit', false)
            ->assertJsonCount(1, 'data.conflicts');
    }

    public function test_tong_tien_trong_khoi_xem_lai_chua_gom_vat(): void
    {
        $this->fillCart();

        $response = $this->actingAs($this->buyer)
            ->postJson('/api/v2/campaigns', $this->payload())
            ->assertStatus(201);

        // VAT cộng MỘT chỗ duy nhất, ở `PaymentService::withVat()`. Cộng thêm
        // ở đây là một phép làm tròn thứ hai, và hai chỗ làm tròn khác nhau là
        // cách sinh ra "công nợ bằng 0 nhưng chưa trả đủ" (Codex R09).
        $this->assertSame(
            (int) round((float) BookingLine::sum('estimated_cost')),
            $response->json('data.summary.subtotal'),
        );
    }
}
