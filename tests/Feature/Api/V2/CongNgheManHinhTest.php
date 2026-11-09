<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Models\VenueCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Công nghệ màn hình — lát 1 của `docs/PLAN-CONG-NGHE-MAN-HINH.md`.
 *
 * ══ Vì sao cột này tồn tại ══
 *
 * Trước 08/11/2026 sàn không có trường nào ghi công nghệ. Câu trả lời duy nhất
 * là `screen_type`, một giá trị **suy ra** từ kích thước và loại điểm đặt — tức
 * trả lời câu "biển lớn hay màn hình nhỏ", không phải câu "LED hay in bạt".
 *
 * ══ Hai trường, và chúng KHÔNG được gộp ══
 *
 * Đây là tính chất quan trọng nhất của lát này, nên nó có ca riêng:
 *
 * | | `screen_type` | `cong_nghe` |
 * |---|---|---|
 * | Trả lời | hình thức | công nghệ |
 * | Nguồn | suy từ kích thước | media owner **khai** |
 * | Một màn LED 6m | `billboard` | `led` |
 *
 * Một màn LED cỡ lớn phải thuộc **cả hai** nhóm, và lọc theo nhóm nào cũng
 * phải thấy nó. Gộp hai trường lại là quay về đúng chỗ sai đang sửa.
 *
 * ══ `null` là "chưa khai", và nó phải khác được với một câu trả lời ══
 *
 * Cả 104 bản ghi production bắt đầu từ `null`. Nếu "chưa khai" không phải một
 * nhóm lọc được thì cột lọc in ba số 0 và đọc như *"sàn không có màn hình
 * nào"*, trong khi sự thật là *"chưa ai khai"* — hai câu dẫn tới hai hành động
 * khác nhau.
 */
class CongNgheManHinhTest extends TestCase
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
     * `$spec === null` nghĩa là **không có dòng `screen_specs`** — trạng thái
     * của 20/104 bản ghi thật, và một trong hai hình dạng của "chưa khai".
     */
    private function manHinh(string $ten, ?array $spec, ?VenueCategory $loai = null): Screen
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

        if ($loai !== null) {
            ScreenInventory::create([
                'screen_id'      => $screen->id,
                'vn_category_id' => $loai->id,
                'pricing_model'  => 'io',
                'spot_length'    => 15,
            ]);
        }

        return $screen;
    }

    private function ngoaiTroi(): VenueCategory
    {
        return VenueCategory::create([
            'id' => 4, 'name' => 'roadside', 'slug' => 'roadside',
            'name_vi' => 'Ngoài trời', 'icon' => 'signpost',
            'sort_order' => 4, 'is_active' => true,
        ]);
    }

    /** slug → `cong_nghe` mà API danh sách in ra. */
    private function congNgheTheoSlug(): array
    {
        return collect($this->getJson('/api/v2/screens?per_page=50')->assertOk()->json('data'))
            ->pluck('cong_nghe', 'slug')->all();
    }

    private function slugLocDuoc(string $ma): array
    {
        return collect($this->getJson("/api/v2/screens?cong_nghe={$ma}&per_page=50")
            ->assertOk()->json('data'))
            ->pluck('slug')->sort()->values()->all();
    }

    // ── Cột, nhãn, và hình dạng của "chưa khai" ────────────────────────────

    public function test_cot_la_enum_ba_gia_tri_va_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('screen_specs', 'cong_nghe'));

        $hang = DB::selectOne(
            'SELECT COLUMN_TYPE AS kieu, IS_NULLABLE AS rong, COLUMN_DEFAULT AS mac_dinh
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['screen_specs', 'cong_nghe'],
        );

        $this->assertSame("enum('led','lcd','in_bat')", strtolower((string) $hang->kieu));

        $this->assertSame('YES', $hang->rong, 'Cột không nullable — không còn chỗ cho "chưa khai".');

        // Một mặc định — kể cả `'led'`, kể cả vì "phần lớn là LED" — biến một
        // khoảng trống thành một lời khẳng định về 104 vị trí mà không ai
        // kiểm. Đó đúng là lỗi `screen_type` đang mắc.
        $this->assertNull($hang->mac_dinh, 'Cột có giá trị mặc định — 104 bản ghi sẽ mang một câu trả lời không ai khai.');
    }

    public function test_bang_chu_khong_co_ma_chua_khai(): void
    {
        // `chua_khai` là một **câu hỏi lọc**, không phải một giá trị của cột.
        // Để nó vào enum là mời media owner chọn "chưa khai" như một câu trả
        // lời, và khi đó không phân biệt được "chưa trả lời" với "đã trả lời
        // rằng chưa biết".
        $this->assertSame(['led', 'lcd', 'in_bat'], array_keys(ScreenSpec::CONG_NGHE_LABELS));
        $this->assertArrayNotHasKey('chua_khai', ScreenSpec::CONG_NGHE_LABELS);
    }

    // ── DTO ────────────────────────────────────────────────────────────────

    public function test_danh_sach_tra_ma_va_nhan(): void
    {
        $this->manHinh('Màn LED nhỏ', ['width_cm' => 120, 'height_cm' => 80, 'cong_nghe' => 'led']);

        $the = $this->getJson('/api/v2/screens?per_page=50')->assertOk()->json('data.0');

        $this->assertSame('led', $the['cong_nghe']);
        $this->assertSame('LED', $the['cong_nghe_label'], 'Thẻ in mã thô ra mặt người dùng.');
    }

    public function test_chua_khai_tra_null_chu_khong_chuoi_rong(): void
    {
        // `''` hay `'chua_khai'` đều làm giao diện phải biết thêm một quy ước.
        // `null` là thứ nó thật sự là, và `cong_nghe_label` cũng `null` theo —
        // không phải chuỗi "Chưa khai", vì chữ hiển thị là việc của giao diện
        // khi nó biết trường rỗng.
        $this->manHinh('Chưa khai', ['width_cm' => 120, 'height_cm' => 80]);

        $the = $this->getJson('/api/v2/screens?per_page=50')->assertOk()->json('data.0');

        $this->assertNull($the['cong_nghe']);
        $this->assertNull($the['cong_nghe_label']);
    }

    public function test_chi_tiet_tra_cong_nghe_o_tang_ngoai(): void
    {
        $man = $this->manHinh('In bạt lớn', ['width_cm' => 1400, 'height_cm' => 700, 'cong_nghe' => 'in_bat']);

        $data = $this->getJson("/api/v2/screens/{$man->slug}")->assertOk()->json('data');

        $this->assertSame('in_bat', $data['cong_nghe']);
        $this->assertSame('In bạt', $data['cong_nghe_label']);
    }

    /**
     * `data.specs` **không** được mang bản thứ hai của hai trường ấy.
     *
     * Bản đầu của lát này đặt `cong_nghe` vào `specs` — chỗ nó trông thuộc về,
     * vì `specs` là nhóm thuộc tính vật lý. Nhưng `ScreenDetailResource` thừa
     * kế `ScreenSummaryResource`, nơi hai trường đã có ở tầng ngoài (DTO danh
     * sách không có `specs` nên ở đó không có lựa chọn khác). Kết quả: cùng một
     * cột `screen_specs.cong_nghe` đi ra hai khoá, và **phép tra
     * `CONG_NGHE_LABELS` viết hai lần**.
     *
     * Hôm nay hai bản không lệch được vì cùng đọc một cột. Cái ca này chặn là
     * ngày mai: ai thêm một mã thứ tư, hay sửa một chữ trong bảng nhãn, mà chỉ
     * sửa một nhánh thì thẻ danh sách và trang chi tiết in hai chữ khác nhau
     * cho cùng một màn hình — và không có gì đỏ để nói ra.
     *
     * `ScreenDetailApiTest::test_khong_them_truong_nao_ngoai_danh_sach_da_khai`
     * chỉ đếm khoá **tầng ngoài**, nên nó không thấy được bản sao nằm trong
     * `specs`. Đó là lý do ca này tồn tại riêng.
     */
    public function test_chi_tiet_khong_co_ban_sao_trong_specs(): void
    {
        $man = $this->manHinh('In bạt lớn', ['width_cm' => 1400, 'height_cm' => 700, 'cong_nghe' => 'in_bat']);

        $specs = $this->getJson("/api/v2/screens/{$man->slug}")->assertOk()->json('data.specs');

        $this->assertArrayNotHasKey('cong_nghe', $specs);
        $this->assertArrayNotHasKey('cong_nghe_label', $specs);
    }

    // ── Hai trường, hai câu hỏi ────────────────────────────────────────────

    public function test_man_led_co_lon_thuoc_ca_hai_nhom(): void
    {
        // Ca quan trọng nhất của tệp này. Trước lát này sàn chỉ có một trường,
        // nên một màn LED 6m buộc phải chọn: hoặc "Billboard" (đúng hình thức,
        // mất công nghệ) hoặc "LED" (đúng công nghệ, mất hình thức). Đó chính
        // là `OOHX_LED_CTO_CAN_THO` trên production — một màn LED mang nhãn
        // "Billboard" và không tìm được bằng bộ lọc LED.
        $man = $this->manHinh('LED cỡ lớn', ['width_cm' => 600, 'height_cm' => 300, 'cong_nghe' => 'led']);

        $the = $this->getJson('/api/v2/screens?per_page=50')->assertOk()->json('data.0');

        $this->assertSame('billboard', $the['screen_type'], 'Hình thức sai: cạnh 6m là một billboard.');
        $this->assertSame('led', $the['cong_nghe'], 'Công nghệ sai: media owner đã khai LED.');

        // Và lọc theo nhóm nào cũng phải thấy nó.
        $this->assertSame([$man->slug], collect(
            $this->getJson('/api/v2/screens?screen_type=billboard&per_page=50')->json('data')
        )->pluck('slug')->all());

        $this->assertSame([$man->slug], $this->slugLocDuoc('led'));
    }

    // ── Phép suy thôi đoán khi đã có khai báo ──────────────────────────────

    public function test_da_khai_thi_screen_type_khong_doan_nua(): void
    {
        // Màn hình nhỏ, điểm đặt **ngoài trời** — phép đoán cũ sẽ ra `led`.
        // Nhưng media owner khai `lcd`, nên `screen_type` phải theo khai báo.
        $ngoaiTroi = $this->ngoaiTroi();

        $this->manHinh('Nhỏ ngoài trời nhưng là LCD', ['width_cm' => 120, 'height_cm' => 80, 'cong_nghe' => 'lcd'], $ngoaiTroi);

        $the = $this->getJson('/api/v2/screens?per_page=50')->assertOk()->json('data.0');

        $this->assertSame(
            'lcd',
            $the['screen_type'],
            '`screen_type` vẫn đoán theo loại điểm đặt dù media owner đã khai công nghệ.',
        );
    }

    public function test_in_bat_nho_van_la_billboard(): void
    {
        // Một tấm in bạt không phải màn hình, bất kể cỡ nào. Không có bậc này
        // thì một pa-nô in bạt nhỏ hơn 3m rơi xuống phép đoán và ra `led` hoặc
        // `lcd` — tức sàn gọi một tấm bạt là một màn hình.
        $this->manHinh('Pa-nô in bạt nhỏ', ['width_cm' => 150, 'height_cm' => 100, 'cong_nghe' => 'in_bat'], $this->ngoaiTroi());

        $the = $this->getJson('/api/v2/screens?per_page=50')->assertOk()->json('data.0');

        $this->assertSame('billboard', $the['screen_type']);
        $this->assertSame('in_bat', $the['cong_nghe']);
    }

    public function test_chua_khai_thi_van_doan_nhu_truoc(): void
    {
        // Nhánh cũ phải còn nguyên cho 104 bản ghi chưa khai — nếu không thì
        // lát này làm `screen_type` của cả kho đổi cùng lúc.
        $this->manHinh('Nhỏ ngoài trời, chưa khai', ['width_cm' => 120, 'height_cm' => 80], $this->ngoaiTroi());

        $the = $this->getJson('/api/v2/screens?per_page=50')->assertOk()->json('data.0');

        $this->assertSame('led', $the['screen_type']);
        $this->assertNull($the['cong_nghe']);
    }

    // ── Bộ lọc ─────────────────────────────────────────────────────────────

    public function test_loc_theo_tung_ma(): void
    {
        $led    = $this->manHinh('A LED', ['width_cm' => 120, 'height_cm' => 80, 'cong_nghe' => 'led']);
        $lcd    = $this->manHinh('B LCD', ['width_cm' => 120, 'height_cm' => 80, 'cong_nghe' => 'lcd']);
        $inBat  = $this->manHinh('C in bạt', ['width_cm' => 400, 'height_cm' => 200, 'cong_nghe' => 'in_bat']);

        $this->assertSame([$led->slug], $this->slugLocDuoc('led'));
        $this->assertSame([$lcd->slug], $this->slugLocDuoc('lcd'));
        $this->assertSame([$inBat->slug], $this->slugLocDuoc('in_bat'));
    }

    public function test_chua_khai_bat_duoc_CA_HAI_hinh_dang(): void
    {
        // "Chưa khai" có hai hình dạng trong CSDL và cả hai đều có thật:
        // không có dòng `screen_specs` nào (20/104 bản ghi), hoặc có dòng mà
        // cột rỗng (84 bản ghi còn lại). Hỏi `whereNull` thôi là bỏ sót nhóm
        // thứ nhất — đúng cái bẫy bộ lọc loại hình đã sập ngày 19/10/2026.
        $khongSpec = $this->manHinh('Không có dòng spec', null);
        $cotRong   = $this->manHinh('Có spec, cột rỗng', ['width_cm' => 120, 'height_cm' => 80]);
        $this->manHinh('Đã khai', ['width_cm' => 120, 'height_cm' => 80, 'cong_nghe' => 'led']);

        $this->assertSame(
            collect([$khongSpec->slug, $cotRong->slug])->sort()->values()->all(),
            $this->slugLocDuoc('chua_khai'),
            'Bộ lọc "chưa khai" bỏ sót một trong hai hình dạng của ô trống.',
        );
    }

    public function test_bon_nhom_chia_het_tap_man_hinh(): void
    {
        $this->manHinh('A', ['width_cm' => 120, 'height_cm' => 80, 'cong_nghe' => 'led']);
        $this->manHinh('B', ['width_cm' => 120, 'height_cm' => 80, 'cong_nghe' => 'lcd']);
        $this->manHinh('C', ['width_cm' => 400, 'height_cm' => 200, 'cong_nghe' => 'in_bat']);
        $this->manHinh('D', ['width_cm' => 120, 'height_cm' => 80]);
        $this->manHinh('E', null);

        $tong = $this->getJson('/api/v2/screens?per_page=1')->json('meta.total');

        $cong = collect(['led', 'lcd', 'in_bat', 'chua_khai'])
            ->sum(fn ($ma) => count($this->slugLocDuoc($ma)));

        $this->assertSame(
            $tong,
            $cong,
            'Tổng bốn nhóm khác tổng số màn hình: có bản ghi rơi ra ngoài cả bốn, hoặc bị đếm '
                . 'hai lần. Mỗi màn hình có đúng một giá trị `cong_nghe` (kể cả rỗng), nên bốn '
                . 'bộ lọc phải chia hết tập.',
        );
    }

    // ── Ô đếm ở /filters ───────────────────────────────────────────────────

    public function test_o_dem_khop_tung_bo_loc_va_co_du_bon_nhom(): void
    {
        $this->manHinh('A', ['width_cm' => 120, 'height_cm' => 80, 'cong_nghe' => 'led']);
        $this->manHinh('B', ['width_cm' => 400, 'height_cm' => 200, 'cong_nghe' => 'in_bat']);
        $this->manHinh('C', null);

        $nhom = collect($this->getJson('/api/v2/filters')->assertOk()->json('data.cong_nghe'))
            ->keyBy('code');

        $this->assertSame(
            ['led', 'lcd', 'in_bat', 'chua_khai'],
            $nhom->keys()->all(),
            'Thiếu nhóm, hoặc thiếu `chua_khai`. Hôm nay `chua_khai` là 104/104 trên production: '
                . 'một cột lọc chỉ in ba số 0 đọc như "sàn không có màn hình nào".',
        );

        foreach ($nhom as $ma => $o) {
            $this->assertSame(
                $o['count'],
                count($this->slugLocDuoc((string) $ma)),
                "Ô đếm `{$ma}` ở /filters lệch khỏi tập /screens lọc được. Người dùng đọc con số "
                    . 'rồi bấm vào chính nó, và được một câu trả lời khác.',
            );
        }

        // Nhóm đếm 0 vẫn phải có mặt, cùng lý do như `screen_types`: giao diện
        // hiện nó mờ chứ không giấu.
        $this->assertSame(0, $nhom['lcd']['count']);
        $this->assertSame('Chưa khai', $nhom['chua_khai']['name']);
    }

    public function test_o_dem_lay_nhan_tu_cung_bang_chu(): void
    {
        $this->manHinh('A', ['width_cm' => 120, 'height_cm' => 80, 'cong_nghe' => 'led']);

        $nhom = collect($this->getJson('/api/v2/filters')->json('data.cong_nghe'))->keyBy('code');

        foreach (ScreenSpec::CONG_NGHE_LABELS as $ma => $nhan) {
            $this->assertSame($nhan, $nhom[$ma]['name'], "Ô đếm `{$ma}` không dùng bảng chữ chung.");
        }
    }

    public function test_ma_la_bi_bo_qua_chu_khong_tra_rong(): void
    {
        // Cùng hành vi như `applyScreenTypeFilter`: mã không hợp lệ thì bỏ qua
        // bộ lọc, không trả danh sách rỗng. Một người gõ tay `?cong_nghe=oled`
        // phải thấy cả kho, không phải một trang trắng không giải thích gì.
        $this->manHinh('A', ['width_cm' => 120, 'height_cm' => 80, 'cong_nghe' => 'led']);

        $this->assertCount(1, $this->getJson('/api/v2/screens?cong_nghe=oled&per_page=50')->json('data'));
    }

    // ── Đường nhập kho ─────────────────────────────────────────────────────

    public function test_duong_nhap_kho_co_cot_cong_nghe(): void
    {
        // Không có dòng này thì cột mới chỉ khai được từng vị trí một trên
        // Filament, và 104 vị trí sẽ không bao giờ được khai.
        $khoa = collect(\App\Services\ScreenImport\FieldCatalog::all())->pluck('key');

        $this->assertTrue(
            $khoa->contains('spec.cong_nghe'),
            'Danh mục cột nhập kho không có `spec.cong_nghe`.',
        );
    }
}
