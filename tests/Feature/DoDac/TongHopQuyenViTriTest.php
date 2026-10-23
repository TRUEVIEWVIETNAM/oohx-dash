<?php

namespace Tests\Feature\DoDac;

use App\Services\DoDacService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bản tổng hợp quyền vị trí — lát 3 của `docs/PLAN-DO-DAC-TOI-THIEU.md`.
 *
 * ══ Vì sao tệp này dài hơn hàm nó canh ══
 *
 * Mọi lỗi của một bản tổng hợp đều **im lặng**. Không có ngoại lệ nào được
 * ném, không có hàng nào thiếu, không có trang nào trắng — chỉ một con số
 * trông bình thường và sai, trên một màn hình mà người ta mở ra để quyết định
 * có bỏ một tính năng hay không.
 *
 * Nên tệp này canh từng phép cộng, không chỉ canh hàm chạy được.
 */
class TongHopQuyenViTriTest extends TestCase
{
    use RefreshDatabase;

    private DoDacService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DoDacService::class);
    }

    /**
     * Ghi thẳng vào bảng với một ngày chỉ định.
     *
     * Không đi qua `ghiNhan()`: nó luôn ghi hôm nay, nên không dựng được một
     * lịch sử để kiểm cửa sổ 30 ngày.
     */
    private function dat(string $ngay, string $giaTri, string $thietBi, int $soLuot, string $suKien = DoDacService::SU_KIEN_QUYEN_VI_TRI): void
    {
        DB::table('do_dac_ngay')->insert([
            'ngay' => $ngay,
            'su_kien' => $suKien,
            'gia_tri' => $giaTri,
            'thiet_bi' => $thietBi,
            'so_luot' => $soLuot,
        ]);
    }

    private function homNayVn(): string
    {
        return Carbon::now(DoDacService::MUI_GIO)->toDateString();
    }

    private function ngayTruoc(int $n): string
    {
        return Carbon::now(DoDacService::MUI_GIO)->subDays($n)->toDateString();
    }

    public function test_bang_rong_thi_moi_so_la_khong_va_khong_chia_cho_khong(): void
    {
        $th = $this->service->tongHopQuyenViTri();

        $this->assertSame(0, $th['hoi']);
        $this->assertSame(0, $th['da_chan_tu_truoc']);

        // Cả hai thiết bị phải có khoá, dù không hàng nào tồn tại: widget đọc
        // `$th['thiet_bi']['desktop']` không điều kiện, nên thiếu khoá là một
        // lỗi "undefined array key" trên trang chủ `/admin`.
        $this->assertSame(['mobile', 'desktop'], array_keys($th['thiet_bi']));

        foreach ($th['thiet_bi'] as $tb) {
            $this->assertSame(0, $tb['hoi']);
            $this->assertFalse($tb['du_lieu']);
            $this->assertSame(0.0, $tb['ti_le']['cho_phep']);
        }
    }

    public function test_gop_nhieu_ngay_cua_cung_mot_o_lai_voi_nhau(): void
    {
        $this->dat($this->homNayVn(), 'hoi', 'mobile', 40);
        $this->dat($this->ngayTruoc(1), 'hoi', 'mobile', 60);
        $this->dat($this->ngayTruoc(2), 'hoi', 'mobile', 100);

        $th = $this->service->tongHopQuyenViTri();

        $this->assertSame(200, $th['hoi']);
        $this->assertSame(200, $th['thiet_bi']['mobile']['hoi']);
    }

    public function test_ti_le_tinh_tren_mau_so_hoi(): void
    {
        $this->dat($this->homNayVn(), 'hoi', 'mobile', 200);
        $this->dat($this->homNayVn(), 'cho_phep', 'mobile', 120);
        $this->dat($this->homNayVn(), 'tu_choi', 'mobile', 50);
        $this->dat($this->homNayVn(), 'chua_tra_loi', 'mobile', 20);
        $this->dat($this->homNayVn(), 'loi', 'mobile', 10);

        $tb = $this->service->tongHopQuyenViTri()['thiet_bi']['mobile'];

        $this->assertSame(60.0, $tb['ti_le']['cho_phep']);
        $this->assertSame(25.0, $tb['ti_le']['tu_choi']);
        $this->assertSame(10.0, $tb['ti_le']['chua_tra_loi']);
        $this->assertSame(5.0, $tb['ti_le']['loi']);
        $this->assertSame(200, $tb['tong_ket_qua']);
    }

    /**
     * Cái sai nguy hiểm nhất của lát này, và nó không ném lỗi nào.
     *
     * `da_chan_tu_truoc` là nhánh `permissions.query` trả `denied`: client
     * không gọi `getCurrentPosition`, nên không có lượt `hoi` nào đi kèm.
     * Chia nó cho `hoi` ra một phân số vượt 100% được — ở ca này là 150%.
     */
    public function test_da_chan_tu_truoc_khong_nam_trong_mau_so(): void
    {
        $this->dat($this->homNayVn(), 'hoi', 'mobile', 100);
        $this->dat($this->homNayVn(), 'cho_phep', 'mobile', 100);
        $this->dat($this->homNayVn(), 'da_chan_tu_truoc', 'mobile', 150);

        $th = $this->service->tongHopQuyenViTri();

        $this->assertSame(150, $th['da_chan_tu_truoc'], 'phải ra ngoài, như một số đếm');
        $this->assertSame(100, $th['hoi'], 'mẫu số không được cộng thêm nhánh không hỏi');

        $tb = $th['thiet_bi']['mobile'];
        $this->assertArrayNotHasKey('da_chan_tu_truoc', $tb['ti_le']);
        $this->assertArrayNotHasKey('da_chan_tu_truoc', $tb['so_luot']);
        $this->assertSame(100, $tb['tong_ket_qua'], 'tổng kết quả không gồm nhánh không hỏi');

        foreach ($tb['ti_le'] as $ketQua => $tiLe) {
            $this->assertLessThanOrEqual(100.0, $tiLe, "`{$ketQua}` vượt 100%");
        }
    }

    public function test_nguong_du_lieu_ap_theo_tung_thiet_bi_khong_theo_tong(): void
    {
        $this->dat($this->homNayVn(), 'hoi', 'mobile', 500);
        $this->dat($this->homNayVn(), 'cho_phep', 'mobile', 300);
        $this->dat($this->homNayVn(), 'hoi', 'desktop', 7);
        $this->dat($this->homNayVn(), 'cho_phep', 'desktop', 3);

        $th = $this->service->tongHopQuyenViTri();

        $this->assertSame(507, $th['hoi'], 'tổng vượt ngưỡng');
        $this->assertTrue($th['thiet_bi']['mobile']['du_lieu']);
        $this->assertFalse(
            $th['thiet_bi']['desktop']['du_lieu'],
            '507 lượt mobile không làm 7 lượt desktop đáng tin hơn',
        );
    }

    public function test_dung_nguong_thi_du_du_lieu(): void
    {
        $this->dat($this->homNayVn(), 'hoi', 'mobile', DoDacService::NGUONG_DU_LIEU);

        $this->assertTrue($this->service->tongHopQuyenViTri()['thiet_bi']['mobile']['du_lieu']);
    }

    public function test_thieu_mot_luot_so_voi_nguong_thi_chua_du(): void
    {
        $this->dat($this->homNayVn(), 'hoi', 'mobile', DoDacService::NGUONG_DU_LIEU - 1);

        $this->assertFalse($this->service->tongHopQuyenViTri()['thiet_bi']['mobile']['du_lieu']);
    }

    public function test_ngay_cuoi_cua_cua_so_con_duoc_tinh(): void
    {
        $this->dat($this->ngayTruoc(DoDacService::SO_NGAY_TONG_HOP - 1), 'hoi', 'mobile', 11);

        $this->assertSame(11, $this->service->tongHopQuyenViTri()['hoi']);
    }

    public function test_ngay_ngoai_cua_so_bi_bo_ra(): void
    {
        $this->dat($this->ngayTruoc(DoDacService::SO_NGAY_TONG_HOP), 'hoi', 'mobile', 11);

        $this->assertSame(0, $this->service->tongHopQuyenViTri()['hoi'], 'ngày thứ 31 phải rơi ra ngoài');
    }

    /**
     * Cùng cái bẫy `homNay()` đã canh, nhưng ở đầu kia của cửa sổ.
     *
     * 17:30 UTC ngày 14/3 là 00:30 giờ VN ngày 15/3. Lấy mốc theo UTC thì cửa
     * sổ bắt đầu từ 14/2, tức **sớm hơn một ngày**, và nó kéo thêm một ngày
     * không thuộc 30 ngày gần nhất vào bản tổng hợp.
     */
    public function test_cua_so_tinh_theo_gio_viet_nam_khong_phai_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-14 17:30:00', 'UTC'));

        try {
            $th = $this->service->tongHopQuyenViTri();

            // 30 ngày tính cả 15/3 → mốc đầu là 14/2.
            $this->assertSame('2026-02-14', $th['tu_ngay']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_su_kien_khac_khong_lam_lech_ban_tong_hop(): void
    {
        $this->dat($this->homNayVn(), 'hoi', 'mobile', 100);

        // Lát 4 sẽ thêm những sự kiện như thế này vào cùng bảng. Chúng không
        // được chảy vào mẫu số của quyền vị trí.
        $this->dat($this->homNayVn(), 'hoi', 'mobile', 9_999, 'doi_kieu_xem');

        $this->assertSame(100, $this->service->tongHopQuyenViTri()['hoi']);
    }

    /**
     * Chốt canh lát 4: thêm một giá trị mà quên phân loại nó.
     *
     * `KET_QUA_TRONG_MAU_SO` được viết tay vì hằng số PHP không gọi hàm được.
     * Thêm một giá trị vào `CHO_PHEP` mà quên thêm vào một trong hai danh sách
     * phân loại thì giá trị ấy **biến mất khỏi widget im lặng**: không lỗi,
     * không hàng thiếu, chỉ là tổng các tỉ lệ hụt đi và không ai biết vì sao.
     */
    public function test_moi_gia_tri_deu_duoc_phan_loai_vao_dung_mot_nhom(): void
    {
        $trongMauSo = DoDacService::KET_QUA_TRONG_MAU_SO;
        $ngoaiMauSo = DoDacService::NGOAI_MAU_SO;
        $tatCa = DoDacService::CHO_PHEP[DoDacService::SU_KIEN_QUYEN_VI_TRI];

        $this->assertSame(
            [],
            array_intersect($trongMauSo, $ngoaiMauSo),
            'một giá trị không thể vừa là mẫu số vừa chia cho mẫu số',
        );

        $phanLoai = array_merge($trongMauSo, $ngoaiMauSo);
        sort($phanLoai);
        $that = $tatCa;
        sort($that);

        $this->assertSame(
            $that,
            $phanLoai,
            'thêm giá trị vào CHO_PHEP thì phải phân loại nó vào KET_QUA_TRONG_MAU_SO hoặc NGOAI_MAU_SO',
        );
    }

    public function test_tong_hop_khong_doc_cot_nao_dinh_danh_duoc(): void
    {
        $this->dat($this->homNayVn(), 'hoi', 'mobile', 100);

        $th = $this->service->tongHopQuyenViTri();

        // Bản tổng hợp không được mọc thêm một chiều nào khác `thiet_bi`.
        // Mai ai đó nhóm thêm theo một cột định danh được, ca này đỏ — và đó
        // đúng là lúc cần một quyết định chứ không phải một commit.
        $this->assertSame(
            ['tu_ngay', 'so_ngay', 'nguong', 'hoi', 'da_chan_tu_truoc', 'thiet_bi'],
            array_keys($th),
        );

        $this->assertSame(
            ['hoi', 'du_lieu', 'tong_ket_qua', 'so_luot', 'ti_le'],
            array_keys($th['thiet_bi']['mobile']),
        );
    }
}
