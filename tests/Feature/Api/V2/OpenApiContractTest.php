<?php

namespace Tests\Feature\Api\V2;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Đặc tả OpenAPI phải khớp bảng route thật — **kiểm hai chiều**.
 *
 * `CLAUDE.md` mục 2 nói "OpenAPI là nguồn sự thật cho kiểu dữ liệu; Next.js
 * sinh TypeScript từ đó, không chép tay". Một đặc tả không ai kiểm là một đặc
 * tả sẽ lệch — và khi nó lệch, bên tiêu thụ sinh ra kiểu dữ liệu sai mà không
 * có gì báo. Nên:
 *
 *  - Thêm endpoint v2 mà quên mô tả → test đỏ.
 *  - Mô tả một endpoint không tồn tại → test đỏ.
 *
 * Test này không kiểm được nội dung schema có đúng hình dạng phản hồi hay
 * không; phần đó do `CatalogApiTest` canh bằng `assertJsonPath`. Nói rõ giới
 * hạn để không ai coi nó là bằng chứng "đặc tả đúng hoàn toàn".
 */
class OpenApiContractTest extends TestCase
{
    private function spec(): array
    {
        // `symfony/yaml` hiện có mặt vì `laravel/pail` và `laravel/sail` (dev)
        // đòi nó, chứ composer.json chưa require thẳng. Nếu hai gói đó biến
        // mất thì test này chết vì thiếu lớp, nên nói rõ cách sửa ngay tại
        // chỗ hỏng thay vì để lại một "Class not found".
        $this->assertTrue(
            class_exists(Yaml::class),
            'Thiếu symfony/yaml. Chạy: composer require --dev symfony/yaml'
        );

        $path = base_path('docs/openapi/v2.yaml');

        $this->assertFileExists($path, 'Thiếu đặc tả OpenAPI cho v2.');

        return Yaml::parseFile($path);
    }

    /** @return array<int, string> ví dụ: "get /api/v2/screens/{slug}" */
    private function routesInApp(): array
    {
        $found = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/' . ltrim($route->uri(), '/');

            if (! str_starts_with($uri, '/api/v2/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $found[] = strtolower($method) . ' ' . $uri;
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /** @return array<int, string> */
    private function routesInSpec(): array
    {
        $found = [];

        // Theo OpenAPI, một path item có thể mang các khóa KHÔNG phải method:
        // `parameters` (tham số dùng chung cho mọi method), `summary`,
        // `description`, `servers`, `$ref`. Đếm chúng như method thì phép kiểm
        // hai chiều báo thiếu một "endpoint" không hề tồn tại.
        $nonMethodKeys = ['parameters', 'summary', 'description', 'servers', '$ref'];

        foreach ($this->spec()['paths'] ?? [] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, $nonMethodKeys, true)) {
                    continue;
                }

                $found[] = strtolower($method) . ' ' . $path;
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    public function test_moi_endpoint_v2_deu_co_trong_dac_ta(): void
    {
        $missing = array_diff($this->routesInApp(), $this->routesInSpec());

        $this->assertSame(
            [],
            array_values($missing),
            "Endpoint v2 chưa được mô tả trong docs/openapi/v2.yaml:\n  - "
            . implode("\n  - ", $missing)
            . "\nBên tiêu thụ sinh kiểu dữ liệu từ file đó, nên endpoint không có mô tả là endpoint họ không dùng được."
        );
    }

    public function test_dac_ta_khong_mo_ta_endpoint_khong_ton_tai(): void
    {
        $extra = array_diff($this->routesInSpec(), $this->routesInApp());

        $this->assertSame(
            [],
            array_values($extra),
            "Đặc tả mô tả endpoint không có trong bảng route:\n  - "
            . implode("\n  - ", $extra)
            . "\nMô tả một thứ không tồn tại còn tệ hơn không mô tả: bên tiêu thụ viết code gọi vào hư không."
        );
    }

    public function test_dac_ta_khai_dinh_dang_loi_thong_nhat(): void
    {
        $error = $this->spec()['components']['schemas']['Error'] ?? null;

        $this->assertNotNull($error, 'Đặc tả phải khai schema Error dùng chung.');
        $this->assertEqualsCanonicalizing(
            ['error', 'message', 'code', 'details'],
            $error['required'] ?? [],
            'Định dạng lỗi thống nhất là {error, message, code, details[]}.'
        );
    }

    public function test_dac_ta_khong_khai_truong_nhay_cam(): void
    {
        $raw = file_get_contents(base_path('docs/openapi/v2.yaml'));

        $sensitive = [
            'revenue_share_pct', 'billing_info',
            'bank_name', 'bank_account_number', 'bank_account_name', 'bank_branch',
            'tax_code', 'business_license_path', 'legal_representative',
            'device_token', 'internal_notes',
        ];

        foreach ($sensitive as $field) {
            // Chú thích có thể nhắc tên trường để giải thích vì sao không trả;
            // chỉ cấm nó xuất hiện như một khóa của schema.
            $this->assertStringNotContainsString(
                $field . ':',
                $raw,
                "Đặc tả khai trường nhạy cảm \"{$field}\" như một thuộc tính trả về."
            );
        }
    }
}
