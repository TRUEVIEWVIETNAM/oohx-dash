<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng giá có lịch sử, và chiết khấu theo thời lượng.
 *
 * Hai thiếu sót cùng một gốc: bảng giá được coi như một con số hiện tại chứ
 * không phải một văn bản có hiệu lực theo thời gian.
 *
 * 1. **Đổi giá là đổi thẳng, không để lại vết.** Giai đoạn 0 đã đóng băng giá
 *    *trong giỏ*, nhưng không ai trả lời được "ngày 3 tháng 5 màn hình này niêm
 *    yết bao nhiêu" — câu hỏi bắt buộc khi có tranh chấp về một đơn cũ.
 *
 * 2. **12 kỳ đúng bằng 12 lần một kỳ.** Không có chỗ nào khai chiết khấu theo
 *    thời lượng, nên mọi thỏa thuận giảm giá cho hợp đồng dài đều nằm ngoài hệ
 *    thống — nghĩa là nằm trong tin nhắn của người bán.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('screen_rate_versions')) {
            Schema::create('screen_rate_versions', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('screen_id');

                // Trạng thái giá kể từ mốc hiệu lực này. Bảng chỉ ghi thêm,
                // không sửa: một phiên bản đã phát hành là một sự thật lịch sử.
                $table->timestamp('effective_from');

                $table->enum('pricing_model', ['cpm', 'io', 'both'])->nullable();
                $table->decimal('floor_cpm', 15, 2)->nullable();
                $table->decimal('io_rate', 15, 2)->nullable();
                $table->enum('io_rate_unit', ['week', 'month'])->nullable();
                $table->json('duration_discounts')->nullable();

                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->foreign('screen_id')->references('id')->on('screens')->cascadeOnDelete();
                $table->index(['screen_id', 'effective_from']);
            });
        }

        // Bậc chiết khấu theo số kỳ thuê, ví dụ:
        // [{"min_units":3,"discount_pct":5},{"min_units":12,"discount_pct":15}]
        if (Schema::hasTable('screen_inventory') && ! Schema::hasColumn('screen_inventory', 'duration_discounts')) {
            Schema::table('screen_inventory', function (Blueprint $table) {
                $table->json('duration_discounts')->nullable()->after('io_rate_unit');
            });
        }

        // Ghi lại mức chiết khấu đã áp, để hóa đơn giải thích được con số.
        foreach (['cart_items', 'booking_lines'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'duration_discount_pct')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedTinyInteger('duration_discount_pct')->default(0);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['cart_items', 'booking_lines'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'duration_discount_pct')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('duration_discount_pct'));
            }
        }

        if (Schema::hasTable('screen_inventory') && Schema::hasColumn('screen_inventory', 'duration_discounts')) {
            Schema::table('screen_inventory', fn (Blueprint $table) => $table->dropColumn('duration_discounts'));
        }

        Schema::dropIfExists('screen_rate_versions');
    }
};
