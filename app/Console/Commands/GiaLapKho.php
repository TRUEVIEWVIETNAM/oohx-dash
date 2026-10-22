<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dựng một kho giả đủ lớn để **bấm giờ** được — lát 0 của phần bổ sung
 * "quy mô 10.000 vị trí" trong `docs/PLAN-GOP-EXPLORE-BAN-DO.md`.
 *
 * ══ Vì sao lệnh này tồn tại ══
 *
 * Mọi con số của plan gộp `/explore` đo trên 104 bản ghi production. Kho thật
 * có hơn 10.000 billboard. Ở bậc đó có ba chỗ đáng ngờ (trần 500 pin, phép
 * sắp theo Haversine, `whereHas` chặn chỉ mục `(lat,lon)`) — nhưng **không ai
 * bấm giờ chúng**, nên mọi chẩn đoán là phỏng đoán. Tối ưu một truy vấn chưa
 * bấm giờ là cách chắc chắn nhất để sửa thứ không đau.
 *
 * ══ Phân bố phải DỒN CỤC, không được đều ══
 *
 * Toạ độ rải đều trên hình chữ nhật Việt Nam sẽ cho số đo **lạc quan sai**:
 * - gom cụm theo ô lưới trông dễ, vì mọi ô có số đếm gần như nhau;
 * - lọc khung nhìn trông chọn lọc tốt, vì khung nào cũng cắt theo tỉ lệ diện
 *   tích.
 *
 * Kho OOH thật thì ngược: hơn một nửa dồn vào Hà Nội và TP.HCM, nên một khung
 * nhìn quanh Hà Nội vẫn chứa hàng nghìn bản ghi và đúng chỗ đó mới là chỗ
 * đau. Nên bảng `self::DO_THI` dưới đây mang trọng số thật, không chia đều.
 *
 * ══ Tỉ lệ thiếu dữ liệu chép từ production ══
 *
 * Đo ngày 22/10/2026 trên 104 bản ghi thật: `floor_cpm` **0/104**,
 * `io_rate` 19/104, `city` chứa `>` 21/104, `district` **0/104**. Giữ đúng
 * những tỉ lệ đó, vì bảng tổng kế hoạch và dải chip lọc đọc chính các trường
 * này — gieo dữ liệu đầy đủ hơn kho thật sẽ làm chúng trông lành mạnh.
 */
class GiaLapKho extends Command
{
    protected $signature = 'oohx:gia-lap-kho
                            {so=10000 : Số màn hình cần gieo}
                            {--chu=40 : Số media owner}';

    protected $description = 'Gieo kho giả dồn cục theo đô thị để bấm giờ ở quy mô lớn (CHỈ chạy trên CSDL cục bộ)';

    /**
     * Những host được phép gieo lên.
     *
     * `.env` của dự án trỏ vào CSDL **production**, nên một lệnh gieo 10.000
     * bản ghi là một lệnh phá hoại nếu gõ sai một biến môi trường. Danh sách
     * trắng cứng ở đây, không phải một cảnh báo in ra rồi hỏi "có chắc không"
     * — câu hỏi đó người ta bấm Enter qua.
     *
     * @var list<string>
     */
    private const HOST_CHO_PHEP = ['127.0.0.1', 'localhost', '::1', 'host.docker.internal', 'mysql'];

    /**
     * Đô thị kèm **trọng số** — tổng 100.
     *
     * Trọng số lấy theo hình dạng kho OOH thật: Hà Nội và TP.HCM chiếm quá
     * nửa, phần còn lại rải mỏng. `ban_kinh` là độ lệch toạ độ tối đa quanh
     * tâm, tính bằng độ (≈0,18° ~ 20km) — thành phố lớn rải rộng hơn.
     *
     * @var list<array{0:string,1:float,2:float,3:int,4:float}>
     */
    private const DO_THI = [
        // tên,              lat,      lon,       trọng số, bán kính
        ['Hà Nội',           21.0278, 105.8342,  30, 0.22],
        ['TP. Hồ Chí Minh',  10.8231, 106.6297,  28, 0.25],
        ['Đà Nẵng',          16.0544, 108.2022,   7, 0.12],
        ['Hải Phòng',        20.8449, 106.6881,   5, 0.12],
        ['Cần Thơ',          10.0452, 105.7469,   4, 0.10],
        ['Bình Dương',       11.0686, 106.6505,   4, 0.14],
        ['Đồng Nai',         10.9574, 106.8427,   3, 0.14],
        ['Khánh Hòa',        12.2388, 109.1967,   3, 0.10],
        ['Quảng Ninh',       20.9599, 107.0448,   3, 0.16],
        ['Thừa Thiên Huế',   16.4637, 107.5909,   3, 0.10],
        ['Lâm Đồng',         11.9404, 108.4583,   2, 0.12],
        ['Nghệ An',          18.6796, 105.6813,   2, 0.14],
        ['Thanh Hóa',        19.8069, 105.7852,   2, 0.14],
        ['Kiên Giang',       10.0124, 105.0808,   2, 0.12],
        ['Lào Cai',          22.4856, 103.9707,   1, 0.10],
        ['Đắk Lắk',          12.6667, 108.0500,   1, 0.12],
    ];

    /** @var list<string> */
    private const LOAI_DIA_DIEM = ['outdoor', 'transit', 'retail', 'office', 'residential', 'entertainment'];

    public function handle(): int
    {
        if (($ma = $this->chanProduction()) !== 0) {
            return $ma;
        }

        // PHP trong container chạy `memory_limit=128M`, và Laravel giữ mọi câu
        // truy vấn kèm tham số trong bộ nhớ khi query log bật. Gieo 50.000 bản
        // ghi chết giữa đường ở lượt đầu vì đúng lý do đó — không phải vì mảng
        // dữ liệu, mà vì cái nhật ký không ai đọc.
        DB::connection()->disableQueryLog();

        $soManHinh = max(1, (int) $this->argument('so'));
        $soChu     = max(1, (int) $this->option('chu'));

        // Nhiều màn hình dùng chung một site là hình dạng thật (một toà nhà có
        // vài màn), và cũng là thứ `gomTheoSite` phía trình duyệt phải gánh.
        $soSite = (int) ceil($soManHinh / 1.7);

        $this->info("Gieo {$soChu} chủ · {$soSite} site · {$soManHinh} màn hình");

        $idChu  = $this->gieoChu($soChu);
        $site   = $this->gieoSite($soSite, $idChu);
        $this->gieoManHinh($soManHinh, $site);

        $this->newLine();
        $this->info('Xong. Số hàng thực tế:');

        foreach (['owners', 'sites', 'screens', 'screen_inventory'] as $bang) {
            $this->line(sprintf('  %-18s %s', $bang, number_format(DB::table($bang)->count())));
        }

        return self::SUCCESS;
    }

    /**
     * Ba lớp chặn, và không lớp nào là một câu hỏi cho người gõ.
     */
    private function chanProduction(): int
    {
        if (app()->environment('production')) {
            $this->error('APP_ENV=production. Lệnh này không chạy ở đó.');

            return self::FAILURE;
        }

        $host = (string) config('database.connections.' . config('database.default') . '.host');

        if (! in_array($host, self::HOST_CHO_PHEP, true) && ! str_starts_with($host, 'oohx-test-')) {
            $this->error("DB_HOST = '{$host}' không phải CSDL cục bộ.");
            $this->line('Chỉ gieo lên: ' . implode(', ', self::HOST_CHO_PHEP) . ', hoặc host bắt đầu bằng "oohx-test-".');

            return self::FAILURE;
        }

        $csdl = (string) config('database.connections.' . config('database.default') . '.database');

        // Tên CSDL production, chặn riêng: một đường hầm SSH hay một bản ghi
        // `/etc/hosts` đủ để làm `DB_HOST` trông như cục bộ.
        if (str_contains($csdl, 'dash_oohx')) {
            $this->error("DB_DATABASE = '{$csdl}' là tên CSDL production.");

            return self::FAILURE;
        }

        return 0;
    }

    /** @return list<string> */
    private function gieoChu(int $so): array
    {
        $hang = [];
        $id   = [];
        $now  = now();

        for ($i = 0; $i < $so; $i++) {
            $ulid = (string) Str::ulid();
            $id[] = $ulid;

            $hang[] = [
                'id'         => $ulid,
                'name'       => 'Chủ kho giả ' . ($i + 1),
                'slug'       => 'chu-kho-gia-' . ($i + 1),
                'type'       => 'media_owner',
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('owners')->insert($hang);

        return $id;
    }

    /**
     * @param  list<string>  $idChu
     * @return list<array{id:string,owner:string}>
     */
    private function gieoSite(int $so, array $idChu): array
    {
        $bangTrongSo = $this->bangTrongSo();
        $now         = now();
        $ra          = [];
        $lo          = [];

        for ($i = 0; $i < $so; $i++) {
            [$ten, $lat, $lon, , $banKinh] = self::DO_THI[$bangTrongSo[random_int(0, 99)]];

            $ulid  = (string) Str::ulid();
            $owner = $idChu[random_int(0, count($idChu) - 1)];

            // 21/104 bản ghi thật có `city` dạng "Tỉnh > Phường" — đúng cái
            // dạng làm nhóm chip lọc dài ra và làm ô "tỉnh thành" của bảng
            // tổng phải tách chuỗi.
            $city = random_int(1, 100) <= 20
                ? $ten . ' > Phường ' . random_int(1, 20)
                : $ten;

            $ra[] = ['id' => $ulid, 'owner' => $owner];

            $lo[] = [
                'id'          => $ulid,
                'owner_id'    => $owner,
                'external_id' => 'site-gia-' . $i,
                'name'        => 'Điểm giả ' . $i . ' · ' . $ten,
                'slug'        => 'diem-gia-' . $i,
                'lat'         => round($lat + (random_int(-1000, 1000) / 1000) * $banKinh, 7),
                'lon'         => round($lon + (random_int(-1000, 1000) / 1000) * $banKinh, 7),
                'address'     => 'Số ' . random_int(1, 400) . ' đường Giả Lập',
                'city'        => $city,
                'country'     => 'VN',
                'status'      => 'active',
                'created_at'  => $now,
                'updated_at'  => $now,
            ];

            if (count($lo) === 250) {
                DB::table('sites')->insert($lo);
                $lo = [];
            }
        }

        if ($lo !== []) {
            DB::table('sites')->insert($lo);
        }

        return $ra;
    }

    /** @param  list<array{id:string,owner:string}>  $site */
    private function gieoManHinh(int $so, array $site): void
    {
        $now     = now();
        $soSite  = count($site);
        $loMan   = [];
        $loKho   = [];

        for ($i = 0; $i < $so; $i++) {
            // Site đầu nhận nhiều màn hơn (một toà nhà vài màn), phần sau
            // một-một — giữ cho `gomTheoSite` có cả hai hình dạng.
            $s    = $site[$i % $soSite];
            $ulid = (string) Str::ulid();

            $loMan[] = [
                'id'          => $ulid,
                'site_id'     => $s['id'],
                'owner_id'    => $s['owner'],
                'external_id' => 'man-gia-' . $i,
                'uuid'        => (string) Str::uuid(),
                'name'        => 'Màn hình giả ' . $i,
                'slug'        => 'man-hinh-gia-' . $i,
                'active'      => 1,
                'status'      => 'online',
                'created_at'  => $now,
                'updated_at'  => $now,
            ];

            // `floor_cpm` 0/104 trên production. Giữ **rỗng hoàn toàn**: ô CPM
            // của bảng tổng kế hoạch đang trống vì lý do này, và gieo giá vào
            // đây sẽ làm nó trông như đã chạy.
            $loKho[] = [
                'screen_id'  => $ulid,
                'venue_type' => self::LOAI_DIA_DIEM[random_int(0, count(self::LOAI_DIA_DIEM) - 1)],
                'floor_cpm'  => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($loMan) === 250) {
                DB::table('screens')->insert($loMan);
                DB::table('screen_inventory')->insert($loKho);
                $loMan = [];
                $loKho = [];
                $this->output->write('.');
            }
        }

        if ($loMan !== []) {
            DB::table('screens')->insert($loMan);
            DB::table('screen_inventory')->insert($loKho);
        }
    }

    /**
     * Bảng tra 100 ô: ô thứ n chứa chỉ số đô thị, lặp theo trọng số. Rút một
     * số 0–99 là rút theo đúng phân bố, không cần luỹ tích.
     *
     * @return list<int>
     */
    private function bangTrongSo(): array
    {
        $bang = [];

        foreach (self::DO_THI as $i => $dt) {
            for ($n = 0; $n < $dt[3]; $n++) {
                $bang[] = $i;
            }
        }

        return $bang;
    }
}
