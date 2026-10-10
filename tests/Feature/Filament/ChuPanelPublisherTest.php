<?php

namespace Tests\Feature\Filament;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Chữ người dùng đọc trong panel publisher phải là tiếng Việt.
 *
 * ══ Lỗi tệp này canh ══
 *
 * Panel `/publisher` là nơi media owner làm việc hằng ngày, và nó từng có
 * **125** chuỗi tiếng Anh nằm lẫn giữa chữ Việt: nhóm điều hướng
 * "Inventory" / "Bookings" / "Reports" / "Tools" / "Settings", cột "Created" /
 * "Updated" / "Status", nhãn "Screen ID" / "Floor CPM" / "Max SOV", và cả
 * "Remove team member?" trong hộp xác nhận xoá người.
 *
 * Không ai thấy hết bằng mắt. Chúng rải trên 42 tệp, và phần lớn nằm ở
 * `app/Filament/Shared/Resources/Base*Resource` — nơi hai panel dùng chung nên
 * đọc một tệp không biết nó hiện ra ở đâu.
 *
 * ══ Cách dò: dấu tiếng Việt ══
 *
 * Một chuỗi tiếng Việt gần như luôn có một dấu (hoặc `đ`/`Đ`). Chuỗi không dấu
 * thì hoặc là tiếng Anh chưa dịch, hoặc là tên riêng / đơn vị / mã / ví dụ —
 * và nhóm thứ hai phải được **khai ra**, không được im lặng bỏ qua.
 *
 * Phép dò này cố ý thô, và nói rõ chỗ nó thô:
 *
 *  - Nó **không** biết một chuỗi có dấu dịch có ĐÚNG hay không. "Tạo lúc" và
 *    "Tạo lức" đều qua. Nó nói chữ đó đã được ai đó dịch, không nói dịch hay.
 *  - Nó **không** thấy chữ ghép lúc chạy (`'Còn ' . $n . ' chỗ'`), chữ trong
 *    Blade của panel, hay chữ do Filament tự sinh từ tên cột — một cột
 *    `BadgeColumn::make('status')` không khai `->label()` thì Filament in
 *    "Status", và không có chuỗi nào cho tệp này bắt. Đã gặp đúng chuyện đó ở
 *    `BaseNetworkResource` và `BaseSiteResource`.
 *  - Nó chỉ nhìn tham số **chuỗi hằng** của các lời gọi ở `GOI`, `THUOC_TINH`
 *    và `MAKE_LA_CHU`. Một nhãn gọi qua biến nằm ngoài tầm.
 *
 * ══ Nên nó là một CHỐT HAI CHIỀU, không phải phép kiểm "sạch" ══
 *
 * `CHO_PHEP` ghi lại đúng những chuỗi không dấu được giữ, và phép so đòi tập
 * thật **khớp từng phần tử**:
 *
 *  - thêm một nhãn tiếng Anh mới → tập thật dài hơn → **đỏ**;
 *  - dịch một chuỗi trong `CHO_PHEP` mà quên gạch nó khỏi đây → tập thật ngắn
 *    hơn → **đỏ**. Một chốt chỉ siết một chiều thì sẽ mục.
 */
class ChuPanelPublisherTest extends TestCase
{
    /**
     * Chuỗi không dấu được giữ, và lý do — tính tới 10/10/2026.
     *
     * Mỗi dòng là một quyết định, không phải một ngoại lệ được chúc phúc.
     */
    private const CHO_PHEP = [
        // Thuật ngữ ngành quảng cáo ngoài trời, dùng nguyên trong tiếng Việt.
        'AdOps', 'Programmatic', 'Media Owner',

        // Từ mượn đã vào tiếng Việt, không có bản dịch nào tự nhiên hơn.
        'Email', 'Website', 'Logo', 'Slug',

        // Viết tắt toạ độ trên đầu cột bảng, nơi "Vĩ độ"/"Kinh độ" quá dài.
        'Lat', 'Lon',

        // Tiếng Việt KHÔNG dấu — đúng chính tả, chỉ là không có dấu nào.
        'Xem', 'Nam %',

        // Nhóm tuổi: con số, không phải chữ.
        '18-24 %', '25-34 %', '35-44 %', '45+ %',

        // Ví dụ trong ô trống (placeholder), cố tình trông như dữ liệu thật.
        '1', '105.8542', '21.0285', '0912 345 6789',
        'contact@company.com', 'user@example.com', 'www.company.com',
        'Starbucks, Highlands, KFC',

        // Dấu gạch ngang cho ô rỗng.
        '—',
    ];

    /** Lời gọi mà tham số đầu là chữ người dùng đọc. */
    private const GOI = [
        'label', 'placeholder', 'helperText', 'description', 'title', 'heading',
        'body', 'emptyStateHeading', 'emptyStateDescription', 'modalHeading',
        'modalDescription', 'modalSubmitActionLabel', 'modalCancelActionLabel',
        'successNotificationTitle', 'navigationLabel', 'tooltip', 'hint',
        'breadcrumb', 'submitActionLabel', 'createAnotherActionLabel',
    ];

    /** Thuộc tính tĩnh mang chữ hiển thị. */
    private const THUOC_TINH = [
        'navigationLabel', 'navigationGroup', 'modelLabel', 'pluralModelLabel',
        'title', 'breadcrumb', 'heading', 'subheading', 'brandName',
    ];

    /**
     * `X::make('...')` nơi tham số là chữ hiển thị.
     *
     * `Action::make('...')` **không** nằm đây, và đó là chỗ bản đầu sai: tham
     * số của nó là **tên** action (`approveAll`, `toggle_rtb`, `quick_detail`),
     * không phải chữ. Bản đầu báo oan 14 chỗ như vậy, và một phép kiểm báo oan
     * thì sẽ bị tắt.
     */
    private const MAKE_LA_CHU = ['Section', 'Fieldset', 'Tab', 'NavigationGroup', 'Step'];

    private const THU_MUC = ['app/Filament/Publisher', 'app/Filament/Shared'];

    private const TEP_LE = ['app/Providers/Filament/PublisherPanelProvider.php'];

    /** @return array<int, string> mọi tệp PHP trong tầm */
    private function tep(): array
    {
        $ra = array_map(fn ($t) => base_path($t), self::TEP_LE);

        foreach (self::THU_MUC as $tm) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($tm)));

            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $ra[] = $f->getPathname();
                }
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
     * Mọi chuỗi hiển thị KHÔNG dấu tìm được.
     *
     * Khoá trả về **luôn là chuỗi**, kể cả `'1'`. PHP ép khoá mảng toàn số
     * thành `int`, nên `$ra['1']` quay ra là `$ra[1]` và
     * `in_array(1, ['1', …], true)` trượt — tệp này đã báo oan đúng một lần vì
     * chuyện đó, với ô ví dụ `->placeholder('1')` của "Số màn hình".
     *
     * @return array<string, array<int, string>> `chuỗi => tệp`
     */
    private function chuKhongDau(): array
    {
        $goc = base_path() . DIRECTORY_SEPARATOR;
        $ra  = [];

        $mau = [
            '/->(?:' . implode('|', self::GOI) . ")\(\s*'(?<chu>(?:[^'\\\\]|\\\\.)*)'/",
            '/\$(?:' . implode('|', self::THUOC_TINH) . ")\s*=\s*'(?<chu>(?:[^'\\\\]|\\\\.)*)'/",
            '/\b(?:' . implode('|', self::MAKE_LA_CHU) . ")::make\(\s*'(?<chu>(?:[^'\\\\]|\\\\.)*)'/",
        ];

        foreach ($this->tep() as $tep) {
            $nguon = file_get_contents($tep);
            $ten   = str_replace([$goc, '\\'], ['', '/'], $tep);

            foreach ($mau as $m) {
                preg_match_all($m, $nguon, $khop, PREG_SET_ORDER);

                foreach ($khop as $k) {
                    if ($k['chu'] === '' || $this->coDau($k['chu'])) {
                        continue;
                    }

                    $ra[(string) $k['chu']][] = $ten;
                }
            }
        }

        ksort($ra);

        return $ra;
    }

    /**
     * Bộ quét phải tìm ra thứ gì — một regex vỡ không được thành test xanh.
     *
     * Hai ngưỡng, cả hai đặt sát con số thật: 42 tệp trong tầm, và hơn 400
     * chuỗi hiển thị trích được. Một ngưỡng quá lỏng là một phép kiểm
     * chống-rỗng không chống gì — bài học của `TheEnumPhaiCoChuTest`, nơi
     * ngưỡng `>= 20` để một bộ quét thấy 59 trong 169 tệp đi qua.
     */
    public function test_bo_quet_khong_rong(): void
    {
        $this->assertGreaterThanOrEqual(
            40,
            count($this->tep()),
            'Chỉ thấy ' . count($this->tep()) . ' tệp trong khi tầm quét có ~42 — '
            . 'bộ liệt kê đã bỏ sót.',
        );

        $this->assertGreaterThanOrEqual(
            380,
            $this->demChuHienThi(),
            'Chỉ trích được ' . $this->demChuHienThi() . ' chuỗi hiển thị trong khi '
            . 'thực tế có hơn 400 — các regex đã vỡ, và phép so dưới đây đang '
            . 'canh ít hơn nó nói.',
        );
    }

    /** Tổng số chuỗi hiển thị trích được, có dấu hay không. */
    private function demChuHienThi(): int
    {
        $dem = 0;

        $mau = [
            '/->(?:' . implode('|', self::GOI) . ")\(\s*'(?:[^'\\\\]|\\\\.)*'/",
            '/\$(?:' . implode('|', self::THUOC_TINH) . ")\s*=\s*'(?:[^'\\\\]|\\\\.)*'/",
            '/\b(?:' . implode('|', self::MAKE_LA_CHU) . ")::make\(\s*'(?:[^'\\\\]|\\\\.)*'/",
        ];

        foreach ($this->tep() as $tep) {
            $nguon = file_get_contents($tep);

            foreach ($mau as $m) {
                $dem += preg_match_all($m, $nguon);
            }
        }

        return $dem;
    }

    public function test_khong_them_chu_tieng_anh_nao(): void
    {
        $tim = $this->chuKhongDau();

        $thua = [];

        foreach ($tim as $chu => $tepDs) {
            if (! in_array((string) $chu, self::CHO_PHEP, true)) {
                $ds = array_unique(array_map(fn ($t) => basename($t), $tepDs));
                $thua[] = "'{$chu}'  —  " . implode(', ', $ds);
            }
        }

        $this->assertSame(
            [],
            $thua,
            "Chuỗi hiển thị sau không có dấu tiếng Việt nào. Dịch nó, hoặc nếu nó\n"
            . "đúng là tên riêng / đơn vị / ví dụ thì khai vào `CHO_PHEP` kèm lý do:\n  "
            . implode("\n  ", $thua),
        );
    }

    /**
     * Chiều ngược: `CHO_PHEP` không được giữ dòng đã chết.
     *
     * Dịch một chuỗi mà quên gạch nó khỏi danh sách thì danh sách dần thành
     * một tập hợp vô nghĩa, và lần sau không ai dám tin nó nữa.
     */
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
