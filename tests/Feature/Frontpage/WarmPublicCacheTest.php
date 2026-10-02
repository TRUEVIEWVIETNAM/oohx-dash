<?php

namespace Tests\Feature\Frontpage;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * `oohx:warm-cache` — dựng sẵn số liệu tổng hợp sau khi deploy xoá cache.
 *
 * Hai tính chất phải giữ, và tính chất thứ hai quan trọng hơn:
 *
 *  1. Chạy xong thì **thật sự có cache**, không chỉ in ra dấu tích.
 *  2. Một aggregate hỏng **không được làm deploy đỏ**. `deploy.sh` dùng
 *     `set -e`, nên một exit code khác 0 ở bước hâm cache sẽ dừng deploy giữa
 *     chừng và để ứng dụng nằm trong chế độ bảo trì. Hậu quả của hâm cache
 *     hỏng chỉ nên là trang đầu chậm.
 */
class WarmPublicCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function publicScreen(): Screen
    {
        $owner  = Owner::factory()->create(['status' => 'active']);
        $site   = Site::factory()->create(['owner_id' => $owner->id, 'city' => 'Hà Nội', 'status' => 'active']);
        $screen = Screen::factory()->create(['owner_id' => $owner->id, 'site_id' => $site->id, 'active' => true]);

        ScreenSpec::factory()->create(['screen_id' => $screen->id, 'width_cm' => 400, 'height_cm' => 200]);
        ScreenInventory::create([
            'screen_id'              => $screen->id,
            'pricing_model'          => 'io',
            'io_rate'                => 1_000_000,
            'io_rate_unit'           => 'month',
            'floor_cpm'              => 50_000,
            'spot_length'            => 15,
            'share_of_voice_max_pct' => 100,
        ]);

        return $screen->fresh(['inventory', 'spec', 'site']);
    }

    public function test_chay_xong_thi_cache_co_that(): void
    {
        $this->publicScreen();

        // Đúng những khóa mà trang chủ và trang khám phá đọc. Kiểm cache có
        // thật, không kiểm lệnh in ra gì — một lệnh in "✓" mà không ghi cache
        // thì vẫn để khách đầu tiên chịu trận.
        foreach (['fp:hero_stats', 'fp:filters', 'fp:venue_types', 'fp:top_cities', 'fp:product_filters'] as $key) {
            $this->assertFalse(Cache::has($key), "Khóa {$key} đáng ra phải trống trước khi hâm.");
        }

        $this->artisan('oohx:warm-cache')->assertExitCode(0);

        foreach (['fp:hero_stats', 'fp:filters', 'fp:venue_types', 'fp:top_cities', 'fp:product_filters'] as $key) {
            $this->assertTrue(Cache::has($key), "Hâm cache xong mà khóa {$key} vẫn trống.");
        }
    }

    public function test_mot_phan_hong_khong_lam_deploy_do(): void
    {
        $this->publicScreen();

        // Dựng hỏng hóc bằng cách thay service trong container, KHÔNG bằng
        // `DROP TABLE`: lệnh DDL trong MySQL **tự commit ngầm**, nên
        // `RefreshDatabase` không rollback được và bảng sẽ biến mất với mọi
        // test chạy sau. Một phép đo làm hỏng bộ test thì không đáng chạy.
        $this->mock(ProductService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getFilterAggregates')
                ->andThrow(new RuntimeException('giả lập aggregate hỏng'));
            $mock->shouldReceive('getFeaturedProducts')->andReturn(collect());
        });

        // `deploy.sh` dùng `set -e`. Exit code khác 0 ở đây sẽ dừng deploy và
        // để ứng dụng kẹt trong chế độ bảo trì — tệ hơn nhiều so với một trang
        // đầu chậm.
        $this->artisan('oohx:warm-cache')->assertExitCode(0);

        // Và những phần KHÔNG hỏng vẫn phải được dựng: một lỗi không được kéo
        // theo cả lượt hâm.
        $this->assertTrue(Cache::has('fp:hero_stats'));
        $this->assertTrue(Cache::has('fp:filters'));
    }
}
