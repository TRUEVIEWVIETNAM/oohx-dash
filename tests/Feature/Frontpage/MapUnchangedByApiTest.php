<?php

namespace Tests\Feature\Frontpage;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Chống hồi quy cho trang bản đồ Blade sau khi `getMapPins()` nhận thêm tham
 * số khung nhìn và giới hạn.
 *
 * `/api/v2/screens/map` **bắt buộc** có khung nhìn; trang Blade thì **không**
 * và phải giữ nguyên hành vi cũ. Hai yêu cầu trái nhau dùng chung một hàm, nên
 * nếu mặc định của hàm trôi về phía API thì trang bản đồ đang chạy sẽ âm thầm
 * mất pin — không lỗi, không cảnh báo, chỉ là ít màn hình hơn trước.
 *
 * Trước đây không có test nào che trang này. "Tương thích ngược do cách viết"
 * là một lời hứa, không phải một phép kiểm.
 */
class MapUnchangedByApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** Route trang công khai bị khoá theo domain, gọi đường dẫn trần sẽ 404. */
    private function url(string $path = '/'): string
    {
        return 'http://' . config('domains.frontpage', 'oohx.net') . $path;
    }

    private function screenAt(float $lat, float $lon): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => 'Hà Nội',
            'status'   => 'active',
            'lat'      => $lat,
            'lon'      => $lon,
        ]);
        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenSpec::factory()->create(['screen_id' => $screen->id, 'width_cm' => 400, 'height_cm' => 200]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'floor_cpm'              => 50_000,
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        return $screen->fresh(['inventory', 'spec', 'site']);
    }

    public function test_trang_ban_do_khong_doi_khung_nhin_va_van_lay_het_pin(): void
    {
        // Hai màn hình cách nhau hơn 1.000 km. Không khung nhìn mặc định nào
        // trùm được cả hai, nên nếu trang bắt đầu áp khung nhìn thì một trong
        // hai sẽ biến mất.
        $hanoi = $this->screenAt(21.02, 105.80);
        $hcm   = $this->screenAt(10.77, 106.70);

        $response = $this->get($this->url('/map'))->assertOk();

        $response->assertSee($hanoi->slug, false);
        $response->assertSee($hcm->slug, false);
    }

    public function test_trang_ban_do_khong_can_tham_so_nao(): void
    {
        $this->screenAt(21.02, 105.80);

        // Gọi trần, đúng như người dùng bấm vào menu. Nếu hàm dùng chung bắt
        // buộc khung nhìn thì đây là chỗ đổ trước tiên.
        $this->get($this->url('/map'))->assertOk();
    }
}
