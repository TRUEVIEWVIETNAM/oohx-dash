<?php

namespace Tests\Feature;

use App\Models\PolicyConsent;
use App\Models\User;
use App\Services\PolicyConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Chấp thuận chính sách khi đăng ký / booking / thanh toán (review mục 4, 5, 7).
 *
 * Điều đáng canh không phải là cái checkbox có hiện ra hay không, mà là:
 *   - server có thật sự kiểm tra nó không (checkbox cũ ở trang booking chỉ có
 *     `id` và thuộc tính `required` của HTML — tắt JS là qua);
 *   - có ghi lại được người dùng đồng ý với BẢN NÀO của chính sách không.
 */
class PolicyConsentTest extends TestCase
{
    use RefreshDatabase;

    private function domain(): string
    {
        return config('domains.frontpage', 'oohx.net');
    }

    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name'                  => 'Nguyễn Văn A',
            'email'                 => 'a@example.com',
            'password'              => 'matkhau12345',
            'password_confirmation' => 'matkhau12345',
            'organization_name'     => 'Công ty ABC',
            'organization_type'     => 'agency',
            'accept_privacy'        => '1',
        ], $overrides);
    }

    // ── Đăng ký ──────────────────────────────────────────────────────────────

    /**
     * Gửi đăng ký qua `/api/v2/auth/register`.
     *
     * Đường `POST /register` của Blade đã gỡ ở giai đoạn 7 (07/10/2026).
     * Nghiệp vụ không đổi chỗ: cả hai đường vào vốn gọi chung
     * `BuyerRegistrationService` và `RegisterBuyerRequest`, nên bản ghi chấp
     * thuận vẫn do đúng một đoạn mã ghi ra.
     *
     * `Origin` là bắt buộc: `EnsureFrontendRequestsAreStateful` chỉ bật
     * middleware phiên khi header đó khớp `config('sanctum.stateful')`. Thiếu
     * nó thì `Auth::login()` trong controller ném "Session store not set".
     */
    private function dangKy(array $payload)
    {
        return $this->postJson(
            'http://' . $this->domain() . '/api/v2/auth/register',
            $payload,
            ['Origin' => config('app.url')],
        );
    }

    public function test_dang_ky_khong_tick_dong_y_thi_bi_tu_choi(): void
    {
        $payload = $this->registerPayload();
        unset($payload['accept_privacy']); // checkbox không tick = trình duyệt không gửi gì

        $this->dangKy($payload)
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed');

        $this->assertDatabaseMissing('users', ['email' => 'a@example.com']);
    }

    public function test_dang_ky_co_tick_thi_ghi_lai_bang_chung_chap_thuan(): void
    {
        $this->dangKy($this->registerPayload());

        $user = User::where('email', 'a@example.com')->first();
        $this->assertNotNull($user);

        $consent = PolicyConsent::where('user_id', $user->id)->first();
        $this->assertNotNull($consent, 'tài khoản tồn tại mà không có bản ghi đồng ý là thứ không trả lời được khi bị hỏi');
        $this->assertSame('privacy', $consent->policy_key);
        $this->assertSame(PolicyConsent::CONTEXT_REGISTER, $consent->context);
        $this->assertNotNull($consent->consented_at);
    }

    public function test_ban_ghi_chap_thuan_dong_dau_dung_phien_ban_chinh_sach(): void
    {
        config(['policies.pages.chinh-sach-bao-mat.version' => '2.7']);

        $this->dangKy($this->registerPayload());

        $this->assertSame('2.7', PolicyConsent::first()->policy_version);
    }

    public function test_dang_ky_that_bai_thi_khong_de_lai_ban_ghi_chap_thuan_mo_coi(): void
    {
        // Email trùng -> validate chặn trước, không có gì được ghi.
        User::factory()->create(['email' => 'a@example.com']);

        $this->dangKy($this->registerPayload());

        $this->assertSame(0, PolicyConsent::count());
    }

    // ── Phiên bản chính sách ─────────────────────────────────────────────────

    public function test_key_chinh_sach_sai_thi_no_ngay_thay_vi_ghi_ban_ghi_vo_nghia(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PolicyConsentService::class)->currentVersion('khong-ton-tai');
    }

    public function test_phien_ban_luon_lay_tu_config_khong_lay_tu_client(): void
    {
        config(['policies.pages.quy-che-hoat-dong.version' => '9.9']);

        $request = Request::create('/', 'POST', ['policy_version' => 'BỊA']);
        $user    = User::factory()->create();

        app(PolicyConsentService::class)->record(['terms'], 'booking', $request, $user->id);

        $consent = PolicyConsent::first();
        $this->assertSame('9.9', $consent->policy_version, 'client không được quyết định version, nếu không bản ghi hết là bằng chứng');
    }

    public function test_ghi_lai_ip_va_user_agent(): void
    {
        $user    = User::factory()->create();
        $request = Request::create('/', 'POST', server: [
            'REMOTE_ADDR'    => '203.0.113.9',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Test',
        ]);

        app(PolicyConsentService::class)->record(['terms'], 'booking', $request, $user->id);

        $consent = PolicyConsent::first();
        $this->assertSame('203.0.113.9', $consent->ip);
        $this->assertStringContainsString('Mozilla', $consent->user_agent);
    }

    public function test_ghi_nhieu_chinh_sach_cung_luc(): void
    {
        $user = User::factory()->create();

        app(PolicyConsentService::class)->record(
            ['terms', 'privacy'],
            PolicyConsent::CONTEXT_BOOKING,
            Request::create('/', 'POST'),
            $user->id,
        );

        $this->assertSame(2, PolicyConsent::count());
        $this->assertEqualsCanonicalizing(
            ['terms', 'privacy'],
            PolicyConsent::pluck('policy_key')->all()
        );
    }
}
