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
}
