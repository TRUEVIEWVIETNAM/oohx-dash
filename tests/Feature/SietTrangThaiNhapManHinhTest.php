<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\ScreenImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * `screen_imports.status` đã siết sang enum — và migration phải TỪ CHỐI khi không siết được.
 *
 * ══ Ca đáng giá nhất ở đây là ca thứ ba ══
 *
 * Hai ca đầu — cột đã là enum, và CSDL từ chối giá trị lạ — chỉ khẳng định
 * migration đã chạy. Ca thứ ba kiểm thứ dễ viết sai nhất: nhánh **từ chối**.
 *
 * Migration đọc các giá trị đang có TRƯỚC khi `ALTER` bất cứ thứ gì, và nếu có
 * giá trị ngoài bảy giá trị đã khai thì nó ném lỗi kèm danh sách, không đổi
 * CSDL. Cách khác — map giá trị lạ về `failed` — thì migration luôn chạy được,
 * nhưng nó ghi đè dữ liệu của người khác mà không ai biết.
 *
 * Nhánh đó chỉ chạy khi cột CÒN là `varchar`, tức sau `down()`. Nên ca này gọi
 * `down()`, nhét một giá trị lạ, rồi gọi `up()` và đòi: ném lỗi, thông điệp nêu
 * đúng giá trị lạ, và cột **vẫn là varchar** — nghĩa là chưa `ALTER` gì.
 */
class SietTrangThaiNhapManHinhTest extends TestCase
{
    use RefreshDatabase;

    private const TEP = 'database/migrations/2026_10_10_000002_screen_imports_status_sang_enum.php';

    private function migration(): object
    {
        return require base_path(self::TEP);
    }

    private function kieuCot(): string
    {
        return (string) DB::selectOne(
            "SELECT COLUMN_TYPE AS kieu FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'screen_imports' AND COLUMN_NAME = 'status'",
        )->kieu;
    }

    /**
     * Một hàng `screen_imports` với trạng thái cho trước, trả về id của **nó**.
     *
     * Ghi bằng `DB::table()` chứ không qua model, có chủ ý: ca
     * `test_up_tu_choi_...` cần nhét được một giá trị mà model lẫn enum đều
     * không nhận.
     *
     * Khoá chính là UUID **chuỗi** (`HasUuids`), nên `insertGetId()` không trả
     * về id — bản đầu của hàm này rơi sang `latest('created_at')->value('id')`
     * và lấy **sai hàng** khi bảy hàng được tạo trong cùng một giây. Ca
     * `test_di_ve_khong_mat_trang_thai_nao` đỏ với "'mapping' thành 'uploaded'"
     * và tôi đã tưởng migration làm mất dữ liệu. Nó không: lỗi ở đây. Nên id
     * được sinh ra trước và trả về thẳng.
     */
    private function motLanNhap(string $trangThai): string
    {
        $owner = Owner::factory()->create(['status' => Owner::STATUS_ACTIVE]);
        $id    = (string) \Illuminate\Support\Str::uuid();

        DB::table('screen_imports')->insert([
            'id'                => $id,
            'owner_id'          => $owner->id,
            'uploaded_by'       => User::factory()->create()->id,
            'original_filename' => 'kho.xlsx',
            'file_path'         => 'imports/kho.xlsx',
            'status'            => $trangThai,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return $id;
    }

    public function test_cot_da_la_enum_bay_gia_tri(): void
    {
        $kieu = $this->kieuCot();

        $this->assertStringStartsWith('enum(', $kieu, "Cột chưa siết: {$kieu}");

        foreach (array_keys(ScreenImport::STATUS_LABELS) as $ma) {
            $this->assertStringContainsString("'{$ma}'", $kieu, "Enum thiếu '{$ma}': {$kieu}");
        }
    }

    /**
     * CSDL từ chối một giá trị không khai — tầng chặn mà `varchar(255)` không có.
     */
    public function test_csdl_tu_choi_trang_thai_la(): void
    {
        $owner = Owner::factory()->create(['status' => Owner::STATUS_ACTIVE]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('screen_imports')->insert([
            'id'                => (string) \Illuminate\Support\Str::uuid(),
            'owner_id'          => $owner->id,
            'uploaded_by'       => User::factory()->create()->id,
            'original_filename' => 'kho.xlsx',
            'file_path'         => 'imports/kho.xlsx',
            'status'            => 'uploadeed',   // lỗi chính tả, đúng hình dạng lỗi enum chặn
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function test_up_tu_choi_khi_co_gia_tri_la_va_khong_doi_csdl(): void
    {
        $migration = $this->migration();

        // Về `varchar` để nhét được giá trị lạ — đúng trạng thái trước khi siết.
        $migration->down();
        $this->assertStringStartsWith('varchar', $this->kieuCot());

        $this->motLanNhap('mot_trang_thai_khong-ton-tai');

        try {
            $migration->up();
            $this->fail('`up()` phải ném lỗi khi có giá trị ngoài bảy giá trị đã khai.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('mot_trang_thai_khong-ton-tai', $e->getMessage());
            $this->assertStringContainsString('CSDL chưa bị đổi gì', $e->getMessage());
        }

        $this->assertStringStartsWith(
            'varchar',
            $this->kieuCot(),
            '`up()` đã ALTER cột trước khi kiểm — phép kiểm phải đi TRƯỚC mọi thay đổi.',
        );

        // Dọn rồi siết lại, để các ca sau không chạy trên một cột varchar.
        DB::table('screen_imports')->where('status', 'mot_trang_thai_khong-ton-tai')->delete();
        $migration->up();
        $this->assertStringStartsWith('enum(', $this->kieuCot());
    }

    /**
     * Đi về rồi đi lại không mất trạng thái của hàng nào.
     *
     * Bảy hàng, mỗi hàng một trạng thái. `down()` đưa cột về `varchar`, `up()`
     * siết lại — nếu một bước nào ghi đè mặc định thì cả bảy hàng về `uploaded`
     * và ca này đỏ.
     */
    public function test_di_ve_khong_mat_trang_thai_nao(): void
    {
        $ids = [];

        foreach (array_keys(ScreenImport::STATUS_LABELS) as $ma) {
            $ids[$ma] = $this->motLanNhap($ma);
        }

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        foreach ($ids as $ma => $id) {
            $this->assertSame(
                $ma,
                DB::table('screen_imports')->where('id', $id)->value('status'),
                "Hàng mang '{$ma}' đã đổi trạng thái sau một vòng down/up",
            );
        }
    }

    /**
     * Ba tập trạng thái dùng chung phải là tập con của bảy giá trị đã khai.
     *
     * `EDITABLE_STATUSES`, `STARTED_STATUSES` và `FINISHED_STATUSES` gom các
     * `in_array(...)` từng viết tay ở bảy chỗ. Một mã lạ lọt vào một trong ba
     * tập đó thì `in_array` không bao giờ đúng, và hệ quả là một cái nút lặng
     * lẽ không bao giờ hiện — không ai đỏ.
     */
    public function test_cac_tap_trang_thai_dung_chung_deu_hop_le(): void
    {
        $hopLe = array_keys(ScreenImport::STATUS_LABELS);

        $tap = [
            'EDITABLE_STATUSES' => ScreenImport::EDITABLE_STATUSES,
            'STARTED_STATUSES'  => ScreenImport::STARTED_STATUSES,
            'FINISHED_STATUSES' => ScreenImport::FINISHED_STATUSES,
        ];

        foreach ($tap as $ten => $ds) {
            $this->assertNotEmpty($ds, "{$ten} rỗng");

            foreach ($ds as $ma) {
                $this->assertContains($ma, $hopLe, "{$ten} chứa '{$ma}' — không phải một trạng thái đã khai");
            }
        }

        // `cancelled` cố ý KHÔNG nằm trong `FINISHED_STATUSES`: lần nhập bị huỷ
        // dừng trước khi sinh báo cáo lỗi, nên không có gì để tải về.
        $this->assertNotContains(ScreenImport::STATUS_CANCELLED, ScreenImport::FINISHED_STATUSES);
    }
}
