<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sổ nhận sự kiện phát sóng — bảng KHÔNG phân vùng, chỉ để chống trùng.
 *
 * Vì sao phải có bảng riêng: `impression_logs` đã phân vùng theo `played_at`,
 * mà MySQL bắt mọi khóa unique của bảng phân vùng phải chứa cột phân vùng. Nên
 * khóa duy nhất mạnh nhất có thể đặt ở đó là `(screen_id, event_id, played_at)`
 * — và nó **không** chống được trùng khi hai yêu cầu cùng `event_id` mang hai
 * `played_at` lệch nhau vài giây: hai khóa khác nhau nên cả hai đều ghi được,
 * và phép tổng hợp cộng hai lượt (Codex R15).
 *
 * Phép hỏi trước khi ghi ở tầng ứng dụng cũng không đủ: hai yêu cầu song song
 * cùng vượt qua phép hỏi trước khi bên nào kịp chèn.
 *
 * Bảng này giữ đúng một dòng cho mỗi `(screen_id, event_id)` với ràng buộc ở
 * tầng CSDL. Chèn vào đây trước; chèn trùng thì biết chắc là gửi lại.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('impression_events')) {
            return;
        }

        Schema::create('impression_events', function (Blueprint $table) {
            $table->id();
            $table->char('screen_id', 26);
            $table->string('event_id', 64);

            // Trỏ tới bản ghi đã tạo, để lần gửi lại trả về đúng nó.
            $table->char('impression_log_id', 26)->nullable();
            $table->timestamp('played_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['screen_id', 'event_id'], 'impression_events_unique');
            $table->index('created_at');

            // Không đặt khóa ngoại tới impression_logs: bảng đó đã phân vùng
            // nên MySQL không cho tham chiếu tới nó.
            $table->foreign('screen_id')->references('id')->on('screens')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impression_events');
    }
};
