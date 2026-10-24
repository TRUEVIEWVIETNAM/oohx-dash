<?php

namespace Tests\Feature\Publisher;

use App\Filament\Publisher\Resources\ScreenResource;
use App\Models\Network;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cột "Ảnh" và bộ lọc "chỉ vị trí thiếu ảnh" — lát 3 của
 * `docs/PLAN-ANH-VI-TRI.md`.
 *
 * ══ Hai đường, và chúng phải nói cùng một điều ══
 *
 * Cột đọc `Screen::displayPhotoWithSource()` ở **PHP**, từng hàng. Bộ lọc và
 * phép sắp đọc `Screen::SQL_CO_ANH_THAT` ở **SQL**, trên cả tập. Hai đường
 * khác nhau cho cùng một câu hỏi là đúng hình dạng mà lượt đột biến thứ sáu
 * của lát 0 đi qua được — nên tệp này canh **sự khớp nhau** của chúng, không
 * chỉ canh từng cái.
 *
 * ══ Một chỗ chúng KHÔNG khớp, và nó được ghim ══
 *
 * SQL không nhìn vào trong mảng JSON, nên `photos: ["", ""]` bị tính là có ảnh
 * ở SQL và không có ảnh ở PHP. Hàng đó không đường ghi nào của hệ thống tạo ra
 * được — biểu mẫu Filament ghi tên tệp nó tự đặt, đường nhập kho từ chối ô
 * rỗng từ lát 1. Ca cuối tệp này ghim đúng chỗ lệch đó, để nó là một điều đã
 * biết chứ không phải một điều sẽ được phát hiện lại.
 */
class AnhPublisherTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'publisher', 'guard_name' => 'web']);

        // Dàn cảnh khi chưa đăng nhập ai: global scope thoát sớm cho khách,
        // nên không hàng rào nào can thiệp lúc dựng fixture.
        $this->owner = Owner::factory()->create(['status' => 'active', 'name' => 'Chủ Một']);
    }

    /**
     * @param  array<string, mixed>  $spec  cột `screen_specs`; `null` = không có hàng spec nào
     * @param  array<string, mixed>  $site  cột `sites`
     */
    private function manHinh(
        ?array $spec = [],
        array $site = [],
        ?Owner $owner = null,
        ?string $bia = null,
        ?int $vnCategoryId = null,
    ): Screen {
        $chu = $owner ?? $this->owner;

        if ($bia !== null) {
            $chu->update(['cover_url' => $bia]);
        }

        $diaDiem = Site::factory()->create(array_merge([
            'owner_id' => $chu->id,
            'status'   => 'active',
            'city'     => 'Hà Nội',
        ], $site));

        $man = Screen::factory()->create([
            'owner_id' => $chu->id,
            'site_id'  => $diaDiem->id,
            'active'   => true,
        ]);

        if ($spec !== null) {
            ScreenSpec::factory()->create(array_merge([
                'screen_id' => $man->id,
                'photos'    => null,
                'photo_url' => null,
            ], $spec));
        }

        ScreenInventory::factory()->create([
            'screen_id'      => $man->id,
            'vn_category_id' => $vnCategoryId,
        ]);

        return $man->fresh();
    }

    private function nhan(Screen $man): string
    {
        // `nhanAnh` là `protected static` trên lớp base — gọi qua closure gắn
        // vào lớp, đúng cách `tests/Feature/Filament` gọi getter của widget.
        return \Closure::bind(
            fn () => ScreenResource::nhanAnh($man),
            null,
            ScreenResource::class,
        )();
    }

    // ── Ba trạng thái của cột ───────────────────────────────────────────────

    public function test_cot_noi_co_bao_nhieu_anh_that(): void
    {
        $man = $this->manHinh(['photos' => ['screen-photos/a.jpg', 'screen-photos/b.jpg']]);

        // Con số trong ngoặc là thứ chủ vị trí dùng để biết mình đã nạp mấy
        // ảnh — "Thật" một mình không phân biệt 1 ảnh với 10.
        $this->assertSame('Thật (2)', $this->nhan($man));

        // ── Con số phải đếm ĐƯỜNG DẪN ĐÃ LỌC, không đếm cột thô ──
        //
        // Hai hình dạng dưới đây là chỗ hai phép đếm tách nhau, và một đột
        // biến đổi `photoPaths()` thành `spec->photos` đi qua được cả chín ca
        // khi chúng còn thiếu:
        //
        //   • hàng chỉ có `photo_url` → cột `photos` là null → đếm cột thô ra
        //     "Thật (0)", một con số tự mâu thuẫn với chính chữ "Thật";
        //   • hàng trộn một đường dẫn thật với một ô rỗng → đếm cột thô ra 2
        //     trong khi chỉ một ảnh hiện được.
        $chiPhotoUrl = $this->manHinh(['photos' => null, 'photo_url' => 'screen-photos/cu.jpg']);
        $this->assertSame('Thật (1)', $this->nhan($chiPhotoUrl));

        $tron = $this->manHinh(['photos' => ['', 'screen-photos/a.jpg', '   ']]);
        $this->assertSame('Thật (1)', $this->nhan($tron));
    }

    public function test_cot_noi_dang_muon_anh_cua_ai(): void
    {
        $man = $this->manHinh([], [], null, 'owners/covers/bia.webp');

        $this->assertSame('Mượn: ảnh bìa chủ sở hữu', $this->nhan($man));

        // Và bậc khác thì nói tên bậc khác — gộp chúng là mất thông tin về
        // việc cần làm gì.
        // Chữ lấy nguyên từ `SoatAnhKhoService::NHAN_NGUON`, không viết lại ở
        // đây: nếu bảng và lệnh soát gọi cùng một bậc bằng hai tên thì chủ vị
        // trí đọc hai báo cáo và tưởng là hai chuyện.
        $banner = $this->manHinh([], ['banner' => 'sites/b.jpg']);
        $this->assertSame('Mượn: banner địa điểm', $this->nhan($banner));

        $net = Network::factory()->create(['owner_id' => $this->owner->id, 'banner' => 'networks/b.jpg']);
        $mang = $this->manHinh([], ['network_id' => $net->id]);
        $this->assertSame('Mượn: banner mạng lưới', $this->nhan($mang));
    }

    public function test_cot_noi_khong_co_gi(): void
    {
        $trang = Owner::factory()->create(['status' => 'active', 'cover_url' => null]);

        $this->assertSame('Không có', $this->nhan($this->manHinh([], [], $trang)));

        // Màn hình chưa có hàng `screen_specs` nào cũng là "không có".
        $this->assertSame('Không có', $this->nhan($this->manHinh(null, [], $trang)));
    }

    public function test_phan_tu_rong_khong_duoc_dem_la_anh(): void
    {
        $trang = Owner::factory()->create(['status' => 'active', 'cover_url' => null]);

        // `asset('storage/')` không trỏ vào ảnh nào, nên "Thật (2)" ở đây là
        // một con số nói sai với chính người sắp sửa nó.
        $this->assertSame('Không có', $this->nhan($this->manHinh(['photos' => ['', '  ']], [], $trang)));
    }

    // ── Bộ lọc ở SQL ────────────────────────────────────────────────────────

    public function test_bo_loc_bat_dung_vi_tri_thieu_anh(): void
    {
        $coAnh    = $this->manHinh(['photos' => ['screen-photos/a.jpg']]);
        $coAnhCu  = $this->manHinh(['photos' => null, 'photo_url' => 'screen-photos/cu.jpg']);
        $muonBia  = $this->manHinh([], [], null, 'owners/covers/bia.webp');
        $khongSpec = $this->manHinh(null);

        $thieu = Screen::query()->withoutGlobalScopes()->thieuAnhThat()->pluck('id')->all();

        // "Thiếu ảnh" nghĩa là thiếu ảnh CỦA CHÍNH NÓ — một màn hình đang mượn
        // ảnh bìa vẫn là một màn hình phải nạp ảnh.
        $this->assertContains($muonBia->id, $thieu);
        $this->assertContains($khongSpec->id, $thieu, 'Màn hình chưa có spec cũng thiếu ảnh.');
        $this->assertNotContains($coAnh->id, $thieu);
        $this->assertNotContains($coAnhCu->id, $thieu, '`photo_url` một mình vẫn là ảnh thật.');

        // Và hai scope phải bù nhau đúng: mọi hàng thuộc đúng một bên.
        $co = Screen::query()->withoutGlobalScopes()->coAnhThat()->pluck('id')->all();

        $this->assertCount(4, array_merge($co, $thieu));
        $this->assertSame([], array_intersect($co, $thieu), 'Một màn hình nằm ở cả hai nhóm.');
    }

    /**
     * **Hai đường phải nói cùng một điều** — cái chốt thật của lát 3.
     *
     * Cột tính ở PHP, bộ lọc tính ở SQL. Ca này dựng mọi hình dạng mà các
     * đường ghi của hệ thống sinh ra được và đòi hai đường khớp trên từng hàng.
     */
    public function test_cot_PHP_va_bo_loc_SQL_khop_tren_moi_hinh_dang_he_thong_sinh_ra(): void
    {
        $trang = Owner::factory()->create(['status' => 'active', 'cover_url' => null]);

        $hinhDang = [
            'nhiều ảnh'            => ['photos' => ['screen-photos/a.jpg', 'screen-photos/b.jpg']],
            'một ảnh'              => ['photos' => ['screen-photos/a.jpg']],
            'chỉ photo_url'        => ['photos' => null, 'photo_url' => 'screen-photos/cu.jpg'],
            'url ngoài'            => ['photos' => ['https://doitac.example/a.jpg']],
            'mảng rỗng'            => ['photos' => []],
            'không cột nào'        => ['photos' => null, 'photo_url' => null],
        ];

        foreach ($hinhDang as $ten => $spec) {
            $man = $this->manHinh($spec, [], $trang);

            $phpCoAnh = str_starts_with($this->nhan($man), 'Thật');
            $sqlCoAnh = Screen::query()->withoutGlobalScopes()
                ->whereKey($man->id)->coAnhThat()->exists();

            $this->assertSame(
                $phpCoAnh,
                $sqlCoAnh,
                "Cột và bộ lọc nói hai điều khác nhau cho hình dạng \"{$ten}\".",
            );
        }
    }

    /**
     * Chỗ hai đường KHÔNG khớp, ghim lại.
     *
     * Hàng này chỉ tạo được bằng cách sửa tay trong CSDL. Nếu sau này có một
     * đường ghi sinh ra nó thì ca này vẫn xanh (nó mô tả đúng hiện trạng),
     * nhưng docblock của `Screen::SQL_CO_ANH_THAT` nói rõ phải quay lại đây.
     */
    public function test_hang_chi_chua_phan_tu_rong_la_cho_hai_duong_lech_nhau(): void
    {
        $trang = Owner::factory()->create(['status' => 'active', 'cover_url' => null]);
        $man   = $this->manHinh(['photos' => ['', '   ']], [], $trang);

        $this->assertSame('Không có', $this->nhan($man), 'PHP phải nói không có ảnh.');

        $this->assertTrue(
            Screen::query()->withoutGlobalScopes()->whereKey($man->id)->coAnhThat()->exists(),
            'SQL không nhìn vào trong mảng JSON — ghi lại chỗ lệch này, đừng sửa ca cho khớp.',
        );
    }

    // ── Phép sắp ────────────────────────────────────────────────────────────

    public function test_sap_theo_cot_anh_dua_vi_tri_thieu_anh_len_truoc(): void
    {
        $muon  = $this->manHinh([], [], null, 'owners/covers/bia.webp');
        $coAnh = $this->manHinh(['photos' => ['screen-photos/a.jpg']]);

        $thuTu = Screen::query()->withoutGlobalScopes()
            ->orderBy(
                DB::table('screen_specs')
                    ->selectRaw('CASE WHEN ' . Screen::SQL_CO_ANH_THAT . ' THEN 1 ELSE 0 END')
                    ->whereColumn('screen_specs.screen_id', 'screens.id')
                    ->limit(1),
                'asc',
            )
            ->pluck('id')->all();

        // Tăng dần = thiếu ảnh trước. Đó là thứ tự một chủ vị trí cần: mở đúng
        // nhóm phải sửa, không phải nhóm đã xong.
        $this->assertSame([$muon->id, $coAnh->id], $thuTu);
    }

    // ── Hàng rào tenant ─────────────────────────────────────────────────────

    public function test_chu_so_huu_khong_dem_duoc_vi_tri_cua_nguoi_khac(): void
    {
        $khac = Owner::factory()->create(['status' => 'active', 'name' => 'Chủ Hai']);

        $cuaToi    = $this->manHinh([], [], null, 'owners/covers/bia.webp');
        $cuaNguoiTa = $this->manHinh([], [], $khac, 'owners/covers/khac.webp');

        $toi = User::factory()->create(['current_owner_id' => $this->owner->id]);
        $toi->assignRole('publisher');
        $this->actingAs($toi);

        $thay = ScreenResource::getEloquentQuery()->thieuAnhThat()->pluck('id')->all();

        $this->assertContains($cuaToi->id, $thay);
        $this->assertNotContains(
            $cuaNguoiTa->id,
            $thay,
            'Bộ lọc ảnh đi qua hàng rào tenant — một chủ vị trí không được đếm kho của người khác.',
        );
    }
}
