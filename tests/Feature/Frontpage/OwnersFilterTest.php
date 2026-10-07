<?php

namespace Tests\Feature\Frontpage;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Giai đoạn 4 — ô lọc trên `/owners` phải lọc thật.
 *
 * Trước đây trang này có ba ô điều khiển và không ô nào làm gì: ô tìm kiếm
 * thiếu `name` và không nằm trong form; chip lọc chỉ đổi class CSS; ô sắp xếp
 * không có `name` lẫn handler. Audit F-15 gọi nhóm này là "nút CTA không có
 * hành vi".
 *
 * Nhưng `getOwnersPaginated()` ĐÃ nhận `q` và `type` — nên hai cái đầu chỉ
 * thiếu dây nối, không thiếu nghiệp vụ. Ô sắp xếp thì không có tham số nào ở
 * backend nên đã gỡ.
 *
 * Và khi nối vào mới lộ ra lỗi thật: filter `type` so SLUG nhóm điểm đặt với
 * cột `screen_inventory.venue_type`, là một cột chuỗi khác giữ mã OpenOOH. Hai
 * từ vựng không giao nhau nên nó khớp không gì cả — kể cả tham số `type` đã
 * công bố ở `/api/v2/owners`. Lỗi im lặng: chỉ trả rỗng, không báo gì.
 */
class OwnersFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function url(string $path = '/owners'): string
    {
        return 'http://' . config('domains.frontpage', 'oohx.net') . $path;
    }

    /** `venue_categories.id` là tinyint gán tay, không tự tăng. */
    private function category(int $id, string $slug, string $nameVi): int
    {
        DB::table('venue_categories')->insert([
            'id'         => $id,
            'name'       => ucfirst($slug),
            'slug'       => $slug,
            'name_vi'    => $nameVi,
            'sort_order' => $id,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function ownerWithScreenIn(int $categoryId, string $ownerName): Owner
    {
        $owner  = Owner::factory()->create(['status' => 'active', 'name' => $ownerName]);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenSpec::factory()->create(['screen_id' => $screen->id]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'vn_category_id'         => $categoryId,
            'venue_type'             => 'transit.airports.arrival_hall',
            'pricing_model'          => 'io',
            'io_rate'                => 1000000,
            'io_rate_unit'           => 'month',
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        return $owner;
    }

    // ── Ngữ nghĩa lọc: đo ở /api/v2/owners ──────────────────────────────
    //
    // Bốn ca dưới đây từng đo HTML của `/owners` trên Blade. Giai đoạn 7
    // (07/10/2026) gỡ trang đó — Next.js phục vụ `/owners`, và nó lấy dữ liệu
    // qua `/api/v2/owners`.
    //
    // Bất biến thì không đổi, và nó là bất biến đã từng sai: filter `type` so
    // SLUG nhóm điểm đặt với một cột giữ mã OpenOOH, hai từ vựng không giao
    // nhau nên nó khớp không gì cả. Lỗi im lặng — chỉ trả rỗng, không báo gì.
    //
    // `CatalogFiltersAndOwnerApiTest` phủ hình dạng, trường nhạy cảm, 404 và
    // kiểu input của endpoint này; nó KHÔNG phủ ngữ nghĩa lọc. Đó là chỗ bốn
    // ca này lấp.

    private function tenOwners(string $query = ''): array
    {
        return collect(
            $this->getJson($this->url('/api/v2/owners' . $query))->assertOk()->json('data')
        )->pluck('name')->all();
    }

    public function test_loc_theo_slug_nhom_diem_dat_tra_dung_owner(): void
    {
        $banLe    = $this->category(1, 'retail', 'Ban le');
        $trungTam = $this->category(2, 'mall', 'Trung tam thuong mai');

        $this->ownerWithScreenIn($banLe, 'Owner Ban Le');
        $this->ownerWithScreenIn($trungTam, 'Owner Trung Tam');

        $ten = $this->tenOwners('?type=retail');

        $this->assertContains('Owner Ban Le', $ten);
        $this->assertNotContains('Owner Trung Tam', $ten);
    }

    public function test_khong_loc_thi_thay_het(): void
    {
        $this->ownerWithScreenIn($this->category(1, 'retail', 'Ban le'), 'Owner Ban Le');
        $this->ownerWithScreenIn($this->category(2, 'mall', 'Trung tam'), 'Owner Trung Tam');

        $ten = $this->tenOwners();

        $this->assertContains('Owner Ban Le', $ten);
        $this->assertContains('Owner Trung Tam', $ten);
    }

    public function test_slug_la_thi_bo_qua_filter_giong_explore(): void
    {
        $this->ownerWithScreenIn($this->category(1, 'retail', 'Ban le'), 'Owner Ban Le');

        // Slug không có trong từ vựng thì BỎ QUA bộ lọc, không trả rỗng — giống
        // cách `/explore` xử lý. Trả rỗng cho một slug gõ sai là nói với người
        // dùng rằng không có owner nào, điều đó không đúng.
        $this->assertContains('Owner Ban Le', $this->tenOwners('?type=khong-ton-tai'));
    }

    public function test_o_tim_kiem_loc_theo_ten(): void
    {
        $this->ownerWithScreenIn($this->category(1, 'retail', 'Ban le'), 'Quang Cao Phuong Nam');
        $this->ownerWithScreenIn($this->category(2, 'mall', 'Trung tam'), 'Truyen Thong Bac Ha');

        $ten = $this->tenOwners('?q=Phuong+Nam');

        $this->assertContains('Quang Cao Phuong Nam', $ten);
        $this->assertNotContains('Truyen Thong Bac Ha', $ten);
    }

    // ── Năm ca về markup Blade: GỠ ──────────────────────────────────────
    //
    // `test_o_tim_kiem_gui_duoc_vi_co_name_va_nam_trong_form`,
    // `test_tim_kiem_giu_lai_nhom_dang_chon`,
    // `test_chip_la_link_chu_khong_phai_div`,
    // `test_chip_dang_chon_khop_voi_danh_sach_dang_hien`,
    // `test_khong_con_js_doi_class_cho_chip`.
    //
    // Chúng đo HTML của trang Blade — `name="q"`, `<a class="cat-chip">`,
    // không còn JS đổi class. Trang đó không còn, nên chúng không đo được gì.
    //
    // Và chúng KHÔNG có bản thay: bản Next của `/owners` hiện **chưa có giao
    // diện lọc**. Đó là một khoảng trống thật, đang nằm trong danh sách việc
    // còn tồn ("filter UI cho các trang danh sách Next"), không phải một thứ
    // đã được phủ ở chỗ khác.
    //
    // Ghi ra đây thay vì xoá lặng, để người làm giao diện lọc bản Next biết
    // năm điều cần canh lại — nhất là "chip là link chứ không phải div", vì
    // đó là khác biệt giữa một bộ lọc dùng được và một bộ lọc chỉ đổi màu.
}
