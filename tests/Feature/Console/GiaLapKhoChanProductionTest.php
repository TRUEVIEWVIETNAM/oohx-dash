<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `oohx:gia-lap-kho` gieo hàng chục nghìn bản ghi. `.env` của dự án trỏ vào
 * CSDL **production**, nên lệnh này là một lệnh phá hoại nếu một biến môi
 * trường bị gõ sai hoặc một `bootstrap/cache/config.php` cũ còn nằm đó.
 *
 * Ba lớp chặn, và tệp này canh cả ba. Không lớp nào là một câu hỏi "có chắc
 * không" cho người gõ — câu hỏi đó người ta bấm Enter qua, và ở đây cái giá
 * của một lần bấm Enter sai là kho thật.
 *
 * Ca quan trọng nhất là `test_ten_csdl_production_bi_chan_du_host_cuc_bo`:
 * một đường hầm SSH hay một dòng trong `/etc/hosts` đủ để làm `DB_HOST` trông
 * như cục bộ, nên chặn theo host một mình là chưa đủ.
 */
class GiaLapKhoChanProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_khong_cuc_bo_bi_chan(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', '172.104.188.62');

        $this->artisan('oohx:gia-lap-kho', ['so' => 5])
            ->expectsOutputToContain('không phải CSDL cục bộ')
            ->assertFailed();

        $this->assertSame(0, DB::table('screens')->count());
    }

    public function test_ten_csdl_production_bi_chan_du_host_cuc_bo(): void
    {
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.host', '127.0.0.1');
        Config::set('database.connections.mysql.database', 'dash_oohx_net');

        $this->artisan('oohx:gia-lap-kho', ['so' => 5])
            ->expectsOutputToContain('tên CSDL production')
            ->assertFailed();

        $this->assertSame(0, DB::table('screens')->count());
    }

    public function test_moi_truong_production_bi_chan(): void
    {
        // Lớp này đứng trước cả hai lớp trên: kể cả khi host và tên CSDL đều
        // trông vô hại, `APP_ENV=production` là đủ để dừng.
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('oohx:gia-lap-kho', ['so' => 5])
            ->expectsOutputToContain('APP_ENV=production')
            ->assertFailed();

        $this->assertSame(0, DB::table('screens')->count());
    }

    public function test_csdl_tam_duoc_phep_va_gieo_that(): void
    {
        // Mặt còn lại: nếu danh sách trắng chặn luôn cả CSDL tạm thì lệnh vô
        // dụng, và một chốt chặn vô dụng sẽ bị ai đó gỡ.
        $this->artisan('oohx:gia-lap-kho', ['so' => 3, '--chu' => 1])
            ->assertSuccessful();

        $this->assertSame(3, DB::table('screens')->count());
        $this->assertSame(3, DB::table('screen_inventory')->count());
    }

    /**
     * Toạ độ phải **dồn cục**, không rải đều.
     *
     * Lý do nằm trong chính mục đích của lệnh: một phân bố đều cho số đo lạc
     * quan sai — mọi ô lưới có số đếm gần như nhau nên gom cụm trông dễ, và
     * mọi khung nhìn cắt theo tỉ lệ diện tích nên lọc trông chọn lọc tốt. Kho
     * OOH thật dồn hơn nửa vào hai thành phố, và đúng chỗ đó mới là chỗ đau.
     */
    public function test_toa_do_don_cuc_quanh_do_thi(): void
    {
        $this->artisan('oohx:gia-lap-kho', ['so' => 400, '--chu' => 2])->assertSuccessful();

        $quanhHaNoiVaSaiGon = DB::table('sites')
            ->where(function ($q) {
                $q->whereBetween('lat', [20.8, 21.3])->whereBetween('lon', [105.6, 106.1]);
            })
            ->orWhere(function ($q) {
                $q->whereBetween('lat', [10.5, 11.1])->whereBetween('lon', [106.3, 106.9]);
            })
            ->count();

        // Trọng số đặt 30 + 28 = 58% vào hai thành phố này. Ngưỡng 0,40 để một
        // lượt gieo may rủi không làm đỏ, nhưng phân bố đều trên hình chữ nhật
        // Việt Nam thì không có cách nào đạt — hai ô này chỉ chiếm vài phần
        // trăm diện tích.
        $this->assertGreaterThan(
            0.40,
            $quanhHaNoiVaSaiGon / DB::table('sites')->count(),
            'Toạ độ rải quá đều — số đo lát 0 sẽ lạc quan sai.'
        );
    }
}
