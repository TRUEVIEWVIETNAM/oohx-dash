<?php

namespace App\Console\Commands;

use App\Models\Screen;
use App\Services\AnhDanXuatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Sinh bản dẫn xuất `w480`/`w1280` cho ảnh **đã có trong kho** — lát 4 của
 * `docs/PLAN-ANH-VI-TRI.md`.
 *
 * ══ Lệnh này làm ba việc mà observer không làm được ══
 *
 *  1. **Ảnh có trước lát này.** `ScreenSpecObserver` chỉ bắt lượt ghi; ảnh đã
 *     nằm trong cột từ trước thì không có lượt ghi nào nữa.
 *  2. **Ảnh thay thế.** Hôm nay 104/104 vị trí hiện ra bằng ảnh bìa chủ sở
 *     hữu, và ảnh bìa thuộc bản ghi `owners`, không thuộc `screen_specs`.
 *     Observer của spec không thấy chúng; lệnh này đi theo đúng chuỗi dự
 *     phòng mà người mua thấy, nên nó thấy.
 *  3. **Lượt chữa.** Observer ghi `warning` khi một ảnh không sinh được rồi
 *     đi tiếp. Lệnh này là chỗ đếm lại còn bao nhiêu ảnh như vậy, và thử lại.
 *
 * ══ `--soat` ══
 *
 * Đếm mà không ghi gì. Hai lý do: biết trước một lượt chạy thật sẽ sinh bao
 * nhiêu tệp, và — quan trọng hơn — **bảng GD ở đầu đầu ra**. Không có đường
 * nào khác để biết PHP của production có GD và có WebP: `php -i` phải gõ qua
 * ssh, và ba lượt ssh gần nhất không đưa được đầu ra về chat. Chạy
 * `--soat` thì lệnh tự nói, và đầu ra của nó an toàn khi dán.
 *
 * ══ Vì sao gộp đường dẫn trùng ══
 *
 * 83 màn hình của Kim Ngân ADV dùng **cùng một** ảnh bìa. Không gộp thì lệnh
 * mở cùng tệp đó 83 lần, thu nhỏ 166 lần, và ghi lên cùng hai đường dẫn 83
 * lần. Gộp xong thì con số "đã sinh" cũng là con số tệp thật trên đĩa.
 */
class SinhAnhDanXuat extends Command
{
    protected $signature = 'oohx:sinh-anh-dan-xuat
                            {--soat : Chỉ đếm, không ghi tệp nào}
                            {--lai : Sinh lại cả bản đã có (dùng khi đổi bề rộng hay chất lượng nén)}
                            {--gioi-han= : Chỉ xử lý N đường dẫn đầu tiên — để thử trên production trước khi chạy cả kho}';

    protected $description = 'Sinh bản dẫn xuất w480/w1280 cho ảnh kho màn hình (chạy lại được, bỏ qua ảnh đã có)';

    /** Số màn hình nạp mỗi lượt — cùng cỡ lô với `SoatAnhKhoService`. */
    private const CO_LO = 500;

    public function handle(AnhDanXuatService $anh): int
    {
        $soat     = (bool) $this->option('soat');
        $lai      = (bool) $this->option('lai');
        $gioiHan  = $this->option('gioi-han') !== null ? max(1, (int) $this->option('gioi-han')) : null;

        $this->newLine();
        $this->info($soat
            ? 'SOÁT — không ghi tệp nào.'
            : 'Sinh bản dẫn xuất cho ảnh kho màn hình công khai.');

        $this->bangGd();

        if (! $soat && ! in_array(true, AnhDanXuatService::gdLamGi(), true)) {
            $this->error('PHP ở đây không có GD cho định dạng nào trong ba định dạng nhận. '
                . 'Không sinh được bản dẫn xuất nào — cài extension gd rồi chạy lại.');

            return self::FAILURE;
        }

        $dia    = Storage::disk('public');
        $daGap  = [];
        $dem    = [];
        $viDu   = [];
        $soTep  = 0;

        foreach (AnhDanXuatService::NHAN_KQ as $ma => $_) {
            $dem[$ma] = 0;
        }

        $this->newLine();

        Screen::publiclyVisible()
            // Cùng bộ quan hệ với `SoatAnhKhoService::soat()`: chuỗi dự phòng
            // chỉ chạm bậc sau khi bậc trước trống, và trên kho sạch ảnh nó
            // chạm **cả bốn**. Thiếu một cái là 10.000 truy vấn thêm.
            ->with([
                'spec:id,screen_id,photos,photo_url',
                'site:id,name,banner,network_id',
                'site.network:id,banner',
                'owner:id,name,cover_url',
                'inventory:id,screen_id,vn_category_id',
                'inventory.vnCategory:id,thumb',
            ])
            ->chunkById(self::CO_LO, function ($lo) use (
                $anh, $dia, $soat, $lai, $gioiHan, &$daGap, &$dem, &$viDu, &$soTep
            ) {
                foreach ($lo as $man) {
                    foreach ($this->duongCuaManHinh($man) as $duong) {
                        if (isset($daGap[$duong])) {
                            continue;
                        }

                        if ($gioiHan !== null && count($daGap) >= $gioiHan) {
                            return false; // dừng cả vòng `chunkById`
                        }

                        $daGap[$duong] = true;

                        $kq = $soat
                            ? $this->doThu($duong, $dia)
                            : $anh->sinhCho($duong, $lai, $dia);

                        $dem[$kq['ket_qua']] = ($dem[$kq['ket_qua']] ?? 0) + 1;
                        $soTep += count($kq['da_sinh']);

                        if ($kq['ly_do'] !== null && count($viDu) < 10) {
                            $viDu[] = [$kq['ket_qua'], $duong, $kq['ly_do']];
                        }
                    }
                }

                return true;
            });

        $this->bangKetQua($dem, count($daGap), $soTep, $soat);

        if ($viDu !== []) {
            $this->newLine();
            $this->line('<fg=yellow>Ảnh không sinh được, kèm lý do:</>');
            $this->table(['Kết quả', 'Đường dẫn', 'Lý do'], $viDu);
        }

        return self::SUCCESS;
    }

    /**
     * Đường dẫn ảnh mà **người mua thấy** của một màn hình.
     *
     * Ảnh của chính màn hình thì lấy **cả** dãy: thư viện ảnh của trang chi
     * tiết hiện hết, nên cả dãy cần bản dẫn xuất. Không có ảnh nào thì lấy
     * đúng bậc thay thế mà chuỗi dự phòng dừng ở — một bậc, vì giao diện cũng
     * chỉ hiện một.
     *
     * @return list<string>
     */
    private function duongCuaManHinh(Screen $man): array
    {
        $cuaManHinh = $man->spec?->photoPaths() ?? [];

        if ($cuaManHinh !== []) {
            return $cuaManHinh;
        }

        $thay = $man->displayPhotoWithSource()['path'];

        return $thay === null ? [] : [$thay];
    }

    /**
     * `--soat`: trả lời "lượt chạy thật sẽ làm gì với ảnh này" mà **không mở
     * tệp ra**.
     *
     * Không gọi `sinhCho()` rồi bỏ kết quả: hàm đó giải nén ảnh, và một lượt
     * soát trên 10.000 ảnh không được tốn đúng số bộ nhớ mà nó sinh ra để
     * đếm. Nên nó chỉ `stat` — và vì thế nó **không** phân biệt được ảnh quá
     * lớn hay ảnh hỏng; cả hai ra `da_sinh` ở bảng soát rồi hiện đúng ở lượt
     * chạy thật. Cột soát là một ước lượng trên, và nói ra ở đây để không ai
     * đọc nó như một lời hứa.
     *
     * @return array{ket_qua: string, da_sinh: list<int>, ly_do: ?string, be_rong_goc: ?int}
     */
    private function doThu(string $duong, $dia): array
    {
        $thieu = [];

        foreach (AnhDanXuatService::BE_RONG as $beRong) {
            $d = AnhDanXuatService::duongDanXuat($duong, $beRong);

            if ($d === null) {
                return [
                    'ket_qua' => str_starts_with($duong, 'http://') || str_starts_with($duong, 'https://')
                        ? AnhDanXuatService::KQ_URL_NGOAI
                        : AnhDanXuatService::KQ_DUONG_SAI,
                    'da_sinh' => [], 'ly_do' => null, 'be_rong_goc' => null,
                ];
            }

            if (! $dia->exists($d)) {
                $thieu[] = $beRong;
            }
        }

        if ($thieu === []) {
            return ['ket_qua' => AnhDanXuatService::KQ_DA_CO, 'da_sinh' => [], 'ly_do' => null, 'be_rong_goc' => null];
        }

        if (! $dia->exists($duong)) {
            return ['ket_qua' => AnhDanXuatService::KQ_THIEU_TEP, 'da_sinh' => [], 'ly_do' => null, 'be_rong_goc' => null];
        }

        return ['ket_qua' => AnhDanXuatService::KQ_DA_SINH, 'da_sinh' => $thieu, 'ly_do' => null, 'be_rong_goc' => null];
    }

    private function bangGd(): void
    {
        $this->newLine();
        $this->line('<fg=gray>GD của PHP ở máy này:</>');

        $this->table(
            ['Định dạng', 'GD làm được?'],
            collect(AnhDanXuatService::gdLamGi())
                ->map(fn (bool $co, string $ten) => [$ten, $co ? 'có' : '<fg=red>KHÔNG</>'])
                ->values()->all(),
        );

        $this->line(sprintf(
            '<fg=gray>memory_limit = %s · trần ảnh = %s điểm · bề rộng = %s</>',
            (string) ini_get('memory_limit'),
            number_format(AnhDanXuatService::TOI_DA_PIXEL),
            implode(', ', array_map(fn (int $w) => 'w' . $w, AnhDanXuatService::BE_RONG)),
        ));
    }

    /** @param array<string, int> $dem */
    private function bangKetQua(array $dem, int $soDuong, int $soTep, bool $soat): void
    {
        $hang = [];

        foreach (AnhDanXuatService::NHAN_KQ as $ma => $nhan) {
            if (($dem[$ma] ?? 0) === 0) {
                continue;
            }

            $hang[] = [$nhan, (string) $dem[$ma]];
        }

        $this->table(['Kết quả', 'Số ảnh'], $hang);

        $this->line(sprintf(
            '%d đường dẫn ảnh phân biệt · %d tệp %s',
            $soDuong,
            $soTep,
            $soat ? 'sẽ được sinh' : 'đã sinh',
        ));

        $this->line(sprintf(
            '<fg=gray>Bản dẫn xuất nằm ở %s — NGOÀI %s, nên con số của oohx:soat-anh-kho không đổi.</>',
            AnhDanXuatService::THU_MUC,
            \App\Services\SoatAnhKhoService::THU_MUC_ANH,
        ));
    }
}
