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
 * resource dự toán. Chúng **không** được sửa ở đây: sửa một bộ lọc đang bất
 * động thành một bộ lọc có tác dụng là đổi hành vi của hai trang quản trị
 * ngoài phạm vi plan ảnh, và đó là một quyết định riêng.
 *
 * Nên tệp này ghi lại đúng chúng và đòi danh sách thật **khớp từng dòng**:
 *
 *  - thêm một closure `->query()` đặt tên khác → danh sách dài hơn → **đỏ**;
 *  - sửa một chỗ trong danh sách → danh sách ngắn hơn → **đỏ**, nhắc gạch nó
 *    khỏi đây. Một chốt chỉ siết một chiều thì sẽ mục.
 */
class ThamSoQueryPhaiTenQueryTest extends TestCase
{
    /**
     * Những chỗ đang sai lúc viết tệp này — `tệp:tên tham số`.
     *
     * Cả năm nằm trên bộ lọc của hai resource dự toán, và cả năm đang bất
     * động. Text để chủ dự án duyệt việc sửa: `sửa năm bộ lọc dự toán`.
     */
    private const DANG_SAI = [
        'app/Filament/Resources/OohxCampaignEstimateResource.php:$q',
        'app/Filament/Resources/OohxCampaignEstimateResource.php:$q',
        'app/Filament/Resources/OohxEstimateResource.php:$q',
        'app/Filament/Resources/OohxEstimateResource.php:$q',
        'app/Filament/Resources/OohxEstimateResource.php:$q',
    ];

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

            // `->query(` hoặc `->modifyQueryUsing(` theo sau là một closure.
            // Kiểu tham số là tuỳ chọn: `fn($query, $data)` không khai kiểu
            // vẫn hợp lệ, và vẫn phải đúng tên.
            preg_match_all(
                '#->(?:query|modifyQueryUsing)\(\s*(?:static\s+)?(?:fn|function)\s*\(\s*(?:\??[\w\\\\|]+\s+)?\$(\w+)#',
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
                '#->(?:query|modifyQueryUsing)\(\s*(?:static\s+)?(?:fn|function)\s*\(#',
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
