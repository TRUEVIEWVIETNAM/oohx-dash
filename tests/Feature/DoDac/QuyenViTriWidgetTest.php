<?php

namespace Tests\Feature\DoDac;

use App\Filament\Widgets\QuyenViTriWidget;
use App\Models\User;
use App\Services\DoDacService;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ô quyền vị trí trên `/admin` — lát 3 của
 * `docs/PLAN-DO-DAC-TOI-THIEU.md`.
 *
 * ══ Vì sao canh chữ, không chỉ canh số ══
 *
 * Service đã được canh riêng ở `TongHopQuyenViTriTest`. Tệp này canh **điều ô
 * này nói với người đọc**, vì đó là chỗ một con số đúng vẫn gây ra quyết định
 * sai: in "43%" khi 43% nghĩa là *3 trên 7*, hay in một tỉ lệ cho
 * `da_chan_tu_truoc` mà mẫu số của nó không tồn tại.
 *
 * Gọi `getStats()` trực tiếp qua một closure gắn vào widget, không mount
 * Livewire: điều cần canh là nội dung các ô, và một lớp Livewire ở giữa chỉ
 * thêm chỗ để ca đỏ vì lý do khác.
 */
class QuyenViTriWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function dat(string $giaTri, string $thietBi, int $soLuot): void
    {
        DB::table('do_dac_ngay')->insert([
            'ngay' => Carbon::now(DoDacService::MUI_GIO)->toDateString(),
            'su_kien' => DoDacService::SU_KIEN_QUYEN_VI_TRI,
            'gia_tri' => $giaTri,
            'thiet_bi' => $thietBi,
            'so_luot' => $soLuot,
        ]);
    }

    /**
     * @return array<int, Stat>
     */
    private function cacO(): array
    {
        return (function () {
            return $this->getStats();
        })->call(new QuyenViTriWidget());
    }

    /**
     * Toàn bộ chữ của mọi ô, gộp thành một chuỗi.
     */
    private function chu(): string
    {
        $phan = [];

        foreach ($this->cacO() as $o) {
            $phan[] = (string) $o->getLabel();
            $phan[] = (string) $o->getValue();
            $phan[] = (string) $o->getDescription();
        }

        return implode(' | ', $phan);
    }

    private function oTen(string $nhan): Stat
    {
        foreach ($this->cacO() as $o) {
            if ((string) $o->getLabel() === $nhan) {
                return $o;
            }
        }

        $this->fail("không có ô nào mang nhãn `{$nhan}`");
    }

    // ── Quyền xem ────────────────────────────────────────────────────────────

    public function test_chi_super_admin_xem_duoc(): void
    {
        foreach (['super_admin', 'publisher'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->assertFalse(QuyenViTriWidget::canView(), 'khách chưa đăng nhập');

        $publisher = User::factory()->create();
        $publisher->assignRole('publisher');
        $this->actingAs($publisher);
        $this->assertFalse(QuyenViTriWidget::canView(), 'publisher không được xem');

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $this->actingAs($admin);
        $this->assertTrue(QuyenViTriWidget::canView());
    }

    // ── Chưa có gì ───────────────────────────────────────────────────────────

    public function test_bang_rong_thi_noi_chua_co_so_lieu_va_khong_in_ti_le_nao(): void
    {
        $o = $this->cacO();

        $this->assertCount(1, $o, 'không dựng ba ô rỗng khi chưa có lượt nào');
        $this->assertStringContainsString('Chưa có số liệu', (string) $o[0]->getValue());
        $this->assertStringNotContainsString('%', $this->chu());
    }

    // ── Dưới ngưỡng ──────────────────────────────────────────────────────────

    public function test_duoi_nguong_thi_noi_chua_du_so_lieu_thay_vi_mot_ti_le(): void
    {
        $this->dat('hoi', 'mobile', 7);
        $this->dat('cho_phep', 'mobile', 3);

        $oMobile = $this->oTen('Di động');

        $this->assertSame('Chưa đủ số liệu', (string) $oMobile->getValue());
        $this->assertStringNotContainsString(
            '%',
            (string) $oMobile->getValue() . (string) $oMobile->getDescription(),
            '3 trên 7 không được in thành 42,9%',
        );
        $this->assertStringContainsString('7/100', (string) $oMobile->getDescription());
        $this->assertSame('gray', $oMobile->getColor());
    }

    public function test_mot_thiet_bi_du_thi_thiet_bi_kia_van_noi_chua_du(): void
    {
        $this->dat('hoi', 'mobile', 500);
        $this->dat('cho_phep', 'mobile', 300);
        $this->dat('hoi', 'desktop', 7);

        $this->assertStringContainsString('60%', (string) $this->oTen('Di động')->getValue());
        $this->assertSame('Chưa đủ số liệu', (string) $this->oTen('Máy tính')->getValue());
    }

    // ── Đủ số liệu ───────────────────────────────────────────────────────────

    public function test_in_bon_so_plan_doi(): void
    {
        $this->dat('hoi', 'mobile', 200);
        $this->dat('cho_phep', 'mobile', 120);
        $this->dat('tu_choi', 'mobile', 50);
        $this->dat('chua_tra_loi', 'mobile', 20);
        $this->dat('loi', 'mobile', 10);

        $o = $this->oTen('Di động');
        $chu = (string) $o->getValue() . ' · ' . (string) $o->getDescription();

        $this->assertStringContainsString('Cho phép 60%', $chu);
        $this->assertStringContainsString('Chặn 25%', $chu);
        $this->assertStringContainsString('Chưa trả lời 10%', $chu);
        $this->assertStringContainsString('Lỗi 5%', $chu);
        $this->assertStringContainsString('200 lượt hỏi', $chu);
    }

    public function test_o_tong_in_tong_luot_hoi_va_cua_so(): void
    {
        $this->dat('hoi', 'mobile', 120);
        $this->dat('hoi', 'desktop', 80);

        $o = $this->oTen('Lượt hỏi quyền vị trí');

        $this->assertSame('200', (string) $o->getValue());
        $this->assertStringContainsString('30 ngày', (string) $o->getDescription());
    }

    /**
     * Cùng tính chất `TongHopQuyenViTriTest` canh ở service, nhưng ở chỗ
     * người đọc thật sự nhìn: ô này không được in một tỉ lệ cho
     * `da_chan_tu_truoc`, vì mẫu số của nó không tồn tại.
     */
    public function test_da_chan_tu_truoc_hien_nhu_so_dem_khong_phai_ti_le(): void
    {
        $this->dat('hoi', 'mobile', 100);
        $this->dat('cho_phep', 'mobile', 100);
        $this->dat('da_chan_tu_truoc', 'mobile', 150);

        $oTong = $this->oTen('Lượt hỏi quyền vị trí');
        $moTa = (string) $oTong->getDescription();

        $this->assertStringContainsString('150', $moTa, 'số đếm phải hiện ra');
        $this->assertStringContainsString('đã chặn sẵn', $moTa);
        $this->assertStringNotContainsString('150%', $this->chu(), 'và không bao giờ thành một tỉ lệ');
        $this->assertSame('100', (string) $oTong->getValue(), 'mẫu số không cộng thêm nhánh không hỏi');
    }

    public function test_khong_co_luot_chan_san_thi_khong_noi_gi_ve_no(): void
    {
        $this->dat('hoi', 'mobile', 100);

        $this->assertStringNotContainsString('đã chặn sẵn', $this->chu());
    }

    // ── Ngưỡng màu ───────────────────────────────────────────────────────────

    /**
     * Màu là lý do duy nhất mắt dừng lại ở ô này, nên nó phải đổi theo đúng
     * chiều: cho phép càng ít thì càng đáng lo.
     */
    public function test_mau_doi_theo_ti_le_cho_phep(): void
    {
        $ngaMau = [
            [70, 'success'],
            [50, 'success'],
            [49, 'warning'],
            [25, 'warning'],
            [24, 'danger'],
            [5, 'danger'],
        ];

        foreach ($ngaMau as [$choPhep, $mau]) {
            DB::table('do_dac_ngay')->truncate();
            $this->dat('hoi', 'mobile', 100);
            $this->dat('cho_phep', 'mobile', $choPhep);

            $this->assertSame(
                $mau,
                $this->oTen('Di động')->getColor(),
                "{$choPhep}% cho phép phải ra màu `{$mau}`",
            );
        }
    }

    // ── Lưới an toàn cho lỗi của lát 2 ───────────────────────────────────────

    /**
     * Plan lát 2 gọi tên đúng cái lỗi này: *"hẹn giờ `chua_tra_loi` phải huỷ
     * được, nếu không một callback về chậm sẽ ghi cả `chua_tra_loi` lẫn
     * `cho_phep` cho cùng một lượt"*.
     *
     * Khi nó xảy ra, từng tỉ lệ vẫn trông hợp lý; chỉ tổng của chúng là vô lý.
     * Không màn hình nào khác nhìn ra được, nên ô này phải nói.
     */
    public function test_tong_ket_qua_lech_khoi_mau_so_thi_noi_ra(): void
    {
        $this->dat('hoi', 'mobile', 100);
        $this->dat('cho_phep', 'mobile', 80);
        $this->dat('chua_tra_loi', 'mobile', 70); // ghi hai lần cho một lượt

        $this->assertStringContainsString('kiểm client', (string) $this->oTen('Di động')->getDescription());
    }

    public function test_khop_mau_so_thi_khong_canh_bao_gi(): void
    {
        $this->dat('hoi', 'mobile', 100);
        $this->dat('cho_phep', 'mobile', 60);
        $this->dat('tu_choi', 'mobile', 40);

        $this->assertStringNotContainsString('kiểm client', $this->chu());
    }

    /**
     * Một ít lượt rơi mất là chuyện thường của đường gửi không chờ phản hồi;
     * cảnh báo mỗi lần như thế thì ô này thành thứ không ai đọc nữa.
     */
    public function test_lech_nho_thi_khong_canh_bao(): void
    {
        $this->dat('hoi', 'mobile', 100);
        $this->dat('cho_phep', 'mobile', 60);
        $this->dat('tu_choi', 'mobile', 37);

        $this->assertStringNotContainsString('kiểm client', $this->chu());
    }
}
