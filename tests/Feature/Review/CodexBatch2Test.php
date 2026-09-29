<?php

namespace Tests\Feature\Review;

use App\Models\ApiClient;
use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Creative;
use App\Models\ImpressionLog;
use App\Models\Network;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\Booking\BundleExpander;
use App\Services\Network\NetworkRelationReconciler;
use App\Services\PaymentService;
use App\Services\Player\DeviceAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Chống hồi quy cho nhóm 3–5 phản hồi review Codex (29/09/2026).
 *
 * Khác nhóm 1, các ca ở đây được dựng theo **đúng kịch bản Codex mô tả** trước
 * khi sửa — bài học từ ca R12: viết test sau khi sửa rất dễ ra một ca mà bản
 * sửa đi qua được, chứ không phải ca bắt được lỗi.
 */
class CodexBatch2Test extends TestCase
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

    private function campaign(Screen $screen, int $cost = 1_000_000): Campaign
    {
        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch',
            'start_date'      => now()->subDays(5)->toDateString(),
            'end_date'        => now()->addDays(25)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_APPROVED,
        ]);

        BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'start_date'         => now()->subDays(5)->toDateString(),
            'end_date'           => now()->addDays(25)->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => $cost,
            'status'             => 'active',
            'pricing_model'      => 'io',
        ]);

        return $campaign->fresh();
    }

    // ── R07 ─────────────────────────────────────────────────────────────────

    public function test_r07_tra_phan_con_lai_trong_cung_phien_van_tao_duoc_khoan_moi(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 2_000_000);

        $svc = app(PaymentService::class);

        // Trả một nửa, admin xác nhận.
        $first = $svc->createPayment($campaign, 'bank_transfer', 1_080_000, $screen->owner_id, 'nonce-lan-1');
        $svc->confirmBankTransfer($first);

        // Cùng phiên, quay lại trả nốt. Mã chống trùng là của LẦN gửi biểu mẫu
        // này nên phải khác; trước đây nó là token CSRF của phiên, không đổi.
        $second = $svc->createPayment($campaign->fresh(), 'bank_transfer', null, $screen->owner_id, 'nonce-lan-2');

        $this->assertNotSame($first->id, $second->id, 'Lần trả thứ hai bị trả về khoản cũ nghĩa là khách không trả nốt được.');
        $this->assertSame(1_080_000, (int) round((float) $second->amount));
    }

    public function test_r07_bam_hai_lan_cung_bieu_mau_van_chi_mot_khoan(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen);

        $svc = app(PaymentService::class);
        $a = $svc->createPayment($campaign, 'bank_transfer', null, $screen->owner_id, 'cung-mot-lan-gui');
        $b = $svc->createPayment($campaign->fresh(), 'bank_transfer', null, $screen->owner_id, 'cung-mot-lan-gui');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Payment::count());
    }

    // ── R20 ─────────────────────────────────────────────────────────────────

    public function test_r20_so_hoa_don_vuot_moc_10000(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaign($screen, 50_000_000);
        $prefix   = 'INV-' . now()->format('Ym') . '-';

        // Dựng sẵn lịch sử vắt qua mốc bốn chữ số.
        foreach (['9999', '10000', '10001'] as $i => $suffix) {
            Payment::create([
                'campaign_id'     => $campaign->id,
                'organization_id' => $this->org->id,
                'owner_id'        => null,
                'amount'          => 1000,
                'currency'        => 'VND',
                'method'          => 'bank_transfer',
                'status'          => 'completed',
                'invoice_number'  => $prefix . $suffix,
            ]);
        }

        $payment = app(PaymentService::class)->createPayment($campaign->fresh(), 'bank_transfer', 1_000_000, $screen->owner_id);

        $this->assertSame(
            $prefix . '10002',
            $payment->invoice_number,
            'Sắp theo chuỗi thì 9999 đứng trên 10000, và từ hóa đơn thứ 10.000 trở đi không cấp được số nào nữa.'
        );
    }

    // ── R21 ─────────────────────────────────────────────────────────────────

    public function test_r21_chia_tien_khong_tran_so_voi_gia_tri_lon(): void
    {
        // Đúng ví dụ Codex đưa: tích total × weight vượt số nguyên 64 bit.
        $parts = BundleExpander::splitVnd(100_000_000_000, [100_000_000, 99_999_999]);

        $this->assertSame(100_000_000_000, array_sum($parts), 'Tổng phải đúng bằng số ban đầu, không âm và không tràn.');
        $this->assertGreaterThan(0, $parts[0]);
        $this->assertGreaterThan(0, $parts[1]);
    }

    public function test_r21_ti_le_van_dung_voi_so_lon(): void
    {
        $parts = BundleExpander::splitVnd(100_000_000_000, [1, 3]);

        $this->assertSame(100_000_000_000, array_sum($parts));
        $this->assertSame(25_000_000_000, $parts[0]);
        $this->assertSame(75_000_000_000, $parts[1]);
    }

    // ── R19 ─────────────────────────────────────────────────────────────────

    private function networkNamed(string $code, Owner $owner): Network
    {
        return Network::factory()->create(['code' => $code, 'name' => $code, 'owner_id' => $owner->id]);
    }

    public function test_r19_khong_roi_xuong_nguon_thu_ba_khi_kho_mau_thuan(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $a = $this->networkNamed('net-a', $owner);
        $b = $this->networkNamed('net-b', $owner);
        $this->networkNamed('net-c', $owner);

        $site = Site::factory()->create(['owner_id' => $owner->id, 'network_id' => null]);

        // Hai màn hình chỉ về A và B trong kho, nhưng network_code đều là C.
        foreach ([$a->id, $b->id] as $networkId) {
            $screen = Screen::factory()->create([
                'owner_id'     => $owner->id,
                'site_id'      => $site->id,
                'active'       => true,
                'network_code' => 'net-c',
            ]);
            ScreenInventory::factory()->create(['screen_id' => $screen->id, 'network_id' => $networkId]);
        }

        $result = app(NetworkRelationReconciler::class)->run();

        $this->assertNull(
            DB::table('sites')->where('id', $site->id)->value('network_id'),
            'Kho nói hai nơi khác nhau thì không được lặng lẽ lấy nguồn thứ ba làm đáp án.'
        );
        $this->assertNotEmpty($result['conflicts'], 'Phải báo là chưa giải quyết được.');
    }

    public function test_r19_chay_thu_va_chay_that_bao_cung_mot_ket_qua(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $a = $this->networkNamed('net-a', $owner);
        $b = $this->networkNamed('net-b', $owner);

        // Site 1: kho nói A, code nói B — hai nguồn mâu thuẫn.
        $site1  = Site::factory()->create(['owner_id' => $owner->id, 'network_id' => null]);
        $s1     = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site1->id, 'network_code' => 'net-b']);
        ScreenInventory::factory()->create(['screen_id' => $s1->id, 'network_id' => $a->id]);

        // Site 2: chỉ có code, không mâu thuẫn.
        $site2 = Site::factory()->create(['owner_id' => $owner->id, 'network_id' => null]);
        $s2    = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site2->id, 'network_code' => 'net-b']);
        ScreenInventory::factory()->create(['screen_id' => $s2->id, 'network_id' => null]);

        $dry  = app(NetworkRelationReconciler::class)->run(dryRun: true);
        $real = app(NetworkRelationReconciler::class)->run();

        $this->assertSame($dry['filled_from_inventory'], $real['filled_from_inventory']);
        $this->assertSame($dry['filled_from_screen_code'], $real['filled_from_screen_code']);
        $this->assertSame(count($dry['conflicts']), count($real['conflicts']), 'Chạy thử tồn tại để cho biết chạy thật sẽ làm gì.');

        $this->assertNull(DB::table('sites')->where('id', $site1->id)->value('network_id'));
        $this->assertSame($b->id, (int) DB::table('sites')->where('id', $site2->id)->value('network_id'));
    }

    // ── R15, R16, R17, R18 ──────────────────────────────────────────────────

    private function playerScreen(): array
    {
        $screen = $this->screen();
        $token  = app(DeviceAuthenticator::class)->issueToken($screen);

        return [$screen->fresh(), $token];
    }

    private function send(Screen $screen, string $token, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Device-Token' => $token])->postJson('/api/v1/player/impression', array_merge([
            'screen_uuid'  => $screen->uuid,
            'event_id'     => (string) Str::ulid(),
            'duration_sec' => 15,
            'played_at'    => now()->toIso8601String(),
        ], $payload));
    }

    public function test_r15_cung_su_kien_moc_lech_vai_giay_chi_ghi_mot_lan(): void
    {
        [$screen, $token] = $this->playerScreen();
        $eventId = (string) Str::ulid();

        $this->send($screen, $token, ['event_id' => $eventId, 'played_at' => now()->subMinutes(5)->toIso8601String()])
            ->assertStatus(201);

        $this->send($screen, $token, ['event_id' => $eventId, 'played_at' => now()->subMinutes(5)->addSecond()->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(
            1,
            ImpressionLog::count(),
            'Khóa unique chứa played_at nên hai mốc khác nhau là hai khóa khác nhau — chống trùng phải nằm ở bảng không phân vùng.'
        );
    }

    public function test_r16_khong_ghi_campaign_id_do_thiet_bi_tu_khai(): void
    {
        [$screen, $token] = $this->playerScreen();

        // Không có dòng đặt chỗ nào cho màn hình này.
        $this->send($screen, $token, ['campaign_id' => (string) Str::ulid()])->assertStatus(201);

        $this->assertNull(
            ImpressionLog::firstOrFail()->campaign_id,
            'Không xác minh được thì để trống, không quy thuộc cho một chiến dịch bất kỳ.'
        );
    }

    public function test_r16_khong_ghi_creative_cua_chien_dich_khac(): void
    {
        [$screen, $token] = $this->playerScreen();
        $campaign = $this->campaign($screen);

        $otherCampaign = $this->campaign($this->screen());
        $foreign = Creative::create([
            'campaign_id'     => $otherCampaign->id,
            'organization_id' => $this->org->id,
            'name'            => 'Mẫu của chiến dịch khác',
            'type'            => 'image',
            'file_path'       => 'x.jpg',
            'status'          => 'approved',
        ]);

        $this->send($screen, $token, [
            'campaign_id' => $campaign->id,
            'creative_id' => $foreign->id,
        ])->assertStatus(201);

        $this->assertNull(ImpressionLog::firstOrFail()->creative_id);
    }

    public function test_r16_khong_doan_khi_nhieu_dong_cung_khop(): void
    {
        [$screen, $token] = $this->playerScreen();
        $campaign = $this->campaign($screen);

        // Dòng thứ hai của cùng chiến dịch, cùng màn hình, chồng ngày.
        BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'start_date'         => now()->subDays(3)->toDateString(),
            'end_date'           => now()->addDays(20)->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 50,
            'estimated_cost'     => 500_000,
            'status'             => 'active',
            'pricing_model'      => 'io',
        ]);

        $this->send($screen, $token, ['campaign_id' => $campaign->id])->assertStatus(201);

        $this->assertNull(
            ImpressionLog::firstOrFail()->booking_line_id,
            'Hai dòng cùng khớp thì gắn bừa một cái là tạo bằng chứng cho suất này và bỏ trống suất kia.'
        );
    }

    public function test_r17_giu_moc_goc_khi_bi_kep_bien(): void
    {
        [$screen, $token] = $this->playerScreen();
        $reported = now()->subDays(60);

        $this->send($screen, $token, ['played_at' => $reported->toIso8601String()])->assertStatus(201);

        $log = ImpressionLog::firstOrFail();

        $this->assertTrue($log->played_at_clamped);
        $this->assertSame(
            $reported->toDateString(),
            $log->reported_played_at->toDateString(),
            'Kẹp để không ghi sai kỳ là đúng, nhưng xóa luôn mốc gốc thì mất khả năng đối soát.'
        );
    }

    public function test_r18_luot_phat_gui_muon_ba_ngay_van_vao_bao_cao(): void
    {
        [$screen, $token] = $this->playerScreen();

        $this->send($screen, $token, ['played_at' => now()->subDays(3)->toIso8601String()])->assertStatus(201);

        // Lịch định kỳ chạy đúng chế độ mặc định.
        $this->artisan('impressions:rollup')->assertSuccessful();

        $this->assertSame(
            1,
            DB::table('impression_daily_rollups')->count(),
            'API nhận muộn tới 7 ngày thì lịch tổng hợp phải phủ trọn đúng cửa sổ đó.'
        );
    }
}
