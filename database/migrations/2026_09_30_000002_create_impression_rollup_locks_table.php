<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Khóa theo NGÀY cho phép tổng hợp lượt phát.
 *
 * Phép tổng hợp thay thế toàn bộ dữ liệu của một ngày (xóa rồi ghi lại). Hai
 * lần chạy cùng ngày mà không xếp hàng thì lần chạy dùng **ảnh chụp cũ** sẽ xóa
 * cả những nhóm mà lần chạy kia vừa tạo — báo cáo bị lùi dữ liệu, và phải có
 * một lần tổng hợp nữa mới sửa lại (Codex R35).
 *
 * `withoutOverlapping` của scheduler không đủ: lệnh `impressions:rollup` nhận
 * `--date/--from/--to` nên người vận hành gọi tay được, và lần gọi tay đó không
 * đi qua cùng mutex với lịch định kỳ.
 *
 * Một hàng cho mỗi ngày, chỉ dùng làm mốc khóa — không mang dữ liệu nghiệp vụ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('impression_rollup_locks')) {
            return;
        }

        Schema::create('impression_rollup_locks', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impression_rollup_locks');
    }
};
