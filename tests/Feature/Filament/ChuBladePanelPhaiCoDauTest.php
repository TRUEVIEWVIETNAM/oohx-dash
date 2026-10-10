<?php

namespace Tests\Feature\Filament;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Chữ trong tệp Blade của panel Filament phải là tiếng Việt.
 *
 * ══ Khoảng trống tệp này bịt ══
 *
 * `ChuPanelPhaiCoDauTest` canh chuỗi hằng trong **PHP** — `->label('…')`,
 * `$navigationLabel = '…'`. Docblock của nó nói rõ một chỗ nó không thấy: chữ
 * trong tệp Blade. Đó là 43 tệp dưới `resources/views/filament`, và chúng có
 * **265** chuỗi tiếng Anh, gồm cả bảng Ma trận quyền, trang Data Engine và
 * hộp chi tiết màn hình.
 *
 * Trong đó có một thứ không bộ dò nào theo quy ước `<CỘT>_LABELS` hay
 * `->label()` bắt được: **tiếng Việt viết KHÔNG DẤU**.
 *
 *     Click ban do hoac keo marker de dat toa do. Tim kiem dung Nominatim.
 *
 * Nó không phải tiếng Anh, nên không nằm trong danh sách "chưa dịch"; nó cũng
 * không có dấu, nên phép dò dấu bắt được. Đó là lý do phép dò chọn **dấu** làm
 * tín hiệu, không chọn "có phải từ tiếng Anh".
 *
 * ══ Cách dò: TEXT NODE, không bóc tag ══
 *
 * Bản đầu bóc tag bằng `preg_replace('/<[^>]*>/', …)` và nó **vỡ**: một tag có
 * `=>` trong thuộc tính Alpine (`x-bind:class="{ 'a' => b }"`) không bị bóc
 * hết, nên mã JS lọt ra thành "chữ" — 757 chuỗi, phần lớn là mảnh
 * `$wire.set(...)`.
 *
 * Bản này lấy phần giữa `>` và `<` mà bên trong không có `<` hay `>`. Một text
 * node HTML không chứa được hai dấu đó, nên phép này đúng theo định nghĩa chứ
 * không theo may mắn.
 *
 * ══ Ba chỗ nó thô hơn chốt PHP, nói ra ══
 *
 *  1. **Không thấy chữ nằm cùng node với directive Blade.** `@else` rồi
 *     `Screen #{{ $id }}` rồi `@endif` nằm trong MỘT text node, và phép so
 *     khớp-chính-xác không khớp được cả cụm đó. Hai chỗ như vậy đã phải sửa
 *     bằng tay.
 *  2. **Chỉ đọc bốn thuộc tính** (`title`, `placeholder`, `alt`, `aria-label`),
 *     và chỉ khi giá trị không chứa biểu thức. `alt="Screen photo"` lọt qua
 *     vòng áp bảng vì bộ áp chỉ sửa text node — tìm ra nhờ bộ dò, sửa tay.
 *  3. **Thực thể HTML.** Bộ dò giải mã `&amp;` trước khi so, bộ áp thì so chuỗi
 *     thô — nên `Revenue &amp; Impressions` trượt vòng một.
 *
 * Nên tệp này là một chốt chống **thêm mới**, và nó nói thẳng rằng nó không
 * phải bằng chứng "mọi chữ trong Blade đều đã dịch".
 *
 * ══ Chốt hai chiều ══
 *
 * `CHO_PHEP` ghi lại đúng những chuỗi không dấu được giữ. Thêm chữ tiếng Anh
 * mới → đỏ; dịch một chuỗi mà quên gạch khỏi danh sách → cũng đỏ.
 */
class ChuBladePanelPhaiCoDauTest extends TestCase
{
    /** Chuỗi không dấu được giữ, kèm lý do — tính tới 10/10/2026. */
    private const CHO_PHEP = [
        // Tên sản phẩm / chuẩn / dịch vụ.
        'Data Engine', 'OpenStreetMap', 'Nominatim.', 'Rsync', 'Powered by',

        // Viết tắt ngành và từ mượn.
        'Email', 'Logo', 'Website', 'Panel', 'POI', 'GPS', 'RTB', 'cpm',
        'Media owner', 'Sheet:',

        // Tiếng Việt KHÔNG dấu — đúng chính tả, chỉ là không có dấu nào.
        'Chung', 'Nam', 'Sau', 'Sau →', 'Xong', 'xanh', 'token ra',

        // Mã vai trò trong bảng Ma trận quyền — hai bên đều là mã.
        'super_admin', 'publisher',

        // Khoá kỹ thuật, tên cột, tên tệp, đường dẫn: đổi là sai.
        'external_id', 'health-digest-YYYYMMDD.json',
        'storage/logs/oohx-health.log', 'sql/013_analytics_views.sql',
        'refresh-analytics', 'apps',

        // Lệnh để người vận hành chép-dán. Dịch một lệnh là làm nó không chạy.
        'ssh -i /www/wwwroot/dash.oohx.net/storage/app/oohx-ssh/oohx_sync \\',
        'systemctl status oohx-pg-tunnel',

        // Cấu hình Leaflet nằm trong thuộc tính, không phải chữ hiển thị.
        "attribution: '©",
        "iconRetinaUrl: '/vendor/leaflet/images/marker-icon-2x.png',",
        "iconUrl: '/vendor/leaflet/images/marker-icon.png',",
        "shadowUrl: '/vendor/leaflet/images/marker-shadow.png',",
    ];

    private const THU_MUC = 'resources/views/filament';

    /** @return array<int, string> mọi tệp Blade trong tầm */
    private function tep(): array
    {
        $ra = [];

        // `File::allFiles()`, KHÔNG `RecursiveIteratorIterator` — lý do đầy đủ
        // ở `ChuPanelPhaiCoDauTest::tep()`.
        foreach (File::allFiles(base_path(self::THU_MUC)) as $f) {
            if (str_ends_with($f->getFilename(), '.blade.php')) {
                $ra[] = $f->getPathname();
            }
        }

        sort($ra);

        return $ra;
    }

    private function coDau(string $s): bool
    {
        return (bool) preg_match(
            '/[àáảãạăằắẳẵặâầấẩẫậèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ'
            . 'ÀÁẢÃẠĂẰẮẲẴẶÂẦẤẨẪẬÈÉẺẼẸÊỀẾỂỄỆÌÍỈĨỊÒÓỎÕỌÔỒỐỔỖỘƠỜỚỞỠỢÙÚỦŨỤƯỪỨỬỮỰỲÝỶỸỴĐ]/u',
            $s,
        );
    }

    /**
     * Trông như mã, không như chữ.
     *
     * Phép lọc này thô có chủ ý: thà bỏ qua vài chuỗi còn hơn báo oan, vì một
     * phép kiểm báo oan thì sẽ bị tắt.
     */
    private function laMa(string $s): bool
    {
        if (str_starts_with($s, '@')) {
            return true; // directive Blade nằm giữa `>` và `<`
        }

        if (preg_match('#^(cd |php artisan |\.venv/|python |/[a-z]|[a-z_]+\.[a-z_]+$|[a-z]+:[a-z-]+$|[a-z_]+@[0-9])#', $s)) {
            return true; // lệnh shell, đường dẫn, tên lệnh artisan
        }

        foreach (['$', '=>', '->', '::', '??', '?:', '//', '{{', '}}', '(', ')', '{', '}', '[', ']', ';', '='] as $d) {
            if (str_contains($s, $d)) {
                return true;
            }
        }

        return ! preg_match('/\p{L}{3,}/u', $s);
    }

    /**
     * Mọi chuỗi hiển thị KHÔNG dấu tìm được.
     *
     * @return array<string, array<int, string>> `chuỗi => tệp`
     */
    private function chuKhongDau(): array
    {
        $ra = [];

        foreach ($this->tep() as $tep) {
            $s = file_get_contents($tep);

            // Bỏ những khối KHÔNG phải chữ người đọc.
            $s = preg_replace('/\{\{--[\s\S]*?--\}\}/', ' ', $s);
            $s = preg_replace('/<script\b[\s\S]*?<\/script>/i', ' ', $s);
            $s = preg_replace('/<style\b[\s\S]*?<\/style>/i', ' ', $s);
            $s = preg_replace('/@php[\s\S]*?@endphp/', ' ', $s);

            $ung = [];

            foreach (['title', 'placeholder', 'alt', 'aria-label'] as $tt) {
                preg_match_all('/\b' . $tt . '="([^"{$]+)"/', $s, $k);
                $ung = array_merge($ung, $k[1]);
            }

            preg_match_all('/>([^<>]+)</', $s, $k);

            foreach ($k[1] as $node) {
                $node = preg_replace('/\{\{[\s\S]*?\}\}/', "\n", $node);
                $node = preg_replace('/\{!![\s\S]*?!!\}/', "\n", $node);
                $node = html_entity_decode($node, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                foreach (preg_split('/\n/', $node) as $dong) {
                    $ung[] = trim(preg_replace('/\s+/u', ' ', $dong));
                }
            }

            foreach ($ung as $chu) {
                $chu = trim($chu);

                if ($chu === '' || $this->coDau($chu) || $this->laMa($chu)) {
                    continue;
                }

                $ra[(string) $chu][] = basename($tep);
            }
        }

        ksort($ra);

        return $ra;
    }

    /**
     * Bộ quét phải tìm ra thứ gì.
     *
     * Hai ngưỡng sát con số thật: 43 tệp Blade, và hơn 1900 text node trích
     * được. Bài học `>= 20` của `TheEnumPhaiCoChuTest` — một ngưỡng quá lỏng là
     * một phép kiểm chống-rỗng không chống gì.
     */
    public function test_bo_quet_khong_rong(): void
    {
        $this->assertGreaterThanOrEqual(
            40,
            count($this->tep()),
            'Chỉ thấy ' . count($this->tep()) . ' tệp Blade trong khi tầm quét có ~43.',
        );

        $this->assertGreaterThanOrEqual(
            1500,
            $this->demTextNode(),
            'Chỉ trích được ' . $this->demTextNode() . ' text node trong khi thực tế '
            . 'có hơn 1900 — regex đã vỡ, và phép so dưới đây đang canh ít hơn nó nói.',
        );
    }

    private function demTextNode(): int
    {
        $dem = 0;

        foreach ($this->tep() as $tep) {
            $dem += preg_match_all('/>([^<>]+)</', file_get_contents($tep));
        }

        return $dem;
    }

    public function test_khong_them_chu_tieng_anh_nao(): void
    {
        $thua = [];

        foreach ($this->chuKhongDau() as $chu => $tepDs) {
            if (! in_array((string) $chu, self::CHO_PHEP, true)) {
                $thua[] = "'{$chu}'  —  " . implode(', ', array_unique($tepDs));
            }
        }

        $this->assertSame(
            [],
            $thua,
            "Chữ sau trong tệp Blade của panel không có dấu tiếng Việt nào. Dịch nó,\n"
            . "hoặc nếu nó đúng là tên riêng / lệnh / khoá kỹ thuật thì khai vào\n"
            . "`CHO_PHEP` kèm lý do:\n  " . implode("\n  ", $thua),
        );
    }

    /** Chiều ngược: `CHO_PHEP` không được giữ dòng đã chết. */
    public function test_cho_phep_khong_co_dong_chet(): void
    {
        $tim  = array_map('strval', array_keys($this->chuKhongDau()));
        $chet = array_values(array_diff(self::CHO_PHEP, $tim));

        $this->assertSame(
            [],
            $chet,
            "Những chuỗi sau đã khai trong `CHO_PHEP` nhưng không còn trong mã —\n"
            . "gạch chúng khỏi danh sách:\n  " . implode("\n  ", $chet),
        );
    }
}
