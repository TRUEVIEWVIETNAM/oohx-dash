<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Một gói hàng được mua → nhiều dòng đặt chỗ, cộng một bản chụp thành phần gói.
 *
 * Trước thay đổi này, mua gói 10 màn hình chỉ sinh **một** dòng booking trỏ vào
 * màn hình đầu tiên (`CartService::addProduct` đặt `screen_id` = màn hình đầu).
 * Hệ quả không phải chuyện hình thức:
 *
 * - SOV chỉ bị trừ trên một màn hình, 9 màn còn lại vẫn báo trống và bán tiếp.
 * - Toàn bộ tiền của gói được ghi cho owner của màn hình đầu tiên, các owner
 *   khác không có dòng công nợ nào.
 * - Owner đổi thành phần gói sau khi bán thì đơn cũ trôi theo, vi phạm quy tắc
 *   "booking đã xác nhận giữ nguyên giá và điều khoản".
 *
 * Bảng này giữ bản chụp: gói gồm những màn hình nào, giá bao nhiêu, mua theo
 * kiểu gì — tại đúng thời điểm mua. Đọc lại được dù sản phẩm đã bị sửa hoặc xóa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_line_bundles')) {
            Schema::create('booking_line_bundles', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('campaign_id');

                // nullOnDelete: sản phẩm bị xóa thì bản chụp vẫn phải đọc được,
                // vì nó là bằng chứng của một đơn đã bán.
                $table->ulid('product_id')->nullable();

                $table->enum('buy_mode', ['package', 'individual'])->default('package');

                // Giá của cả gói tại thời điểm mua. Tổng giá các dòng con phải
                // bằng đúng con số này — không được lệch vì làm tròn.
                $table->decimal('price_total', 15, 2)->default(0);

                // Thành phần gói lúc mua: id + tên màn hình, owner, giá niêm yết,
                // phần tiền được chia, và cách chia.
                $table->json('snapshot');

                $table->timestamps();

                $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
                $table->index('campaign_id');
            });
        }

        if (Schema::hasTable('booking_lines') && ! Schema::hasColumn('booking_lines', 'bundle_id')) {
            Schema::table('booking_lines', function (Blueprint $table) {
                $table->ulid('bundle_id')->nullable()->after('product_id');
                $table->foreign('bundle_id')->references('id')->on('booking_line_bundles')->nullOnDelete();
                $table->index('bundle_id');
            });
        }

        // buy_mode ghi thẳng vào giỏ, không suy ra từ việc selected_screen_ids có
        // rỗng hay không: suy đoán thì đúng hôm nay và sai vào ngày ai đó đổi
        // cách lưu danh sách màn hình đã chọn.
        if (Schema::hasTable('cart_items') && ! Schema::hasColumn('cart_items', 'buy_mode')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->enum('buy_mode', ['package', 'individual'])->nullable()->after('product_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('booking_lines') && Schema::hasColumn('booking_lines', 'bundle_id')) {
            Schema::table('booking_lines', function (Blueprint $table) {
                $table->dropForeign(['bundle_id']);
                $table->dropIndex(['bundle_id']);
                $table->dropColumn('bundle_id');
            });
        }

        if (Schema::hasTable('cart_items') && Schema::hasColumn('cart_items', 'buy_mode')) {
            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropColumn('buy_mode');
            });
        }

        Schema::dropIfExists('booking_line_bundles');
    }
};
