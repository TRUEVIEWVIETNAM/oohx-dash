<?php

namespace Tests\Feature\Buyer;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Creative;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\Booking\CreativeGate;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Giai đoạn 1.3 và 1.4 — nội dung phải duyệt mới lên sóng, và tiền phải tính
 * theo từng media owner.
 *
 * Trước sửa:
 *  - `creatives.status` có đủ ba trạng thái nhưng **không nối vào đâu**: duyệt
 *    hay không cũng không đổi gì, và `booking_line_creatives` chưa từng có dòng.
 *  - `checkAndActivate` chỉ chạy khi TỔNG tiền của cả chiến dịch đủ, nên một
 *    owner chậm xác nhận là cả chiến dịch đứng.
 *  - `breakdownByOwner` cộng cả khoản CHỜ vào phần "đã trả", nên owner hiện ra
 *    đã nhận đủ tiền ngay khi người mua bấm nút.
 *  - `amount` nhận thẳng từ request, chỉ chặn `min:1000`.
 *  - Số hóa đơn sinh bằng "đọc số lớn nhất rồi cộng một", không ràng buộc.
 */
class CreativeGateAndPaymentTest extends TestCase
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

    /**
     * Chiến dịch đã duyệt với các dòng cho trước.
     *
     * @param  array<int, array{screen: Screen, cost: int}>  $lines
     */
    private function campaignWith(array $lines): Campaign
    {
        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch thử',
            'start_date'      => now()->addMonth()->toDateString(),
            'end_date'        => now()->addMonths(2)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_APPROVED,
        ]);

        foreach ($lines as $line) {
            BookingLine::create([
                'campaign_id'        => $campaign->id,
                'screen_id'          => $line['screen']->id,
                'owner_id'           => $line['screen']->owner_id,
                'start_date'         => now()->addMonth()->toDateString(),
                'end_date'           => now()->addMonths(2)->toDateString(),
                'spot_length'        => 15,
                'share_of_voice_pct' => 100,
                'estimated_cost'     => $line['cost'],
                'status'             => 'approved',
                'pricing_model'      => 'io',
            ]);
        }

        return $campaign->fresh();
    }

    private function creativeFor(Campaign $campaign, string $status = 'pending_review'): Creative
    {
        return Creative::create([
            'campaign_id'     => $campaign->id,
            'organization_id' => $this->org->id,
            'name'            => 'Mẫu quảng cáo',
            'type'            => 'image',
            'file_path'       => 'creatives/x.jpg',
            'status'          => $status,
        ]);
    }

    /** Trả đủ tiền cho một owner, đã gồm VAT. */
    private function payInFull(Campaign $campaign, Owner $owner): Payment
    {
        $payment = app(PaymentService::class)->createPayment($campaign->fresh(), 'bank_transfer', null, $owner->id);

        return app(PaymentService::class)->confirmBankTransfer($payment);
    }

    // ── 1.3 Cổng nội dung ────────────────────────────────────────────────────

    public function test_duyet_noi_dung_thi_gan_vao_cac_dong_dat_cho(): void
    {
        $campaign = $this->campaignWith([
            ['screen' => $this->screen(), 'cost' => 1_000_000],
            ['screen' => $this->screen(), 'cost' => 2_000_000],
        ]);
        $creative = $this->creativeFor($campaign);

        $this->assertSame(0, \DB::table('booking_line_creatives')->count(), 'Trước khi duyệt thì chưa gắn gì.');

        app(CreativeGate::class)->approve($creative, $this->buyer->id);

        $this->assertSame(2, \DB::table('booking_line_creatives')->count(), 'Duyệt rồi thì mọi dòng phải có nội dung.');
    }

    public function test_tu_choi_noi_dung_thi_go_khoi_dong_dat_cho(): void
    {
        $campaign = $this->campaignWith([['screen' => $this->screen(), 'cost' => 1_000_000]]);
        $creative = $this->creativeFor($campaign);

        app(CreativeGate::class)->approve($creative, $this->buyer->id);
        app(CreativeGate::class)->reject($creative->fresh(), $this->buyer->id, 'Sai kích thước');

        $this->assertSame(0, \DB::table('booking_line_creatives')->count());
        $this->assertFalse(app(CreativeGate::class)->isReadyToAir($campaign->fresh()));
    }

    public function test_khong_gan_duoc_noi_dung_chua_duyet(): void
    {
        $campaign = $this->campaignWith([['screen' => $this->screen(), 'cost' => 1_000_000]]);
        $creative = $this->creativeFor($campaign);
        $line     = $campaign->bookingLines()->firstOrFail();

        try {
            app(CreativeGate::class)->attach($line, $creative);
            $this->fail('Nội dung chưa duyệt thì không được gắn vào dòng đặt chỗ.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_bao_ro_man_hinh_nao_con_thieu_noi_dung(): void
    {
        $a = $this->screen();
        $campaign = $this->campaignWith([['screen' => $a, 'cost' => 1_000_000]]);

        try {
            app(CreativeGate::class)->assertReadyToAir($campaign);
            $this->fail('Phải chặn khi chưa có nội dung đã duyệt.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString($a->name, $e->getMessage(), 'Thông báo phải nêu đích danh màn hình thiếu nội dung.');
        }
    }

    public function test_tra_du_tien_nhung_noi_dung_chua_duyet_thi_khong_len_song(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaignWith([['screen' => $screen, 'cost' => 1_000_000]]);
        $this->creativeFor($campaign); // còn chờ duyệt

        $this->payInFull($campaign, $screen->owner);

        $this->assertSame(Campaign::STATUS_APPROVED, $campaign->fresh()->status, 'Tiền đủ nhưng nội dung chưa duyệt thì chưa được chạy.');
        $this->assertSame('approved', $campaign->bookingLines()->first()->status);
        $this->assertDatabaseHas('campaign_activities', ['campaign_id' => $campaign->id, 'action' => 'activation_blocked']);
    }

    public function test_du_tien_va_noi_dung_da_duyet_thi_len_song(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaignWith([['screen' => $screen, 'cost' => 1_000_000]]);
        app(CreativeGate::class)->approve($this->creativeFor($campaign), $this->buyer->id);

        $this->payInFull($campaign, $screen->owner);

        $this->assertSame(Campaign::STATUS_ACTIVE, $campaign->fresh()->status);
        $this->assertSame('active', $campaign->bookingLines()->first()->status);
    }

    // ── 1.4 Tiền theo từng owner ─────────────────────────────────────────────

    public function test_owner_nao_du_tien_thi_dong_cua_owner_do_chay(): void
    {
        $a = $this->screen();
        $b = $this->screen();
        $campaign = $this->campaignWith([
            ['screen' => $a, 'cost' => 1_000_000],
            ['screen' => $b, 'cost' => 3_000_000],
        ]);
        app(CreativeGate::class)->approve($this->creativeFor($campaign), $this->buyer->id);

        $this->payInFull($campaign, $a->owner);

        $lines = $campaign->fresh()->bookingLines()->get()->keyBy('screen_id');

        $this->assertSame('active', $lines[$a->id]->status, 'Owner đã nhận đủ tiền thì dòng của họ chạy ngay.');
        $this->assertSame('approved', $lines[$b->id]->status, 'Owner chưa nhận tiền thì dòng của họ chưa chạy.');
        $this->assertSame(Campaign::STATUS_ACTIVE, $campaign->fresh()->status, 'Có ít nhất một dòng chạy thì chiến dịch là đang chạy.');
    }

    public function test_khoan_cho_xac_nhan_khong_duoc_tinh_la_da_tra(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaignWith([['screen' => $screen, 'cost' => 1_000_000]]);

        app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);

        $row = app(PaymentService::class)->breakdownByOwner($campaign->fresh())->first();

        $this->assertSame(0.0, $row['paid'], 'Chưa xác nhận thì chưa phải tiền đã nhận.');
        $this->assertSame(1_080_000.0, $row['pending'], 'Khoản chờ phải hiện riêng, gồm VAT 8%.');
        $this->assertFalse($row['is_paid'], 'Bấm nút không phải là đã trả tiền.');
    }

    public function test_bam_thanh_toan_hai_lan_khong_tao_hai_khoan(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaignWith([['screen' => $screen, 'cost' => 1_000_000]]);

        $first  = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id, 'key-abc');
        $second = app(PaymentService::class)->createPayment($campaign->fresh(), 'bank_transfer', null, $screen->owner_id, 'key-abc');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Payment::where('campaign_id', $campaign->id)->count());
    }

    public function test_bam_lai_khong_kem_khoa_chong_trung_van_nhan_lai_khoan_dang_cho(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaignWith([['screen' => $screen, 'cost' => 1_000_000]]);

        $first  = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);
        $second = app(PaymentService::class)->createPayment($campaign->fresh(), 'bank_transfer', null, $screen->owner_id);

        $this->assertSame($first->id, $second->id, 'Cùng owner, cùng chiến dịch, khoản chờ đã có thì dùng lại.');
    }

    public function test_so_tien_do_may_chu_tinh_khong_nhan_tu_client(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaignWith([['screen' => $screen, 'cost' => 1_000_000]]);

        // Người mua khai 1.000 đồng cho một đơn 1.080.000 đồng.
        try {
            app(PaymentService::class)->createPayment($campaign, 'bank_transfer', 20_000_000, $screen->owner_id);
            $this->fail('Không được nhận số tiền lớn hơn phần còn nợ.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // Không truyền gì thì máy chủ tự tính đúng phần còn nợ, gồm VAT 8%.
        $payment = app(PaymentService::class)->createPayment($campaign->fresh(), 'bank_transfer', null, $screen->owner_id);

        $this->assertSame(1_080_000, (int) round((float) $payment->amount));
    }

    public function test_tra_thieu_thi_dong_chua_chay(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaignWith([['screen' => $screen, 'cost' => 1_000_000]]);
        app(CreativeGate::class)->approve($this->creativeFor($campaign), $this->buyer->id);

        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', 500_000, $screen->owner_id);
        app(PaymentService::class)->confirmBankTransfer($payment);

        $this->assertSame('approved', $campaign->fresh()->bookingLines()->first()->status);
    }

    public function test_so_hoa_don_khong_trung(): void
    {
        $screen   = $this->screen();
        $a = $this->campaignWith([['screen' => $screen, 'cost' => 1_000_000]]);
        $b = $this->campaignWith([['screen' => $this->screen(), 'cost' => 1_000_000]]);
        $c = $this->campaignWith([['screen' => $this->screen(), 'cost' => 1_000_000]]);

        $numbers = collect([$a, $b, $c])->map(function (Campaign $campaign) {
            $owner = $campaign->bookingLines()->first()->owner_id;

            return app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $owner)->invoice_number;
        });

        $this->assertCount(3, $numbers->unique(), 'Ba hóa đơn phải mang ba số khác nhau.');
    }
}
