<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\Site;
use App\Models\User;
use App\Services\ScreenImport\RowValidator;
use App\Services\ScreenImport\ScreenWriter;
use App\Services\SiteImportService;
use App\Services\SoatViTriService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `screens.site_external_id` — khoá ghép điểm đặt (20/10/2026).
 *
 * ══ Cột này nói gì ══
 *
 * "Nguồn dữ liệu bảo màn hình này thuộc điểm đặt nào." Nó phải khớp
 * `external_id` của điểm đặt bản ghi đang gắn vào; lệch nhau nghĩa là lần
 * ghép đã đi chệch, và `SoatViTriService::ghepLechKhoa()` báo nó lên.
 *
 * Trước hôm nay cả ba lối nhập tra mã để tìm `site_id` rồi **bỏ mã đi**, nên
 * phép đo mạnh nhất của báo cáo soát có **0/104** bản ghi đối chiếu được.
 *
 * ══ Hai mặt phải đúng cùng lúc ══
 *
 * 1. **Lối nhập lưu mã từ nguồn**, không từ điểm đặt vừa tra ra. Lưu từ điểm
 *    đặt thì hai bên luôn bằng nhau, và một bất biến không bao giờ vỡ được
 *    thì không canh gì.
 * 2. **Chuyển màn hình bằng tay không được sinh báo oan.** Người sửa
 *    `site_id` qua biểu mẫu Filament không nói gì về mã nguồn, nên hàng rào
 *    trong `Screen::booted()` phải kéo cột theo — nếu không, mỗi lần chuyển
 *    hợp lệ là một dòng "ghép lệch" sai, và một báo cáo báo oan sẽ bị tắt.
 */
class KhoaGhepDiemDatTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->owner = Owner::factory()->create(['status' => 'active']);
    }

    private function diaDiem(string $maNgoai, string $ten): Site
    {
        return Site::factory()->create([
            'owner_id'    => $this->owner->id,
            'external_id' => $maNgoai,
            'name'        => $ten,
            'status'      => 'active',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function loiGhepLech(): array
    {
        return array_values(array_filter(
            app(SoatViTriService::class)->soat()['loi'],
            fn (array $l) => $l['loai'] === 'ghep_lech_khoa',
        ));
    }

    // ── Lối nhập lưu mã nguồn ────────────────────────────────────────────────

    public function test_nhap_excel_luu_ma_nguon(): void
    {
        $tep = $this->tepExcel(
            sites: [['SITE-A', 'Địa điểm A', 'Số 1 Lê Lợi', 'Hà Nội', 21.02, 105.84]],
            screens: [['SCR-1', 'Màn hình một', 'SITE-A']],
        );

        $kq = app(SiteImportService::class)->import($tep, $this->owner->id);

        $this->assertSame(1, $kq['screens_created']);
        $this->assertSame([], $kq['errors']);

        $man = Screen::withoutGlobalScopes()->where('external_id', 'SCR-1')->firstOrFail();

        $this->assertSame(
            'SITE-A',
            $man->site_external_id,
            'Lượt nhập tra mã rồi bỏ nó đi — không còn gì để đối chiếu khi phép '
                . 'tra đi chệch.',
        );
    }

    /**
     * `ScreenWriter` gắn được màn hình vào một địa điểm **đã tồn tại**.
     *
     * Hồi quy cho một lỗi có sẵn ca này tìm ra: `RowValidator::resolveSiteId()`
     * khai kiểu trả về `?int`, nhưng `sites.id` là ULID (`char(26)`) và PHP 8
     * **ném TypeError** chứ không ép chuỗi đó sang số. Nghĩa là mỗi dòng nhập
     * có mã điểm đặt đã tồn tại đều đổ, và `createSite(): int` cũng vậy — cả
     * lối nhập màn hình ở `/admin` không ghi được hàng nào.
     *
     * Nó im lặng đến hôm nay vì không ca test nào gọi `ScreenWriter`.
     */
    public function test_screen_writer_gan_duoc_vao_dia_diem_san_co_va_luu_ma_nguon(): void
    {
        $san = $this->diaDiem('SITE-B', 'Địa điểm B');

        $this->ghiQuaScreenWriter('SCR-2', 'Màn hình hai', 'SITE-B');

        $man = Screen::withoutGlobalScopes()->where('external_id', 'SCR-2')->firstOrFail();

        $this->assertSame($san->id, $man->site_id, 'Màn hình phải gắn vào địa điểm đã có, không tạo địa điểm mới.');
        $this->assertSame('SITE-B', $man->site_external_id);
        $this->assertSame(1, Site::withoutGlobalScopes()->where('owner_id', $this->owner->id)->count());
    }

    public function test_screen_writer_tao_dia_diem_moi_khi_ma_chua_co(): void
    {
        $writer    = new ScreenWriter($this->owner->id, 'update');
        $validator = new RowValidator($this->owner->id);

        $writer->write([
            'screens'   => ['external_id' => 'SCR-7', 'name' => 'Màn hình bảy'],
            'site'      => ['external_id' => 'SITE-MOI', 'name' => 'Địa điểm mới', 'city' => 'Hà Nội'],
            'spec'      => [],
            'inventory' => [],
        ], $validator);

        $man = Screen::withoutGlobalScopes()->where('external_id', 'SCR-7')->firstOrFail();

        $this->assertSame('SITE-MOI', $man->site_external_id);
        $this->assertSame(
            'SITE-MOI',
            Site::withoutGlobalScopes()->whereKey($man->site_id)->value('external_id'),
            'Nguồn và đích phải là một khi địa điểm vừa được tạo từ chính dòng đó.',
        );
    }

    public function test_api_v1_luu_ma_nguon(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $quanTri = User::factory()->create(['current_owner_id' => $this->owner->id]);
        $quanTri->assignRole('super_admin');

        $this->diaDiem('SITE-C', 'Địa điểm C');

        $this->actingAs($quanTri)
            ->postJson('/api/v1/screens', [
                'owner_id'         => $this->owner->id,
                'site_external_id' => 'SITE-C',
                'external_id'      => 'SCR-3',
                'name'             => 'Màn hình ba',
            ])
            ->assertStatus(201);

        $this->assertSame(
            'SITE-C',
            Screen::withoutGlobalScopes()->where('external_id', 'SCR-3')->value('site_external_id'),
        );
    }

    /**
     * Thay đổi duy nhất chạm `/api/v1` — ghi lại tường minh.
     *
     * CLAUDE.md mục 1: không đổi hành vi `/api/v1`, đó là hợp đồng với đối
     * tác. `POST /api/v1/screens` trả thẳng model, nên `site_external_id`
     * trong phản hồi đi từ `null` sang **đúng chuỗi người gọi vừa gửi lên**.
     *
     * Trường đã có trong phản hồi từ trước (nó không nằm trong `$hidden`), và
     * giá trị mới là dữ liệu của chính người gọi, nên không ai đọc được điều
     * gì mới. Ca này đóng băng hình dạng đó để lần đổi sau là một quyết định
     * chứ không phải một tác dụng phụ.
     */
    public function test_phan_hoi_v1_tra_lai_dung_ma_nguoi_goi_gui(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $quanTri = User::factory()->create(['current_owner_id' => $this->owner->id]);
        $quanTri->assignRole('super_admin');

        $this->diaDiem('SITE-V1', 'Địa điểm V1');

        $this->actingAs($quanTri)
            ->postJson('/api/v1/screens', [
                'owner_id'         => $this->owner->id,
                'site_external_id' => 'SITE-V1',
                'external_id'      => 'SCR-V1',
                'name'             => 'Màn hình v1',
            ])
            ->assertStatus(201)
            ->assertJsonPath('site_external_id', 'SITE-V1')
            ->assertJsonPath('external_id', 'SCR-V1');
    }

    /**
     * Lưu từ **nguồn**, không từ điểm đặt vừa tra ra.
     *
     * Đây là ca phân biệt một bất biến có nội dung với một bất biến tự đúng:
     * nếu lối nhập lấy mã từ `$site->external_id` thì hai bên luôn bằng nhau
     * và phép soát không bao giờ nói được gì.
     */
    public function test_ma_lay_tu_nguon_chu_khong_tu_diem_dat_vua_tra(): void
    {
        $this->diaDiem('SITE-D', 'Địa điểm D');

        $this->ghiQuaScreenWriter('SCR-4', 'Màn hình bốn', 'SITE-D');

        // Giả lập đúng hình dạng một lần tra đi chệch: mã nguồn nói một chỗ,
        // bản ghi gắn vào chỗ khác.
        $khac = $this->diaDiem('SITE-E', 'Địa điểm E');

        Screen::withoutGlobalScopes()
            ->where('external_id', 'SCR-4')
            ->update(['site_id' => $khac->id]);

        $loi = $this->loiGhepLech();

        $this->assertCount(1, $loi, 'Mã nguồn và điểm đặt lệch nhau mà báo cáo im lặng.');
        $this->assertStringContainsString('SITE-D', $loi[0]['chi_tiet']);
        $this->assertStringContainsString('SITE-E', $loi[0]['chi_tiet']);
    }

    // ── Không sinh báo oan ───────────────────────────────────────────────────

    public function test_chuyen_bang_tay_khong_sinh_bao_oan(): void
    {
        $a = $this->diaDiem('SITE-F', 'Địa điểm F');
        $b = $this->diaDiem('SITE-G', 'Địa điểm G');

        $man = Screen::factory()->create([
            'owner_id' => $this->owner->id,
            'site_id'  => $a->id,
            'active'   => true,
        ]);

        $this->assertSame('SITE-F', $man->fresh()->site_external_id);

        // Đúng việc biểu mẫu Filament làm: đổi `site_id`, không nói gì về mã.
        $man->update(['site_id' => $b->id]);

        $this->assertSame('SITE-G', $man->fresh()->site_external_id);
        $this->assertSame([], $this->loiGhepLech(), 'Một lần chuyển hợp lệ hoá một dòng báo oan.');
    }

    public function test_doi_ma_dia_diem_thi_man_hinh_di_theo(): void
    {
        $site = $this->diaDiem('SITE-H', 'Địa điểm H');

        $man = Screen::factory()->create([
            'owner_id' => $this->owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        $site->update(['external_id' => 'SITE-H-MOI']);

        $this->assertSame('SITE-H-MOI', $man->fresh()->site_external_id);
        $this->assertSame(
            [],
            $this->loiGhepLech(),
            'Sửa mã địa điểm làm MỌI màn hình của nó hoá "ghép lệch" — báo oan '
                . 'hàng loạt vì một lần sửa hợp lệ.',
        );
    }

    /**
     * Hàng cũ **không** được điền ngược.
     *
     * Cột này nói "nguồn đã nói gì", mà với hàng nhập trước hôm nay thì không
     * ai còn biết nguồn nói gì. Điền từ điểm đặt hiện tại là tự dựng bằng
     * chứng rằng chỗ gắn hôm nay đúng — kể cả khi nó sai, và hàng
     * "Aeon Mall Tân Phú" là đúng loại hàng bị dựng bằng chứng cho.
     */
    public function test_sua_man_hinh_khong_dien_nguoc_ma_cho_hang_cu(): void
    {
        $site = $this->diaDiem('SITE-I', 'Địa điểm I');

        $man = Screen::factory()->create([
            'owner_id' => $this->owner->id,
            'site_id'  => $site->id,
            'active'   => true,
        ]);

        // Hàng cũ: cột rỗng, không qua lối nhập nào.
        Screen::withoutGlobalScopes()->whereKey($man->id)->update(['site_external_id' => null]);

        $man->refresh()->update(['name' => 'Đổi tên thôi']);

        $this->assertNull(
            $man->fresh()->site_external_id,
            'Một lần sửa tên không được biến thành một lời khẳng định về chỗ '
                . 'gắn của bản ghi.',
        );
    }

    // ── Lượt nhập chuyển điểm đặt thì phải nói ra ────────────────────────────

    public function test_nhap_lai_chuyen_diem_dat_thi_bao_cao_noi_ra(): void
    {
        $tep1 = $this->tepExcel(
            sites: [
                ['SITE-J', 'Địa điểm J', 'Số 1', 'Hà Nội', 21.02, 105.84],
                ['SITE-K', 'Địa điểm K', 'Số 2', 'Hà Nội', 21.03, 105.85],
            ],
            screens: [['SCR-5', 'Màn hình năm', 'SITE-J']],
        );

        $dichVu = app(SiteImportService::class);
        $kq1    = $dichVu->import($tep1, $this->owner->id);

        $this->assertSame([], $kq1['doi_diem_dat'], 'Lần nhập đầu không chuyển gì cả.');

        // Cùng màn hình, tệp nay nói nó thuộc điểm đặt khác.
        $tep2 = $this->tepExcel(
            sites: [
                ['SITE-J', 'Địa điểm J', 'Số 1', 'Hà Nội', 21.02, 105.84],
                ['SITE-K', 'Địa điểm K', 'Số 2', 'Hà Nội', 21.03, 105.85],
            ],
            screens: [['SCR-5', 'Màn hình năm', 'SITE-K']],
        );

        $kq2 = $dichVu->import($tep2, $this->owner->id);

        $this->assertCount(1, $kq2['doi_diem_dat']);
        $this->assertSame('SCR-5', $kq2['doi_diem_dat'][0]['screen']);
        $this->assertStringContainsString('SITE-J', $kq2['doi_diem_dat'][0]['tu']);
        $this->assertSame('SITE-K', $kq2['doi_diem_dat'][0]['sang']);

        // Và cột đi theo, nên không có "ghép lệch" nào.
        $this->assertSame('SITE-K', Screen::withoutGlobalScopes()->where('external_id', 'SCR-5')->value('site_external_id'));
        $this->assertSame([], $this->loiGhepLech());
    }

    // ── Độ phủ của phép soát ─────────────────────────────────────────────────

    public function test_do_phu_lon_len_sau_khi_nhap(): void
    {
        $tep = $this->tepExcel(
            sites: [['SITE-L', 'Địa điểm L', 'Số 3', 'Hà Nội', 21.02, 105.84]],
            screens: [['SCR-6', 'Màn hình sáu', 'SITE-L']],
        );

        app(SiteImportService::class)->import($tep, $this->owner->id);

        $kq = app(SoatViTriService::class)->soat();

        $this->assertSame(
            1,
            $kq['theo_loai']['ghep_lech_khoa']['do_phu'],
            'Nhập xong mà phép đo vẫn không đối chiếu được bản ghi nào.',
        );
        $this->assertSame(0, $kq['theo_loai']['ghep_lech_khoa']['so']);
    }

    // ── Công cụ ──────────────────────────────────────────────────────────────

    private function ghiQuaScreenWriter(string $maMan, string $tenMan, string $maSite): void
    {
        $writer = new ScreenWriter($this->owner->id, 'update');

        // `RowValidator` nạp danh sách mã điểm đặt sẵn có để tra.
        $validator = new RowValidator($this->owner->id);

        $writer->write([
            'screens'   => ['external_id' => $maMan, 'name' => $tenMan],
            'site'      => ['external_id' => $maSite],
            'spec'      => [],
            'inventory' => [],
        ], $validator);
    }

    /**
     * Dựng một tệp Excel đúng khuôn `SiteImportService` đọc.
     *
     * Dữ liệu bắt đầu ở **dòng 5** ở cả hai sheet; bốn dòng trên là tiêu đề
     * của bản mẫu. Dựng bằng PhpSpreadsheet chứ không dùng tệp cố định trong
     * repo: khuôn là thứ chốt này canh, nên nó phải đọc được ngay trong ca
     * test chứ không nằm trong một tệp nhị phân không ai mở ra xem.
     *
     * @param  array<int, array<int, mixed>>  $sites
     * @param  array<int, array<int, mixed>>  $screens
     * @return string  đường dẫn tệp tạm
     */
    private function tepExcel(array $sites, array $screens): string
    {
        $wb = new Spreadsheet();

        $s1 = $wb->getActiveSheet();
        $s1->setTitle('Sites');
        $s1->fromArray($sites, null, 'A5', true);

        $s2 = $wb->createSheet();
        $s2->setTitle('Screens');
        $s2->fromArray($screens, null, 'A5', true);

        $duong = tempnam(sys_get_temp_dir(), 'oohx-nhap-') . '.xlsx';

        (new Xlsx($wb))->save($duong);

        $wb->disconnectWorksheets();

        return $duong;
    }
}
