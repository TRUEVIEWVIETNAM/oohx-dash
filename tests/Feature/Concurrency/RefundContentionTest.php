<?php

namespace Tests\Feature\Concurrency;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\InventoryHold;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Hủy một dòng **trong lúc** một khoản thanh toán đang được đối soát.
 *
 * ## Khoảng trống này đã được ghi nhiều lần và đến giờ mới đo
 *
 * `RefundLockPlacementTest` chứng minh **vị trí** khóa: `cancelLine()` có đòi
 * hàng mốc chiến dịch. Nhưng vị trí khóa không phải bằng chứng không còn tranh
 * chấp — tôi đã tự ghi đúng câu đó trong docblock lớp ấy, và trong năm vòng
 * review thì đây là món nợ đo lường lớn nhất còn lại của đường tiền.
 *
 * ## Đường hỏng đang đo
 *
 * Một dòng A bị hủy khi khoản chuyển còn `pending`, nên nghĩa vụ hoàn ghi 0.
 * Sau đó sàn xác nhận khoản đó. `confirmBankTransfer()` đánh dấu `completed`
 * rồi **commit ngay**, sau đó mới mở transaction đối soát. Cùng lúc một người
 * hủy dòng B.
 *
 * Nếu `cancelLine()` không xếp hàng với việc đối soát, đọc thường của nó thấy
 * tiền đã `completed` nhưng **chưa thấy** phần vừa phân bổ cho A, và thấy A đã
 * `cancelled` nên mẫu số chỉ còn B — phần của B phình thành toàn bộ số tiền.
 * Cộng với phần đối soát vừa ghi cho A, tổng nghĩa vụ hoàn **vượt** số tiền
 * thật nhận được. Với số liệu dưới đây: 3.240.000 trên 2.160.000.
 *
 * ## Bất biến được kiểm
 *
 * Tổng `refunds.paid_amount` của một owner **không bao giờ** vượt số tiền
 * owner đó thực nhận. Và mỗi dòng không nhận quá phần chính nó bị tính.
 *
 * ## Giới hạn, nói trước
 *
 * Rào chắn nằm **ngoài** vùng tới hạn: hai tiến trình được bắn cùng lúc, chứ
 * không phải dừng một tiến trình ở giữa transaction rồi mới thả tiến trình kia.
 * Nên một lần chạy xanh **không** chứng minh mọi xen kẽ đều an toàn — nó chứng
 * minh bất biến giữ được dưới tranh chấp thật, trên một xen kẽ thật. Đó vẫn
 * nhiều hơn những gì các phép đo trước đó làm được.
 *
 * Không dùng `RefreshDatabase`: nó bọc test trong transaction chưa commit nên
 * tiến trình con không thấy fixture, và ca test sẽ **skip hoặc xanh mà không
 * đo gì** — đúng cái đã xảy ra với `DeadlockOrderTest` (bài học R29).
 */
class RefundContentionTest extends TestCase
{
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

        Role::firstOrCreate(['name' => 'buyer', 'guard_name' => 'web']);
    }

    protected function tearDown(): void
    {
        try {
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

            OrganizationUser::whereIn('organization_id', $this->cleanupOrgIds)->delete();
            User::whereIn('id', $this->cleanupUserIds)->delete();
            Organization::whereIn('id', $this->cleanupOrgIds)->delete();
        } catch (\Throwable) {
            // Dọn hết sức; không che lỗi thật của test.
        }

        parent::tearDown();
    }

    public function test_huy_trung_luc_doi_soat_khong_lam_hoan_qua_tien(): void
    {
        $org   = Organization::factory()->create(['status' => 'active']);
        $buyer = User::factory()->create(['current_organization_id' => $org->id]);
        $buyer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $org->id,
            'user_id'         => $buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        $owner = Owner::factory()->create(['status' => 'active']);

        $this->cleanupOrgIds[]  = $org->id;
        $this->cleanupUserIds[] = $buyer->id;

        $start = now()->addDays(30);

        $campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => $buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch đo tranh chấp',
            'start_date'      => $start->toDateString(),
            'end_date'        => $start->copy()->addMonth()->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_APPROVED,
        ]);

        $this->cleanupCampaignIds[] = $campaign->id;

        $lines = [];

        foreach (['A', 'B'] as $label) {
            $site   = Site::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
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

            $this->cleanupScreenIds[] = $screen->id;

            $lines[$label] = BookingLine::create([
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
        }

        $payments  = app(PaymentService::class);
        $ownerPaid = $payments->withVat(2_000_000);   // 2.160.000

        // Người mua đã chuyển đủ; sàn chưa đối chiếu nên khoản còn `pending`.
        $payment = $payments->createPayment($campaign, 'bank_transfer', null, $owner->id);
        $this->assertSame('pending', $payment->status);
        $this->assertSame($ownerPaid, (int) round((float) $payment->amount));

        // Hủy A trong lúc tiền còn chờ → nghĩa vụ hoàn ghi 0 (đúng ở thời
        // điểm đó), và đây là điều kiện để việc đối soát có việc phải làm.
        app(CancellationService::class)->cancelLine($lines['A']->fresh(), $buyer);
        $this->assertSame(0, (int) round((float) Refund::where('booking_line_id', $lines['A']->id)->value('amount')));

        // ── Bắn hai bên cùng lúc ────────────────────────────────────────────

        $ready  = tempnam(sys_get_temp_dir(), 'rc_ready');
        $go     = tempnam(sys_get_temp_dir(), 'rc_go');
        $result = tempnam(sys_get_temp_dir(), 'rc_result');
        @unlink($ready);
        @unlink($go);

        $base  = base_path();
        $child = <<<PHP
        <?php
        require '{$base}/vendor/autoload.php';
        \$app = require '{$base}/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();

        file_put_contents('{$ready}', 'ready');
        \$deadline = microtime(true) + 15;
        while (! file_exists('{$go}') && microtime(true) < \$deadline) { usleep(2000); }

        try {
            \$p = App\\Models\\Payment::findOrFail('{$payment->id}');
            app(App\\Services\\PaymentService::class)->confirmBankTransfer(\$p);
            file_put_contents('{$result}', 'ok');
        } catch (Illuminate\\Database\\QueryException \$e) {
            // Deadlock hoặc hết thời gian chờ khóa là kết quả HỢP LỆ của tranh
            // chấp: thao tác thất bại sạch, bất biến vẫn phải giữ.
            file_put_contents('{$result}', 'db:' . \$e->getMessage());
        } catch (Throwable \$e) {
            file_put_contents('{$result}', 'error:' . get_class(\$e) . ':' . \$e->getMessage());
        }
        PHP;

        $script = tempnam(sys_get_temp_dir(), 'rc') . '.php';
        file_put_contents($script, $child);

        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            $this->markTestSkipped('Không mở được tiến trình nền.');
        }

        // Chờ con nạp xong Laravel. Không chờ thì "tranh chấp" chỉ là hai thao
        // tác cách nhau vài trăm mili giây.
        $deadline = microtime(true) + 30;
        while (! file_exists($ready) && microtime(true) < $deadline) {
            usleep(5000);
        }

        $this->assertFileExists($ready, 'Tiến trình con không nạp được Laravel — chưa đo được gì.');

        $parentOutcome = 'ok';

        file_put_contents($go, 'go');

        try {
            app(CancellationService::class)->cancelLine($lines['B']->fresh(), $buyer);
        } catch (\Illuminate\Database\QueryException $e) {
            $parentOutcome = 'db:' . $e->getMessage();
        } catch (\Throwable $e) {
            $parentOutcome = 'error:' . $e::class . ':' . $e->getMessage();
        }

        $waited = microtime(true) + 30;
        while (proc_get_status($process)['running'] && microtime(true) < $waited) {
            usleep(10000);
        }

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $childOutcome = file_exists($result) ? trim((string) file_get_contents($result)) : 'khong-co-ket-qua';

        @unlink($ready);
        @unlink($go);
        @unlink($result);
        @unlink($script);

        $shown = json_encode(
            ['parent' => mb_substr($parentOutcome, 0, 160), 'child' => mb_substr($childOutcome, 0, 160)],
            JSON_UNESCAPED_UNICODE,
        );

        $this->assertSame(0, $exitCode, "Tiến trình con phải kết thúc bình thường. stderr: {$stderr}");
        $this->assertNotSame('khong-co-ket-qua', $childOutcome, 'Tiến trình con không ghi kết quả.');

        // Không nhận lỗi lập trình ở bất kỳ bên nào: một phép đo chấp nhận mọi
        // kiểu hỏng thì không phân biệt được "chặn đúng" với "hỏng" (R33).
        foreach ([$parentOutcome, $childOutcome] as $outcome) {
            $this->assertStringStartsNotWith('error:', $outcome, "Một bên hỏng vì lý do khác tranh chấp: {$shown}");
        }

        // Ít nhất một bên phải thành công. Cả hai thất bại thì bất biến giữ
        // được một cách tầm thường — xanh mà không chứng minh gì.
        $this->assertTrue(
            $parentOutcome === 'ok' || $childOutcome === 'ok',
            "Cả hai bên đều thất bại nên phép đo vô nghĩa: {$shown}",
        );

        // ── Bất biến ────────────────────────────────────────────────────────

        $completedPaid = (int) round((float) Payment::where('campaign_id', $campaign->id)
            ->where('owner_id', $owner->id)
            ->where('status', 'completed')
            ->sum('amount'));

        $allocated = (int) round((float) Refund::where('campaign_id', $campaign->id)
            ->where('owner_id', $owner->id)
            ->sum('paid_amount'));

        $this->assertLessThanOrEqual(
            $completedPaid,
            $allocated,
            "Tổng tiền phân bổ cho các khoản hoàn ({$allocated}) vượt số tiền owner thực nhận ({$completedPaid}). {$shown}",
        );

        // Và không dòng nào nhận quá phần chính nó bị tính.
        $cap = app(PaymentService::class)->withVat(1_000_000);

        foreach ($lines as $label => $line) {
            $perLine = (int) round((float) Refund::where('booking_line_id', $line->id)->sum('paid_amount'));

            $this->assertLessThanOrEqual(
                $cap,
                $perLine,
                "Dòng {$label} được phân bổ {$perLine}, quá trần {$cap} của chính nó. {$shown}",
            );
        }
    }
}
