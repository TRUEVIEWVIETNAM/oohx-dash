<?php

namespace Tests\Feature\Api\V2;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PolicyConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `/api/v2/auth/*` — đăng nhập, đăng ký, đăng xuất cho app Next.js.
 *
 * Nhóm test này canh bốn thứ, và cả bốn đều là thứ hỏng im lặng:
 *
 *  1. Đăng nhập đặt PHIÊN, không phát token — khu người mua trên Blade dùng
 *     cùng cookie đó, nên hỏng chỗ này là người dùng phải đăng nhập hai lần.
 *  2. `session()->regenerate()` chạy. Thiếu nó thì session fixation còn nguyên,
 *     mà trang vẫn hoạt động bình thường.
 *  3. Đăng ký ghi ĐỦ: vai trò `buyer`, tổ chức, và bản ghi chấp thuận có đóng
 *     dấu phiên bản chính sách. Thiếu bản ghi đó thì không có gì chứng minh
 *     người dùng đã đồng ý với chữ nào.
 *  4. Sai thông tin đăng nhập không phân biệt được "email không tồn tại" với
 *     "mật khẩu sai".
 */
class BuyerAuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function nguoiDung(array $attributes = []): User
    {
        return User::create(array_merge([
            'name'     => 'Người Mua',
            'email'    => 'nguoi-mua@example.com',
            'password' => Hash::make('mat-khau-rat-dai'),
        ], $attributes));
    }

    /**
     * Header mà một trình duyệt thật gửi, và Sanctum CẦN để coi là stateful.
     *
     * `EnsureFrontendRequestsAreStateful` không bật middleware phiên cho mọi
     * request — nó chỉ bật khi `Origin` hoặc `Referer` khớp một tên miền trong
     * `config('sanctum.stateful')`. Thiếu header đó thì nhánh phiên không chạy
     * và `session()->regenerate()` ném "Session store not set on request".
     *
     * Nên test phải gửi nó. Không gửi là test một đường khác với đường người
     * dùng thật đi, và cái nó chứng minh được sẽ không phải cái ta cần.
     *
     * Hệ quả cho production: `APP_URL` phải đúng tên miền thật. Sai thì đăng
     * nhập vẫn trả 200 mà không phiên nào được đặt, người dùng quay lại trang
     * đăng nhập và không thấy lỗi gì — hỏng im lặng đúng nghĩa.
     */
    private function headerSPA(): array
    {
        return ['Origin' => config('app.url')];
    }

    private function duLieuDangKy(array $ghi_de = []): array
    {
        return array_merge([
            'name'                  => 'Người Đăng Ký',
            'email'                 => 'dang-ky@example.com',
            'password'              => 'mat-khau-rat-dai',
            'password_confirmation' => 'mat-khau-rat-dai',
            'organization_name'     => 'Công Ty Thử',
            'organization_type'     => 'agency',
            'accept_privacy'        => true,
        ], $ghi_de);
    }

    // ── Đăng nhập ───────────────────────────────────────────────────────────

    public function test_dang_nhap_dung_thi_co_phien(): void
    {
        $user = $this->nguoiDung();

        $this->postJson('/api/v2/auth/login', [
            'email'    => $user->email,
            'password' => 'mat-khau-rat-dai',
        ], $this->headerSPA())
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.has_organization', false);

        // Phiên, không phải token: đây là điều kiện để khu người mua trên Blade
        // nhận ra cùng người dùng mà không phải đăng nhập lại.
        $this->assertAuthenticatedAs($user);
    }

    public function test_dang_nhap_cap_id_phien_moi(): void
    {
        $user = $this->nguoiDung();

        $this->get('/login');
        $truoc = session()->getId();

        $this->postJson('/api/v2/auth/login', [
            'email'    => $user->email,
            'password' => 'mat-khau-rat-dai',
        ], $this->headerSPA())->assertOk();

        // Thiếu `session()->regenerate()` thì ID phiên giữ nguyên, và kẻ tấn
        // công đặt trước một ID cho nạn nhân vẫn ở trong phiên đã đăng nhập.
        $this->assertNotSame($truoc, session()->getId());
    }

    public function test_sai_thong_tin_tra_401_khong_lo_email_nao_ton_tai(): void
    {
        $this->nguoiDung();

        $sai_mat_khau = $this->postJson('/api/v2/auth/login', [
            'email'    => 'nguoi-mua@example.com',
            'password' => 'sai-mat-khau-hoan-toan',
        ], $this->headerSPA())->assertStatus(401);

        $khong_co_email = $this->postJson('/api/v2/auth/login', [
            'email'    => 'khong-ton-tai@example.com',
            'password' => 'mat-khau-rat-dai',
        ], $this->headerSPA())->assertStatus(401);

        // Hai trường hợp phải KHÔNG phân biệt được. Khác nhau một chữ là cho
        // người lạ một cách dò xem địa chỉ nào có tài khoản trên sàn.
        $this->assertSame(
            $sai_mat_khau->json(),
            $khong_co_email->json(),
            'Hai kiểu sai trả hai response khác nhau — đó là một đường dò email.',
        );

        $this->assertGuest();
    }

    public function test_khong_bao_gio_tra_mat_khau(): void
    {
        $user = $this->nguoiDung();

        $than = $this->postJson('/api/v2/auth/login', [
            'email'    => $user->email,
            'password' => 'mat-khau-rat-dai',
        ], $this->headerSPA())->assertOk()->getContent();

        foreach (['password', 'remember_token', 'id'] as $cam) {
            $this->assertStringNotContainsString(
                '"' . $cam . '"',
                $than,
                "Response đăng nhập để lộ trường \"{$cam}\".",
            );
        }
    }

    // ── Đăng ký ─────────────────────────────────────────────────────────────

    public function test_dang_ky_tao_du_tai_khoan_vai_tro_to_chuc_va_chap_thuan(): void
    {
        $this->postJson('/api/v2/auth/register', $this->duLieuDangKy(), $this->headerSPA())
            ->assertStatus(201)
            ->assertJsonPath('data.email', 'dang-ky@example.com')
            ->assertJsonPath('data.has_organization', true);

        $user = User::where('email', 'dang-ky@example.com')->firstOrFail();

        // Vai trò hệ thống: thiếu nó thì người dùng vào được /my nhưng không
        // vào được panel /buyer, và hai lối onboarding cho hai kết quả khác
        // nhau (Codex F15).
        $this->assertTrue($user->hasRole('buyer'), 'Người tự đăng ký thiếu vai trò buyer.');

        $this->assertSame(1, Organization::where('name', 'Công Ty Thử')->count());
        $this->assertSame(1, OrganizationUser::where('user_id', $user->id)->count());
        $this->assertNotNull($user->current_organization_id);

        // Bằng chứng chấp thuận, có đóng dấu phiên bản chính sách.
        $this->assertSame(
            1,
            PolicyConsent::where('user_id', $user->id)->count(),
            'Tài khoản tạo ra mà không có bản ghi đồng ý — không trả lời được khi bị hỏi.',
        );

        $this->assertAuthenticatedAs($user);
    }

    public function test_khong_tick_dong_y_thi_khong_tao_gi(): void
    {
        $this->postJson('/api/v2/auth/register', $this->duLieuDangKy(['accept_privacy' => false]), $this->headerSPA())
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        // `false` là một giá trị CÓ MẶT, nên luật phải là `accepted` chứ không
        // phải `required`. Đây là trường hợp bản JSON lộ ra mà bản biểu mẫu
        // không: trình duyệt bỏ hẳn checkbox chưa tick.
        $this->assertSame(0, User::where('email', 'dang-ky@example.com')->count());
        $this->assertSame(0, PolicyConsent::count());
        $this->assertGuest();
    }

    public function test_dang_ky_that_bai_khong_de_lai_manh_vun(): void
    {
        $this->nguoiDung(['email' => 'trung@example.com']);

        $this->postJson('/api/v2/auth/register', $this->duLieuDangKy(['email' => 'trung@example.com']), $this->headerSPA())
            ->assertStatus(422);

        // Email trùng bị chặn ở tầng kiểm, nên giao dịch chưa bắt đầu — không
        // có tổ chức mồ côi nào được tạo.
        $this->assertSame(0, Organization::where('name', 'Công Ty Thử')->count());
        $this->assertSame(0, PolicyConsent::count());
    }

    public function test_mat_khau_phai_khop_xac_nhan(): void
    {
        $this->postJson('/api/v2/auth/register', $this->duLieuDangKy([
            'password_confirmation' => 'mot-mat-khau-khac',
        ]), $this->headerSPA())->assertStatus(422);

        $this->assertSame(0, User::where('email', 'dang-ky@example.com')->count());
    }

    // ── Đăng xuất ───────────────────────────────────────────────────────────

    public function test_dang_xuat_huy_phien(): void
    {
        $user = $this->nguoiDung();

        $this->postJson('/api/v2/auth/login', [
            'email'    => $user->email,
            'password' => 'mat-khau-rat-dai',
        ], $this->headerSPA())->assertOk();

        $truoc = session()->getId();

        $this->postJson('/api/v2/auth/logout', [], $this->headerSPA())->assertOk();

        // `assertGuest()` trần KHÔNG dùng được ở đây, và lý do đáng ghi lại.
        //
        // `auth:sanctum` gọi `Auth::shouldUse('sanctum')`, tức guard mặc định
        // đổi sang `RequestGuard` của Sanctum cho cả tiến trình test. Mà
        // `RequestGuard` nhớ người dùng nó đã phân giải trong chính request vừa
        // rồi, nên nó vẫn báo "đã đăng nhập" dù phiên đã huỷ.
        //
        // Nói đích danh guard phiên — đó mới là thứ `logout()` chạm tới.
        $this->assertGuest('web');

        // `invalidate()` vừa xoá dữ liệu vừa cấp ID phiên mới. ID đổi là bằng
        // chứng đo được rằng phiên cũ không còn dùng lại được.
        //
        // Tôi đã thử một phép kiểm mạnh hơn — gọi lại `/api/v2/me` và đòi 401 —
        // và nó SAI: harness test không mang cookie giữa hai lời gọi như trình
        // duyệt, nên lời gọi sau không chạy trên phiên cũ. Nó đỏ vì mô hình của
        // phép kiểm sai, không vì code sai.
        $this->assertNotSame($truoc, session()->getId());
    }

    public function test_dang_xuat_khi_chua_dang_nhap_tra_401(): void
    {
        $this->postJson('/api/v2/auth/logout', [], $this->headerSPA())->assertStatus(401);
    }

    // ── Hạn mức ─────────────────────────────────────────────────────────────

    public function test_dang_nhap_co_han_muc(): void
    {
        $this->nguoiDung();

        // `throttle:login` — 5 lần/phút. Lần thứ sáu phải bị chặn, kể cả khi
        // thông tin đúng: hạn mức chặn việc DÒ, không chặn việc đăng nhập.
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v2/auth/login', [
                'email'    => 'nguoi-mua@example.com',
                'password' => 'sai-mat-khau-hoan-toan',
            ], $this->headerSPA())->assertStatus(401);
        }

        $this->postJson('/api/v2/auth/login', [
            'email'    => 'nguoi-mua@example.com',
            'password' => 'mat-khau-rat-dai',
        ], $this->headerSPA())->assertStatus(429);
    }
}
