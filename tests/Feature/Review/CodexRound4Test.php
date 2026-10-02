<?php

namespace Tests\Feature\Review;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\ImpressionLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\Booking\CancellationService;
use App\Services\PaymentService;
use App\Services\Player\ImpressionRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Chống hồi quy cho review vòng bốn của Codex (R34, R35).
 *
 * R34 dựng đúng chuỗi mà test vòng ba của tôi **bỏ sót**: trả MỘT PHẦN rồi mới
 * hủy. Test cũ trả đủ trước khi hủy nên không bao giờ đi qua đường trả thêm —
 * đó là lý do lỗi này sống sót qua một vòng.
 */
class CodexRound4Test extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['buyer', 'publisher', 'super_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->org   = Organization::factory()->create(['status' => 'active']);
        $this->buyer = User::factory()->create(['current_organization_id' => $this->org->id]);
        $this->buyer->assignRole('buyer');
        OrganizationUser::create([
            'organization_id' => $this->org->id,
            'user_id'         => $this->buyer->id,
            'role'            => OrganizationUser::ROLE_ADMIN,
        ]);

        $this->actingAs($this->buyer);
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

    private function campaign(): Campaign
    {
        return Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch',
            'start_date'      => now()->addDays(10)->toDateString(),
            'end_date'        => now()->addDays(60)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_APPROVED,
        ]);
    }

    private function line(Campaign $campaign, Screen $screen, int $daysFromNow, int $cost = 1_000_000): BookingLine
    {
        $start = now()->addDays($daysFromNow);

        return BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'start_date'         => $start->toDateString(),
            'end_date'           => $start->copy()->addDays(20)->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => $cost,
            'status'             => 'approved',
            'pricing_model'      => 'io',
        ]);
    }

    // ── R34 ─────────────────────────────────────────────────────────────────

    public function test_r34_tra_mot_phan_roi_huy_50_phan_tram_van_tra_not_duoc(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $campaign = $this->campaign();
        $lineA = $this->line($campaign, $this->screen($owner), 10);   // hoàn 50%
        $this->line($campaign, $this->screen($owner), 30);            // còn sống

        $svc = app(PaymentService::class);

        // Trả MỘT NỬA tổng nghĩa vụ (2.160.000 → trả 1.080.000).
        $svc->confirmBankTransfer($svc->createPayment($campaign->fresh(), 'bank_transfer', 1_080_000, $owner->id));

        app(CancellationService::class)->cancelLine($lineA->fresh(), $this->buyer);

        $row = $svc->breakdownByOwner($campaign->fresh())->firstWhere(fn ($r) => $r['owner']->id === $owner->id);

        $this->assertSame(540_000.0, $row['remaining'], 'Dòng còn sống nợ 1.080.000, đã có 540.000.');

        // Biểu mẫu gửi đúng con số remaining này. Trước đây công nợ báo 270.000
        // nên nó bị 422 "vượt quá phần còn nợ".
        $this->assertSame(
            540_000,
            $svc->outstandingForOwner($campaign->fresh(), $owner->id),
            'Công nợ và công nợ hiển thị phải là cùng một con số.'
        );

        $topUp = $svc->createPayment($campaign->fresh(), 'bank_transfer', 540_000, $owner->id, 'top-up');
        $svc->confirmBankTransfer($topUp);

        $this->assertSame(0, $svc->outstandingForOwner($campaign->fresh(), $owner->id));
        $this->assertTrue(
            $svc->breakdownByOwner($campaign->fresh())->firstWhere(fn ($r) => $r['owner']->id === $owner->id)['is_paid'],
            'Trả nốt rồi thì cả hai phép tính phải về 0 — không được chỗ này 0 chỗ kia còn nợ.'
        );
    }

    public function test_r34_huy_khong_hoan_dong_nao_thi_cong_no_khong_ve_0(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $campaign = $this->campaign();

        // Dòng bắt đầu sau 3 ngày: hoàn 0%, bản ghi ở trạng thái waived.
        $lineA = $this->line($campaign, $this->screen($owner), 3);
        $this->line($campaign, $this->screen($owner), 30);

        $svc = app(PaymentService::class);
        $svc->confirmBankTransfer($svc->createPayment($campaign->fresh(), 'bank_transfer', 1_080_000, $owner->id));

        app(CancellationService::class)->cancelLine($lineA->fresh(), $this->buyer);

        $this->assertSame(
            540_000,
            $svc->outstandingForOwner($campaign->fresh(), $owner->id),
            'Bản ghi waived cũng mang paid_amount; bỏ qua nó là báo đã đủ tiền ngay trong khi còn thiếu.'
        );
    }

    // ── R35 ─────────────────────────────────────────────────────────────────

    public function test_r35_khoa_ngay_duoc_giu_truoc_khi_doc_nguon(): void
    {
        $screen = $this->screen();

        ImpressionLog::create([
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'event_id'           => (string) Str::ulid(),
            'played_at'          => now(),
            'reported_played_at' => now(),
            'played_at_clamped'  => false,
            'duration_sec'       => 15,
            'multiplier_applied' => 1,
            'imp_count'          => 1,
            'deal_type'          => 'direct',
            'source'             => 'adtrue_player',
        ]);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = strtolower($q->sql);
        });

        app(ImpressionRollupService::class)->rollupDay(now());

        $lockIndex = $this->firstIndexMatching(
            $queries,
            fn ($sql) => str_contains($sql, 'impression_rollup_locks') && str_contains($sql, 'for update'),
        );
        $sourceIndex = $this->firstIndexMatching(
            $queries,
            fn ($sql) => str_contains($sql, 'from `impression_logs`') && str_contains($sql, 'group by'),
        );
        $deleteIndex = $this->firstIndexMatching(
            $queries,
            fn ($sql) => str_starts_with($sql, 'delete from `impression_daily_rollups`'),
        );

        $this->assertNotNull($lockIndex, 'Không thấy câu khóa ngày — hai lần chạy cùng ngày không phải xếp hàng.');
        $this->assertNotNull($sourceIndex);
        $this->assertNotNull($deleteIndex);

        $this->assertLessThan(
            $sourceIndex,
            $lockIndex,
            'Khóa phải giành TRƯỚC khi đọc nguồn. Đọc trước rồi khóa sau là mang ảnh chụp cũ đi xóa dữ liệu mới.'
        );
        $this->assertLessThan($deleteIndex, $lockIndex);
    }

    public function test_r35_doc_nguon_la_doc_co_khoa(): void
    {
        $screen = $this->screen();

        ImpressionLog::create([
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'event_id'           => (string) Str::ulid(),
            'played_at'          => now(),
            'reported_played_at' => now(),
            'played_at_clamped'  => false,
            'duration_sec'       => 15,
            'multiplier_applied' => 1,
            'imp_count'          => 1,
            'deal_type'          => 'direct',
            'source'             => 'adtrue_player',
        ]);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = strtolower($q->sql);
        });

        app(ImpressionRollupService::class)->rollupDay(now());

        $sourceRead = null;
        foreach ($queries as $sql) {
            if (str_contains($sql, 'from `impression_logs`') && str_contains($sql, 'group by')) {
                $sourceRead = $sql;
                break;
            }
        }

        $this->assertNotNull($sourceRead);
        $this->assertStringContainsString(
            'for update',
            $sourceRead,
            'Khóa ngày bắt xếp hàng, nhưng đọc thường vẫn dùng ảnh chụp cũ — đúng bài học R04, lặp lại ở chỗ khác.'
        );
    }

    public function test_r35_chay_lai_sau_khi_co_du_lieu_moi_khong_lam_mat_nhom(): void
    {
        $screen = $this->screen();

        // Lần chạy đầu: chưa có gì.
        $rollups = app(ImpressionRollupService::class);
        $this->assertSame(0, $rollups->rollupDay(now()));

        // Dữ liệu mới về sau đó.
        ImpressionLog::create([
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'event_id'           => (string) Str::ulid(),
            'played_at'          => now(),
            'reported_played_at' => now(),
            'played_at_clamped'  => false,
            'duration_sec'       => 15,
            'multiplier_applied' => 1,
            'imp_count'          => 1,
            'deal_type'          => 'direct',
            'source'             => 'adtrue_player',
        ]);

        $rollups->rollupDay(now());

        $this->assertSame(
            1,
            DB::table('impression_daily_rollups')->count(),
            'Lần chạy sau phải thấy dữ liệu mới, không được giữ kết quả rỗng của lần trước.'
        );
    }

    /** @param array<int, string> $items */
    private function firstIndexMatching(array $items, callable $matches): ?int
    {
        foreach ($items as $i => $item) {
            if ($matches($item)) {
                return $i;
            }
        }

        return null;
    }
}
