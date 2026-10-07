<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `Route::fallback` — đường dẫn không khớp route nào.
 *
 * ══ Vì sao một route bốn dòng cần một lớp test riêng ══
 *
 * Fallback là route DUY NHẤT khớp mọi thứ còn lại, nên nó là chỗ hai tình
 * huống khác hẳn nhau gặp nhau và dễ bị gộp làm một:
 *
 *   1. **Host lạ** (IP thô, `www.*`, tên miền trỏ nhầm). Mọi route đều nằm
 *      trong `Route::domain()`, nên không cái nào khớp và đường dẫn nào cũng
 *      rơi xuống fallback. Chuyển hướng về tên miền chính là ĐÚNG.
 *   2. **Host đúng, đường dẫn chết.** Chuyển hướng là SAI.
 *
 * Bản trước gộp cả hai vào một lệnh `redirect()`, và lỗi đó sống được vì nó
 * trông như đang hoạt động: trình duyệt về trang chủ, không ai thấy lỗi gì.
 * Thứ thấy là Google (đọc 302-về-trang-chủ như "soft 404", giữ URL chết trong
 * chỉ mục) và client đối tác gọi sai endpoint (nhận 200 kèm HTML trang chủ sau
 * khi tự đi theo chuyển hướng, rồi đọc đó là thành công).
 *
 * Cả hai nhóm đều không kêu. Nên phép kiểm phải canh **mã trạng thái**, không
 * canh "trang có hiện ra không".
 *
 * ══ Lớp này cố tình KHÔNG dùng `assertNotFound()` ở ca quan trọng nhất ══
 *
 * Ca hồi quy canh `assertStatus(404)` **và** khẳng định không phải 3xx. Nếu
 * ai đó khôi phục lệnh chuyển hướng cũ, `assertNotFound()` một mình đã đủ đỏ —
 * nhưng thông điệp lỗi sẽ chỉ nói "mong 404, nhận 302", không nói vì sao điều
 * đó quan trọng. Thông điệp ở đây nói ra.
 */
class FallbackRouteTest extends TestCase
{
    use RefreshDatabase;

    private function fp(): string
    {
        return config('domains.frontpage', 'oohx.net');
    }

    private function dash(): string
    {
        return config('domains.dash', 'dash.oohx.net');
    }

    // ── Host đúng, đường dẫn chết → 404 thật ────────────────────────────────

    #[DataProvider('duongChet')]
    public function test_duong_dan_chet_tren_ten_mien_chinh_tra_404_khong_chuyen_huong(string $duong): void
    {
        $phanHoi = $this->get('http://' . $this->fp() . $duong);

        $phanHoi->assertStatus(404);

        $this->assertFalse(
            $phanHoi->isRedirect(),
            "`{$duong}` trả chuyển hướng thay vì 404. Google đọc đó là soft 404 "
                . 'và giữ URL chết trong chỉ mục thay vì bỏ nó đi.',
        );
    }

    public static function duongChet(): array
    {
        return [
            // Ba đường đo được trên production 07/10/2026, cả ba ra 302 về `/`.
            'trang giới thiệu chưa từng có' => ['/gioi-thieu'],
            'trang liên hệ chưa từng có'    => ['/lien-he'],
            'blog chưa từng có'             => ['/blog/bai-1'],

            // Đường dẫn trông giống trang công khai thật nhưng không phải.
            // Sau giai đoạn 6–7 Laravel không còn route công khai nào, nên
            // nhóm này rơi xuống fallback thay vì vào một controller.
            'gần giống slug chính sách' => ['/quy-che'],
            'gần giống đường khám phá'  => ['/explores'],
        ];
    }

    public function test_duong_dan_chet_tren_ten_mien_dash_cung_tra_404(): void
    {
        // `dash.oohx.net` là host ĐÚNG — nó có nhóm route riêng. Nên đường chết
        // ở đó phải 404, không phải bị đẩy sang trang công khai: khu quản trị
        // không nên trả lời bằng cách chỉ người lạ sang chỗ khác.
        $this->get('http://' . $this->dash() . '/khong-co-duong-nay')
            ->assertStatus(404);
    }

    // ── Host lạ → chuyển hướng, giữ đường dẫn ───────────────────────────────

    public function test_host_la_duoc_dua_ve_ten_mien_chinh(): void
    {
        $this->get('http://192.0.2.10/explore')
            ->assertRedirect('https://' . $this->fp() . '/explore');
    }

    public function test_host_la_giu_nguyen_duong_dan_va_tham_so(): void
    {
        // Bản trước vứt đường dẫn đi: ai mở `<ip>/explore?q=led` cũng rơi về
        // trang chủ và phải tự tìm lại.
        $this->get('http://192.0.2.10/agency?q=led')
            ->assertRedirect('https://' . $this->fp() . '/agency?q=led');
    }

    public function test_host_la_khong_bi_dung_lam_ban_dap_sang_site_khac(): void
    {
        // `//evil.example` ở đầu đường dẫn là mẹo chuyển hướng giao-thức-tương-
        // đối: nếu đích được ghép cẩu thả thành `https://` . `//evil.example`
        // thì trình duyệt hiểu là host `evil.example`. `ltrim` gộp dấu `/` thừa
        // nên nó thành một đường dẫn trên chính site này.
        $dich = $this->get('http://192.0.2.10//evil.example/gi-do')
            ->headers->get('Location');

        $this->assertSame(
            'https://' . $this->fp() . '/evil.example/gi-do',
            $dich,
            'Fallback bị dùng làm bàn đạp chuyển hướng sang tên miền ngoài.',
        );
    }

    // ── API: không bao giờ trả HTML cho client JSON ─────────────────────────

    public function test_api_v1_sai_duong_tra_404_json_khong_phai_302_html(): void
    {
        $phanHoi = $this->getJson('http://' . $this->fp() . '/api/v1/khong-ton-tai');

        // Đây là ca nặng nhất của lỗi cũ. Client đối tác bật `followRedirects`
        // — mặc định ở phần lớn thư viện HTTP — nhận 200 kèm HTML trang chủ và
        // có thể đọc đó là thành công. Gõ sai tên endpoint mà được báo "ổn" là
        // kiểu lỗi im lặng tệ nhất.
        $this->assertFalse(
            $phanHoi->isRedirect(),
            'Endpoint v1 sai đường trả chuyển hướng sang HTML. Client đối tác đi '
                . 'theo chuyển hướng sẽ nhận 200 và đọc đó là thành công.',
        );

        $phanHoi->assertStatus(404)
            ->assertJsonPath('error', 'not_found');
    }

    public function test_api_v1_giu_hinh_dang_loi_hai_khoa_cua_v1(): void
    {
        // v1 dùng `{error, message}`. `{code, details}` là envelope của v2, và
        // thêm chúng vào v1 là đổi hợp đồng đang chạy với đối tác.
        $than = $this->getJson('http://' . $this->fp() . '/api/v1/khong-ton-tai')
            ->assertStatus(404)
            ->json();

        $this->assertSame(['error', 'message'], array_keys($than));
    }

    public function test_api_duoc_xet_truoc_host_nen_host_la_khong_bien_404_thanh_chuyen_huong(): void
    {
        // Một client đối tác có thể sai CẢ HAI cùng lúc: gọi vào IP thô và gõ
        // sai tên endpoint. Nếu fallback xét host trước thì họ nhận chuyển
        // hướng sang HTML và mất hẳn thông tin "endpoint này không tồn tại" —
        // đúng kiểu lỗi mà bản cũ gây ra, chỉ hẹp hơn một chút.
        //
        // Đường API ĐÚNG không bao giờ tới fallback (`/api/*` không khoá theo
        // domain nên nó khớp route thật), nên thứ tới đây luôn là đường sai và
        // câu trả lời JSON luôn là câu hữu ích hơn.
        $phanHoi = $this->getJson('http://192.0.2.10/api/v1/khong-ton-tai');

        $this->assertFalse($phanHoi->isRedirect(), 'Host lạ biến 404 của API thành chuyển hướng.');
        $phanHoi->assertStatus(404)->assertJsonPath('error', 'not_found');
    }

    public function test_ten_mien_chinh_rong_thi_tra_404_khong_tao_vong_chuyen_huong(): void
    {
        // `config('domains.frontpage', 'oohx.net')` KHÔNG rơi về mặc định khi
        // giá trị là chuỗi rỗng — mặc định chỉ dùng khi thiếu khoá. Nên
        // `FRONTPAGE_DOMAIN=` trong .env làm mọi host thành "lạ" và đích
        // `https:///...` tạo một vòng chuyển hướng vô tận.
        //
        // Cấu hình sai thì sao cũng hỏng; nhưng hỏng thành 404 thì còn đọc
        // được log mà gỡ, còn hỏng thành vòng lặp thì trình duyệt chỉ nói
        // "ERR_TOO_MANY_REDIRECTS" và không chỉ về đâu cả.
        config(['domains.frontpage' => '']);

        $this->get('http://192.0.2.10/bat-ky-dau')
            ->assertStatus(404);
    }

    public function test_api_v2_sai_duong_tra_dung_envelope_thong_nhat(): void
    {
        // Không viết lại định dạng trong fallback: `abort(404)` đi qua khối
        // `HttpExceptionInterface` trong `bootstrap/app.php`. Ca này canh rằng
        // đường đó còn thông — một `return response()->json(...)` đặt thẳng vào
        // fallback sẽ qua mặt renderer đó và lặng lẽ tạo ra định dạng thứ hai.
        $this->getJson('http://' . $this->fp() . '/api/v2/khong-ton-tai')
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found')
            ->assertJsonPath('code', 404)
            ->assertJsonPath('details', []);
    }

    // ── Fallback không được nuốt thứ đang sống ──────────────────────────────

    public function test_duong_dan_that_van_chay_binh_thuong(): void
    {
        // Một fallback viết sai `where()` hoặc đặt nhầm chỗ có thể chặn trước
        // route thật. `sitemap.xml` là thứ Laravel còn sinh sau giai đoạn 7,
        // và nó là mốc SEO đang được canh ở nơi khác.
        //
        // `robots.txt` thì KHÔNG kiểm ở đây, và lý do đáng ghi lại: nó là file
        // TĨNH ở `public/robots.txt`, OpenLiteSpeed phục vụ trực tiếp trước khi
        // Laravel thấy request. Bản đầu của lớp này khẳng định nó ra 200 và ca
        // đó đỏ với 404 — PHPUnit không phục vụ file tĩnh. Phép kiểm sai, không
        // phải code sai; fallback không với tới được nó.
        $this->get('http://' . $this->fp() . '/sitemap.xml')->assertOk();
    }

    public function test_fallback_khong_che_mat_route_v1_that(): void
    {
        // Fallback giờ có nhánh `$request->is('api/v1/*')`. Nếu nhánh đó chạy
        // trước route thật — hoặc ai đó đổi nó thành một `Route::any` — thì
        // endpoint đối tác sẽ trả 404 thay vì làm việc, và hợp đồng v1 vỡ.
        //
        // Không token thì `/api/v1/screens` phải ra 401 (chưa xác thực), KHÔNG
        // phải 404 (không tồn tại). Hai mã này phân biệt "route còn sống" với
        // "route bị fallback nuốt".
        $this->getJson('http://' . $this->fp() . '/api/v1/screens')
            ->assertStatus(401);
    }

    public function test_khach_chua_dang_nhap_vao_khu_nguoi_mua_van_duoc_dua_toi_dang_nhap(): void
    {
        // `/my` CÓ route, chỉ là cần đăng nhập. Nó phải ra chuyển hướng tới
        // `/login`, KHÔNG phải 404 — nếu nó 404 thì fallback đã nuốt mất route
        // thật, và cả khu người mua biến mất mà không ai thấy.
        $this->get('http://' . $this->fp() . '/my')
            ->assertRedirect('http://' . $this->fp() . '/login');
    }
}
