<?php

namespace Tests\Feature\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Thẻ `->badge()` trên một cột enum **phải** có chữ.
 *
 * ══ Lỗi tệp này canh ══
 *
 * Filament in thẳng giá trị cột khi không ai bảo nó in gì khác. Nên một cột
 * khai `->badge()` + `->color(...)` mà thiếu `->formatStateUsing(...)` thì ra
 * một viên thuốc có màu chứa mã CSDL: `pending_approval`, `agency`,
 * `vast_tag`, `active`.
 *
 * Lỗi đó đã sửa **năm lần** ở năm cột khác nhau — `campaigns.status` (#41),
 * `creatives.status` (#43), `organizations.type` (#47), `creatives.type` (#48),
 * `organizations.status` (PR này) — và cả năm lần đều được phát hiện bằng mắt.
 *
 * Khảo sát toàn bộ `app/Filament` cho thấy còn **nhiều chỗ nữa**. Chúng không
 * được sửa trong một lần: phần lớn là trạng thái vận hành nội bộ (lần chạy
 * collector, job tính lại, nhập tệp màn hình) mà chữ tiếng Việt cho chúng là
 * một quyết định nghiệp vụ, không phải một lần dịch.
 *
 * ══ Nên tệp này là một CHỐT, không phải một phép kiểm "sạch" ══
 *
 * Nó ghi lại đúng những chỗ đang thiếu chữ và đòi danh sách thật **khớp từng
 * con số** với danh sách ghi lại. Hệ quả hai chiều:
 *
 *  - thêm một `->badge()` trên cột enum mà quên chữ → danh sách thật dài hơn →
 *    **đỏ**, và lỗi bị bắt lúc viết chứ không phải lúc có người nhìn thấy;
 *  - sửa một chỗ trong danh sách → danh sách thật ngắn hơn → **đỏ**, nhắc gạch
 *    nó khỏi đây. Một chốt chỉ siết một chiều thì sẽ mục.
 *
 * ══ Chỗ bộ quét này không chính xác, nói ra ══
 *
 * Nó khớp theo **tên cột**, không theo bảng của từng resource: lần ra bảng từ
 * một Page của Filament (`ViewCampaign`, `ViewBookingInbox`) phải đi qua
 * resource cha. Nên một `->badge()` trên cột **không** phải enum mà tình cờ
 * tên `status` cũng bị tính. Hướng sai đó chỉ làm danh sách dài hơn thực tế —
 * và vì phép so là so với danh sách ghi lại, nó không gây báo oan.
 *
 * Nó cũng cắt nguồn theo mốc `::make('x')` chứ không phân tích cú pháp PHP,
 * nên một cách viết lạ (ví dụ tách chuỗi gọi ra biến) sẽ nằm ngoài tầm.
 */
class TheEnumPhaiCoChuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chỗ đang thiếu chữ, tính tới 09/10/2026: `đường dẫn::cột => số chỗ`.
     *
     * Mỗi dòng là một món nợ đã biết, không phải một ngoại lệ được chúc phúc.
     * Sửa được thì sửa rồi gạch khỏi đây.
     */
    private const DANG_THIEU = [
        // Trống từ 10/10/2026. Lịch sử, để không ai tưởng danh sách này chưa
        // từng dùng:
        //
        //  - bốn dòng `ProductResource` (publisher + admin, `type` + `status`)
        //    gạch cùng lượt dịch panel publisher — `Product::TYPE_LABELS` và
        //    `STATUS_LABELS` ra đời ở đó;
        //  - năm dòng còn lại (`OwnerResource` ×2, `ScreenImportResource` ×2,
        //    `PoiSnapshotResource`) gạch ở lượt "nhãn vận hành".
        //
        // Hai dòng của OOHX Data Engine KHÔNG gạch, và cũng không còn là nợ —
        // xem `KHONG_DICH` ngay dưới.
    ];

    /**
     * Cố ý GIỮ mã thô, kèm lý do — không phải nợ.
     *
     * ══ Vì sao hai chỗ này khác bảy chỗ kia ══
     *
     * Chú thích cũ gộp cả bảy vào một nhóm "trạng thái vận hành nội bộ mà mã
     * thô có thể đúng là thứ người vận hành cần đối chiếu với log". Khảo sát
     * ngày 10/10/2026 cho thấy lý do đó chỉ đúng với **hai** chỗ:
     *
     *  - `CollectorRun` và `RecomputeJob` sống trên connection `oohx_control`,
     *    tức một CSDL KHÁC, do một worker Python sở hữu. Bảng của chúng không
     *    có migration nào trong repo này — `information_schema` của CSDL chính
     *    không biết chúng tồn tại.
     *  - Người đọc hai trang đó là người vận hành sàn đang đối chiếu với log
     *    của worker Python, nơi trạng thái in ra là `pending` / `running` /
     *    `done` / `failed` / `cancelled`. Dịch ở một phía làm việc đối chiếu khó
     *    hơn, không dễ hơn.
     *
     * Năm chỗ còn lại thì KHÔNG như vậy, và đó là lý do chúng đã được sửa:
     * `owners.status` / `owners.type` là enum của chính dự án; còn
     * `screen_imports.status` và `poi_snapshots.source` thì người đọc là **media
     * owner đang tự nhập kho của mình**, không phải người vận hành sàn.
     *
     * Đổi ý thì đổi — nhưng đổi ở đây, với một lý do viết ra, chứ không để nó
     * nằm im trong danh sách nợ như một việc chưa làm.
     *
     * @var array<string, int>
     */
    private const KHONG_DICH = [
        'app/Filament/Resources/OohxCollectorRunResource.php::status' => 2,
        'app/Filament/Resources/OohxRecomputeJobResource.php::status' => 2,
    ];

    /** @return array<int, string> tên mọi cột enum trong CSDL */
    private function tenCotEnum(): array
    {
        return array_values(array_unique(array_map(
            fn ($h) => $h->c,
            DB::select(
                "SELECT DISTINCT COLUMN_NAME AS c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'enum'",
            ),
        )));
    }

    /** @return array<int, string> mọi tệp PHP dưới `app/Filament` */
    private function tepFilament(): array
    {
        $ra = [];

        // `File::allFiles()` (Symfony Finder), KHÔNG `RecursiveIteratorIterator`.
        //
        // Bản đầu dùng `RecursiveIteratorIterator` và nó chỉ trả **59** trong
        // số **169** tệp — hai phần ba cây thư mục rụng im lặng, nên bộ quét
        // bỏ qua `CampaignResource`, `OrganizationResource`, `ProductResource`
        // và phần lớn phần còn lại. Ngưỡng chống-rỗng lúc đó đặt ở `>= 20` nên
        // nó **không bắt được**: 59 vẫn qua.
        //
        // Đó là lý do ngưỡng dưới đây đặt sát con số thật, không đặt cho an
        // toàn: một ngưỡng quá lỏng là một phép kiểm chống-rỗng không chống gì.
        foreach (File::allFiles(app_path('Filament')) as $f) {
            if ($f->getExtension() === 'php') {
                $ra[] = $f->getPathname();
            }
        }

        sort($ra);

        return $ra;
    }

    /**
     * Thẻ enum thiếu chữ, tìm được trong mã nguồn.
     *
     * @return array<string, int> `đường dẫn::cột => số chỗ`
     */
    private function timThieuChu(): array
    {
        $enum = $this->tenCotEnum();
        $goc  = base_path() . DIRECTORY_SEPARATOR;
        $ra   = [];

        foreach ($this->tepFilament() as $tep) {
            $nguon = file_get_contents($tep);

            preg_match_all(
                "/(?:TextColumn|TextEntry)::make\(\s*'([^']+)'\s*\)/",
                $nguon,
                $moc,
                PREG_OFFSET_CAPTURE,
            );

            $n = count($moc[0]);

            for ($i = 0; $i < $n; $i++) {
                $dau  = $moc[0][$i][1];
                $cuoi = $i + 1 < $n ? $moc[0][$i + 1][1] : strlen($nguon);
                $than = substr($nguon, $dau, $cuoi - $dau);

                if (! str_contains($than, '->badge()')) {
                    continue;
                }

                // `user.role` → `role`: thẻ vẽ cột của quan hệ, nhưng cột vẫn
                // là cột đó.
                $cot = str_contains($moc[1][$i][0], '.')
                    ? substr($moc[1][$i][0], strrpos($moc[1][$i][0], '.') + 1)
                    : $moc[1][$i][0];

                if (! in_array($cot, $enum, true)) {
                    continue;
                }

                if (preg_match('/->(?:formatStateUsing|state|enum)\(/', $than)) {
                    continue;
                }

                $khoa = str_replace([$goc, '\\'], ['', '/'], $tep) . '::' . $cot;

                $ra[$khoa] = ($ra[$khoa] ?? 0) + 1;
            }
        }

        ksort($ra);

        return $ra;
    }

    /**
     * Bộ quét phải tìm ra thứ gì — một regex vỡ không được thành test xanh.
     *
     * Nếu bộ quét trả rỗng thì phép so bên dưới sẽ đỏ, nhưng nó sẽ đỏ với một
     * thông điệp dài về danh sách; ca này đỏ với thông điệp đúng nguyên nhân.
     */
    public function test_bo_quet_khong_rong(): void
    {
        $this->assertGreaterThanOrEqual(
            10,
            count($this->tenCotEnum()),
            'Không đọc được cột enum nào từ information_schema — bộ đọc đã vỡ.',
        );

        $this->assertGreaterThanOrEqual(
            160,
            count($this->tepFilament()),
            'Chỉ thấy ' . count($this->tepFilament()) . ' tệp Filament trong khi cây '
            . 'thư mục có ~169 — bộ quét đang bỏ sót, và mọi phép kiểm dưới đây '
            . 'đang canh ít hơn nó nói.',
        );
    }

    public function test_khong_them_the_enum_nao_thieu_chu(): void
    {
        $thuc = $this->timThieuChu();

        // Hai danh sách, một phép so: nợ chưa trả CỘNG chỗ cố ý giữ mã thô.
        // Tách làm hai là để đọc được ý định, không phải để lỏng phép kiểm —
        // tổng vẫn phải khớp từng con số với thực tế.
        $ghi = self::DANG_THIEU + self::KHONG_DICH;

        ksort($ghi);

        $them = array_diff_key($thuc, $ghi);
        $het  = array_diff_key($ghi, $thuc);
        $lech = [];

        foreach (array_intersect_key($thuc, $ghi) as $k => $v) {
            if ($v !== $ghi[$k]) {
                $lech[] = "{$k}: ghi {$ghi[$k]}, thực tế {$v}";
            }
        }

        $this->assertSame([], array_keys($them), implode("\n", [
            'Có thẻ `->badge()` MỚI trên cột enum mà không có chữ, nên Filament sẽ',
            'in thẳng mã CSDL ra giao diện. Thêm `->formatStateUsing(...)` lấy chữ',
            'từ bảng nhãn của model:',
            '  ' . implode("\n  ", array_keys($them)),
        ]));

        $this->assertSame([], array_keys($het), implode("\n", [
            'Những chỗ sau đã có chữ (tốt) nhưng vẫn còn trong danh sách nợ của',
            'tệp này. Gạch chúng khỏi `DANG_THIEU` — một chốt chỉ siết một chiều',
            'thì sẽ mục:',
            '  ' . implode("\n  ", array_keys($het)),
        ]));

        $this->assertSame([], $lech, implode("\n", [
            'Số chỗ thiếu chữ đã đổi ở cùng một tệp+cột. Nếu vừa sửa thì hạ con',
            'số trong `DANG_THIEU`; nếu vừa thêm thì thêm chữ:',
            '  ' . implode("\n  ", $lech),
        ]));
    }
}
