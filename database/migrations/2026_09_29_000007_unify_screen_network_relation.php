<?php

use App\Services\Network\NetworkRelationReconciler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Hợp nhất quan hệ Màn hình ↔ Mạng lưới (F-12).
 *
 * Hệ thống đang có **ba** cách nói "màn hình này thuộc mạng lưới nào", và
 * chúng trả lời khác nhau:
 *
 *  A. `sites.network_id` — trang công khai, API và bộ lọc khám phá đọc đường
 *     này (`networks → sites → screens`).
 *  B. `screen_inventory.network_id` — năm chỗ trong Filament (admin lẫn
 *     publisher) đếm và lọc theo đường này.
 *  C. `screens.network_code` — quan hệ `Screen::network()` trỏ tới
 *     `networks.code`. Không code nào GHI cột này; chỉ có test dùng.
 *
 * Hệ quả không phải chuyện hình thức: cùng một mạng lưới, trang quản trị và
 * trang công khai báo hai con số màn hình khác nhau, và không ai biết con số
 * nào đúng. Bảy ca test bị loại khỏi CI từ giai đoạn 0 cũng vì chuyện này.
 *
 * **Chốt: `sites.network_id` là nguồn sự thật duy nhất.** Mạng lưới là một
 * chuỗi địa điểm, còn màn hình nằm tại một địa điểm — nó thừa hưởng mạng lưới
 * của nơi nó đứng. Trình nhập kho cũng đã ghi cột này từ trước.
 *
 * Phần đối chiếu dữ liệu nằm trong `NetworkRelationReconciler`, không viết
 * thẳng ở đây, để **chạy thử được trước khi chạy thật**:
 *
 *     php artisan networks:reconcile --dry-run
 *
 * Nó chỉ điền vào ô trống, không bao giờ ghi đè, và báo cáo chỗ mâu thuẫn để
 * người xem quyết định.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasTable('screens')) {
            return;
        }

        $result = app(NetworkRelationReconciler::class)->run();

        Log::info('F-12 hợp nhất quan hệ màn hình ↔ mạng lưới', $result);

        if (app()->runningInConsole()) {
            echo PHP_EOL . '── F-12: hợp nhất quan hệ Màn hình ↔ Mạng lưới' . PHP_EOL;
            echo "   Địa điểm có mạng lưới: {$result['sites_with_network_before']} → {$result['sites_with_network_after']}" . PHP_EOL;
            echo "   Điền từ screen_inventory: {$result['filled_from_inventory']}" . PHP_EOL;
            echo "   Điền từ screens.network_code: {$result['filled_from_screen_code']}" . PHP_EOL;
            echo '   Còn mâu thuẫn (để nguyên, cần xem tay): ' . count($result['conflicts']) . PHP_EOL;

            foreach (array_slice($result['conflicts'], 0, 10) as $row) {
                echo "     - địa điểm {$row['site_id']}: {$row['site_network_id']} ≠ {$row['inventory_network_id']}" . PHP_EOL;
            }

            if (count($result['conflicts']) > 0) {
                echo '   Xem đầy đủ: php artisan networks:reconcile --dry-run' . PHP_EOL;
            }

            echo PHP_EOL;
        }
    }

    /**
     * Không đảo ngược được, và cố ý không giả vờ là đảo ngược được.
     *
     * Migration chỉ điền vào ô trống; muốn quay lại thì phải biết ô nào vốn
     * trống, mà thông tin đó không được lưu. Khôi phục bằng bản sao lưu.
     */
    public function down(): void
    {
        //
    }
};
