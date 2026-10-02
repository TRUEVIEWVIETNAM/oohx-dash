<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giữ lại mốc thời gian GỐC mà thiết bị báo.
 *
 * Máy chủ kẹp `played_at` về biên (muộn tối đa 7 ngày, không nhận tương lai)
 * để lượt phát không rơi vào phân vùng và kỳ báo cáo tùy ý. Nhưng kẹp xong thì
 * mốc gốc biến mất: một lượt phát thật cách đây 60 ngày được ghi là 7 ngày
 * trước, và không còn cách nào biết nó đã bị dịch (Codex R17).
 *
 * Hai cột này để phân biệt "hệ thống tin là lúc nào" với "thiết bị nói là lúc
 * nào" — khi đối soát lệch, đó là chỗ đầu tiên phải nhìn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('impression_logs')) {
            return;
        }

        if (! Schema::hasColumn('impression_logs', 'reported_played_at')) {
            Schema::table('impression_logs', function (Blueprint $table) {
                $table->timestamp('reported_played_at')->nullable()->after('played_at');
                // true khi mốc gốc nằm ngoài cửa sổ cho phép và đã bị kẹp.
                $table->boolean('played_at_clamped')->default(false)->after('reported_played_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('impression_logs') && Schema::hasColumn('impression_logs', 'reported_played_at')) {
            Schema::table('impression_logs', function (Blueprint $table) {
                $table->dropColumn(['reported_played_at', 'played_at_clamped']);
            });
        }
    }
};
