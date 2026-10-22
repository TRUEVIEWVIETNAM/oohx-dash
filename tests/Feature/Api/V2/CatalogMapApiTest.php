<?php

namespace Tests\Feature\Api\V2;

use App\Models\Network;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Giai đoạn 5, mốc 2 — endpoint bản đồ.
 *
 * Điều khoản đang canh ở đây, từ CLAUDE.md mục 2: **"Endpoint bản đồ bắt buộc
 * có khung nhìn."** Không có khung nhìn thì "lấy pin bản đồ" nghĩa là lấy mọi
 * màn hình có toạ độ — một lần xuất toàn bộ kho dưới cái tên vô hại.
 *
 * Bốn thứ test này canh:
 *
 *  1. Thiếu khung nhìn thì **hỏng ngay**, không chạy được một cách âm thầm.
 *  2. Khung nhìn **lọc thật**, không phải tham số trang trí được nhận rồi bỏ.
 *  3. Giới hạn cứng số pin, và khi bị cắt thì **nói ra** chứ không trả thiếu
 *     trong im lặng.
 *  4. Cắt có thứ tự xác định — kéo bản đồ qua lại không ra tập pin khác nhau.
 */
class CatalogMapApiTest extends TestCase
{
    use RefreshDatabase;

    /** Khung nhìn trùm Hà Nội, dùng cho phần lớn ca test. */
    private const HANOI = 'north=21.2&south=20.9&east=105.9&west=105.6';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function screenAt(float $lat, float $lon, ?Owner $owner = null): Screen
    {
        $owner  = $owner ?: Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => 'Hà Nội',
            'status'   => 'active',
            'lat'      => $lat,
            'lon'      => $lon,
        ]);
        $screen = Screen::factory()->create([
            'owner_id'     => $owner->id,
            'site_id'      => $site->id,
            'active'       => true,
            'device_token' => 'bi-mat-cua-thiet-bi',
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

    // ── Khung nhìn bắt buộc ─────────────────────────────────────────────────

    public function test_thieu_khung_nhin_thi_tu_choi(): void
    {
        $this->screenAt(21.02, 105.80);

        $this->getJson('/api/v2/screens/map')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonPath('code', 422);
    }

    public function test_thieu_mot_canh_cung_bi_tu_choi(): void
    {
        $this->screenAt(21.02, 105.80);

        // Ba cạnh vẫn không thành khung nhìn. Nhận thiếu một cạnh rồi tự đoán
        // là cách âm thầm biến endpoint có giới hạn thành endpoint không.
        foreach (['north', 'south', 'east', 'west'] as $missing) {
            $params = collect(['north' => 21.2, 'south' => 20.9, 'east' => 105.9, 'west' => 105.6])
                ->except($missing)
                ->map(fn ($v, $k) => "{$k}={$v}")
                ->implode('&');

            $response = $this->getJson('/api/v2/screens/map?' . $params);

            $response->assertStatus(422);
            $this->assertContains(
                $missing,
                collect($response->json('details'))->pluck('field')->all(),
                "Thiếu cạnh \"{$missing}\" mà lỗi trả về không nhắc tới nó."
            );
        }
    }

    public function test_khung_nhin_lon_nguoc_thi_tu_choi(): void
    {
        $this->screenAt(21.02, 105.80);

        // north <= south là khung nhìn rỗng. Nhận nó thì API trả 0 pin và
        // trông y hệt "khu vực này không có màn hình nào".
        $this->getJson('/api/v2/screens/map?north=20.9&south=21.2&east=105.9&west=105.6')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');
    }

    public function test_toa_do_ngoai_dai_hop_le_thi_tu_choi(): void
    {
        $this->getJson('/api/v2/screens/map?north=95&south=20.9&east=105.9&west=105.6')
            ->assertStatus(422);
    }

    // ── Khung nhìn lọc thật ─────────────────────────────────────────────────

    public function test_pin_ngoai_khung_nhin_khong_duoc_tra_ve(): void
    {
        $trong  = $this->screenAt(21.02, 105.80);   // Hà Nội
        $ngoai  = $this->screenAt(10.77, 106.70);   // TP.HCM

        $response = $this->getJson('/api/v2/screens/map?' . self::HANOI)->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains($trong->slug, $slugs);
        $this->assertNotContains(
            $ngoai->slug,
            $slugs,
            'Màn hình ngoài khung nhìn vẫn lọt ra — khung nhìn đang là tham số trang trí.'
        );
    }

    public function test_man_hinh_khong_cong_khai_khong_len_ban_do(): void
    {
        $an = $this->screenAt(21.02, 105.80);
        $an->update(['active' => false]);

        $this->getJson('/api/v2/screens/map?' . self::HANOI)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_bo_loc_cua_ban_do_giong_bo_loc_cua_danh_sach(): void
    {
        $ownerA = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-a']);
        $ownerB = Owner::factory()->create(['status' => 'active', 'slug' => 'owner-b']);

        $a = $this->screenAt(21.02, 105.80, $ownerA);
        $b = $this->screenAt(21.03, 105.81, $ownerB);

        $response = $this->getJson('/api/v2/screens/map?' . self::HANOI . '&owner[]=owner-a')->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains($a->slug, $slugs);
        $this->assertNotContains($b->slug, $slugs);
    }

    // ── Giới hạn cứng và nói thật khi bị cắt ────────────────────────────────

    public function test_gioi_han_pin_bi_kep_ve_muc_cung(): void
    {
        $this->screenAt(21.02, 105.80);

        $this->getJson('/api/v2/screens/map?' . self::HANOI . '&limit=10000')
            ->assertOk()
            ->assertJsonPath('meta.limit', 500)
            ->assertJsonPath('meta.max_limit', 500);
    }

    public function test_bi_cat_thi_noi_ra_chu_khong_tra_thieu_trong_im_lang(): void
    {
        $this->screenAt(21.02, 105.80);
        $this->screenAt(21.03, 105.81);
        $this->screenAt(21.04, 105.82);

        $response = $this->getJson('/api/v2/screens/map?' . self::HANOI . '&limit=1')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.returned', 1)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.truncated', true);
    }

    public function test_khong_bi_cat_thi_khong_bao_la_bi_cat(): void
    {
        $this->screenAt(21.02, 105.80);
        $this->screenAt(21.03, 105.81);

        $this->getJson('/api/v2/screens/map?' . self::HANOI)
            ->assertOk()
            ->assertJsonPath('meta.returned', 2)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.truncated', false);
    }

    public function test_tong_so_dem_theo_dung_khung_nhin_dang_hoi(): void
    {
        $this->screenAt(21.02, 105.80);
        $this->screenAt(21.03, 105.81);
        $this->screenAt(10.77, 106.70);   // ngoài khung

        // `meta.total` là tổng TRONG khung nhìn, không phải tổng cả kho. Đếm
        // cả kho rồi bảo "bị cắt" là đẩy client đi thu nhỏ khung nhìn mãi mà
        // không bao giờ hết.
        $this->getJson('/api/v2/screens/map?' . self::HANOI . '&limit=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_cat_co_thu_tu_xac_dinh(): void
    {
        foreach ([[21.02, 105.80], [21.03, 105.81], [21.04, 105.82], [21.05, 105.83]] as [$lat, $lon]) {
            $this->screenAt($lat, $lon);
        }

        $lan1 = $this->getJson('/api/v2/screens/map?' . self::HANOI . '&limit=2')->assertOk();
        $lan2 = $this->getJson('/api/v2/screens/map?' . self::HANOI . '&limit=2')->assertOk();

        $this->assertSame(
            collect($lan1->json('data'))->pluck('slug')->all(),
            collect($lan2->json('data'))->pluck('slug')->all(),
            'Hai lần gọi giống hệt nhau ra hai tập pin khác nhau — pin bị cắt đang không có thứ tự.'
        );
    }

    // ── Danh sách trắng ─────────────────────────────────────────────────────

    public function test_pin_khong_lo_truong_nhay_cam(): void
    {
        $owner = Owner::factory()->create(['status' => 'active', 'revenue_share_pct' => 63.17]);
        $this->screenAt(21.02, 105.80, $owner);

        $response = $this->getJson('/api/v2/screens/map?' . self::HANOI)->assertOk();

        $this->assertStringNotContainsString('device_token', $response->getContent());
        $this->assertStringNotContainsString('revenue_share_pct', $response->getContent());
        $this->assertStringNotContainsString('63.17', $response->getContent());
    }

    public function test_pin_co_toa_do_that(): void
    {
        $this->screenAt(21.0245, 105.8412);

        $this->getJson('/api/v2/screens/map?' . self::HANOI)
            ->assertOk()
            ->assertJsonPath('data.0.lat', 21.0245)
            ->assertJsonPath('data.0.lng', 105.8412);
    }

    public function test_meta_tra_lai_dung_khung_nhin_da_hoi(): void
    {
        $this->screenAt(21.02, 105.80);

        $this->getJson('/api/v2/screens/map?' . self::HANOI)
            ->assertOk()
            ->assertJsonPath('meta.viewport.north', 21.2)
            ->assertJsonPath('meta.viewport.south', 20.9)
            ->assertJsonPath('meta.viewport.east', 105.9)
            ->assertJsonPath('meta.viewport.west', 105.6);
    }
    // ── Ba trường cho trang bản đồ (lát 6) ──────────────────────────────────

    public function test_pin_mang_ten_site_de_gom_duoc_theo_toa_nha(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $site  = Site::factory()->create([
            'owner_id' => $owner->id,
            'name'     => 'Toà Nhà Thử',
            'city'     => 'Hà Nội',
            'status'   => 'active',
            'lat'      => 21.0,
            'lon'      => 105.8,
        ]);

        // Hai màn hình **cùng một site**: trang bản đồ gom chúng thành một
        // marker có badge "2". Không có tên site thì không gom được, và hai
        // pin trùng toạ độ chồng lên nhau thành một chấm.
        foreach ([1, 2] as $i) {
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
                'share_of_voice_max_pct' => 100,
            ]);
        }

        $data = $this->getJson('/api/v2/screens/map?north=22&south=20&east=106&west=105')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);

        foreach ($data as $pin) {
            $this->assertSame('Toà Nhà Thử', $pin['site']['name']);
        }
    }

    public function test_pin_mang_network_va_loai_diem_dat_de_to_mau(): void
    {
        $owner   = Owner::factory()->create(['status' => 'active']);
        $network = Network::factory()->create(['code' => 'NET9', 'name' => 'Mạng Chín']);

        $site = Site::factory()->create([
            'owner_id'   => $owner->id,
            'network_id' => $network->id,
            'city'       => 'Hà Nội',
            'status'     => 'active',
            'lat'        => 21.0,
            'lon'        => 105.8,
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
            'venue_type'             => 'mall',
            'share_of_voice_max_pct' => 100,
        ]);

        $pin = $this->getJson('/api/v2/screens/map?north=22&south=20&east=106&west=105')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('NET9', $pin['network']['code']);
        $this->assertSame('Mạng Chín', $pin['network']['name']);

        // Màu pin đọc trường này. Thiếu nó thì mọi pin một màu.
        $this->assertSame('mall', $pin['venue_type']);
    }

    public function test_pin_khong_co_network_thi_tra_null_chu_khong_vo(): void
    {
        $this->screenAt(21.0, 105.8);

        $pin = $this->getJson('/api/v2/screens/map?north=22&south=20&east=106&west=105')
            ->assertOk()
            ->json('data.0');

        // `SiteFactory` không gán network, nên đây là trường hợp thật chứ
        // không phải dựng riêng: pin phải trả `null`, không ném lỗi.
        $this->assertNull($pin['network']);
        $this->assertArrayHasKey('site', $pin);
    }

    public function test_pin_van_khong_lo_khoa_noi_bo_sau_khi_them_truong(): void
    {
        $this->screenAt(21.0, 105.8);

        $response = $this->getJson('/api/v2/screens/map?north=22&south=20&east=106&west=105')
            ->assertOk();

        // DTO bản đồ cố ý không trả id. Thêm `site` là chỗ dễ nhất để một
        // `site_id` lọt ra cùng, nên canh lại sau khi mở rộng.
        $response->assertDontSee('site_id');
        $response->assertDontSee('"id"');
        $response->assertDontSee('device_token');

        $pin = $response->json('data.0');
        $this->assertSame(['name'], array_keys($pin['site']));
    }

    // ── Loại hình và kích thước trên pin (lát 1, 22/10/2026) ───────────────
    //
    // Trang gộp dựng lại thẻ trong panel **từ pin** sau mỗi lần người dùng bấm
    // "Tìm trong khu vực này". Thẻ in loại hình và kích thước, nên thiếu hai
    // trường này thì danh sách nghèo đi đúng lúc người dùng vừa thao tác — và
    // không có lỗi nào để lần ra.

    public function test_pin_mang_loai_hinh_va_kich_thuoc(): void
    {
        $this->screenAt(21.0, 105.8);

        $pin = $this->getJson('/api/v2/screens/map?' . self::HANOI)
            ->assertOk()
            ->json('data.0');

        // 400cm ≥ ngưỡng 300cm nên là billboard, và nhãn là chữ người đọc —
        // không phải mã `billboard`.
        $this->assertSame('Billboard', $pin['screen_type_label']);

        // Cột lưu xăng-ti-mét, DTO trả mét.
        //
        // `assertEquals` chứ không `assertSame`: `round(400/100, 2)` là float
        // 4.0 trong PHP, nhưng JSON không phân biệt 4.0 với 4 nên nó về đây
        // thành int. So kiểu ở đây là canh một chi tiết của JSON, không phải
        // canh hành vi.
        $this->assertEquals(4, $pin['size']['width_m']);
        $this->assertEquals(2, $pin['size']['height_m']);
    }

    public function test_nhan_va_kich_thuoc_tren_pin_giong_het_trong_danh_sach(): void
    {
        // Ca quan trọng nhất của lát này. Trước 22/10/2026 phép suy loại màn
        // hình chỉ sống trong `ScreenSummaryResource`; chép ngưỡng 300cm sang
        // DTO bản đồ là cách chắc chắn nhất để hai trang nói hai chuyện khác
        // nhau về **cùng một màn hình**, mà không có gì đỏ lên vì mỗi bên tự
        // nhất quán với chính nó.
        //
        // Hai DTO nay dùng chung trait `SuyRaLoaiManHinh`. Ca này canh điều đó
        // từ bên ngoài: cùng một bản ghi, hai endpoint, cùng câu trả lời.
        $screen = $this->screenAt(21.0, 105.8);

        $pin = $this->getJson('/api/v2/screens/map?' . self::HANOI)
            ->assertOk()
            ->json('data.0');

        $the = collect($this->getJson('/api/v2/screens')->assertOk()->json('data'))
            ->firstWhere('slug', $screen->slug);

        $this->assertNotNull($the, 'Màn hình phải có mặt trong cả hai endpoint.');

        $this->assertSame(
            $the['screen_type_label'],
            $pin['screen_type_label'],
            'Nhãn loại hình lệch giữa danh sách và bản đồ — hai phép suy đã trôi khỏi nhau.',
        );

        $this->assertSame($the['size'], $pin['size'], 'Kích thước lệch giữa danh sách và bản đồ.');
    }

    public function test_chua_do_kich_thuoc_thi_tra_null_chu_khong_phai_khong(): void
    {
        // 20/104 bản ghi trên production không có dòng `screen_specs`. `null`
        // nghĩa là **chưa đo**; `0` nghĩa là đã đo và bằng không. Giao diện
        // in "—" cho cái đầu và "0m" cho cái sau, nên trộn hai thứ là nói sai.
        $owner = Owner::factory()->create(['status' => 'active']);
        $site  = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => 'Hà Nội',
            'status'   => 'active',
            'lat'      => 21.0,
            'lon'      => 105.8,
        ]);
        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'share_of_voice_max_pct' => 100,
        ]);

        $pin = $this->getJson('/api/v2/screens/map?' . self::HANOI)
            ->assertOk()
            ->json('data.0');

        $this->assertNull($pin['size']['width_m']);
        $this->assertNull($pin['size']['height_m']);

        // Không có spec thì hai cạnh là 0, nên không phải billboard — nhưng
        // vẫn phải có nhãn, không được rỗng.
        $this->assertSame('LCD', $pin['screen_type_label']);
    }

    // ── Bản đồ của `/explore` dùng chung bộ lọc với danh sách ───────────────
    //
    // Lát 3 đặt một bản đồ cạnh danh sách `/explore`. Nó không có bộ lọc
    // riêng: tham số đến từ URL của trang danh sách, nên hai bên không thể
    // nói hai con số khác nhau về cùng một bộ lọc.
    //
    // Điều đó chỉ đúng nếu endpoint bản đồ thật sự áp `price_status` — tham
    // số thêm ngày 19/10/2026. `MapViewportRequest` kế thừa
    // `FrontpageListingRequest` nên nó *nhận* tham số; nhận mà không lọc là
    // dạng hỏng tệ nhất ở đây, vì bản đồ vẫn trả về một tập pin trông hợp lý.

    /** Màn hình trong khung nhìn Hà Nội, chưa có giá nào. */
    private function manHinhChuaBaoGia(float $lat, float $lon): Screen
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
        ]);

        ScreenInventory::create([
            'screen_id'     => $screen->id,
            'pricing_model' => 'io',
            'io_rate'       => null,
            'floor_cpm'     => null,
            'spot_length'   => 15,
        ]);

        return $screen;
    }

    public function test_ban_do_loc_theo_trang_thai_gia(): void
    {
        $coGia = $this->screenAt(21.02, 105.80);
        $chua  = $this->manHinhChuaBaoGia(21.03, 105.81);

        $slugs = function (string $them): array {
            $data = $this->getJson('/api/v2/screens/map?' . self::HANOI . '&' . $them)
                ->assertOk()
                ->json('data');

            return collect($data)->pluck('slug')->sort()->values()->all();
        };

        $this->assertSame(
            [$coGia->slug],
            $slugs('price_status=quoted'),
            'Bản đồ nhận `price_status` nhưng không lọc theo nó. Danh sách '
                . 'nói một con số, bản đồ vẽ một tập khác, và cả hai trông '
                . 'vẫn hợp lý.',
        );

        $this->assertSame(
            [$chua->slug],
            $slugs('price_status=unquoted'),
        );
    }

    public function test_tong_pin_khop_tong_danh_sach_cua_cung_bo_loc(): void
    {
        $this->screenAt(21.02, 105.80);
        $this->screenAt(21.04, 105.82);
        $this->manHinhChuaBaoGia(21.03, 105.81);

        foreach (['quoted', 'unquoted', 'quoted,unquoted'] as $trangThai) {
            $this->assertSame(
                $this->getJson("/api/v2/screens?price_status={$trangThai}")->json('meta.total'),
                $this->getJson('/api/v2/screens/map?' . self::HANOI . "&price_status={$trangThai}")
                    ->json('meta.total'),
                "Số pin và số kết quả lệch nhau với `price_status={$trangThai}`. "
                    . 'Bản đồ hiện MỌI vị trí khớp bộ lọc, không chỉ trang '
                    . 'hiện tại — nên hai con số này phải bằng nhau khi mọi '
                    . 'vị trí đều nằm trong khung nhìn.',
            );
        }
    }

    public function test_ban_do_tu_choi_trang_thai_gia_la(): void
    {
        $this->screenAt(21.02, 105.80);

        // Cùng một bộ luật với `/screens` vì cùng một FormRequest. Nếu bản đồ
        // lặng lẽ bỏ qua giá trị lạ thì nó trả về cả kho cho một bộ lọc người
        // dùng tưởng đang hẹp.
        $this->getJson('/api/v2/screens/map?' . self::HANOI . '&price_status=mien-phi')
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }
}
