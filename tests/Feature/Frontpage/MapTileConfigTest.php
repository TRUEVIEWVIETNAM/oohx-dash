<?php

namespace Tests\Feature\Frontpage;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Chín view bản đồ của Filament phải biên dịch được với `config/map.php`.
 *
 * ══ Vì sao cần, dù `tests/js/leaflet.test.mjs` đã canh nguồn tile ══
 *
 * Chốt JS đọc **văn bản** của các view và khẳng định không chỗ nào cắm thẳng
 * URL. Nó không biết `{{ config('map.tile_url') }}` có biên dịch được hay
 * không — và một dấu nháy đặt sai trong Blade là lỗi lúc render, tức lúc một
 * người mở trang quản trị, chứ không phải lúc chạy test.
 *
 * Đổi nguồn tile ngày 18/10/2026 sửa cùng một dòng ở chín tệp bằng script.
 * Đây là phép kiểm rằng cả chín vẫn dựng được.
 */
class MapTileConfigTest extends TestCase
{
    /** Chín view Filament có bản đồ Leaflet. */
    private const VIEW_BAN_DO = [
        'filament.components.screen-map',
        'filament.components.site-map',
        'filament.forms.components.map-picker',
        'filament.forms.components.screen-map-picker',
        'filament.modals.network-screens-map',
        'filament.publisher.components.headquarters-map',
        'filament.resources.network-resource.pages.view-network',
        'filament.resources.poi-snapshot-resource.map-and-pois',
        'filament.shared.components.screen-detail-modal',
    ];

    public function test_config_ban_do_co_du_khoa(): void
    {
        foreach ([
            'map.tile_url',
            'map.tile_subdomains',
            'map.tile_attribution',
            'map.tile_fallback_url',
            'map.tile_fallback_attribution',
            'map.tile_max_zoom',
        ] as $khoa) {
            $this->assertNotNull(config($khoa), "Thiếu khoá cấu hình `{$khoa}`.");
        }

        // URL tile phải có đủ ba chỗ thay — thiếu một cái thì Leaflet xin
        // cùng một ô ảnh cho mọi vị trí, và bản đồ hiện một ô lặp đi lặp lại.
        foreach (['{z}', '{x}', '{y}'] as $phan) {
            $this->assertStringContainsString($phan, config('map.tile_url'));
        }
    }

    public function test_chin_view_ban_do_deu_bien_dich_duoc(): void
    {
        foreach (self::VIEW_BAN_DO as $view) {
            $this->assertTrue(view()->exists($view), "Không tìm thấy view `{$view}`.");

            // `Blade::compileString()` dựng PHP từ template rồi `eval` kiểm cú
            // pháp — không render, nên không cần dữ liệu giả cho từng view.
            $php = Blade::compileString(file_get_contents(
                view()->getFinder()->find($view),
            ));

            $loi = null;

            try {
                // `eval` với mã đã bọc trong `return function(){}` chỉ phân
                // tích cú pháp, không chạy thân hàm.
                eval('return static function () { ?>' . $php . '<?php };');
            } catch (\ParseError $e) {
                $loi = $e->getMessage();
            }

            $this->assertNull($loi, "View `{$view}` không biên dịch được: {$loi}");
        }
    }

    public function test_khong_view_nao_con_cam_thang_nguon_tile(): void
    {
        foreach (self::VIEW_BAN_DO as $view) {
            $noiDung = file_get_contents(view()->getFinder()->find($view));

            $this->assertStringNotContainsString(
                'tile.openstreetmap.org',
                $noiDung,
                "View `{$view}` còn cắm thẳng nguồn tile cũ — nguồn sống ở `config/map.php`.",
            );

            $this->assertStringContainsString(
                "config('map.tile_url')",
                $noiDung,
                "View `{$view}` không đọc nguồn tile từ cấu hình.",
            );
        }
    }

    /**
     * Hai nguồn đã thử và loại, không được quay lại bằng một lần sửa config.
     *
     * Cả hai đều hỏng theo kiểu khó thấy, nên cả hai đều đáng một dòng ở đây:
     *
     *  - `tile.openstreetmap.org`: hạ tầng cộng đồng, trả 503 từ mạng Việt
     *    Nam, và chính sách của OSM không cho dùng cho ứng dụng thương mại.
     *  - `basemaps.cartocdn.com`: không có API key thì trả HTTP 200 kèm ảnh
     *    "API KEY REQUIRED" — xanh với mọi phép kiểm trừ con mắt.
     *
     * Chốt này chỉ canh tên miền. Thứ canh được "nguồn có trả bản đồ thật
     * không" là `php artisan map:kiem-tile`, vì phép đó cần gọi mạng.
     */
    public function test_khong_quay_lai_hai_nguon_da_loai(): void
    {
        foreach (['tile_url', 'tile_fallback_url'] as $khoa) {
            foreach ([
                'tile.openstreetmap.org' => 'hạ tầng cộng đồng, 503 từ Việt Nam',
                'cartocdn.com' => 'trả watermark "API KEY REQUIRED" kèm HTTP 200',
            ] as $mien => $lyDo) {
                $this->assertStringNotContainsString(
                    $mien,
                    (string) config("map.{$khoa}"),
                    "`map.{$khoa}` quay lại `{$mien}` — đã loại 18/10/2026 vì {$lyDo}.",
                );
            }
        }
    }
    /**
     * Nạp lại `config/map.php` với một môi trường khác.
     *
     * `require` thẳng tệp config thay vì đọc `config('map.*')`: lời gọi kia
     * trả về giá trị đã chốt lúc framework khởi động, nên nó không nói được
     * gì về **luật chọn** — thứ duy nhất đáng kiểm ở đây.
     */
    private function nap(array $moiTruong): array
    {
        $cu = [];

        foreach ($moiTruong as $k => $v) {
            $cu[$k] = $_ENV[$k] ?? null;
            $_ENV[$k] = $v;
        }

        try {
            return require config_path('map.php');
        } finally {
            foreach ($cu as $k => $v) {
                if ($v === null) {
                    unset($_ENV[$k]);
                } else {
                    $_ENV[$k] = $v;
                }
            }
        }
    }

    public function test_co_khoa_thi_dung_maptiler(): void
    {
        $cfg = $this->nap(['MAPTILER_KEY' => 'khoa-gia-de-test']);

        $this->assertStringContainsString('api.maptiler.com', $cfg['tile_url']);
        $this->assertStringContainsString('streets-v2', $cfg['tile_url']);

        // `{key}` phải còn nguyên trong URL: Leaflet là bên thay nó, bằng
        // option `key` mà chín view truyền vào. Nhúng thẳng khóa vào URL ở
        // đây sẽ đem khóa vào `config:cache` trên đĩa máy chủ.
        $this->assertStringContainsString('{key}', $cfg['tile_url']);
        $this->assertSame('khoa-gia-de-test', $cfg['tile_key']);

        $this->assertStringContainsString('MapTiler', $cfg['tile_attribution']);
    }

    /**
     * Style rỗng phải lùi về mặc định, không được cho ra `maps//{z}/…`.
     *
     * Chuyện thật, 18/10/2026. `deploy.sh` export
     * `NEXT_PUBLIC_MAPTILER_STYLE` từ một secret chưa đặt, nên nó là chuỗi
     * rỗng. Phía Next dùng `?? 'streets-v2'`, mà `??` chỉ bắt `undefined` —
     * chuỗi rỗng đi thẳng vào URL: `api.maptiler.com/maps//13/6504/3605.png`.
     * MapTiler trả 503 cho mọi ô.
     *
     * Không ai thấy, vì dự phòng Esri nhảy vào và bản đồ vẫn có nền. Phải mở
     * tab Network mới biết MapTiler chưa hề chạy — hình dạng lỗi thứ tư
     * trong một ngày: báo thành công, làm việc khác.
     *
     * Phía PHP có đúng cái bẫy đó với `env('X', 'mặc định')`, nên ca này
     * canh cả hai.
     */
    public function test_style_rong_phai_lui_ve_mac_dinh(): void
    {
        // Chỉ chuỗi rỗng, không thử chuỗi toàn khoảng trắng: Dotenv cắt
        // khoảng trắng của giá trị không đặt trong nháy, nên một `.env` thật
        // không sinh ra trường hợp đó — và một chốt canh thứ không xảy ra là
        // một chốt bắt mã phức tạp thêm mà không đổi lấy gì.
        foreach ([''] as $rong) {
            $cfg = $this->nap([
                'MAPTILER_KEY' => 'khoa-gia-de-test',
                'MAPTILER_STYLE' => $rong,
            ]);

            $this->assertStringNotContainsString(
                'maps//',
                $cfg['tile_url'],
                'Style rỗng cho ra URL hai dấu gạch chéo — MapTiler trả 503 mọi ô.',
            );

            $this->assertStringContainsString('streets-v2', $cfg['tile_url']);
        }
    }

    public function test_style_dat_thi_dung_style_do(): void
    {
        $cfg = $this->nap([
            'MAPTILER_KEY' => 'khoa-gia-de-test',
            'MAPTILER_STYLE' => 'oohx-tieng-viet',
        ]);

        $this->assertStringContainsString('maps/oohx-tieng-viet/', $cfg['tile_url']);
    }

    public function test_khong_khoa_thi_lui_ve_esri(): void
    {
        $cfg = $this->nap(['MAPTILER_KEY' => '']);

        // Đây là thứ giữ cho máy lập trình, CI và một khóa gõ sai ở
        // production đều có bản đồ thay vì nền xám.
        $this->assertStringContainsString('arcgisonline.com', $cfg['tile_url']);
        $this->assertStringNotContainsString('{key}', $cfg['tile_url']);
        $this->assertSame('', $cfg['tile_key']);

        $this->assertStringContainsString('Esri', $cfg['tile_attribution']);
        $this->assertStringNotContainsString('MapTiler', $cfg['tile_attribution']);
    }

    public function test_du_phong_luon_la_nguon_khong_can_khoa(): void
    {
        // Dự phòng mà cũng cần khóa thì nó vô dụng đúng vào lúc cần nhất:
        // khi hạn mức MapTiler cạn giữa tháng.
        foreach ([['MAPTILER_KEY' => 'co-khoa'], ['MAPTILER_KEY' => '']] as $mt) {
            $cfg = $this->nap($mt);

            $this->assertStringContainsString('arcgisonline.com', $cfg['tile_fallback_url']);
            $this->assertStringNotContainsString('{key}', $cfg['tile_fallback_url']);
        }
    }

    public function test_chin_view_deu_truyen_key_cho_leaflet(): void
    {
        foreach (self::VIEW_BAN_DO as $view) {
            $noiDung = file_get_contents(view()->getFinder()->find($view));

            // Thiếu dòng này thì Leaflet gửi nguyên chữ `{key}` cho MapTiler
            // và nhận về 403 — bản đồ trắng, không một dòng log.
            $this->assertStringContainsString(
                "key: '{{ config('map.tile_key') }}'",
                $noiDung,
                "View `{$view}` không truyền `key` cho Leaflet, nên `{key}` "
                . 'trong URL tile sẽ không được thay.',
            );
        }
    }
}
