<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Bộ lọc **trạng thái giá** — `price_status` trên `/api/v2/screens`, và nhóm
 * đếm cùng tên trên `/api/v2/filters`.
 *
 * ══ Vì sao bộ lọc này tồn tại ══
 *
 * Đo trên production ngày 18–19/10/2026 (50 trong 104 bản ghi): **1** có giá.
 * Cả 50 mang `pricing_model = io`, `floor_cpm` null, và `/api/v2/filters` trả
 * `price_range {min: 0, max: 0}` — tức thanh lọc giá không lọc được gì.
 *
 * Nên câu hỏi đổi: thay vì "giá nằm trong khoảng nào" (không trả lời được),
 * hỏi "đã có giá hay chưa" (trả lời được).
 *
 * ══ Ba cái bẫy mà các phép kiểm dưới đây canh ══
 *
 * 1. **Chỉ nhìn `floor_cpm`.** Hai mô hình giá cùng tồn tại; thẻ màn hình
 *    hiện cả `floor_cpm` lẫn `io_rate`. Một định nghĩa chỉ nhìn `floor_cpm`
 *    nói "0 màn hình có giá" trong khi trang công khai đang in một cái giá.
 * 2. **Màn hình không có dòng tồn kho nào.** Nó cũng là chưa báo giá.
 *    `whereHas('inventory', ...)` bỏ sót đúng nhóm đó.
 * 3. **Số đếm lệch khỏi tập lọc.** Nhóm đếm ở `/filters` và bộ lọc ở
 *    `/screens` là hai đường mã khác nhau; chúng phải ra cùng một con số, và
 *    phép kiểm dưới đây so trực tiếp hai đường.
 */
class CatalogPriceStatusApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Một màn hình công khai. `$gia` là mảng cột tồn kho, hoặc `null` để tạo
     * màn hình **không có dòng `screen_inventory` nào** — trạng thái có thật
     * trong dữ liệu nhập từ owner.
     */
    private function manHinh(?array $gia, string $ten): Screen
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

        if ($gia !== null) {
            ScreenInventory::create(array_merge([
                'screen_id'   => $screen->id,
                'spot_length' => 15,
            ], $gia));
        }

        return $screen;
    }

    /** Dựng đúng bốn màn hình phủ cả bốn trạng thái có thật. */
    private function dungKho(): array
    {
        return [
            'cpm'       => $this->manHinh(['pricing_model' => 'cpm', 'floor_cpm' => 50_000], 'Có giá CPM'),
            'io'        => $this->manHinh(['pricing_model' => 'io', 'io_rate' => 550_000_000, 'io_rate_unit' => 'month'], 'Có giá IO'),
            'khong'     => $this->manHinh(['pricing_model' => 'io', 'floor_cpm' => null, 'io_rate' => null], 'Chưa báo giá'),
            'khongKho'  => $this->manHinh(null, 'Không có dòng tồn kho'),
        ];
    }

    private function tenTraVe(string $query): array
    {
        $response = $this->getJson('/api/v2/screens?per_page=50&' . $query);
        $response->assertOk();

        return collect($response->json('data'))->pluck('name')->sort()->values()->all();
    }

    public function test_quoted_nhan_ca_floor_cpm_lan_io_rate(): void
    {
        $this->dungKho();

        $this->assertSame(
            ['Có giá CPM', 'Có giá IO'],
            $this->tenTraVe('price_status=quoted'),
            'Bộ lọc "đã báo giá" phải nhận CẢ HAI mô hình giá. Chỉ nhìn '
                . '`floor_cpm` là bỏ sót toàn bộ kho hiện tại: 50/50 bản ghi '
                . 'trên production mang `pricing_model = io`.',
        );
    }

    public function test_unquoted_gom_ca_man_hinh_khong_co_dong_ton_kho(): void
    {
        $this->dungKho();

        $this->assertSame(
            ['Chưa báo giá', 'Không có dòng tồn kho'],
            $this->tenTraVe('price_status=unquoted'),
            'Màn hình KHÔNG có dòng `screen_inventory` nào cũng là chưa báo '
                . 'giá. Dùng `whereHas(... = 0)` thay cho `whereDoesntHave` '
                . 'là bỏ sót đúng nhóm đó, và nó biến mất khỏi cả hai bộ lọc.',
        );
    }

    public function test_chon_ca_hai_trang_thai_la_khong_loc(): void
    {
        $this->dungKho();

        $this->assertCount(
            4,
            $this->tenTraVe('price_status=quoted,unquoted'),
            'Chọn cả hai nghĩa là "có giá hoặc không có giá" — tức không lọc. '
                . 'Trả tập rỗng là trả lời một câu hỏi không ai hỏi.',
        );
    }

    public function test_khong_gui_tham_so_thi_tra_het(): void
    {
        $this->dungKho();

        $this->assertCount(4, $this->tenTraVe('sort=newest'));
    }

    public function test_gia_tri_la_tra_422(): void
    {
        $this->getJson('/api/v2/screens?price_status=mien-phi')
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    public function test_dem_o_filters_khop_voi_tap_loc_duoc(): void
    {
        $this->dungKho();

        $filters = $this->getJson('/api/v2/filters');
        $filters->assertOk();

        $dem = $filters->json('data.price_status');

        $this->assertSame(
            ['quoted' => 2, 'unquoted' => 2],
            ['quoted' => $dem['quoted'], 'unquoted' => $dem['unquoted']],
        );

        // Và quan trọng hơn: khớp với con số mà chính bộ lọc trả về. Hai
        // đường mã khác nhau, nên chúng có thể trôi khỏi nhau.
        $this->assertSame(
            $dem['quoted'],
            $this->getJson('/api/v2/screens?price_status=quoted')->json('meta.total'),
            'Nhóm đếm ở /filters lệch khỏi số bản ghi /screens lọc được.',
        );
        $this->assertSame(
            $dem['unquoted'],
            $this->getJson('/api/v2/screens?price_status=unquoted')->json('meta.total'),
            'Nhóm đếm ở /filters lệch khỏi số bản ghi /screens lọc được.',
        );
    }

    public function test_dem_khong_tinh_man_hinh_khong_cong_khai(): void
    {
        $this->dungKho();

        // Owner ngừng hoạt động: màn hình của họ biến khỏi trang công khai,
        // nên cũng phải biến khỏi nhóm đếm. Đếm trên một tập rộng hơn tập
        // hiển thị là nói với người mua rằng còn hàng mà họ không bao giờ
        // thấy.
        $owner = Owner::factory()->create(['status' => 'suspended']);
        $site  = Site::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);

        $screen = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);
        ScreenInventory::create([
            'screen_id'     => $screen->id,
            'pricing_model' => 'cpm',
            'floor_cpm'     => 90_000,
            'spot_length'   => 15,
        ]);

        Cache::flush();

        $dem = $this->getJson('/api/v2/filters')->json('data.price_status');

        $this->assertSame(2, $dem['quoted'], 'Màn hình của owner ngừng hoạt động bị đếm vào nhóm đã báo giá.');
        $this->assertSame(2, $dem['unquoted']);
    }

    /**
     * Nhóm đếm theo loại màn hình, thêm cùng cột lọc.
     *
     * Hai điều được canh ở đây. Thứ nhất: con số khớp tập mà bộ lọc
     * `screen_type` trả về — `screen_type` là giá trị **suy ra** từ kích
     * thước và `venue_type`, không phải một cột, nên hai lần diễn giải cùng
     * quy tắc sẽ trôi khỏi nhau. Thứ hai: nhóm đếm 0 **vẫn được trả về**,
     * để giao diện hiện nó mờ thay vì giấu.
     */
    public function test_dem_loai_man_hinh_khop_bo_loc_va_giu_nhom_bang_khong(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $site  = Site::factory()->create(['owner_id' => $owner->id, 'status' => 'active']);

        // Một billboard: cạnh ≥ 300cm.
        $to = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);
        \App\Models\ScreenSpec::factory()->create(['screen_id' => $to->id, 'width_cm' => 1920, 'height_cm' => 960]);
        ScreenInventory::create(['screen_id' => $to->id, 'venue_type' => 'outdoor', 'spot_length' => 15]);

        // Một LCD: nhỏ, trong nhà.
        $nho = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);
        \App\Models\ScreenSpec::factory()->create(['screen_id' => $nho->id, 'width_cm' => 120, 'height_cm' => 200]);
        ScreenInventory::create(['screen_id' => $nho->id, 'venue_type' => 'mall', 'spot_length' => 15]);

        $nhom = collect($this->getJson('/api/v2/filters')->json('data.screen_types'))
            ->keyBy('code');

        $this->assertSame(
            ['billboard', 'led', 'lcd'],
            $nhom->keys()->all(),
            'Nhóm đếm 0 bị bỏ khỏi phản hồi. Giao diện hiện nó mờ chứ không '
                . 'giấu: một loại hình hiện 0 nói rằng sàn biết loại đó tồn '
                . 'tại nhưng chưa có hàng.',
        );

        $this->assertSame(1, $nhom['billboard']['count']);
        $this->assertSame(0, $nhom['led']['count']);
        $this->assertSame(1, $nhom['lcd']['count']);

        // Nhãn lấy từ cùng bảng chữ mà thẻ màn hình dùng.
        $this->assertSame('Billboard', $nhom['billboard']['name']);

        foreach (['billboard', 'led', 'lcd'] as $loai) {
            $this->assertSame(
                $nhom[$loai]['count'],
                $this->getJson("/api/v2/screens?screen_type={$loai}")->json('meta.total'),
                "Nhóm đếm `{$loai}` ở /filters lệch khỏi số bản ghi /screens lọc được.",
            );
        }
    }

    public function test_loc_gia_khong_dung_den_api_v1(): void
    {
        $this->dungKho();

        // `/api/v1` là hợp đồng với đối tác (CLAUDE.md mục 1). Tham số mới
        // không được đổi hành vi của nó — kể cả bằng cách trả 422 cho một
        // tham số nó vốn lờ đi.
        $truoc = $this->getJson('/api/v1/screens')->status();
        $sau   = $this->getJson('/api/v1/screens?price_status=quoted')->status();

        $this->assertSame($truoc, $sau, '/api/v1 đổi hành vi vì một tham số của v2.');
    }
}
