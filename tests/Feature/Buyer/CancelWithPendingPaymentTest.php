<?php

namespace Tests\Feature\Buyer;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\Booking\CancellationService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Codex R36 — hủy trong khi khoản chuyển còn chờ đối soát.
 *
 * Đường hỏng: người mua đã chuyển tiền thật nhưng sàn chưa xác nhận, DB còn
 * `pending`. Người mua hủy qua đường mới. `quote()` chỉ tính khoản đã
 * `completed` nên ghi `paid_amount = 0`, `amount = 0`, `status = waived`. Sau
 * đó sàn đối chiếu ngân hàng và xác nhận đúng khoản ấy — tiền thật đã vào,
 * nghĩa vụ hoàn vẫn bằng 0, người mua không hủy lại được để tính lại. Hủy
 * trong kỳ hoàn 100% mà mất trắng.
 *
 * Mọi ca hoàn tiền cũ đều xác nhận tiền **trước** rồi mới hủy, nên không ca
 * nào đi qua đường này. Đây là lý do một bộ test xanh không chứng minh được
 * điều gì về những thứ tự mà nó không thử.
 */
class CancelWithPendingPaymentTest extends TestCase
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

    private function screen(?Owner $owner = null): Screen
    {
        $owner  = $owner ?: Owner::factory()->create(['status' => 'active']);
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

    /** @param array<int, array{screen: Screen, cost: int}> $lines */
    private function campaign(array $lines, int $daysFromNow = 30): Campaign
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

        foreach ($lines as $spec) {
            BookingLine::create([
                'campaign_id'        => $campaign->id,
                'screen_id'          => $spec['screen']->id,
                'owner_id'           => $spec['screen']->owner_id,
                'start_date'         => $start->toDateString(),
                'end_date'           => $start->copy()->addMonth()->toDateString(),
                'spot_length'        => 15,
                'share_of_voice_pct' => 100,
                'estimated_cost'     => $spec['cost'],
                'status'             => 'approved',
                'pricing_model'      => 'io',
            ]);
        }

        return $campaign->fresh();
    }

    private function vat(int $amount): int
    {
        return app(PaymentService::class)->withVat($amount);
    }

    // ── Đường hỏng mà Codex chỉ ra ──────────────────────────────────────────

    public function test_tien_vao_sau_khi_huy_thi_nghia_vu_hoan_duoc_tinh_lai(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign([['screen' => $screen, 'cost' => 1_000_000]]);
        $line     = $campaign->bookingLines->first();

        // Người mua đã chuyển tiền ở ngân hàng; sàn chưa đối chiếu nên DB còn
        // `pending`.
        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);
        $this->assertSame('pending', $payment->status);

        // Hủy qua đúng đường người mua bấm.
        $this->actingAs($this->buyer)
            ->post(route('buyer.campaigns.lines.cancel', [$campaign, $line]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $refund = Refund::where('booking_line_id', $line->id)->firstOrFail();

        // Ở thời điểm này ghi 0 là ĐÚNG: chưa có đồng nào vào hệ thống.
        $this->assertSame(0, (int) round((float) $refund->amount));
        $this->assertSame(Refund::STATUS_WAIVED, $refund->status);

        // Sàn đối chiếu ngân hàng và xác nhận đúng khoản đó.
        app(PaymentService::class)->confirmBankTransfer($payment->fresh(), 'VCB-123456789');

        $refund->refresh();

        // Hủy trước 30 ngày → bậc hoàn 100%. Tiền đã trả gồm VAT.
        $this->assertSame(
            $this->vat(1_000_000),
            (int) round((float) $refund->paid_amount),
            'Tiền xác nhận muộn không được phân bổ cho dòng đã hủy.'
        );
        $this->assertSame(
            $this->vat(1_000_000),
            (int) round((float) $refund->amount),
            'Người mua hủy trong kỳ hoàn 100% mà nghĩa vụ hoàn vẫn bằng 0.'
        );
        $this->assertSame(
            Refund::STATUS_PENDING,
            $refund->status,
            'Khoản hoàn vẫn ở waived nên không xuất hiện trong việc-phải-làm của owner.'
        );
    }

    public function test_khoan_hoan_sau_doi_soat_len_duoc_danh_sach_cua_owner(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign([['screen' => $screen, 'cost' => 1_000_000]]);
        $line     = $campaign->bookingLines->first();

        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);

        $this->actingAs($this->buyer)
            ->post(route('buyer.campaigns.lines.cancel', [$campaign, $line]));

        app(PaymentService::class)->confirmBankTransfer($payment->fresh());

        // Đích cuối của việc đối soát: owner phải THẤY nghĩa vụ và settle được.
        $this->assertSame(
            1,
            Refund::where('owner_id', $screen->owner_id)->where('status', Refund::STATUS_PENDING)->count(),
        );
    }

    public function test_ty_le_hoan_giu_theo_ngay_huy_khong_theo_ngay_tien_vao(): void
    {
        // Hủy khi còn 10 ngày → bậc 50%. Tiền vào sau đó không được đẩy tỉ lệ
        // lên hay xuống: chính sách áp theo ngày hủy.
        $screen   = $this->screen();
        $campaign = $this->campaign([['screen' => $screen, 'cost' => 1_000_000]], 10);
        $line     = $campaign->bookingLines->first();

        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);

        $this->actingAs($this->buyer)
            ->post(route('buyer.campaigns.lines.cancel', [$campaign, $line]));

        app(PaymentService::class)->confirmBankTransfer($payment->fresh());

        $refund = Refund::where('booking_line_id', $line->id)->firstOrFail();

        $this->assertSame(50, (int) $refund->refund_pct);
        $this->assertSame(
            (int) round($this->vat(1_000_000) * 0.5),
            (int) round((float) $refund->amount),
        );
    }

    // ── Không hoàn quá ──────────────────────────────────────────────────────

    public function test_doi_soat_khong_phan_bo_qua_phan_cua_chinh_dong_do(): void
    {
        $owner    = Owner::factory()->create(['status' => 'active']);
        $screenA  = $this->screen($owner);
        $screenB  = $this->screen($owner);
        $campaign = $this->campaign([
            ['screen' => $screenA, 'cost' => 1_000_000],
            ['screen' => $screenB, 'cost' => 1_000_000],
        ]);

        $lineA = $campaign->bookingLines->firstWhere('screen_id', $screenA->id);

        // Trả đủ cho cả hai dòng, nhưng chỉ hủy một dòng.
        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $owner->id);

        $this->actingAs($this->buyer)
            ->post(route('buyer.campaigns.lines.cancel', [$campaign, $lineA]));

        app(PaymentService::class)->confirmBankTransfer($payment->fresh());

        $refund = Refund::where('booking_line_id', $lineA->id)->firstOrFail();

        // Trần là phần của chính dòng A, không phải toàn bộ tiền của owner.
        // Không có trần thì A ăn hết tiền của cả B.
        $this->assertSame(
            $this->vat(1_000_000),
            (int) round((float) $refund->paid_amount),
            'Dòng đã hủy được phân bổ nhiều hơn phần chính nó bị tính.'
        );
    }

    public function test_doi_soat_chay_lai_khong_cong_don(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign([['screen' => $screen, 'cost' => 1_000_000]]);
        $line     = $campaign->bookingLines->first();

        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);

        $this->actingAs($this->buyer)
            ->post(route('buyer.campaigns.lines.cancel', [$campaign, $line]));

        app(PaymentService::class)->confirmBankTransfer($payment->fresh());

        $after = (int) round((float) Refund::where('booking_line_id', $line->id)->value('amount'));

        // Gọi lại phải là phép không làm gì. Một hàm đối soát cộng dồn mỗi lần
        // chạy là một hàm không được phép chạy hai lần — và nó sẽ chạy hai lần.
        $adjusted = app(CancellationService::class)->reconcileAfterPayment($campaign->fresh(), $screen->owner_id);

        $this->assertSame(0, $adjusted);
        $this->assertSame(
            $after,
            (int) round((float) Refund::where('booking_line_id', $line->id)->value('amount')),
        );
    }

    public function test_thu_tu_cu_khong_doi_xac_nhan_truoc_roi_huy(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign([['screen' => $screen, 'cost' => 1_000_000]]);
        $line     = $campaign->bookingLines->first();

        // Thứ tự cũ: xác nhận tiền TRƯỚC rồi mới hủy. Việc đối soát mới không
        // được làm đường này lệch đi.
        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);
        app(PaymentService::class)->confirmBankTransfer($payment);

        $this->actingAs($this->buyer)
            ->post(route('buyer.campaigns.lines.cancel', [$campaign, $line]));

        $refund = Refund::where('booking_line_id', $line->id)->firstOrFail();

        $this->assertSame($this->vat(1_000_000), (int) round((float) $refund->amount));
        $this->assertSame(Refund::STATUS_PENDING, $refund->status);
    }

    public function test_khoan_da_hoan_xong_thi_ghi_nghia_vu_moi_chu_khong_ghi_de(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign([['screen' => $screen, 'cost' => 1_000_000]]);
        $line     = $campaign->bookingLines->first();

        $half = (float) round($this->vat(1_000_000) / 2);

        // Trả nửa đầu và xác nhận.
        $first = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', $half, $screen->owner_id);
        app(PaymentService::class)->confirmBankTransfer($first);

        // Nửa sau: người mua đã chuyển, sàn chưa đối chiếu. Khoản này **tồn
        // tại trước khi hủy**, nên nó thuộc dòng bị hủy.
        $second = app(PaymentService::class)->createPayment($campaign->fresh(), 'bank_transfer', null, $screen->owner_id);
        $this->assertSame('pending', $second->status);

        $this->actingAs($this->buyer)
            ->post(route('buyer.campaigns.lines.cancel', [$campaign, $line]));

        // Owner hoàn xong phần đã xác nhận được tới lúc đó.
        $refund = Refund::where('booking_line_id', $line->id)->firstOrFail();
        app(CancellationService::class)->settle($refund, $this->buyer);

        $settledAmount = (int) round((float) $refund->fresh()->amount);
        $this->assertSame((int) $half, $settledAmount);

        // Giờ sàn xác nhận nửa sau.
        app(PaymentService::class)->confirmBankTransfer($second->fresh());

        $refund->refresh();

        // Bản ghi cũ không bị sửa: tiền đã chuyển xong ở ngoài hệ thống.
        $this->assertSame(Refund::STATUS_SETTLED, $refund->status);
        $this->assertSame($settledAmount, (int) round((float) $refund->amount));

        // Nhưng phần tiền vào muộn KHÔNG được mất: phải có một nghĩa vụ mới
        // cho cùng dòng đó. Bản đầu tôi chỉ bỏ qua khoản đã settled, tức
        // người mua trả thêm mà không được hoàn thêm.
        $extra = Refund::where('booking_line_id', $line->id)
            ->where('id', '!=', $refund->id)
            ->get();

        $this->assertCount(1, $extra, 'Phần tiền vào muộn bị bỏ rơi, không có nghĩa vụ hoàn nào.');
        $this->assertSame(Refund::STATUS_PENDING, $extra->first()->status);
        $this->assertSame(100, (int) $extra->first()->refund_pct, 'Tỉ lệ phải giữ theo ngày hủy.');

        // Tổng hai nghĩa vụ đúng bằng toàn bộ tiền đã trả, vì bậc là 100%.
        $this->assertSame(
            $this->vat(1_000_000),
            (int) round((float) $refund->amount) + (int) round((float) $extra->first()->amount),
        );
    }

    /**
     * Ranh giới với R24 — chỗ bản sửa đầu của tôi sai.
     *
     * Bản đầu phân bổ mọi tiền chưa phân bổ cho dòng đã hủy, nên nó kéo cả
     * tiền trả thêm cho dòng **còn sống** về dòng đã hủy. Điều đó mâu thuẫn
     * với quyết định có chủ ý ở R24 và làm đỏ đúng hai ca chống hồi quy R24,
     * R34. Ca này chốt ranh giới ngay tại chỗ code mới nằm, để lần sau ai đọc
     * `reconcileAfterPayment()` thấy luôn giới hạn của nó.
     */
    public function test_tien_tao_sau_khi_huy_khong_duoc_chia_vao_dong_da_huy(): void
    {
        $owner    = Owner::factory()->create(['status' => 'active']);
        $screenA  = $this->screen($owner);
        $screenB  = $this->screen($owner);
        $campaign = $this->campaign([
            ['screen' => $screenA, 'cost' => 1_000_000],
            ['screen' => $screenB, 'cost' => 1_000_000],
        ]);

        $lineA = $campaign->bookingLines->firstWhere('screen_id', $screenA->id);

        // Trả một nửa tổng (1.080.000 trong 2.160.000) và xác nhận.
        $paid = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', 1_080_000.0, $owner->id);
        app(PaymentService::class)->confirmBankTransfer($paid);

        // Hủy A: chia đôi theo tỉ lệ hai dòng còn mở → 540.000.
        $this->actingAs($this->buyer)
            ->post(route('buyer.campaigns.lines.cancel', [$campaign, $lineA]));

        $refundA = Refund::where('booking_line_id', $lineA->id)->firstOrFail();
        $this->assertSame(540_000, (int) round((float) $refundA->amount));

        // Trả thêm 540.000 — khoản này tạo SAU khi hủy, tức tiền cho dòng B
        // còn sống. Không được chia lại vào A.
        $topUp = app(PaymentService::class)->createPayment($campaign->fresh(), 'bank_transfer', 540_000.0, $owner->id, 'top-up');
        app(PaymentService::class)->confirmBankTransfer($topUp);

        $this->assertSame(
            540_000,
            (int) round((float) $refundA->fresh()->amount),
            'Tiền trả thêm cho dòng còn sống bị kéo về dòng đã hủy — mâu thuẫn R24.'
        );
        $this->assertSame(
            1,
            Refund::where('booking_line_id', $lineA->id)->count(),
            'Đối soát tạo thêm một nghĩa vụ từ tiền không thuộc dòng này.'
        );
    }

    public function test_khoan_khong_gan_owner_thi_khong_doi_soat(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign([['screen' => $screen, 'cost' => 1_000_000]]);

        // Dữ liệu trước khi thanh toán tách theo owner: `paidForLine()` không
        // tính khoản này cho dòng nào, nên đối soát cũng không có gì để làm.
        Payment::create([
            'campaign_id'     => $campaign->id,
            'organization_id' => $this->org->id,
            'owner_id'        => null,
            'amount'          => $this->vat(1_000_000),
            'method'          => 'bank_transfer',
            'status'          => 'completed',
            'currency'        => 'VND',
            'paid_at'         => now(),
        ]);

        $this->assertSame(
            0,
            app(CancellationService::class)->reconcileAfterPayment($campaign, null),
        );
    }
}
