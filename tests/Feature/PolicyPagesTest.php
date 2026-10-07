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

    // ── Mục 1 và 2 đã chuyển sang webapp/test/seo.mjs ───────────────────
    //
    // Năm ca gỡ ở đây: chân trang có đủ thông tin pháp nhân, có đủ năm liên
    // kết chính sách, không còn liên kết chết; pop-up thử nghiệm hiện và tắt
    // được.
    //
    // Chúng đo khung trang Blade, và `/agency` — trang cuối cùng dùng khung
    // đó — rời Blade ngày 07/10/2026.
    //
    // ══ Đây là chỗ coverage THẬT SỰ mỏng đi, nói thẳng ══
    //
    // `seo.mjs` canh `class="ft-legal"` CÓ MẶT trên mọi trang Next, nhưng
    // KHÔNG canh nội dung bên trong nó: mã số doanh nghiệp, nơi cấp, ngày
    // cấp, địa chỉ, người đại diện, hotline, email. Nó cũng không canh pop-up
    // thử nghiệm.
    //
    // ĐÃ LẤP trong cùng lát này: `seo.mjs` giờ canh bảy mục pháp nhân (tên,
    // mã số, nơi cấp, ngày cấp, người đại diện, hotline, email) cùng thông
    // báo chế độ thử nghiệm, trên MỌI trang Next.
    //
    // Bảy mục đó chép tay từ `config/policies.php` chứ không đọc từ
    // `lib/company.ts`: đọc động thì phép kiểm so một hằng số với chính nó và
    // luôn xanh. Chép tay thì ngày nào hai nơi lệch, nó đỏ — mà hai nơi ĐANG
    // có thể lệch, vì unit systemd chưa truyền biến sang.
    //
    // Phần văn bản pháp lý (bốn ca ở trên) thì không mỏng đi: nó đo
    // `body_html` của API, tức đo ở nơi chữ thật sự phát ra.
}
