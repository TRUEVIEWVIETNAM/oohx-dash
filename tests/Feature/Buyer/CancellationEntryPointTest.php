<?php

namespace Tests\Feature\Buyer;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Cart;
use App\Models\InventoryHold;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Refund;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\CampaignService;
use App\Services\CartService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * **Đường chạy thật** từ giao diện người mua tới CSDL cho việc hủy đặt chỗ.
 *
 * `CancellationTest` đã canh phần tính toán của `CancellationService`. Nhưng
 * dịch vụ đó **không được gọi từ đâu cả**: không controller, không route, không
 * Filament action. Tôi từng báo tính năng này là xong ở giai đoạn 1 — nó chưa
 * xong, vì CLAUDE.md mục 8 nói rõ không gọi một tính năng là hoàn chỉnh khi
 * chưa có đường chạy thật từ giao diện tới CSDL.
 *
 * Nên file này không kiểm lại công thức hoàn tiền. Nó kiểm rằng **có người
 * bấm được**, và bấm xong thì CSDL thật đổi.
 */
class CancellationEntryPointTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function screen(): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
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

    /** Chiến dịch một dòng, ngày chạy bắt đầu sau $daysFromNow ngày. */
    private function campaign(Screen $screen, int $daysFromNow, ?Organization $org = null): Campaign
    {
        $org   = $org ?: $this->org;
        $start = now()->addDays($daysFromNow);

        $campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch thử',
            'start_date'      => $start->toDateString(),
            'end_date'        => $start->copy()->addMonth()->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_APPROVED,
        ]);

        BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'start_date'         => $start->toDateString(),
            'end_date'           => $start->copy()->addMonth()->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => 1_000_000,
            'status'             => 'approved',
            'pricing_model'      => 'io',
        ]);

        return $campaign->fresh();
    }

    private function payInFull(Campaign $campaign, Screen $screen): void
    {
        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);
        app(PaymentService::class)->confirmBankTransfer($payment);
    }

    private function cancelUrl(Campaign $campaign, BookingLine $line): string
    {
        return route('buyer.campaigns.lines.cancel', [$campaign, $line]);
    }

    // ── Có người bấm được, và bấm xong thì CSDL đổi ─────────────────────────

    public function test_nguoi_mua_huy_duoc_va_du_lieu_that_doi(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);
        $this->payInFull($campaign, $screen);
        $line = $campaign->bookingLines->first();

        $this->actingAs($this->buyer)
            ->post($this->cancelUrl($campaign, $line), ['reason' => 'Đổi kế hoạch truyền thông'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('cancelled', $line->fresh()->status);

        $refund = Refund::where('booking_line_id', $line->id)->first();
        $this->assertNotNull($refund, 'Hủy qua HTTP mà không sinh nghĩa vụ hoàn tiền.');
        $this->assertSame('Đổi kế hoạch truyền thông', $refund->reason);
        $this->assertSame(100, $refund->refund_pct, 'Hủy trước 30 ngày phải nằm ở bậc hoàn 100%.');
    }

    public function test_huy_qua_http_thi_nha_suat_ve_kho(): void
    {
        // Đi qua giỏ hàng thật để có suất bị chiếm thật: dựng `BookingLine`
        // bằng tay thì không có `InventoryHold` nào, và ca test sẽ xanh vì
        // không có gì để nhả.
        $screen = $this->screen();
        $cart   = Cart::create([
            'user_id'         => $this->buyer->id,
            'organization_id' => $this->org->id,
            'status'          => 'active',
            'name'            => 'Giỏ thử',
        ]);
        $start = now()->addYear()->startOfYear();
        $dates = [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
        ];

        app(CartService::class)->addItem($cart, $screen->id, $dates + ['share_of_voice_pct' => 100]);
        $campaign = app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);
        $line     = $campaign->bookingLines()->first();

        $this->assertSame(
            0,
            app(AvailabilityService::class)->getRemainingSOV($screen->id, $dates['start_date'], $dates['end_date']),
            'Chưa có suất nào bị chiếm — ca test không dựng đúng tình huống.'
        );

        $this->actingAs($this->buyer)
            ->post($this->cancelUrl($campaign, $line))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(
            100,
            app(AvailabilityService::class)->getRemainingSOV($screen->id, $dates['start_date'], $dates['end_date']),
            'Hủy qua HTTP rồi mà suất vẫn bị chiếm — mất doanh thu thật.'
        );
        $this->assertSame(0, InventoryHold::effective()->count());
    }

    /**
     * Con số hoàn dự kiến phải có TRƯỚC khi bấm.
     *
     * ══ Ba ca dưới đây đã đổi nguồn kiểm — 08/10/2026 ══
     *
     * Trang `/my/campaigns/{campaign}` nay đọc `GET /api/v2/campaigns/{campaign}`
     * từ trình duyệt, nên `assertSee('Hủy đặt chỗ')` trên HTML đã thành một
     * phép kiểm **rỗng**: chuỗi đó nằm trong khối `<script>` của trang, nên nó
     * xanh kể cả khi API không trả báo giá nào. Ca "không hiện nút cho vai chỉ
     * xem" còn tệ hơn — nó **đỏ** dù hành vi đúng, vì `assertDontSee` thấy
     * chính chuỗi đó trong script.
     *
     * Nên phép kiểm chuyển về nơi quyết định: phản hồi API. Phần JS vẽ ra từ
     * phản hồi đó được canh riêng ở `tests/js/chi-tiet-campaign.test.mjs`, và
     * `MocDomTrangBladeTest` canh việc trang trỏ đúng endpoint.
     *
     * Bắt người mua bấm rồi mới biết được hoàn bao nhiêu là bắt họ ký vào một
     * tờ giấy trắng — nên điều phải giữ là *con số có mặt*, không phải *nó
     * được render ở tầng nào*.
     */
    public function test_trang_chien_dich_hien_so_tien_hoan_du_kien(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);
        $this->payInFull($campaign, $screen);

        $line = $campaign->bookingLines()->first();

        // Trang vẫn phải mở được…
        $this->actingAs($this->buyer)
            ->get(route('buyer.campaigns.show', $campaign))
            ->assertOk();

        // …và báo giá phải có mặt, kèm con số khác 0 (đã trả đủ, còn 30 ngày
        // nên theo chính sách là hoàn 100%).
        $bao = $this->actingAs($this->buyer)
            ->getJson('/api/v2/campaigns/' . $campaign->id)
            ->assertOk()
            ->json('data.cancel_quotes');

        $dong = collect($bao)->firstWhere('booking_line_id', $line->id);

        $this->assertNotNull($dong, 'dòng còn hủy được mà không có báo giá nào');
        $this->assertGreaterThan(0, $dong['refundable'], 'trả đủ rồi hủy sớm thì phải có tiền hoàn');
        $this->assertSame(100, $dong['refund_pct']);
        $this->assertTrue($dong['is_estimate'], 'máy chủ phải tự nói đây là con số dự kiến');
    }

    public function test_chinh_sach_hien_tren_trang_lay_tu_config(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);

        $this->actingAs($this->buyer)
            ->get(route('buyer.campaigns.show', $campaign))
            ->assertOk();

        // Các mốc chính sách thật phải ra ngoài, để trang không nói một đằng
        // máy chủ áp một nẻo. Trước đây trang đọc `config()` trực tiếp trong
        // Blade; nay nó đi qua API, nên client — kể cả client Next.js không
        // đọc được config — không còn lý do nào để chép cứng phần trăm.
        $this->assertSame(
            array_map(fn ($t) => [
                'min_days_before' => (int) $t['min_days_before'],
                'refund_pct'      => (int) $t['refund_pct'],
            ], config('pricing.refund_tiers')),
            $this->actingAs($this->buyer)
                ->getJson('/api/v2/campaigns/' . $campaign->id)
                ->assertOk()
                ->json('data.refund_policy'),
        );
    }

    // ── Quyền ───────────────────────────────────────────────────────────────

    public function test_vai_chi_xem_khong_huy_duoc(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);
        $line     = $campaign->bookingLines->first();

        $viewer = User::factory()->create(['current_organization_id' => $this->org->id]);
        $viewer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $viewer->id,
            'role'            => OrganizationUser::ROLE_VIEWER,
        ]);

        // Hủy kéo theo nghĩa vụ tiền nên xếp cùng `manage_payments`, mà `viewer`
        // không có. Giao diện ẩn nút là chưa đủ — đây là phép chặn thật.
        $this->actingAs($viewer)
            ->post($this->cancelUrl($campaign, $line))
            ->assertForbidden();

        $this->assertSame('approved', $line->fresh()->status);
        $this->assertSame(0, Refund::count());
    }

    /**
     * Vai chỉ xem không được **mời** đi hủy.
     *
     * `assertDontSee('Hủy đặt chỗ')` trên HTML không dùng được nữa: chuỗi đó
     * nằm trong khối `<script>` của trang, nên phép kiểm đỏ dù hành vi đúng.
     * Nơi quyết định là `cancel_quotes` — rỗng thì phần JS không vẽ nút nào, và
     * việc đó có ca riêng ở `tests/js/chi-tiet-campaign.test.mjs`
     * (`không có báo giá thì không vẽ nút hủy nào`).
     *
     * Đây là lớp *giao diện*, không phải lớp chặn. Phép chặn thật ở ca
     * `test_vai_chi_xem_khong_huy_duoc` ngay trên, nơi `viewer` POST vào đường
     * hủy và nhận 403.
     */
    public function test_vai_chi_xem_khong_duoc_moi_di_huy(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);

        $viewer = User::factory()->create(['current_organization_id' => $this->org->id]);
        $viewer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $viewer->id,
            'role'            => OrganizationUser::ROLE_VIEWER,
        ]);

        // Vẫn xem được trang…
        $this->actingAs($viewer)
            ->get(route('buyer.campaigns.show', $campaign))
            ->assertOk();

        // …nhưng không nhận báo giá nào, nên không có gì để vẽ nút.
        $this->actingAs($viewer)
            ->getJson('/api/v2/campaigns/' . $campaign->id)
            ->assertOk()
            ->assertJsonCount(0, 'data.cancel_quotes');

        // Và người CÓ quyền thì vẫn nhận — nếu không thì đây là chặn quá tay
        // chứ không phải siết đúng chỗ.
        $this->actingAs($this->buyer)
            ->getJson('/api/v2/campaigns/' . $campaign->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.cancel_quotes');
    }

    public function test_nguoi_ngoai_to_chuc_khong_huy_duoc(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);
        $line     = $campaign->bookingLines->first();

        $otherOrg  = Organization::factory()->create(['status' => 'active']);
        $outsider  = User::factory()->create(['current_organization_id' => $otherOrg->id]);
        $outsider->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $otherOrg->id,
            'user_id'         => $outsider->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        $this->actingAs($outsider)
            ->post($this->cancelUrl($campaign, $line))
            ->assertForbidden();

        $this->assertSame('approved', $line->fresh()->status);
    }

    public function test_khong_huy_duoc_dong_thuoc_chien_dich_khac(): void
    {
        $screenA   = $this->screen();
        $screenB   = $this->screen();
        $campaignA = $this->campaign($screenA, 30);
        $campaignB = $this->campaign($screenB, 30);

        $lineB = $campaignB->bookingLines->first();

        // URL ghép chiến dịch của mình với dòng của chiến dịch khác. Policy đã
        // đúng mà thiếu phép kiểm này thì vẫn rò, vì nó kiểm sai đối tượng.
        $this->actingAs($this->buyer)
            ->post($this->cancelUrl($campaignA, $lineB))
            ->assertNotFound();

        $this->assertSame('approved', $lineB->fresh()->status);
        $this->assertSame(0, Refund::count());
    }

    public function test_huy_hai_lan_khong_tao_hai_khoan_hoan(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);
        $this->payInFull($campaign, $screen);
        $line = $campaign->bookingLines->first();

        $this->actingAs($this->buyer)
            ->post($this->cancelUrl($campaign, $line))
            ->assertSessionHas('success');

        // Lần hai: người dùng bấm lại, hoặc gửi lại form. Phải là thông báo
        // lỗi tử tế, không phải trang 500 và không phải khoản hoàn thứ hai.
        $this->actingAs($this->buyer)
            ->post($this->cancelUrl($campaign, $line))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, Refund::where('booking_line_id', $line->id)->count());
    }

    public function test_khach_chua_dang_nhap_khong_vao_duoc(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);
        $line     = $campaign->bookingLines->first();

        $this->post($this->cancelUrl($campaign, $line))->assertRedirect();

        $this->assertSame('approved', $line->fresh()->status);
    }
}
