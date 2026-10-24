<?php

namespace Tests\Feature\Console;

use App\Models\Network;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Services\SoatAnhKhoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `oohx:soat-anh-kho` — lát 0 của `docs/PLAN-ANH-VI-TRI.md`.
 *
 * ══ Vì sao lệnh này cần chốt ══
 *
 * Nó là **phép đo** mà bốn lát sau dựa vào để biết mình có làm được gì không.
 * Một phép đo sai không báo lỗi: nó báo một con số, và con số sai thì dẫn tới
 * quyết định sai mà không ai thấy. Nên mỗi con số lệnh in ra có một ca riêng
 * trên một kho giả dựng **đủ ba trạng thái và đủ bốn bậc dự phòng**.
 *
 * ══ Cái chốt thật của tệp này ══
 *
 * `test_phan_loai_dung_ba_trang_thai`: đổi một màn hình từ "có ảnh thật" sang
 * "chỉ còn bìa owner" phải đổi đúng hai con số. Đó là đột biến mà plan đòi, và
 * nó là đột biến đáng lo nhất — vì nếu phép phân loại trôi thì mọi lát sau đo
 * trên một thế giới không phải thế giới thật.
 */
class SoatAnhKhoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Đĩa giả: lệnh đi bộ qua `screen-photos` để đo dung lượng và tìm tệp
        // mất, nên nó phải đọc một đĩa mà test kiểm soát được.
        Storage::fake('public');
    }

    private function owner(string $ten, ?string $bia = null): Owner
    {
        return Owner::factory()->create([
            'status'    => 'active',
            'name'      => $ten,
            'cover_url' => $bia,
        ]);
    }

    /**
     * Một màn hình công khai, với đúng những thứ chuỗi dự phòng đi qua.
     *
     * @param  array<string, mixed>  $spec   cột của `screen_specs`
     * @param  array<string, mixed>  $site   cột của `sites`
     */
    private function manHinh(Owner $owner, array $spec = [], array $site = [], ?int $vnCategoryId = null): Screen
    {
        $s = Site::factory()->create(array_merge([
            'owner_id' => $owner->id,
            'status'   => 'active',
            'city'     => 'Hà Nội',
        ], $site));

        $man = Screen::factory()->create([
            'owner_id' => $owner->id,
            'site_id'  => $s->id,
            'active'   => true,
        ]);

        ScreenSpec::factory()->create(array_merge([
            'screen_id'  => $man->id,
            'photos'     => null,
            'photo_url'  => null,
        ], $spec));

        ScreenInventory::factory()->create([
            'screen_id'      => $man->id,
            'vn_category_id' => $vnCategoryId,
        ]);

        return $man->fresh();
    }

    private function soat(bool $doDia = true): array
    {
        return app(SoatAnhKhoService::class)->soat($doDia);
    }

    // ── Phân loại ba trạng thái ─────────────────────────────────────────────

    public function test_phan_loai_dung_ba_trang_thai(): void
    {
        $o = $this->owner('Chủ Một', 'owners/covers/bia.webp');

        Storage::disk('public')->put('screen-photos/a.jpg', 'x');

        $coAnh  = $this->manHinh($o, ['photos' => ['screen-photos/a.jpg']]);
        $this->manHinh($o);                                       // chỉ còn bìa owner
        $this->manHinh($this->owner('Chủ Trắng'));                // không gì cả

        $kq = $this->soat();

        $this->assertSame(3, $kq['tong_man_hinh']);
        $this->assertSame(1, $kq['tong'][SoatAnhKhoService::CO_ANH]);
        $this->assertSame(1, $kq['tong'][SoatAnhKhoService::THAY_THE]);
        $this->assertSame(1, $kq['tong'][SoatAnhKhoService::KHONG_CO]);

        // ── Đột biến mà plan đòi, viết thành một ca ──
        //
        // Gỡ ảnh thật của một màn hình: nó phải rời nhóm "có ảnh thật" và sang
        // nhóm "mượn ảnh thay thế" — bìa owner của chủ nó vẫn còn đó. Hai con
        // số đổi cùng lúc; một con số đổi là phép phân loại đã trôi.
        $coAnh->spec->update(['photos' => null]);

        $sau = $this->soat();

        $this->assertSame(0, $sau['tong'][SoatAnhKhoService::CO_ANH]);
        $this->assertSame(2, $sau['tong'][SoatAnhKhoService::THAY_THE]);
        $this->assertSame(1, $sau['tong'][SoatAnhKhoService::KHONG_CO]);
    }

    public function test_photo_url_mot_minh_cung_la_anh_that(): void
    {
        // `photos` rỗng nhưng `photo_url` có: đó là hình dạng của mọi hàng
        // nhập trước migration `add_photos_to_screen_specs_table`, và nó vẫn
        // là ảnh của chính màn hình.
        $this->manHinh($this->owner('Chủ Một'), ['photos' => [], 'photo_url' => 'screen-photos/cu.jpg']);

        $this->assertSame(1, $this->soat()['tong'][SoatAnhKhoService::CO_ANH]);
    }

    public function test_bon_bac_du_phong_moi_bac_mang_ten_cua_no(): void
    {
        $net = Network::factory()->create(['banner' => 'networks/b.jpg']);

        $this->manHinh($this->owner('Có banner địa điểm'), [], ['banner' => 'sites/b.jpg']);
        $this->manHinh($this->owner('Có banner mạng lưới'), [], ['network_id' => $net->id]);
        $this->manHinh($this->owner('Có bìa owner', 'owners/covers/bia.webp'));

        $kq = $this->soat();

        $this->assertSame(3, $kq['tong'][SoatAnhKhoService::THAY_THE]);
        $this->assertSame(
            ['site' => 1, 'network' => 1, 'owner_cover' => 1],
            $kq['theo_nguon'],
            'Ba bậc phải được đếm RIÊNG: gộp chúng là mất thông tin về việc cần làm gì.',
        );
    }

    // ── Theo chủ sở hữu ─────────────────────────────────────────────────────

    public function test_dem_rieng_tung_chu_so_huu(): void
    {
        // Đúng hình dạng production ngày 24/10/2026: hai chủ sở hữu, mỗi người
        // một ảnh bìa, và mọi vị trí của họ mượn nó.
        $a = $this->owner('Kim Ngân ADV', 'owners/covers/a.jpg');
        $b = $this->owner('AdTRUE Platform', 'owners/covers/b.webp');

        for ($i = 0; $i < 3; $i++) {
            $this->manHinh($a);
        }

        $this->manHinh($b);
        $this->manHinh($b, ['photos' => ['screen-photos/that.jpg']]);

        $kq    = $this->soat();
        $theo  = collect($kq['theo_owner'])->keyBy('owner_name');

        $this->assertSame(3, $theo['Kim Ngân ADV']['tong']);
        $this->assertSame(0, $theo['Kim Ngân ADV'][SoatAnhKhoService::CO_ANH]);
        $this->assertSame(3, $theo['Kim Ngân ADV'][SoatAnhKhoService::THAY_THE]);
        $this->assertSame(['owner_cover' => 3], $theo['Kim Ngân ADV']['theo_nguon']);

        $this->assertSame(2, $theo['AdTRUE Platform']['tong']);
        $this->assertSame(1, $theo['AdTRUE Platform'][SoatAnhKhoService::CO_ANH]);
        $this->assertSame(1, $theo['AdTRUE Platform'][SoatAnhKhoService::THAY_THE]);
    }

    // ── Phạm vi ─────────────────────────────────────────────────────────────

    public function test_man_hinh_tat_va_chu_so_huu_ngung_hoat_dong_nam_NGOAI_pham_vi(): void
    {
        $o = $this->owner('Chủ Một', 'owners/covers/bia.webp');

        $this->manHinh($o);
        $this->manHinh($o)->update(['active' => false]);

        $ngung = Owner::factory()->create(['status' => 'suspended', 'cover_url' => 'owners/covers/x.jpg']);
        $this->manHinh($ngung);

        $kq = $this->soat();

        // Người mua không thấy ảnh của hai màn hình kia, nên chúng không nằm
        // trong phép đo — nhưng số của chúng phải nói ra, không thì tổng ở
        // trên bị đọc thành "cả kho".
        $this->assertSame(1, $kq['tong_man_hinh']);
        $this->assertSame(2, $kq['ngoai_pham_vi']);
    }

    public function test_man_hinh_da_xoa_khong_tinh_vao_ngoai_pham_vi(): void
    {
        $o = $this->owner('Chủ Một');

        $this->manHinh($o);
        $this->manHinh($o)->delete();

        // `withoutGlobalScopes()` dạng số nhiều gỡ luôn scope của `SoftDeletes`
        // và sẽ đếm bản ghi đã xoá vào đây. Một con số "ngoài phạm vi" phình
        // lên vì bản ghi đã xoá thì không nói gì về kho đang sống.
        $this->assertSame(0, $this->soat()['ngoai_pham_vi']);
    }

    // ── Hai con số là LỖI, không phải thống kê ──────────────────────────────

    public function test_dem_phan_tu_la_url_day_du(): void
    {
        $o = $this->owner('Chủ Một');

        $this->manHinh($o, ['photos' => ['https://doitac.example/a.jpg', 'https://doitac.example/b.jpg']]);
        $this->manHinh($o, ['photos' => ['screen-photos/noi-bo.jpg']]);

        $kq = $this->soat();

        // Hai phần tử trên MỘT màn hình: đếm cả hai trục, vì "bao nhiêu hàng
        // phải sửa" và "bao nhiêu phần tử phải sửa" là hai con số khác nhau.
        $this->assertSame(1, $kq['url_ngoai']['so_man_hinh']);
        $this->assertSame(2, $kq['url_ngoai']['so_phan_tu']);
        $this->assertSame('https://doitac.example/a.jpg', $kq['url_ngoai']['vi_du'][0]['duong']);

        // URL ngoài vẫn là ảnh của chính màn hình — nó chỉ nằm ở chỗ khác.
        $this->assertSame(2, $kq['tong'][SoatAnhKhoService::CO_ANH]);
    }

    public function test_dem_tep_khai_co_ma_khong_con_tren_dia(): void
    {
        $o = $this->owner('Chủ Một');

        Storage::disk('public')->put('screen-photos/con.jpg', 'x');

        $this->manHinh($o, ['photos' => ['screen-photos/con.jpg']]);
        $this->manHinh($o, ['photos' => ['screen-photos/mat.jpg', 'screen-photos/mat-2.jpg']]);

        $kq = $this->soat();

        $this->assertSame(1, $kq['tep_mat']['so_man_hinh']);
        $this->assertSame(2, $kq['tep_mat']['so_phan_tu']);
    }

    public function test_url_ngoai_khong_bi_dem_la_tep_mat(): void
    {
        // Kiểm một URL ngoài là đi gọi mạng, và một lệnh soát không được treo
        // vì máy chủ của người khác chậm. Nên URL ngoài KHÔNG bao giờ vào
        // nhóm "tệp mất", dù `Storage::exists()` trả false cho nó.
        $this->manHinh($this->owner('Chủ Một'), ['photos' => ['https://doitac.example/a.jpg']]);

        $this->assertSame(0, $this->soat()['tep_mat']['so_man_hinh']);
    }

    // ── Số ảnh ──────────────────────────────────────────────────────────────

    public function test_so_anh_dung_trung_vi_khong_dung_trung_binh(): void
    {
        $o = $this->owner('Chủ Một');

        // Bốn vị trí 1 ảnh, một vị trí 10 ảnh. Trung bình là 2,8 — một con số
        // không mô tả vị trí nào trong kho. Trung vị là 1.
        for ($i = 0; $i < 4; $i++) {
            $this->manHinh($o, ['photos' => ["screen-photos/{$i}.jpg"]]);
        }

        $this->manHinh($o, ['photos' => array_map(fn ($i) => "screen-photos/nhieu-{$i}.jpg", range(1, 10))]);

        $kq = $this->soat();

        $this->assertSame(5, $kq['so_anh']['so_vi_tri_co_anh']);
        $this->assertSame(1, $kq['so_anh']['trung_vi']);
        $this->assertSame(10, $kq['so_anh']['lon_nhat']);
        $this->assertSame(14, $kq['so_anh']['tong_phan_tu']);
    }

    public function test_kho_khong_anh_nao_thi_khong_doan_trung_vi(): void
    {
        $this->manHinh($this->owner('Chủ Một', 'owners/covers/bia.webp'));

        $kq = $this->soat();

        // `null`, không phải `0`: "trung vị 0 ảnh" là một kết luận từ dữ liệu
        // không có. Cùng lý do `lechTiLe()` của lát 4 trả null.
        $this->assertSame(0, $kq['so_anh']['so_vi_tri_co_anh']);
        $this->assertNull($kq['so_anh']['trung_vi']);
    }

    // ── Đĩa ─────────────────────────────────────────────────────────────────

    public function test_do_dia_dem_tep_va_tim_tep_lon_nhat(): void
    {
        Storage::disk('public')->put('screen-photos/nho.jpg', str_repeat('x', 100));
        Storage::disk('public')->put('screen-photos/1/to.jpg', str_repeat('x', 5_000));

        // Ảnh NGOÀI thư mục màn hình không được tính: bìa owner là ảnh của
        // `/owners`, và trộn nó vào đây làm con số dung lượng nói sai.
        Storage::disk('public')->put('owners/covers/bia.webp', str_repeat('x', 9_999));

        $dia = $this->soat()['dia'];

        $this->assertTrue($dia['ton_tai']);
        $this->assertSame(2, $dia['so_tep']);
        $this->assertSame(5_100, $dia['bytes']);
        $this->assertSame('screen-photos/1/to.jpg', $dia['lon_nhat']['duong']);
    }

    public function test_thu_muc_chua_ton_tai_noi_ro_la_chua_ai_tai_anh_len(): void
    {
        $this->manHinh($this->owner('Chủ Một'));

        $this->assertFalse($this->soat()['dia']['ton_tai']);

        $this->artisan('oohx:soat-anh-kho')
            ->expectsOutputToContain('chưa một ảnh màn hình nào được tải lên')
            ->assertSuccessful();
    }

    public function test_bo_qua_dia_thi_khong_do_dia_va_khong_tim_tep_mat(): void
    {
        $this->manHinh($this->owner('Chủ Một'), ['photos' => ['screen-photos/mat.jpg']]);

        $kq = $this->soat(false);

        // `null`, không phải một bộ số 0: không đo khác với đo rồi thấy không
        // có gì — đúng nguyên tắc độ phủ của `SoatViTriService`.
        $this->assertNull($kq['dia']);
        $this->assertNull($kq['tep_mat']);

        // Phần phân loại vẫn chạy đủ.
        $this->assertSame(1, $kq['tong'][SoatAnhKhoService::CO_ANH]);
    }

    // ── Lệnh ────────────────────────────────────────────────────────────────

    public function test_lenh_chay_duoc_tren_kho_rong(): void
    {
        // Chia cho `tong_man_hinh` để ra phần trăm: kho rỗng là một phép chia
        // cho 0 nếu không ai chặn.
        $this->artisan('oohx:soat-anh-kho')->assertSuccessful();
    }

    public function test_lenh_canh_bao_ve_url_ngoai_va_tep_mat(): void
    {
        $o = $this->owner('Chủ Một');

        $this->manHinh($o, ['photos' => ['https://doitac.example/a.jpg']]);
        $this->manHinh($o, ['photos' => ['screen-photos/mat.jpg']]);

        $this->artisan('oohx:soat-anh-kho')
            ->expectsOutputToContain('có ảnh là URL đầy đủ')
            ->expectsOutputToContain('tệp không còn trên đĩa')
            ->assertSuccessful();
    }

    public function test_json_in_ra_json_doc_duoc(): void
    {
        $this->manHinh($this->owner('Chủ Một', 'owners/covers/bia.webp'));

        // `Artisan::call()`, KHÔNG `$this->artisan()`.
        //
        // `$this->artisan()` trả một `PendingCommand` giữ đầu ra trong buffer
        // riêng của nó, nên `Artisan::output()` trả chuỗi RỖNG sau lượt đó.
        // Lần đầu viết ca này theo cách kia và nó đỏ ở đúng chỗ dễ đọc sai —
        // nhưng ca "không in trường nhạy cảm" bên dưới thì **xanh một cách
        // rỗng**: `assertStringNotContainsString` trên chuỗi rỗng luôn đúng.
        // Một ca canh sự VẮNG MẶT phải tự chứng minh là nó có gì để đọc.
        Artisan::call('oohx:soat-anh-kho', ['--json' => true]);

        $ra = json_decode(Artisan::output(), true);

        $this->assertIsArray($ra);

        foreach (['tong_man_hinh', 'ngoai_pham_vi', 'tong', 'theo_nguon', 'theo_owner', 'so_anh', 'url_ngoai'] as $khoa) {
            $this->assertArrayHasKey($khoa, $ra, "Thiếu khoá `{$khoa}` trong đầu ra JSON.");
        }
    }

    /**
     * Lệnh này sẽ được dán vào chat, nên nó chỉ in thứ an toàn khi lộ.
     *
     * Quét **đệ quy** đầu ra JSON: một cột mới thêm vào service về sau sẽ đi
     * thẳng ra đây, và chỗ duy nhất chặn được là một ca đỏ.
     */
    public function test_khong_in_truong_nhay_cam(): void
    {
        $o = Owner::factory()->create([
            'status'            => 'active',
            'name'              => 'Chủ Một',
            'cover_url'         => 'owners/covers/bia.webp',
            'revenue_share_pct' => 63.17,
            'tax_code'          => '0123456789',
        ]);

        $this->manHinh($o)->update(['device_token' => 'bi-mat-cua-thiet-bi']);

        Artisan::call('oohx:soat-anh-kho', ['--json' => true]);

        $ra = Artisan::output();

        // Trước hết: ca này có gì để đọc.
        //
        // `assertStringNotContainsString` trên chuỗi rỗng luôn đúng, nên một ca
        // canh sự vắng mặt mà không khẳng định điều này là một ca xanh rỗng —
        // và nó xanh rỗng suốt trong khi không canh gì cả.
        $this->assertStringContainsString('Chủ Một', $ra, 'Không đọc được đầu ra của lệnh.');

        foreach ([
            'revenue_share_pct', 'billing_info', 'bank_name', 'bank_account_number',
            'tax_code', 'business_license_path', 'device_token', 'internal_notes',
            '0123456789', 'bi-mat-cua-thiet-bi',
        ] as $cam) {
            $this->assertStringNotContainsString($cam, $ra, "Lệnh in ra \"{$cam}\".");
        }
    }
}
