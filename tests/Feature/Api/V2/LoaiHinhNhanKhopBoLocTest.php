<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Nhãn loại hình trên thẻ và bộ lọc loại hình phải nói **cùng một chuyện**.
 *
 * ══ Lỗi đã có trên production, thấy ngày 19/10/2026 ══
 *
 * Cột lọc mới của `/explore` in **LCD 0** và **LED 0**, hiện mờ không bấm
 * được — trong khi 20 trong 50 thẻ đọc được đang mang nhãn **LCD** ngay cạnh.
 * Hai luật cho cùng một tên:
 *
 * | Màn hình không có dòng `screen_specs` | |
 * |---|---|
 * | `resolveScreenType()` | hai cạnh là 0 → không billboard → **LCD** |
 * | `applyScreenTypeFilter()` | cả ba nhánh `whereHas('spec')` → **không nhóm nào** |
 *
 * ══ Một lỗi tôi tưởng có mà không có ══
 *
 * Nhánh `billboard` viết `where(w >= 300)->orWhere(h >= 300)` thẳng trong
 * `whereHas`, và tôi đọc nó thành `screen_id = X and w >= 300 or h >= 300`
 * — tức `OR` nuốt mất ràng buộc khoá ngoại. In `toSql()` ra thì không:
 * `whereHas` **tự bọc** điều kiện của closure trong ngoặc. Con số 84 đo được
 * trên production là số billboard thật, không phải triệu chứng.
 *
 * Ghi lại vì nó là một suy luận hợp lý mà sai, và phép đột biến là thứ bác
 * được nó: bỏ ngoặc đi thì **không ca nào** đỏ.
 *
 * ══ Vì sao phép kiểm này đi theo từng bản ghi ══
 *
 * `CatalogPriceStatusApiTest` đã so nhóm đếm với `meta.total` của cùng bộ lọc,
 * và nó **xanh suốt** qua cả hai lỗi trên: hai con số lấy từ cùng một hàm sai
 * thì vẫn bằng nhau. Cái phải so là nhãn mà người dùng **đọc trên thẻ** với
 * nhóm mà bộ lọc xếp bản ghi đó vào.
 */
class LoaiHinhNhanKhopBoLocTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Một màn hình công khai.
     *
     * `$spec` là `null` để **không tạo dòng `screen_specs`** nào — trạng thái
     * của 20/104 bản ghi thật. `$venueType` là `null` để không tạo dòng tồn
     * kho nào.
     */
    private function manHinh(string $ten, ?array $spec, ?string $venueType): Screen
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $site  = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => 'Hà Nội',
            'status'   => 'active',
        ]);

        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
            'name'     => $ten,
        ]);

        if ($spec !== null) {
            ScreenSpec::factory()->create(array_merge(['screen_id' => $screen->id], $spec));
        }

        if ($venueType !== null) {
            ScreenInventory::create([
                'screen_id'     => $screen->id,
                'venue_type'    => $venueType,
                'pricing_model' => 'io',
                'spot_length'   => 15,
            ]);
        }

        return $screen;
    }

    /**
     * Sáu màn hình phủ cả sáu trường hợp biên, kèm loại **đúng** của từng cái
     * theo luật của `resolveScreenType()`.
     *
     * @return array<string, string> slug → loại mong đợi
     */
    private function dungKho(): array
    {
        $to       = ['width_cm' => 400, 'height_cm' => 200];
        $nho      = ['width_cm' => 120, 'height_cm' => 80];
        $caoThoi  = ['width_cm' => 100, 'height_cm' => 320];

        return [
            $this->manHinh('Spec rộng', $to, 'outdoor')->slug          => 'billboard',
            $this->manHinh('Spec cao', $caoThoi, 'mall')->slug         => 'billboard',
            $this->manHinh('Nhỏ ngoài trời', $nho, 'outdoor')->slug    => 'led',
            $this->manHinh('Nhỏ trong nhà', $nho, 'mall')->slug        => 'lcd',

            // Hai ca làm đỏ bản cũ.
            $this->manHinh('Không spec, ngoài trời', null, 'outdoor')->slug => 'led',
            $this->manHinh('Không spec, không tồn kho', null, null)->slug   => 'lcd',
        ];
    }

    /** slug → `screen_type` mà API danh sách in ra. */
    private function nhanTheoSlug(): array
    {
        $data = $this->getJson('/api/v2/screens?per_page=50')->assertOk()->json('data');

        return collect($data)->pluck('screen_type', 'slug')->all();
    }

    /** Các slug mà bộ lọc trả về cho một loại. */
    private function slugLocDuoc(string $loai): array
    {
        $data = $this->getJson("/api/v2/screens?screen_type={$loai}&per_page=50")
            ->assertOk()
            ->json('data');

        return collect($data)->pluck('slug')->sort()->values()->all();
    }

    public function test_nhan_tren_the_dung_voi_tung_truong_hop_bien(): void
    {
        $mongDoi = $this->dungKho();
        $thucTe  = $this->nhanTheoSlug();

        // Sắp theo khoá trước khi so: `assertSame` trên mảng kết hợp nhạy cả
        // thứ tự khoá, mà thứ tự ở đây là thứ tự sắp xếp của API — không phải
        // thứ đang kiểm.
        ksort($mongDoi);
        ksort($thucTe);

        $this->assertSame(
            $mongDoi,
            $thucTe,
            'Nhãn loại hình sai ở ít nhất một trường hợp biên.',
        );
    }

    public function test_moi_man_hinh_duoc_bo_loc_xep_dung_nhom_cua_nhan(): void
    {
        $mongDoi = $this->dungKho();

        foreach (array_keys(array_count_values($mongDoi)) as $loai) {
            $theoNhan = collect($mongDoi)->filter(fn ($l) => $l === $loai)
                ->keys()->sort()->values()->all();

            $this->assertSame(
                $theoNhan,
                $this->slugLocDuoc($loai),
                "Bộ lọc `screen_type={$loai}` trả về một tập khác với tập "
                    . 'những bản ghi mang nhãn đó. Người dùng đọc nhãn trên '
                    . 'thẻ rồi bấm đúng nhãn ấy ở cột lọc, và được một câu '
                    . 'trả lời khác.',
            );
        }
    }

    public function test_ba_nhom_chia_het_tap_man_hinh(): void
    {
        $this->dungKho();

        $tong = $this->getJson('/api/v2/screens?per_page=1')->json('meta.total');

        $cong = collect(['billboard', 'led', 'lcd'])
            ->sum(fn ($loai) => $this->getJson("/api/v2/screens?screen_type={$loai}&per_page=1")
                ->json('meta.total'));

        $this->assertSame(
            $tong,
            $cong,
            'Tổng ba nhóm khác tổng số màn hình, nghĩa là có bản ghi rơi ra '
                . 'ngoài cả ba (hoặc bị đếm hai lần). Mỗi màn hình mang đúng '
                . 'một nhãn, nên ba bộ lọc phải chia hết tập.',
        );
    }

    public function test_billboard_do_kich_thuoc_chu_khong_chi_doi_co_spec(): void
    {
        $this->dungKho();

        // Kho có bốn màn hình **có** spec, trong đó hai cái nhỏ. Một bộ lọc
        // chỉ hỏi "có dòng spec không" sẽ nhận cả bốn, và con số nó trả về
        // vẫn trông hợp lý — gần bằng tổng kho, đúng như hình dạng dữ liệu
        // thật nơi phần lớn màn hình lớn thật.
        $this->assertSame(
            ['Spec cao', 'Spec rộng'],
            collect($this->getJson('/api/v2/screens?screen_type=billboard&per_page=50')->json('data'))
                ->pluck('name')->sort()->values()->all(),
            'Bộ lọc `billboard` nhận cả màn hình nhỏ — nó đang hỏi sự tồn '
                . 'tại của dòng `screen_specs` thay vì hỏi kích thước.',
        );
    }

    public function test_nhom_dem_o_filters_khop_tung_bo_loc(): void
    {
        $this->dungKho();

        $nhom = collect($this->getJson('/api/v2/filters')->json('data.screen_types'))
            ->keyBy('code');

        foreach (['billboard', 'led', 'lcd'] as $loai) {
            $this->assertSame(
                $nhom[$loai]['count'],
                count($this->slugLocDuoc($loai)),
                "Nhóm đếm `{$loai}` ở /filters lệch khỏi tập /screens lọc được.",
            );
        }

        // Và nhóm đếm 0 vẫn phải có mặt: giao diện hiện nó mờ chứ không giấu.
        $this->assertSame(['billboard', 'led', 'lcd'], $nhom->keys()->all());
    }

    public function test_api_v1_giu_nguyen_hanh_vi_cu(): void
    {
        $this->dungKho();

        // CLAUDE.md mục 1: `/api/v1` là hợp đồng với đối tác, không đổi hành
        // vi. `InventoryController` có bản `applyScreenTypeFilter()` của
        // riêng nó, và nó KHÔNG được sửa cùng lượt này — kể cả khi nó mang
        // cùng hai lỗi. Đổi nó là một quyết định riêng, cần chủ dự án chốt.
        //
        // Phép kiểm đọc **mã nguồn** chứ không gọi endpoint: `/api/v1` đòi
        // token đối tác, và dựng một token chỉ để chứng minh một dòng mã
        // chưa đổi là đi vòng quá xa cho cùng một câu trả lời.
        $this->assertStringContainsString(
            "where('venue_type', '!=', 'outdoor')",
            file_get_contents(app_path('Http/Controllers/Api/V1/InventoryController.php')),
            'Bộ lọc loại hình của v1 đã bị sửa theo v2. Nếu đó là chủ ý thì '
                . 'xoá phép kiểm này và ghi lại quyết định; nếu không, hoàn '
                . 'lại — đối tác đang gọi vào đường đó.',
        );
    }
}
