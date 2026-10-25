<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ScreenResource;
use App\Models\Screen;
use App\Rules\AnhKhongQuaNhieuDiem;
use App\Services\AnhDanXuatService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Trần **số điểm ảnh** ở đường tải ảnh — nửa còn lại của `chặn ảnh bom`.
 *
 * ══ Lỗ hổng này đã được ghi ra và để mở hai lần ══
 *
 * Lát 2 của `docs/PLAN-ANH-VI-TRI.md` ghi: *"một tệp 8MB vẫn có thể là ảnh
 * 20.000×20.000. Hôm nay không mã nào phía máy chủ giải nén những ảnh này nên
 * nó chưa gây hại — lát 4 là lúc phải đóng."* Lát 4 đóng **một nửa**: lớp
 * `AnhDanXuatService` đo kích thước từ đầu tệp và từ chối trên trần, nên máy
 * chủ an toàn.
 *
 * Nửa còn lại là chiều mà cả hai lát trước chưa nói tới: **ảnh bom vẫn vào kho
 * và ra trang**. Không sinh được bản dẫn xuất nghĩa là `srcset` vắng mặt,
 * nghĩa là **mọi** trình duyệt tải đúng bản gốc. Lớp dưới hỏng theo chiều an
 * toàn cho máy chủ và theo chiều nguy hiểm cho người xem — hai chiều trái
 * nhau, nên nó không phải một lớp phòng thủ đủ.
 *
 * ══ Vì sao chốt phải đi qua `TemporaryUploadedFile`, không phải `UploadedFile` ══
 *
 * `BaseFileUpload::getValidationRules()` (dòng 608-617 của vendor) **lọc** giá
 * trị: `array_filter($value, fn ($file) => $file instanceof TemporaryUploadedFile)`.
 * Một ca dùng `UploadedFile::fake()->image(...)` đi qua bộ lọc ấy thành **mảng
 * rỗng**, không luật nào chạy, và ca xanh trong khi không đo gì cả. Có một ca
 * ở dưới ghim đúng cái bẫy đó, để ai đổi fixture sang `UploadedFile` thì biết
 * ngay thay vì tin một màu xanh rỗng.
 *
 * ══ Phạm vi: mười bốn ô, không phải một ══
 *
 * Quyết định `chặn ảnh bom` nói về ô ảnh màn hình. Nhưng ảnh bìa chủ sở hữu
 * **là** ảnh mà 104/104 vị trí đang hiện ra, và nó vào qua một ô khác; ảnh
 * thumbnail loại địa điểm và ảnh tỉnh còn đặt `maxSize(51200)` — 50MB. Chặn
 * một ô trong mười bốn thì không phải chặn. Nên luật áp cho **mọi** ô
 * `->image()` trong `app/Filament`, và có một bộ quét đòi đúng điều đó, để ô
 * thứ mười lăm thêm sau này không lọt.
 */
class AnhBomTest extends TestCase
{
    /** Trần tiêm vào cho các ca đo: 1 triệu điểm, để không phải dựng ảnh khổng lồ. */
    private const TRAN_THU = 1_000_000;

    // ══ 1. Luật, đo riêng ═════════════════════════════════════════════════

    public function test_anh_nho_di_qua(): void
    {
        $loi = $this->chayLuat($this->tepAnh(400, 300), self::TRAN_THU);

        $this->assertSame([], $loi);
    }

    public function test_anh_vuot_tran_bi_tu_choi_va_cau_loi_noi_ca_hai_con_so(): void
    {
        // PNG khai kích thước, không dựng ảnh thật: luật chỉ đọc header, nên
        // một fixture 45 byte đo được đúng điều ca này nói.
        $loi = $this->chayLuat($this->tepAnhBom(2000, 1500), self::TRAN_THU);

        $this->assertCount(1, $loi);

        // Câu lỗi phải nói kích thước THẬT của ảnh và trần — người tải lên
        // không có cách nào khác để biết mình phải xuất lại ảnh bao nhiêu.
        $this->assertStringContainsString('2.000×1.500', $loi[0]);
        $this->assertStringContainsString('triệu điểm', $loi[0]);
        $this->assertStringContainsString('2000px', $loi[0]);
    }

    /**
     * Cái chốt thật của tệp này: **dung lượng tệp và số điểm ảnh là hai thang
     * khác nhau**, và đó là toàn bộ lý do `maxSize` không đủ.
     */
    public function test_tep_be_ma_anh_lon_van_bi_tu_choi(): void
    {
        $tep = $this->tepAnh(2000, 2000);

        // Một PNG 2000×2000 toàn một màu nén xuống vài KB: nén lossless xoá
        // sạch mọi vùng phẳng. Ở 20.000×20.000 thì tệp vẫn dưới 8MB mà ảnh là
        // 400 triệu điểm — 1,6GB trong bộ nhớ GD.
        $this->assertLessThan(
            100 * 1024,
            $tep->getSize(),
            'Fixture phải là một tệp NHỎ, nếu không nó không đo được điều ca này nói.',
        );

        $loi = $this->chayLuat($tep, self::TRAN_THU);

        $this->assertCount(1, $loi, 'Tệp 4 triệu điểm phải bị từ chối dù chỉ nặng vài KB.');
    }

    public function test_gia_tri_khong_phai_tep_thi_luat_khong_noi_gi(): void
    {
        // `file`, `mimetypes:` và cổng upload tạm của Livewire đã lo. Một luật
        // trả lời thêm câu hỏi của luật khác là hai chỗ nói về cùng một thứ,
        // và hai chỗ sẽ lệch.
        foreach ([null, '', 'screen-photos/a.jpg', [], 42] as $gt) {
            $this->assertSame([], $this->chayLuat($gt, self::TRAN_THU), 'Giá trị: ' . var_export($gt, true));
        }
    }

    public function test_tep_khong_do_duoc_kich_thuoc_bi_tu_choi(): void
    {
        // Tới được luật này nghĩa là `mimetypes:` đã nhận tệp theo **nội
        // dung**, nên một tệp không đo được kích thước là tệp hỏng hoặc tải
        // lên chưa xong. Cho qua là cho qua một ảnh không chặn được.
        $duong = tempnam(sys_get_temp_dir(), 'rac') . '.jpg';
        File::put($duong, 'day khong phai anh');

        $loi = $this->chayLuat(new UploadedFile($duong, 'a.jpg', 'image/jpeg', null, true), self::TRAN_THU);

        $this->assertCount(1, $loi);
        $this->assertStringContainsString('Không đọc được kích thước', $loi[0]);

        @unlink($duong);
    }

    // ══ 2. Trần của biểu mẫu và trần của máy chủ là MỘT ═══════════════════

    public function test_tran_mac_dinh_doc_dung_hang_cua_service(): void
    {
        // Biểu mẫu nhận ⟹ trần cố định của service cho qua. Hai con số riêng
        // là hai con số sẽ lệch: biểu mẫu rộng hơn thì ảnh vào kho mà vĩnh
        // viễn không có bản dẫn xuất và không ai biết; biểu mẫu hẹp hơn thì
        // người dùng bị từ chối một ảnh hệ thống xử lý được.
        $ngayDuoi = AnhDanXuatService::TOI_DA_PIXEL;

        $r = new \ReflectionClass(AnhKhongQuaNhieuDiem::class);
        $tran = $r->getConstructor()->getParameters()[0];

        $this->assertTrue($tran->isDefaultValueAvailable());
        $this->assertSame($ngayDuoi, $tran->getDefaultValue());
    }

    public function test_anh_bieu_mau_cho_qua_thi_service_khong_goi_la_qua_lon(): void
    {
        Storage::fake('public');

        // Một ảnh ngay dưới trần theo một chiều: 3000×1000 = 3 triệu điểm, dưới
        // cả 12 triệu. Luật cho qua, và service phải sinh được — nếu không thì
        // hai trần đã lệch.
        $tep = $this->tepAnh(3000, 1000);

        $this->assertSame([], $this->chayLuat($tep));

        Storage::disk('public')->put('screen-photos/a.jpg', File::get($tep->getRealPath()));
        $kq = (new AnhDanXuatService)->sinhCho('screen-photos/a.jpg');

        $this->assertNotSame(AnhDanXuatService::KQ_QUA_LON, $kq['ket_qua']);
    }

    // ══ 3. Qua đúng đường Filament chạy ══════════════════════════════════

    public function test_o_anh_that_tu_choi_anh_qua_nhieu_diem(): void
    {
        $tep = $this->tepBom(20_000, 20_000);

        // 45 byte khai 400 triệu điểm. `maxSize(8192)` cho qua không chút do
        // dự — đó chính là lý do trần dung lượng không chặn được ảnh bom.
        $this->assertLessThan(1024, $tep->getSize());

        $loi = $this->chayLuatCuaO($tep);

        $this->assertNotSame([], $loi, 'Ô thật phải từ chối ảnh 400 triệu điểm.');
        $this->assertStringContainsString('quá lớn', implode(' ', $loi));
    }

    public function test_o_anh_that_nhan_anh_binh_thuong(): void
    {
        $this->assertSame([], $this->chayLuatCuaO($this->tepTam(1600, 900)));
    }

    /**
     * ══ Tên ô không được có dấu chấm, và đó là một lỗi đã sống trên production ══
     *
     * `BaseFileUpload::getValidationRules()` dựng `Validator` với khoá
     * `"{getName()}.*"`. Tên `spec.photos` làm khoá thành `spec.photos.*`, và
     * Laravel đọc dấu chấm là **mảng lồng** — nó tìm `spec` → `photos`, trong
     * khi dữ liệu có đúng một khoá phẳng `'spec.photos'`. Không khớp, nên
     * **không luật từng tệp nào chạy**. Đo thật 25/10/2026: khoá có dấu chấm
     * `fails: 0`, khoá không dấu chấm `fails: 1`.
     *
     * Nên `acceptedFileTypes` và `maxSize` của lát 2 đã bất động từ đầu. Ca
     * này canh cái tên; hai ca dưới canh hai luật đó **thật sự chạy**.
     */
    public function test_ten_o_khong_duoc_co_dau_cham(): void
    {
        $o = $this->oAnh();

        $this->assertStringNotContainsString('.', $o->getName());

        // Và state path vẫn phải có dấu chấm: đường nạp
        // (`mutateFormDataBeforeFill`) và đường lưu (`SavesScreenRelationships`
        // đọc `$data['spec']`) dựa vào nó, không dựa vào tên.
        $this->assertSame('spec.photos', $o->getStatePath(false));
    }

    public function test_o_anh_that_tu_choi_tep_vuot_8mb(): void
    {
        // `maxSize(8192)` của lát 2, lần đầu thật sự chạy. 9.000.000 byte =
        // 8.789 KB, trên trần 8.192 KB.
        //
        // Tên tệp mang `-size=`: `TemporaryUploadedFile::getSize()` đọc con số
        // ấy khi đang chạy test (vendor dòng 46-50), nên không phải ghi 9MB ra
        // đĩa chỉ để đo một con số.
        $loi = $this->chayLuatCuaO($this->tepTam(400, 300, 'anh-size=9000000.png'));

        $this->assertNotSame([], $loi, 'maxSize(8192) phải từ chối một tệp 8,6MB.');
    }

    public function test_o_anh_that_tu_choi_dinh_dang_ngoai_ba_loai(): void
    {
        // `acceptedFileTypes(['image/jpeg','image/png','image/webp'])` của lát
        // 2, cũng lần đầu thật sự chạy. GIF là một ảnh thật, hợp lệ, và **không**
        // nằm trong ba loại — nên nó phân biệt được "luật có chạy" với "tệp bị
        // từ chối vì không phải ảnh".
        $loi = $this->chayLuatCuaO($this->tepGif());

        $this->assertNotSame([], $loi, 'acceptedFileTypes phải từ chối GIF.');
    }

    /**
     * Ghim cái bẫy làm một ca thành xanh rỗng.
     *
     * `BaseFileUpload` lọc giá trị xuống còn `TemporaryUploadedFile`. Nên hai
     * ca trên **chỉ** đo được gì đó khi fixture là loại ấy; đổi sang
     * `UploadedFile::fake()` thì chúng xanh mà không chạy luật nào.
     */
    public function test_upload_file_thuong_bi_filament_bo_qua_hoan_toan(): void
    {
        $this->assertSame(
            [],
            $this->chayLuatCuaO($this->tepAnhBom(20_000, 20_000)),
            'Nếu ca này ĐỎ thì Filament đã thôi lọc theo TemporaryUploadedFile, '
            . 'và lời giải thích ở đầu tệp này cần viết lại.',
        );

        // Và cùng ảnh đó, đúng loại, thì bị từ chối — hai nửa của một phép đo.
        $this->assertNotSame([], $this->chayLuatCuaO($this->tepBom(20_000, 20_000)));
    }

    // ══ 4. Bộ quét: mười bốn ô, không bỏ ô nào ═══════════════════════════

    public function test_moi_o_tai_anh_trong_filament_deu_co_tran_diem_anh(): void
    {
        $soO = 0;
        $soTep = 0;

        foreach ($this->tepFilament() as $tep) {
            // `php_strip_whitespace`, KHÔNG regex bỏ chú thích: bộ quét của
            // lát 2 từng im lặng quét thiếu vì `image/*` trong một docblock mở
            // một chú thích giả. Và chính `BaseScreenResource` có `->image()`
            // trong docblock.
            $ma = php_strip_whitespace($tep->getRealPath());

            $anh = substr_count($ma, '->image()');

            if ($anh === 0) {
                continue;
            }

            $luat = substr_count($ma, 'new AnhKhongQuaNhieuDiem');

            $this->assertSame(
                $anh,
                $luat,
                $tep->getRelativePathname() . " có {$anh} ô `->image()` nhưng {$luat} lần "
                . '`new AnhKhongQuaNhieuDiem`. Mọi ô tải ảnh phải có trần số điểm ảnh: '
                . 'dung lượng tệp không chặn được một PNG 20.000×20.000.',
            );

            $soO += $anh;
            $soTep++;
        }

        // Bộ quét không rỗng. Thiếu lưới này thì một lượt đổi `->image()` sang
        // một cách viết khác làm bộ quét đếm 0 ô và xanh mãi mãi.
        $this->assertGreaterThanOrEqual(15, $soO, 'Bộ quét không thấy đủ ô tải ảnh — nó còn quét đúng chỗ không?');
        $this->assertGreaterThanOrEqual(8, $soTep);
    }

    // ══ Dựng dữ liệu ═════════════════════════════════════════════════════

    /** @return list<SplFileInfo> */
    private function tepFilament(): array
    {
        return array_values(array_filter(
            File::allFiles(app_path('Filament')),
            fn (SplFileInfo $t) => $t->getExtension() === 'php',
        ));
    }

    /**
     * Byte của một PNG đặc một màu — nén lossless nên tệp rất nhẹ.
     *
     * `imagecreate()` (bảng màu, **1 byte** một điểm), không
     * `imagecreatetruecolor()` (4 byte): một ảnh 5000×4000 truecolor là 80MB
     * trong GD, và `memory_limit` của tiến trình test là 128M. Đó đúng là cái
     * bẫy đã làm đỏ một ca của lát 4 — một ca test về bộ nhớ không được tự nó
     * tốn đúng số bộ nhớ ấy.
     */
    private function byteAnh(int $w, int $h): string
    {
        $im = imagecreate($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 10, 120, 200));

        ob_start();
        imagepng($im);
        $byte = (string) ob_get_clean();
        imagedestroy($im);

        return $byte;
    }

    /** Một `UploadedFile` thường — đủ cho các ca đo riêng luật. */
    private function tepAnh(int $w, int $h): UploadedFile
    {
        $duong = tempnam(sys_get_temp_dir(), 'anh') . '.png';
        File::put($duong, $this->byteAnh($w, $h));

        return new UploadedFile($duong, 'anh.png', 'image/png', null, true);
    }

    /**
     * Một PNG **chỉ có header khai kích thước** — 45 byte khai 400 triệu điểm.
     *
     * ══ Vì sao không dựng ảnh thật ══
     *
     * Bản đầu của tệp này dùng `imagecreate(5000, 4000)` — bảng màu, 1 byte
     * một điểm, nên 20MB. Chạy riêng thì xanh; chạy cùng sáu lớp chốt khác thì
     * **OOM thật**: `Allowed memory size of 134217728 bytes exhausted`. Một ca
     * test về ảnh bom không được tự nó là một ảnh bom — tôi đã viết đúng câu
     * đó vào docblock của `byteAnh()` rồi vẫn sập.
     *
     * ══ Và đây là fixture ĐÚNG hơn ══
     *
     * `getimagesize()` chỉ đọc chunk `IHDR`, nên một ảnh bom thật **không cần**
     * dữ liệu điểm ảnh hợp lệ để khai 20.000×20.000. Người tấn công cũng
     * không gửi 1,6GB — họ gửi vài chục byte và để máy chủ tự cấp phát. Nên
     * fixture này gần hình dạng thật hơn bản cũ, không chỉ nhẹ hơn.
     *
     * `finfo` nhận nó là `image/png` (đo thật), nên `mimetypes:` của lát 2 vẫn
     * cho qua và luật của lát này mới là chỗ chặn — đúng thứ tự cần đo.
     */
    private function byteBom(int $w, int $h): string
    {
        $ihdr = pack('N', $w) . pack('N', $h)
            . chr(8)   // bit depth
            . chr(2)   // color type: truecolor
            . chr(0) . chr(0) . chr(0);

        return "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
            . pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
    }

    private function tepAnhBom(int $w, int $h): UploadedFile
    {
        $duong = tempnam(sys_get_temp_dir(), 'bom') . '.png';
        File::put($duong, $this->byteBom($w, $h));

        return new UploadedFile($duong, 'bom.png', 'image/png', null, true);
    }

    private function tepBom(int $w, int $h): TemporaryUploadedFile
    {
        Storage::fake('tmp-for-tests');
        Storage::disk('tmp-for-tests')->put('livewire-tmp/bom.png', $this->byteBom($w, $h));

        return new TemporaryUploadedFile('bom.png', 'tmp-for-tests');
    }

    /**
     * Một `TemporaryUploadedFile` thật — loại duy nhất Filament thực sự kiểm.
     *
     * `FileUploadConfiguration::disk()` trả `tmp-for-tests` khi đang chạy test,
     * và không gói nào khai disk đó, nên test phải tự dựng nó.
     */
    private function tepTam(int $w, int $h, string $ten = 'anh.png'): TemporaryUploadedFile
    {
        Storage::fake('tmp-for-tests');
        Storage::disk('tmp-for-tests')->put('livewire-tmp/' . $ten, $this->byteAnh($w, $h));

        return new TemporaryUploadedFile($ten, 'tmp-for-tests');
    }

    /** Một GIF thật — ảnh hợp lệ, ngoài ba loại được nhận. */
    private function tepGif(): TemporaryUploadedFile
    {
        $im = imagecreate(40, 30);
        imagefilledrectangle($im, 0, 0, 39, 29, imagecolorallocate($im, 10, 120, 200));
        ob_start();
        imagegif($im);
        $byte = (string) ob_get_clean();
        imagedestroy($im);

        Storage::fake('tmp-for-tests');
        Storage::disk('tmp-for-tests')->put('livewire-tmp/anh.gif', $byte);

        return new TemporaryUploadedFile('anh.gif', 'tmp-for-tests');
    }

    /**
     * Chạy **một** luật và thu câu lỗi.
     *
     * @return list<string>
     */
    private function chayLuat(mixed $gt, ?int $tran = null): array
    {
        $loi = [];

        $luat = $tran === null ? new AnhKhongQuaNhieuDiem : new AnhKhongQuaNhieuDiem($tran);
        $luat->validate('photos.0', $gt, function (string $m) use (&$loi) {
            $loi[] = $m;
        });

        return $loi;
    }

    /**
     * Chạy **đúng** bộ luật mà Filament dựng cho ô ảnh màn hình.
     *
     * `getValidationRules()` của `BaseFileUpload` trả về một closure tự dựng
     * `Validator` với khoá `"{$name}.*"`. Gọi closure ấy là đi qua cả
     * `mimetypes:`, `max:` và luật của lát này — tức đúng con đường một lượt
     * lưu biểu mẫu đi.
     *
     * @return list<string>
     */
    private function chayLuatCuaO(mixed $tep): array
    {
        $chu = new class extends \Livewire\Component implements \Filament\Forms\Contracts\HasForms
        {
            use \Filament\Forms\Concerns\InteractsWithForms;

            public array $data = [];

            public function render()
            {
                return '';
            }
        };

        $form = \Filament\Forms\Form::make($chu)
            ->statePath('data')
            ->model(new Screen)
            ->schema([$this->oAnh()]);

        $o = $form->getComponents()[0];

        $loi = [];

        foreach ($o->getValidationRules() as $luat) {
            if (! $luat instanceof \Closure) {
                continue;
            }

            $luat('data.spec.photos', [$tep], function (string $m) use (&$loi) {
                $loi[] = $m;
            });
        }

        return $loi;
    }

    /** `oAnhManHinh()` là `protected static` — cùng cách gọi với chốt của lát 2. */
    private function oAnh(): \Filament\Forms\Components\FileUpload
    {
        return \Closure::bind(
            fn () => ScreenResource::oAnhManHinh(),
            null,
            ScreenResource::class,
        )();
    }
}
