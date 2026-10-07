<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Nội dung pháp lý bắt buộc trên trang công khai (review mục 1, 2, 3).
 *
 * Đây là những thứ đơn vị review sẽ tự mở ra xem, nên chúng phải thật sự tải
 * được chứ không chỉ tồn tại trong repo.
 *
 * ══ Lớp này đổi chỗ đo sau giai đoạn 7, không đổi thứ được đo ══
 *
 * Bốn trang chính sách và trang chủ giờ do Next.js phục vụ; bản Blade đã gỡ
 * (07/10/2026). Nên lớp này không `get('/bang-phi')` được nữa — đường đó không
 * còn route trong Laravel.
 *
 * Nhưng **văn bản thì vẫn ở đây**: `resources/views/frontpage/policies/bodies/`
 * là nguồn, và `GET /api/v2/policies/{slug}` render đúng những partial đó. Nên
 * phép kiểm nội dung chuyển sang đo `body_html` của endpoint — cùng một chữ,
 * đo ở nơi nó thật sự phát ra.
 *
 * Phần khung (chân trang pháp nhân, pop-up thử nghiệm) đo ở `/agency` — trang
 * công khai **duy nhất** Laravel còn phục vụ, và nó dùng chung
 * `frontpage/layouts/app.blade.php` với các trang cũ.
 *
 * Phần hiển thị trên Next (ô cảnh báo bản nháp, chân trang bản Next) do
 * `webapp/test/seo.mjs` canh, và nó canh **cả hai chiều** — có khi còn nháp,
 * không có khi đã ban hành.
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

    /** Phần thân một văn bản, lấy từ đúng nơi bên tiêu thụ lấy. */
    private function than(string $slug): string
    {
        return $this->get('http://' . $this->domain() . '/api/v2/policies/' . $slug)
            ->assertOk()
            ->json('data.body_html');
    }

    public static function policySlugs(): array
    {
        return [
            'quy chế'    => ['quy-che-hoat-dong', 'Quy chế hoạt động'],
            'bảo mật'    => ['chinh-sach-bao-mat', 'Chính sách bảo mật'],
            'tranh chấp' => ['giai-quyet-tranh-chap', 'Cơ chế giải quyết tranh chấp'],
            'bảng phí'   => ['bang-phi', 'Bảng phí dịch vụ'],
        ];
    }

    // ── Mục 3: bốn văn bản vẫn phát ra được ─────────────────────────────────

    #[DataProvider('policySlugs')]
    public function test_van_ban_chinh_sach_van_phat_ra_duoc(string $slug, string $title): void
    {
        $this->get('http://' . $this->domain() . '/api/v2/policies/' . $slug)
            ->assertOk()
            ->assertJsonPath('data.title', config("policies.pages.{$slug}.title"))
            ->assertJsonPath('data.url', url('/' . $slug));

        $this->assertNotEmpty($this->than($slug), "Văn bản {$slug} trả thân rỗng.");
    }

    public function test_bang_phi_neu_ro_muc_phi_va_doi_tuong_thu(): void
    {
        $than = $this->than('bang-phi');

        foreach ([
            'Người mua sử dụng sàn miễn phí.',
            '0 đồng',
            '6.668.000 đồng/năm',
            'chưa bao gồm VAT',
            '30 media owner được duyệt hoạt động',
            'truy thu phí cho thời gian media owner đã sử dụng miễn phí',
        ] as $can) {
            $this->assertStringContainsString($can, $than, "Bảng phí thiếu: {$can}");
        }
    }

    public function test_bang_phi_da_ban_hanh(): void
    {
        // Sở yêu cầu "bảng phí cụ thể" — một trang ghi "bản nháp, chưa có hiệu
        // lực" thì không trả lời được yêu cầu đó.
        //
        // Trước đây đo bằng cách tìm chữ "Văn bản đang hoàn thiện" trên HTML.
        // Giờ đo `is_effective`, tức đo đúng cái dữ kiện mà bên hiển thị dựa
        // vào — nếu nó sai thì mọi bên tiêu thụ đều sai, không riêng một trang.
        $this->get('http://' . $this->domain() . '/api/v2/policies/bang-phi')
            ->assertOk()
            ->assertJsonPath('data.is_effective', true);
    }

    public function test_bang_phi_khong_noi_toi_chia_doanh_thu(): void
    {
        // Hồ sơ khai sàn không chia lợi nhuận giao dịch; tỷ lệ 70/30 còn sót
        // trong DB không được lọt ra văn bản công khai.
        $than = $this->than('bang-phi');

        $this->assertStringNotContainsString('70%', $than);
        $this->assertStringNotContainsStringIgnoringCase('revenue', $than);
    }

    public function test_chinh_sach_bao_mat_neu_viec_chia_se_lien_he_cho_media_owner(): void
    {
        $than = $this->than('chinh-sach-bao-mat');

        $this->assertStringContainsString(
            'Chia sẻ thông tin người mua cho media owner khi gửi booking',
            $than,
        );
        $this->assertStringContainsString(
            'Mỗi media owner chỉ xem được thông tin người mua trong các booking có màn hình của mình.',
            $than,
        );
    }

    // ── Mục 2: thông tin pháp nhân dưới chân trang ──────────────────────────
    //
    // Đo ở `/agency` — trang công khai duy nhất Laravel còn phục vụ. Chân trang
    // bản Next có phép kiểm riêng ở `webapp/test/seo.mjs` (`class="ft-legal"`
    // trên mọi trang).

    public function test_chan_trang_co_day_du_thong_tin_phap_nhan(): void
    {
        $response = $this->get_('/agency');

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
        $response = $this->get_('/agency');

        $response->assertSee('Quy chế hoạt động');
        $response->assertSee('Chính sách bảo mật');
        $response->assertSee('Cơ chế giải quyết tranh chấp, khiếu nại, phản ánh');
        $response->assertSee('Tiếp nhận phản ánh của TCXH');
        $response->assertSee('Danh sách phản ánh của TCXH');
    }

    public function test_chan_trang_khong_con_link_chet(): void
    {
        $html = $this->get_('/agency')->getContent();

        // Trước fix: <a href="#">Điều khoản</a> — có chữ mà bấm không ra gì.
        $this->assertStringNotContainsString('<a href="#">Điều khoản</a>', $html);
        $this->assertStringNotContainsString('<a href="#">Bảo mật</a>', $html);
    }

    // ── Mục 1: pop-up thử nghiệm ────────────────────────────────────────────

    public function test_pop_up_thu_nghiem_hien_tren_trang_blade_con_lai(): void
    {
        config(['policies.trial_mode' => true]);

        $this->get_('/agency')->assertSee(
            'Website đang hoạt động ở chế độ thử nghiệm, đang thực hiện đăng ký với Bộ Công Thương.'
        );
    }

    public function test_tat_trial_mode_thi_pop_up_bien_mat(): void
    {
        config(['policies.trial_mode' => false]);

        $this->get_('/agency')->assertDontSee('chế độ thử nghiệm');
    }
}
