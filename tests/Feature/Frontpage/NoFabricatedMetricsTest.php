<?php

namespace Tests\Feature\Frontpage;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Giai đoạn 4 — trang công khai chỉ nói những gì đọc được từ CSDL.
 *
 * Audit F-15 liệt kê bốn chỗ bịa số: "30M+ impressions", bộ đếm impression
 * chạy bằng `Math.random()` gắn tên một màn hình thật, "AI Match 94% — 12 vị
 * trí đề xuất" cho một tính năng không tồn tại, và badge "Còn trống" in vô
 * điều kiện.
 *
 * Với một sàn đang nộp hồ sơ TMĐT thì đây là rủi ro pháp lý, không phải chuyện
 * câu chữ — nên test canh **cả hai chiều**: số bịa không được quay lại, và số
 * thật phải đúng bằng dữ liệu trong CSDL.
 */
class NoFabricatedMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function url(string $path = '/'): string
    {
        return 'http://' . config('domains.frontpage', 'oohx.net') . $path;
    }

    private function sellableScreen(?Owner $owner = null): Screen
    {
        $owner  = $owner ?: Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'city' => 'Hà Nội', 'status' => 'active']);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenSpec::factory()->create(['screen_id' => $screen->id]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        return $screen->fresh(['inventory', 'site']);
    }

    // ── Số bịa không được quay lại ──────────────────────────────────────────

    public function test_trang_chu_khong_con_so_bia(): void
    {
        $this->sellableScreen();

        $response = $this->get($this->url('/'))->assertOk();

        $response->assertDontSee('30M+', false);
        $response->assertDontSee('AI Match', false);
        $response->assertDontSee('94%', false);
        $response->assertDontSee('Math.random', false);
        $response->assertDontSee('Live Impressions', false);
        $response->assertDontSee('120,847', false);
    }

    public function test_trang_chu_hien_so_that_tu_csdl(): void
    {
        $ownerA = Owner::factory()->create(['status' => 'active']);
        $ownerB = Owner::factory()->create(['status' => 'active']);

        $this->sellableScreen($ownerA);
        $this->sellableScreen($ownerA);
        $this->sellableScreen($ownerB);

        $response = $this->get($this->url('/'))->assertOk();

        // Ba màn hình, hai media owner — đúng những gì vừa dựng.
        $response->assertSee('3 vị trí từ 2 media owner', false);
    }

    // ── Badge còn trống phải là sự thật ─────────────────────────────────────

    /** Bán kín 100% SOV suốt 30 ngày tới. */
    private function sellOut(Screen $screen): void
    {
        $org  = Organization::factory()->create(['status' => 'active']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);

        $campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Đã bán',
            'start_date'      => now()->subDay()->toDateString(),
            'end_date'        => now()->addDays(40)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_ACTIVE,
        ]);

        BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'start_date'         => now()->subDay()->toDateString(),
            'end_date'           => now()->addDays(40)->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 100,
            'estimated_cost'     => 1_000_000,
            'status'             => 'active',
            'pricing_model'      => 'io',
        ]);
    }

    public function test_man_hinh_con_trong_thi_hien_con_trong(): void
    {
        $screen = $this->sellableScreen();

        $this->get($this->url('/explore/' . $screen->slug))
            ->assertOk()
            ->assertSee('Còn trống', false);
    }

    public function test_man_hinh_ban_kin_thi_khong_con_moi_nguoi_mua(): void
    {
        $screen = $this->sellableScreen();
        $this->sellOut($screen);

        $response = $this->get($this->url('/explore/' . $screen->slug))->assertOk();

        $response->assertSee('Đã đầy 30 ngày tới', false);

        // KHÔNG assert vắng hẳn chuỗi "Còn trống": phần chú giải màu của lịch
        // dùng đúng chữ đó làm nhãn ("ô xanh = còn trống"), và đó là nhãn hợp
        // lệ chứ không phải lời hứa về màn hình này. Canh đúng lời hứa: dòng
        // nói còn bao nhiêu phần trăm thời lượng.
        $response->assertDontSee('% thời lượng trong 30 ngày tới', false);
    }

    public function test_ban_mot_nua_thi_noi_dung_con_mot_nua(): void
    {
        $screen = $this->sellableScreen();

        $org  = Organization::factory()->create(['status' => 'active']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        $campaign = Campaign::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'code'            => 'CD-' . uniqid(),
            'name'            => 'Nửa suất',
            'start_date'      => now()->toDateString(),
            'end_date'        => now()->addDays(40)->toDateString(),
            'currency'        => 'VND',
            'status'          => Campaign::STATUS_ACTIVE,
        ]);
        BookingLine::create([
            'campaign_id'        => $campaign->id,
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'start_date'         => now()->toDateString(),
            'end_date'           => now()->addDays(40)->toDateString(),
            'spot_length'        => 15,
            'share_of_voice_pct' => 60,
            'estimated_cost'     => 1_000_000,
            'status'             => 'active',
            'pricing_model'      => 'io',
        ]);

        $this->get($this->url('/explore/' . $screen->slug))
            ->assertOk()
            ->assertSee('Còn 40% thời lượng trong 30 ngày tới', false);
    }

    public function test_ban_do_khong_in_badge_con_trong_vo_dieu_kien(): void
    {
        $screen = $this->sellableScreen();
        $this->sellOut($screen);

        $response = $this->get($this->url('/map'))->assertOk();

        // Cả thẻ trong panel danh sách lẫn popup trên bản đồ đều từng in badge
        // viết cứng. Dữ liệu pin gửi xuống trình duyệt không mang thông tin
        // suất, nên không có gì để kiểm — đã gỡ cả hai.
        $response->assertDontSee('mpop-avail', false);
        $response->assertDontSee('Còn trống', false);
    }

    public function test_the_man_hinh_ban_kin_khong_hien_badge_tren_trang_danh_sach(): void
    {
        $available = $this->sellableScreen();
        $soldOut   = $this->sellableScreen();
        $this->sellOut($soldOut);

        $response = $this->get($this->url('/explore'))->assertOk();

        // Badge là một lời hứa theo từng thẻ, nên đếm số badge phải khớp số
        // màn hình còn suất — không phải cứ có thẻ là có badge.
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'sc-badge'),
            'Chỉ màn hình còn suất mới được gắn badge "Còn trống".'
        );
    }

    public function test_the_man_hinh_khong_hien_badge_khi_nguoi_goi_khong_truyen_du_lieu_suat(): void
    {
        $screen = $this->sellableScreen();

        // Partial được render mà không có mảng suất — fail-closed.
        $html = view('frontpage.partials.screen-card', [
            'screen'      => $screen->load(['spec', 'inventory', 'owner', 'site']),
            'vnCatLabels' => [],
        ])->render();

        $this->assertStringNotContainsString(
            'sc-badge',
            $html,
            'Thiếu dữ liệu thì không nói gì, chứ không mặc định là còn trống.'
        );
    }

    // ── Lời hứa kết quả kinh doanh, và ô điều khiển không làm gì ────────────

    public function test_trang_chu_khong_hua_fill_rate(): void
    {
        $this->sellableScreen();

        $response = $this->get($this->url('/'))->assertOk();

        // "Tăng fill rate lên 40%" là một lời hứa kết quả kinh doanh với media
        // owner. Fill rate cần dữ liệu phát sóng thật để tính, mà chưa có
        // player nào gửi dữ liệu vào `impression_logs` — nên 40% không chỉ
        // thiếu nguồn, nó không tính được bằng bất cứ con số nào khác, kể cả
        // khi muốn thay bằng số thật.
        $response->assertDontSee('fill rate', false);
        $response->assertDontSee('Fill rate', false);
    }

    public function test_danh_sach_owner_va_agency_khong_con_o_sap_xep_chet(): void
    {
        Owner::factory()->count(2)->create(['status' => 'active']);

        foreach (['/owners', '/agency'] as $path) {
            $response = $this->get($this->url($path))->assertOk();

            // Ô `<select>` cũ không có `name`, không nằm trong form, và không
            // JS nào bắt nó — bấm chọn thì thứ tự không đổi.
            //
            // Canh bằng thẻ chứ không bằng chữ trong `<option>`: không layout
            // hay partial nào của trang công khai có `<select>`, nên một thẻ
            // xuất hiện lại ở đây là ô chết quay về, kể cả khi người ta đổi
            // nhãn các lựa chọn.
            $response->assertDontSee('<select', false);
        }
    }
    // ── Nút không có hành vi, và khẳng định truy vấn không đỡ ────────────────

    public function test_trang_chu_khong_con_nut_khong_co_hanh_vi(): void
    {
        $this->sellableScreen();

        $response = $this->get($this->url('/'))->assertOk();

        // Năm `<button>` trần: không `onclick`, không trong form, không JS nào
        // bắt. Canh kèm `</button>` chứ không canh riêng nhãn — "Tìm hiểu thêm"
        // là cụm chữ có thể dùng lại ở chỗ khác một cách hợp lệ, cái sai là nó
        // nằm trong một cái nút không đi đâu.
        $response->assertDontSee('Đăng ký làm chủ sở hữu</button>', false);
        $response->assertDontSee('Tìm hiểu thêm</button>', false);
        $response->assertDontSee('>City Launch</button>', false);
        $response->assertDontSee('>Retail Activation</button>', false);
        $response->assertDontSee('>Event Blitz</button>', false);
    }

    public function test_tieu_de_muc_khong_hua_con_trong(): void
    {
        $screen = $this->sellableScreen();
        $this->sellOut($screen);

        $response = $this->get($this->url('/'))->assertOk();

        // `getFeaturedScreens()` lọc có ảnh + có giá sàn, KHÔNG lọc còn suất —
        // nên một màn hình bán kín vẫn nằm trong mục này. Tiêu đề cũ hứa điều
        // truy vấn không bảo đảm. Badge trên từng thẻ thì vẫn đúng theo suất
        // thật, và test khác đã canh.
        $response->assertDontSee('Đang còn trống</h2>', false);
        $response->assertSee('Vị trí nổi bật', false);
    }

    public function test_trang_owners_khong_khang_dinh_verified(): void
    {
        Owner::factory()->count(2)->create(['status' => 'active']);

        $response = $this->get($this->url('/owners'))->assertOk();

        // `getOwnersPaginated()` lọc `status = 'active'`, không đọc cột
        // `verified`/`verified_at` — nên gọi mọi owner đang hoạt động là "đối
        // tác verified" là một khẳng định về tư cách pháp lý của đối tác mà
        // không ai kiểm. Nặng hơn một con số trang trí.
        $response->assertDontSee('VERIFIED PARTNERS', false);
        $response->assertDontSee('đối tác verified', false);
        $response->assertSee('đối tác đang hoạt động', false);
    }
}
