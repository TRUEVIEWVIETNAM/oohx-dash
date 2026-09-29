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
use App\Services\Booking\CancellationService;
use App\Services\CampaignService;
use App\Services\CartService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Giai đoạn 1.5 — hủy đặt chỗ và hoàn tiền theo bậc thang.
 *
 * Trước sửa: `cancelled` chỉ là một giá trị trong enum, không có đường nào đi
 * tới. Khách muốn hủy thì không ai xử lý được, suất vẫn bị chiếm, và không có
 * chỗ nào ghi lại họ được hoàn bao nhiêu.
 *
 * Chính sách chốt 29/09/2026: ≥14 ngày hoàn 100%, 7–13 ngày hoàn 50%, dưới 7
 * ngày không hoàn — tính theo ngày chạy của CHÍNH DÒNG bị hủy.
 */
class CancellationTest extends TestCase
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
            'role'            => 'admin',
        ]);
        $this->actingAs($this->buyer);
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
    private function campaign(Screen $screen, int $daysFromNow, int $cost = 1_000_000, string $lineStatus = 'approved'): Campaign
    {
        $start = now()->addDays($daysFromNow);

        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
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
            'estimated_cost'     => $cost,
            'status'             => $lineStatus,
            'pricing_model'      => 'io',
        ]);

        return $campaign->fresh();
    }

    private function payInFull(Campaign $campaign, Screen $screen): void
    {
        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);
        app(PaymentService::class)->confirmBankTransfer($payment);
    }

    // ── Bậc thang hoàn tiền ──────────────────────────────────────────────────

    public function test_huy_som_tren_14_ngay_hoan_toan_bo(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);
        $this->payInFull($campaign, $screen);

        $refund = app(CancellationService::class)->cancelLine(
            $campaign->bookingLines()->first(),
            $this->buyer,
            'Đổi kế hoạch',
        );

        $this->assertSame(100, $refund->refund_pct);
        $this->assertSame(1_080_000, (int) round((float) $refund->amount), 'Hoàn cả phần VAT đã trả.');
        $this->assertSame(Refund::STATUS_PENDING, $refund->status);
    }

    public function test_huy_truoc_10_ngay_hoan_mot_nua(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 10);
        $this->payInFull($campaign, $screen);

        $refund = app(CancellationService::class)->cancelLine($campaign->bookingLines()->first(), $this->buyer);

        $this->assertSame(50, $refund->refund_pct);
        $this->assertSame(540_000, (int) round((float) $refund->amount));
    }

    public function test_huy_sat_ngay_chay_khong_hoan(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 3);
        $this->payInFull($campaign, $screen);

        $refund = app(CancellationService::class)->cancelLine($campaign->bookingLines()->first(), $this->buyer);

        $this->assertSame(0, $refund->refund_pct);
        $this->assertSame(0, (int) round((float) $refund->amount));
        $this->assertSame(Refund::STATUS_WAIVED, $refund->status, 'Không có gì để hoàn thì không tạo nghĩa vụ treo.');
    }

    public function test_chua_tra_tien_thi_khong_co_gi_de_hoan(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);

        $refund = app(CancellationService::class)->cancelLine($campaign->bookingLines()->first(), $this->buyer);

        $this->assertSame(100, $refund->refund_pct, 'Vẫn ghi đúng mốc chính sách...');
        $this->assertSame(0, (int) round((float) $refund->amount), '...nhưng chưa trả đồng nào thì hoàn 0.');
    }

    public function test_khoan_cho_xac_nhan_khong_duoc_hoan(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);

        // Tạo khoản thanh toán nhưng KHÔNG xác nhận.
        app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);

        $refund = app(CancellationService::class)->cancelLine($campaign->bookingLines()->first(), $this->buyer);

        $this->assertSame(0, (int) round((float) $refund->amount), 'Tiền chưa chuyển đi thì không có gì để hoàn lại.');
    }

    public function test_chup_lai_chinh_sach_luc_huy(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);

        $refund = app(CancellationService::class)->cancelLine($campaign->bookingLines()->first(), $this->buyer);

        $this->assertSame(100, $refund->policy_snapshot['tier_applied']['refund_pct']);
        $this->assertNotEmpty($refund->policy_snapshot['tiers'], 'Chụp cả bảng bậc thang, để đổi chính sách sau này không làm hồ sơ cũ khó hiểu.');
    }

    // ── Hủy phải nhả suất ────────────────────────────────────────────────────

    public function test_huy_thi_nha_suat_ve_kho_ngay(): void
    {
        $screen = $this->screen();
        $cart   = Cart::create(['user_id' => $this->buyer->id, 'organization_id' => $this->org->id, 'status' => 'active', 'name' => 'P']);
        $start  = now()->addYear()->startOfYear();
        $dates  = [
            'start_date' => $start->toDateString(),
            'end_date'   => $start->copy()->addMonthNoOverflow()->subDay()->toDateString(),
        ];

        app(CartService::class)->addItem($cart, $screen->id, $dates + ['share_of_voice_pct' => 100]);
        $campaign = app(CampaignService::class)->createFromCart($this->org, $this->buyer, $cart->fresh(), ['name' => 'CD']);

        $this->assertSame(0, app(AvailabilityService::class)->getRemainingSOV($screen->id, $dates['start_date'], $dates['end_date']));

        app(CancellationService::class)->cancelLine($campaign->bookingLines()->first(), $this->buyer);

        $this->assertSame(
            100,
            app(AvailabilityService::class)->getRemainingSOV($screen->id, $dates['start_date'], $dates['end_date']),
            'Hủy rồi mà suất vẫn bị chiếm là mất doanh thu thật.'
        );
        $this->assertSame(0, InventoryHold::effective()->count());
    }

    // ── Trạng thái ───────────────────────────────────────────────────────────

    public function test_huy_het_dong_thi_chien_dich_thanh_da_huy(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);

        app(CancellationService::class)->cancelCampaign($campaign, $this->buyer, 'Khách rút');

        $this->assertSame(Campaign::STATUS_CANCELLED, $campaign->fresh()->status);
    }

    public function test_khong_huy_duoc_dong_da_chay_xong(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30, 1_000_000, 'completed');

        try {
            app(CancellationService::class)->cancelLine($campaign->bookingLines()->first(), $this->buyer);
            $this->fail('Dòng đã chạy xong thì không hủy được.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_khong_huy_hai_lan(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);
        $line     = $campaign->bookingLines()->first();

        app(CancellationService::class)->cancelLine($line, $this->buyer);

        try {
            app(CancellationService::class)->cancelLine($line->fresh(), $this->buyer);
            $this->fail('Không được hủy hai lần — lần hai sẽ sinh thêm một nghĩa vụ hoàn tiền trùng.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(1, Refund::count());
    }

    public function test_danh_dau_da_hoan_tien_xong(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 30);
        $this->payInFull($campaign, $screen);

        $refund = app(CancellationService::class)->cancelLine($campaign->bookingLines()->first(), $this->buyer);
        $settled = app(CancellationService::class)->settle($refund, $this->buyer);

        $this->assertSame(Refund::STATUS_SETTLED, $settled->status);
        $this->assertNotNull($settled->settled_at);
    }

    public function test_tinh_theo_ngay_chay_cua_tung_dong_khong_phai_cua_chien_dich(): void
    {
        $early = $this->screen();
        $late  = $this->screen();

        // Chiến dịch bắt đầu sau 3 ngày, nhưng dòng thứ hai chạy sau 60 ngày.
        $campaign = $this->campaign($early, 3);
        BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $late->id,
            'owner_id'           => $late->owner_id,
            'start_date'         => now()->addDays(60)->toDateString(),
            'end_date'           => now()->addDays(90)->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => 1_000_000,
            'status'             => 'approved',
            'pricing_model'      => 'io',
        ]);

        $lateLine = $campaign->bookingLines()->where('screen_id', $late->id)->firstOrFail();

        $this->assertSame(
            100,
            app(CancellationService::class)->quote($lateLine)['refund_pct'],
            'Hủy dòng chạy sau 60 ngày là hủy sớm, dù chiến dịch sắp bắt đầu.'
        );
    }
}
