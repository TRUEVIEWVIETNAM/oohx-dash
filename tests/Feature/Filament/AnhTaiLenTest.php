<?php

namespace Tests\Feature\Filament;

use App\Filament\Publisher\Resources\ScreenResource;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Models\User;
use App\Services\SoatAnhKhoService;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Đường tải ảnh của màn hình — lát 2 của `docs/PLAN-ANH-VI-TRI.md`.
 *
 * ══ Cái gì ở máy chủ, cái gì chỉ ở trình duyệt ══
 *
 * Ba hạn mức được chốt, và chúng **không** cùng một hạng:
 *
 *  - `maxSize(8192)` → luật `max:8192` (KB) — **máy chủ**.
 *  - `acceptedFileTypes([…])` → luật `mimetypes:…` đọc nội dung tệp — **máy chủ**.
 *  - `imageResize*` → tham số truyền xuống FilePond — **trình duyệt**. Không
 *    có lớp xử lý ảnh nào phía máy chủ trong `filament/forms`.
 *
 * Nên tệp này canh hai cái đầu bằng một `Validator` thật với tệp thật, và chỉ
 * canh *khai báo* của cái thứ ba. Một ca khẳng định ảnh 4000px "đã bị thu nhỏ"
 * sẽ là một ca nói sai về hệ thống.
 *
 * ══ Vì sao không dựng cả biểu mẫu Filament ══
 *
 * `getDirectory()` gọi `evaluate()`, và `evaluate()` của một form component
 * tra `record` qua `getContainer()`. Không có container thì nó nổ, nên không
 * dựng được ô này rời khỏi một biểu mẫu sống. Thay vì giả lập cả tầng
 * Livewire, phần nghiệp vụ được đẩy xuống hai hàm thuần (`thuMucAnh`,
 * `ownerIdChoAnh`) và kiểm trực tiếp — còn thứ **duy nhất** nối chúng với
 * Filament là tên tham số của closure, nên có một ca riêng quét đúng tên ấy.
 */
class AnhTaiLenTest extends TestCase
{
    use RefreshDatabase;

    /** Ô tải ảnh thật mà biểu mẫu dùng. `protected static` nên phải bind. */
    private function o(): FileUpload
    {
        return \Closure::bind(
            fn () => ScreenResource::oAnhManHinh(),
            null,
            ScreenResource::class,
        )();
    }

    private function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }

    // ══ thuMucAnh(): một chỗ dựng đường dẫn ═══════════════════════════════

    public function test_owner_id_thanh_thu_muc_rieng(): void
    {
        $id = $this->ulid();

        $this->assertSame("screen-photos/{$id}", SoatAnhKhoService::thuMucAnh($id));
    }

    public function test_hai_chu_so_huu_khong_dung_chung_thu_muc(): void
    {
        $this->assertNotSame(
            SoatAnhKhoService::thuMucAnh($this->ulid()),
            SoatAnhKhoService::thuMucAnh($this->ulid()),
            'Chia theo chủ sở hữu mà hai chủ ra cùng một thư mục.',
        );
    }

    public function test_chu_hoa_va_chu_thuong_ve_cung_mot_thu_muc(): void
    {
        $id = $this->ulid();

        $this->assertSame(
            SoatAnhKhoService::thuMucAnh($id),
            SoatAnhKhoService::thuMucAnh(strtoupper($id)),
            'Thư mục trên Linux phân biệt chữ hoa — không chuẩn hoá thì một chủ có hai thư mục.',
        );
    }

    public function test_thu_muc_viet_chu_thuong_nhu_trong_csdl(): void
    {
        // `HasUlids::newUniqueId()` của Laravel gọi `strtolower()`, nên
        // `owners.id` là chữ thường. Thư mục viết hoa thì không ai đi từ một
        // dòng `ls` sang CSDL được.
        $id = $this->ulid();

        $this->assertSame("screen-photos/{$id}", SoatAnhKhoService::thuMucAnh(strtoupper($id)));
    }

    public function test_moi_hinh_dang_khac_ve_thung_chung(): void
    {
        $khongDung = [
            'null'                  => null,
            'rỗng'                  => '',
            'khoảng trắng'          => '   ',
            'đi ra ngoài'           => '../../../etc',
            'có dấu gạch chéo'      => '01KP5QP03FDDC0HJGWG2FP2T4/',
            'ngắn một ký tự'        => '01KP5QP03FDDC0HJGWG2FP2T4',
            'dài một ký tự'         => '01KP5QP03FDDC0HJGWG2FP2T422',
            'chữ I ngoài Crockford' => '01KP5QP03FDDC0HJGWG2FP2TI2',
            'chữ O ngoài Crockford' => '01KP5QP03FDDC0HJGWG2FP2TO2',
            'có byte NUL'           => "01KP5QP03FDDC0HJGWG2FP2T4\0",
        ];

        foreach ($khongDung as $ten => $xau) {
            $duong = SoatAnhKhoService::thuMucAnh($xau);

            $this->assertSame('screen-photos/chua-ro-chu', $duong, $ten);
            $this->assertStringNotContainsString('..', $duong, "{$ten}: ra khỏi disk công khai");
        }
    }

    public function test_thung_chung_nam_trong_thu_muc_anh(): void
    {
        // Lát 0 đo đĩa bằng `allFiles(THU_MUC_ANH)`. Thùng chung nằm ngoài đó
        // thì ảnh trong nó không bao giờ được đếm.
        $this->assertStringStartsWith(
            SoatAnhKhoService::THU_MUC_ANH . '/',
            SoatAnhKhoService::thuMucAnh(null),
        );
    }

    // ══ ownerIdChoAnh(): ba đường, theo thứ tự ════════════════════════════

    private function ownerIdChoAnh(?Screen $man, callable $get): ?string
    {
        return \Closure::bind(
            fn () => ScreenResource::ownerIdChoAnh($man, $get),
            null,
            ScreenResource::class,
        )();
    }

    private function manHinhCuaOwner(Owner $chu): Screen
    {
        $diaDiem = Site::factory()->create(['owner_id' => $chu->id, 'status' => 'active']);

        return Screen::factory()->create([
            'owner_id' => $chu->id,
            'site_id'  => $diaDiem->id,
            'active'   => true,
        ]);
    }

    public function test_ban_ghi_da_co_thang_ca_trang_thai_bieu_mau(): void
    {
        $chu = Owner::factory()->create(['status' => 'active']);
        $man = $this->manHinhCuaOwner($chu);

        // Biểu mẫu nói một chủ khác — bản ghi trong CSDL mới là sự thật.
        $khac = $this->ulid();

        $this->assertSame($chu->id, $this->ownerIdChoAnh($man, fn () => $khac));
    }

    public function test_tao_moi_o_admin_doc_o_chon_chu_so_huu(): void
    {
        $chu = Owner::factory()->create(['status' => 'active']);

        $this->assertSame(
            $chu->id,
            $this->ownerIdChoAnh(null, fn ($khoa) => $khoa === 'owner_id' ? $chu->id : null),
        );
    }

    public function test_tao_moi_o_publisher_doc_tenant_dang_dang_nhap(): void
    {
        Role::firstOrCreate(['name' => 'publisher', 'guard_name' => 'web']);
        $chu = Owner::factory()->create(['status' => 'active']);

        $this->actingAs(User::factory()->create(['current_owner_id' => $chu->id]));

        // `/publisher` không có ô `owner_id` trong schema, nên `$get` trả null.
        $this->assertSame($chu->id, $this->ownerIdChoAnh(null, fn () => null));
    }

    public function test_khong_biet_duoc_thi_tra_null_chu_khong_doan(): void
    {
        $this->assertNull($this->ownerIdChoAnh(null, fn () => null));
    }

    public function test_cung_mot_cot_tenant_voi_hang_rao_truy_van(): void
    {
        // `HasOwnerScope` thu hẹp mọi truy vấn bằng `current_owner_id`. Nếu
        // đường ảnh đọc một định nghĩa tenant khác, ảnh của người này vào
        // thư mục của người kia.
        $this->assertStringContainsString(
            'current_owner_id',
            File::get(base_path('app/Traits/HasOwnerScope.php')),
        );
        $this->assertStringContainsString(
            'current_owner_id',
            File::get(base_path('app/Filament/Shared/Resources/BaseScreenResource.php')),
        );
    }

    // ══ Khai báo của ô tải ảnh ════════════════════════════════════════════

    public function test_ba_han_muc_da_chot(): void
    {
        $o = $this->o();

        $this->assertSame(10, $o->getMaxFiles(), 'hạn mức 10 ảnh');
        $this->assertSame(8192, $o->getMaxSize(), 'hạn mức 8MB, tính bằng KB');
        $this->assertSame('2000', $o->getImageResizeTargetWidth());
        $this->assertSame('2000', $o->getImageResizeTargetHeight());
    }

    public function test_chi_ba_loai_anh_va_khong_co_svg(): void
    {
        $loai = $this->o()->getAcceptedFileTypes();

        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], $loai);

        // `->image()` đặt `image/*`, và `image/*` nhận cả `image/svg+xml` —
        // svg chạy được script, và ảnh này đi vào disk CÔNG KHAI.
        $this->assertNotContains('image/*', $loai);
        $this->assertNotContains('image/svg+xml', $loai);
    }

    public function test_thu_nho_vua_khung_chu_khong_cat_va_khong_keo_len(): void
    {
        $o = $this->o();

        $this->assertSame('contain', $o->getImageResizeMode(), 'cắt ảnh biển quảng cáo là cắt mất nội dung');
        $this->assertFalse($o->getImageResizeUpscale(), 'mặc định của Filament là true: ảnh 300px bị kéo lên 2000px');
    }

    public function test_anh_vao_disk_cong_khai(): void
    {
        $this->assertSame('public', $this->o()->getDiskName());
    }

    public function test_helper_text_noi_du_ba_con_so(): void
    {
        // Người tải ảnh phải biết hạn mức TRƯỚC khi chọn 10 tệp 20MB và chờ.
        $chu = (string) $this->o()->getHelperText();

        foreach (['10', '8MB', '2000px'] as $so) {
            $this->assertStringContainsString($so, $chu, "helperText không nói {$so}");
        }
    }

    // ══ Hai hạn mức máy chủ, kiểm bằng tệp thật ═══════════════════════════

    /**
     * Đúng hai luật mà `acceptedFileTypes()` và `maxSize()` đăng ký.
     *
     * Dựng lại từ giá trị mà component trả về, chứ không viết lại con số: một
     * ngày ai đổi `maxSize` thì các ca dưới đây đổi theo, và ca
     * `test_ba_han_muc_da_chot` là chỗ con số bị ghim.
     *
     * @return list<string>
     */
    private function luatMayChu(): array
    {
        $o = $this->o();

        return [
            'file',
            'mimetypes:' . implode(',', $o->getAcceptedFileTypes() ?? []),
            'max:' . $o->getMaxSize(),
        ];
    }

    private function quaDuoc(UploadedFile $tep): bool
    {
        return Validator::make(['f' => $tep], ['f' => $this->luatMayChu()])->passes();
    }

    /** Tệp thật trên đĩa, để MIME được đoán theo NỘI DUNG chứ không theo tên. */
    private function tepThat(string $ten, string $noiDung): UploadedFile
    {
        $duong = tempnam(sys_get_temp_dir(), 'anh');
        file_put_contents($duong, $noiDung);

        return new UploadedFile($duong, $ten, null, null, true);
    }

    public function test_nhan_jpg_va_png(): void
    {
        $this->assertTrue($this->quaDuoc(UploadedFile::fake()->image('a.jpg')));
        $this->assertTrue($this->quaDuoc(UploadedFile::fake()->image('a.png')));
    }

    public function test_nhan_webp(): void
    {
        // WebP không dựng được bằng `fake()->image()`; 'RIFF' + cỡ + 'WEBPVP8 '
        // là đủ để `finfo` nhận ra. Có ca riêng vì webp là định dạng mà lát 4
        // sẽ sinh bản dẫn xuất — nó bị từ chối ở đây thì lát 4 không có đường.
        $webp = 'RIFF' . pack('V', 32) . 'WEBPVP8 ' . str_repeat("\0", 24);

        $this->assertTrue($this->quaDuoc($this->tepThat('a.webp', $webp)), 'webp bị từ chối');
    }

    public function test_tu_choi_svg_doi_duoi_thanh_jpg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->assertFalse($this->quaDuoc($this->tepThat('logo.jpg', $svg)));
    }

    public function test_tu_choi_php_doi_duoi_thanh_jpg(): void
    {
        $shell = '<?php echo shell_exec($_GET["cmd"]); ?>';

        $this->assertFalse($this->quaDuoc($this->tepThat('logo.jpg', $shell)));
    }

    public function test_tu_choi_gif_vi_ngoai_ba_loai(): void
    {
        // `image/*` nhận gif; ba loại đã chốt thì không. Ca này canh việc danh
        // sách thật sự HẸP hơn `image/*`, không chỉ viết khác chữ.
        $this->assertFalse($this->quaDuoc(UploadedFile::fake()->image('a.gif')));
    }

    public function test_tu_choi_anh_9mb_va_nhan_anh_7mb(): void
    {
        // Hạn mức của Laravel tính bằng KB. Ai viết `maxSize(8)` nghĩ là MB
        // thì ca "nhận 7MB" đỏ; ai viết `maxSize(8192000)` thì ca "từ chối
        // 9MB" đỏ. Hai chiều cùng ghim đúng một đơn vị.
        $this->assertFalse($this->quaDuoc(UploadedFile::fake()->image('to.jpg')->size(9 * 1024)));
        $this->assertTrue($this->quaDuoc(UploadedFile::fake()->image('vua.jpg')->size(7 * 1024)));
    }

    public function test_han_muc_nam_duoi_cong_upload_tam_cua_livewire(): void
    {
        // Cổng `config/livewire.php` chạy TRƯỚC validation của biểu mẫu. Nếu
        // `maxSize` lớn hơn trần của cổng ấy thì người dùng nhận câu lỗi của
        // cổng, và câu lỗi ở đó không nói gì về ảnh.
        $tranCong = null;

        foreach ((array) config('livewire.temporary_file_upload.rules') as $mot) {
            if (is_string($mot) && str_starts_with($mot, 'max:')) {
                $tranCong = (int) substr($mot, 4);
            }
        }

        $this->assertNotNull($tranCong, 'Cổng upload tạm không còn trần dung lượng.');
        $this->assertLessThanOrEqual(
            $tranCong,
            $this->o()->getMaxSize(),
            'maxSize của biểu mẫu vượt trần của cổng upload tạm.',
        );
    }

    // ══ Tên tham số closure: thứ duy nhất nối hai hàm thuần với Filament ══

    public function test_closure_directory_dat_dung_ten_tham_so(): void
    {
        // `Component::resolveDefaultClosureDependencyForEvaluationByName()`
        // tra theo TÊN. Tên khác thì Filament rơi xuống `app()->make(...)` và
        // dựng một đối tượng MỚI: `$record` thành một Screen trắng, `$get`
        // thành một thứ không đọc được biểu mẫu. Ảnh im lặng vào thùng chung,
        // hoặc vào thư mục của người khác. `CLAUDE.md` mục 4 ghi cái bẫy này,
        // và lượt đột biến thứ năm của lát 3 đã đi qua được cả 12 ca.
        $choPhep = ['record', 'get', 'set', 'state', 'livewire', 'model', 'operation', 'context', 'component'];
        $thay = [];

        foreach (File::allFiles(base_path('app/Filament')) as $tep) {
            if ($tep->getExtension() !== 'php') {
                continue;
            }

            // `php_strip_whitespace()`, không phải một regex bỏ chú thích.
            //
            // Lượt chạy đầu của ca này ĐỎ với "không thấy closure nào", và
            // nguyên nhân là regex `#/\*[\s\S]*?\*/#`: docblock của
            // `oAnhManHinh()` nhắc `image/*`, nên dấu `/*` trong đó mở một
            // chú thích giả và phép thay thế ăn luôn tới `*/` của docblock
            // sau — cùng với cả khối mã ở giữa, `->directory(` trong đó. Một
            // bộ quét ăn mất mã nguồn là một bộ quét XANH giả.
            $ma = php_strip_whitespace($tep->getRealPath());

            preg_match_all('#->directory\(\s*(?:static\s+)?(?:fn|function)\s*\(([^)]*)\)#', $ma, $khop);

            foreach ($khop[1] as $thamSo) {
                preg_match_all('#\$(\w+)#', $thamSo, $ten);
                $thay = [...$thay, ...$ten[1]];
            }
        }

        $this->assertNotEmpty($thay, 'Không thấy closure `->directory()` nào — regex hỏng, không phải không có.');

        foreach ($thay as $ten) {
            $this->assertContains($ten, $choPhep, "`->directory(fn (\${$ten}))` — Filament tra theo tên, tên này không có.");
        }
    }

    // ══ Bất biến nối với lát 0 ════════════════════════════════════════════

    public function test_lenh_soat_van_dem_duoc_anh_trong_thu_muc_con(): void
    {
        // Lát 0 đi bộ bằng `allFiles()` (đệ quy) chứ `files()` (một tầng).
        // Đổi sang thư mục con mà phép đo ấy không đệ quy thì lệnh soát báo
        // "không có tệp nào" trên một đĩa đầy ảnh.
        $dia = Storage::fake('public');

        $chu = Owner::factory()->create(['status' => 'active']);
        $man = $this->manHinhCuaOwner($chu);

        $duong = SoatAnhKhoService::thuMucAnh($chu->id) . '/anh-that.jpg';
        $dia->put($duong, 'x');

        ScreenSpec::factory()->create([
            'screen_id' => $man->id,
            'photos'    => [$duong],
            'photo_url' => null,
        ]);

        $ra = app(SoatAnhKhoService::class)->soat();

        $this->assertSame(1, $ra['dia']['so_tep'], 'ảnh trong thư mục con không được đếm');
        $this->assertSame(0, $ra['tep_mat']['so_phan_tu'], 'ảnh có thật mà bị báo là mất');
        $this->assertSame(1, $ra['tong'][SoatAnhKhoService::CO_ANH]);
    }
}
