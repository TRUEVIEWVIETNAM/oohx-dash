<?php

namespace Tests\Feature\Concurrency;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\ImpressionLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\Booking\CancellationService;
use App\Services\PaymentService;
use App\Services\Player\DeviceAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Đo thật ba chỗ tuần tự hóa còn lại: R08, R13, R15.
 *
 * Ba bản sửa này tôi từng ghi là "đúng lập luận nhưng chưa đo", và đó là cách
 * nói giảm cho "chưa biết có đúng không". Cách đo ở đây:
 *
 * - **R08 và R13** dựa vào một khóa hàng. Ở đây tôi kiểm rằng **câu khóa thật
 *   sự được phát ra, và phát ra TRƯỚC phép ghi** — bắt bằng cách nghe toàn bộ
 *   SQL của đường chạy thật.
 * - **R15** dựa vào ràng buộc duy nhất ở CSDL, không phụ thuộc thời điểm, nên
 *   đo được mà không cần tranh chấp: chiếm chỗ trước bằng SQL rồi gửi sự kiện.
 *
 * **Nói rõ giới hạn:** hai ca đầu không chạy hai luồng thật. Chúng chứng minh
 * *điểm tuần tự hóa nằm đúng chỗ trên đường chạy*, còn việc `FOR UPDATE` thật
 * sự chặn được kết nối khác thì đã đo riêng ở `DeadlockOrderTest` và
 * `SnapshotReadTest`. Hai mảnh đó ghép lại mới thành chuỗi lập luận đầy đủ; tôi
 * không tuyên bố ca này một mình chứng minh được an toàn khi tranh chấp.
 */
class SerializationPointsTest extends TestCase
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

    private function campaignWithLine(Screen $screen): Campaign
    {
        $campaign = Campaign::create([
            'organization_id' => $this->org->id,
            'created_by'      => $this->buyer->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Chiến dịch',
            'start_date'      => now()->addMonth()->toDateString(),
            'end_date'        => now()->addMonths(2)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_APPROVED,
        ]);

        BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'start_date'         => now()->addMonth()->toDateString(),
            'end_date'           => now()->addMonths(2)->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => 1_000_000,
            'status'             => 'approved',
            'pricing_model'      => 'io',
        ]);

        return $campaign->fresh();
    }

    // ── R08 ─────────────────────────────────────────────────────────────────

    public function test_r08_tao_thanh_toan_khoa_chien_dich_truoc_khi_tinh_no(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaignWithLine($screen);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = strtolower($q->sql);
        });

        app(PaymentService::class)->createPayment($campaign->fresh(), 'bank_transfer', null, $screen->owner_id);

        $lockIndex = $this->firstIndexMatching(
            $queries,
            fn ($sql) => str_contains($sql, 'from `campaigns`') && str_contains($sql, 'for update'),
        );
        $insertIndex = $this->firstIndexMatching($queries, fn ($sql) => str_starts_with($sql, 'insert into `payments`'));

        $this->assertNotNull($lockIndex, 'Không thấy câu khóa chiến dịch — hai yêu cầu song song vẫn tạo được hai nghĩa vụ cho cùng một khoản nợ.');
        $this->assertNotNull($insertIndex);
        $this->assertLessThan($insertIndex, $lockIndex, 'Khóa phải nằm TRƯỚC phép ghi, nếu không nó chẳng chặn được gì.');
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

    // ── R13 ─────────────────────────────────────────────────────────────────

    public function test_r13_huy_dat_cho_khoa_dong_truoc_khi_doc_trang_thai(): void
    {
        $screen   = $this->screen();
        $campaign = $this->campaignWithLine($screen);
        $line     = $campaign->bookingLines()->firstOrFail();

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = strtolower($q->sql);
        });

        app(CancellationService::class)->cancelLine($line->fresh(), $this->buyer);

        $lockIndex   = $this->firstIndexMatching(
            $queries,
            fn ($sql) => str_contains($sql, 'from `booking_lines`') && str_contains($sql, 'for update'),
        );
        $updateIndex = $this->firstIndexMatching($queries, fn ($sql) => str_starts_with($sql, 'update `booking_lines`'));

        $this->assertNotNull(
            $lockIndex,
            'Không thấy câu khóa dòng đặt chỗ — phép kiểm trạng thái vẫn chạy trên đối tượng người gọi truyền vào, '
            . 'và hai yêu cầu hủy song song sinh hai nghĩa vụ hoàn tiền.'
        );
        $this->assertNotNull($updateIndex);
        $this->assertLessThan($updateIndex, $lockIndex, 'Khóa phải nằm trước phép đổi trạng thái.');
        $this->assertSame(1, \App\Models\Refund::count());
    }

    // ── R15 ─────────────────────────────────────────────────────────────────

    public function test_r15_cho_da_bi_chiem_thi_khong_ghi_them_luot(): void
    {
        $screen = $this->screen();
        $token  = app(DeviceAuthenticator::class)->issueToken($screen);
        $screen->refresh();

        $eventId = (string) Str::ulid();

        // Mô phỏng chính xác điều xảy ra khi một yêu cầu song song đã giành
        // được chỗ nhưng chưa ghi xong bản ghi chính. Không cần canh giờ: sự
        // kiện đã có mặt trong sổ nhận là đủ.
        DB::table('impression_events')->insert([
            'screen_id'  => $screen->id,
            'event_id'   => $eventId,
            'played_at'  => now(),
            'created_at' => now(),
        ]);

        $response = $this->withHeaders(['X-Device-Token' => $token])
            ->postJson('/api/v1/player/impression', [
                'screen_uuid'  => $screen->uuid,
                'event_id'     => $eventId,
                'duration_sec' => 15,
                'played_at'    => now()->toIso8601String(),
            ]);

        $response->assertStatus(202)->assertJsonPath('duplicate', true);

        $this->assertSame(
            0,
            ImpressionLog::count(),
            'Chỗ đã bị chiếm thì không được ghi thêm lượt — cộng thêm ở đây là cộng thêm tiền.'
        );
    }

    public function test_r15_khoa_duy_nhat_o_so_nhan_su_kien_la_that(): void
    {
        $screen = $this->screen();
        $eventId = (string) Str::ulid();

        DB::table('impression_events')->insert([
            'screen_id'  => $screen->id,
            'event_id'   => $eventId,
            'created_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('impression_events')->insert([
            'screen_id'  => $screen->id,
            'event_id'   => $eventId,
            'created_at' => now(),
        ]);
    }
}
