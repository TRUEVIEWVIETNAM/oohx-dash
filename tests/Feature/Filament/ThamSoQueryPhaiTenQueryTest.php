<?php

namespace Tests\Feature\Filament;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Tham số đầu của closure `->query(...)` **phải** tên `$query`.
 *
 * ══ Vì sao tên quan trọng, đọc từ mã Filament ══
 *
 * `Filament\Tables\Filters\Concerns\InteractsWithTableQuery::apply()` gọi
 * `$this->evaluate($callback, ['data' => …, 'query' => $query, 'state' => …])`
 * rồi **bỏ giá trị trả về** và `return $query` — nên closure phải sửa chính
 * cái Builder được truyền vào.
 *
 * `EvaluatesClosures::resolveClosureDependencyForEvaluation()` tra theo **tên**
 * trước. Không khớp tên thì nó rơi xuống nhánh
 *
 *     if (filled($typedParameterClassName)) { return app()->make($typedParameterClassName); }
 *
 * tức nó **dựng một Builder mới từ container**. Closure sửa cái Builder đó,
 * truy vấn của bảng không bị chạm, và bộ lọc **im lặng không làm gì**. Không
 * ngoại lệ nào ném ra, không dòng log nào.
 *
 * `CLAUDE.md` mục 4 đã ghi cái bẫy này. Tệp này là phép kiểm cho nó.
 *
 * ══ Lát 3 của plan ảnh suýt mắc ══
 *
 * Lượt đột biến thứ năm đổi `fn (Builder $query) => …` thành
 * `fn (Builder $q) => …` trong bộ lọc "Chỉ vị trí thiếu ảnh" và **cả 12 ca đều
 * xanh** — vì chốt của lát đó gọi scope `thieuAnhThat()` trực tiếp, không bao
 * giờ đi qua đối tượng filter. Ở đây sự im lặng ấy đặc biệt tệ: một bộ lọc
 * không lọc gì trả **toàn bộ** kho, và chủ vị trí đọc nó như "mọi vị trí của
 * tôi đều thiếu ảnh".
 *
 * ══ Đây là một CHỐT, không phải một phép kiểm "sạch" ══
 *
 * Quét lúc viết (24/10/2026) tìm thấy **năm** chỗ đang sai, cả năm ở hai
 * resource dự toán. Chúng **không** được sửa lúc đó: sửa một bộ lọc đang bất
 * động thành một bộ lọc có tác dụng là đổi hành vi của hai trang quản trị
 * ngoài phạm vi plan ảnh, và đó là một quyết định riêng.
 *
 * Nên tệp này ghi lại đúng chúng và đòi danh sách thật **khớp từng dòng**:
 *
 *  - thêm một closure `->query()` đặt tên khác → danh sách dài hơn → **đỏ**;
 *  - sửa một chỗ trong danh sách → danh sách ngắn hơn → **đỏ**, nhắc gạch nó
 *    khỏi đây. Một chốt chỉ siết một chiều thì sẽ mục.
 *
 * ══ `sửa năm bộ lọc dự toán` — 26/10/2026 ══
 *
 * Chủ dự án chốt việc ấy, nên `DANG_SAI` nay **rỗng**: chiều thứ hai của chốt
 * đã làm đúng việc của nó, nó đỏ lên ngay khi năm chỗ được sửa.
 *
 * Lượt sửa đó phát hiện bộ quét này **mù bốn chỗ**. Nó chỉ đọc `->query(` và
 * `->modifyQueryUsing(`, trong khi cùng cái bẫy còn sập ở cột:
 * `Columns\Concerns\InteractsWithTableQuery` cũng `evaluate()` theo tên, cũng
 * không dạy container kiểu `Builder`, nên `searchable(query: fn (Builder $q…))`
 * làm ô tìm kiếm **không lọc gì** và `sortable(query: …)` làm cột **không sắp
 * được**. Hai chỗ `searchable` + một `sortable` ở `OohxEstimateResource` và một
 * `searchable` ở `OohxRecomputeJobResource` đã nằm đó suốt, xanh dưới mắt bộ
 * quét này. Mẫu regex nay phủ cả `searchable(query:`, `sortable(query:` và
 * `->baseQuery(`.
 *
 * Bộ quét so chuỗi, nên nó không chứng minh được *vì sao* tên lại quan trọng —
 * và cũng không biết ba hình dạng **gần giống mà vô hại**:
 * `TernaryFilter::queries(true:, false:)` cùng `->when()`/`->unless()` của
 * Laravel gọi closure **theo vị trí**, nên `$q` ở đó hoàn toàn đúng và mẫu
 * regex cố ý không bắt. Phần đo hành vi — cái bẫy sập thật, `whereHas` trên
 * Builder không model thì **nổ** chứ không im lặng, và ba hình dạng vô hại kia
 * — ở `tests/Feature/Filament/BoLocDuToanTest.php`.
 */
class ThamSoQueryPhaiTenQueryTest extends TestCase
{
    /**
     * Những chỗ đang sai — `tệp:tên tham số`.
     *
     * Rỗng từ 26/10/2026. Năm chỗ cũ (hai bộ lọc của
     * `OohxCampaignEstimateResource`, ba của `OohxEstimateResource`) đã được
     * sửa cùng bốn chỗ `searchable`/`sortable` mà bộ quét này từng mù.
     *
     * Để trống **không** làm chốt yếu đi: ca dưới đây vẫn so khớp từng dòng,
     * nên thêm một chỗ sai mới là đỏ ngay. Và `test_bo_quet_khong_rong` canh
     * cho chính mẫu regex, để một danh sách rỗng không bị đọc là "sạch" khi
     * thật ra là "không quét được gì".
     *
     * @var list<string>
     */
    private const DANG_SAI = [];

    /**
     * Những lời gọi truyền closure qua `evaluate()` dưới **tên** `query`.
     *
     * Một hằng dùng chung cho cả `quet()` và `test_bo_quet_khong_rong()`: bản
     * trước giữ hai bản regex riêng, nên nới một bản mà quên bản kia là bộ quét
     * đếm một tập còn kiểm một tập khác.
     *
     * Cố ý **không** có `->queries(` (dấu `(` phải nằm ngay sau `query`, nên
     * `queries` không khớp), `->when(` và `->unless(`: cả ba gọi closure theo
     * **vị trí**, nên tên tham số ở đó không có vai trò gì. Đo ở
     * `BoLocDuToanTest`.
     */
    private const MO_DAU = '(?:->(?:query|baseQuery|modifyQueryUsing)\(|(?:searchable|sortable)\(\s*query:)';

    /**
     * Bỏ chú thích trước khi quét.
     *
     * Docblock của tệp này nhắc nguyên văn `fn (Builder $q) => …` để giải
     * thích cái bẫy. Một phép tìm chuỗi thô sẽ đếm chính lời giải thích đó là
     * một vi phạm — cùng cái bẫy đã làm đỏ ca đếm `getScreenAvailability` ở
     * lát 3 của plan trang chi tiết.
     *
     * ══ Vì sao là `php_strip_whitespace()` chứ không phải regex ══
     *
     * Bản đầu bỏ chú thích bằng một regex "mở chú thích … đóng chú thích,
     * lười". Lát 2 của plan ảnh viết một docblock có nhắc `image/` + dấu sao,
     * và cặp ký tự ấy mở một chú thích **giả**: phép thay thế chạy tới dấu
     * đóng của docblock kế tiếp và ăn luôn cả khối mã ở giữa. Bộ quét của lát
     * 2 đỏ với "không thấy closure nào" — may là nó có ca canh rỗng. Bộ quét
     * này thì **không** đỏ, nó chỉ im lặng quét thiếu, tức xanh giả.
     *
     * (Lời giải thích này không viết nổi cặp ký tự đóng chú thích ra đây —
     * viết ra là docblock tự kết thúc giữa câu. Đúng một lần nữa cái bẫy.)
     *
     * `php_strip_whitespace()` dùng tokenizer của PHP, nên `image/*` nằm
     * trong một chuỗi hay một chú thích đều không mở được gì cả. Đây cũng là
     * cách `CLAUDE.md`-era các chốt đọc mã nguồn khác của dự án đang dùng.
     */
    private function khongChuThich(string $duongTep): string
    {
        return php_strip_whitespace($duongTep);
    }

    /** @return list<string> */
    private function quet(): array
    {
        $thay = [];

        foreach (File::allFiles(base_path('app/Filament')) as $tep) {
            if ($tep->getExtension() !== 'php') {
                continue;
            }

            $ma = $this->khongChuThich($tep->getRealPath());

            // Kiểu tham số là tuỳ chọn: `fn($query, $data)` không khai kiểu vẫn
            // hợp lệ, và vẫn phải đúng tên.
            preg_match_all(
                '#' . self::MO_DAU . '\s*(?:static\s+)?(?:fn|function)\s*\(\s*(?:\??[\w\\\\|]+\s+)?\$(\w+)#',
                $ma,
                $khop,
            );

            foreach ($khop[1] as $ten) {
                if ($ten === 'query') {
                    continue;
                }

                $duong = str_replace('\\', '/', substr($tep->getRealPath(), strlen(base_path()) + 1));
                $thay[] = $duong . ':$' . $ten;
            }
        }

        sort($thay);

        return $thay;
    }

    public function test_bo_quet_khong_rong(): void
    {
        // Một bộ quét không tìm thấy gì cả thì không canh gì cả. Đếm tổng số
        // closure `->query()` để biết nó thật sự đọc được mã.
        $tong = 0;

        foreach (File::allFiles(base_path('app/Filament')) as $tep) {
            if ($tep->getExtension() !== 'php') {
                continue;
            }

            $tong += preg_match_all(
                '#' . self::MO_DAU . '\s*(?:static\s+)?(?:fn|function)\s*\(#',
                $this->khongChuThich($tep->getRealPath()),
                $bo,
            );
        }

        $this->assertGreaterThan(
            10,
            $tong,
            'Bộ quét không thấy closure `->query()` nào — regex hỏng, không phải panel sạch.',
        );

    }

    /**
     * Mẫu `MO_DAU` phải phủ đúng bốn họ lời gọi, và **chỉ** bốn họ đó.
     *
     * Ca này không đọc mã dự án, nó đọc chính cái hằng — vì một ca đếm trên mã
     * dự án không bắt được việc nới hay thu mẫu. Đo thật: lượt đột biến thứ hai
     * của 26/10/2026 gỡ hẳn nhánh cột khỏi `MO_DAU` (đưa bộ quét về đúng trạng
     * thái mù bốn chỗ của bản cũ) và **cả hai ca kia vẫn xanh** — vì phép đếm
     * nhánh cột lúc ấy dùng một regex viết tay riêng, không đọc hằng. Đúng cái
     * lỗi hai-bản-regex mà hằng này được đặt ra để dẹp.
     */
    public function test_mau_mo_dau_phu_dung_bon_ho_loi_goi(): void
    {
        $mau = '#' . self::MO_DAU . '#';

        // Bốn họ đi qua `evaluate()` dưới tên `query` — phải khớp.
        foreach ([
            '->query(fn (Builder $query) => $query)',
            '->baseQuery(fn (Builder $query) => $query)',
            '->modifyQueryUsing(fn (Builder $query) => $query)',
            '->searchable(query: fn (Builder $query) => $query)',
            '->sortable(query: fn (Builder $query) => $query)',
        ] as $doan) {
            $this->assertMatchesRegularExpression($mau, $doan, "Mẫu không còn phủ: {$doan}");
        }

        // Ba họ gọi closure theo **vị trí** — không được khớp, xem `BoLocDuToanTest`.
        foreach ([
            '->queries(true: fn (Builder $q) => $q, false: fn (Builder $q) => $q)',
            '->when($co, fn (Builder $q) => $q)',
            '->unless($co, fn (Builder $q) => $q)',
        ] as $doan) {
            $this->assertDoesNotMatchRegularExpression($mau, $doan, "Mẫu bắt một chỗ đang đúng: {$doan}");
        }
    }

    public function test_khong_them_closure_query_nao_dat_ten_khac(): void
    {
        $sai = self::DANG_SAI;
        sort($sai);

        $this->assertSame(
            $sai,
            $this->quet(),
            "Danh sách closure `->query()` đặt tên khác `\$query` đã đổi.\n"
            . "Thêm một chỗ: đổi tên tham số thành `\$query` — Filament tra theo TÊN,\n"
            . "và tên khác làm bộ lọc im lặng không làm gì.\n"
            . 'Sửa một chỗ trong danh sách: gạch nó khỏi `DANG_SAI` của tệp này.',
        );
    }
}
