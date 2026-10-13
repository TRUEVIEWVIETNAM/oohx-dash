<?php

namespace Tests\Feature\Buyer;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Refund;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Notifications\RefundObligationNotification;
use App\Services\Booking\CancellationService;
use App\Services\PaymentService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Media owner được **báo** khi họ có một nghĩa vụ hoàn tiền.
 *
 * ══ Lỗ này được ghi lại từ 30/09/2026 và để mở tới nay ══
 *
 * Giai đoạn 1.5 dựng ba lối vào cho hủy/hoàn tiền, nhưng không thông báo gì:
 * owner phải **tự mở** `/publisher` mới biết mình đang nợ người mua một khoản.
 * Sàn không thu hộ — người mua chuyển khoản trực tiếp cho từng owner — nên
 * khoản hoàn cũng do owner tự chuyển lại. Một nghĩa vụ mà người phải trả không
 * biết là một nghĩa vụ không ai thực hiện.
 *
 * ══ Bốn trục tệp này canh ══
 *
 *  1. **Gửi khi có tiền thật phải chuyển, và chỉ khi đó.** Khoản `waived` (hủy
 *     sát ngày, hoặc chưa chuyển gì) không sinh thư.
 *  2. **Hủy cả chiến dịch vẫn gửi.** `cancelCampaign()` bọc nhiều lần
 *     `cancelLine()` trong một transaction NGOÀI, nên transaction trong chỉ là
 *     savepoint. Một bản gửi thư "sau transaction của `cancelLine`" vẫn là gửi
 *     *bên trong* transaction ngoài — và `DB::afterCommit()` là thứ xử đúng cả
 *     hai đường.
 *  3. **Nghĩa vụ TĂNG cũng phải báo.** Người mua hủy khi khoản chuyển còn
 *     `pending` thì nghĩa vụ ghi 0; sàn xác nhận khoản đó sau và nghĩa vụ thành
 *     một số dương. Owner không mở trang thì không biết nó đã đổi.
 *  4. **SMTP chết không được làm đổ lệnh hủy.** Dòng đã hủy, suất đã nhả, nghĩa
 *     vụ đã ghi — tất cả đã commit trước khi thư đi.
 */
class ThongBaoNghiaVuHoanTienTest extends TestCase
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

    /** Một màn hình, kèm owner và một người dùng của owner đó. */
    private function screen(?Owner $owner = null): Screen
    {
        $owner ??= Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenInventory::create([
            'screen_id'     => $screen->id,
            'pricing_model' => 'io',
            'io_rate'       => 1_000_000,
            'io_rate_unit'  => 'month',
            'spot_length'   => 15,
        ]);

        return $screen->fresh('inventory');
    }

    /** Thêm một người dùng cho owner, trả về chính người đó. */
    private function nguoiCuaOwner(Owner $owner, string $role = 'owner'): User
    {
        $user = User::factory()->create();

        OwnerUser::create([
            'owner_id' => $owner->id,
            'user_id'  => $user->id,
            'role'     => $role,
        ]);

        return $user;
    }

    /**
     * Chiến dịch với các dòng cho sẵn.
     *
     * @param  array<int, array{screen: Screen, cost?: int}>  $dong
     */
    private function campaign(array $dong, int $daysFromNow = 30): Campaign
    {
        $start = now()->addDays($daysFromNow);

        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch thử thông báo',
            'start_date'      => $start->toDateString(),
            'end_date'        => $start->copy()->addMonth()->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_APPROVED,
        ]);

        foreach ($dong as $d) {
            BookingLine::create([
                'campaign_id'        => $campaign->id,
                'screen_id'          => $d['screen']->id,
                'owner_id'           => $d['screen']->owner_id,
                'start_date'         => $start->toDateString(),
                'end_date'           => $start->copy()->addMonth()->toDateString(),
                'spot_length'        => 15,
                'share_of_voice_pct' => 100,
                'estimated_cost'     => $d['cost'] ?? 1_000_000,
                'status'             => BookingLine::STATUS_APPROVED,
                'pricing_model'      => 'io',
            ]);
        }

        return $campaign->fresh('bookingLines');
    }

    private function payInFull(Campaign $campaign, Screen $screen): void
    {
        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);
        app(PaymentService::class)->confirmBankTransfer($payment);
    }

    // ── Trục 1: gửi khi có tiền thật phải chuyển ─────────────────────────────

    public function test_huy_mot_dong_da_tra_tien_thi_owner_nhan_thu(): void
    {
        $screen   = $this->screen();
        $nguoi    = $this->nguoiCuaOwner($screen->owner);
        $campaign = $this->campaign([['screen' => $screen]]);
        $this->payInFull($campaign, $screen);

        Notification::fake();

        app(CancellationService::class)->cancelLine(
            $campaign->bookingLines()->first(),
            $this->buyer,
            'Đổi kế hoạch',
        );

        Notification::assertSentTo($nguoi, RefundObligationNotification::class);
    }

    public function test_thu_gui_cho_MOI_nguoi_cua_owner_do_va_khong_ai_khac(): void
    {
        $screen = $this->screen();
        $a      = $this->nguoiCuaOwner($screen->owner, 'owner');
        $b      = $this->nguoiCuaOwner($screen->owner, 'operator');

        // Một owner khác, không liên quan tới dòng bị hủy.
        $ownerKhac  = Owner::factory()->create(['status' => 'active']);
        $nguoiKhac  = $this->nguoiCuaOwner($ownerKhac);

        $campaign = $this->campaign([['screen' => $screen]]);
        $this->payInFull($campaign, $screen);

        Notification::fake();

        app(CancellationService::class)->cancelLine($campaign->bookingLines()->first(), $this->buyer);

        Notification::assertSentTo($a, RefundObligationNotification::class);
        Notification::assertSentTo($b, RefundObligationNotification::class);
        Notification::assertNotSentTo($nguoiKhac, RefundObligationNotification::class);

        // Người mua không phải người phải chuyển tiền, nên không nhận thư này.
        Notification::assertNotSentTo($this->buyer, RefundObligationNotification::class);
    }

    /**
     * Hủy sát ngày chạy: bậc hoàn 0% → `waived` → không thư nào.
     *
     * Gửi thư cho một khoản bằng 0 là dạy owner bỏ qua loại thư này, và loại
     * thư này nói về tiền.
     */
    public function test_khoan_waived_khong_sinh_thu(): void
    {
        $screen   = $this->screen();
        $nguoi    = $this->nguoiCuaOwner($screen->owner);
        $campaign = $this->campaign([['screen' => $screen]], daysFromNow: 3);
        $this->payInFull($campaign, $screen);

        Notification::fake();

        $refund = app(CancellationService::class)->cancelLine($campaign->bookingLines()->first(), $this->buyer);

        $this->assertSame(Refund::STATUS_WAIVED, $refund->status, 'Hủy sát ngày thì không hoàn.');
        Notification::assertNotSentTo($nguoi, RefundObligationNotification::class);
    }

    // ── Trục 2: hủy cả chiến dịch (transaction LỒNG) ─────────────────────────

    /**
     * `cancelCampaign()` bọc mọi `cancelLine()` trong một transaction ngoài.
     *
     * Ca này canh một thứ hẹp: hủy nhiều dòng thì **mỗi** owner đều có thư,
     * không chỉ owner của dòng đầu. Nó **không** phân biệt được
     * `DB::afterCommit()` với một lời gọi thẳng — `Notification::fake()` ghi
     * nhận thư dù transaction còn mở hay không. Trục đó là ca rollback bên
     * dưới.
     */
    public function test_huy_ca_chien_dich_thi_moi_owner_deu_nhan_thu(): void
    {
        $mh1 = $this->screen();
        $mh2 = $this->screen();
        $n1  = $this->nguoiCuaOwner($mh1->owner);
        $n2  = $this->nguoiCuaOwner($mh2->owner);

        $campaign = $this->campaign([['screen' => $mh1], ['screen' => $mh2]]);
        $this->payInFull($campaign, $mh1);
        $this->payInFull($campaign, $mh2);

        Notification::fake();

        $refunds = app(CancellationService::class)->cancelCampaign($campaign->fresh(), $this->buyer, 'Hủy toàn bộ');

        $this->assertCount(2, $refunds);
        Notification::assertSentTo($n1, RefundObligationNotification::class);
        Notification::assertSentTo($n2, RefundObligationNotification::class);
    }

    /**
     * Transaction rollback thì **không** thư nào đi.
     *
     * Đây là ca canh đúng lựa chọn `DB::afterCommit()`. Thông báo của dự án
     * không khai `ShouldQueue` nên `notify()` gửi ngay trong tiến trình: một
     * lời gọi thẳng ở giữa transaction sẽ gửi thư rồi mới biết là transaction
     * đổ, và không có cách nào thu thư về. Owner nhận thư về một nghĩa vụ
     * không tồn tại, rồi mở trang và không thấy gì.
     *
     * Đổi `DB::afterCommit(…)` trong `thongBaoNghiaVu()` thành một lời gọi
     * thẳng là ca này đỏ ngay.
     */
    public function test_transaction_do_thi_khong_thu_nao_di(): void
    {
        $screen   = $this->screen();
        $nguoi    = $this->nguoiCuaOwner($screen->owner);
        $campaign = $this->campaign([['screen' => $screen]]);
        $this->payInFull($campaign, $screen);

        $line = $campaign->bookingLines()->first();

        Notification::fake();

        try {
            DB::transaction(function () use ($line) {
                app(CancellationService::class)->cancelLine($line, $this->buyer);

                // Một thứ khác trong cùng giao dịch thất bại.
                throw new RuntimeException('đổi ý giữa đường');
            });
        } catch (RuntimeException) {
            // Mong đợi.
        }

        Notification::assertNotSentTo($nguoi, RefundObligationNotification::class);
        $this->assertSame(
            BookingLine::STATUS_APPROVED,
            $line->fresh()->status,
            'Rollback phải trả dòng về trạng thái cũ — nếu không thì ca này đang đo thứ khác.',
        );
    }

    // ── Trục 3: nghĩa vụ TĂNG sau đối soát ──────────────────────────────────

    /**
     * Hủy khi khoản chuyển còn `pending`, rồi sàn xác nhận khoản đó.
     *
     * Lúc hủy, nghĩa vụ ghi 0 và `waived` — đúng, chưa có đồng nào vào hệ
     * thống, nên không thư. Sau khi xác nhận, nghĩa vụ thành một số dương:
     * **đó** là lúc phải báo.
     */
    public function test_nghia_vu_tang_sau_doi_soat_thi_moi_gui_thu(): void
    {
        $screen   = $this->screen();
        $nguoi    = $this->nguoiCuaOwner($screen->owner);
        $campaign = $this->campaign([['screen' => $screen]]);
        $line     = $campaign->bookingLines->first();

        // Người mua đã chuyển ở ngân hàng; sàn chưa đối chiếu nên DB còn `pending`.
        $payment = app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $screen->owner_id);

        Notification::fake();

        app(CancellationService::class)->cancelLine($line, $this->buyer);

        Notification::assertNotSentTo(
            $nguoi,
            RefundObligationNotification::class,
            'Lúc hủy chưa có đồng nào vào hệ thống nên chưa có nghĩa vụ để báo.',
        );

        app(PaymentService::class)->confirmBankTransfer($payment->fresh(), 'VCB-123456789');

        Notification::assertSentTo($nguoi, RefundObligationNotification::class);
    }

    // ── Trục 4: thư hỏng không làm đổ lệnh hủy ──────────────────────────────

    /**
     * SMTP chết thì lệnh hủy vẫn thành công.
     *
     * Khi thư đi thì mọi thay đổi đã commit: dòng đã `cancelled`, suất đã nhả,
     * `refunds` đã có hàng. Để ngoại lệ bay lên là trả 500 cho một thao tác
     * **đã thành công** — người mua bấm hủy lần hai và gặp "đã bị hủy trước
     * đó", tức một lỗi trông như lỗi dữ liệu.
     */
    public function test_loi_gui_thu_khong_lam_do_lenh_huy(): void
    {
        $screen   = $this->screen();
        $this->nguoiCuaOwner($screen->owner);
        $campaign = $this->campaign([['screen' => $screen]]);
        $this->payInFull($campaign, $screen);

        // Bộ gửi thông báo nổ ở mọi lời gọi. `Notifiable::notify()` phân giải
        // `Dispatcher` từ container mỗi lần gửi, nên thay instance là đủ.
        $no = new class implements Dispatcher
        {
            public function send($notifiables, $notification)
            {
                throw new RuntimeException('SMTP chết');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                throw new RuntimeException('SMTP chết');
            }
        };

        $this->app->instance(Dispatcher::class, $no);

        $refund = app(CancellationService::class)->cancelLine(
            $campaign->bookingLines()->first(),
            $this->buyer,
            'Đổi kế hoạch',
        );

        $this->assertSame(Refund::STATUS_PENDING, $refund->status);
        $this->assertSame(
            'cancelled',
            $campaign->bookingLines()->first()->fresh()->status,
            'Dòng phải hủy xong bất kể thư có đi được hay không.',
        );
    }

    // ── Nội dung thư ────────────────────────────────────────────────────────

    /**
     * Thư phải nói **số tiền** và cho một chỗ bấm.
     *
     * Một thông báo nghĩa vụ tiền mà không nói bao nhiêu thì owner vẫn phải tự
     * mở trang — tức thư không giải quyết việc gì.
     */
    public function test_thu_mang_so_tien_va_loi_bam(): void
    {
        $screen   = $this->screen();
        $nguoi    = $this->nguoiCuaOwner($screen->owner);
        $campaign = $this->campaign([['screen' => $screen]]);
        $this->payInFull($campaign, $screen);

        $refund = app(CancellationService::class)->cancelLine($campaign->bookingLines()->first(), $this->buyer);

        $mail = (new RefundObligationNotification($refund))->toMail($nguoi);
        // `array_merge`, KHÔNG `+`: hai mảng đều đánh chỉ số từ 0, nên phép
        // hợp mảng sẽ bỏ im lặng các dòng của mảng sau.
        $chu = implode("\n", array_map(
            fn ($d) => is_array($d) ? implode(' ', $d) : (string) $d,
            array_merge($mail->introLines, $mail->outroLines),
        ));

        // Hủy trước 30 ngày → 100% của 1.000.000 + VAT.
        $this->assertStringContainsString('1.080.000 ₫', $chu);
        $this->assertStringContainsString('100%', $chu);
        $this->assertNotEmpty($mail->actionUrl, 'Phải có chỗ bấm tới trang Hoàn tiền.');
        $this->assertStringContainsString('/publisher/refunds', $mail->actionUrl);
    }
}
