<?php

namespace Tests\Feature\Map;

use App\Filament\Widgets\NguonBanDoWidget;
use App\Services\Map\TrangThaiNguonTile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Widget "Nền bản đồ" trên `/admin` phải nói đúng ba trạng thái.
 *
 * ══ Vì sao đáng test ══
 *
 * Ngày 18/10/2026 nguồn ảnh nền bản đồ hỏng bốn lần mà không lần nào có một
 * dòng lỗi nào được ghi ở đâu — bản đồ luôn **trông như đang chạy**. Widget
 * này là chỗ duy nhất một người nhìn màn hình sẽ thấy sự thật, nên nó nói
 * sai là quay lại đúng tình trạng cũ, chỉ thêm một lớp sơn yên tâm.
 *
 * Trạng thái nguy hiểm nhất là **quá hạn**: kết quả "ổn" từ ba ngày trước
 * hiển thị màu xanh là một lời nói dối, và nó giấu đúng chuyện cần biết —
 * lịch đã dừng chạy.
 */
class TrangThaiNguonTileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function anh(int $byte): string
    {
        return str_repeat('x', $byte);
    }

    public function test_chua_soat_lan_nao_thi_doc_tra_null(): void
    {
        $this->assertNull(app(TrangThaiNguonTile::class)->doc());
    }

    public function test_lenh_ghi_lai_ket_qua_ca_khi_xanh(): void
    {
        // Ghi cả khi xanh, không chỉ khi đỏ: widget cần "lần soát gần nhất
        // lúc nào" để trả lời câu mà kết quả không trả lời — lịch còn chạy
        // không.
        Http::fake(['*' => Http::response($this->anh(27_862), 200, ['Content-Type' => 'image/jpeg'])]);

        $this->artisan('map:kiem-tile')->assertSuccessful();

        $tt = app(TrangThaiNguonTile::class)->doc();

        $this->assertNotNull($tt);
        $this->assertTrue($tt['ok']);
        $this->assertCount(2, $tt['nguon'], 'Phải ghi cả nguồn chính lẫn dự phòng.');
        $this->assertFalse($tt['qua_han']);
    }

    public function test_lenh_ghi_lai_ca_khi_do(): void
    {
        Http::fake(['*' => Http::response($this->anh(2_049), 200, ['Content-Type' => 'image/png'])]);

        $this->artisan('map:kiem-tile')->assertFailed();

        $tt = app(TrangThaiNguonTile::class)->doc();

        $this->assertNotNull($tt);
        $this->assertFalse($tt['ok']);
        $this->assertStringContainsString('watermark', $tt['nguon'][0]['mo_ta']);
    }

    public function test_soat_mot_url_le_khong_ghi_de_trang_thai_he_thong(): void
    {
        // `--url` là người đang thử tay một nguồn nào đó. Để nó ghi đè trạng
        // thái hệ thống thì một lần thử nghiệm biến thành một cảnh báo giả
        // trên trang chủ admin — hoặc tệ hơn, xóa một cảnh báo thật.
        Http::fake(['*' => Http::response($this->anh(27_862), 200, ['Content-Type' => 'image/jpeg'])]);

        $this->artisan('map:kiem-tile', ['--url' => 'https://vi-du.test/{z}/{x}/{y}.png'])
            ->assertSuccessful();

        $this->assertNull(app(TrangThaiNguonTile::class)->doc());
    }

    public function test_qua_han_khi_ket_qua_cu_hon_muoi_hai_gio(): void
    {
        $tt = app(TrangThaiNguonTile::class);

        $this->travel(-13)->hours();
        $tt->ghi([['ten' => 'nguồn chính', 'ok' => true, 'mo_ta' => 'image/png, 82.078 byte']]);
        $this->travelBack();

        $doc = $tt->doc();

        $this->assertTrue($doc['ok'], 'Kết quả lúc đó vẫn là xanh…');
        $this->assertTrue($doc['qua_han'], '…nhưng đã quá hạn, và đó mới là điều cần nói.');
    }

    public function test_widget_noi_lich_da_dung_khi_qua_han(): void
    {
        $tt = app(TrangThaiNguonTile::class);

        $this->travel(-13)->hours();
        $tt->ghi([['ten' => 'nguồn chính', 'ok' => true, 'mo_ta' => 'image/png, 82.078 byte']]);
        $this->travelBack();

        $chu = $this->chuTrongWidget();

        $this->assertStringContainsString('Lịch soát đã dừng', $chu);
        $this->assertStringNotContainsString(
            'Đang chạy',
            $chu,
            'Một kết quả xanh đã cũ không được hiện như đang chạy.',
        );
    }

    public function test_widget_noi_dang_chay_khi_moi_va_xanh(): void
    {
        app(TrangThaiNguonTile::class)->ghi([
            ['ten' => 'nguồn chính', 'ok' => true, 'mo_ta' => 'image/png, 82.078 byte'],
        ]);

        $this->assertStringContainsString('Đang chạy', $this->chuTrongWidget());
    }

    public function test_widget_noi_co_nguon_hong(): void
    {
        app(TrangThaiNguonTile::class)->ghi([
            ['ten' => 'nguồn chính', 'ok' => false, 'mo_ta' => 'HTTP 403'],
            ['ten' => 'dự phòng', 'ok' => true, 'mo_ta' => 'image/jpeg, 27.862 byte'],
        ]);

        $chu = $this->chuTrongWidget();

        $this->assertStringContainsString('Có nguồn hỏng', $chu);
        $this->assertStringContainsString('HTTP 403', $chu);
    }

    public function test_widget_chi_danh_cho_super_admin(): void
    {
        // Không đăng nhập thì không ai thấy. Trạng thái hạ tầng không phải
        // thứ để lộ ra ngoài vòng quản trị.
        $this->assertFalse(NguonBanDoWidget::canView());
    }

    /** Gom mọi nhãn, giá trị và mô tả của widget thành một chuỗi để soi. */
    private function chuTrongWidget(): string
    {
        $widget = new NguonBanDoWidget;

        $phuongThuc = new \ReflectionMethod($widget, 'getStats');
        $phuongThuc->setAccessible(true);

        $chu = '';

        foreach ($phuongThuc->invoke($widget) as $stat) {
            $chu .= ' ' . $stat->getLabel() . ' ' . $stat->getValue() . ' ' . $stat->getDescription();
        }

        return $chu;
    }
}
