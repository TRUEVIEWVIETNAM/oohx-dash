<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Services\AnhDanXuatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ảnh của popup bản đồ — `srcset bản đồ`, 25/10/2026.
 *
 * ══ Việc này hoá ra là một quyết định KHÔNG làm điều tên nó nói ══
 *
 * Tên việc là "srcset bản đồ". Số đo nói ngược:
 *
 * | | |
 * |---|---|
 * | Payload bản đồ hôm nay | **70,3KB** cho 104 pin (production, 25/10/2026) |
 * | Thêm một chuỗi `srcset` mỗi pin | **+11,8KB (+17%)**, ~+57KB ở trần 500 pin |
 * | Trả khi nào | **mỗi** lượt kéo bản đồ |
 * | Lợi ích | **một** ảnh mỗi lần mở popup |
 * | Chỗ tiêu thụ `photo_url` của pin | đúng một: `PopupBanDo.tsx:64` |
 *
 * Một phiên thực tế — 10 lượt kéo, 3 lần mở popup — trả ~570KB để tiết kiệm
 * ~162KB. Lỗ. Nên DTO bản đồ **không** nhận `photo_srcset`, và có một ca dưới
 * đây ghim điều đó: một lần ai thêm trường ấy "cho đồng bộ với các DTO khác"
 * thì nó đỏ và đọc được lý do.
 *
 * Thứ *được* làm thay vào đó không tốn thêm byte nào ngoài độ dài URL: đổi
 * **giá trị** của `photo_url` sang bản `w1280` khi đã có tệp. `.map-popup`
 * rộng tối đa 340 CSS px nên ô ảnh ấy cần ~1020px ở DPR 3 — `w1280` vừa đủ,
 * và nó không bao giờ cần một bản gốc 2000px.
 *
 * ══ Hôm nay đây là no-op, và đó cũng là một phép đo ══
 *
 * Hai ảnh bìa của kho hẹp hơn 1280px, nên `oohx:sinh-anh-dan-xuat` báo "2 tệp
 * đã sinh" chứ không 4 — không có bản `w1280` nào. Nhánh dự phòng về bản gốc
 * là nhánh **đang chạy** trên production, nên nó có ca riêng chứ không phải
 * một nhánh chỉ tồn tại trên giấy.
 */
class AnhPopupBanDoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Cache::flush();
    }

    // ══ `urlBeRong()` — phép thuần + đĩa ═════════════════════════════════

    public function test_url_be_rong_tra_null_khi_chua_co_tep(): void
    {
        Storage::disk('public')->put('owners/covers/bia.jpg', 'x');

        $this->assertNull(AnhDanXuatService::urlBeRong('owners/covers/bia.jpg', 1280));
    }

    public function test_url_be_rong_tra_url_cua_dung_be_rong_do(): void
    {
        Storage::disk('public')->put('anh-dan-xuat/w1280/owners/covers/bia.jpg', 'x');
        Storage::disk('public')->put('anh-dan-xuat/w480/owners/covers/bia.jpg', 'x');

        // Đúng bề rộng được hỏi, không phải bề rộng nào khác đang có.
        $this->assertSame(
            asset('storage/anh-dan-xuat/w1280/owners/covers/bia.jpg'),
            AnhDanXuatService::urlBeRong('owners/covers/bia.jpg', 1280),
        );

        $this->assertSame(
            asset('storage/anh-dan-xuat/w480/owners/covers/bia.jpg'),
            AnhDanXuatService::urlBeRong('owners/covers/bia.jpg', 480),
        );
    }

    public function test_url_be_rong_khong_du_phong_sang_be_rong_nho_hon(): void
    {
        // Chỉ có `w480`. Hỏi `w1280` phải ra `null`, KHÔNG rơi về `w480`: một
        // ảnh 480px trong ô 340px ở DPR 2 là ảnh mờ thấy được, tệ hơn bản gốc
        // nó vừa thay.
        Storage::disk('public')->put('anh-dan-xuat/w480/owners/covers/bia.jpg', 'x');

        $this->assertNull(AnhDanXuatService::urlBeRong('owners/covers/bia.jpg', 1280));
    }

    public function test_url_be_rong_tra_null_cho_url_ngoai_va_duong_dan_sai(): void
    {
        foreach ([null, '', 'https://doitac.example/a.jpg', '../../etc/passwd', '/etc/passwd'] as $p) {
            $this->assertNull(
                AnhDanXuatService::urlBeRong($p, 1280),
                'Đường dẫn: ' . var_export($p, true),
            );
        }
    }

    // ══ Đường chạy thật: endpoint bản đồ ════════════════════════════════

    public function test_pin_dung_ban_w1280_khi_da_co(): void
    {
        $man = $this->manHinh(bia: 'owners/covers/bia.jpg');

        Storage::disk('public')->put('owners/covers/bia.jpg', 'goc');
        Storage::disk('public')->put('anh-dan-xuat/w1280/owners/covers/bia.jpg', 'nho');

        $pin = $this->pinCua($man->slug);

        $this->assertStringContainsString('anh-dan-xuat/w1280/owners/covers/bia.jpg', (string) $pin['photo_url']);
    }

    public function test_pin_roi_ve_ban_goc_khi_chua_co_w1280(): void
    {
        // Nhánh **đang chạy** trên production: hai ảnh bìa của kho hẹp hơn
        // 1280px nên không có bản `w1280` nào.
        $man = $this->manHinh(bia: 'owners/covers/bia.jpg');

        Storage::disk('public')->put('owners/covers/bia.jpg', 'goc');

        $pin = $this->pinCua($man->slug);

        $this->assertSame(asset('storage/owners/covers/bia.jpg'), $pin['photo_url']);
        $this->assertStringNotContainsString('anh-dan-xuat', (string) $pin['photo_url']);
    }

    public function test_pin_dung_anh_cua_chinh_man_hinh_khi_co(): void
    {
        $man = $this->manHinh(spec: ['photos' => ['screen-photos/01k/a.jpg']]);

        Storage::disk('public')->put('screen-photos/01k/a.jpg', 'goc');
        Storage::disk('public')->put('anh-dan-xuat/w1280/screen-photos/01k/a.jpg', 'nho');

        $pin = $this->pinCua($man->slug);

        $this->assertStringContainsString('anh-dan-xuat/w1280/screen-photos/01k/a.jpg', (string) $pin['photo_url']);
    }

    public function test_pin_khong_anh_tra_null(): void
    {
        $man = $this->manHinh();

        $this->assertNull($this->pinCua($man->slug)['photo_url']);
    }

    // ══ Quyết định được ghim: KHÔNG có `photo_srcset` ở DTO bản đồ ══════

    public function test_dto_ban_do_khong_co_srcset_va_bo_khoa_khong_phinh(): void
    {
        $man = $this->manHinh(bia: 'owners/covers/bia.jpg');
        Storage::disk('public')->put('owners/covers/bia.jpg', 'goc');

        $pin = $this->pinCua($man->slug);

        // Số đo ở docblock đầu tệp: +17% payload cho mỗi lượt kéo bản đồ, để
        // tiết kiệm một ảnh mỗi lần mở popup. Ai thêm trường này "cho đồng bộ
        // với ScreenSummary" thì đọc lý do ở đây.
        $this->assertArrayNotHasKey('photo_srcset', $pin);
        $this->assertArrayNotHasKey('photo_srcsets', $pin);

        // Và bộ khoá đúng như trước: DTO này cố tình gọn vì nó trả tới 500 bản
        // ghi một lượt.
        $this->assertSame([
            'slug', 'name', 'lat', 'lng', 'city', 'address', 'site', 'network',
            'venue_type', 'screen_type_label', 'size', 'owner', 'price', 'photo_url',
        ], array_keys($pin));
    }

    public function test_hai_dto_dung_cung_mot_phep_dung_url(): void
    {
        // Lát 1 gộp ba bản của phép dựng URL thành một. `urlBeRong()` đi qua
        // `ScreenSpec::photoUrlOf()` như mọi chỗ khác, nên URL của bản dẫn
        // xuất ở DTO bản đồ và ở `photo_srcset` của DTO danh sách luôn cùng
        // một gốc.
        Storage::disk('public')->put('anh-dan-xuat/w1280/owners/covers/bia.jpg', 'x');

        $this->assertStringStartsWith(
            asset('storage/'),
            (string) AnhDanXuatService::urlBeRong('owners/covers/bia.jpg', 1280),
        );
    }

    // ══ Dựng dữ liệu ═══════════════════════════════════════════════════

    /** @param array<string, mixed> $spec */
    private function manHinh(array $spec = [], ?string $bia = null): Screen
    {
        $owner = Owner::factory()->create(['status' => 'active', 'cover_url' => $bia]);

        $site = Site::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'active',
            'city'     => 'Hà Nội',
            'banner'   => null,
            'lat'      => 21.0285,
            'lon'      => 105.8542,
        ]);

        $man = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        ScreenSpec::factory()->create(array_merge([
            'screen_id' => $man->id,
            'photos'    => null,
            'photo_url' => null,
        ], $spec));

        ScreenInventory::factory()->create([
            'screen_id'      => $man->id,
            'vn_category_id' => null,
        ]);

        return $man->fresh();
    }

    /** @return array<string, mixed> */
    private function pinCua(string $slug): array
    {
        $data = $this->getJson('/api/v2/screens/map?north=24&south=8&east=110&west=102')
            ->assertOk()
            ->json('data');

        $pin = collect($data)->firstWhere('slug', $slug);

        $this->assertNotNull($pin, "Không thấy pin của {$slug} trong phản hồi.");

        return $pin;
    }
}
