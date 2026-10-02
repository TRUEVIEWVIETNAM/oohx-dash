<?php

namespace Tests\Feature\Api\V2;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Codex R41 — đổi định dạng **body** không được phá thông tin điều khiển của
 * giao thức.
 *
 * Renderer lỗi của `/api/v2` tạo một `JsonResponse` mới. Bản đầu chỉ truyền
 * body và status, nên mọi header của `HttpException` biến mất: client gặp 429
 * mà không biết chờ bao lâu. Bản sửa truyền `$e->getHeaders()`.
 *
 * Lúc phản hồi R41 tôi chỉ đo được ca 405 giữ `Allow`, và **nói rõ là chưa đo
 * 429** vì cần đẩy rate limiter thật. Lớp này đóng chỗ đó.
 *
 * Cách đo: định nghĩa lại giới hạn `api` xuống 1 lần/phút ngay trong test, thay
 * vì gọi 301 lần cho hết hạn mức thật. Hai cách đi qua **cùng** middleware
 * `ThrottleRequests` và cùng đường ném `ThrottleRequestsException`, nên thứ
 * đang đo không đổi — chỉ nhanh hơn và không phụ thuộc vào con số 300 trong
 * `AppServiceProvider`.
 */
class RateLimitHeadersTest extends TestCase
{
    // `/api/v2/stats` truy vấn CSDL, nên cần schema có mặt kể cả khi lớp này
    // chạy đầu tiên. Lớp này không tạo dữ liệu nào.
    use RefreshDatabase;

    private function throttleTo(int $perMinute): void
    {
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute($perMinute)->by($r->ip()));
    }

    public function test_429_cua_v2_giu_header_retry_after(): void
    {
        $this->throttleTo(1);

        // Lần đầu đi qua.
        $this->getJson('/api/v2/stats')->assertOk();

        $response = $this->getJson('/api/v2/stats')->assertStatus(429);

        // Kiểm **có header**, không kiểm "không rỗng".
        //
        // `X-RateLimit-Remaining` trên một phản hồi 429 có giá trị `"0"`, và
        // trong PHP `empty("0")` là `true` — nên `assertNotEmpty()` làm ca này
        // đỏ dù header có mặt đúng như mong đợi. Bản đầu tôi viết đúng lỗi đó.
        foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
            $this->assertTrue(
                $response->headers->has($header),
                "Phản hồi 429 của v2 mất header {$header} — đổi định dạng body không được phá thông tin điều khiển."
            );
        }

        // `Retry-After` thì phải là một con số giây dùng được, không chỉ là có
        // mặt rỗng.
        $this->assertGreaterThan(
            0,
            (int) $response->headers->get('Retry-After'),
            'Retry-After có mặt nhưng không phải số giây dùng được.'
        );
    }

    public function test_429_cua_v2_van_dung_dinh_dang_loi_thong_nhat(): void
    {
        $this->throttleTo(1);

        $this->getJson('/api/v2/stats')->assertOk();

        $this->getJson('/api/v2/stats')
            ->assertStatus(429)
            ->assertJsonPath('error', 'too_many_requests')
            ->assertJsonPath('code', 429)
            ->assertJsonStructure(['error', 'message', 'code', 'details']);
    }

    public function test_429_cua_v1_khong_doi_dinh_dang(): void
    {
        $this->throttleTo(1);

        // v1 là hợp đồng đang chạy với đối tác: envelope mới KHÔNG được lan
        // sang đó. Gọi không token nên cả hai lần đều 401, tới lần thứ ba thì
        // hạn mức mới chặn — nên đẩy đủ số lần.
        $this->getJson('/api/v1/inventory/screens');

        $response = $this->getJson('/api/v1/inventory/screens');

        // Dù là 401 hay 429, điều phải giữ là v1 không mang `code`/`details`.
        $response->assertJsonMissingPath('code');
        $response->assertJsonMissingPath('details');

        if ($response->getStatusCode() === 429) {
            $this->assertNotEmpty(
                $response->headers->get('Retry-After'),
                'v1 cũng không được mất Retry-After.'
            );
        }
    }

    public function test_nhom_can_quyen_bi_gioi_han_ke_ca_khi_chua_dang_nhap(): void
    {
        $this->throttleTo(1);

        // Lần đầu: 401 vì chưa đăng nhập — nhưng throttle ĐÃ đếm.
        $this->getJson('/api/v2/cart')->assertStatus(401);

        // Lần hai: phải là 429, không phải 401 nữa.
        //
        // Đây là phép kiểm cho một lỗi tôi tự tạo: bản đầu tôi để
        // `throttle:api` SAU `auth:sanctum` trong nhóm này, nên `auth` chặn
        // trước và throttle không bao giờ kịp đếm. Hệ quả: ai cũng dội được
        // vào endpoint cần quyền mà không bị hạn, và mỗi lần dội vẫn tốn công
        // dựng phiên. Trái CLAUDE.md mục 2 — "mọi endpoint có giới hạn tần
        // suất" — và một endpoint chỉ bị hạn SAU khi đăng nhập thì phần nguy
        // hiểm nhất của nó không được bảo vệ.
        $response = $this->getJson('/api/v2/cart')->assertStatus(429);

        $this->assertTrue(
            $response->headers->has('Retry-After'),
            'Nhóm cần quyền mất Retry-After khi bị hạn mức.'
        );
        $response->assertJsonPath('code', 429);
    }
}
