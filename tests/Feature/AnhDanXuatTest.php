<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Services\AnhDanXuatService;
use App\Services\SoatAnhKhoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bản dẫn xuất `w480`/`w1280` — lát 4 của `docs/PLAN-ANH-VI-TRI.md`.
 *
 * ══ Ba hạng chốt khác nhau, và đừng đọc lẫn ══
 *
 *  1. **Phép thuần** (`duongDanXuat`, `srcsetCho`): chạy không cần GD, không
 *     cần CSDL. Đây là chỗ phép kiểm đường dẫn sống, và nó là một phép kiểm
 *     bảo mật — giá trị vào đó đến từ cột `photos`, mà đường nhập kho ghi vào
 *     cột ấy những gì bảng tính của đối tác nói (`AnhNhapKhoTest` đã có một ca
 *     `../../etc/passwd`).
 *
 *  2. **Sinh ảnh thật bằng GD**, với ảnh thật dựng trong ca test. Không dùng
 *     `UploadedFile::fake()->image()` — hàm đó cũng gọi GD nhưng không cho
 *     kiểm soát định dạng và không trả byte, còn ở đây bề rộng ra đúng bao
 *     nhiêu **là** thứ cần đo.
 *
 *  3. **Đường chạy thật**: observer khi cột `photos` đổi, lệnh artisan khi ảnh
 *     đã có từ trước, và hai DTO khi ảnh ra tới người mua. `CLAUDE.md` mục 8:
 *     có service và có lệnh chưa phải là có đường chạy.
 *
 * ══ Một bất biến nối sang lát 0 ══
 *
 * `oohx:soat-anh-kho` đi bộ qua `screen-photos` và trả "kho có bao nhiêu ảnh,
 * nặng bao nhiêu". Bản dẫn xuất nằm ngoài thư mục đó **có chủ ý**, nên có một
 * ca ở dưới sinh ảnh rồi đòi con số của lát 0 không đổi một đơn vị. Đặt bản
 * dẫn xuất vào trong `screen-photos` thì ca đó đỏ — và nếu không có nó thì
 * báo cáo của lát 0 lặng lẽ nói gấp ba.
 */
class AnhDanXuatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function dv(?int $toiDaPixel = null): AnhDanXuatService
    {
        return new AnhDanXuatService($toiDaPixel ?? AnhDanXuatService::TOI_DA_PIXEL);
    }

    /**
     * Byte của một ảnh **thật**, dựng bằng GD.
     *
     * Có một vùng trong suốt ở góc trên trái cho PNG và WebP: đó là thứ ca
     * `alpha` đo, và một ảnh đặc hoàn toàn thì nhánh `imagesavealpha` không
     * được kiểm.
     */
    private function anhThat(int $w, int $h, int $loai = IMAGETYPE_JPEG): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);

        // Nền đỏ đặc, rồi một ô trong suốt 1/4 bề rộng ở góc trên trái.
        $do = imagecolorallocate($im, 220, 40, 40);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, $do);

        $trong = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, max(1, intdiv($w, 4)) - 1, max(1, intdiv($h, 4)) - 1, $trong);

        ob_start();
        match ($loai) {
            IMAGETYPE_PNG  => imagepng($im),
            IMAGETYPE_WEBP => imagewebp($im, null, 90),
            default        => imagejpeg($im, null, 90),
        };
        $byte = (string) ob_get_clean();
        imagedestroy($im);

        return $byte;
    }

    /** Bề rộng × bề cao của một tệp trên đĩa giả. @return array{int, int} */
    private function doAnh(string $duong): array
    {
        $tt = getimagesizefromstring((string) Storage::disk('public')->get($duong));

        $this->assertNotFalse($tt, "Tệp {$duong} không đọc ra ảnh.");

        return [(int) $tt[0], (int) $tt[1]];
    }

    /** @return list<string> mọi tệp trên đĩa giả, đã sắp */
    private function moiTep(): array
    {
        $tep = Storage::disk('public')->allFiles();
        sort($tep);

        return $tep;
    }

    // ══ 1. Đường dẫn — phép thuần ═════════════════════════════════════════

    public function test_duong_dan_xuat_nam_duoi_thu_muc_rieng_theo_be_rong(): void
    {
        $this->assertSame(
            'anh-dan-xuat/w480/screen-photos/01k0/a.jpg',
            AnhDanXuatService::duongDanXuat('screen-photos/01k0/a.jpg', 480),
        );

        $this->assertSame(
            'anh-dan-xuat/w1280/screen-photos/01k0/a.jpg',
            AnhDanXuatService::duongDanXuat('screen-photos/01k0/a.jpg', 1280),
        );
    }

    public function test_hai_be_rong_khong_bao_gio_tro_vao_cung_mot_tep(): void
    {
        $a = AnhDanXuatService::duongDanXuat('owners/covers/bia.webp', 480);
        $b = AnhDanXuatService::duongDanXuat('owners/covers/bia.webp', 1280);

        $this->assertNotSame($a, $b);
        $this->assertNotNull($a);
        $this->assertNotNull($b);
    }

    public function test_be_rong_khong_khai_tra_null(): void
    {
        // Không phải phòng xa: một lượt gọi `duongDanXuat($p, 800)` ở đâu đó
        // mà trả một đường dẫn nghĩa là có tệp `w800` trên đĩa mà `srcsetCho()`
        // không bao giờ liệt — tốn chỗ, không ai dùng, và không ai biết.
        foreach ([0, -480, 240, 800, 1920] as $be) {
            $this->assertNull(
                AnhDanXuatService::duongDanXuat('screen-photos/a.jpg', $be),
                "Bề rộng {$be} không nằm trong BE_RONG.",
            );
        }
    }

    public function test_url_day_du_khong_co_ban_dan_xuat(): void
    {
        foreach ([
            'https://doitac.example/a.jpg',
            'http://doitac.example/a.jpg',
        ] as $url) {
            $this->assertNull(AnhDanXuatService::duongDanXuat($url, 480));
            $this->assertNull(AnhDanXuatService::srcsetCho($url));
        }
    }

    public function test_duong_dan_hinh_dang_sai_tra_null_va_khong_ra_ngoai_disk(): void
    {
        $sai = [
            null,
            '',
            '   ',
            '../../etc/passwd',
            'screen-photos/../../etc/passwd',
            '/etc/passwd',
            "screen-photos/a\0.jpg",
            // Không sinh bản dẫn xuất của một bản dẫn xuất: lượt thứ hai sẽ
            // ra `anh-dan-xuat/w480/anh-dan-xuat/w480/…` và cứ thế.
            'anh-dan-xuat/w480/screen-photos/a.jpg',
        ];

        foreach ($sai as $p) {
            foreach (AnhDanXuatService::BE_RONG as $be) {
                $ra = AnhDanXuatService::duongDanXuat($p, $be);

                $this->assertNull($ra, 'Hình dạng sai phải ra null: ' . var_export($p, true));
            }

            $this->assertNull(AnhDanXuatService::srcsetCho($p));
        }
    }

    /**
     * Bất biến với lát 0: hai thư mục **không** lồng nhau.
     *
     * `SoatAnhKhoService::doDia()` đếm tệp trong `screen-photos`. Bản dẫn xuất
     * nằm trong đó thì con số ấy gấp ba và báo cáo nói sai.
     */
    public function test_thu_muc_dan_xuat_khong_long_trong_thu_muc_anh(): void
    {
        $this->assertStringStartsNotWith(
            SoatAnhKhoService::THU_MUC_ANH,
            AnhDanXuatService::THU_MUC,
        );

        $this->assertStringStartsNotWith(
            AnhDanXuatService::THU_MUC,
            SoatAnhKhoService::THU_MUC_ANH,
        );

        foreach (AnhDanXuatService::BE_RONG as $be) {
            $this->assertStringStartsWith(
                AnhDanXuatService::THU_MUC . '/',
                (string) AnhDanXuatService::duongDanXuat('screen-photos/a.jpg', $be),
            );
        }
    }

    // ══ 2. srcset — phép thuần + đĩa ══════════════════════════════════════

    public function test_srcset_la_null_khi_chua_co_ban_nao(): void
    {
        Storage::disk('public')->put('screen-photos/a.jpg', 'x');

        // `null`, KHÔNG chuỗi rỗng: React in `srcset=""` ra HTML cho chuỗi
        // rỗng, và một thuộc tính rỗng là một thuộc tính trình duyệt vẫn phải
        // đọc.
        $this->assertNull(AnhDanXuatService::srcsetCho('screen-photos/a.jpg'));
    }

    public function test_srcset_chi_liet_be_rong_co_tep_that(): void
    {
        Storage::disk('public')->put('anh-dan-xuat/w480/screen-photos/a.jpg', 'x');

        $this->assertSame(
            asset('storage/anh-dan-xuat/w480/screen-photos/a.jpg') . ' 480w',
            AnhDanXuatService::srcsetCho('screen-photos/a.jpg'),
        );
    }

    public function test_srcset_hai_ban_theo_be_rong_tang_dan(): void
    {
        Storage::disk('public')->put('anh-dan-xuat/w1280/screen-photos/a.jpg', 'x');
        Storage::disk('public')->put('anh-dan-xuat/w480/screen-photos/a.jpg', 'x');

        $this->assertSame(
            asset('storage/anh-dan-xuat/w480/screen-photos/a.jpg') . ' 480w, '
            . asset('storage/anh-dan-xuat/w1280/screen-photos/a.jpg') . ' 1280w',
            AnhDanXuatService::srcsetCho('screen-photos/a.jpg'),
        );
    }

    public function test_srcset_dung_cung_phep_dung_url_voi_lat_1(): void
    {
        Storage::disk('public')->put('anh-dan-xuat/w480/screen-photos/a.jpg', 'x');

        $srcset = (string) AnhDanXuatService::srcsetCho('screen-photos/a.jpg');

        // Lát 1 gộp ba bản của phép dựng URL thành một ở `photoUrlOf()`. Bản
        // dẫn xuất dựng URL bằng cách khác là bản thứ hai quay lại.
        $this->assertStringStartsWith(
            ScreenSpec::photoUrlOf('anh-dan-xuat/w480/screen-photos/a.jpg'),
            $srcset,
        );
    }

    public function test_srcset_cho_day_giu_dung_chi_so(): void
    {
        Storage::disk('public')->put('anh-dan-xuat/w480/a.jpg', 'x');
        Storage::disk('public')->put('anh-dan-xuat/w480/c.jpg', 'x');

        $ra = AnhDanXuatService::srcsetChoDay(['a.jpg', 'b.jpg', 'c.jpg']);

        // Ba phần tử, `null` ở đúng ô giữa. Nén dãy lại cho gọn là mỗi ảnh từ
        // ô đó về sau mang `srcset` của ảnh khác — trình duyệt tải một ảnh,
        // hiện một ảnh khác.
        $this->assertCount(3, $ra);
        $this->assertNotNull($ra[0]);
        $this->assertNull($ra[1]);
        $this->assertNotNull($ra[2]);
        $this->assertStringContainsString('w480/c.jpg', (string) $ra[2]);
    }

    // ══ 3. Sinh ảnh thật ══════════════════════════════════════════════════

    /**
     * Ảnh 3000×1000, không 3000×2000 — và lý do là một phát hiện của lượt
     * chạy đầu.
     *
     * Bản đầu dùng 3000×2000 = 6 triệu điểm, tức ~24MB cho bản gốc trong GD.
     * Ca đó **đỏ**: `quaLon()` từ chối vì bộ nhớ còn lại của tiến trình test
     * không đủ. Phép ước lượng của `quaLon()` sai thật và đã sửa — nhưng ngay
     * sau khi sửa, nhu cầu ~37MB vẫn nằm sát mức còn lại của một tiến trình
     * PHPUnit, nên ca ấy sẽ **chập chờn**: xanh hay đỏ tuỳ dấu chân bộ nhớ của
     * cả bộ test ở thời điểm chạy.
     *
     * Một ca chập chờn tệ hơn không có ca: nó dạy người ta chạy lại cho tới
     * khi xanh. Nên ảnh ở đây là 3000×1000 — vẫn **3000px** như chốt của plan
     * đòi, 3 triệu điểm, ~12MB, nằm sâu dưới mọi ngưỡng. Và 3:1 đúng là tỉ lệ
     * của một biển quảng cáo ngoài trời.
     *
     * Hàng rào bộ nhớ có ca riêng (`test_anh_vuot_tran_diem_anh_bi_bo_qua...`)
     * với trần tiêm vào, nên nó được đo mà không cần một ảnh nặng.
     */
    public function test_anh_lon_sinh_du_hai_ban_va_be_rong_dung_con_so(): void
    {
        Storage::disk('public')->put('screen-photos/goc.jpg', $this->anhThat(3000, 1000));

        $kq = $this->dv()->sinhCho('screen-photos/goc.jpg');

        $this->assertSame(AnhDanXuatService::KQ_DA_SINH, $kq['ket_qua']);
        $this->assertSame([480, 1280], $kq['da_sinh']);
        $this->assertSame(3000, $kq['be_rong_goc']);

        // Chốt mà plan đòi nguyên văn: *"Tải một ảnh 3000px → có ba tệp, và
        // `w480` đúng là 480px."*
        $this->assertSame([480, 160], $this->doAnh('anh-dan-xuat/w480/screen-photos/goc.jpg'));
        $this->assertSame([1280, 427], $this->doAnh('anh-dan-xuat/w1280/screen-photos/goc.jpg'));

        // Ba tệp: bản gốc còn nguyên chỗ cũ.
        $this->assertCount(3, $this->moiTep());
    }

    public function test_ban_goc_khong_bi_sua(): void
    {
        $byte = $this->anhThat(2000, 1000);
        Storage::disk('public')->put('screen-photos/goc.jpg', $byte);

        $this->dv()->sinhCho('screen-photos/goc.jpg');

        $this->assertSame($byte, Storage::disk('public')->get('screen-photos/goc.jpg'));
        $this->assertSame([2000, 1000], $this->doAnh('screen-photos/goc.jpg'));
    }

    public function test_chay_lan_hai_khong_sinh_them_tep_nao(): void
    {
        Storage::disk('public')->put('screen-photos/goc.jpg', $this->anhThat(2000, 1000));

        $this->dv()->sinhCho('screen-photos/goc.jpg');
        $truoc = Storage::disk('public')->get('anh-dan-xuat/w480/screen-photos/goc.jpg');
        $tep   = $this->moiTep();

        $kq = $this->dv()->sinhCho('screen-photos/goc.jpg');

        // Chốt mà plan đòi: *"Lệnh chạy hai lần không sinh thêm tệp nào ở lượt
        // hai."* Và không ghi lại lên tệp cũ: ghi lại là một lượt I/O vô nghĩa
        // trên 10.000 ảnh, và nó đổi `mtime` nên mọi lớp cache phía trước coi
        // ảnh là mới.
        $this->assertSame(AnhDanXuatService::KQ_DA_CO, $kq['ket_qua']);
        $this->assertSame([], $kq['da_sinh']);
        $this->assertSame($tep, $this->moiTep());
        $this->assertSame($truoc, Storage::disk('public')->get('anh-dan-xuat/w480/screen-photos/goc.jpg'));
    }

    public function test_co_lai_thi_sinh_de_len_ban_cu(): void
    {
        Storage::disk('public')->put('screen-photos/goc.jpg', $this->anhThat(2000, 1000));
        Storage::disk('public')->put('anh-dan-xuat/w480/screen-photos/goc.jpg', 'rac');

        $kq = $this->dv()->sinhCho('screen-photos/goc.jpg', lai: true);

        $this->assertSame(AnhDanXuatService::KQ_DA_SINH, $kq['ket_qua']);
        $this->assertSame([480, 1280], $kq['da_sinh']);
        $this->assertSame([480, 240], $this->doAnh('anh-dan-xuat/w480/screen-photos/goc.jpg'));
    }

    public function test_khong_phong_to_mot_anh_nho_hon_be_rong(): void
    {
        // 600px: `w480` làm được, `w1280` thì phải phóng to — và một bản phóng
        // to nặng hơn bản gốc mà không rõ hơn, lại khai sai bề rộng trong
        // `srcset`. Cùng bài học `imageResizeUpscale(false)` của lát 2, lần
        // này ở phía máy chủ.
        Storage::disk('public')->put('screen-photos/nho.jpg', $this->anhThat(600, 400));

        $kq = $this->dv()->sinhCho('screen-photos/nho.jpg');

        $this->assertSame(AnhDanXuatService::KQ_DA_SINH, $kq['ket_qua']);
        $this->assertSame([480], $kq['da_sinh']);
        $this->assertTrue(Storage::disk('public')->exists('anh-dan-xuat/w480/screen-photos/nho.jpg'));
        $this->assertFalse(Storage::disk('public')->exists('anh-dan-xuat/w1280/screen-photos/nho.jpg'));

        // Và `srcset` chỉ có một mục, khai đúng 480w.
        $this->assertSame(
            asset('storage/anh-dan-xuat/w480/screen-photos/nho.jpg') . ' 480w',
            AnhDanXuatService::srcsetCho('screen-photos/nho.jpg'),
        );
    }

    public function test_anh_nho_hon_moi_be_rong_khong_sinh_gi_va_srcset_null(): void
    {
        Storage::disk('public')->put('screen-photos/ti.jpg', $this->anhThat(300, 200));

        $kq = $this->dv()->sinhCho('screen-photos/ti.jpg');

        $this->assertSame(AnhDanXuatService::KQ_GOC_NHO, $kq['ket_qua']);
        $this->assertCount(1, $this->moiTep());

        // `<img>` khi đó không có `srcset` và dùng thẳng bản gốc — đúng thứ
        // phải xảy ra, không phải một lỗi.
        $this->assertNull(AnhDanXuatService::srcsetCho('screen-photos/ti.jpg'));
    }

    public function test_tep_mat_khoi_dia_bao_dung_ly_do(): void
    {
        $kq = $this->dv()->sinhCho('screen-photos/khong-ton-tai.jpg');

        $this->assertSame(AnhDanXuatService::KQ_THIEU_TEP, $kq['ket_qua']);
        $this->assertSame([], $this->moiTep());
    }

    public function test_tep_khong_phai_anh_khong_lam_vo_gi(): void
    {
        // Hình dạng thật: một hàng CSDL sửa tay, hoặc một tệp bị cắt giữa lúc
        // tải lên. Không được ném, và không được để lại một tệp `w480` rỗng
        // mà `srcsetCho()` sẽ liệt vào `srcset` — một `<img>` trỏ vào một tệp
        // không giải nén được thì hiện ra ô ảnh vỡ.
        Storage::disk('public')->put('screen-photos/rac.jpg', 'day khong phai anh');

        $kq = $this->dv()->sinhCho('screen-photos/rac.jpg');

        $this->assertSame(AnhDanXuatService::KQ_KHONG_DOC, $kq['ket_qua']);
        $this->assertCount(1, $this->moiTep());
        $this->assertNull(AnhDanXuatService::srcsetCho('screen-photos/rac.jpg'));
    }

    public function test_png_giu_kenh_trong_suot(): void
    {
        Storage::disk('public')->put('screen-photos/logo.png', $this->anhThat(1000, 1000, IMAGETYPE_PNG));

        $this->dv()->sinhCho('screen-photos/logo.png');

        $im = imagecreatefromstring(
            (string) Storage::disk('public')->get('anh-dan-xuat/w480/screen-photos/logo.png'),
        );

        $this->assertNotFalse($im);

        // Góc trên trái của ảnh gốc là vùng trong suốt. Thiếu
        // `imagesavealpha`/`imagealphablending` thì nó ra ĐEN ĐẶC — và một
        // logo trên nền sáng thành một khối đen mà không lỗi nào báo.
        $mau = imagecolorsforindex($im, imagecolorat($im, 2, 2));
        imagedestroy($im);

        $this->assertSame(127, $mau['alpha'], 'Điểm trong suốt phải còn trong suốt.');
    }

    public function test_webp_vao_thi_webp_ra(): void
    {
        if (! AnhDanXuatService::gdLam(IMAGETYPE_WEBP)) {
            $this->markTestSkipped('GD ở máy này không có WebP.');
        }

        Storage::disk('public')->put('owners/covers/bia.webp', $this->anhThat(1600, 900, IMAGETYPE_WEBP));

        $kq = $this->dv()->sinhCho('owners/covers/bia.webp');

        $this->assertSame(AnhDanXuatService::KQ_DA_SINH, $kq['ket_qua']);

        // Giữ định dạng gốc, không đổi hết sang một định dạng: đổi sang WebP
        // cho mọi ảnh cần GD có WebP ở **mọi** máy chạy mã này, và không có
        // cách nào từ đây xác nhận production có.
        $tt = getimagesizefromstring(
            (string) Storage::disk('public')->get('anh-dan-xuat/w480/owners/covers/bia.webp'),
        );

        $this->assertSame(IMAGETYPE_WEBP, $tt[2]);
        $this->assertSame(480, $tt[0]);
    }

    // ══ Ảnh bom ══════════════════════════════════════════════════════════

    public function test_anh_vuot_tran_diem_anh_bi_bo_qua_va_noi_ly_do(): void
    {
        // Trần tiêm vào nhỏ xíu, vì dựng một ảnh 12 triệu điểm trong một ca
        // test là tốn đúng số bộ nhớ mà phép kiểm này sinh ra để tránh.
        Storage::disk('public')->put('screen-photos/bom.jpg', $this->anhThat(600, 400));

        $kq = $this->dv(toiDaPixel: 1_000)->sinhCho('screen-photos/bom.jpg');

        $this->assertSame(AnhDanXuatService::KQ_QUA_LON, $kq['ket_qua']);
        $this->assertSame(600, $kq['be_rong_goc']);
        $this->assertStringContainsString('600×400', (string) $kq['ly_do']);

        // Không mở ảnh ra, nên không tệp nào được ghi.
        $this->assertCount(1, $this->moiTep());
    }

    public function test_tran_that_nam_tren_kich_thuoc_duong_tai_anh_sinh_ra(): void
    {
        // Lát 2 co ảnh về 2000px ở trình duyệt, tức 4 triệu điểm. Trần phải ở
        // trên con số đó — dưới nó thì đường tải ảnh bình thường không bao giờ
        // có bản dẫn xuất, và không ai hiểu vì sao.
        $this->assertGreaterThan(2000 * 2000, AnhDanXuatService::TOI_DA_PIXEL);

        // Và phải ở dưới mức mà GD cần hơn 128MB: 4 byte một điểm ảnh.
        $this->assertLessThanOrEqual(
            30_000_000,
            AnhDanXuatService::TOI_DA_PIXEL,
            'Trần quá cao thì một lượt OOM giết request đang lưu biểu mẫu, '
            . 'và lỗi tử của PHP không catch được.',
        );
    }

    public function test_gd_lam_gi_tra_du_ba_dinh_dang(): void
    {
        // Lệnh in bảng này ra, và đó là **đường duy nhất** để biết PHP của
        // production có GD: `php -i` phải gõ qua ssh, và ba lượt ssh gần nhất
        // không đưa được đầu ra về chat.
        $this->assertSame(['jpeg', 'png', 'webp'], array_keys(AnhDanXuatService::gdLamGi()));

        // Ở CI thì cả ba đều có (`.github/workflows/tests.yml` khai `gd`), nên
        // JPEG là chiều dương đo được ở mọi lượt chạy.
        $this->assertTrue(AnhDanXuatService::gdLam(IMAGETYPE_JPEG));
        $this->assertFalse(AnhDanXuatService::gdLam(IMAGETYPE_GIF));
    }

    // ══ 4. Đường chạy thật — observer ═════════════════════════════════════

    public function test_luu_cot_photos_thi_ban_dan_xuat_co_ngay(): void
    {
        Storage::disk('public')->put('screen-photos/moi.jpg', $this->anhThat(1600, 900));

        $man = $this->manHinh();

        // Lượt ghi đi qua model, không qua service: observer là chỗ bắt nó, và
        // đó là lý do nó không nằm trong biểu mẫu Filament — đường nhập kho và
        // seeder cũng phải được bản dẫn xuất.
        $man->spec->update(['photos' => ['screen-photos/moi.jpg']]);

        $this->assertTrue(Storage::disk('public')->exists('anh-dan-xuat/w480/screen-photos/moi.jpg'));
        $this->assertTrue(Storage::disk('public')->exists('anh-dan-xuat/w1280/screen-photos/moi.jpg'));
    }

    public function test_tao_spec_moi_cung_sinh_ban_dan_xuat(): void
    {
        Storage::disk('public')->put('screen-photos/tao.jpg', $this->anhThat(1600, 900));

        // `created`, không chỉ `updated`: sau một lượt `insert` thì
        // `wasChanged()` của Laravel là rỗng, nên một observer chỉ nghe
        // `updated` bỏ qua đúng lượt ghi đầu tiên của mọi màn hình.
        $this->manHinh(['photos' => ['screen-photos/tao.jpg']]);

        $this->assertTrue(Storage::disk('public')->exists('anh-dan-xuat/w480/screen-photos/tao.jpg'));
    }

    public function test_luu_cot_khac_khong_cham_vao_anh(): void
    {
        Storage::disk('public')->put('screen-photos/moi.jpg', $this->anhThat(1600, 900));
        $man = $this->manHinh(['photos' => ['screen-photos/moi.jpg']]);

        Storage::disk('public')->delete('anh-dan-xuat/w480/screen-photos/moi.jpg');

        $man->spec->update(['width_px' => 1920]);

        // Một lượt lưu đổi `width_px` không phải lúc đi `stat` mười tệp ảnh.
        // Ở 10.000 vị trí × 10 ảnh thì "vô hại" cộng lại thành 100.000 lần
        // `stat` cho mỗi lượt lưu bất kỳ.
        $this->assertFalse(Storage::disk('public')->exists('anh-dan-xuat/w480/screen-photos/moi.jpg'));
    }

    public function test_anh_hong_khong_lam_vo_luot_luu(): void
    {
        Storage::disk('public')->put('screen-photos/rac.jpg', 'khong phai anh');

        $man = $this->manHinh();
        $man->spec->update(['photos' => ['screen-photos/rac.jpg']]);

        // Bản dẫn xuất là một phép tối ưu. Để nó ném ngược ra là biến phép tối
        // ưu thành điều kiện để lưu được một màn hình — người dùng mất cả biểu
        // mẫu vì một tệp GD không đọc nổi.
        $this->assertSame(['screen-photos/rac.jpg'], $man->spec->fresh()->photos);
    }

    // ══ 5. Đường chạy thật — lệnh artisan ════════════════════════════════

    public function test_lenh_sinh_cho_anh_da_co_tu_truoc(): void
    {
        Storage::disk('public')->put('screen-photos/cu.jpg', $this->anhThat(1600, 900));

        // Ghi thẳng vào CSDL, vòng qua observer: đúng hình dạng của ảnh đã nằm
        // trong cột từ trước lát 4, thứ mà observer không bao giờ thấy vì
        // không còn lượt ghi nào nữa.
        $man = $this->manHinh();
        ScreenSpec::withoutEvents(fn () => $man->spec->update(['photos' => ['screen-photos/cu.jpg']]));

        $this->assertFalse(Storage::disk('public')->exists('anh-dan-xuat/w480/screen-photos/cu.jpg'));

        Artisan::call('oohx:sinh-anh-dan-xuat');

        $this->assertTrue(Storage::disk('public')->exists('anh-dan-xuat/w480/screen-photos/cu.jpg'));
        $this->assertTrue(Storage::disk('public')->exists('anh-dan-xuat/w1280/screen-photos/cu.jpg'));
    }

    public function test_lenh_di_theo_chuoi_du_phong_nen_bia_owner_cung_co_ban_dan_xuat(): void
    {
        // Đây là nhánh **phổ biến nhất** trên dữ liệu hôm nay: 104/104 vị trí
        // hiện ra bằng ảnh bìa chủ sở hữu. Observer của `screen_specs` không
        // thấy ảnh bìa — nó thuộc bản ghi `owners`.
        Storage::disk('public')->put('owners/covers/bia.jpg', $this->anhThat(1600, 900));

        $this->manHinh(bia: 'owners/covers/bia.jpg');

        Artisan::call('oohx:sinh-anh-dan-xuat');

        $this->assertTrue(Storage::disk('public')->exists('anh-dan-xuat/w480/owners/covers/bia.jpg'));
    }

    public function test_lenh_gop_duong_dan_trung(): void
    {
        Storage::disk('public')->put('owners/covers/bia.jpg', $this->anhThat(1600, 900));

        $owner = Owner::factory()->create(['status' => 'active', 'cover_url' => 'owners/covers/bia.jpg']);

        // Ba màn hình cùng một chủ, nên cùng một ảnh bìa. Không gộp thì lệnh
        // mở cùng tệp đó ba lần và thu nhỏ sáu lần — ở 83 màn hình của Kim
        // Ngân ADV thì là 166 lượt resize cho hai tệp.
        $this->manHinh(owner: $owner);
        $this->manHinh(owner: $owner);
        $this->manHinh(owner: $owner);

        Artisan::call('oohx:sinh-anh-dan-xuat');
        $ra = Artisan::output();

        $this->assertStringContainsString('1 đường dẫn ảnh phân biệt', $ra);
        $this->assertStringContainsString('2 tệp đã sinh', $ra);

        // Hai khẳng định trên **không** đo được phép gộp: bỏ phép gộp đi thì
        // lệnh vẫn ra "1 đường dẫn" và "2 tệp", chỉ mở cùng tệp đó ba lần.
        // Thứ đo được là bảng kết quả — không gộp thì nó có thêm hai hàng "Đã
        // có đủ, không làm gì" cho hai lượt thừa.
        $this->assertStringNotContainsString(
            AnhDanXuatService::NHAN_KQ[AnhDanXuatService::KQ_DA_CO],
            $ra,
            'Lệnh xử lý cùng một đường dẫn nhiều hơn một lần.',
        );
    }

    public function test_soat_khong_ghi_tep_nao(): void
    {
        Storage::disk('public')->put('screen-photos/cu.jpg', $this->anhThat(1600, 900));

        $man = $this->manHinh();
        ScreenSpec::withoutEvents(fn () => $man->spec->update(['photos' => ['screen-photos/cu.jpg']]));

        $truoc = $this->moiTep();

        Artisan::call('oohx:sinh-anh-dan-xuat', ['--soat' => true]);
        $ra = Artisan::output();

        $this->assertSame($truoc, $this->moiTep());
        $this->assertStringContainsString('SOÁT', $ra);
        $this->assertStringContainsString('2 tệp sẽ được sinh', $ra);
    }

    public function test_lenh_in_bang_gd_de_biet_production_co_gi(): void
    {
        Artisan::call('oohx:sinh-anh-dan-xuat', ['--soat' => true]);
        $ra = Artisan::output();

        foreach (['jpeg', 'png', 'webp', 'memory_limit'] as $can) {
            $this->assertStringContainsString($can, $ra);
        }

        // Và nó nói ra chỗ bản dẫn xuất nằm, để ai đọc đầu ra cũng biết con số
        // của `oohx:soat-anh-kho` không bị ảnh hưởng.
        $this->assertStringContainsString(AnhDanXuatService::THU_MUC, $ra);
    }

    public function test_lenh_chay_hai_lan_khong_sinh_them(): void
    {
        Storage::disk('public')->put('screen-photos/cu.jpg', $this->anhThat(1600, 900));

        $man = $this->manHinh();
        ScreenSpec::withoutEvents(fn () => $man->spec->update(['photos' => ['screen-photos/cu.jpg']]));

        Artisan::call('oohx:sinh-anh-dan-xuat');
        $tep = $this->moiTep();

        Artisan::call('oohx:sinh-anh-dan-xuat');
        $ra = Artisan::output();

        $this->assertSame($tep, $this->moiTep());
        $this->assertStringContainsString('0 tệp đã sinh', $ra);
    }

    public function test_gioi_han_dung_sau_dung_so_duong_dan(): void
    {
        Storage::disk('public')->put('screen-photos/a.jpg', $this->anhThat(1600, 900));
        Storage::disk('public')->put('screen-photos/b.jpg', $this->anhThat(1600, 900));

        $m1 = $this->manHinh();
        $m2 = $this->manHinh();
        ScreenSpec::withoutEvents(function () use ($m1, $m2) {
            $m1->spec->update(['photos' => ['screen-photos/a.jpg']]);
            $m2->spec->update(['photos' => ['screen-photos/b.jpg']]);
        });

        Artisan::call('oohx:sinh-anh-dan-xuat', ['--gioi-han' => 1]);
        $ra = Artisan::output();

        $this->assertStringContainsString('1 đường dẫn ảnh phân biệt', $ra);
        $this->assertStringContainsString('2 tệp đã sinh', $ra);
    }

    // ══ 6. Bất biến nối lát 0 ════════════════════════════════════════════

    public function test_con_so_cua_lat_0_khong_doi_sau_khi_sinh_ban_dan_xuat(): void
    {
        Storage::disk('public')->put('screen-photos/01k0/a.jpg', $this->anhThat(1600, 900));

        $man = $this->manHinh();
        ScreenSpec::withoutEvents(fn () => $man->spec->update(['photos' => ['screen-photos/01k0/a.jpg']]));

        $truoc = app(SoatAnhKhoService::class)->soat();

        Artisan::call('oohx:sinh-anh-dan-xuat');

        $sau = app(SoatAnhKhoService::class)->soat();

        // Một tệp trước, một tệp sau — dù trên đĩa nay có ba. Bản dẫn xuất
        // nằm trong `screen-photos` thì con số này thành 3, và báo cáo của lát
        // 0 lặng lẽ nói kho có gấp ba số ảnh nó có.
        $this->assertSame(1, $truoc['dia']['so_tep']);
        $this->assertSame(1, $sau['dia']['so_tep']);
        $this->assertSame($truoc['dia']['bytes'], $sau['dia']['bytes']);
        $this->assertSame(1, $sau['tong'][SoatAnhKhoService::CO_ANH]);
        $this->assertCount(3, $this->moiTep());
    }

    // ══ 7. Đường chạy thật — hai DTO ═════════════════════════════════════

    public function test_dto_chi_tiet_tra_srcset_cung_do_dai_voi_photo_urls(): void
    {
        Storage::disk('public')->put('screen-photos/a.jpg', $this->anhThat(1600, 900));
        Storage::disk('public')->put('screen-photos/b.jpg', $this->anhThat(300, 200));

        $man = $this->manHinh(['photos' => ['screen-photos/a.jpg', 'screen-photos/b.jpg']]);

        $data = $this->getJson('/api/v2/screens/' . $man->slug)->assertOk()->json('data');

        $this->assertCount(2, $data['photo_urls']);
        $this->assertCount(2, $data['photo_srcsets']);

        // Ảnh đầu 1600px có cả hai bản; ảnh thứ hai 300px không có bản nào —
        // và `null` phải ở ĐÚNG ô thứ hai.
        $this->assertStringContainsString('480w', (string) $data['photo_srcsets'][0]);
        $this->assertStringContainsString('1280w', (string) $data['photo_srcsets'][0]);
        $this->assertNull($data['photo_srcsets'][1]);
    }

    public function test_dto_danh_sach_tra_srcset_cua_dung_anh_dang_hien(): void
    {
        Storage::disk('public')->put('owners/covers/bia.jpg', $this->anhThat(1600, 900));

        $man = $this->manHinh(bia: 'owners/covers/bia.jpg');

        Artisan::call('oohx:sinh-anh-dan-xuat');

        $data = collect($this->getJson('/api/v2/screens')->assertOk()->json('data'))
            ->firstWhere('slug', $man->slug);

        $this->assertNotNull($data);

        // `photo_url` là bìa chủ sở hữu (bậc 4 của chuỗi dự phòng), nên
        // `photo_srcset` phải trỏ vào bản dẫn xuất của **đúng tệp đó**. Lấy
        // đường dẫn từ `spec.photos` thay vì từ bậc chuỗi dự phòng dừng ở là
        // một `srcset` trỏ vào ảnh khác hẳn.
        $this->assertStringContainsString('owners/covers/bia.jpg', (string) $data['photo_url']);
        $this->assertStringContainsString('w480/owners/covers/bia.jpg 480w', (string) $data['photo_srcset']);
    }

    public function test_dto_danh_sach_luon_co_khoa_srcset_du_chua_sinh(): void
    {
        $man = $this->manHinh();

        $data = collect($this->getJson('/api/v2/screens')->assertOk()->json('data'))
            ->firstWhere('slug', $man->slug);

        // Khoá luôn có mặt, giá trị `null`. Một khoá biến mất tuỳ dữ liệu buộc
        // mỗi client viết hai nhánh, và `openapi-typescript` sinh ra một kiểu
        // không nói được chuyện đó.
        $this->assertArrayHasKey('photo_srcset', $data);
        $this->assertNull($data['photo_srcset']);
    }

    // ══ Dựng dữ liệu ═════════════════════════════════════════════════════

    /**
     * Một màn hình công khai — cùng hình dạng với `SoatAnhKhoTest::manHinh()`.
     *
     * @param  array<string, mixed>  $spec
     */
    private function manHinh(array $spec = [], ?string $bia = null, ?Owner $owner = null): Screen
    {
        $owner ??= Owner::factory()->create(['status' => 'active', 'cover_url' => $bia]);

        // `banner` null và không `network_id`: chuỗi dự phòng phải chạy hết
        // tới bậc 4 (bìa chủ sở hữu) — bậc mà 104/104 vị trí production đang
        // dừng ở. Một banner địa điểm lẻn vào đây làm mọi ca "bìa owner" đo
        // bậc 2 mà vẫn xanh.
        $site = Site::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'active',
            'city'     => 'Hà Nội',
            'banner'   => null,
        ]);

        $man = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenSpec::factory()->create(array_merge([
            'screen_id' => $man->id,
            'photos'    => null,
            'photo_url' => null,
        ], $spec));

        ScreenInventory::factory()->create([
            'screen_id'      => $man->id,
            'vn_category_id' => null,
        ]);

        return $man->fresh();
    }
}
