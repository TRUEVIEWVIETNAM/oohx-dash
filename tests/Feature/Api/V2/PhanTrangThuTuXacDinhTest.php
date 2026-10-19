<?php

namespace Tests\Feature\Api\V2;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lật hết các trang phải thấy **mỗi bản ghi đúng một lần**.
 *
 * ══ Lỗi đo được trên production ngày 19/10/2026 ══
 *
 * Đọc ba trang của `GET /api/v2/screens` (104 bản ghi, 50 một trang): 104
 * dòng trả về nhưng chỉ **75 slug duy nhất**. Trang 1 và trang 2 trùng nhau
 * 29 vị trí, và 29 vị trí khác không xuất hiện ở trang nào — người mua không
 * có đường nào để tới chúng. Với `sort=price_asc`: 54/104.
 *
 * ══ Vì sao ══
 *
 * `LIMIT ... OFFSET ...` **không** cam kết thứ tự khi khoá sắp xếp có hàng
 * bằng nhau. Mỗi danh sách ở `FrontpageService` sắp theo đúng một cột không
 * duy nhất: `created_at`, giá quy đổi, `screen_count`. Kho thật nhập theo lô
 * nên `created_at` trùng hàng loạt, và 103/104 bản ghi chưa có giá nên khoá
 * sắp theo giá là NULL cho gần hết.
 *
 * MySQL được tự do trả thứ tự nào rẻ nhất cho **từng lần chạy**, nên hai
 * truy vấn khác `OFFSET` có thể chồng lấn mà không có gì sai về mặt SQL.
 *
 * ══ Vì sao dữ liệu của phép kiểm này cố tình giống nhau ══
 *
 * Mọi màn hình dựng ở đây có **cùng `created_at`** và **không có giá**. Đó
 * là điều kiện làm lỗi lộ ra; một fixture với thời điểm tạo khác nhau sẽ
 * xanh cả khi khoá phụ bị gỡ, vì khi đó khoá chính đã đủ duy nhất.
 */
class PhanTrangThuTuXacDinhTest extends TestCase
{
    use RefreshDatabase;

    /** Đủ để chia thành ba trang mà vẫn chạy nhanh. */
    private const SO_MAN_HINH = 25;

    private const MOT_LUC = '2026-10-01 08:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function dungKho(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);
        $site  = Site::factory()->create([
            'owner_id' => $owner->id,
            'city'     => 'Hà Nội',
            'status'   => 'active',
        ]);

        for ($i = 0; $i < self::SO_MAN_HINH; $i++) {
            $screen = Screen::factory()->create([
                'owner_id'   => $owner->id,
                'site_id'    => $site->id,
                'active'     => true,
                'name'       => 'Màn hình ' . $i,
                'created_at' => self::MOT_LUC,
                'updated_at' => self::MOT_LUC,
            ]);

            ScreenSpec::factory()->create([
                'screen_id' => $screen->id,
                'width_cm'  => 400,
                'height_cm' => 200,
            ]);

            // Không giá: khoá sắp theo giá là NULL cho mọi hàng, đúng hình
            // dạng kho thật.
            ScreenInventory::create([
                'screen_id'     => $screen->id,
                'pricing_model' => 'io',
                'spot_length'   => 15,
            ]);
        }
    }

    /**
     * Lật hết các trang của một endpoint, trả về mọi khoá đã thấy (kể cả
     * trùng) cùng `meta.total`.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function latHetTrang(string $duong, int $perPage, string $khoa = 'slug'): array
    {
        $thay  = [];
        $tong  = 0;
        $trang = 1;

        do {
            $noi = str_contains($duong, '?') ? '&' : '?';

            $body = $this->getJson("{$duong}{$noi}per_page={$perPage}&page={$trang}")
                ->assertOk()
                ->json();

            foreach ($body['data'] as $dong) {
                $thay[] = (string) $dong[$khoa];
            }

            $tong    = (int) ($body['meta']['total'] ?? 0);
            $cuoi    = (int) ($body['meta']['last_page'] ?? 1);
            $trang++;
        } while ($trang <= $cuoi && $trang <= 20);

        return [$thay, $tong];
    }

    /** @return array<string, string> tên ca → tham số thêm vào URL */
    public static function cachSapXep(): array
    {
        return [
            'mặc định'  => [''],
            'newest'    => ['?sort=newest'],
            'price_asc' => ['?sort=price_asc'],
            'price_desc' => ['?sort=price_desc'],
        ];
    }

    #[DataProvider('cachSapXep')]
    public function test_lat_het_trang_thay_moi_man_hinh_dung_mot_lan(string $thamSo): void
    {
        $this->dungKho();

        [$thay, $tong] = $this->latHetTrang('/api/v2/screens' . $thamSo, 10);

        $this->assertSame(self::SO_MAN_HINH, $tong, 'Fixture không dựng đủ.');

        $this->assertSame(
            $tong,
            count(array_unique($thay)),
            "Lật hết các trang (`{$thamSo}`) chỉ thấy " . count(array_unique($thay))
                . " bản ghi khác nhau trên tổng {$tong}. Những bản ghi thiếu "
                . 'không có đường nào để người dùng tới, và số còn lại hiện ra '
                . 'hai lần.',
        );

        $this->assertSame(
            $tong,
            count($thay),
            'Tổng số dòng trả về khác `meta.total` — có bản ghi bị trả lặp.',
        );
    }

    public function test_danh_sach_media_owner_cung_khong_lap(): void
    {
        // Cùng lớp lỗi: `/owners` sắp theo `featured` rồi `screen_count`, và
        // owner mới chưa có màn hình nào thì cả hai đều bằng nhau.
        Owner::factory()->count(25)->create(['status' => 'active', 'featured' => false]);

        [$thay, $tong] = $this->latHetTrang('/api/v2/owners', 10);

        $this->assertSame(25, $tong);
        $this->assertSame($tong, count(array_unique($thay)));
    }

    /**
     * Ca này **không** bắt được lỗi ở trên, và nói ra điều đó là có ích.
     *
     * Gỡ khoá phụ đi thì sáu ca khác đỏ, riêng ca này vẫn xanh: thứ tự không
     * xác định vẫn có thể nhất quán giữa hai lượt gọi liền nhau — đo trên
     * production ngày 19/10 đúng như vậy, hai lần hỏi trang 1 trả về y hệt
     * trong khi trang 2 vẫn trùng trang 1 tới 29 bản ghi.
     *
     * Giữ nó vì nó canh một thuộc tính khác: nếu sau này có ai thêm một yếu
     * tố ngẫu nhiên vào thứ tự (xáo trộn để "đa dạng hoá" chẳng hạn), lật
     * trang sẽ hỏng theo kiểu ca này bắt được mà các ca kia thì không.
     */
    public function test_hai_lan_lat_cung_mot_trang_tra_cung_mot_tap(): void
    {
        $this->dungKho();

        [$lan1] = $this->latHetTrang('/api/v2/screens', 10);
        [$lan2] = $this->latHetTrang('/api/v2/screens', 10);

        $this->assertSame(
            $lan1,
            $lan2,
            'Hai lượt lật cho hai thứ tự khác nhau. Danh sách phải ổn định '
                . 'giữa các lần gọi, nếu không thì trang 2 của lượt này không '
                . 'nối tiếp trang 1 của lượt trước — và người dùng lật trang '
                . 'chính là làm hai lượt gọi.',
        );
    }

    public function test_moi_service_danh_sach_deu_di_qua_mot_cho(): void
    {
        // Chốt tĩnh chống tái phát. Thêm một danh sách mới mà gọi thẳng
        // `paginate()` là mất khoá phụ, và lỗi chỉ lộ ra khi dữ liệu đủ
        // nhiều để có hàng bằng nhau — tức trên production, không phải ở đây.
        $service = [
            'FrontpageService',
            'ProductService',
            'PublicReflectionService',
            'CampaignService',
        ];

        foreach ($service as $ten) {
            $nguon = file_get_contents(app_path("Services/{$ten}.php"));

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
                    . 'sắp xếp.',
            );
        }

        $this->assertStringContainsString(
            'getQualifiedKeyName()',
            file_get_contents(app_path('Support/PhanTrangOnDinh.php')),
            '`phanTrang()` không còn thêm khoá chính làm khoá phụ.',
        );
    }
}
