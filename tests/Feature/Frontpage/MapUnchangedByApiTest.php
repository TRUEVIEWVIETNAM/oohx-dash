<?php

namespace Tests\Feature\Frontpage;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Services\FrontpageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * `getMapPins()` gọi KHÔNG khung nhìn phải trả về hết — bất biến giữ nguyên,
 * chỗ đo thì đổi.
 *
 * `/api/v2/screens/map` **bắt buộc** có khung nhìn. Nếu mặc định của hàm trôi
 * về phía API thì mọi bên gọi không-khung-nhìn âm thầm mất pin: không lỗi,
 * không cảnh báo, chỉ là ít màn hình hơn trước.
 *
 * ══ Vì sao đo service chứ không đo trang ══
 *
 * Lớp này từng gọi `GET /map` của Blade. Giai đoạn 7 (07/10/2026) gỡ trang
 * đó — Next.js phục vụ `/map`, và nó lấy dữ liệu qua `/api/v2/screens/pins`.
 *
 * Nhưng bất biến cần canh không nằm ở trang: nó nằm ở **giá trị mặc định của
 * tham số** `$viewport`. Đo thẳng service là đo đúng chỗ nó có thể trôi, và
 * không phụ thuộc vào việc đường dẫn nào đang do bên nào phục vụ.
 *
 * "Tương thích ngược do cách viết" là một lời hứa, không phải một phép kiểm.
 */
class MapUnchangedByApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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

    public function test_khong_khung_nhin_thi_lay_het_pin(): void
    {
        // Hai màn hình cách nhau hơn 1.000 km. Không khung nhìn mặc định nào
        // trùm được cả hai, nên nếu hàm bắt đầu áp một khung nhìn thì một
        // trong hai sẽ biến mất.
        $hanoi = $this->screenAt(21.02, 105.80);
        $hcm   = $this->screenAt(10.77, 106.70);

        $pins = app(FrontpageService::class)->getMapPins(new Request());

        $slugs = $pins->pluck('slug')->all();

        $this->assertContains($hanoi->slug, $slugs, 'Pin Hà Nội biến mất khi gọi không khung nhìn.');
        $this->assertContains($hcm->slug, $slugs, 'Pin TP.HCM biến mất khi gọi không khung nhìn.');
    }

    public function test_goi_tran_khong_can_tham_so_nao(): void
    {
        $this->screenAt(21.02, 105.80);

        // Gọi với một Request rỗng — không khung nhìn, không giới hạn. Nếu hàm
        // dùng chung bắt buộc khung nhìn thì đây là chỗ đổ trước tiên.
        $pins = app(FrontpageService::class)->getMapPins(new Request());

        $this->assertNotEmpty($pins, 'Gọi trần trả về rỗng — mặc định đã trôi về phía API.');
    }
}
