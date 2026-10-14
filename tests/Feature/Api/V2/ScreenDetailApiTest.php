<?php

namespace Tests\Feature\Api\V2;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\InventoryHold;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lát 4 — `GET /api/v2/screens/{slug}` mở rộng và
 * `GET /api/v2/screens/{slug}/availability`.
 *
 * Bốn thứ test này canh:
 *
 *  1. **DTO rộng hơn vẫn là danh sách trắng.** Thêm `photo_urls`, `specs`,
 *     `site`, `insights`, POI và `similar` là thêm bề mặt; mỗi trường thêm là
 *     một chỗ `device_token` hay `revenue_share_pct` có thể lọt ra.
 *  2. **Lịch trống trừ cả suất đang có người giữ.** Lịch của trang Blade tự
 *     cộng `BookingLine` lấy và bỏ qua `InventoryHold`, nên nó sơn xanh ngày
 *     người khác đang thanh toán. Đây là ca chống hồi quy cho đúng chuyện đó.
 *  3. **`months` có trần, và quá trần là 422** — không phải lặng lẽ cắt.
 *  4. **`insights` là `null` khi không có dữ liệu**, không phải một đối tượng
 *     toàn trường rỗng.
 */
class ScreenDetailApiTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_KEYS = [
        'revenue_share_pct', 'billing_info', 'bank_name', 'bank_account_number',
        'bank_account_name', 'bank_branch', 'tax_code', 'business_license_path',
        'device_token', 'internal_notes', 'price_per_slot_vnd', 'vn_category_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function screen(array $screenAttributes = [], array $specAttributes = []): Screen
    {
        $owner = Owner::factory()->create([
            'status'            => 'active',
            'revenue_share_pct' => 63.17,
            'phone'             => '0900000001',
            'email'             => 'lien-he@owner.test',
            'verified'          => true,
        ]);

        $site = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => 'Hà Nội',
            'address'  => 'Số 1 Đường Thử',
            'status'   => 'active',
            'lat'      => 21.0278,
            'lon'      => 105.8342,
        ]);

        $screen = Screen::factory()->create(array_merge([
            'owner_id'     => $owner->id,
            'site_id'      => $site->id,
            'active'       => true,
            'device_token' => 'bi-mat-cua-thiet-bi',
            'description'  => 'Màn hình thử ở sảnh.',
        ], $screenAttributes));

        ScreenSpec::factory()->create(array_merge([
            'screen_id'   => $screen->id,
            'width_cm'    => 400,
            'height_cm'   => 200,
            'width_px'    => 1920,
            'height_px'   => 1080,
            'allow_image' => true,
            'allow_video' => true,
        ], $specAttributes));

        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'floor_cpm'              => 50_000,
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        return $screen->fresh(['inventory', 'spec', 'site', 'owner']);
    }

/**
     * Một dòng đặt chỗ đang sống trên màn hình này.
     *
     * Repo không có factory cho `Campaign`/`BookingLine`, nên dựng bằng
     * `::create()` — đúng cách `BookingApiTest` làm.
     */
    private function bookingLine(Screen $screen, string $from, string $to, int $sovPct, string $status = 'approved'): BookingLine
    {
        $org  = Organization::factory()->create();
        // `campaigns.created_by` không có giá trị mặc định, nên phải có người
        // tạo thật — một chiến dịch không ai tạo là một hàng không truy được.
        $user = User::factory()->create();

        $campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'code'            => 'CPN-' . Str::random(8),
            'name'            => 'Chiến dịch thử',
            'start_date'      => $from,
            'end_date'        => $to,
            'status'          => Campaign::STATUS_ACTIVE,
        ]);

        return BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'start_date'         => $from,
            'end_date'           => $to,
            'spot_length'        => 15,
            'share_of_voice_pct' => $sovPct,
            'estimated_cost'     => 1_000_000,
            'status'             => $status,
            'pricing_model'      => 'io',
        ]);
    }

    /** Quét đệ quy mọi khóa — không chỉ tầng ngoài. */
    private function assertNoForbiddenKeys(array $payload, string $endpoint): void
    {
        $walk = function ($node, string $path) use (&$walk, $endpoint) {
            if (! is_array($node)) {
                return;
            }

            foreach ($node as $key => $value) {
                if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                    $this->fail("{$endpoint} trả về trường nhạy cảm \"{$key}\" tại {$path}.{$key}");
                }

                $walk($value, is_string($key) ? "{$path}.{$key}" : $path . '[]');
            }
        };

        $walk($payload, '$');
    }

    // ── DTO chi tiết ────────────────────────────────────────────────────────

    public function test_chi_tiet_co_cac_khoi_cua_lat_4(): void
    {
        $screen = $this->screen();

        $data = $this->getJson('/api/v2/screens/' . $screen->slug)->assertOk()->json('data');

        foreach (['photo_urls', 'specs', 'site', 'owner', 'insights', 'nearby_pois', 'similar', 'availability'] as $khoa) {
            $this->assertArrayHasKey($khoa, $data, "Thiếu khối `{$khoa}` trong chi tiết màn hình.");
        }

        $this->assertSame(1920, $data['specs']['width_px']);
        $this->assertSame(1080, $data['specs']['height_px']);
        $this->assertSame(15, $data['specs']['spot_length_s']);
        $this->assertTrue($data['specs']['allow_video']);
        $this->assertSame(100, $data['specs']['sov_max_pct']);
        $this->assertSame('Màn hình thử ở sảnh.', $data['description']);
    }

    public function test_chi_tiet_mo_rong_van_khong_lo_truong_nhay_cam(): void
    {
        $screen = $this->screen([
            'daily_footfall'   => 12_000,
            'placement_zone'   => 'lobby',
            'audience_profile' => ['male_pct' => 55, 'female_pct' => 45, 'bi_mat_noi_bo' => 'khong-duoc-ra'],
        ]);

        $response = $this->getJson('/api/v2/screens/' . $screen->slug)->assertOk();

        $this->assertNoForbiddenKeys($response->json(), 'GET /api/v2/screens/{slug}');

        // Cột JSON là tự do, nên DTO phải lọc theo khóa — không phải trả cả
        // blob. Một khóa lạ trong `audience_profile` không được ra ngoài.
        $response->assertDontSee('bi_mat_noi_bo');
    }

    public function test_toa_do_site_ra_ngoai_de_dung_khoi_ban_do(): void
    {
        $screen = $this->screen();

        $data = $this->getJson('/api/v2/screens/' . $screen->slug)->assertOk()->json('data');

        $this->assertSame(21.0278, $data['site']['lat']);
        // `lng`, không phải `lon`: cùng tên với `MapPin` để một client không
        // phải nhớ hai tên cho cùng một thứ.
        $this->assertSame(105.8342, $data['site']['lng']);
        $this->assertSame('Số 1 Đường Thử', $data['site']['address']);
    }

    public function test_owner_co_duong_lien_he_vi_san_khong_thu_ho(): void
    {
        $screen = $this->screen();

        $data = $this->getJson('/api/v2/screens/' . $screen->slug)->assertOk()->json('data');

        $this->assertSame('0900000001', $data['owner']['phone']);
        $this->assertSame('lien-he@owner.test', $data['owner']['email']);
        $this->assertTrue($data['owner']['verified']);
    }

    public function test_thu_vien_anh_tra_url_dung_duoc_khong_tra_duong_dan_trong_disk(): void
    {
        $screen = $this->screen([], ['photos' => ['screens/mot.jpg', 'screens/hai.jpg']]);

        $data = $this->getJson('/api/v2/screens/' . $screen->slug)->assertOk()->json('data');

        $this->assertCount(2, $data['photo_urls']);

        foreach ($data['photo_urls'] as $url) {
            // Cùng lỗi đã sửa ở lát 0 cho ảnh bìa owner: một đường dẫn trong
            // disk được trình duyệt giải tương đối với trang đang mở.
            $this->assertStringStartsWith('http', $url, 'Ảnh phải là URL đầy đủ.');
            $this->assertStringContainsString('/storage/', $url);
        }
    }

    public function test_khong_co_anh_thi_tra_mang_rong_khong_tra_dich_vu_ngoai(): void
    {
        $screen = $this->screen([], ['photos' => [], 'photo_url' => null]);

        $data = $this->getJson('/api/v2/screens/' . $screen->slug)->assertOk()->json('data');

        // Bản Blade chèn `placehold.co`. Một URL ảnh của dịch vụ thứ ba trong
        // payload API là bắt mọi client tin một dịch vụ không ai khai.
        $this->assertIsArray($data['photo_urls']);
        $this->assertSame([], array_filter($data['photo_urls'], fn ($u) => str_contains((string) $u, 'placehold')));
    }

    // ── Insights ────────────────────────────────────────────────────────────

    public function test_insights_la_null_khi_khong_co_du_lieu_nao(): void
    {
        $screen = $this->screen();

        $data = $this->getJson('/api/v2/screens/' . $screen->slug)->assertOk()->json('data');

        // `null`, không phải đối tượng toàn trường rỗng: trang ẩn hẳn tab khi
        // không có gì, và một đối tượng rỗng buộc mỗi client tự định nghĩa
        // lại "rỗng là thế nào".
        $this->assertNull($data['insights']);
    }

    public function test_insights_tra_du_lieu_owner_khai_va_noi_ro_nguon(): void
    {
        $screen = $this->screen([
            'daily_footfall'           => 10_000,
            'monthly_reach'            => 250_000,
            'placement_zone'           => 'escalator',
            'audience_profile'         => ['male_pct' => 52, 'income_tier' => 'mid', 'lifestyle_tags' => ['sinh viên']],
            'time_performance'         => ['best_day' => 'sat', 'morning_pct' => 20],
            'nearby_context'           => ['brands' => ['Highlands'], 'highlights' => 'Cạnh ga metro'],
            'traffic_methodology_note' => 'Đếm thủ công 3 ngày.',
        ]);

        $insights = $this->getJson('/api/v2/screens/' . $screen->slug)->assertOk()->json('data.insights');

        $this->assertSame(10_000, $insights['daily_footfall']);
        $this->assertSame(250_000, $insights['monthly_reach']);
        $this->assertSame('escalator', $insights['placement_zone']);
        $this->assertSame('Cạnh thang cuốn', $insights['placement_label']);

        // Ước tính, và tên trường nói ra điều đó: 10.000 × 0,6.
        $this->assertSame(6_000, $insights['daily_impressions_est']);

        $this->assertSame(52, $insights['audience_profile']['male_pct']);
        $this->assertSame('sat', $insights['time_performance']['best_day']);
        $this->assertSame(['Highlands'], $insights['nearby_context']['brands']);
        $this->assertSame('Đếm thủ công 3 ngày.', $insights['methodology_note']);
        $this->assertSame('owner', $insights['source']);
    }

    // ── Màn hình tương tự ───────────────────────────────────────────────────

    public function test_man_hinh_tuong_tu_dung_dto_cua_danh_sach(): void
    {
        $screen = $this->screen();
        $this->screen(); // một màn hình khác cùng thành phố

        $similar = $this->getJson('/api/v2/screens/' . $screen->slug)->assertOk()->json('data.similar');

        $this->assertNotEmpty($similar, 'Phải có màn hình tương tự khi cùng thành phố có màn hình khác.');
        $this->assertArrayHasKey('slug', $similar[0]);
        $this->assertArrayHasKey('pricing', $similar[0]);

        // Không tự trỏ về chính nó — một mục "tương tự" dẫn về trang đang mở
        // là một liên kết chết đối với người đọc.
        foreach ($similar as $item) {
            $this->assertNotSame($screen->slug, $item['slug']);
        }
    }

    // ── Lịch trống theo ngày ────────────────────────────────────────────────

    public function test_lich_trong_tra_mot_phan_tu_moi_ngay(): void
    {
        $screen = $this->screen();

        $data = $this->getJson('/api/v2/screens/' . $screen->slug . '/availability?months=1')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $data['months']);
        $this->assertSame(100, $data['sov_max_pct']);
        $this->assertSame(now()->toDateString(), $data['from']);

        $songay = (int) now()->startOfDay()->diffInDays(now()->addMonth()->endOfMonth()->startOfDay()) + 1;
        $this->assertCount($songay, $data['days'], 'Phải có đúng một phần tử cho mỗi ngày trong khoảng.');

        foreach ($data['days'] as $ngay) {
            $this->assertSame(100, $ngay['remaining_sov_pct']);
        }
    }

    public function test_lich_trong_tru_dong_dat_cho_dang_song(): void
    {
        $screen = $this->screen();

        $this->bookingLine($screen, now()->addDays(3)->toDateString(), now()->addDays(5)->toDateString(), 40);

        $days = collect(
            $this->getJson('/api/v2/screens/' . $screen->slug . '/availability?months=1')
                ->assertOk()
                ->json('data.days')
        )->keyBy('date');

        $this->assertSame(100, $days[now()->addDays(2)->toDateString()]['remaining_sov_pct']);
        $this->assertSame(60, $days[now()->addDays(3)->toDateString()]['remaining_sov_pct']);
        $this->assertSame(60, $days[now()->addDays(5)->toDateString()]['remaining_sov_pct']);
        $this->assertSame(100, $days[now()->addDays(6)->toDateString()]['remaining_sov_pct']);
    }

    /**
     * Ca chống hồi quy cho đúng lỗi của lịch bản Blade.
     *
     * Lịch cũ cộng `BookingLine` có `status` trong `['approved','active']` và
     * **không biết gì về `InventoryHold`**, nên một ngày đang có người giữ
     * trong giỏ vẫn hiện xanh — người thứ hai chỉ biết mình mất suất ở bước
     * cuối.
     */
    public function test_lich_trong_tru_ca_suat_dang_co_nguoi_giu_trong_gio(): void
    {
        $screen = $this->screen();

        InventoryHold::create([
            'screen_id'  => $screen->id,
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date'   => now()->addDays(12)->toDateString(),
            'sov_pct'    => 30,
            'status'     => InventoryHold::STATUS_ACTIVE,
            'expires_at' => now()->addHour(),
        ]);

        $days = collect(
            $this->getJson('/api/v2/screens/' . $screen->slug . '/availability?months=1')
                ->assertOk()
                ->json('data.days')
        )->keyBy('date');

        $this->assertSame(70, $days[now()->addDays(10)->toDateString()]['remaining_sov_pct']);
        $this->assertSame(70, $days[now()->addDays(11)->toDateString()]['remaining_sov_pct']);
        $this->assertSame(100, $days[now()->addDays(13)->toDateString()]['remaining_sov_pct']);
    }

    public function test_lich_trong_tu_choi_so_thang_qua_tran(): void
    {
        $screen = $this->screen();

        // 422, không phải lặng lẽ cắt về 3: client phải biết nó không nhận
        // được điều đã xin.
        $this->getJson('/api/v2/screens/' . $screen->slug . '/availability?months=99')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_lich_trong_mac_dinh_hai_thang_nhu_ban_blade(): void
    {
        $screen = $this->screen();

        $this->getJson('/api/v2/screens/' . $screen->slug . '/availability')
            ->assertOk()
            ->assertJsonPath('data.months', 2);
    }

    public function test_lich_trong_cua_slug_khong_ton_tai_tra_dinh_dang_loi_thong_nhat(): void
    {
        $this->getJson('/api/v2/screens/khong-ton-tai/availability')
            ->assertStatus(404)
            ->assertJson(['error' => 'not_found', 'code' => 404, 'details' => []]);
    }

    public function test_lich_trong_khong_bi_khop_vao_duong_chi_tiet(): void
    {
        $screen = $this->screen();

        // Hai đoạn đường nên `{slug}` một đoạn không thể ăn vào — nhưng nếu ai
        // đổi route thành `{slug}/{phan?}` thì ca này đỏ.
        $this->getJson('/api/v2/screens/' . $screen->slug . '/availability')
            ->assertOk()
            ->assertJsonStructure(['data' => ['from', 'to', 'months', 'sov_max_pct', 'days']]);
    }
}
