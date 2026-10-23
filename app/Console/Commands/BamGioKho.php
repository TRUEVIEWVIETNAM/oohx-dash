<?php

namespace App\Console\Commands;

use App\Services\FrontpageService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bấm giờ sáu đường của `/explore` và in `EXPLAIN` — lát 0 của phần bổ sung
 * "quy mô 10.000 vị trí" trong `docs/PLAN-GOP-EXPLORE-BAN-DO.md`.
 *
 * Chạy sau `oohx:gia-lap-kho`. Số đo ghi vào chính tệp plan đó.
 *
 * ══ Vì sao đo ở tầng service, không qua HTTP ══
 *
 * Đo qua HTTP ở máy sẽ lẫn thời gian khởi động framework, serialize JSON và
 * một bind mount Windows chậm — ba thứ không có trên production. Cái cần biết
 * là **truy vấn** mất bao lâu và MySQL chọn kế hoạch nào, nên đo đúng lời gọi
 * service và đếm số truy vấn kèm theo.
 *
 * Số truy vấn quan trọng ngang thời gian: `getMapPins` nạp kèm 8 quan hệ, và
 * một đường 50ms chạy 9 truy vấn sẽ xấu đi theo cách khác với một đường 50ms
 * chạy 1 truy vấn khi độ trễ mạng tới CSDL không còn bằng 0 như ở đây.
 */
class BamGioKho extends Command
{
    protected $signature = 'oohx:bam-gio-kho
                            {--lan=3 : Số lần chạy mỗi đường, lấy số nhỏ nhất}
                            {--chi-tiet= : Mổ một đường ra từng câu truy vấn (hiện có: filters)}';

    protected $description = 'Bấm giờ sáu đường của /explore ở quy mô hiện có trong CSDL';

    /** Khung nhìn cả nước — đúng khung `/explore?view=map` mở ra khi chưa có vị trí. */
    private const KHUNG_CA_NUOC = ['south' => 8.0, 'north' => 23.5, 'west' => 102.0, 'east' => 110.0];

    /** Khung quanh một quận nội thành Hà Nội, ~5km mỗi chiều. */
    private const KHUNG_MOT_QUAN = ['south' => 21.00, 'north' => 21.06, 'west' => 105.80, 'east' => 105.87];

    public function handle(FrontpageService $kho): int
    {
        if ($this->option('chi-tiet') === 'filters') {
            return $this->moFilters($kho);
        }

        $lan = max(1, (int) $this->option('lan'));

        $this->line('Quy mô: ' . number_format(DB::table('screens')->count()) . ' màn hình · '
            . number_format(DB::table('sites')->count()) . ' site');
        $this->newLine();

        $soTrangCuoi = (int) ceil(DB::table('screens')->count() / 24);

        $duong = [
            'map · khung cả nước' => fn () => [
                $kho->getMapPins($this->req([]), self::KHUNG_CA_NUOC, 500)->count(),
                $kho->countMapPins($this->req([]), self::KHUNG_CA_NUOC),
            ],

            'map · khung một quận' => fn () => [
                $kho->getMapPins($this->req([]), self::KHUNG_MOT_QUAN, 500)->count(),
                $kho->countMapPins($this->req([]), self::KHUNG_MOT_QUAN),
            ],

            // Đường của lát A: một `GROUP BY` thay cho `count` + 500 pin + 8
            // lượt nạp quan hệ.
            'map · cụm, khung cả nước' => fn () => $kho->getMapClusters(
                $this->req([]),
                self::KHUNG_CA_NUOC,
                FrontpageService::coOLuoi(self::KHUNG_CA_NUOC)
            )->count(),

            'map · cụm, khung một quận' => fn () => $kho->getMapClusters(
                $this->req([]),
                self::KHUNG_MOT_QUAN,
                FrontpageService::coOLuoi(self::KHUNG_MOT_QUAN)
            )->count(),

            'sort=distance · trang 1' => fn () => $kho->getScreensPaginated(
                $this->req(['sort' => 'distance', 'near_lat' => '21.0278', 'near_lng' => '105.8342']),
                24
            )->count(),

            'sort=distance · trang cuối' => fn () => $kho->getScreensPaginated(
                $this->req([
                    'sort'     => 'distance',
                    'near_lat' => '21.0278',
                    'near_lng' => '105.8342',
                    'page'     => (string) $soTrangCuoi,
                ]),
                24
            )->count(),

            'q=… (ba phép khớp chuỗi con)' => fn () => $kho->getScreensPaginated(
                $this->req(['q' => 'Giả']),
                24
            )->count(),

            'filters · cache nguội' => function () use ($kho) {
                Cache::forget('fp:filters');

                return count($kho->getFilterAggregates());
            },
        ];

        $this->line(sprintf('%-32s %10s %8s', 'Đường', 'nhanh nhất', 'truy vấn'));
        $this->line(str_repeat('─', 52));

        // Đăng ký **một** listener cho cả lượt chạy và đặt lại biến đếm ở mỗi
        // vòng. Bản đầu gọi `DB::listen` trong vòng lặp, nên listener cộng dồn
        // và số truy vấn in ra là tổng của mọi vòng trước — 21/28/63/70/105/126
        // tăng đều một cách đáng ngờ, đó là cách tôi phát hiện.
        $dem = 0;
        DB::listen(function () use (&$dem) {
            $dem++;
        });

        foreach ($duong as $ten => $goi) {
            $nhanhNhat = PHP_FLOAT_MAX;
            $soTruyVan = 0;

            for ($i = 0; $i < $lan; $i++) {
                $dem = 0;

                $batDau = microtime(true);
                $goi();
                $hetGio = (microtime(true) - $batDau) * 1000;

                // Lượt đầu trả giá cho cache truy vấn nguội của MySQL; lấy số
                // nhỏ nhất là lấy trạng thái nóng, tức trạng thái production.
                if ($hetGio < $nhanhNhat) {
                    $nhanhNhat = $hetGio;
                    $soTruyVan = $dem;
                }
            }

            $this->line(sprintf(
                '%-32s %7.0f ms %8d%s',
                $ten,
                $nhanhNhat,
                $soTruyVan,
                $nhanhNhat > 300 ? '  ← quá 300ms' : ''
            ));
        }

        $this->newLine();
        $this->inPhanBiCat($kho);
        $this->newLine();
        $this->inKeHoach($kho);

        return self::SUCCESS;
    }

    /**
     * Mổ `getFilterAggregates()` ra từng câu truy vấn.
     *
     * Tổng thời gian của một đường không nói sửa chỗ nào. `filters` ở 50.000
     * bản ghi mất 717ms — là đường dốc nhất bảng (7,2×) — nhưng nó gọi tới
     * tám nhóm truy vấn con, và tối ưu nhóm sai là cách tốn công nhất.
     */
    private function moFilters(FrontpageService $kho): int
    {
        $lan = max(1, (int) $this->option('lan'));

        /*
         * **Hai chế độ nguội khác nhau hẳn**, và lẫn chúng là cách tự lừa mình.
         *
         * `getFilterAggregates()` cache ở khoá `fp:filters`, nhưng ba hàm nó
         * gọi (`getVenueTypesWithCounts`, `getTopCities`,
         * `getCategoriesWithNetworks`) có cache **riêng** với thời hạn riêng.
         * Nên:
         *
         *  - *tất cả nguội* — sau một lần xoá cache hay đổi máy chủ cache. Đây
         *    là con số tệ nhất, và nó chỉ xảy ra một lần cho mỗi lần xoá.
         *  - *chỉ `fp:filters` nguội* — ca **thường xuyên**: cứ 1800 giây khoá
         *    đó hết hạn trong khi cache con vẫn còn. Đây là con số mà một
         *    người dùng thật trả mỗi 30 phút.
         *
         * Bản đầu của hàm này chỉ `Cache::forget('fp:filters')` và gọi một
         * lần, rồi bảng đo chính lại lặp 5 lượt lấy lượt nhanh nhất — nên
         * dòng "filters cache nguội" của nó đo lượt thứ năm với cache con đã
         * nóng, tức **không nguội gì cả**. Số truy vấn 6 thay vì 11 là chỗ
         * phát hiện ra.
         */
        $cheDo = [
            'tất cả nguội'        => fn () => Cache::flush(),
            'chỉ fp:filters nguội' => fn () => Cache::forget(FrontpageService::KHOA_BO_LOC),
        ];

        /*
         * Một listener cho cả lượt chạy, và đặt lại `$cau` ở mỗi vòng.
         *
         * Đăng ký `DB::listen` *trong* vòng lặp làm listener cộng dồn, nên số
         * truy vấn in ra là tổng của mọi vòng trước. Lỗi này tôi mắc **ba lần**
         * trong cùng một phiên, và dấu hiệu nhận ra nó luôn giống nhau: tỉ lệ
         * "trong SQL" vượt 100% (lần này 797%).
         *
         * Closure giữ `$cau` **theo tham chiếu**, nên gán `$cau = []` ở ngoài
         * là nó thấy mảng mới.
         */
        $cau = [];
        DB::listen(function ($su) use (&$cau) {
            $cau[] = ['sql' => $su->sql, 'ms' => $su->time];
        });

        foreach ($cheDo as $ten => $donCache) {
            /** @var list<array{sql:string,ms:float}> $nhanhNhat */
            $nhanhNhat = [];
            $msNhanhNhat = PHP_FLOAT_MAX;

            for ($i = 0; $i < $lan; $i++) {
                // Nóng lại mọi cache con trước khi đo chế độ thứ hai, nếu
                // không thì lượt đầu của nó vẫn là "tất cả nguội".
                $kho->getFilterAggregates();

                $donCache();

                $cau = [];
                $batDau = microtime(true);

                $kho->getFilterAggregates();

                $ms = (microtime(true) - $batDau) * 1000;

                if ($ms < $msNhanhNhat) {
                    $msNhanhNhat = $ms;
                    $nhanhNhat = $cau;
                }
            }

            $tongSql = array_sum(array_column($nhanhNhat, 'ms'));

            $this->info(sprintf(
                '%s: %.0f ms · %d truy vấn · %.0f ms trong SQL (%.0f%%)',
                $ten,
                $msNhanhNhat,
                count($nhanhNhat),
                $tongSql,
                $msNhanhNhat > 0 ? $tongSql / $msNhanhNhat * 100 : 0
            ));

            usort($nhanhNhat, fn ($a, $b) => $b['ms'] <=> $a['ms']);

            foreach (array_slice($nhanhNhat, 0, 8) as $c) {
                // Nén khoảng trắng và cắt ngắn: câu của `filters` dài vài trăm
                // ký tự, và cái cần nhận ra là *câu nào*, không phải đọc lại
                // cả câu.
                $gon = preg_replace('/\s+/', ' ', $c['sql']);

                $this->line(sprintf('  %7.1f ms  %s', $c['ms'], mb_strimwidth($gon, 0, 84, '…')));
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * Phần bị trần 500 pin cắt mất — và cắt theo kiểu gì.
     *
     * Đây là bằng chứng cho giả thuyết 1 của plan: trần cắt bằng
     * `orderBy('screens.id')`, nên phần trả về là phần **nhập vào sớm nhất**,
     * không phải một mẫu. Cách thấy điều đó bằng số: so khoảng `created_at`
     * (hoặc ULID, vốn tăng theo thời gian) của tập trả về với của toàn khung.
     */
    private function inPhanBiCat(FrontpageService $kho): void
    {
        $tra = $kho->getMapPins($this->req([]), self::KHUNG_CA_NUOC, 500);
        $tong = $kho->countMapPins($this->req([]), self::KHUNG_CA_NUOC);

        $this->info('Trần 500 pin · khung cả nước');
        $this->line("  trả về {$tra->count()} / tổng {$tong} · bị cắt " . ($tong - $tra->count()));

        if ($tra->isEmpty()) {
            return;
        }

        // ULID xếp theo thứ tự thời gian tạo, nên id lớn nhất của tập trả về
        // cho biết "bản đồ nhìn thấy kho tới thời điểm nào".
        $idLonNhatTraVe = $tra->max('id');
        $soNamTruoc     = DB::table('screens')->where('active', true)->whereNull('deleted_at')
            ->where('id', '<=', $idLonNhatTraVe)->count();

        $this->line("  id lớn nhất trong tập trả về đứng thứ {$soNamTruoc} / " . number_format($tong) . ' theo thứ tự nhập');
        $this->line('  → bản đồ KHÔNG thấy ' . number_format($tong - $soNamTruoc) . ' màn hình nhập sau đó');
    }

    /**
     * `EXPLAIN` của hai truy vấn đáng ngờ nhất.
     *
     * Đây là phần trả lời được câu hỏi mà con số không trả lời được: MySQL bắt
     * đầu từ bảng nào, và nó có dùng chỉ mục `(lat, lon)` để **cắt** tập hay
     * chỉ để kiểm tra từng hàng.
     */
    private function inKeHoach(FrontpageService $kho): void
    {
        $this->info('EXPLAIN · lọc khung nhìn bản đồ (whereHas)');
        $this->keHoach(
            'select screens.* from screens'
            . ' where screens.active = 1'
            . ' and exists (select 1 from owners where owners.id = screens.owner_id and owners.status = ? and owners.deleted_at is null)'
            . ' and exists (select 1 from sites where screens.site_id = sites.id and lat is not null and lon is not null'
            . ' and lat != 0 and lon != 0 and lat between ? and ? and lon between ? and ?)'
            . ' and screens.deleted_at is null',
            ['active', self::KHUNG_MOT_QUAN['south'], self::KHUNG_MOT_QUAN['north'],
                self::KHUNG_MOT_QUAN['west'], self::KHUNG_MOT_QUAN['east']]
        );

        $this->newLine();
        $this->info('EXPLAIN · sắp theo khoảng cách (join + Haversine)');
        $this->keHoach(
            'select screens.* from screens'
            . ' left join sites as sort_site on screens.site_id = sort_site.id'
            . ' where screens.active = 1 and screens.deleted_at is null'
            . ' order by (sort_site.lat is null or sort_site.lon is null),'
            . ' (6371 * acos(least(1, cos(radians(?)) * cos(radians(sort_site.lat))'
            . ' * cos(radians(sort_site.lon) - radians(?)) + sin(radians(?)) * sin(radians(sort_site.lat)))))'
            . ' asc limit 24',
            [21.0278, 105.8342, 21.0278]
        );
    }

    /** @param  list<mixed>  $buoc */
    private function keHoach(string $sql, array $buoc): void
    {
        foreach (DB::select('EXPLAIN ' . $sql, $buoc) as $hang) {
            $h = (array) $hang;

            $this->line(sprintf(
                '  %-12s %-8s %-18s hàng=%-8s %s',
                $h['table'] ?? '?',
                $h['type'] ?? '?',
                $h['key'] ?? '(không chỉ mục)',
                $h['rows'] ?? '?',
                $h['Extra'] ?? ''
            ));
        }
    }

    /** @param  array<string,string>  $tham */
    private function req(array $tham): Request
    {
        return Request::create('/api/v2/screens', 'GET', $tham);
    }
}
