<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nghĩa vụ hoàn tiền khi khách hủy một dòng đặt chỗ.
 *
 * **Sàn không giữ tiền** — người mua chuyển thẳng cho từng media owner. Nên
 * bảng này ghi *nghĩa vụ*, không phải *giao dịch*: nó nói owner nào phải trả
 * lại bao nhiêu cho ai, theo mốc chính sách nào, và tiền đã trả lại chưa. Việc
 * chuyển tiền diễn ra ngoài hệ thống, đúng như luồng thu tiền hiện tại.
 *
 * Trước thay đổi này, enum trạng thái có `cancelled` nhưng không có đường nào
 * đi tới: khách muốn hủy thì không ai xử lý được, và không có chỗ nào ghi lại
 * khách được hoàn bao nhiêu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('refunds')) {
            return;
        }

        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('campaign_id');
            $table->ulid('booking_line_id')->nullable();
            $table->ulid('owner_id')->nullable();
            $table->ulid('organization_id');

            // Số tiền người mua đã trả cho phần này, và phần được hoàn lại.
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedTinyInteger('refund_pct')->default(0);

            // Chụp lại căn cứ tính, để sau này đổi chính sách thì hồ sơ cũ vẫn
            // giải thích được vì sao ra con số đó.
            $table->unsignedSmallInteger('days_before_start')->default(0);
            $table->json('policy_snapshot')->nullable();

            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'settled', 'waived'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->foreign('booking_line_id')->references('id')->on('booking_lines')->nullOnDelete();
            $table->foreign('owner_id')->references('id')->on('owners')->nullOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();

            $table->index(['campaign_id', 'status']);
            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
