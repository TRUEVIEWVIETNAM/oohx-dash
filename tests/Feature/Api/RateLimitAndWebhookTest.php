<?php

namespace Tests\Feature\Api;

use App\Jobs\SendWebhookJob;
use App\Models\ApiClient;
use App\Models\WebhookSubscription;
use App\Rules\SafePublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T4 — giới hạn tần suất và chặn webhook độc hại (audit F-10, Codex F18/R10).
 *
 * Trước sửa: không có giới hạn nào trên /api/*, nên dò client_secret và mật khẩu
 * thoải mái; webhook nhận URL bất kỳ kể cả trỏ vào mạng nội bộ; và khi đích trả
 * lỗi thì job gọi $this->fail() nên không bao giờ thử lại.
 */
class RateLimitAndWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('token');
        RateLimiter::clear('login');

        // Container test chạy trên network nội bộ, không có DNS. Thay bộ phân giải
        // để kiểm được cả hai nhánh (đích công cộng / đích nội bộ) mà không phụ
        // thuộc mạng thật.
        SafePublicUrl::fakeResolver(fn (string $host) => match ($host) {
            'hooks.example.com' => ['93.184.216.34'],
            'evil.example.com'  => ['10.1.2.3'],          // tên miền công cộng trỏ về mạng riêng
            'nxdomain.test'     => [],                    // không phân giải được
            'localhost'         => ['127.0.0.1'],         // đúng như DNS thật trả về
            default             => ['93.184.216.34'],
        });
    }

    protected function tearDown(): void
    {
        SafePublicUrl::fakeResolver(null);
        parent::tearDown();
    }

    private function apiClient(): ApiClient
    {
        return ApiClient::create([
            'client_id'     => 'partner-' . uniqid(),
            'client_secret' => bcrypt('secret'),
            'name'          => 'Đối tác thử nghiệm',
            'scopes'        => ['inventory'],
            'active'        => true,
        ]);
    }

    // ── Giới hạn tần suất ────────────────────────────────────────────────────

    public function test_cap_token_bi_gioi_han(): void
    {
        $payload = [
            'client_id'     => 'khong-ton-tai',
            'client_secret' => 'sai',
            'grant_type'    => 'client_credentials',
        ];

        // 10 lần đầu được xử lý (dù sai thông tin), lần thứ 11 bị chặn.
        for ($i = 0; $i < 10; $i++) {
            $status = $this->postJson('/api/v1/auth/token', $payload)->status();
            $this->assertNotSame(429, $status, "Lần thứ " . ($i + 1) . " không nên bị chặn.");
        }

        $this->postJson('/api/v1/auth/token', $payload)->assertStatus(429);
    }

    public function test_dang_nhap_sai_nhieu_lan_bi_chan(): void
    {
        // POST /login cua Blade da go o giai doan 7. Duong dang nhap gio la
        // /api/v2/auth/login, va no mang CUNG han muc throttle:login.
        //
        // Origin la bat buoc: nhom auth cua v2 chi bat middleware phien khi
        // header do khop config(sanctum.stateful).
        $url = 'http://' . config('domains.frontpage', 'oohx.net') . '/api/v2/auth/login';
        $header = ['Origin' => config('app.url')];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($url, ['email' => 'ai@do.vn', 'password' => 'sai'], $header);
        }

        $this->postJson($url, ['email' => 'ai@do.vn', 'password' => 'sai'], $header)->assertStatus(429);
    }

    // ── Chặn SSRF ────────────────────────────────────────────────────────────

    #[DataProvider('forbiddenUrls')]
    public function test_khong_dang_ky_duoc_webhook_tro_vao_mang_noi_bo(string $url): void
    {
        $token = $this->apiClient()->createToken('t', ['inventory'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/inventory/webhook/register', [
                'url'    => $url,
                'events' => ['screen.updated'],
                'secret' => str_repeat('x', 20),
            ])
            ->assertStatus(422);

        $this->assertSame(0, WebhookSubscription::count());
    }

    public static function forbiddenUrls(): array
    {
        return [
            'loopback'           => ['http://127.0.0.1/hook'],
            'localhost'          => ['http://localhost/hook'],
            'mạng riêng 10.x'    => ['http://10.0.0.5/hook'],
            'mạng riêng 192.168' => ['http://192.168.1.1/hook'],
            'metadata cloud'     => ['http://169.254.169.254/latest/meta-data/'],
            'IPv6 loopback'      => ['http://[::1]/hook'],
            'scheme lạ'          => ['ftp://example.com/hook'],
            'có thông tin đăng nhập' => ['http://user:pass@example.com/hook'],
        ];
    }

    public function test_luat_chan_ca_dia_chi_ipv4_anh_xa_trong_ipv6(): void
    {
        $this->assertTrue(SafePublicUrl::isForbiddenIp('::ffff:10.0.0.1'));
        $this->assertTrue(SafePublicUrl::isForbiddenIp('127.0.0.1'));
        $this->assertTrue(SafePublicUrl::isForbiddenIp('169.254.169.254'));
        $this->assertFalse(SafePublicUrl::isForbiddenIp('8.8.8.8'));
    }

    // ── Webhook phải thử lại thật ────────────────────────────────────────────

    private function subscription(): WebhookSubscription
    {
        return WebhookSubscription::create([
            'api_client_id' => $this->apiClient()->id,
            'webhook_id'    => 'WH-TEST01',
            'url'           => 'https://hooks.example.com/oohx',
            'events'        => ['screen.updated'],
            'secret'        => str_repeat('x', 20),
            'status'        => 'active',
        ]);
    }

    public function test_dich_tra_loi_thi_nem_ngoai_le_de_hang_doi_thu_lai(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('lỗi máy chủ', 500)]);

        $job = new SendWebhookJob($this->subscription(), 'screen.updated', ['screen_id' => 'X']);

        // Trước đây job gọi $this->fail() nên không ném gì và không bao giờ thử lại.
        $this->expectException(\RuntimeException::class);
        $job->handle();
    }

    public function test_chi_tat_subscription_khi_da_het_so_lan_thu(): void
    {
        $sub = $this->subscription();

        (new SendWebhookJob($sub, 'screen.updated', []))->failed(new \RuntimeException('hết lượt'));

        $this->assertSame('inactive', $sub->fresh()->status);
    }

    public function test_gui_thanh_cong_thi_giu_nguyen_subscription_va_co_event_id(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true], 200)]);

        $sub = $this->subscription();
        (new SendWebhookJob($sub, 'screen.updated', ['screen_id' => 'X']))->handle();

        $this->assertSame('active', $sub->fresh()->status);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ! empty($body['event_id'])
                && $body['event'] === 'screen.updated'
                && $request->hasHeader('X-TapOn-Signature');
        });
    }

    public function test_ten_mien_cong_khai_nhung_tro_ve_mang_rieng_van_bi_chan(): void
    {
        Http::fake();

        $sub = $this->subscription();
        $sub->update(['url' => 'https://evil.example.com/hook']);   // DNS trỏ về 10.1.2.3

        (new SendWebhookJob($sub, 'screen.updated', []))->handle();

        Http::assertNothingSent();
        $this->assertSame('inactive', $sub->fresh()->status);
    }

    public function test_dns_hong_tam_thoi_thi_thu_lai_chu_khong_tat_subscription(): void
    {
        Http::fake();

        $sub = $this->subscription();
        $sub->update(['url' => 'https://nxdomain.test/hook']);

        try {
            (new SendWebhookJob($sub, 'screen.updated', []))->handle();
            $this->fail('Phải ném ngoại lệ để hàng đợi thử lại.');
        } catch (\RuntimeException $e) {
            // đúng như mong đợi
        }

        Http::assertNothingSent();
        $this->assertSame(
            'active',
            $sub->fresh()->status,
            'Trục trặc DNS là sự cố hạ tầng, không được tắt webhook của đối tác.'
        );
    }

    public function test_dich_tro_vao_mang_noi_bo_thi_khong_gui_va_tat_subscription(): void
    {
        Http::fake();

        $sub = $this->subscription();
        $sub->update(['url' => 'http://127.0.0.1/hook']);

        (new SendWebhookJob($sub, 'screen.updated', []))->handle();

        Http::assertNothingSent();
        $this->assertSame('inactive', $sub->fresh()->status);
    }
}
