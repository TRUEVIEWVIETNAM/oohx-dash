<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giữ chỗ tạm thời cho một màn hình trong một khoảng ngày.
 *
 * Trước thay đổi này **không có khóa nào** trên đường đặt chỗ: hai người mua
 * cùng một suất, gửi booking cùng lúc, thì cả hai đều lọt — `AvailabilityService`
 * chỉ đọc rồi so sánh, không chặn ai. Bảng này là nơi ghi "suất đang có người
 * nhắm", còn việc chặn nằm ở khóa hàng màn hình trong `InventoryHoldService`.
 *
 * `expires_at` để giỏ hàng bỏ dở tự nhả kho (mặc định 30 phút, xem
 * config/pricing.php). Giữ chỗ đã chuyển thành dòng đặt chỗ thì `expires_at`
 * là null — nó không còn là "tạm" nữa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_holds')) {
            return;
        }

        Schema::create('inventory_holds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('screen_id');

            // Nguồn của giữ chỗ: dòng giỏ (tạm) hoặc dòng đặt chỗ (đã chốt).
            //
            // Hai kiểu khóa khác nhau vì hai bảng đánh khóa chính khác nhau:
            // `cart_items` dùng bigint tự tăng, `booking_lines` dùng ULID. Đặt
            // sai kiểu thì MySQL từ chối khóa ngoại với lỗi 3780.
            $table->unsignedBigInteger('cart_item_id')->nullable();
            $table->ulid('booking_line_id')->nullable();

            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedTinyInteger('sov_pct')->default(100);

            // null = không hết hạn (đã chốt đơn).
            $table->timestamp('expires_at')->nullable();

            $table->enum('status', ['active', 'released', 'consumed'])->default('active');

            $table->timestamps();

            $table->foreign('screen_id')->references('id')->on('screens')->cascadeOnDelete();
            $table->foreign('cart_item_id')->references('id')->on('cart_items')->cascadeOnDelete();
            $table->foreign('booking_line_id')->references('id')->on('booking_lines')->cascadeOnDelete();

            // Truy vấn nóng nhất: còn bao nhiêu SOV trên màn hình này trong
            // khoảng ngày này.
            $table->index(['screen_id', 'status', 'start_date', 'end_date'], 'holds_capacity_idx');
            $table->index(['status', 'expires_at']);
            $table->index('cart_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_holds');
    }
};
