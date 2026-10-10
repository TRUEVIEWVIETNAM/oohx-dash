<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Mọi bảng chữ của mọi enum, canh bằng **một** phép kiểm tự tìm.
 *
 * ══ Vì sao tệp này tồn tại ══
 *
 * Cùng một lỗi đã xảy ra bốn lần, ở bốn cột khác nhau, và mỗi lần lại được
 * phát hiện bằng mắt:
 *
 *  1. `campaigns.status` — bảng quản trị sàn hiện `pending_approval` (PR #41).
 *  2. `creatives.status` — trang người mua hiện `approved`, `rejected` (#43).
 *  3. `organizations.type` — trang đầu hiện "Agency"; cột quản trị hiện
 *     `agency` (#47).
 *  4. `creatives.type` — hai trang đặt chỗ hiện `VAST_TAG`; cột quản trị hiện
 *     `vast_tag`.
 *
 * Bốn lần, bốn lần viết một tệp test riêng cho đúng một cột. Lần thứ năm sẽ
 * lại là một cột khác, và nó sẽ lại được phát hiện bằng mắt.
 *
 * ══ Tự tìm, không viết cứng danh sách ══
 *
 * Một danh sách `[model, cột]` viết tay sẽ mục: ai thêm bảng chữ mới mà không
 * đăng ký vào đó thì cột mới **không** được canh — mà đúng cột mới mới là cột
 * chưa ai đọc lại.
 *
 * Nên phạm vi lần theo quy ước đang có: mọi hằng tên `<CỘT>_LABELS` trên mọi
 * model trong `app/Models`. Bảy cột hiện khớp, và hằng thứ tám tự vào.
 *
 * ══ Phép kiểm "không có dấu gạch dưới" ══
 *
 * Không phải "nhãn phải khác mã": nhãn đúng của `html5` **là** `HTML5`, và
 * nhãn đúng của `video` là `Video`. Thứ không bao giờ là nhãn là một mã có dấu
 * gạch dưới — `VAST_TAG`, `pending_approval`, `in_review`. Đó là phép kiểm hẹp
 * mà đúng.
 *
 * Tệp này **không** thay các tệp canh riêng từng cột: chúng kiểm cả việc chữ
 * tới được chỗ người dùng đọc (render trang thật), còn tệp này chỉ kiểm bảng
 * chữ đầy đủ và sạch. Hai việc khác nhau.
 */
class NhanEnumMotNoiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mọi cặp `[lớp model, tên cột, bảng chữ]` tìm được.
     *
     * @return array<int, array{lop: string, bang: string, cot: string, nhan: array<string, string>}>
     */
    private function moiBangChu(): array
    {
        $ra = [];

        foreach (glob(app_path('Models/*.php')) as $tep) {
            $lop = 'App\\Models\\' . basename($tep, '.php');

            if (! class_exists($lop)) {
                continue;
            }

            $phanChieu = new ReflectionClass($lop);

            if ($phanChieu->isAbstract() || ! $phanChieu->isSubclassOf(Model::class)) {
                continue;
            }

            foreach ($phanChieu->getConstants() as $ten => $gia) {
                if (! preg_match('/^([A-Z0-9]+(?:_[A-Z0-9]+)*)_LABELS$/', $ten, $khop) || ! is_array($gia)) {
                    continue;
                }

                $ra[] = [
                    'lop'  => $lop,
                    'bang' => (new $lop)->getTable(),
                    'cot'  => strtolower($khop[1]),
                    'nhan' => $gia,
                ];
            }
        }

        return $ra;
    }

    /** Giá trị enum của một cột, hoặc `null` nếu cột không phải enum. */
    private function enumCuaCot(string $bang, string $cot): ?array
    {
        $hang = DB::selectOne(
            'SELECT COLUMN_TYPE AS kieu FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$bang, $cot],
        );

        if (! $hang || ! str_starts_with(strtolower((string) $hang->kieu), 'enum(')) {
            return null;
        }

        preg_match_all("/'([^']*)'/", $hang->kieu, $khop);

        return $khop[1];
    }

    /**
     * Bộ tự tìm phải tìm ra thứ gì — một regex vỡ không được thành test xanh.
     *
     * Sáu cột đang có bảng chữ: `campaigns.status`, `booking_lines.status`,
     * `creatives.status`, `creatives.type`, `owner_reviews.status`,
     * `public_reflections.status`.
     *
     * Đây là **ngưỡng sàn**, không phải bản kiểm kê: thêm bảng chữ mới thì số
     * thật tăng và `>=` vẫn đúng, nên không ai phải sửa con số này khi làm
     * việc khác. `organizations.type` sẽ là cái thứ bảy khi PR #47 vào.
     */
    public function test_bo_tu_tim_khong_rong(): void
    {
        $tim = $this->moiBangChu();

        $this->assertGreaterThanOrEqual(
            6,
            count($tim),
            'Chỉ tìm được ' . count($tim) . ' bảng chữ — bộ tự tìm đã vỡ, '
            . 'và mọi phép kiểm dưới đây đang canh ít hơn nó nói.',
        );

        foreach ($tim as $m) {
            $this->assertNotEmpty($m['nhan'], "{$m['lop']}: bảng chữ của `{$m['cot']}` rỗng");
        }
    }

    /**
     * Cột có bảng chữ mà KHÔNG phải enum — khai ra, kèm lý do.
     *
     * Phép kiểm ngay dưới từng đòi mọi cột có bảng chữ phải là enum, và thông
     * điệp của nó dặn: "nếu đó là có chủ ý thì nới phép kiểm này cùng lượt —
     * đừng để một bảng chữ không ai đối chiếu". Đây là lần nới đó.
     *
     * Hai cột dưới đây KHÔNG mất người canh: chúng chuyển sang
     * `test_bang_chu_cot_varchar_khop_hang_cua_model`, đối chiếu bảng chữ với
     * các hằng `<CỘT>_*` khai trên chính model. Đổi nguồn sự thật từ CSDL sang
     * model, không bỏ canh.
     */
    private const COT_KHONG_PHAI_ENUM = [
        // `varchar(32)`. Tên ba dịch vụ POI bên ngoài; thêm một nguồn là sửa mã,
        // không phải sửa enum.
        'poi_snapshots.source'  => 'App\Models\PoiSnapshot',

        // `screen_imports.status` đã gạch khỏi đây 10/10/2026: migration
        // `2026_10_10_000002` siết nó từ `varchar(255)` sang enum bảy giá trị,
        // nên nó quay về phép kiểm chuẩn ở trên.
        //
        // Và chính `test_khong_co_ngoai_le_chet` ngay dưới đã bắt tôi gạch:
        // migration vào trước, danh sách này chưa sửa, test đỏ với đúng một câu
        // "giờ ĐÃ là enum". Đó là chiều mà một chốt một-chiều sẽ bỏ qua.
    ];

    public function test_moi_cot_co_bang_chu_deu_la_enum_doc_duoc(): void
    {
        $khongDoc = [];

        foreach ($this->moiBangChu() as $m) {
            $khoa = "{$m['bang']}.{$m['cot']}";

            if (array_key_exists($khoa, self::COT_KHONG_PHAI_ENUM)) {
                continue;
            }

            if ($this->enumCuaCot($m['bang'], $m['cot']) === null) {
                $khongDoc[] = "{$khoa}  ({$m['lop']})";
            }
        }

        $this->assertSame(
            [],
            $khongDoc,
            "Những cột sau có bảng chữ nhưng không phải enum trong CSDL, nên không\n"
            . "kiểm được tính đầy đủ. Nếu đó là có chủ ý (ví dụ cột chuyển sang\n"
            . "varchar) thì khai vào `COT_KHONG_PHAI_ENUM` kèm lý do — đừng để một\n"
            . "bảng chữ không ai đối chiếu:\n  " . implode("\n  ", $khongDoc),
        );
    }

    /**
     * Ngoại lệ không được thành dòng chết.
     *
     * Một cột đã khai ở `COT_KHONG_PHAI_ENUM` mà sau này chuyển sang enum thì
     * phải gạch khỏi danh sách, không thì nó đứng đó che một cột mà phép kiểm
     * CSDL lẽ ra canh được.
     */
    public function test_khong_co_ngoai_le_chet(): void
    {
        $chet = [];

        foreach (self::COT_KHONG_PHAI_ENUM as $khoa => $lop) {
            [$bang, $cot] = explode('.', $khoa);

            if ($this->enumCuaCot($bang, $cot) !== null) {
                $chet[] = "{$khoa} — giờ ĐÃ là enum, gạch khỏi `COT_KHONG_PHAI_ENUM`";
            }
        }

        $this->assertSame([], $chet, implode("\n  ", $chet));
    }

    /**
     * Bảng chữ của cột `varchar` phải khớp các hằng `<CỘT>_*` trên model.
     *
     * Đây là chỗ canh thay cho CSDL. Không có nó thì hai bảng chữ kia hoàn toàn
     * tự do: thêm một bước vào máy trạng thái mà quên chữ thì không ai đỏ, và
     * `screen_imports.status` thì chỉ `varchar(255)` — CSDL nhận mọi chuỗi.
     *
     * Hai chiều, như mọi chốt khác: hằng nào cũng phải có chữ, chữ nào cũng
     * phải ứng với một hằng.
     */
    public function test_bang_chu_cot_varchar_khop_hang_cua_model(): void
    {
        $xau = [];

        foreach (self::COT_KHONG_PHAI_ENUM as $khoa => $lop) {
            [, $cot] = explode('.', $khoa);

            $nhan = constant($lop . '::' . strtoupper($cot) . '_LABELS');

            // Hằng `<CỘT>_X` có giá trị là chuỗi — bỏ chính bảng `_LABELS`.
            $hang = [];
            foreach ((new ReflectionClass($lop))->getConstants() as $ten => $gia) {
                if (preg_match('/^' . strtoupper($cot) . '_(?!LABELS$)[A-Z0-9_]+$/', $ten) && is_string($gia)) {
                    $hang[] = $gia;
                }
            }

            sort($hang);

            $this->assertNotEmpty(
                $hang,
                "{$lop}: không có hằng " . strtoupper($cot) . "_* nào — bảng chữ của "
                . "`{$cot}` không còn gì đối chiếu, và cột thì không phải enum.",
            );

            foreach ($hang as $ma) {
                if (! array_key_exists($ma, $nhan)) {
                    $xau[] = "{$lop}::{$cot} — hằng '{$ma}' không có chữ";
                }
            }

            foreach (array_keys($nhan) as $ma) {
                if (! in_array((string) $ma, $hang, true)) {
                    $xau[] = "{$lop}::{$cot} — chữ cho '{$ma}' nhưng không hằng nào mang giá trị đó";
                }
            }
        }

        $this->assertSame([], $xau, "Bảng chữ và hằng của model đã trôi khỏi nhau:\n  " . implode("\n  ", $xau));
    }

    /**
     * Hai chiều: mã nào trong enum cũng có chữ, và chữ nào cũng ứng với một mã.
     *
     * Chiều thứ hai không phải cho đẹp: một chữ cho mã không tồn tại là một
     * lựa chọn trong ô chọn mà CSDL sẽ từ chối khi lưu.
     */
    public function test_moi_ma_enum_deu_co_chu_va_nguoc_lai(): void
    {
        $thieu = [];
        $du    = [];

        foreach ($this->moiBangChu() as $m) {
            $enum = $this->enumCuaCot($m['bang'], $m['cot']);

            if ($enum === null) {
                continue; // Đã có phép kiểm riêng ở trên.
            }

            foreach ($enum as $ma) {
                if (! array_key_exists($ma, $m['nhan'])) {
                    $thieu[] = "{$m['bang']}.{$m['cot']} = '{$ma}' (không có chữ trong {$m['lop']})";
                }
            }

            foreach (array_keys($m['nhan']) as $ma) {
                if (! in_array($ma, $enum, true)) {
                    $du[] = "{$m['lop']}: có chữ cho '{$ma}' nhưng {$m['bang']}.{$m['cot']} không nhận giá trị đó";
                }
            }
        }

        $this->assertSame([], $thieu, "Mã enum không có chữ — nó sẽ hiện ra nguyên văn:\n  " . implode("\n  ", $thieu));
        $this->assertSame([], $du, "Chữ cho mã không tồn tại:\n  " . implode("\n  ", $du));
    }

    /**
     * Nhãn không bao giờ chứa dấu gạch dưới.
     *
     * Đây là phép kiểm bắt đúng lỗi `VAST_TAG`, và nó hẹp có chủ ý. Phép kiểm
     * "nhãn phải khác mã" thì **sai**: nhãn đúng của `html5` là `HTML5` và của
     * `video` là `Video`. Nhưng dấu gạch dưới là dấu của một mã CSDL — không
     * từ tiếng Việt nào có nó.
     */
    /**
     * Nhãn không được là chính mã đó **viết hoa chữ đầu**.
     *
     * ══ Khoảng trống phép kiểm này đóng ══
     *
     * Phép kiểm dấu gạch dưới ở dưới bắt `VAST_TAG`, nhưng **không** bắt
     * `'admin' => 'Admin'`. Tôi phát hiện bằng đột biến: đổi chữ của vai trò
     * `admin` về "Admin" thì cả bốn ca kia vẫn xanh — mà đó đúng là hình dạng
     * của lỗi vừa sửa ở `OrganizationUser::ROLES` ("Admin", "Planner",
     * "Viewer") và `Organization::TYPE_LABELS` (`ucfirst($org->type)`).
     *
     * ══ Một ngoại lệ, và chỉ một ══
     *
     * `Creative::TYPE_LABELS['video'] === 'Video'`, và đó là **đúng**: "Video"
     * là từ tiếng Việt đã dùng, không phải một mã chưa dịch. Nên nó được khai
     * ra ở đây thay vì làm phép kiểm lỏng đi.
     *
     * Ba nhãn trông giống mã mà **không** bị bắt, vì `ucfirst` cho ra chuỗi
     * khác: `HTML5` (`Html5`), `VNPAY` (`Vnpay`), `MoMo` (`Momo`). Cả ba là
     * tên riêng viết theo cách chủ sở hữu viết — hướng sai này an toàn.
     */
    public function test_nhan_khong_phai_ma_viet_hoa_chu_dau(): void
    {
        $choPhep = [
            'App\Models\Creative::type.video' => 'Video',

            // Tên công ty, viết đúng cách họ viết. `ucfirst('foursquare')` tình
            // cờ ra đúng chữ đó — hướng sai của phép kiểm, không phải một mã
            // chưa dịch. Hai nguồn kia không bị bắt vì `ucfirst` cho ra chuỗi
            // khác: `OpenStreetMap` (`Osm`), `Google Places` (`Google_places`).
            'App\Models\PoiSnapshot::source.foursquare' => 'Foursquare',
        ];

        $xau = [];

        foreach ($this->moiBangChu() as $m) {
            foreach ($m['nhan'] as $ma => $chu) {
                $khoa = "{$m['lop']}::{$m['cot']}.{$ma}";

                if (($choPhep[$khoa] ?? null) === $chu) {
                    continue;
                }

                if (is_string($chu) && $chu === ucfirst($ma)) {
                    $xau[] = "{$khoa} = '{$chu}' — vẫn là mã viết hoa chữ đầu";
                }
            }
        }

        $this->assertSame(
            [],
            $xau,
            "Nhãn sau chưa được dịch:\n  " . implode("\n  ", $xau),
        );
    }

    public function test_khong_nhan_nao_la_mot_ma_csdl(): void
    {
        $xau = [];

        foreach ($this->moiBangChu() as $m) {
            foreach ($m['nhan'] as $ma => $chu) {
                if (! is_string($chu) || str_contains($chu, '_')) {
                    $xau[] = "{$m['lop']}::{$m['cot']}['{$ma}'] = " . var_export($chu, true);
                }
            }
        }

        $this->assertSame(
            [],
            $xau,
            "Nhãn sau còn là một mã CSDL, không phải chữ cho người đọc:\n  " . implode("\n  ", $xau),
        );
    }
}
