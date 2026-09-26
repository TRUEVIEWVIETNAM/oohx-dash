<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chụp lại giá tại thời điểm thêm vào giỏ.
 *
 * Trước đây khi chuyển giỏ thành chiến dịch, hệ thống đọc lại giá hiện hành của
 * kho. Nếu media owner đổi giá trong lúc giỏ còn nằm đó thì `estimated_cost` là
 * giá cũ còn `io_rate_at_booking` lại là giá mới — hai con số trong cùng một
 * dòng booking mâu thuẫn nhau (audit F-01, Codex R03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            if (! Schema::hasColumn('cart_items', 'rate_captured_at')) {
                $table->timestamp('rate_captured_at')->nullable()->after('unit_price');
            }
            if (! Schema::hasColumn('cart_items', 'rate_snapshot')) {
                $table->json('rate_snapshot')->nullable()->after('rate_captured_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            foreach (['rate_captured_at', 'rate_snapshot'] as $column) {
                if (Schema::hasColumn('cart_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
