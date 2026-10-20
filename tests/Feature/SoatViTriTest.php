<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use App\Models\VietnamProvince;
use App\Services\SoatViTriService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Soát dữ liệu vị trí (20/10/2026).
 *
 * ══ Thứ bộ chốt này canh, và vì sao ══
 *
 * Nửa đầu canh **phép đo bắt được lỗi**. Nửa sau — quan trọng không kém —
 * canh **phép đo im lặng khi không có lỗi**.
 *
 * Nửa sau tồn tại vì lần soát đầu tiên đã thử một phép đo nghe rất hợp lý
 * ("tên màn hình không có token nào chung với điểm đặt / địa chỉ / tỉnh") và
 * nó cho 15 cảnh báo trên kho thật, trong đó **14 là báo sai**: một media
 * owner đặt tên bằng chữ viết tắt. Phép đo đó không được ship, và
 * `test_ten_viet_tat_khong_bi_bao_sai` đóng băng quyết định ấy — ai thêm lại
 * một phép đo kiểu đó sẽ thấy ca này đỏ.
 */
class SoatViTriTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->owner = Owner::factory()->create(['status' => 'active']);
    }

    /** Một màn hình gắn vào một điểm đặt với đúng các cột cần cho ca test. */
    private function manHinh(string $tenManHinh, array $site, array $them = []): Screen
    {
        $diaDiem = Site::factory()->create(array_merge([
            'owner_id' => $this->owner->id,
            'status'   => 'active',
        ], $site));

        return Screen::factory()->create(array_merge([
            'owner_id' => $this->owner->id,
            'site_id'  => $diaDiem->id,
            'active'   => true,
            'name'     => $tenManHinh,
        ], $them));
    }

    /** @return array<int, array<string, mixed>> */
    private function soat(): array
    {
        return app(SoatViTriService::class)->soat()['loi'];
    }

    /** @return array<int, array<string, mixed>> */
    private function loaiLoi(string $loai): array
    {
        return array_values(array_filter($this->soat(), fn (array $l) => $l['loai'] === $loai));
    }

    // ── Phép đo bắt được lỗi ─────────────────────────────────────────────────

    public function test_thieu_toa_do(): void
    {
        $this->manHinh('Màn hình không có pin', ['lat' => null, 'lon' => null, 'city' => 'Hà Nội']);

        $loi = $this->loaiLoi('thieu_toa_do');

        $this->assertCount(1, $loi);
        $this->assertSame('Màn hình không có pin', $loi[0]['screen_name']);
        $this->assertSame(SoatViTriService::MUC_CAO, $loi[0]['muc']);
    }

    public function test_mot_toa_do_thieu_mot_nua_cung_bi_bat(): void
    {
        // `lat` có mà `lon` null thì pin vẫn không vẽ được. Một phép kiểm chỉ
        // hỏi "cả hai đều null?" sẽ bỏ qua đúng hàng này.
        $this->manHinh('Một nửa toạ độ', ['lat' => 21.02, 'lon' => null, 'city' => 'Hà Nội']);

        $this->assertCount(1, $this->loaiLoi('thieu_toa_do'));
    }

    public function test_toa_do_ngoai_viet_nam(): void
    {
        $this->manHinh('Pin ở Bangkok', ['lat' => 13.75, 'lon' => 100.5, 'city' => 'Hà Nội']);
        $this->manHinh('Pin ở số không', ['lat' => 0, 'lon' => 0, 'city' => 'Hà Nội']);

        $this->assertCount(2, $this->loaiLoi('toa_do_ngoai_vn'));
    }

    public function test_toa_do_trong_viet_nam_khong_bi_bat(): void
    {
        // Bốn góc của kho thật: Lũng Cú, Phú Quốc, Côn Đảo, Trường Sơn Tây.
        $this->manHinh('Hà Giang', ['lat' => 23.39, 'lon' => 105.32, 'city' => 'Tuyên Quang']);
        $this->manHinh('Phú Quốc', ['lat' => 10.22, 'lon' => 103.96, 'city' => 'An Giang']);
        $this->manHinh('Côn Đảo', ['lat' => 8.68, 'lon' => 106.60, 'city' => 'Hồ Chí Minh']);

        $this->assertSame([], $this->loaiLoi('toa_do_ngoai_vn'));
    }

    public function test_thieu_tinh(): void
    {
        // Đúng ca thật trên production: `OOHX_LED_LCU_LAI_CHAU` có địa chỉ và
        // toạ độ đầy đủ ở Lai Châu nhưng ô tỉnh/thành rỗng, nên không bộ lọc
        // khu vực nào tìm ra nó.
        $this->manHinh('OOHX_LED_LCU_LAI_CHAU', [
            'city'    => null,
            'address' => 'Đại lộ Lê Lợi, P. Đoàn Kết, TP Lai Châu, Lai Châu',
            'lat'     => 22.3862021,
            'lon'     => 103.4702726,
        ]);

        $loi = $this->loaiLoi('thieu_tinh');

        $this->assertCount(1, $loi);
        $this->assertStringContainsString('Lai Châu', $loi[0]['chi_tiet']);
    }

    public function test_ghep_lech_khoa(): void
    {
        $dung = Site::factory()->create([
            'owner_id'    => $this->owner->id,
            'external_id' => 'SITE-DUNG',
            'status'      => 'active',
        ]);

        Screen::factory()->create([
            'owner_id'         => $this->owner->id,
            'site_id'          => $dung->id,
            'active'           => true,
            'name'             => 'Màn hình bị ghép lệch',
            'site_external_id' => 'SITE-KHAC',
        ]);

        $loi = $this->loaiLoi('ghep_lech_khoa');

        $this->assertCount(1, $loi);
        $this->assertStringContainsString('SITE-KHAC', $loi[0]['chi_tiet']);
        $this->assertStringContainsString('SITE-DUNG', $loi[0]['chi_tiet']);
    }

    public function test_khoa_khop_thi_khong_bao(): void
    {
        $site = Site::factory()->create([
            'owner_id'    => $this->owner->id,
            'external_id' => 'SITE-A',
            'status'      => 'active',
        ]);

        Screen::factory()->create([
            'owner_id'         => $this->owner->id,
            'site_id'          => $site->id,
            'active'           => true,
            'site_external_id' => 'SITE-A',
        ]);

        $this->assertSame([], $this->loaiLoi('ghep_lech_khoa'));
    }

    /**
     * Cột khoá rỗng thì phép đo **không** kết luận gì — và phải nói ra rằng
     * nó không đối chiếu được bản ghi nào.
     *
     * `SiteImportService` tra `site_external_id` để tìm `site_id` rồi không
     * lưu lại mã, nên phần lớn kho có cột này rỗng. Nếu báo cáo chỉ in "0
     * lỗi" thì nó đang nói "ghép đúng hết" trong khi thật ra là "không kiểm
     * được".
     */
    public function test_khoa_rong_thi_do_phu_bang_khong(): void
    {
        Screen::factory()->create([
            'owner_id'         => $this->owner->id,
            'site_id'          => Site::factory()->create(['owner_id' => $this->owner->id, 'status' => 'active'])->id,
            'active'           => true,
            'site_external_id' => null,
        ]);

        $kq = app(SoatViTriService::class)->soat();

        $this->assertSame(0, $kq['theo_loai']['ghep_lech_khoa']['so']);
        $this->assertSame(
            0,
            $kq['theo_loai']['ghep_lech_khoa']['do_phu'],
            'Báo cáo nói "0 lỗi" mà không nói "0 bản ghi kiểm được" — hai điều '
                . 'khác nhau hoàn toàn.',
        );
        $this->assertSame(1, $kq['tong_man_hinh']);
    }

    public function test_toa_do_xa_nhom(): void
    {
        // Ba vị trí thật ở TP.HCM, cộng một bản ghi khai "Hồ Chí Minh" nhưng
        // toạ độ ở Bắc Kạn — hình dạng kinh điển của một lần ghép sai.
        foreach ([[10.77, 106.69], [10.80, 106.66], [10.75, 106.70]] as [$lat, $lon]) {
            $this->manHinh('Billboard TP.HCM', ['lat' => $lat, 'lon' => $lon, 'city' => 'Hồ Chí Minh']);
        }

        $this->manHinh('Billboard khai sai tỉnh', [
            'lat' => 22.1371344, 'lon' => 105.8302229, 'city' => 'Hồ Chí Minh',
        ]);

        $loi = $this->loaiLoi('toa_do_xa_nhom');

        $this->assertCount(1, $loi);
        $this->assertSame('Billboard khai sai tỉnh', $loi[0]['screen_name']);
    }

    public function test_nhom_duoi_ba_thi_khong_ket_luan(): void
    {
        // Với hai bản ghi, trung vị nằm giữa chúng và "cả hai xa tâm bằng
        // nhau" là một câu không có nội dung. Báo cả hai là báo sai cả hai.
        $this->manHinh('Ở Hà Nội', ['lat' => 21.02, 'lon' => 105.84, 'city' => 'Hà Nội']);
        $this->manHinh('Ở Cà Mau', ['lat' => 9.18, 'lon' => 105.15, 'city' => 'Hà Nội']);

        $this->assertSame([], $this->loaiLoi('toa_do_xa_nhom'));
    }

    public function test_nhieu_man_mot_toa_do(): void
    {
        // Đúng ca `LED Yên Bái`: sáu màn hình trên một điểm đặt, tên chúng chỉ
        // ba con đường khác nhau.
        $site = Site::factory()->create([
            'owner_id' => $this->owner->id,
            'status'   => 'active',
            'name'     => 'LED Yên Bái',
            'city'     => 'Lào Cai > Phường Yên Bái',
            'address'  => 'Đường Nguyễn Tất Thành, Yên Bái, Lào Cai',
            'lat'      => 21.7225221,
            'lon'      => 104.91513,
        ]);

        foreach (['NGUYEN_TAT_THANH_1', 'NGUYEN_VAN_CU_1', 'QUANG_TRUONG'] as $hau) {
            Screen::factory()->create([
                'owner_id' => $this->owner->id,
                'site_id'  => $site->id,
                'active'   => true,
                'name'     => 'OOHX_LED_YBI_YEN_BAI_' . $hau,
            ]);
        }

        $loi = $this->loaiLoi('nhieu_man_mot_toa_do');

        $this->assertCount(3, $loi, 'Mỗi màn hình trong cụm phải có một dòng để sửa.');
        $this->assertSame(SoatViTriService::MUC_XEM_TAY, $loi[0]['muc']);
    }

    public function test_hai_man_mot_toa_do_la_binh_thuong(): void
    {
        // Hai mặt của một bảng, hoặc hai bảng ở một giao lộ — kho thật có cả
        // hai kiểu, và cả hai đúng.
        $site = Site::factory()->create([
            'owner_id' => $this->owner->id,
            'status'   => 'active',
            'lat'      => 20.9454327,
            'lon'      => 106.3434358,
            'city'     => 'Hải Phòng',
        ]);

        foreach (['MAT_TRUOC', 'MAT_SAU'] as $mat) {
            Screen::factory()->create([
                'owner_id' => $this->owner->id,
                'site_id'  => $site->id,
                'active'   => true,
                'name'     => 'OOHX_LED_HDG_HAI_DUONG_' . $mat,
            ]);
        }

        $this->assertSame([], $this->loaiLoi('nhieu_man_mot_toa_do'));
    }

    public function test_city_hai_cap(): void
    {
        $this->manHinh('OOHX_LED_BKN_BAC_KAN', [
            'name' => 'LED Bắc Kạn',
            'city' => 'Thái Nguyên > Phường Bắc Kạn',
        ]);

        $loi = $this->loaiLoi('city_hai_cap');

        $this->assertCount(1, $loi);
        $this->assertSame(SoatViTriService::MUC_XEM_TAY, $loi[0]['muc']);
        $this->assertStringContainsString('LED Bắc Kạn', $loi[0]['chi_tiet']);
    }

    public function test_ten_noi_tinh_khac(): void
    {
        VietnamProvince::create(['code' => '11', 'name' => 'Sơn La', 'full_name' => 'Tỉnh Sơn La', 'type' => 'tinh']);
        VietnamProvince::create(['code' => '10', 'name' => 'Lào Cai', 'full_name' => 'Tỉnh Lào Cai', 'type' => 'tinh']);

        $this->manHinh('OOHX_LED_SLA_SON_LA', [
            'name'    => 'LED Lào Cai',
            'city'    => 'Lào Cai',
            'address' => 'Đường Trần Hưng Đạo, Lào Cai',
        ]);

        $loi = $this->loaiLoi('ten_noi_tinh_khac');

        $this->assertCount(1, $loi);
        $this->assertSame(SoatViTriService::MUC_XEM_TAY, $loi[0]['muc'], 'Tên tỉnh cũng là tên đường; phép đo này không được tự nhận là lỗi.');
        $this->assertStringContainsString('son la', $loi[0]['chi_tiet']);
    }

    public function test_tinh_trong_ten_khop_dia_chi_thi_khong_bao(): void
    {
        VietnamProvince::create(['code' => '10', 'name' => 'Lào Cai', 'full_name' => 'Tỉnh Lào Cai', 'type' => 'tinh']);

        $this->manHinh('OOHX_LED_LCI_LAO_CAI', ['name' => 'LED Lào Cai', 'city' => 'Lào Cai']);

        $this->assertSame([], $this->loaiLoi('ten_noi_tinh_khac'));
    }

    public function test_bang_tinh_rong_thi_phep_do_tu_tat(): void
    {
        // Không seed `vietnam_provinces`. Phép đo không được đoán bừa, và độ
        // phủ phải nói rằng nó không chạy.
        $this->manHinh('OOHX_LED_SLA_SON_LA', ['name' => 'LED Lào Cai', 'city' => 'Lào Cai']);

        $kq = app(SoatViTriService::class)->soat();

        $this->assertSame(0, $kq['theo_loai']['ten_noi_tinh_khac']['so']);
        $this->assertSame(0, $kq['theo_loai']['ten_noi_tinh_khac']['do_phu']);
    }

    // ── Tên lệch quy ước: phép đo bắt được hàng "Aeon Mall Tân Phú" ──────────

    /**
     * Đúng hình dạng của hàng trên production.
     *
     * Bảy phép đo kia **không** bắt được nó: toạ độ đúng, tỉnh đúng, nhóm
     * đúng, không khoá nào để đối chiếu, trong tên không có tên tỉnh nào.
     */
    public function test_ten_lech_quy_uoc_bat_duoc_hang_nhap_tay(): void
    {
        $site = Site::factory()->create([
            'owner_id' => $this->owner->id,
            'status'   => 'active',
            'name'     => 'LED Bắc Kạn',
            'city'     => 'Thái Nguyên',
            'lat'      => 22.1371344,
            'lon'      => 105.8302229,
        ]);

        // Mười hai bản ghi theo quy ước mã, mỗi cái một điểm đặt riêng để
        // không rơi vào phép đo cụm toạ độ.
        foreach (range(1, 12) as $i) {
            $this->manHinh('OOHX_LED_X' . $i . '_TINH', [
                'city' => 'Thái Nguyên',
                'lat'  => 22.13 + $i / 100,
                'lon'  => 105.83 + $i / 100,
            ]);
        }

        Screen::factory()->create([
            'owner_id' => $this->owner->id,
            'site_id'  => $site->id,
            'active'   => true,
            'name'     => 'Màn Hình Quảng Cáo Aeon Mall Tân Phú',
        ]);

        $loi = $this->loaiLoi('ten_lech_quy_uoc');

        $this->assertCount(1, $loi);
        $this->assertSame('Màn Hình Quảng Cáo Aeon Mall Tân Phú', $loi[0]['screen_name']);
        $this->assertSame(SoatViTriService::MUC_XEM_TAY, $loi[0]['muc']);
    }

    public function test_owner_dat_ten_thong_nhat_thi_khong_bao(): void
    {
        // Đúng ca owner thứ hai trên production: 83/83 tên dạng chữ. Không có
        // thiểu số nào, nên không có gì để chỉ ra.
        foreach (range(1, 12) as $i) {
            $this->manHinh('Billboard Giao lộ số ' . $i, [
                'city' => 'Hồ Chí Minh',
                'lat'  => 10.77 + $i / 100,
                'lon'  => 106.69 + $i / 100,
            ]);
        }

        $this->assertSame([], $this->loaiLoi('ten_lech_quy_uoc'));
    }

    public function test_owner_dung_lan_hai_kieu_thi_khong_ket_luan(): void
    {
        // Nửa mã nửa chữ: owner không có quy ước nào, nên phép đo phải im.
        foreach (range(1, 6) as $i) {
            $this->manHinh('OOHX_LED_Y' . $i, [
                'city' => 'Hà Nội',
                'lat'  => 21.02 + $i / 100,
                'lon'  => 105.84 + $i / 100,
            ]);
            $this->manHinh('Billboard số ' . $i, [
                'city' => 'Hà Nội',
                'lat'  => 21.00 - $i / 100,
                'lon'  => 105.80 - $i / 100,
            ]);
        }

        $this->assertSame([], $this->loaiLoi('ten_lech_quy_uoc'));
    }

    public function test_kho_nho_thi_khong_noi_ve_quy_uoc(): void
    {
        // Ba cái tên không phải một quy ước.
        $this->manHinh('OOHX_LED_A', ['city' => 'Hà Nội', 'lat' => 21.02, 'lon' => 105.84]);
        $this->manHinh('OOHX_LED_B', ['city' => 'Hà Nội', 'lat' => 21.03, 'lon' => 105.85]);
        $this->manHinh('Billboard lẻ', ['city' => 'Hà Nội', 'lat' => 21.04, 'lon' => 105.86]);

        $this->assertSame([], $this->loaiLoi('ten_lech_quy_uoc'));
    }

    // ── Phép đo im lặng khi không có lỗi ─────────────────────────────────────

    /**
     * Tên viết tắt **không** được báo sai.
     *
     * Đây là ca đóng băng quyết định của lần soát 20/10/2026: phép đo "tên
     * không có token nào chung với điểm đặt" cho 14/15 cảnh báo sai trên kho
     * thật, vì một owner đặt tên bằng chữ viết tắt. Bốn hàng dưới đây là bốn
     * hàng thật trong số đó.
     */
    public function test_ten_viet_tat_khong_bi_bao_sai(): void
    {
        VietnamProvince::create(['code' => '79', 'name' => 'Hồ Chí Minh', 'full_name' => 'Thành phố Hồ Chí Minh', 'type' => 'thanh_pho']);
        VietnamProvince::create(['code' => '48', 'name' => 'Đà Nẵng', 'full_name' => 'Thành phố Đà Nẵng', 'type' => 'thanh_pho']);

        $that = [
            ['Billboard CV 23/9 13x6.5m',     'Công viên 23 Tháng 9',                  'Hồ Chí Minh'],
            ['Billboard LTT-SVH 13x5.5m',     'Giao lộ Lý Thái Tổ - Sư Vạn Hạnh (516)', 'Hồ Chí Minh'],
            ['Billboard CH-TKQT 18x9m',       'Giao lộ Cộng Hòa - Tân Kỳ Tân Quý',     'Hồ Chí Minh'],
            ['Billboard NVL-NTP ĐN 18x8m',    'Nguyễn Văn Linh - Nguyễn Tri Phương',   'Đà Nẵng'],
        ];

        foreach ($that as $i => [$tenMan, $tenSite, $tinh]) {
            $this->manHinh($tenMan, [
                'name' => $tenSite,
                'city' => $tinh,
                // Toạ độ tách nhau đủ để không thành cụm trùng, và vẫn trong
                // cùng thành phố.
                'lat'  => 10.77 + $i / 100,
                'lon'  => 106.69 + $i / 100,
            ]);
        }

        $this->assertSame(
            [],
            $this->soat(),
            'Một bảng mà phần lớn dòng là báo sai thì không ai đọc nó lần thứ hai.',
        );
    }

    public function test_kho_sach_thi_khong_bao_gi(): void
    {
        $this->manHinh('Billboard Nguyễn Huệ', [
            'name' => 'Phố đi bộ Nguyễn Huệ',
            'city' => 'Hồ Chí Minh',
            'lat'  => 10.7743,
            'lon'  => 106.7038,
        ]);

        $this->assertSame([], $this->soat());
    }

    public function test_man_hinh_chua_cong_khai_khong_vao_bao_cao(): void
    {
        // Phạm vi là tập người mua chạm tới. Một bản ghi hỏng chưa bật thì
        // còn thời gian sửa; đưa nó vào đây chỉ làm loãng bảng.
        $this->manHinh('Chưa bật', ['lat' => null, 'lon' => null], ['active' => false]);

        $this->assertSame([], $this->soat());
    }

    public function test_owner_tam_dung_khong_vao_bao_cao(): void
    {
        $owner = Owner::factory()->create(['status' => 'suspended']);

        $site = Site::factory()->create(['owner_id' => $owner->id, 'lat' => null, 'lon' => null]);

        Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        $this->assertSame([], $this->soat());
    }

    public function test_khong_bao_trung_mot_loi_hai_lan(): void
    {
        // Thiếu toạ độ là một lỗi, không phải hai. Nếu `toa_do_ngoai_vn` đọc
        // null thành 0 thì cùng một hàng hiện ở hai phép đo và con số tổng
        // nói quá sự thật.
        $this->manHinh('Không pin', ['lat' => null, 'lon' => null, 'city' => 'Hà Nội']);

        $loi = $this->soat();

        $this->assertCount(1, $loi);
        $this->assertSame('thieu_toa_do', $loi[0]['loai']);
    }

    // ── Trang trong `/admin` ─────────────────────────────────────────────────

    /**
     * Trang dựng được thật, không chỉ "lớp Page tồn tại".
     *
     * CLAUDE.md mục 8: có route, có menu, có model thì chưa phải có tính
     * năng. Ca này nạp đúng URL người dùng bấm và đọc nội dung trả về, nên
     * một lỗi Blade hay một biến thiếu trong `getViewData()` làm nó đỏ.
     */
    public function test_trang_admin_render_duoc_va_in_ra_phat_hien(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $quanTri = User::factory()->create();
        $quanTri->assignRole('super_admin');

        $this->manHinh('OOHX_LED_LCU_LAI_CHAU', [
            'name'    => 'LED Lai Châu',
            'city'    => null,
            'address' => 'Đại lộ Lê Lợi, P. Đoàn Kết, TP Lai Châu, Lai Châu',
            'lat'     => 22.3862021,
            'lon'     => 103.4702726,
        ]);

        $this->actingAs($quanTri)
            ->get($this->duongTrang())
            ->assertOk()
            ->assertSee('OOHX_LED_LCU_LAI_CHAU')
            ->assertSee('Thiếu tỉnh/thành')
            // Phạm vi phải in ra: một báo cáo im lặng về thứ nó KHÔNG soát bị
            // đọc như một báo cáo đầy đủ.
            ->assertSee('Không soát ở đây', false);
    }

    public function test_media_owner_khong_vao_duoc_trang_soat(): void
    {
        Role::firstOrCreate(['name' => 'publisher', 'guard_name' => 'web']);

        $nguoi = User::factory()->create(['current_owner_id' => $this->owner->id]);
        $nguoi->assignRole('publisher');

        $this->actingAs($nguoi)
            ->get($this->duongTrang())
            ->assertForbidden();
    }

    /**
     * URL đầy đủ của trang, kèm tên miền của panel.
     *
     * `AdminPanelProvider` gắn `->domain(config('domains.dash'))` ở mọi môi
     * trường trừ `local`, nên một cú `get('/admin/...')` trong test trả **404**
     * vì tên miền không khớp — không phải vì trang chưa tồn tại.
     */
    private function duongTrang(): string
    {
        return 'http://' . config('domains.dash') . '/admin/soat-vi-tri';
    }

    // ── Hình dạng kết quả ────────────────────────────────────────────────────

    public function test_moi_phep_do_deu_co_nhan_va_he_qua(): void
    {
        // Thêm một mã lỗi mà quên chữ tiếng Việt thì báo cáo in mã máy ra cho
        // người đọc — đúng trên trang có việc giải thích dữ liệu.
        foreach (array_keys(SoatViTriService::NHAN) as $loai) {
            $this->assertArrayHasKey(
                $loai,
                SoatViTriService::HE_QUA,
                'Phép đo ' . $loai . ' không nói được hệ quả của nó với người mua.',
            );
            $this->assertNotSame('', trim(SoatViTriService::HE_QUA[$loai]));
        }
    }

    public function test_hai_muc_deu_co_chu_tieng_viet(): void
    {
        // `ChuBladePanelPhaiCoDauTest` đòi mọi chuỗi hiển thị trong Blade của
        // panel phải có dấu. Bản đầu in "cao" và "xem tay" thẳng vào Blade và
        // CI đỏ ở đúng chốt đó.
        foreach ([SoatViTriService::MUC_CAO, SoatViTriService::MUC_XEM_TAY] as $muc) {
            $this->assertArrayHasKey($muc, SoatViTriService::MUC_NHAN);
            $this->assertMatchesRegularExpression(
                '/[àáảãạăằắẳẵặâầấẩẫậèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ]/u',
                Str::lower(SoatViTriService::MUC_NHAN[$muc]),
                'Chữ cho mức "' . $muc . '" không có dấu tiếng Việt nào.',
            );
        }
    }

    public function test_tong_hop_phu_moi_phep_do_ke_ca_khi_khong_co_loi(): void
    {
        $this->manHinh('Sạch', ['city' => 'Hà Nội', 'lat' => 21.02, 'lon' => 105.84]);

        $kq = app(SoatViTriService::class)->soat();

        foreach (array_keys(SoatViTriService::NHAN) as $loai) {
            $this->assertArrayHasKey($loai, $kq['theo_loai']);
            $this->assertArrayHasKey('do_phu', $kq['theo_loai'][$loai]);
        }
    }
}
