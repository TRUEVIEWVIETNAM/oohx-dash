<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Upload tạm của Livewire (regression sự cố 08–09/2026).
 *
 * Trên production có 22 webshell (`shell_exec($_GET["cmd"])`) nằm trong
 * storage/app/private/livewire-tmp. Luật upload tạm khi đó chỉ là
 * 'required|file|max:51200' — nhận mọi loại file — và Livewire chỉ dọn thư mục
 * này khi một lượt upload hoàn tất, nên file bỏ dở nằm lại vĩnh viễn.
 */
class LivewireUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SHELL = '<?php if(isset($_GET["cmd"])){echo shell_exec($_GET["cmd"]);} ?>';

    private function passes(UploadedFile $file): bool
    {
        return Validator::make(
            ['file' => $file],
            ['file' => config('livewire.temporary_file_upload.rules')]
        )->passes();
    }

    // ── Luật upload tạm ──────────────────────────────────────────────────────

    /** File thật trên đĩa, để MIME được đoán theo nội dung (file fake đoán theo tên). */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_endpoint_upload_tu_choi_file_php(): void
    {
        // Đúng đường tấn công: gọi thẳng endpoint với URL ký hợp lệ rồi bỏ đi.
        $disk = Storage::fake(FileUploadConfiguration::disk());
        $url  = (new GenerateSignedUploadUrl)->forLocal();

        $this->post($url, ['files' => [$this->realFile('poc.php', self::SHELL)]], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame([], $disk->allFiles(FileUploadConfiguration::path()));
    }

    public function test_endpoint_upload_van_nhan_anh_hop_le(): void
    {
        $disk = Storage::fake(FileUploadConfiguration::disk());
        $url  = (new GenerateSignedUploadUrl)->forLocal();

        $this->post($url, ['files' => [UploadedFile::fake()->image('logo.png')]], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertCount(1, $disk->allFiles(FileUploadConfiguration::path()));
    }

    public function test_tu_choi_webshell_doi_duoi_thanh_anh(): void
    {
        $this->assertFalse($this->passes($this->realFile('logo.jpg', self::SHELL)));
    }

    public function test_tu_choi_cac_duoi_php_khac(): void
    {
        foreach (['x.phtml', 'x.phar', 'x.php5'] as $name) {
            $this->assertFalse($this->passes(UploadedFile::fake()->createWithContent($name, self::SHELL)), $name);
        }
    }

    public function test_tu_choi_svg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $this->assertFalse($this->passes(UploadedFile::fake()->createWithContent('logo.svg', $svg)));
    }

    public function test_nhan_anh(): void
    {
        $this->assertTrue($this->passes(UploadedFile::fake()->image('logo.png')));
        $this->assertTrue($this->passes(UploadedFile::fake()->image('cover.jpg')));
    }

    public function test_nhan_pdf_giay_phep_kinh_doanh(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
        $this->assertTrue($this->passes(UploadedFile::fake()->createWithContent('dkkd.pdf', $pdf)));
    }

    public function test_nhan_excel_va_csv_de_import(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx(new Spreadsheet()))->save($path);
        $xlsx = new UploadedFile($path, 'screens.xlsx', null, null, true);

        $this->assertTrue($this->passes($xlsx), 'import màn hình/địa điểm dùng .xlsx');
        $this->assertTrue($this->passes(UploadedFile::fake()->createWithContent('screens.csv', "name,lat,lng\nA,21.0,105.8\n")));
    }

    // ── Dọn upload tạm bỏ dở ─────────────────────────────────────────────────

    public function test_lenh_don_xoa_file_cu_va_giu_file_moi(): void
    {
        $disk = Storage::fake(FileUploadConfiguration::disk());
        $dir  = FileUploadConfiguration::path();

        $disk->put("{$dir}/old-metacG9jLnBocA==-.php", self::SHELL);
        $disk->put("{$dir}/fresh-meta.png", 'x');
        touch($disk->path("{$dir}/old-metacG9jLnBocA==-.php"), now()->subDays(40)->timestamp);

        $this->artisan('uploads:prune-livewire-tmp')->assertSuccessful();

        $disk->assertMissing("{$dir}/old-metacG9jLnBocA==-.php");
        $disk->assertExists("{$dir}/fresh-meta.png");
    }

    public function test_lenh_don_duoc_dat_lich(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('uploads:prune-livewire-tmp');
    }
}
