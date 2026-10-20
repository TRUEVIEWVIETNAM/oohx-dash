<?php

namespace Tests\Feature\Api\V1;

use App\Models\ApiClient;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lật hết các trang của `/api/v1` phải thấy **mỗi bản ghi đúng một lần**.
 *
 * ══ Cùng lớp lỗi với #130, hoãn rồi sửa ══
 *
 * #130 sửa `/api/v2` sau khi đo trên production: lật ba trang của
 * `GET /api/v2/screens` được 104 dòng nhưng chỉ 75 vị trí khác nhau — trang 1
 * và trang 2 trùng 29 vị trí, 29 vị trí khác không ở trang nào. Nguyên nhân:
 * `LIMIT ... OFFSET ...` không cam kết thứ tự khi khoá sắp xếp có hàng bằng
 * nhau, nên MySQL được tự do trả thứ tự nào rẻ nhất cho **từng lần chạy**.
 *
 * `/api/v1` mang đúng lỗi đó nhưng bị hoãn, vì nó là hợp đồng với đối tác
 * (CLAUDE.md mục 1). Chủ dự án chốt sửa ngày 20/10/2026.
 *
 * ══ Năm chỗ ══
 *
 * | Đường | Khoá sắp xếp trước khi sửa |
 * |---|---|
 * | `GET inventory/screens?sort=newest` | `updated_at` — trùng hàng loạt khi nhập theo lô |
 * | `GET inventory/screens?sort=price_asc\|price_desc` | `floor_cpm` — NULL cho 103/104 bản ghi thật |
 * | `GET inventory/owners` | `featured` (boolean) rồi `name` |
 * | `GET owners`, `GET sites`, `GET screens` | **không có `ORDER BY` nào** khi đối tác không gửi `sort` |
 *
 * Nhánh mặc định của `inventory/screens` vốn đã sắp theo `screens.id` nên
 * không hỏng; nó vẫn nằm trong phép kiểm này để không ai gỡ mất.
 *
 * ══ Vì sao dữ liệu ở đây cố tình giống nhau ══
 *
 * Mọi bản ghi dựng trong `dungKhoGiongNhau()` có **cùng `created_at`**,
 * **cùng `updated_at`**, **cùng tên** và **không có giá**. Đó là điều kiện
 * làm lỗi lộ ra; một fixture với giá trị khác nhau sẽ xanh cả khi khoá phụ
 * bị gỡ, vì khi đó khoá chính đã đủ duy nhất.
 *
 * Nửa sau của tệp canh chiều ngược lại: khoá phụ **không được** giành chỗ
 * khoá sắp xếp mà đối tác yêu cầu. Đó mới là phần "không đổi hợp đồng".
 */
class PhanTrangV1Test extends TestCase
{
    use RefreshDatabase;

    /** Quá 20 — `/owners`, `/sites`, `/screens` chốt cứng 20 một trang. */
    private const SO_BAN_GHI = 25;

    private const MOT_LUC = '2026-10-01 08:00:00';

    /** Tên trùng nhau để khoá sắp xếp `name` không phân biệt được hàng nào. */
    private const CUNG_TEN = 'Màn hình giống nhau';

    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'publisher'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->owner = Owner::factory()->create([
            'status'   => 'active',
            'featured' => false,
            'name'     => self::CUNG_TEN,
        ]);
    }

    // ── Dữ liệu ──────────────────────────────────────────────────────────────

    /**
     * Kho mà mọi khoá sắp xếp công bố đều **bằng nhau** trên mọi hàng.
     *
     * @return list<string> external_id của các màn hình, để đối chiếu
     */
    private function dungKhoGiongNhau(): array
    {
        $site = Site::factory()->create([
            'owner_id'   => $this->owner->id,
            'city'       => 'Hà Nội',
            'name'       => self::CUNG_TEN,
            'status'     => 'active',
            'created_at' => self::MOT_LUC,
            'updated_at' => self::MOT_LUC,
        ]);

        $ma = [];

        for ($i = 0; $i < self::SO_BAN_GHI; $i++) {
            $screen = Screen::factory()->create([
                'owner_id'    => $this->owner->id,
                'site_id'     => $site->id,
                'active'      => true,
                'name'        => self::CUNG_TEN,
                'external_id' => 'SCR-GIONG-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'created_at'  => self::MOT_LUC,
                'updated_at'  => self::MOT_LUC,
            ]);

            ScreenSpec::factory()->create([
                'screen_id' => $screen->id,
                'width_cm'  => 400,
                'height_cm' => 200,
            ]);

            // Không giá: khoá sắp theo giá là NULL cho mọi hàng, đúng hình
            // dạng kho thật (103/104 bản ghi chưa có giá).
            ScreenInventory::create([
                'screen_id'     => $screen->id,
                'pricing_model' => 'io',
                'spot_length'   => 15,
            ]);

            $ma[] = $screen->external_id;
        }

        return $ma;
    }

    /** 25 địa điểm cùng tên, cùng tỉnh, cùng lúc tạo — cho `GET /sites`. */
    private function dungDiemDatGiongNhau(): void
    {
        for ($i = 0; $i < self::SO_BAN_GHI; $i++) {
            Site::factory()->create([
                'owner_id'    => $this->owner->id,
                'external_id' => 'SITE-GIONG-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'name'        => self::CUNG_TEN,
                'city'        => 'Hà Nội',
                'status'      => 'active',
                'created_at'  => self::MOT_LUC,
                'updated_at'  => self::MOT_LUC,
            ]);
        }
    }

    /** 25 media owner cùng tên, cùng `featured`, không màn hình nào. */
    private function dungOwnerGiongNhau(): void
    {
        // `$this->owner` đã là một trong số đó.
        Owner::factory()->count(self::SO_BAN_GHI - 1)->create([
            'status'     => 'active',
            'featured'   => false,
            'name'       => self::CUNG_TEN,
            'created_at' => self::MOT_LUC,
            'updated_at' => self::MOT_LUC,
        ]);
    }

    // ── Người gọi ────────────────────────────────────────────────────────────

    /** Token đối tác — đúng thứ nhóm `inventory` nhận. */
    private function tokenDoiTac(): string
    {
        $client = ApiClient::create([
            'client_id'     => 'phan-trang-v1',
            'client_secret' => bcrypt('secret'),
            'name'          => 'Đối tác thử phân trang',
            'scopes'        => ['inventory'],
            'active'        => true,
        ]);

        return $client->createToken('test', ['inventory'])->plainTextToken;
    }

    private function dangNhapQuanTriSan(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        Sanctum::actingAs($user, ['manage']);
    }

    private function dangNhapPublisher(): void
    {
        $user = User::factory()->create(['current_owner_id' => $this->owner->id]);
        $user->assignRole('publisher');
        OwnerUser::create([
            'owner_id' => $this->owner->id,
            'user_id'  => $user->id,
            'role'     => 'owner',
        ]);

        Sanctum::actingAs($user, ['manage']);
    }

    // ── Lật trang ────────────────────────────────────────────────────────────

    /**
     * Lật hết các trang của một endpoint `inventory/*` — phản hồi ở đó là
     * `{total, page, limit, data}`, không có `last_page`.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function latHetKhoHang(string $duong, string $khoa, int $limit): array
    {
        $token = $this->tokenDoiTac();
        $thay  = [];
        $trang = 1;
        $tong  = 0;

        do {
            $noi  = str_contains($duong, '?') ? '&' : '?';
            $body = $this->withToken($token)
                ->getJson("/api/v1{$duong}{$noi}limit={$limit}&page={$trang}")
                ->assertOk()
                ->json();

            foreach ($body['data'] as $dong) {
                $thay[] = (string) data_get($dong, $khoa);
            }

            $tong = (int) $body['total'];
            $trang++;
        } while (($trang - 1) * $limit < $tong && $trang <= 20);

        return [$thay, $tong];
    }

    /**
     * Lật hết các trang của một endpoint quản lý — 20 một trang, không nhận
     * tham số đổi cỡ trang.
     *
     * Hai dạng phản hồi khác nhau trong cùng một nhóm route: `/sites` và
     * `/screens` trả paginator thuần (`total`, `last_page` ở gốc), còn
     * `/owners` đi qua `OwnerResource::collection()->response()` nên hai số
     * đó nằm trong `meta`. Đọc cả hai chỗ.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function latHetQuanLy(string $duong, string $khoa = 'id'): array
    {
        $thay  = [];
        $trang = 1;

        do {
            $noi  = str_contains($duong, '?') ? '&' : '?';
            $body = $this->getJson("/api/v1{$duong}{$noi}page={$trang}")
                ->assertOk()
                ->json();

            foreach ($body['data'] as $dong) {
                $thay[] = (string) data_get($dong, $khoa);
            }

            $cuoi = (int) ($body['last_page'] ?? $body['meta']['last_page'] ?? 1);
            $tong = (int) ($body['total'] ?? $body['meta']['total'] ?? 0);
            $trang++;
        } while ($trang <= $cuoi && $trang <= 20);

        return [$thay, $tong];
    }

    /** @param list<string> $thay */
    private function khangDinhKhongLap(array $thay, int $tong, string $duong): void
    {
        $this->assertSame(
            $tong,
            count(array_unique($thay)),
            "Lật hết các trang của `{$duong}` chỉ thấy " . count(array_unique($thay))
                . " bản ghi khác nhau trên tổng {$tong}. Những bản ghi thiếu "
                . 'không có đường nào để đối tác tới, và số còn lại trả về hai lần.',
        );

        $this->assertSame(
            $tong,
            count($thay),
            "Tổng số dòng `{$duong}` trả về khác `total` — có bản ghi bị trả lặp.",
        );
    }

    // ── Kho hàng công khai ───────────────────────────────────────────────────

    /** @return array<string, list<string>> tên ca → tham số thêm vào URL */
    public static function cachSapXep(): array
    {
        return [
            'mặc định'   => [''],
            'newest'     => ['?sort=newest'],
            'price_asc'  => ['?sort=price_asc'],
            'price_desc' => ['?sort=price_desc'],
        ];
    }

    #[DataProvider('cachSapXep')]
    public function test_lat_het_trang_kho_hang_thay_moi_man_hinh_dung_mot_lan(string $thamSo): void
    {
        $this->dungKhoGiongNhau();

        [$thay, $tong] = $this->latHetKhoHang(
            '/inventory/screens' . $thamSo,
            'screen_id',
            10,
        );

        $this->assertSame(self::SO_BAN_GHI, $tong, 'Fixture không dựng đủ.');
        $this->khangDinhKhongLap($thay, $tong, 'inventory/screens' . $thamSo);
    }

    public function test_lat_het_trang_media_owner_cong_khai_khong_lap(): void
    {
        $this->dungOwnerGiongNhau();

        [$thay, $tong] = $this->latHetKhoHang('/inventory/owners', 'owner_id', 10);

        $this->assertSame(self::SO_BAN_GHI, $tong, 'Fixture không dựng đủ.');
        $this->khangDinhKhongLap($thay, $tong, 'inventory/owners');
    }

    // ── Ba endpoint quản lý ──────────────────────────────────────────────────
    //
    // ĐỌC TRƯỚC KHI TIN SÁU CA DƯỚI ĐÂY.
    //
    // Phép đột biến (gỡ khoá phụ khỏi `phanTrang()`) làm bốn ca kho hàng ở
    // trên đỏ, nhưng **sáu ca quản lý vẫn xanh**. Lý do: ba endpoint đó
    // không có `ORDER BY` nào khi khoá phụ bị gỡ, và với 25 hàng trong một
    // bảng vừa dựng, MySQL quét theo khoá chính một cách nhất quán giữa hai
    // truy vấn — nên không có bản ghi nào lặp. Thứ tự *không xác định* không
    // có nghĩa là thứ tự *ngẫu nhiên*: nó đổi khi kế hoạch truy vấn đổi, tức
    // khi dữ liệu đủ nhiều để optimizer chọn đường khác, chứ không đổi ở cỡ
    // của một fixture.
    //
    // Nên sáu ca này canh một thứ hẹp hơn tên chúng gợi ra: endpoint trả về
    // đủ số bản ghi và không lặp *trong điều kiện hiện tại*. Thứ canh đúng
    // lỗi là `test_ba_endpoint_quan_ly_co_khoa_phu_trong_cau_sql` ở dưới, và
    // nó đi xuống tận câu SQL vì ở mức phản hồi không quan sát được.

    public function test_lat_het_trang_owners_quan_ly_khong_lap(): void
    {
        $this->dungOwnerGiongNhau();
        $this->dangNhapQuanTriSan();

        [$thay, $tong] = $this->latHetQuanLy('/owners');

        $this->assertSame(self::SO_BAN_GHI, $tong, 'Fixture không dựng đủ.');
        $this->khangDinhKhongLap($thay, $tong, 'owners');
    }

    public function test_lat_het_trang_owners_quan_ly_khong_lap_ke_ca_khi_co_sort(): void
    {
        $this->dungOwnerGiongNhau();
        $this->dangNhapQuanTriSan();

        // 25 owner cùng tên: khoá `name` không phân biệt được hàng nào, đúng
        // chỗ khoá phụ phải đỡ.
        [$thay, $tong] = $this->latHetQuanLy('/owners?sort=name');

        $this->khangDinhKhongLap($thay, $tong, 'owners?sort=name');
    }

    public function test_lat_het_trang_sites_quan_ly_khong_lap(): void
    {
        $this->dungDiemDatGiongNhau();
        $this->dangNhapPublisher();

        [$thay, $tong] = $this->latHetQuanLy('/sites');

        $this->assertSame(self::SO_BAN_GHI, $tong, 'Fixture không dựng đủ.');
        $this->khangDinhKhongLap($thay, $tong, 'sites');
    }

    public function test_lat_het_trang_sites_quan_ly_khong_lap_khi_sort_theo_tinh(): void
    {
        $this->dungDiemDatGiongNhau();
        $this->dangNhapPublisher();

        // Cả 25 địa điểm cùng một tỉnh.
        [$thay, $tong] = $this->latHetQuanLy('/sites?sort=city');

        $this->khangDinhKhongLap($thay, $tong, 'sites?sort=city');
    }

    public function test_lat_het_trang_screens_quan_ly_khong_lap(): void
    {
        $this->dungKhoGiongNhau();
        $this->dangNhapPublisher();

        [$thay, $tong] = $this->latHetQuanLy('/screens');

        $this->assertSame(self::SO_BAN_GHI, $tong, 'Fixture không dựng đủ.');
        $this->khangDinhKhongLap($thay, $tong, 'screens');
    }

    public function test_lat_het_trang_screens_quan_ly_khong_lap_khi_sort_theo_ten(): void
    {
        $this->dungKhoGiongNhau();
        $this->dangNhapPublisher();

        [$thay, $tong] = $this->latHetQuanLy('/screens?sort=name');

        $this->khangDinhKhongLap($thay, $tong, 'screens?sort=name');
    }

    // ── Hợp đồng không đổi: khoá phụ vẫn là PHỤ ──────────────────────────────

    /**
     * Phần quan trọng nhất của tệp này.
     *
     * Nếu khoá phụ chèn **trước** khoá sắp xếp đối tác yêu cầu thì danh sách
     * tuy không lặp nhưng thứ tự đã khác hẳn — đó mới thật là đổi hành vi
     * hợp đồng. `allowedSorts()` của Spatie nạp sort vào query ngay trong
     * thân nó, nên `orderBy()` gọi sau là khoá phụ; ca này đóng băng điều đó
     * lại, vì nó phụ thuộc vào thứ tự gọi trong một thư viện bên ngoài.
     */
    public function test_sort_newest_van_sap_theo_lan_cap_nhat_moi_nhat(): void
    {
        $site = Site::factory()->create([
            'owner_id' => $this->owner->id,
            'status'   => 'active',
        ]);

        $moc = ['2026-10-03 10:00:00', '2026-10-01 10:00:00', '2026-10-02 10:00:00'];

        foreach ($moc as $i => $luc) {
            $screen = Screen::factory()->create([
                'owner_id'    => $this->owner->id,
                'site_id'     => $site->id,
                'active'      => true,
                'external_id' => 'SCR-MOC-' . $i,
                'updated_at'  => $luc,
            ]);

            ScreenSpec::factory()->create(['screen_id' => $screen->id]);
            ScreenInventory::create([
                'screen_id'     => $screen->id,
                'pricing_model' => 'io',
                'spot_length'   => 15,
            ]);
        }

        [$thay] = $this->latHetKhoHang('/inventory/screens?sort=newest', 'screen_id', 10);

        $this->assertSame(
            ['SCR-MOC-0', 'SCR-MOC-2', 'SCR-MOC-1'],
            $thay,
            '`sort=newest` phải trả mới nhất trước. Khoá phụ chỉ được phá thế '
                . 'bằng, không được giành chỗ khoá sắp xếp đối tác yêu cầu.',
        );
    }

    public function test_sort_name_o_endpoint_quan_ly_van_sap_theo_ten(): void
    {
        foreach (['Cần Thơ', 'An Giang', 'Bắc Ninh'] as $i => $ten) {
            Site::factory()->create([
                'owner_id'    => $this->owner->id,
                'external_id' => 'SITE-TEN-' . $i,
                'name'        => $ten,
                'status'      => 'active',
            ]);
        }

        $this->dangNhapPublisher();

        [$thay] = $this->latHetQuanLy('/sites?sort=name', 'name');

        $this->assertSame(
            ['An Giang', 'Bắc Ninh', 'Cần Thơ'],
            $thay,
            '`sort=name` phải thắng khoá phụ. Nếu thứ tự ra theo ULID thì '
                . 'khoá phụ đã chèn trước khoá chính.',
        );
    }

    public function test_sort_price_asc_van_sap_theo_gia(): void
    {
        $site = Site::factory()->create([
            'owner_id' => $this->owner->id,
            'status'   => 'active',
        ]);

        foreach ([30000, 10000, 20000] as $i => $gia) {
            $screen = Screen::factory()->create([
                'owner_id'    => $this->owner->id,
                'site_id'     => $site->id,
                'active'      => true,
                'external_id' => 'SCR-GIA-' . $i,
            ]);

            ScreenSpec::factory()->create(['screen_id' => $screen->id]);
            ScreenInventory::create([
                'screen_id'     => $screen->id,
                'pricing_model' => 'io',
                'spot_length'   => 15,
                'floor_cpm'     => $gia,
            ]);
        }

        [$thay] = $this->latHetKhoHang('/inventory/screens?sort=price_asc', 'screen_id', 10);

        $this->assertSame(
            ['SCR-GIA-1', 'SCR-GIA-2', 'SCR-GIA-0'],
            $thay,
            '`sort=price_asc` phải trả rẻ nhất trước.',
        );
    }

    public function test_owner_noi_bat_van_dung_dau_danh_sach_cong_khai(): void
    {
        // `featured` là khoá sắp đầu tiên của `inventory/owners`; khoá phụ
        // không được đẩy owner nổi bật xuống dưới.
        Owner::factory()->count(5)->create(['status' => 'active', 'featured' => false]);

        $noiBat = Owner::factory()->create([
            'status'   => 'active',
            'featured' => true,
            'slug'     => 'owner-noi-bat',
        ]);

        [$thay] = $this->latHetKhoHang('/inventory/owners', 'owner_id', 10);

        $this->assertSame(
            $noiBat->slug,
            $thay[0],
            'Owner `featured` phải đứng đầu — khoá phụ không được giành chỗ.',
        );
    }

    /**
     * Ba endpoint quản lý: khoá phụ phải có mặt trong câu SQL.
     *
     * Ca này đi xuống mức SQL vì ở mức phản hồi không quan sát được — xem
     * khối chú thích trên sáu ca lật trang quản lý. Nó đỏ ngay khi
     * `phanTrang()` mất `orderBy()`, kể cả khi MySQL tình cờ trả đúng.
     *
     * Khẳng định là "câu SQL **kết thúc** bằng khoá chính": điều đó vừa nói
     * khoá phụ có mặt, vừa nói nó đứng **sau** mọi khoá sắp xếp khác.
     */
    public function test_ba_endpoint_quan_ly_co_khoa_phu_trong_cau_sql(): void
    {
        // Phải có hàng thật: `paginate()` bỏ hẳn truy vấn liệt kê khi tổng
        // bằng 0, nên một bảng rỗng thì không có câu SQL nào để xem.
        $this->dungKhoGiongNhau();

        $duong = [
            '/owners'            => 'owners',
            '/sites'             => 'sites',
            '/screens'           => 'screens',
            '/sites?sort=name'   => 'sites',
            '/screens?sort=name' => 'screens',
        ];

        foreach ($duong as $url => $bang) {
            // `/owners` cần super_admin, hai cái kia cần publisher có tenant.
            str_starts_with($url, '/owners')
                ? $this->dangNhapQuanTriSan()
                : $this->dangNhapPublisher();

            $cau = [];
            DB::listen(function ($su) use (&$cau) {
                $cau[] = $su->sql;
            });

            $this->getJson('/api/v1' . $url)->assertOk();

            // Bỏ truy vấn đếm của paginator và các truy vấn phụ (quyền, đếm
            // quan hệ): câu cần xem là câu có `limit`.
            $cauLiet = array_values(array_filter(
                $cau,
                fn (string $sql) => str_contains($sql, "from `{$bang}`")
                    && str_contains($sql, 'limit'),
            ));

            $this->assertNotEmpty(
                $cauLiet,
                "Không thấy truy vấn liệt kê nào cho `{$url}`.",
            );

            foreach ($cauLiet as $sql) {
                $this->assertMatchesRegularExpression(
                    '/order by .*`' . $bang . '`\.`id` asc\s*limit/i',
                    $sql,
                    "`{$url}` phân trang mà khoá chính không phải khoá sắp cuối "
                        . "cùng. Câu SQL: {$sql}",
                );
            }
        }
    }

    /**
     * Nhánh mặc định của `inventory/screens` cũng chỉ còn khoá phụ.
     *
     * Trước ngày 20/10 nhánh đó tự sắp `screens.id`; nay nó dựa vào
     * `phanTrang()` như ba nhánh kia, nên SQL sinh ra y hệt nhưng chỗ quyết
     * định đã chuyển. Phép đột biến cho thấy ca lật trang **không** bắt được
     * điều này — không có `ORDER BY`, MySQL vẫn trả nhất quán ở cỡ fixture —
     * nên câu SQL là chỗ duy nhất quan sát được.
     */
    public function test_kho_hang_mac_dinh_co_khoa_phu_trong_cau_sql(): void
    {
        $this->dungKhoGiongNhau();

        $cau = [];
        DB::listen(function ($su) use (&$cau) {
            $cau[] = $su->sql;
        });

        $this->withToken($this->tokenDoiTac())
            ->getJson('/api/v1/inventory/screens?limit=10')
            ->assertOk();

        $cauLiet = array_values(array_filter(
            $cau,
            fn (string $sql) => str_contains($sql, 'from `screens`')
                && str_contains($sql, 'limit'),
        ));

        $this->assertNotEmpty($cauLiet, 'Không thấy truy vấn liệt kê màn hình nào.');

        foreach ($cauLiet as $sql) {
            $this->assertMatchesRegularExpression(
                '/order by .*`screens`\.`id` asc\s*limit/i',
                $sql,
                "Danh sách kho hàng phân trang mà khoá chính không phải khoá "
                    . "sắp cuối cùng. Câu SQL: {$sql}",
            );
        }
    }

    // ── Chốt tĩnh chống tái phát ─────────────────────────────────────────────

    public function test_moi_danh_sach_v1_deu_di_qua_mot_cho(): void
    {
        $controller = [
            'InventoryController',
            'OwnerController',
            'ScreenController',
            'SiteController',
        ];

        foreach ($controller as $ten) {
            $nguon = file_get_contents(app_path("Http/Controllers/Api/V1/{$ten}.php"));

            $this->assertStringContainsString(
                'use PhanTrangOnDinh;',
                $nguon,
                "{$ten} không dùng trait `PhanTrangOnDinh`.",
            );

            $this->assertSame(
                0,
                substr_count($nguon, '->paginate('),
                "{$ten} gọi thẳng `paginate()`. Mọi danh sách phải đi qua "
                    . '`phanTrang()`, nơi thêm khoá phụ duy nhất vào thứ tự '
                    . 'sắp xếp — và lỗi chỉ lộ ra khi dữ liệu đủ nhiều để có '
                    . 'hàng bằng nhau, tức trên production, không phải ở đây.',
            );
        }
    }
}
