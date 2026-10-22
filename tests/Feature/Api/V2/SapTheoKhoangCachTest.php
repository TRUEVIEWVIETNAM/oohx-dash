<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * `GET /api/v2/screens?sort=distance` — sắp theo khoảng cách tới một toạ độ.
 *
 * ══ Vì sao tính năng này tồn tại ══
 *
 * Lát định vị (22/10/2026) kéo bản đồ tới chỗ người dùng, nhưng **danh sách
 * bên cạnh vẫn là toàn kho**: bản đồ ở Hà Nội với 13 vị trí quanh người dùng,
 * còn thẻ đầu tiên trong danh sách ở Thái Nguyên. Hai tập dữ liệu cạnh nhau
 * trên một màn hình, và người mua không có cách nào biết vì sao.
 *
 * ══ Toạ độ KHÔNG đi vào URL trang ══
 *
 * Hai tham số này là vị trí của một người. Chúng đi trong một yêu cầu API
 * cùng nguồn gốc, do trình duyệt gọi sau khi người dùng cho phép — không nằm
 * trong URL của `/explore`, vì URL rơi vào log máy chủ, header `Referer` và
 * nút chia sẻ. Trang do máy chủ dựng vẫn là toàn kho.
 *
 * ══ Ba cái bẫy mà bộ ca này canh ══
 *
 *  1. **`NULL` xếp đầu trong MySQL.** Màn hình chưa có toạ độ sẽ đứng **trên
 *     cùng** của một danh sách "gần tôi nhất" nếu không có vế
 *     `ORDER BY (lat IS NULL)` đứng trước.
 *  2. **`ACOS` của số lớn hơn 1 trả `NULL`.** Sai số dấu phẩy động khi hai
 *     điểm trùng nhau đẩy tích cosin vượt 1, nên màn hình ở **đúng chỗ người
 *     dùng đứng** rơi xuống cuối — đúng cái nó phải đứng đầu.
 *  3. **`sort=distance` thiếu toạ độ.** Lặng lẽ rơi về thứ tự mặc định là
 *     trả một danh sách sai thứ tự mà trông bình thường.
 */
class SapTheoKhoangCachTest extends TestCase
{
    use RefreshDatabase;

    /** Hồ Gươm, Hà Nội. */
    private const TOI_LAT = 21.0287;
    private const TOI_LNG = 105.8524;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function manHinh(string $ten, ?float $lat, ?float $lon): Screen
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $site  = Site::factory()->create([
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
            'name'     => $ten,
        ]);

        ScreenSpec::factory()->create(['screen_id' => $screen->id, 'width_cm' => 400, 'height_cm' => 200]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        return $screen->fresh(['inventory', 'spec', 'site']);
    }

    private function gan(array $them = []): \Illuminate\Testing\TestResponse
    {
        $q = http_build_query(array_merge([
            'sort'     => 'distance',
            'near_lat' => self::TOI_LAT,
            'near_lng' => self::TOI_LNG,
            'per_page' => 50,
        ], $them));

        return $this->getJson("/api/v2/screens?{$q}");
    }

    // ── Thứ tự ──────────────────────────────────────────────────────────────

    public function test_gan_nhat_dung_dau_xa_nhat_dung_cuoi(): void
    {
        // ~1km, ~9km, ~1.100km tính từ Hồ Gươm.
        $this->manHinh('xa-nhat', 10.7769, 106.7009);   // TP.HCM
        $this->manHinh('gan-nhat', 21.0245, 105.8412);  // Hoàn Kiếm
        $this->manHinh('giua', 21.0045, 105.7850);      // Thanh Xuân

        $ten = collect($this->gan()->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['gan-nhat', 'giua', 'xa-nhat'], $ten);
    }

    public function test_man_hinh_chua_co_toa_do_xuong_CUOI_chu_khong_len_dau(): void
    {
        // Bẫy số 1: trong MySQL `NULL` xếp ĐẦU ở thứ tự tăng dần. Không có vế
        // `ORDER BY (lat IS NULL)` thì danh sách "gần tôi nhất" mở đầu bằng
        // những màn hình không ai biết ở đâu.
        $this->manHinh('khong-toa-do', null, null);
        $this->manHinh('co-toa-do-xa', 10.7769, 106.7009);
        $this->manHinh('co-toa-do-gan', 21.0245, 105.8412);

        $ten = collect($this->gan()->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['co-toa-do-gan', 'co-toa-do-xa', 'khong-toa-do'], $ten);
    }

    public function test_man_hinh_o_DUNG_cho_nguoi_dung_dung_khong_bi_ACOS_day_xuong_cuoi(): void
    {
        // Bẫy số 2: `ACOS` của một số lớn hơn 1 trả `NULL` trong MySQL, và
        // tích các cosin vượt 1 vì sai số dấu phẩy động khi hai điểm TRÙNG
        // nhau. Không kẹp bằng `LEAST(1, …)` thì màn hình gần nhất có thể có
        // lại xếp cuối cùng.
        $this->manHinh('trung-cho', self::TOI_LAT, self::TOI_LNG);
        $this->manHinh('cach-mot-chut', 21.0245, 105.8412);

        $data = $this->gan()->assertOk()->json('data');

        $this->assertSame('trung-cho', $data[0]['name']);
        $this->assertNotNull($data[0]['distance_km'], 'Khoảng cách tới chính chỗ đang đứng ra `null`.');
        $this->assertSame(0.0, (float) $data[0]['distance_km']);
    }

    // ── Trường `distance_km` ────────────────────────────────────────────────

    public function test_tra_ve_khoang_cach_dung_so_km(): void
    {
        // Hoàn Kiếm → Hồ Gươm: dưới 2km. Không chốt con số chính xác tới số
        // lẻ — công thức Haversine và bán kính Trái Đất là phép xấp xỉ, và
        // chốt một con số cứng ở đây là chốt phép xấp xỉ chứ không chốt hành
        // vi.
        $this->manHinh('gan', 21.0245, 105.8412);

        $km = (float) $this->gan()->assertOk()->json('data.0.distance_km');

        $this->assertGreaterThan(0, $km);
        $this->assertLessThan(2, $km, "Khoảng cách {$km}km — quá xa cho hai điểm cách nhau ~1km.");
    }

    public function test_khong_sap_theo_khoang_cach_thi_KHONG_co_truong_do(): void
    {
        // Trả `null` ở mọi phản hồi danh sách là thêm một trường rỗng vào
        // hàng trăm bản ghi mỗi lượt, cho một thứ gần như không bao giờ dùng.
        $this->manHinh('mot', 21.0245, 105.8412);

        $data = $this->getJson('/api/v2/screens')->assertOk()->json('data');

        $this->assertArrayNotHasKey('distance_km', $data[0]);
    }

    // ── Từ chối khi thiếu toạ độ ────────────────────────────────────────────

    public function test_sap_theo_khoang_cach_ma_thieu_toa_do_thi_tra_422(): void
    {
        // Bẫy số 3. Lặng lẽ rơi về thứ tự mặc định là trả một danh sách sai
        // thứ tự mà trông bình thường — bên gọi tin rằng nó đã được sắp.
        $this->manHinh('mot', 21.0245, 105.8412);

        $this->getJson('/api/v2/screens?sort=distance')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        $this->getJson('/api/v2/screens?sort=distance&near_lat=' . self::TOI_LAT)
            ->assertStatus(422);
    }

    public function test_mot_toa_do_nua_voi_bi_tu_choi_du_khong_sap_theo_khoang_cach(): void
    {
        // Chỉ `near_lat`, không `near_lng`, là dấu hiệu bên gọi đang dựng URL
        // sai — nhận nó rồi bỏ qua là giấu một lỗi lập trình.
        $this->manHinh('mot', 21.0245, 105.8412);

        $this->getJson('/api/v2/screens?near_lat=' . self::TOI_LAT)->assertStatus(422);
        $this->getJson('/api/v2/screens?near_lng=' . self::TOI_LNG)->assertStatus(422);
    }

    public function test_toa_do_ngoai_dai_bi_tu_choi(): void
    {
        $this->manHinh('mot', 21.0245, 105.8412);

        $this->gan(['near_lat' => 91])->assertStatus(422);
        $this->gan(['near_lng' => 181])->assertStatus(422);
    }

    // ── Vẫn là endpoint cũ, với mọi ràng buộc cũ ────────────────────────────

    public function test_bo_loc_van_chay_cung_voi_sap_theo_khoang_cach(): void
    {
        // Sắp xếp không được nuốt bộ lọc: một danh sách "gần tôi nhất" bỏ qua
        // bộ lọc đang bật là đúng cái lỗi mà dòng chữ "n vị trí quanh bạn" đã
        // mắc ở bản đầu của lát định vị.
        $gan = $this->manHinh('gan', 21.0245, 105.8412);
        $this->manHinh('xa', 10.7769, 106.7009);

        $data = $this->gan(['q' => 'gan'])->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($gan->slug, $data[0]['slug']);
    }

    public function test_van_phan_trang_va_van_chan_tran_per_page(): void
    {
        $this->manHinh('mot', 21.0245, 105.8412);
        $this->manHinh('hai', 21.0045, 105.7850);

        $meta = $this->gan(['per_page' => 999])->assertOk()->json('meta');

        $this->assertSame(50, $meta['per_page'], 'Trần `per_page` bị bỏ khi sắp theo khoảng cách.');
        $this->assertSame(2, $meta['total']);
    }
}
