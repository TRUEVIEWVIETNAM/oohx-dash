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

    // ══ Hai ca GIỮ, chuyển sang API ═══════════════════════════════════════
    //
    // Chúng là phần có sức nặng nhất của lớp này: chúng chứng minh con số suất
    // còn lại PHẢN ÁNH THỰC TẾ, không phải một hằng số.
    //
    // `CatalogApiTest::test_chi_tiet_tra_suat_con_lai_that` chỉ đo màn hình
    // TRỐNG — nó xanh cả khi endpoint trả cứng 100%. Hai ca dưới mới phân
    // biệt được "đo thật" với "in sẵn".

    public function test_man_hinh_ban_kin_thi_khong_con_suat(): void
    {
        $screen = $this->sellableScreen();
        $this->sellOut($screen);

        $this->getJson($this->url('/api/v2/screens/' . $screen->slug))
            ->assertOk()
            ->assertJsonPath('data.availability.has_capacity', false)
            ->assertJsonPath('data.availability.remaining_sov_pct', 0);
    }

    public function test_ban_mot_nua_thi_suat_con_mot_nua(): void
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

        // Bán 60% thì còn 40%. Một endpoint in sẵn sẽ trả 100 hoặc 0.
        $this->getJson($this->url('/api/v2/screens/' . $screen->slug))
            ->assertOk()
            ->assertJsonPath('data.availability.remaining_sov_pct', 40)
            ->assertJsonPath('data.availability.has_capacity', true);
    }

    // ══ Mười một ca GỠ, và đây là KHOẢNG TRỐNG THẬT ══════════════════════
    //
    // Chúng đo chữ và thẻ trên trang Blade, và giai đoạn 7 (07/10/2026) gỡ
    // những trang đó:
    //
    //   trang chủ không còn số bịa / hiện số thật từ CSDL
    //   trang chủ không hứa fill rate
    //   trang chủ không còn nút không có hành vi
    //   tiêu đề mục không hứa còn trống
    //   màn hình còn trống thì hiện còn trống
    //   bản đồ không in badge "còn trống" vô điều kiện
    //   thẻ màn hình bán kín không hiện badge trên trang danh sách
    //   thẻ màn hình không hiện badge khi người gọi không truyền dữ liệu suất
    //   danh sách owner và agency không còn ô sắp xếp chết
    //   trang owners không khẳng định "verified"
    //
    // ══ Nói thẳng: chúng KHÔNG có bản thay ══
    //
    // Phần DỮ LIỆU của chúng còn được canh — hai ca ở trên, cộng
    // `CatalogApiTest` và `HomeCatalogApiTest`: API không trả số bịa, và
    // `/api/v2/stats` lấy số từ CSDL.
    //
    // Phần HIỂN THỊ thì không. `webapp/test/seo.mjs` canh thẻ SEO, JSON-LD và
    // khung trang — nó KHÔNG canh "trang chủ có hứa một con số không có
    // nguồn hay không", cũng không canh "badge còn trống chỉ hiện khi thật sự
    // còn trống".
    //
    // Đó là một khoảng trống mở ra bởi lát này, không phải một thứ đã được
    // phủ ở chỗ khác. Ghi ra đây thay vì xoá lặng, vì audit F-15 sinh ra
    // chính từ loại lỗi này và không ai muốn tìm lại nó lần thứ hai.

}
