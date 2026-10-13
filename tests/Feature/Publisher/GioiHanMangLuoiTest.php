<?php

namespace Tests\Feature\Publisher;

use App\Filament\Publisher\Resources\NetworkResource;
use App\Models\Network;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Product;
use App\Models\Screen;
use App\Models\ScreenImport;
use App\Models\Site;
use App\Models\User;
use App\Services\GioiHanNetwork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Hàng rào theo mạng lưới: `owner_users.allowed_network_ids` phải **có hiệu lực**.
 *
 * ══ Thứ các ca này canh ══
 *
 * Trước 13/10/2026 cột đó được bốn biểu mẫu thu thập, bảng thành viên hiện ra
 * *"1 restricted"*, thư mời mang nó theo — và không một dòng mã nào đọc nó.
 * `OwnerUser::canAccessNetwork()`, phương thức duy nhất biết tới cột, không có
 * người gọi.
 *
 * Nghĩa là một chủ sở hữu giới hạn nhân viên vào một mạng lưới, đọc dòng chữ
 * xác nhận việc đó, và nhân viên vẫn thấy toàn bộ kho. Một ô cấu hình
 * least-privilege **không làm gì** còn tệ hơn không có ô nào, vì nó làm người
 * ta tin rằng đã khoá.
 *
 * ══ Hai tầng, nên hai nhóm ca ══
 *
 *  - Nhóm **đọc**: global scope ở `HasOwnerScope` — danh sách và việc tìm ra
 *    một hàng.
 *  - Nhóm **policy**: câu trả lời được/không khi hàng đã nằm trong tay, kể cả
 *    khi nó được tìm bằng `withoutGlobalScopes()` — thứ mã này làm ở hơn ba
 *    mươi chỗ.
 *  - Nhóm **ghi**: chốt `Site::saving`, vì không có nó thì người bị fence vẫn
 *    *đặt* được kho sang bên kia hàng rào, rồi mất luôn khả năng nhìn thấy
 *    hậu quả.
 *
 * Mỗi nhóm có ca cho chiều ngược: không giới hạn thì thấy hết, sửa trong hàng
 * rào thì được. Một hàng rào chỉ chặn mới là một hàng rào; một hàng rào chặn
 * cả việc hợp lệ là một lỗi.
 */
class GioiHanMangLuoiTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;

    private Network $trongRao;
    private Network $ngoaiRao;

    private Site $diaDiemTrong;
    private Site $diaDiemNgoai;
    private Site $diaDiemKhongMangLuoi;

    private Screen $manHinhTrong;
    private Screen $manHinhNgoai;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'publisher',   'guard_name' => 'web']);

        // Toàn bộ fixture dựng khi **chưa đăng nhập ai**: global scope thoát
        // sớm cho khách, nên không có hàng rào nào can thiệp lúc dàn cảnh —
        // kể cả chốt `Site::saving`.
        $this->owner = Owner::factory()->create(['status' => 'active']);

        $this->trongRao = Network::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Mạng A']);
        $this->ngoaiRao = Network::factory()->create(['owner_id' => $this->owner->id, 'name' => 'Mạng B']);

        $this->diaDiemTrong = $this->taoDiaDiem($this->trongRao->id);
        $this->diaDiemNgoai = $this->taoDiaDiem($this->ngoaiRao->id);
        $this->diaDiemKhongMangLuoi = $this->taoDiaDiem(null);

        $this->manHinhTrong = Screen::factory()->create([
            'owner_id' => $this->owner->id,
            'site_id'  => $this->diaDiemTrong->id,
        ]);
        $this->manHinhNgoai = Screen::factory()->create([
            'owner_id' => $this->owner->id,
            'site_id'  => $this->diaDiemNgoai->id,
        ]);
    }

    // ── Dàn cảnh ─────────────────────────────────────────────────────────────

    private function taoDiaDiem(?int $networkId): Site
    {
        return Site::factory()->create([
            'owner_id'   => $this->owner->id,
            'network_id' => $networkId,
        ]);
    }

    /**
     * @param list<int>|null|array{} $gioiHan `null` = không giới hạn
     */
    private function thanhVien(string $role, array|null $gioiHan): User
    {
        $user = User::factory()->create(['current_owner_id' => $this->owner->id]);
        $user->assignRole('publisher');

        OwnerUser::create([
            'owner_id'            => $this->owner->id,
            'user_id'             => $user->id,
            'role'                => $role,
            'allowed_network_ids' => $gioiHan,
        ]);

        return $user;
    }

    /** `operator` bị giới hạn vào đúng mạng lưới `trongRao`, đã đăng nhập */
    private function operatorBiGioiHan(): User
    {
        $user = $this->thanhVien('operator', [$this->trongRao->id]);
        $this->actingAs($user);

        return $user;
    }

    private function taoGoi(array $thuocTinh): Product
    {
        return Product::create(array_merge([
            'owner_id' => $this->owner->id,
            'name'      => 'Gói ' . uniqid(),
            'category'  => 'billboard',
        ], $thuocTinh));
    }

    // ── Nhóm ĐỌC ─────────────────────────────────────────────────────────────

    public function test_chi_thay_mang_luoi_duoc_giao(): void
    {
        $this->operatorBiGioiHan();

        $thay = Network::pluck('id')->all();

        $this->assertSame([$this->trongRao->id], $thay);
    }

    public function test_dia_diem_va_man_hinh_di_theo_cung_hang_rao(): void
    {
        $this->operatorBiGioiHan();

        $this->assertSame(
            [$this->diaDiemTrong->id],
            Site::pluck('id')->all(),
            'Địa điểm phải lọc theo sites.network_id',
        );

        $this->assertSame(
            [$this->manHinhTrong->id],
            Screen::pluck('id')->all(),
            'Màn hình phải lọc **qua địa điểm** — cùng một đường với Screen::network()',
        );
    }

    public function test_dia_diem_chua_gan_mang_luoi_roi_ra_ngoai_hang_rao(): void
    {
        $this->operatorBiGioiHan();

        // Hàng rào phải đóng khi không biết, không mở. Một địa điểm
        // `network_id = null` không nằm trong tập được giao.
        $this->assertFalse(
            Site::whereKey($this->diaDiemKhongMangLuoi->id)->exists(),
            'network_id null phải rơi ra ngoài, không được coi là "thuộc mọi mạng lưới"',
        );
    }

    public function test_goi_san_pham_di_theo_ca_hai_duong(): void
    {
        $theoMangLuoi = $this->taoGoi(['network_id' => $this->trongRao->id]);
        $theoDiaDiem  = $this->taoGoi(['site_id' => $this->diaDiemTrong->id]);
        $ngoaiRao     = $this->taoGoi(['network_id' => $this->ngoaiRao->id]);
        $khongGanGi   = $this->taoGoi([]);

        $this->operatorBiGioiHan();

        $thay = Product::pluck('id')->all();

        $this->assertContains($theoMangLuoi->id, $thay);
        $this->assertContains($theoDiaDiem->id, $thay, 'Gói chỉ có site_id phải suy ra mạng lưới từ địa điểm');
        $this->assertNotContains($ngoaiRao->id, $thay);
        $this->assertNotContains($khongGanGi->id, $thay, 'Gói không gắn mạng lưới lẫn địa điểm phải rơi ra ngoài');
        $this->assertCount(2, $thay);
    }

    public function test_khong_gioi_han_thi_thay_het(): void
    {
        $this->actingAs($this->thanhVien('operator', null));

        $this->assertCount(2, Network::all());
        $this->assertCount(3, Site::all());
        $this->assertCount(2, Screen::all());
    }

    public function test_bo_tich_het_o_nghia_la_tat_ca(): void
    {
        // Nhãn trên biểu mẫu nói "để trống = tất cả", và `CheckboxList` không
        // tích ô nào lưu xuống `[]`. Nếu `[]` bị đọc là "không mạng lưới nào"
        // thì một chủ sở hữu bỏ tích hết ô sẽ **làm mù** nhân viên của mình
        // trong khi giao diện hứa điều ngược lại.
        $this->actingAs($this->thanhVien('operator', []));

        $this->assertCount(2, Network::all());
        $this->assertCount(3, Site::all());
    }

    public function test_vai_tro_khong_fence_duoc_thi_du_lieu_sot_cung_khong_fence(): void
    {
        // `manager` sửa giá và thông tin tổ chức, `reporting_only` đọc báo cáo
        // của cả media owner — những thứ không chia theo mạng lưới được, nên
        // biểu mẫu không mời giới hạn chúng. Hàng pivot còn sót dữ liệu cũ từ
        // thời người đó là `operator` thì cũng không được biến thành hàng rào.
        foreach (['owner', 'manager', 'reporting_only', 'sales_manager'] as $role) {
            $this->actingAs($this->thanhVien($role, [$this->trongRao->id]));

            $this->assertCount(
                2,
                Network::all(),
                "Vai trò `{$role}` không nằm trong ROLES_GIOI_HAN_NETWORK nên không được bị giới hạn",
            );
        }
    }

    public function test_cot_tho_va_cau_tra_loi_that_co_the_lech_nhau(): void
    {
        // Cái bẫy cho mọi chỗ **hiển thị**: hàng pivot vẫn còn
        // `allowed_network_ids` từ thời người đó là `operator`, nhưng vai trò
        // `manager` không bị fence. Badge nào đọc thẳng cột sẽ ghi "1 mạng
        // lưới" cho một người không bị giới hạn gì — và đó là dòng chữ duy
        // nhất người gán vai trò có để kiểm.
        $manager = $this->thanhVien('manager', [$this->trongRao->id]);
        $pivot   = OwnerUser::where('user_id', $manager->id)->firstOrFail();

        $this->assertNotNull(
            $pivot->allowed_network_ids,
            'Cột thô vẫn còn dữ liệu — đó chính là cái bẫy, không phải một lỗi dàn cảnh',
        );

        $this->assertNull(
            $pivot->networkDuocPhep(),
            'Câu trả lời thật phải là "không bị giới hạn"; mọi chỗ hiển thị phải đọc hàm này',
        );
    }

    public function test_super_admin_va_khach_khong_bi_hang_rao(): void
    {
        $superAdmin = User::factory()->create(['current_owner_id' => $this->owner->id]);
        $superAdmin->assignRole('super_admin');

        OwnerUser::create([
            'owner_id'            => $this->owner->id,
            'user_id'             => $superAdmin->id,
            'role'                => 'operator',
            'allowed_network_ids' => [$this->trongRao->id],
        ]);

        $this->actingAs($superAdmin);
        $this->assertCount(2, Network::all(), 'super_admin đi qua trước mọi hàng rào tenant');

        auth()->forgetUser();
        $this->assertCount(2, Network::all(), 'Khách chưa đăng nhập: scope thoát sớm, trang công khai không đổi');
    }

    public function test_doi_gioi_han_co_hieu_luc_ngay_trong_cung_request(): void
    {
        $user  = $this->operatorBiGioiHan();
        $pivot = OwnerUser::where('user_id', $user->id)->first();

        $this->assertCount(1, Network::all());

        // `GioiHanNetwork` ghi nhớ câu trả lời suốt một request vì global scope
        // hỏi nó ở **mọi** truy vấn kho. `OwnerUser::booted()` phải xoá bộ nhớ
        // đó khi hàng pivot được lưu, nếu không một lần mở rộng quyền sẽ không
        // có hiệu lực cho tới request sau.
        $pivot->update(['allowed_network_ids' => [$this->trongRao->id, $this->ngoaiRao->id]]);

        $this->assertCount(2, Network::all());

        $pivot->update(['allowed_network_ids' => [$this->ngoaiRao->id]]);

        $this->assertSame([$this->ngoaiRao->id], Network::pluck('id')->all());
    }

    public function test_lan_nhap_kho_cua_nguoi_khac_khong_doc_duoc(): void
    {
        // `screen_imports` không nối tới `networks`, nhưng `preview_data` của
        // nó chứa nguyên các hàng Excel của bất kỳ mạng lưới nào — nên hàng
        // rào ở đây đổi hình: chỉ còn lần nhập do chính người đó tải lên.
        $nguoiKhac = $this->thanhVien('manager', null);
        $user      = $this->operatorBiGioiHan();

        $cuaNguoiKhac = ScreenImport::create([
            'owner_id'          => $this->owner->id,
            'uploaded_by'       => $nguoiKhac->id,
            'original_filename' => 'kho-mang-b.xlsx',
            'file_path'         => 'imports/kho-mang-b.xlsx',
        ]);

        $cuaChinhMinh = ScreenImport::create([
            'owner_id'          => $this->owner->id,
            'uploaded_by'       => $user->id,
            'original_filename' => 'kho-mang-a.xlsx',
            'file_path'         => 'imports/kho-mang-a.xlsx',
        ]);

        $this->assertSame([$cuaChinhMinh->id], ScreenImport::pluck('id')->all());
        $this->assertFalse(ScreenImport::whereKey($cuaNguoiKhac->id)->exists());
    }

    // ── Nhóm POLICY ──────────────────────────────────────────────────────────

    public function test_policy_chan_ca_khi_hang_duoc_tim_khong_qua_scope(): void
    {
        $this->operatorBiGioiHan();

        // Mã này tìm hàng bằng `withoutGlobalScopes()` ở hơn ba mươi chỗ. Ở
        // những đường đó global scope không che gì, nên policy phải tự gác.
        $mangNgoai   = Network::withoutGlobalScopes()->findOrFail($this->ngoaiRao->id);
        $diaDiemNgoai = Site::withoutGlobalScopes()->findOrFail($this->diaDiemNgoai->id);
        $manHinhNgoai = Screen::withoutGlobalScopes()->findOrFail($this->manHinhNgoai->id);

        $this->assertFalse(Gate::allows('view', $mangNgoai));
        $this->assertFalse(Gate::allows('update', $mangNgoai));
        $this->assertFalse(Gate::allows('delete', $mangNgoai));

        $this->assertFalse(Gate::allows('view', $diaDiemNgoai));
        $this->assertFalse(Gate::allows('update', $diaDiemNgoai));

        $this->assertFalse(Gate::allows('view', $manHinhNgoai));
        $this->assertFalse(Gate::allows('update', $manHinhNgoai));
    }

    public function test_policy_van_cho_qua_trong_hang_rao(): void
    {
        $this->operatorBiGioiHan();

        $this->assertTrue(Gate::allows('view', $this->trongRao->fresh()));
        $this->assertTrue(Gate::allows('view', $this->diaDiemTrong->fresh()));
        $this->assertTrue(Gate::allows('update', $this->diaDiemTrong->fresh()));
        $this->assertTrue(Gate::allows('view', $this->manHinhTrong->fresh()));
        $this->assertTrue(Gate::allows('update', $this->manHinhTrong->fresh()));
    }

    public function test_dia_diem_chua_gan_mang_luoi_policy_cung_chan(): void
    {
        $this->operatorBiGioiHan();

        $site = Site::withoutGlobalScopes()->findOrFail($this->diaDiemKhongMangLuoi->id);

        // Cùng một luật với global scope: không biết thì đóng. Hai tầng phải
        // trả lời giống nhau, nếu không sẽ có hàng liệt kê được mà không mở
        // được, hoặc ngược lại.
        $this->assertFalse(Gate::allows('view', $site));
        $this->assertFalse(Gate::allows('update', $site));
    }

    public function test_khong_bi_gioi_han_thi_policy_khong_chan(): void
    {
        $this->actingAs($this->thanhVien('operator', null));

        $this->assertTrue(Gate::allows('view', $this->ngoaiRao->fresh()));
        $this->assertTrue(Gate::allows('update', $this->diaDiemNgoai->fresh()));
        $this->assertTrue(
            Gate::allows('view', Site::withoutGlobalScopes()->findOrFail($this->diaDiemKhongMangLuoi->id)),
            'Địa điểm chưa gán mạng lưới chỉ bị chặn khi có hàng rào',
        );
    }

    // ── Nhóm GHI ─────────────────────────────────────────────────────────────

    public function test_khong_dat_duoc_dia_diem_moi_vao_mang_luoi_ngoai_hang_rao(): void
    {
        $this->operatorBiGioiHan();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Bạn chỉ được đặt địa điểm vào những mạng lưới đã được giao.');

        Site::create([
            'owner_id'    => $this->owner->id,
            'network_id'  => $this->ngoaiRao->id,
            'external_id' => 'SITE-LAU',
            'name'        => 'Địa điểm lậu',
            'status'      => 'active',
        ]);
    }

    public function test_khong_tao_duoc_dia_diem_vo_chu_khong_thuoc_mang_luoi_nao(): void
    {
        $this->operatorBiGioiHan();

        // Một hàng mới không đặt `network_id` thì `isDirty('network_id')` là
        // false. Nếu chốt chỉ dựa vào `isDirty` thì đây là đường đi vòng: tạo
        // địa điểm không thuộc mạng lưới nào, rồi chính người tạo không thấy
        // nó nữa (hàng rào loại `network_id` null), và kho của tổ chức có thêm
        // một địa điểm vô chủ.
        try {
            Site::create([
                'owner_id'    => $this->owner->id,
                'external_id' => 'SITE-VO-CHU',
                'name'        => 'Địa điểm vô chủ',
                'status'      => 'active',
            ]);
            $this->fail('Tạo địa điểm không thuộc mạng lưới nào phải bị chặn khi đang bị giới hạn');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertFalse(
            Site::withoutGlobalScopes()->where('external_id', 'SITE-VO-CHU')->exists(),
        );
    }

    public function test_nguoi_khong_bi_gioi_han_van_tao_duoc_dia_diem_chua_gan_mang_luoi(): void
    {
        // Chiều ngược: chốt chỉ được áp cho người có hàng rào. Địa điểm chưa
        // gán mạng lưới là trạng thái hợp lệ — cột đó nullable, và dữ liệu
        // thật đang có hàng như vậy.
        $this->actingAs($this->thanhVien('operator', null));

        $moi = Site::create([
            'owner_id'    => $this->owner->id,
            'external_id' => 'SITE-CHUA-GAN',
            'name'        => 'Chưa gán mạng lưới',
            'status'      => 'active',
        ]);

        $this->assertTrue($moi->exists);
        $this->assertNull($moi->network_id);
    }

    public function test_khong_chuyen_duoc_dia_diem_sang_mang_luoi_ngoai_hang_rao(): void
    {
        $this->operatorBiGioiHan();

        $site = Site::findOrFail($this->diaDiemTrong->id);

        try {
            $site->update(['network_id' => $this->ngoaiRao->id]);
            $this->fail('Chuyển địa điểm ra ngoài hàng rào phải bị chặn');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(
            $this->trongRao->id,
            (int) Site::withoutGlobalScopes()->findOrFail($this->diaDiemTrong->id)->network_id,
            'Hàng trong CSDL phải không đổi',
        );
    }

    public function test_tao_va_sua_dia_diem_trong_hang_rao_van_duoc(): void
    {
        $this->operatorBiGioiHan();

        $moi = Site::create([
            'owner_id'    => $this->owner->id,
            'network_id'  => $this->trongRao->id,
            'external_id' => 'SITE-HOP-LE',
            'name'        => 'Địa điểm hợp lệ',
            'status'      => 'active',
        ]);

        $this->assertTrue($moi->exists);

        // Chốt chỉ xét khi `network_id` **đổi**: sửa tên một địa điểm trong
        // hàng rào là việc hằng ngày của vai trò này.
        $site = Site::findOrFail($this->diaDiemTrong->id);
        $site->update(['name' => 'Tên mới']);

        $this->assertSame('Tên mới', $site->fresh()->name);
    }

    public function test_khong_tao_duoc_mang_luoi_moi_khi_bi_gioi_han(): void
    {
        $this->operatorBiGioiHan();

        // Không phải vì nó mở được hàng rào — mạng lưới mới thì rỗng. Mà vì
        // ngay sau khi lưu, chính người tạo không còn thấy nó.
        $this->assertFalse(Gate::allows('create', Network::class));
        $this->assertFalse(NetworkResource::canCreate());

        $this->actingAs($this->thanhVien('manager', null));

        $this->assertTrue(Gate::allows('create', Network::class));
        $this->assertTrue(NetworkResource::canCreate());
    }

    // ── Chốt tự tìm phạm vi ──────────────────────────────────────────────────

    public function test_moi_model_dung_owner_scope_deu_that_su_thu_hep(): void
    {
        // `locTheoNetwork()` khai `abstract` trong trait, nên PHP đã bắt được
        // trường hợp **quên** khai. Nó không bắt được một thân hàm **rỗng** —
        // và một thân rỗng là đúng hình dạng của một cửa sau im lặng: model
        // vẫn được scope theo tenant, chỉ lặng lẽ không theo mạng lưới.
        $models = $this->modelsDungOwnerScope();

        $this->assertNotEmpty($models, 'Không dò được model nào dùng HasOwnerScope — cách dò đã hỏng?');

        $khongGioiHan = $this->thanhVien('operator', null);
        $biGioiHan    = $this->thanhVien('operator', [$this->trongRao->id]);

        foreach ($models as $class) {
            $this->actingAs($khongGioiHan);
            $sqlRong = $class::query()->toSql();

            $this->actingAs($biGioiHan);
            $sqlRao = $class::query()->toSql();

            $this->assertNotSame(
                $sqlRong,
                $sqlRao,
                "{$class} dùng HasOwnerScope nhưng locTheoNetwork() không thu hẹp gì — "
                . 'một model được scope theo tenant mà không theo mạng lưới là một cửa sau im lặng',
            );
        }
    }

    /**
     * Dò bằng `class_uses_recursive()`, **không** bằng cách tìm chuỗi
     * "HasOwnerScope" trong tệp.
     *
     * Bản đầu dò bằng chuỗi, và nó đỏ ngay — vì `OwnerUser` *nhắc tên* trait
     * trong một docblock mà không dùng trait. Một phép dò nhận cả lời bàn về
     * mã sẽ gọi tên sai thủ phạm, và người đọc thông báo đỏ sẽ đi tìm ở chỗ
     * không có gì.
     *
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private function modelsDungOwnerScope(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) as $duongDan) {
            $class = 'App\\Models\\' . basename($duongDan, '.php');

            if (! class_exists($class)) {
                continue;
            }

            if (in_array(\App\Traits\HasOwnerScope::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        return $models;
    }

    public function test_danh_sach_vai_tro_bi_fence_chi_con_mot_ban(): void
    {
        // Danh sách này từng được viết lại bằng chữ ở mười chỗ. Từ khi nó là
        // một luật **có người thực thi**, một bản chép mới sẽ là hai luật.
        $banChep = [];

        foreach ($this->tepPhpTrongApp() as $duongDan) {
            if (str_ends_with($duongDan, 'Models' . DIRECTORY_SEPARATOR . 'OwnerUser.php')) {
                continue;
            }

            $ma = (string) file_get_contents($duongDan);

            // Chỉ bắt dạng mảng hai phần tử đúng cặp vai trò này; `PERMISSIONS`
            // có `'operator', 'read_only'` nằm giữa các vai trò khác và không
            // phải một bản chép của luật.
            if (preg_match("/\[\s*'operator'\s*,\s*'read_only'\s*\]/", $ma)) {
                $banChep[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $duongDan);
            }
        }

        $this->assertSame(
            [],
            $banChep,
            'Danh sách vai trò bị-fence-được phải đọc từ OwnerUser::ROLES_GIOI_HAN_NETWORK. '
            . 'Bản chép tìm thấy ở: ' . implode(', ', $banChep),
        );
    }

    /** @return list<string> */
    private function tepPhpTrongApp(): array
    {
        $tep = [];

        $duyet = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($duyet as $muc) {
            if ($muc->isFile() && $muc->getExtension() === 'php') {
                $tep[] = $muc->getPathname();
            }
        }

        return $tep;
    }

    public function test_bo_nho_khong_tra_loi_bang_gioi_han_cua_nguoi_truoc(): void
    {
        $service = app(GioiHanNetwork::class);

        $biGioiHan    = $this->thanhVien('operator', [$this->trongRao->id]);
        $khongGioiHan = $this->thanhVien('operator', null);

        $this->assertSame(
            [$this->trongRao->id],
            $service->cho($biGioiHan, $this->owner->id),
        );

        // Bộ nhớ khoá theo cặp người + tenant. Nếu nó khoá theo tenant thôi
        // thì người thứ hai nhận giới hạn của người thứ nhất — và đó là một
        // lỗi cho **quá nhiều** quyền, không phải quá ít.
        $this->assertNull($service->cho($khongGioiHan, $this->owner->id));
    }
}
