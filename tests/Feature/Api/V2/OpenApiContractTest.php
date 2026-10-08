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

    /**
     * Đặc tả không được khai trường nhạy cảm — **trừ đúng một khối**.
     *
     * Ngoại lệ duyệt 08/10/2026: `bank_*` và `tax_code` ra ngoài qua đúng một
     * đường (`GET campaigns/{campaign}/payment-recipients`) và đúng một schema
     * (`OwnerRemittance`). Lý do nghiệp vụ ở `OwnerRemittanceResource`; điều
     * kiện kèm theo ở CLAUDE.md mục 2.
     *
     * ══ Cách nới ngoại lệ ở đây mới là phần quan trọng ══
     *
     * **Không** bỏ năm trường đó khỏi danh sách cấm. Thay vào đó cắt khối
     * `OwnerRemittance` ra khỏi đống rạ rồi giữ nguyên lệnh cấm trên phần còn
     * lại. Nên:
     *
     *  - thêm `bank_account_number` vào `Payment`, `OwnerDebt` hay bất kỳ
     *    schema nào khác → test đỏ, y như trước;
     *  - xoá hay đổi tên khối `OwnerRemittance` mà vẫn để năm trường nằm rải
     *    rác → test đỏ, vì phép kiểm thứ hai đòi khối đó CÒN và còn đủ cả năm;
     *  - nhét `revenue_share_pct` vào chính khối ngoại lệ → test đỏ, vì ngoại
     *    lệ chỉ cấp cho năm trường, không cấp cho cả khối.
     *
     * Bỏ hẳn năm trường khỏi danh sách cấm là cách để lần rò tiếp theo đi qua
     * mà không ai biết — và đó là cách dễ nhất, nên nó phải được nói ra là
     * sai ngay tại đây.
     */
    public function test_dac_ta_khong_khai_truong_nhay_cam(): void
    {
        $raw = file_get_contents(base_path('docs/openapi/v2.yaml'));

        [$khoiNgoaiLe, $conLai] = $this->tachKhoiSchema($raw, 'OwnerRemittance');

        $this->assertNotSame(
            '',
            $khoiNgoaiLe,
            'Không tìm thấy schema `OwnerRemittance`. Nếu nó bị xoá hoặc đổi tên thì '
            . 'ngoại lệ không còn chỗ trú, và phép kiểm dưới đây mất hiệu lực — '
            . 'sửa test cùng lượt với đặc tả, đừng để nó xanh rỗng.'
        );

        // Cấm tuyệt đối, không có ngoại lệ nào, ở cả hai phần của tệp.
        $camTuyetDoi = [
            'revenue_share_pct', 'billing_info', 'business_license_path',
            'legal_representative', 'device_token', 'internal_notes',
        ];

        // Chỉ được phép nằm trong khối ngoại lệ, không chỗ nào khác.
        $chiTrongKhoiNgoaiLe = [
            'bank_name', 'bank_account_number', 'bank_account_name', 'bank_branch',
            'tax_code',
        ];

        foreach (array_merge($camTuyetDoi, $chiTrongKhoiNgoaiLe) as $field) {
            // Chú thích có thể nhắc tên trường để giải thích vì sao không trả;
            // chỉ cấm nó xuất hiện như một khóa của schema.
            $this->assertStringNotContainsString(
                $field . ':',
                $conLai,
                "Đặc tả khai trường nhạy cảm \"{$field}\" như một thuộc tính trả về, "
                . 'ngoài khối `OwnerRemittance`.'
            );
        }

        foreach ($camTuyetDoi as $field) {
            $this->assertStringNotContainsString(
                $field . ':',
                $khoiNgoaiLe,
                "Ngoại lệ cấp cho `bank_*` và `tax_code`, không cấp cho \"{$field}\"."
            );
        }

        foreach ($chiTrongKhoiNgoaiLe as $field) {
            $this->assertStringContainsString(
                $field . ':',
                $khoiNgoaiLe,
                "\"{$field}\" không còn trong `OwnerRemittance`. Nếu trường này không "
                . 'ra ngoài nữa thì bỏ nó khỏi danh sách ngoại lệ ở đây, để lệnh cấm '
                . 'toàn phần có hiệu lực lại.'
            );
        }
    }

    /**
     * Cắt một khối schema ra khỏi tệp theo thụt lề.
     *
     * `components.schemas.<Tên>` thụt 4 dấu cách, và khối kết thúc ở dòng tiếp
     * theo cũng thụt đúng 4 dấu cách.
     *
     * Cố ý làm việc trên **chữ** của tệp chứ không parse rồi dump lại: thứ phép
     * kiểm này canh là tệp mà con người đọc và sửa, không phải cây dữ liệu đã
     * chuẩn hoá. Một trường nhạy cảm nằm trong một chú thích YAML sai chỗ cũng
     * phải bị bắt.
     *
     * @return array{0: string, 1: string} [khối, phần còn lại của tệp]
     */
    private function tachKhoiSchema(string $raw, string $ten): array
    {
        $trong  = false;
        $khoi   = [];
        $conLai = [];

        foreach (preg_split('/\R/', $raw) as $dong) {
            // Thoát TRƯỚC khi nhận dòng mở, nên chính dòng mở không tự đóng
            // khối của mình.
            if ($trong && preg_match('/^    \S/', $dong) === 1) {
                $trong = false;
            }

            if ($dong === '    ' . $ten . ':') {
                $trong = true;
            }

            if ($trong) {
                $khoi[] = $dong;
            } else {
                $conLai[] = $dong;
            }
        }

        return [implode("\n", $khoi), implode("\n", $conLai)];
    }
}
