<?php

namespace Tests\Feature\Api\V2;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Tên trường mà trang Blade ĐỌC phải có trong schema của **đúng endpoint** nó gọi.
 *
 * ══ Chốt này thay `tests/js/truong-api.test.mjs` ══
 *
 * Bản JS (#58) đối chiếu với **cả tệp** `docs/openapi/v2.yaml` bằng cách tìm
 * chuỗi. Nó bắt được việc một trường biến mất khỏi đặc tả, nhưng không bắt
 * được việc trang đọc một trường **của endpoint khác**: `bank_name` có trong
 * đặc tả, nên một trang đọc nó từ `GET payments` — nơi không trả `bank_*` —
 * vẫn đi qua.
 *
 * Mà đó đúng là chỗ nguy: `bank_*` và `tax_code` chỉ ra ngoài qua **một**
 * đường (`payment-recipients`, ngoại lệ đã duyệt 08/10). Một trang đọc chúng
 * từ đường khác là hoặc trang sai, hoặc có người vừa nới một DTO thứ hai.
 *
 * Chốt này ở PHP vì `symfony/yaml` đã có ở đây và `OpenApiContractTest` đã
 * phân tích cùng tệp đặc tả. Viết lại bộ phân tích YAML bằng tay trong JS là
 * cách chắc chắn để có một bộ phân tích sai — tôi vừa gặp đúng chuyện đó với
 * bộ bỏ chú thích của bản JS.
 *
 * ══ Vì sao trục này cần canh ══
 *
 * Các ca jsdom dựng phản hồi API bằng **dữ liệu mẫu viết tay**. Nếu DTO đổi
 * tên một trường thì dữ liệu mẫu vẫn mang tên cũ, mọi ca vẫn xanh, và trang
 * thật đọc `undefined`. Trên trang tiền, `undefined` không nổ: nó rơi về nhánh
 * `|| p.status` và hiện một mã CSDL cho người mua.
 *
 * ══ Giới hạn, nói ra ══
 *
 *  - Chỉ canh tên **snake_case**. API đặt tên kiểu đó; biến cục bộ trong các
 *    khối script là camelCase hoặc tiếng Việt không dấu. Đổi DTO sang
 *    `statusLabel` thì chốt này không thấy.
 *  - Chỉ canh phản hồi **GET**. Các trang này chỉ đọc qua GET; đường ghi của
 *    chúng đi về route Blade.
 *  - Không canh chiều ngược: một trường đặc tả khai mà không ai đọc là trường
 *    chết, và chốt này không nói gì.
 *  - **Trang gọi nhiều đường thì phạm vi là HỢP của chúng.** Trang thanh toán
 *    gọi cả `payments` lẫn `payment-recipients`, nên `bank_name` đọc ở bất cứ
 *    đâu trên trang đó cũng qua. Tôi đo điều này bằng đột biến: đặt
 *    `p.bank_name` vào chỗ vẽ lịch sử thanh toán thì chốt **không** đỏ.
 *
 *    Chặt hơn thì phải lần từng biến tới lời gọi `fetch` sinh ra nó — tức một
 *    phép phân tích luồng dữ liệu, không phải một phép quét. Trang giỏ hàng
 *    chỉ gọi một đường, nên ở đó chốt chặt đúng như mô tả: đột biến đặt
 *    `bank_name` vào trang giỏ thì đỏ ngay, trong khi bản JS cũ cho qua.
 */
class TruongTrangDocTest extends TestCase
{
    /**
     * Trường snake_case trang đọc mà KHÔNG thuộc schema của endpoint nó gọi.
     *
     * Mỗi dòng là một món nợ đã biết, không phải một ngoại lệ được chúc phúc.
     * Danh sách rỗng là trạng thái đúng; thêm dòng vào đây phải kèm lý do.
     *
     * @var array<string, string>
     */
    private const CHAP_NHAN = [];

    private function spec(): array
    {
        $this->assertTrue(
            class_exists(Yaml::class),
            'Thiếu symfony/yaml. Chạy: composer require --dev symfony/yaml',
        );

        return Yaml::parseFile(base_path('docs/openapi/v2.yaml'));
    }

    /** @return array<int, string> đường dẫn tệp blade của các trang đọc API */
    private function trangDocApi(): array
    {
        $khai = json_decode(
            file_get_contents(base_path('tests/js/moc-dom.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return array_values(array_filter(
            array_keys($khai),
            fn (string $k) => ! str_starts_with($k, '//'),
        ));
    }

    /**
     * Đường API một trang gọi, chuẩn hoá về dạng so được với `paths` của đặc tả.
     *
     * Đoạn động (`' . $campaign->id . '`) thành `{*}`, và khoá của đặc tả cũng
     * vậy — nên phép so không phụ thuộc việc tham số tên `campaign` hay `item`.
     *
     * @return array<int, string>
     */
    private function duongApi(string $trang): array
    {
        // Bỏ chú thích Blade TRƯỚC khi trích.
        //
        // `cart.blade.php` có một khối `{{-- --}}` giải thích đúng cái bẫy của
        // Blade, và trong đó có chuỗi `url('/api/v2/cart'` **không đóng
        // ngoặc** — cố ý, vì nó đang mô tả lỗi. Bản đầu của bộ trích khớp từ
        // đó rồi chạy tới dấu `)` tiếp theo, nên nó đọc ra một "đường API" là
        // mấy dòng văn xuôi tiếng Việt.
        //
        // Và `[^)\n]` chứ không `[^)]`: một lời gọi thật nằm trên một dòng, nên
        // không cho phép khớp qua dòng là lớp phòng thứ hai.
        $nguon = (string) preg_replace('/\{\{--[\s\S]*?--\}\}/', ' ', file_get_contents(base_path($trang)));
        $nguon = self::boChuThich($nguon);
        $ra    = [];

        preg_match_all("/url\(\s*('\/api\/v2[^)\n]*)\)/", $nguon, $khop);

        foreach ($khop[1] as $bieuThuc) {
            // `'/api/v2/campaigns/' . $campaign->id . '/payments'`
            //   → `/api/v2/campaigns/{*}/payments`
            //
            // Thay biến TRƯỚC rồi mới bỏ dấu nối, nên nó đúng cho cả đoạn động
            // ở giữa và đoạn động ở cuối (`… . $campaign->id` không có chuỗi
            // nào theo sau) — bản đầu chỉ xử được trường hợp ở giữa và vỡ ở
            // `campaign-detail`.
            //
            // Bỏ luôn dấu chấm: đường API của v2 không có đoạn nào chứa dấu
            // chấm, nên đây là phép đơn giản an toàn. Thêm một đường có dấu
            // chấm thì phải sửa chỗ này cùng lượt.
            $d = preg_replace('/\$[A-Za-z_]\w*(?:->\w+)*/', '{*}', $bieuThuc);
            $d = str_replace(["'", ' ', '.'], '', (string) $d);

            $ra[] = $d;
        }

        return array_values(array_unique($ra));
    }

    private function chuanHoaKhoa(string $duong): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{*}', $duong);
    }

    /**
     * Mọi tên thuộc tính có thể với tới từ schema phản hồi 200 của một đường.
     *
     * Đi theo `$ref` vào `components.schemas`, có chống lặp vòng. Gom cả
     * `properties`, `items`, `allOf`/`oneOf`/`anyOf` và
     * `additionalProperties` — thiếu một nhánh nào trong đó thì chốt báo oan.
     *
     * @return array<int, string>
     */
    private function truongCuaDuong(array $spec, string $duong): array
    {
        $khoa = null;

        foreach (array_keys($spec['paths'] ?? []) as $k) {
            if ($this->chuanHoaKhoa($k) === $duong) {
                $khoa = $k;
                break;
            }
        }

        $this->assertNotNull(
            $khoa,
            "Đặc tả không có đường nào khớp `{$duong}` — hoặc trang gọi một đường "
            . 'không tồn tại, hoặc đặc tả thiếu nó.',
        );

        $schema = $spec['paths'][$khoa]['get']['responses'][200]['content']['application/json']['schema'] ?? null;

        $this->assertNotNull($schema, "Đường {$khoa} không khai schema phản hồi 200.");

        $ra = [];
        $daDi = [];

        $di = function ($n) use (&$di, $spec, &$ra, &$daDi): void {
            if (! is_array($n)) {
                return;
            }

            if (isset($n['$ref'])) {
                $ref = $n['$ref'];

                if (in_array($ref, $daDi, true)) {
                    return;
                }

                $daDi[] = $ref;

                // `#/components/schemas/Payment` → ['components','schemas','Payment']
                $duongRef = explode('/', ltrim($ref, '#/'));
                $dich = $spec;

                foreach ($duongRef as $doan) {
                    $dich = $dich[$doan] ?? null;

                    if ($dich === null) {
                        return;
                    }
                }

                $di($dich);

                return;
            }

            foreach ($n['properties'] ?? [] as $ten => $con) {
                $ra[] = $ten;
                $di($con);
            }

            foreach (['items', 'additionalProperties'] as $k) {
                if (isset($n[$k])) {
                    $di($n[$k]);
                }
            }

            foreach (['allOf', 'oneOf', 'anyOf'] as $k) {
                foreach ($n[$k] ?? [] as $con) {
                    $di($con);
                }
            }
        };

        $di($schema);

        return array_values(array_unique($ra));
    }

    /**
     * Bỏ chú thích JS, giữ nguyên chuỗi **và biểu thức chính quy**.
     *
     * Bản JS đầu tiên của chốt này chỉ theo dõi ba kiểu nháy, và mọi trang đều
     * có `.replace(/'/g, '&#39;')`. `/'/g` là một regex literal chứa dấu nháy
     * đơn, nên máy mở trạng thái chuỗi ở đó, lệch nhịp, rồi thôi bỏ chú thích
     * — và chốt báo một tên nằm trong docblock là "thiếu trong đặc tả".
     *
     * Phép đoán phân biệt chia với regex: `/` mở regex khi ký tự có nghĩa
     * trước nó cho phép một toán hạng đứng sau.
     */
    public static function boChuThich(string $js): string
    {
        $moRegex = ['(', ',', '=', ':', '[', '!', '&', '|', '?', '{', '}', ';', '+', '-', '*', '%', '~', '^', '<', '>', ''];

        $ra = '';
        $i = 0;
        $n = strlen($js);
        $nhay = null;
        $chuThich = null;
        $truoc = '';

        while ($i < $n) {
            $c = $js[$i];
            $d = $js[$i + 1] ?? '';

            if ($chuThich === 'dong') {
                if ($c === "\n") { $chuThich = null; $ra .= $c; }
                $i++;
                continue;
            }

            if ($chuThich === 'khoi') {
                if ($c === '*' && $d === '/') { $chuThich = null; $i += 2; continue; }
                if ($c === "\n") { $ra .= $c; }
                $i++;
                continue;
            }

            if ($nhay !== null) {
                $ra .= $c;

                if ($c === '\\') { $ra .= $js[$i + 1] ?? ''; $i += 2; continue; }
                if ($c === $nhay) { $nhay = null; $truoc = $c; }

                $i++;
                continue;
            }

            if ($c === '"' || $c === "'" || $c === '`') { $nhay = $c; $ra .= $c; $truoc = $c; $i++; continue; }
            if ($c === '/' && $d === '/') { $chuThich = 'dong'; $i += 2; continue; }
            if ($c === '/' && $d === '*') { $chuThich = 'khoi'; $i += 2; continue; }

            if ($c === '/' && in_array($truoc, $moRegex, true)) {
                $ra .= $c;
                $i++;
                $trongLop = false;

                while ($i < $n) {
                    $r = $js[$i];
                    $ra .= $r;

                    if ($r === '\\') { $ra .= $js[$i + 1] ?? ''; $i += 2; continue; }
                    if ($r === '[') { $trongLop = true; }
                    elseif ($r === ']') { $trongLop = false; }
                    elseif ($r === '/' && ! $trongLop) { $i++; break; }
                    elseif ($r === "\n") { $i++; break; }

                    $i++;
                }

                $truoc = '/';
                continue;
            }

            $ra .= $c;

            if (trim($c) !== '') { $truoc = $c; }

            $i++;
        }

        return $ra;
    }

    /** @return array<int, string> tên snake_case khối script của trang đọc */
    private function truongTrangDoc(string $trang): array
    {
        $nguon = file_get_contents(base_path($trang));

        preg_match_all("/<script>\n([\s\S]*?)\n<\/script>/", $nguon, $khop);

        $this->assertCount(1, $khop[1], "{$trang}: cần đúng một khối script hành vi.");

        $js = self::boChuThich($khop[1][0]);
        $ra = [];

        preg_match_all('/\.([a-z][a-z0-9]*(?:_[a-z0-9]+)+)\b/', $js, $m1);
        preg_match_all("/\[\s*'([a-z][a-z0-9]*(?:_[a-z0-9]+)+)'\s*\]/", $js, $m2);

        return array_values(array_unique(array_merge($m1[1], $m2[1])));
    }

    // ── Phép kiểm ───────────────────────────────────────────────────────────

    public function test_moi_truong_trang_doc_deu_thuoc_schema_cua_dung_endpoint(): void
    {
        $spec = $this->spec();
        $xau  = [];

        foreach ($this->trangDocApi() as $trang) {
            $duocPhep = [];

            foreach ($this->duongApi($trang) as $duong) {
                $duocPhep = array_merge($duocPhep, $this->truongCuaDuong($spec, $duong));
            }

            foreach ($this->truongTrangDoc($trang) as $t) {
                if (in_array($t, $duocPhep, true)) {
                    continue;
                }

                $khoa = basename($trang) . ':' . $t;

                if (array_key_exists($khoa, self::CHAP_NHAN)) {
                    continue;
                }

                $xau[] = "{$trang}: `{$t}` — endpoint nó gọi không khai trường này";
            }
        }

        $this->assertSame([], $xau, implode("\n", [
            'Trang đọc những trường sau mà schema của ĐÚNG endpoint nó gọi không',
            'khai. Một trong hai bên sai: hoặc đặc tả thiếu/cũ, hoặc trang đọc một',
            'trường của endpoint khác — và đó là cách `bank_*` rò ra một đường thứ hai:',
            '  ' . implode("\n  ", $xau),
        ]));
    }

    /**
     * Bộ quét phải tìm ra thứ gì — một regex vỡ không được thành test xanh.
     *
     * Sáu trang, ≥60 trường, và mỗi trang gọi ít nhất một đường API. Ngưỡng
     * đặt sát con số thật, không đặt cho an toàn: một ngưỡng quá lỏng là một
     * phép kiểm chống-rỗng không chống gì (bài học từ `TheEnumPhaiCoChuTest`,
     * nơi `>= 20` cho qua một bộ quét chỉ thấy 59 trong 169 tệp).
     */
    public function test_bo_quet_khong_rong(): void
    {
        $trang = $this->trangDocApi();

        $this->assertGreaterThanOrEqual(6, count($trang), 'thiếu trang đọc API');

        $tong = 0;

        foreach ($trang as $t) {
            $duong = $this->duongApi($t);
            $truong = $this->truongTrangDoc($t);

            $this->assertNotEmpty($duong, "{$t}: không trích được đường API nào");
            $this->assertNotEmpty($truong, "{$t}: không trích được trường nào");

            $tong += count($truong);
        }

        $this->assertGreaterThanOrEqual(60, $tong, "chỉ trích được {$tong} trường trên cả sáu trang");
    }

    /**
     * Hai đường thật phải cho ra hai tập trường KHÁC nhau.
     *
     * Không có ca này thì một lỗi trong bộ đi theo `$ref` có thể làm
     * `truongCuaDuong()` trả về hợp của mọi schema — và phép kiểm chính lại
     * thành phép tìm chuỗi trong cả tệp, tức đúng thứ chốt này tồn tại để thay.
     */
    public function test_pham_vi_that_su_theo_tung_endpoint(): void
    {
        $spec = $this->spec();

        $tien   = $this->truongCuaDuong($spec, '/api/v2/campaigns/{*}/payments');
        $nguoi  = $this->truongCuaDuong($spec, '/api/v2/campaigns/{*}/payment-recipients');

        $this->assertNotEmpty($tien);
        $this->assertNotEmpty($nguoi);

        // `bank_*` chỉ ra ngoài qua MỘT đường — ngoại lệ đã duyệt 08/10.
        $this->assertContains('bank_name', $nguoi, 'đường nơi-nhận-tiền phải khai bank_name');
        $this->assertNotContains(
            'bank_name',
            $tien,
            'đường công nợ KHÔNG được khai bank_name — nếu nó có, ngoại lệ bảo mật '
            . 'đã thành hai đường và phải xem lại ngay.',
        );
    }

    /**
     * Bộ bỏ chú thích, kiểm trực tiếp.
     *
     * Ca này canh chính công cụ, không canh trang nào — và nó tồn tại vì bản
     * JS đầu tiên sai ở đúng chỗ `.replace(/'/g, …)`.
     */
    public function test_bo_chu_thich_giu_chuoi_va_regex(): void
    {
        $vao = implode("\n", [
            "var u = 'https://oohx.net/api/v2'; // doc cf.khong_co_that",
            '/* khoi: p.cung_khong_co_that */',
            "function chu(s) { return s.replace(/'/g, '&#39;'); }",
            "/** docblock: config('pricing.ten_trong_chu_thich') */",
            'var x = p.ten_trong_ma;',
            'var t = a / b; // ten_bi_bo',
        ]);

        $ra = self::boChuThich($vao);

        $this->assertStringContainsString('https://oohx.net/api/v2', $ra, '`//` trong chuỗi phải được giữ');
        $this->assertStringContainsString('ten_trong_ma', $ra, 'mã sau regex phải còn');
        $this->assertStringContainsString('a / b', $ra, 'phép chia vẫn là phép chia');

        $this->assertStringNotContainsString('khong_co_that', $ra);
        $this->assertStringNotContainsString('cung_khong_co_that', $ra);
        $this->assertStringNotContainsString('ten_trong_chu_thich', $ra, 'docblock sau regex vẫn phải bị bỏ');
        $this->assertStringNotContainsString('ten_bi_bo', $ra);
    }
}
