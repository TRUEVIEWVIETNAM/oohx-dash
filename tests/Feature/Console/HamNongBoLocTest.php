<?php

namespace Tests\Feature\Console;

use App\Models\Owner;
use App\Models\Screen;
use App\Models\ScreenInventory;
use App\Models\ScreenSpec;
use App\Models\Site;
use App\Services\FrontpageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `oohx:ham-nong-bo-loc` — tính trước bộ lọc `/explore` để không người dùng
 * nào phải trả phép tính.
 *
 * Bộ lọc cache 1800 giây, nên không có lệnh này thì **cứ 30 phút một người
 * dùng thật là người tính**: đo 23/10/2026 trên 50.000 bản ghi giả là 987ms
 * cho ca thường xuyên (khoá `fp:filters` hết hạn, cache con còn nóng).
 *
 * Ca quan trọng nhất ở đây là `test_sau_ham_nong_loi_goi_cua_nguoi_dung_khong_cham_csdl`:
 * nó đo đúng điều lệnh này hứa, chứ không đo việc lệnh chạy xong không lỗi.
 */
class HamNongBoLocTest extends TestCase
{
    use RefreshDatabase;

    private function dungKho(): void
    {
        $owner = Owner::factory()->create(['status' => 'active']);

        foreach (range(1, 3) as $i) {
            $site = Site::factory()->create([
                'owner_id' => $owner->id,
                'city'     => 'Hà Nội',
                'status'   => 'active',
                'lat'      => 21.0 + $i / 100,
                'lon'      => 105.8 + $i / 100,
            ]);

            $screen = Screen::factory()->create([
                'owner_id' => $owner->id,
                'site_id'  => $site->id,
                'active'   => true,
            ]);

            ScreenSpec::factory()->create([
                'screen_id' => $screen->id,
                'width_cm'  => 400,
                'height_cm' => 200,
            ]);

            ScreenInventory::create([
                'screen_id'               => $screen->id,
                'venue_type'              => 'outdoor',
                'spot_length'             => 15,
                'share_of_voice_max_pct'  => 100,
            ]);
        }
    }

    public function test_ham_nong_dien_cache_vao_dung_khoa(): void
    {
        $this->dungKho();
        Cache::forget(FrontpageService::KHOA_BO_LOC);

        $this->artisan('oohx:ham-nong-bo-loc')->assertSuccessful();

        $boLoc = Cache::get(FrontpageService::KHOA_BO_LOC);

        $this->assertIsArray($boLoc);
        $this->assertArrayHasKey('cities', $boLoc);
        $this->assertArrayHasKey('screen_types', $boLoc);
    }

    /**
     * Đây là điều lệnh hứa, và cũng là điều duy nhất đáng đo.
     *
     * Một lệnh chạy xong không lỗi mà cache vẫn nguội thì vô dụng — và nó
     * **trông như đang chạy**, vì lệnh in ra một dòng thành công. Nên ca này
     * đếm truy vấn của lời gọi tiếp theo thay vì xem mã trả về.
     */
    public function test_sau_ham_nong_loi_goi_cua_nguoi_dung_khong_cham_csdl(): void
    {
        $this->dungKho();
        Cache::flush();

        $this->artisan('oohx:ham-nong-bo-loc')->assertSuccessful();

        $dem = 0;
        DB::listen(function () use (&$dem) {
            $dem++;
        });

        app(FrontpageService::class)->getFilterAggregates();

        $this->assertSame(
            0,
            $dem,
            'Người dùng vẫn phải chạy truy vấn sau khi hâm nóng — cache không tới đúng khoá, '
            . 'hoặc thời hạn đặt sai.',
        );
    }

    public function test_ham_nong_thay_gia_tri_cu_chu_khong_bo_qua(): void
    {
        $this->dungKho();

        // Giá trị cũ còn hạn: lệnh phải ghi đè, không được thấy "cache còn
        // hạn" rồi bỏ qua. `Cache::remember` sẽ bỏ qua, nên nếu ai đó đổi
        // lệnh sang dùng `remember` thì ca này đỏ.
        Cache::put(FrontpageService::KHOA_BO_LOC, ['dau_vet' => true], 3600);

        $this->artisan('oohx:ham-nong-bo-loc')->assertSuccessful();

        $boLoc = Cache::get(FrontpageService::KHOA_BO_LOC);

        $this->assertArrayNotHasKey('dau_vet', $boLoc, 'Lệnh thấy cache còn hạn rồi bỏ qua.');
        $this->assertArrayHasKey('cities', $boLoc);
    }

    /**
     * Lệnh **không** được `forget` rồi tính.
     *
     * Cách viết hiển nhiên là `Cache::forget()` rồi gọi
     * `getFilterAggregates()` cho nó tính lại. Giữa hai lời gọi đó có một khe
     * hở gần một giây với cache rỗng, và người dùng rơi vào đúng khe ấy phải
     * trả cả phép tính — tức lệnh hâm nóng tự tạo ra chính thứ nó sinh ra để
     * tránh, đều đặn theo lịch, mãi mãi.
     *
     * Ca này đọc **mã nguồn**, và đó là một lựa chọn có ý thức: khe hở ấy là
     * một tính chất về *thứ tự* trong một tiến trình, nên dựng lại nó bằng
     * test cần hai tiến trình chạy song song và một phép tính đủ chậm để bắt
     * kịp — tốn hơn nhiều so với câu trả lời thu được. Có tiền lệ trong bộ
     * test này: `LoaiHinhNhanKhopBoLocTest::test_api_v1_giu_nguyen_hanh_vi_cu`
     * cũng đọc mã nguồn vì cùng loại lý do.
     *
     * ══ `php_strip_whitespace`, không `file_get_contents` ══
     *
     * Bản đầu đọc tệp thô, và một lần đột biến (23/10/2026) cho thấy nó **xanh
     * một cách rỗng**: đổi `Cache::put` thành `Cache::remember` làm lời gọi
     * biến khỏi mã, nhưng khẳng định vẫn xanh vì chuỗi `Cache::put` còn nằm
     * trong docblock của chính lệnh. Một chốt đọc mã mà chú thích thoả mãn
     * được thì nó canh chú thích, không canh mã.
     *
     * Gỡ chú thích cũng cho docblock của lệnh được viết bằng tên hàm thật thay
     * vì diễn đạt vòng quanh để né chốt.
     */
    public function test_lenh_khong_xoa_cache_truoc_khi_tinh(): void
    {
        $ma = php_strip_whitespace(app_path('Console/Commands/HamNongBoLoc.php'));

        $this->assertStringNotContainsString(
            'Cache::forget',
            $ma,
            'Lệnh xoá cache trước khi tính — nó tự tạo ra khe hở cache nguội mà nó sinh ra để tránh.',
        );

        $this->assertStringContainsString(
            'Cache::put',
            $ma,
            'Lệnh không ghi cache bằng `Cache::put` — xem docblock về việc vì sao không dùng `remember`.',
        );

        $this->assertStringContainsString(
            'tinhBoLoc()',
            $ma,
            'Lệnh gọi đường có cache thay vì `tinhBoLoc()`, nên cache còn hạn sẽ làm nó không tính gì cả.',
        );
    }

    public function test_lich_chay_co_ham_nong_va_dut_duoi_thoi_han_cache(): void
    {
        $lich = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('oohx:ham-nong-bo-loc', $lich, 'Lệnh không có trong lịch chạy nào.');

        // Chu kỳ phải **ngắn hơn** thời hạn cache, nếu không thì giữa hai lượt
        // chạy vẫn có khoảng cache nguội và người dùng lại là người tính.
        // 15 phút cho thời hạn 1800 giây: chạy đúng 30 phút thì một lượt
        // trượt là một khoảng nguội, nửa thời hạn cho phép bỏ hẳn một lượt.
        $this->assertStringContainsString('everyFifteenMinutes', $lich);
        $this->assertSame(1800, FrontpageService::HAN_BO_LOC);
    }
}
