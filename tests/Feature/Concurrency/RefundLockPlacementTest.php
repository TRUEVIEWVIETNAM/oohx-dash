<?php

namespace Tests\Feature\Concurrency;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\InventoryHold;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\Booking\CancellationService;
use App\Services\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Đường tiền có **một hàng mốc duy nhất** để xếp hàng.
 *
 * ## Lỗi mà lớp này canh
 *
 * `cancelLine()` trước đây chỉ khóa **dòng đặt chỗ** (R13 thêm để chặn hủy
 * trùng cùng một dòng), còn `createPayment()` và `reconcileAfterPayment()`
 * khóa **hàng chiến dịch**. Hai bên khóa hai hàng khác nhau nên không xếp
 * hàng với nhau, trong khi cả hai đều phân bổ tiền theo tổng
 * `payments.amount` và tổng `refunds.paid_amount` của **cả owner**.
 *
 * Xen kẽ làm hoàn quá tiền, với một dòng A đã hủy (nghĩa vụ 0 vì tiền còn
 * chờ) và một dòng B còn sống:
 *
 *  1. `confirmBankTransfer()` đánh dấu khoản 2.160.000 là `completed` —
 *     commit ngay, ngoài transaction của việc đối soát.
 *  2. Việc đối soát mở transaction, khóa hàng chiến dịch, bắt đầu phân bổ
 *     1.080.000 cho A. **Chưa commit.**
 *  3. Cùng lúc, hủy B. Nó không chờ hàng chiến dịch. Đọc thường của nó thấy
 *     khoản đã `completed` nhưng **chưa thấy** phần vừa phân bổ cho A, và
 *     thấy A đã `cancelled` nên mẫu số chỉ còn B. Phần của B thành 2.160.000.
 *  4. Việc đối soát commit thêm 1.080.000 cho A.
 *
 * Tổng nghĩa vụ hoàn 3.240.000 trên 2.160.000 tiền thật nhận được.
 *
 * ## Lớp này chứng minh cái gì, và KHÔNG chứng minh cái gì
 *
 * Nó chứng minh **vị trí khóa**: `cancelLine()` nay thực sự đòi hàng chiến
 * dịch, nên nó không thể chạy song song với `createPayment()` hay
 * `reconcileAfterPayment()`. Đo bằng cách giữ đúng hàng đó trên một kết nối
 * khác rồi xem `cancelLine()` có chờ hay không.
 *
 * Nó **không** chứng minh không còn tranh chấp nào trên đường tiền. Muốn vậy
 * phải có rào chắn **bên trong** vùng tới hạn của hai tiến trình thật —
 * khoảng trống này đã được ghi từ vòng review trước và vẫn còn. Đừng dẫn lớp
 * này như bằng chứng cho điều đó.
 *
 * ## Không dùng RefreshDatabase
 *
 * Cố ý, và đây là bài học R29: `RefreshDatabase` bọc test trong một
 * transaction chưa commit, nên **kết nối thứ hai không thấy fixture**. Hàng
 * chiến dịch sẽ không tồn tại với nó, `lockForUpdate()` không khóa gì, và ca
 * test xanh mà chưa đo được điều gì. Nên lớp này commit thật và dọn tường
 * minh.
 */
class RefundLockPlacementTest extends TestCase
{
    private const SECOND = 'race_second';

    /** @var array<int, string> */
    private array $cleanupCampaignIds = [];

    /** @var array<int, string> */
    private array $cleanupScreenIds = [];

    /** @var array<int, string> */
    private array $cleanupOrgIds = [];

    /** @var array<int, int|string> */
    private array $cleanupUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('refunds')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        try {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');

            Refund::whereIn('campaign_id', $this->cleanupCampaignIds)->delete();
            CampaignActivity::whereIn('campaign_id', $this->cleanupCampaignIds)->delete();
            Payment::whereIn('campaign_id', $this->cleanupCampaignIds)->delete();

            $lineIds = BookingLine::whereIn('campaign_id', $this->cleanupCampaignIds)->pluck('id');
            InventoryHold::whereIn('booking_line_id', $lineIds)->delete();
            BookingLine::whereIn('campaign_id', $this->cleanupCampaignIds)->delete();

            Campaign::whereIn('id', $this->cleanupCampaignIds)->delete();

            foreach ($this->cleanupScreenIds as $screenId) {
                ScreenInventory::where('screen_id', $screenId)->delete();
                $screen = Screen::withoutGlobalScopes()->find($screenId);
                if ($screen) {
                    $siteId  = $screen->site_id;
                    $ownerId = $screen->owner_id;
                    Screen::withoutGlobalScopes()->whereKey($screenId)->forceDelete();
                    Site::withoutGlobalScopes()->whereKey($siteId)->forceDelete();
                    Owner::whereKey($ownerId)->forceDelete();
                }
            }

            DB::table('organization_users')->whereIn('organization_id', $this->cleanupOrgIds)->delete();
            User::whereIn('id', $this->cleanupUserIds)->delete();
            Organization::whereIn('id', $this->cleanupOrgIds)->delete();
        } catch (\Throwable) {
            // Dọn hết sức; không che lỗi thật của test.
        }

        parent::tearDown();
    }

    private function secondConnection(): string
    {
        $base = Config::get('database.connections.' . Config::get('database.default'));
        Config::set('database.connections.' . self::SECOND, $base);
        DB::purge(self::SECOND);

        return self::SECOND;
    }

    /** @return array{campaign: Campaign, line: BookingLine, buyer: User} */
    private function scenario(): array
    {
        $org   = Organization::factory()->create(['status' => 'active']);
        $buyer = User::factory()->create(['current_organization_id' => $org->id]);
        $owner = Owner::factory()->create(['status' => 'active']);

        $site   = Site::factory()->create(['owner_id' => $owner->id]);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);
        ScreenInventory::create([
            'screen_id'     => $screen->id,
            'pricing_model' => 'io',
            'io_rate'       => 1_000_000,
            'io_rate_unit'  => 'month',
            'spot_length'   => 15,
        ]);

        $start = now()->addDays(30);

        $campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => $buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch thử',
            'start_date'      => $start->toDateString(),
            'end_date'        => $start->copy()->addMonth()->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_APPROVED,
        ]);

        $line = BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $owner->id,
            'start_date'         => $start->toDateString(),
            'end_date'           => $start->copy()->addMonth()->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => 1_000_000,
            'status'             => 'approved',
            'pricing_model'      => 'io',
        ]);

        app(PaymentService::class)->createPayment($campaign, 'bank_transfer', null, $owner->id);

        $this->cleanupCampaignIds[] = $campaign->id;
        $this->cleanupScreenIds[]   = $screen->id;
        $this->cleanupOrgIds[]      = $org->id;
        $this->cleanupUserIds[]     = $buyer->id;

        return ['campaign' => $campaign->fresh(), 'line' => $line, 'buyer' => $buyer];
    }

    public function test_huy_phai_cho_hang_chien_dich_dang_bi_giu(): void
    {
        ['campaign' => $campaign, 'line' => $line, 'buyer' => $buyer] = $this->scenario();

        $second = $this->secondConnection();

        // Kết nối khác giữ đúng hàng mốc mà việc đối soát khóa.
        DB::connection($second)->beginTransaction();

        $held = DB::connection($second)->table('campaigns')
            ->where('id', $campaign->id)
            ->lockForUpdate()
            ->first();

        // Nếu kết nối thứ hai không thấy hàng này thì nó không giữ khóa nào, và
        // phép đo bên dưới không nói lên điều gì — chính bẫy R29.
        $this->assertNotNull($held, 'Kết nối thứ hai không thấy hàng chiến dịch; fixture chưa được commit.');

        // Chờ khóa 1 giây rồi bỏ: đủ để phân biệt "có chờ" với "chạy luôn",
        // và không làm bộ test treo nếu khóa không được đòi.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $blocked = false;

        try {
            app(CancellationService::class)->cancelLine($line->fresh(), $buyer);
        } catch (QueryException $e) {
            $blocked = str_contains(mb_strtolower($e->getMessage()), 'lock wait timeout');
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
            DB::connection($second)->rollBack();
            DB::connection($second)->disconnect();
        }

        $this->assertTrue(
            $blocked,
            'cancelLine() chạy xong dù hàng chiến dịch đang bị giữ — nó không đòi hàng mốc, nên nó không xếp hàng với createPayment() và reconcileAfterPayment().'
        );

        // Và không để lại nửa vời: transaction bị bỏ thì không có nghĩa vụ
        // hoàn tiền nào được ghi.
        $this->assertSame('approved', $line->fresh()->status);
        $this->assertSame(0, Refund::where('booking_line_id', $line->id)->count());
    }

    public function test_huy_chay_binh_thuong_khi_khong_ai_giu_hang_moc(): void
    {
        ['line' => $line, 'buyer' => $buyer] = $this->scenario();

        // Ca đối chứng. Không có nó thì ca trên xanh cả khi `cancelLine()`
        // hỏng vì một lý do hoàn toàn khác — ví dụ ném QueryException vì một
        // lỗi SQL của chính tôi.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $refund = app(CancellationService::class)->cancelLine($line->fresh(), $buyer);
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame('cancelled', $line->fresh()->status);
        $this->assertSame(100, (int) $refund->refund_pct);
    }
}
