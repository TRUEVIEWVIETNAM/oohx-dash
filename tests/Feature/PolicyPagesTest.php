<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nội dung pháp lý bắt buộc trên trang công khai (review mục 1, 2, 3).
 *
 * Đây là những thứ đơn vị review sẽ tự mở ra xem, nên chúng phải thật sự tải
 * được chứ không chỉ tồn tại trong repo. Trước đây footer có sẵn ba link
 * "Điều khoản / Bảo mật / Liên hệ" nhưng cả ba đều là href="#".
 */
class PolicyPagesTest extends TestCase
{
    use RefreshDatabase;

    private function domain(): string
    {
        return config('domains.frontpage', 'oohx.net');
    }

    private function get_(string $path)
    {
        return $this->get('http://' . $this->domain() . $path);
    }

    // ── Mục 3: các trang chính sách tải được ─────────────────────────────────

    /** @dataProvider policySlugs */
    public function test_trang_chinh_sach_tai_duoc(string $slug, string $title): void
    {
        $response = $this->get_('/' . $slug);

        $response->assertOk();
        $response->assertSee($title);
    }

    public static function policySlugs(): array
    {
        return [
            'quy chế'      => ['quy-che-hoat-dong', 'Quy chế hoạt động'],
            'bảo mật'      => ['chinh-sach-bao-mat', 'Chính sách bảo mật'],
            'tranh chấp'   => ['giai-quyet-tranh-chap', 'Cơ chế giải quyết tranh chấp'],
            'bảng phí'     => ['bang-phi', 'Bảng phí dịch vụ'],
        ];
    }

    // ── Review vòng 2, mục 16: bảng phí công khai ────────────────────────────

    public function test_bang_phi_neu_ro_muc_phi_va_doi_tuong_thu(): void
    {
        $this->get_('/bang-phi')
            ->assertOk()
            ->assertSee('Người mua sử dụng sàn miễn phí.')
            ->assertSee('0 đồng')
            ->assertSee('6.668.000 đồng/năm')
            ->assertSee('chưa bao gồm VAT')
            ->assertSee('30 media owner được duyệt hoạt động')
            ->assertSeeText('truy thu phí cho thời gian media owner đã sử dụng miễn phí');
    }

    public function test_bang_phi_da_ban_hanh_khong_hien_canh_bao_ban_nhap(): void
    {
        // Sở yêu cầu "bảng phí cụ thể" — một trang ghi "bản nháp, chưa có hiệu
        // lực" thì không trả lời được yêu cầu đó.
        $this->get_('/bang-phi')->assertDontSee('Văn bản đang hoàn thiện');
    }

    public function test_bang_phi_khong_noi_toi_chia_doanh_thu(): void
    {
        // Hồ sơ khai sàn không chia lợi nhuận giao dịch; tỷ lệ 70/30 còn sót trong
        // DB không được lọt ra trang công khai.
        $this->get_('/bang-phi')->assertDontSee('70%')->assertDontSee('Revenue');
    }

    public function test_chan_trang_co_link_bang_phi(): void
    {
        $this->get_('/')->assertSee('Bảng phí dịch vụ');
    }

    // ── Chia sẻ liên hệ người mua cho media owner ────────────────────────────

    public function test_chinh_sach_bao_mat_neu_viec_chia_se_lien_he_cho_media_owner(): void
    {
        $this->get_('/chinh-sach-bao-mat')
            ->assertSee('Chia sẻ thông tin người mua cho media owner khi gửi booking')
            ->assertSee('Mỗi media owner chỉ xem được thông tin người mua trong các booking có màn hình của mình.');
    }

    public function test_slug_chinh_sach_khong_ton_tai_thi_khong_render_trang_chinh_sach(): void
    {
        // App có Route::fallback() đẩy mọi đường dẫn lạ về trang chủ, nên đây là
        // 302 chứ không phải 404. Điều cần canh là route /{slug} không nhận bừa
        // một slug ngoài config rồi nổ ở PolicyController.
        $response = $this->get_('/chinh-sach-khong-co-that');

        $response->assertRedirect();
        $response->assertDontSee('pol-body');
    }

    public function test_route_chinh_sach_khong_nuot_cac_duong_dan_khac(): void
    {
        // Route /{slug} bị ràng buộc whereIn — /explore phải vẫn về listing.
        $this->get_('/explore')->assertOk();
        $this->get_('/owners')->assertOk();
    }

    public function test_trang_chinh_sach_noi_ro_la_ban_nhap_khi_chua_ban_hanh(): void
    {
        config(['policies.pages.quy-che-hoat-dong.effective_from' => null]);

        $this->get_('/quy-che-hoat-dong')
            ->assertSee('Văn bản đang hoàn thiện')
            ->assertSee('chưa có hiệu lực áp dụng');
    }

    public function test_khi_da_ban_hanh_thi_khong_con_canh_bao_ban_nhap(): void
    {
        config([
            'policies.pages.quy-che-hoat-dong.effective_from' => '01/08/2026',
            'policies.pages.quy-che-hoat-dong.version'        => '1.0',
        ]);

        $this->get_('/quy-che-hoat-dong')
            ->assertDontSee('Văn bản đang hoàn thiện')
            ->assertSee('Hiệu lực từ 01/08/2026');
    }

    // ── Mục 2: thông tin pháp nhân dưới chân trang ───────────────────────────

    public function test_chan_trang_co_day_du_thong_tin_phap_nhan(): void
    {
        $response = $this->get_('/');

        $response->assertOk();
        $response->assertSee('CÔNG TY TNHH TRUEVIEW');
        $response->assertSee('0109944503');
        $response->assertSee('Sở Tài Chính thành phố Hà Nội');
        $response->assertSee('24/3/2022');
        $response->assertSee('Số 110 đường Lạc Long Quân, Phường Tây Hồ, Thành phố Hà Nội, Việt Nam.', false);
        $response->assertSee('NGUYỄN ANH TUẤN');
        $response->assertSee('0943668996');
        $response->assertSee('tuan.nguyen@attvietnam.vn');
    }

    public function test_chan_trang_co_du_nam_link_chinh_sach(): void
    {
        $response = $this->get_('/');

        $response->assertSee('Quy chế hoạt động');
        $response->assertSee('Chính sách bảo mật');
        $response->assertSee('Cơ chế giải quyết tranh chấp, khiếu nại, phản ánh');
        $response->assertSee('Tiếp nhận phản ánh của TCXH');
        $response->assertSee('Danh sách phản ánh của TCXH');
    }

    public function test_chan_trang_khong_con_link_chet(): void
    {
        $html = $this->get_('/')->getContent();

        // Trước fix: <a href="#">Điều khoản</a> — có chữ mà bấm không ra gì.
        $this->assertStringNotContainsString('<a href="#">Điều khoản</a>', $html);
        $this->assertStringNotContainsString('<a href="#">Bảo mật</a>', $html);
    }

    // ── Mục 1: pop-up thử nghiệm ─────────────────────────────────────────────

    public function test_pop_up_thu_nghiem_hien_tren_trang_chu(): void
    {
        config(['policies.trial_mode' => true]);

        $this->get_('/')->assertSee(
            'Website đang hoạt động ở chế độ thử nghiệm, đang thực hiện đăng ký với Bộ Công Thương.'
        );
    }

    public function test_pop_up_thu_nghiem_hien_ca_o_trang_dang_ky(): void
    {
        // Trang register render với hideFooter=true; banner không nằm trong footer
        // nên vẫn phải hiện. Yêu cầu là mọi khách đều thấy.
        config(['policies.trial_mode' => true]);

        $this->get_('/register')->assertSee('chế độ thử nghiệm');
    }

    public function test_tat_trial_mode_thi_pop_up_bien_mat_toan_site(): void
    {
        config(['policies.trial_mode' => false]);

        $this->get_('/')->assertDontSee('chế độ thử nghiệm');
    }
}
