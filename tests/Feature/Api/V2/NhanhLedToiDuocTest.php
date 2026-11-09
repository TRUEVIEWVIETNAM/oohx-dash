<?php

namespace Tests\Feature\Api\V2;

use App\Models\VenueCategory;
use Database\Seeders\VenueCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Nhánh `led` của phép suy loại hình phải **tới được** từ dữ liệu thật.
 *
 * ══ Lỗi đã sống trên production, tìm ra 08/11/2026 ══
 *
 * `/explore` in **LED 0** và hiện mờ không bấm được, trong khi 19 vị trí có
 * tên `OOHX_LED_<tỉnh>` nằm ngay trong kho mang nhãn "LCD".
 *
 * Nguyên nhân không phải thiếu nhãn — `SCREEN_TYPE_LABELS` có `led` từ đầu, và
 * `resolveScreenType()` có nhánh trả `led`. Nguyên nhân là **điều kiện của
 * nhánh ấy không bao giờ đúng**:
 *
 * | | |
 * |---|---|
 * | Mã đi tìm | chuỗi `'outdoor'` |
 * | Mã có thật trong taxonomy | `roadside` — xem `VenueCategorySeeder` |
 * | Cột đi tìm | `screen_inventory.venue_type` |
 * | Giá trị cột đó trên production | **NULL ở cả 104 bản ghi** |
 *
 * Sai hai lần ở một dòng, và không có gì đỏ: `LoaiHinhNhanKhopBoLocTest` dựng
 * fixture với chính chuỗi `'outdoor'`, nên nhãn và bộ lọc cùng đọc một hằng số
 * sai và khớp nhau hoàn hảo.
 *
 * ══ Vì sao phải là một tệp riêng ══
 *
 * Mọi ca ở `LoaiHinhNhanKhopBoLocTest` đều hỏi *"nhãn và bộ lọc có nói cùng
 * một chuyện không"*. Câu hỏi đó **không bắt được** lỗi này, vì hai phía sai
 * giống nhau thì vẫn khớp. Câu hỏi ở đây khác: *"nhánh này có tới được từ từ
 * vựng mà sàn thật sự điền không"*, và nó cần chạy đúng seeder thật chứ không
 * phải một fixture tự bịa từ vựng.
 *
 * Đó cũng là bài học chung của lần này, nên nó đáng một tệp để người đọc sau
 * thấy: **một ca dựng cả hai phía bằng cùng một hằng số không chứng minh được
 * hằng số đó đúng.**
 */
class NhanhLedToiDuocTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_danh_sach_ngoai_troi_khong_rong(): void
    {
        // `SLUG_NGOAI_TROI = []` làm `idNgoaiTroi()` trả `[]`, và khi đó nhánh
        // `led` không bao giờ đúng — y hệt lỗi cũ, chỉ im lặng hơn. Hai phía
        // vẫn khớp nhau nên mọi ca của `LoaiHinhNhanKhopBoLocTest` vẫn xanh.
        $this->assertNotEmpty(
            VenueCategory::SLUG_NGOAI_TROI,
            'Không mã nào được coi là ngoài trời, nên loại hình LED không bao giờ xuất hiện.',
        );
    }

    public function test_moi_ma_ngoai_troi_co_that_trong_taxonomy(): void
    {
        // Đây là phép kiểm mà lỗi cũ sẽ đỏ: `'outdoor'` không phải slug của
        // danh mục nào trong 12 danh mục thật.
        $this->seed(VenueCategorySeeder::class);

        $slugCoThat = VenueCategory::query()->pluck('slug')->all();

        $this->assertNotEmpty($slugCoThat, 'Seeder không dựng danh mục nào — bộ dò đã vỡ.');

        foreach (VenueCategory::SLUG_NGOAI_TROI as $slug) {
            $this->assertContains(
                $slug,
                $slugCoThat,
                "`VenueCategory::SLUG_NGOAI_TROI` gọi tên `{$slug}`, nhưng `VenueCategorySeeder` "
                    . 'không dựng danh mục nào có slug đó (' . implode(', ', $slugCoThat) . '). '
                    . 'Một hằng số trỏ vào một mã không tồn tại làm nhánh LED thành mã chết, và '
                    . 'không ca nào đỏ vì cả nhãn lẫn bộ lọc đều đọc chính hằng số ấy.',
            );
        }
    }

    public function test_id_ngoai_troi_doc_duoc_tu_seeder_that(): void
    {
        $this->seed(VenueCategorySeeder::class);

        $id = VenueCategory::idNgoaiTroi();

        $this->assertNotEmpty(
            $id,
            'Không tra được id ngoài trời nào từ seeder thật — nhánh LED không tới được.',
        );

        // Và chúng phải là chính những hàng mang slug ngoài trời, không phải
        // một danh sách id viết tay sẽ lệch khi seeder đổi.
        $this->assertSame(
            VenueCategory::query()
                ->whereIn('slug', VenueCategory::SLUG_NGOAI_TROI)
                ->pluck('id')
                ->map(fn ($i) => (int) $i)
                ->values()
                ->all(),
            $id,
        );
    }

    public function test_phep_suy_khong_con_doc_cot_venue_type(): void
    {
        // Cột `screen_inventory.venue_type` là cột tự do thời `/api/v1` và NULL
        // trên toàn bộ production. Quay lại đọc nó là quay lại lỗi cũ.
        //
        // Đọc mã nguồn vì cái cần canh là **chỗ dữ liệu được lấy từ đâu**, và
        // một ca gọi endpoint không phân biệt được "đọc cột NULL" với "đọc
        // taxonomy" khi cả hai cùng trả `lcd`.
        $nguon = file_get_contents(
            app_path('Http/Resources/V2/Concerns/SuyRaLoaiManHinh.php'),
        );

        $than = substr($nguon, strpos($nguon, 'private function resolveScreenType'));

        $this->assertStringNotContainsString(
            'venue_type',
            $than,
            '`resolveScreenType()` đọc lại cột `venue_type`. Cột đó NULL trên cả 104 bản ghi '
                . 'production, nên nhánh LED sẽ thành mã chết lần nữa.',
        );

        $this->assertStringContainsString(
            'vn_category_id',
            $than,
            '`resolveScreenType()` không còn đọc `vn_category_id` — đó là taxonomy duy nhất sàn '
                . 'thật sự điền.',
        );
    }

    public function test_bo_loc_va_phep_suy_doc_cung_mot_danh_sach(): void
    {
        // Không so hai con số — `CatalogPriceStatusApiTest` đã làm thế và xanh
        // suốt qua hai lỗi, vì hai con số lấy từ cùng một nguồn sai thì vẫn
        // bằng nhau. Ca này so **nguồn**: cả hai phải gọi `idNgoaiTroi()`.
        $suy = file_get_contents(app_path('Http/Resources/V2/Concerns/SuyRaLoaiManHinh.php'));
        $loc = file_get_contents(app_path('Services/FrontpageService.php'));

        foreach ([['SuyRaLoaiManHinh', $suy], ['FrontpageService', $loc]] as [$ten, $ma]) {
            $this->assertStringContainsString(
                'idNgoaiTroi()',
                $ma,
                "`{$ten}` không gọi `VenueCategory::idNgoaiTroi()`. Hai phía dựng danh sách "
                    . 'ngoài trời riêng là hai bản luật sẽ trôi khỏi nhau, và chỗ lệch chỉ lộ ra '
                    . 'dưới dạng "thẻ nói LED, bộ lọc LED trả 0".',
            );
        }
    }
}
