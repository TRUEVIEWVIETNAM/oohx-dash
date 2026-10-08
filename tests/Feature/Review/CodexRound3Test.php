<?php

namespace Tests\Feature\Review;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Refund;
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
 * Chống hồi quy cho review vòng ba của Codex (R30–R32).
 *
 * R33 là chất lượng của phép đo tranh chấp, sửa trực tiếp trong
 * `ServiceRaceTest`.
 *
 * Hai ca R30 và R31 dựng đúng con số trong báo cáo, kể cả tổng hoàn 2.160.000
 * sai và phí hủy 540.000 bị mất — viết theo mô tả của người review trước, rồi
 * mới sửa code, vì tự dựng kịch bản sau khi sửa là cách chắc chắn để ra một ca
 * mà bản sửa đi qua được.
 */
class CodexRound3Test extends TestCase
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

    /** Dòng đặt chỗ bắt đầu sau $daysFromNow ngày — quyết định bậc hoàn tiền. */
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

    // ── R30 ─────────────────────────────────────────────────────────────────

    public function test_r30_phi_huy_khong_bi_chia_lai_cho_dong_con_mo(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $sa = $this->screen($owner);
        $sb = $this->screen($owner);

        $campaign = $this->campaign();
        $lineA = $this->line($campaign, $sa, 10);   // hoàn 50%
        $lineB = $this->line($campaign, $sb, 30);   // hoàn 100%

        $svc = app(PaymentService::class);
        $svc->confirmBankTransfer($svc->createPayment($campaign->fresh(), 'bank_transfer', null, $owner->id));

        $cancel = app(CancellationService::class);

        $refundA = $cancel->cancelLine($lineA->fresh(), $this->buyer);
        $this->assertSame(50, $refundA->refund_pct);
        $this->assertSame(1_080_000, (int) round((float) $refundA->paid_amount));
        $this->assertSame(540_000, (int) round((float) $refundA->amount));

        $refundB = $cancel->cancelLine($lineB->fresh(), $this->buyer);

        $this->assertSame(
            1_080_000,
            (int) round((float) $refundB->amount),
            'Phần giữ lại theo chính sách của dòng A không được biến thành tiền đã trả cho B — cách cũ ra 1.620.000.'
        );
        $this->assertSame(
            1_620_000,
            (int) round((float) Refund::sum('amount')),
            'Tổng hoàn = 540.000 (A, 50%) + 1.080.000 (B, 100%). Ra 2.160.000 là mất sạch phí hủy của A.'
        );
    }

    public function test_r30_dao_thu_tu_huy_cho_cung_tong_hoan(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $sa = $this->screen($owner);
        $sb = $this->screen($owner);

        $campaign = $this->campaign();
        $lineA = $this->line($campaign, $sa, 10);   // 50%
        $lineB = $this->line($campaign, $sb, 30);   // 100%

        $svc = app(PaymentService::class);
        $svc->confirmBankTransfer($svc->createPayment($campaign->fresh(), 'bank_transfer', null, $owner->id));

        $cancel = app(CancellationService::class);

        // Hủy B TRƯỚC, rồi A — thứ tự ngược với ca trên.
        $cancel->cancelLine($lineB->fresh(), $this->buyer);
        $cancel->cancelLine($lineA->fresh(), $this->buyer);

        $this->assertSame(
            1_620_000,
            (int) round((float) Refund::sum('amount')),
            'Tổng hoàn không được phụ thuộc thứ tự hủy.'
        );
    }

    // ── R31 ─────────────────────────────────────────────────────────────────

    public function test_r31_tien_du_cua_owner_nay_khong_bu_khoan_thieu_cua_owner_khac(): void
    {
        // Đúng kịch bản Codex: X dư sau khi hủy một dòng ở mức 50%, Y chưa
        // nhận đồng nào.
        $ownerX = Owner::factory()->create(['status' => 'active']);
        $ownerY = Owner::factory()->create(['status' => 'active']);

        $x1 = $this->screen($ownerX);
        $x2 = $this->screen($ownerX);
        $y1 = $this->screen($ownerY);

        $campaign = $this->campaign();
        $lineX1 = $this->line($campaign, $x1, 10);            // X, hoàn 50%
        $this->line($campaign, $x2, 30);                      // X, còn sống
        $this->line($campaign, $y1, 30, 500_000);             // Y, chưa trả

        $svc = app(PaymentService::class);
        $svc->confirmBankTransfer($svc->createPayment($campaign->fresh(), 'bank_transfer', null, $ownerX->id));

        app(CancellationService::class)->cancelLine($lineX1->fresh(), $this->buyer);

        $summary = $svc->getSummary($campaign->fresh());

        $this->assertFalse(
            $summary['is_fully_paid'],
            'Owner Y chưa nhận đồng nào — không được coi là đã trả đủ chỉ vì X có tiền dư. '
            . 'Giao diện dùng cờ này để quyết định hiện biểu mẫu chuyển khoản.'
        );
        $this->assertSame(
            540_000.0,
            $summary['remaining'],
            'Còn thiếu phải là phần thiếu của Y (500.000 + VAT 8%), không bị tiền dư của X trừ đi.'
        );
    }

    public function test_r31_trang_thanh_toan_van_hien_bieu_mau_cho_owner_con_thieu(): void
    {
        $ownerX = Owner::factory()->create(['status' => 'active']);
        $ownerY = Owner::factory()->create(['status' => 'active']);

        $campaign = $this->campaign();
        $campaign->update(['status' => Campaign::STATUS_APPROVED]);

        $lineX = $this->line($campaign, $this->screen($ownerX), 10);
        $this->line($campaign, $this->screen($ownerY), 30, 500_000);

        $svc = app(PaymentService::class);
        $svc->confirmBankTransfer($svc->createPayment($campaign->fresh(), 'bank_transfer', null, $ownerX->id));
        app(CancellationService::class)->cancelLine($lineX->fresh(), $this->buyer);

        $url = 'http://' . config('domains.frontpage', 'oohx.net') . '/booking/' . $campaign->id . '/payment';

        // Trang vẫn phải mở được…
        $this->actingAs($this->buyer)->get($url)->assertOk();

        // …nhưng từ 08/10/2026 nó **không render sẵn** danh sách owner nữa; nó
        // đọc `GET /api/v2/campaigns/{campaign}/payments` từ trình duyệt. Nên
        // `assertSee($ownerY->name)` trên HTML đã thành một phép kiểm rỗng —
        // nó sẽ xanh kể cả khi công nợ của ownerY biến mất hoàn toàn.
        //
        // Bảo đảm thật của R31 là: hủy dòng của ownerX và trả đủ cho ownerX
        // KHÔNG được làm ownerY trông như đã xong. Nên hỏi đúng chỗ quyết định
        // điều đó.
        $byOwner = $this->actingAs($this->buyer)
            ->getJson('/api/v2/campaigns/' . $campaign->id . '/payments')
            ->assertOk()
            ->json('data.by_owner');

        $dongY = collect($byOwner)->firstWhere('owner.id', $ownerY->id);

        $this->assertNotNull($dongY, 'ownerY còn nợ mà không còn trong danh sách công nợ');
        $this->assertFalse($dongY['is_paid']);
        $this->assertGreaterThan(0, $dongY['remaining'], 'ownerY phải vẫn còn phần cần chuyển');
    }

    // ── R32 ─────────────────────────────────────────────────────────────────

    public function test_r32_chay_lai_tong_hop_xoa_nhom_khong_con_hop_le(): void
    {
        $screen = $this->screen();
        $day    = now()->toDateString();

        // Dòng báo cáo do bản CŨ sinh ra, từ một lượt phát nay không còn hợp lệ.
        DB::table('impression_daily_rollups')->insert([
            'day'                => $day,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'campaign_id'        => '',
            'booking_line_id'    => '',
            'plays'              => 5,
            'impressions'        => 5,
            'duration_sec_total' => 75,
            'revenue_gross'      => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // Nguồn duy nhất của ngày đó là một lượt bị kẹp — nay bị loại.
        \App\Models\ImpressionLog::create([
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'event_id'           => (string) Str::ulid(),
            'played_at'          => now(),
            'reported_played_at' => now()->subDays(60),
            'played_at_clamped'  => true,
            'duration_sec'       => 15,
            'multiplier_applied' => 1,
            'imp_count'          => 1,
            'deal_type'          => 'direct',
            'source'             => 'adtrue_player',
        ]);

        app(ImpressionRollupService::class)->rollupDay(now());

        $this->assertSame(
            0,
            DB::table('impression_daily_rollups')->where('day', $day)->count(),
            'Chạy lại tổng hợp phải dọn nhóm không còn nguồn hợp lệ — chỉ upsert thì dòng sai ở lại vĩnh viễn.'
        );
    }

    public function test_r32_nhom_hop_le_van_duoc_giu_khi_chay_lai(): void
    {
        $screen = $this->screen();

        \App\Models\ImpressionLog::create([
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

        $rollups = app(ImpressionRollupService::class);
        $rollups->rollupDay(now());
        $rollups->rollupDay(now());

        $rows = DB::table('impression_daily_rollups')->get();

        $this->assertCount(1, $rows, 'Xóa-rồi-ghi không được nhân đôi nhóm hợp lệ.');
        $this->assertSame(1, (int) $rows[0]->plays);
    }
}
