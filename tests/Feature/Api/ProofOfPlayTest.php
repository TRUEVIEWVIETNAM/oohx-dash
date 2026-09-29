<?php

namespace Tests\Feature\Api;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\ImpressionLog;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\Player\DeviceAuthenticator;
use App\Services\Player\ImpressionRollupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Giai đoạn 2 — đường ghi bằng chứng phát sóng.
 *
 * Trước sửa, đường này hỏng ở bốn chỗ cùng lúc:
 *
 *  1. **Không ghi được dòng nào.** Khóa chính `(id, played_at)` với `id` là
 *     `char(26)` không có mặc định, model thiếu `HasUlids` → SQLSTATE 1364.
 *     Toàn bộ "bằng chứng phát sóng" của sàn không tồn tại.
 *  2. **Ai cũng ghi được.** Chỉ cần biết `screen_uuid` — thứ nằm trong cấu
 *     hình thiết bị và trong log — là bơm được lượt hiển thị, tức là chế ra
 *     doanh thu. Cột `device_token` có sẵn từ đầu nhưng chưa từng được dùng.
 *  3. **Cộng trùng.** Thiết bị mất mạng gửi lại là cộng thêm lượt.
 *  4. **Không nối được với đơn hàng.** `campaign_id` khai `unsignedBigInteger`
 *     trong khi chiến dịch dùng ULID, và luật kiểm đầu vào ghi `integer`.
 */
class ProofOfPlayTest extends TestCase
{
    use RefreshDatabase;

    private Screen $screen;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = Owner::factory()->create(['status' => 'active']);
        $site  = Site::factory()->create(['owner_id' => $owner->id]);

        $this->screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenInventory::create([
            'screen_id'     => $this->screen->id,
            'pricing_model' => 'io',
            'io_rate'       => 1_000_000,
            'io_rate_unit'  => 'month',
            'spot_length'   => 15,
        ]);

        $this->screen = $this->screen->fresh('inventory');
        $this->token  = app(DeviceAuthenticator::class)->issueToken($this->screen);
        $this->screen->refresh();
    }

    private function post(array $payload, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        $headers = $token === null ? [] : ['X-Device-Token' => $token];

        return $this->withHeaders($headers)->postJson('/api/v1/player/impression', $payload);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'screen_uuid'  => $this->screen->uuid,
            'event_id'     => (string) Str::ulid(),
            'duration_sec' => 15,
            // Bắt buộc: thời điểm phát là nội dung chính của bằng chứng, và
            // chống trùng dựa vào nó.
            'played_at'    => now()->toIso8601String(),
        ], $override);
    }

    // ── 1. Ghi được ─────────────────────────────────────────────────────────

    public function test_ghi_duoc_mot_luot_phat(): void
    {
        $response = $this->post($this->payload(), $this->token);

        $response->assertStatus(201)->assertJsonPath('duplicate', false);

        $this->assertSame(1, ImpressionLog::count(), 'Bảng này trước đây chèn là hỏng với SQLSTATE 1364.');
        $this->assertSame(26, strlen(ImpressionLog::first()->id), 'Khóa chính phải là ULID do model sinh.');
    }

    // ── 2. Xác thực thiết bị ────────────────────────────────────────────────

    public function test_khong_co_token_thi_khong_ghi_duoc(): void
    {
        $this->post($this->payload())->assertStatus(401);

        $this->assertSame(0, ImpressionLog::count(), 'Biết UUID không đủ để chế ra bằng chứng phát sóng.');
    }

    public function test_token_sai_thi_khong_ghi_duoc(): void
    {
        $this->post($this->payload(), 'token-bia-dat')->assertStatus(401);

        $this->assertSame(0, ImpressionLog::count());
    }

    public function test_man_hinh_chua_duoc_cap_token_thi_khong_ghi_duoc(): void
    {
        $other = Screen::factory()->create([
            'owner_id' => $this->screen->owner_id,
            'site_id'  => $this->screen->site_id,
            'active'   => true,
        ]);

        $this->post($this->payload(['screen_uuid' => $other->uuid]), $this->token)
            ->assertStatus(401);
    }

    public function test_token_cua_man_hinh_khac_khong_dung_duoc(): void
    {
        $other = Screen::factory()->create([
            'owner_id' => $this->screen->owner_id,
            'site_id'  => $this->screen->site_id,
            'active'   => true,
        ]);
        $otherToken = app(DeviceAuthenticator::class)->issueToken($other);

        $this->post($this->payload(), $otherToken)->assertStatus(401);
    }

    public function test_token_luu_duoi_dang_bam(): void
    {
        $this->assertNotSame(
            $this->token,
            $this->screen->fresh()->device_token,
            'Đọc được CSDL không được phép là giả mạo được thiết bị.'
        );
    }

    public function test_heartbeat_cung_can_token(): void
    {
        $this->postJson('/api/v1/player/heartbeat', ['screen_uuid' => $this->screen->uuid])
            ->assertStatus(401);

        $this->withHeaders(['X-Device-Token' => $this->token])
            ->postJson('/api/v1/player/heartbeat', ['screen_uuid' => $this->screen->uuid])
            ->assertOk();
    }

    // ── 3. Chống trùng ──────────────────────────────────────────────────────

    public function test_gui_lai_cung_su_kien_khong_cong_them_luot(): void
    {
        $payload = $this->payload();

        $this->post($payload, $this->token)->assertStatus(201);
        $second = $this->post($payload, $this->token);

        $second->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, ImpressionLog::count(), 'Gửi lại sau khi mất mạng không được cộng thêm tiền.');
    }

    public function test_thieu_thoi_diem_phat_thi_bi_tu_choi(): void
    {
        $payload = $this->payload();
        unset($payload['played_at']);

        $this->post($payload, $this->token)->assertStatus(422);
    }

    public function test_gui_lai_voi_moc_thoi_gian_lech_van_khong_cong_them(): void
    {
        $eventId = (string) Str::ulid();

        $this->post($this->payload([
            'event_id'  => $eventId,
            'played_at' => now()->subMinutes(10)->toIso8601String(),
        ]), $this->token)->assertStatus(201);

        // Thiết bị gửi lại nhưng đồng hồ đã nhích: mốc khác một chút.
        $this->post($this->payload([
            'event_id'  => $eventId,
            'played_at' => now()->subMinutes(9)->toIso8601String(),
        ]), $this->token)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(
            1,
            ImpressionLog::count(),
            'Khóa unique chứa played_at nên không chặn được trường hợp này — phải hỏi theo event_id trước khi ghi.'
        );
    }

    public function test_hai_man_hinh_trung_ma_su_kien_van_ghi_ca_hai(): void
    {
        $eventId = (string) Str::ulid();

        $other = Screen::factory()->create([
            'owner_id' => $this->screen->owner_id,
            'site_id'  => $this->screen->site_id,
            'active'   => true,
        ]);
        $otherToken = app(DeviceAuthenticator::class)->issueToken($other);

        $this->post($this->payload(['event_id' => $eventId]), $this->token)->assertStatus(201);
        $this->post($this->payload(['event_id' => $eventId, 'screen_uuid' => $other->uuid]), $otherToken)
            ->assertStatus(201);

        $this->assertSame(2, ImpressionLog::count(), 'event_id do thiết bị tự sinh, chỉ duy nhất trong phạm vi một thiết bị.');
    }

    public function test_hai_su_kien_khac_nhau_van_ghi_ca_hai(): void
    {
        $this->post($this->payload(), $this->token)->assertStatus(201);
        $this->post($this->payload(), $this->token)->assertStatus(201);

        $this->assertSame(2, ImpressionLog::count());
    }

    // ── 4. Nối với đơn hàng ─────────────────────────────────────────────────

    private function activeLine(): BookingLine
    {
        $org = Organization::factory()->create(['status' => 'active']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);

        $campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch',
            'start_date'      => now()->subDays(5)->toDateString(),
            'end_date'        => now()->addDays(25)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_ACTIVE,
        ]);

        return BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $this->screen->id,
            'owner_id'           => $this->screen->owner_id,
            'start_date'         => now()->subDays(5)->toDateString(),
            'end_date'           => now()->addDays(25)->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => 1_000_000,
            'status'             => 'active',
            'pricing_model'      => 'io',
        ]);
    }

    public function test_luot_phat_noi_duoc_voi_dong_dat_cho(): void
    {
        $line = $this->activeLine();

        $this->post($this->payload(['booking_line_id' => $line->id]), $this->token)->assertStatus(201);

        $log = ImpressionLog::firstOrFail();

        $this->assertSame($line->id, $log->booking_line_id);
        $this->assertSame($line->campaign_id, $log->campaign_id, 'Chiến dịch suy từ dòng, không tin client.');
    }

    public function test_suy_duoc_dong_dat_cho_tu_chien_dich(): void
    {
        $line = $this->activeLine();

        $this->post($this->payload(['campaign_id' => $line->campaign_id]), $this->token)->assertStatus(201);

        $this->assertSame($line->id, ImpressionLog::firstOrFail()->booking_line_id);
    }

    public function test_khong_gan_dong_cua_man_hinh_khac(): void
    {
        $line = $this->activeLine();

        // Dòng thuộc màn hình khác: thiết bị gửi id của nó cũng không được gắn.
        $otherScreen = Screen::factory()->create([
            'owner_id' => $this->screen->owner_id,
            'site_id'  => $this->screen->site_id,
        ]);
        $line->update(['screen_id' => $otherScreen->id]);

        $this->post($this->payload(['booking_line_id' => $line->id]), $this->token)->assertStatus(201);

        $this->assertNull(
            ImpressionLog::firstOrFail()->booking_line_id,
            'Gắn sai còn tệ hơn không gắn: nó tạo ra bằng chứng cho một suất không bán.'
        );
    }

    public function test_id_dang_ulid_khong_bi_luat_kiem_tu_choi(): void
    {
        // Luật cũ ghi 'integer' nên mọi id thật đều bị từ chối 422.
        $this->post($this->payload(['campaign_id' => (string) Str::ulid()]), $this->token)
            ->assertStatus(201);
    }

    // ── Chặn biên đồng hồ ───────────────────────────────────────────────────

    public function test_thoi_diem_phat_o_tuong_lai_bi_keo_ve_hien_tai(): void
    {
        $this->post($this->payload(['played_at' => now()->addYear()->toIso8601String()]), $this->token)
            ->assertStatus(201);

        $this->assertTrue(
            ImpressionLog::firstOrFail()->played_at->lessThanOrEqualTo(now()->addMinutes(6)),
            'Đồng hồ thiết bị sai không được phép ghi vào tương lai — nó rơi vào kỳ báo cáo và hóa đơn khác.'
        );
    }

    public function test_bao_muon_trong_han_thi_giu_nguyen_moc(): void
    {
        $twoDaysAgo = now()->subDays(2)->startOfHour();

        $this->post($this->payload(['played_at' => $twoDaysAgo->toIso8601String()]), $this->token)
            ->assertStatus(201);

        $this->assertSame(
            $twoDaysAgo->toDateTimeString(),
            ImpressionLog::firstOrFail()->played_at->toDateTimeString(),
            'Thiết bị mất mạng gửi bù là bình thường, mốc thật phải được giữ.'
        );
    }

    public function test_bao_muon_qua_han_bi_kep_ve_bien(): void
    {
        $this->post($this->payload(['played_at' => now()->subDays(60)->toIso8601String()]), $this->token)
            ->assertStatus(201);

        $playedAt = ImpressionLog::firstOrFail()->played_at;

        $this->assertTrue($playedAt->greaterThan(now()->subDays(8)), 'Kẹp về biên 7 ngày.');
        $this->assertSame(1, ImpressionLog::count(), 'Kẹp chứ không vứt dữ liệu đi.');
    }

    // ── Tổng hợp theo ngày ──────────────────────────────────────────────────

    public function test_tong_hop_theo_ngay_va_chay_lai_khong_nhan_doi(): void
    {
        $line = $this->activeLine();

        foreach (range(1, 3) as $i) {
            $this->post($this->payload(['booking_line_id' => $line->id]), $this->token)->assertStatus(201);
        }

        $rollups = app(ImpressionRollupService::class);
        $rollups->rollupDay(now());
        $rollups->rollupDay(now());   // chạy lại

        $rows = DB::table('impression_daily_rollups')->get();

        $this->assertCount(1, $rows, 'Chạy lại phép tổng hợp không được đẻ thêm dòng.');
        $this->assertSame(3, (int) $rows[0]->plays);
        $this->assertSame(45, (int) $rows[0]->duration_sec_total);
    }

    public function test_tong_hop_tach_theo_tung_man_hinh(): void
    {
        $this->post($this->payload(), $this->token)->assertStatus(201);

        $other = Screen::factory()->create([
            'owner_id' => $this->screen->owner_id,
            'site_id'  => $this->screen->site_id,
            'active'   => true,
        ]);
        $otherToken = app(DeviceAuthenticator::class)->issueToken($other);

        $this->post($this->payload(['screen_uuid' => $other->uuid]), $otherToken)->assertStatus(201);

        app(ImpressionRollupService::class)->rollupDay(now());

        $this->assertSame(2, DB::table('impression_daily_rollups')->count());
    }

    public function test_lenh_tong_hop_chay_duoc(): void
    {
        $this->post($this->payload(), $this->token)->assertStatus(201);

        $this->artisan('impressions:rollup')->assertSuccessful();

        $this->assertSame(1, DB::table('impression_daily_rollups')->count());
    }

    // ── Lệnh cấp token ──────────────────────────────────────────────────────

    public function test_lenh_cap_token_in_ra_ban_ro_mot_lan(): void
    {
        $fresh = Screen::factory()->create([
            'owner_id' => $this->screen->owner_id,
            'site_id'  => $this->screen->site_id,
        ]);

        $this->artisan('screens:issue-device-token', ['screen' => $fresh->uuid])
            ->assertSuccessful();

        $this->assertNotNull($fresh->fresh()->device_token);
    }
}
