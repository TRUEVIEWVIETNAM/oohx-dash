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

    public function test_loc_theo_slug_nhom_diem_dat_tra_dung_owner(): void
    {
        $banLe    = $this->category(1, 'retail', 'Ban le');
        $trungTam = $this->category(2, 'mall', 'Trung tam thuong mai');

        $this->ownerWithScreenIn($banLe, 'Owner Ban Le');
        $this->ownerWithScreenIn($trungTam, 'Owner Trung Tam');

        $response = $this->get($this->url('/owners?type=retail'))->assertOk();

        $response->assertSee('Owner Ban Le', false);
        $response->assertDontSee('Owner Trung Tam', false);
    }

    public function test_khong_loc_thi_thay_het(): void
    {
        $this->ownerWithScreenIn($this->category(1, 'retail', 'Ban le'), 'Owner Ban Le');
        $this->ownerWithScreenIn($this->category(2, 'mall', 'Trung tam'), 'Owner Trung Tam');

        $this->get($this->url('/owners'))
            ->assertOk()
            ->assertSee('Owner Ban Le', false)
            ->assertSee('Owner Trung Tam', false);
    }

    public function test_slug_la_thi_bo_qua_filter_giong_explore(): void
    {
        $this->ownerWithScreenIn($this->category(1, 'retail', 'Ban le'), 'Owner Ban Le');

        $this->get($this->url('/owners?type=khong-ton-tai'))
            ->assertOk()
            ->assertSee('Owner Ban Le', false);
    }

    public function test_o_tim_kiem_loc_theo_ten(): void
    {
        $this->ownerWithScreenIn($this->category(1, 'retail', 'Ban le'), 'Quang Cao Phuong Nam');
        $this->ownerWithScreenIn($this->category(2, 'mall', 'Trung tam'), 'Truyen Thong Bac Ha');

        $this->get($this->url('/owners?q=Phuong+Nam'))
            ->assertOk()
            ->assertSee('Quang Cao Phuong Nam', false)
            ->assertDontSee('Truyen Thong Bac Ha', false);
    }

    public function test_o_tim_kiem_gui_duoc_vi_co_name_va_nam_trong_form(): void
    {
        $this->ownerWithScreenIn($this->category(1, 'retail', 'Ban le'), 'Owner Ban Le');

        $html = $this->get($this->url('/owners'))->assertOk()->getContent();

        $this->assertStringContainsString('name="q"', $html);
        $this->assertStringContainsString('<form', $html);
    }

    public function test_tim_kiem_giu_lai_nhom_dang_chon(): void
    {
        $this->ownerWithScreenIn($this->category(1, 'retail', 'Ban le'), 'Owner Ban Le');

        $html = $this->get($this->url('/owners?type=retail'))->assertOk()->getContent();

        $this->assertStringContainsString('name="type"', $html);
        $this->assertStringContainsString('value="retail"', $html);
    }

    public function test_chip_la_link_chu_khong_phai_div(): void
    {
        $this->ownerWithScreenIn($this->category(1, 'retail', 'Ban le'), 'Owner Ban Le');

        $html = $this->get($this->url('/owners'))->assertOk()->getContent();

        $this->assertStringContainsString('<a class="cat-chip', $html);
        $this->assertStringNotContainsString('<div class="cat-chip"', $html);
        $this->assertStringContainsString('type=retail', $html);
    }

    public function test_chip_dang_chon_khop_voi_danh_sach_dang_hien(): void
    {
        $this->ownerWithScreenIn($this->category(1, 'retail', 'Ban le'), 'Owner Ban Le');

        $khongLoc = $this->get($this->url('/owners'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/cat-chip[^"]*\bon\b[^"]*"[^>]*>\s*T/u', $khongLoc);

        $coLoc = $this->get($this->url('/owners?type=retail'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/cat-chip[^"]*\bon\b[^"]*"[^>]*>\s*Ban le/u', $coLoc);
    }

    public function test_khong_con_js_doi_class_cho_chip(): void
    {
        $this->ownerWithScreenIn($this->category(1, 'retail', 'Ban le'), 'Owner Ban Le');

        $html = $this->get($this->url('/owners'))->assertOk()->getContent();

        $this->assertStringNotContainsString("classList.add('on')", $html);
    }
}
