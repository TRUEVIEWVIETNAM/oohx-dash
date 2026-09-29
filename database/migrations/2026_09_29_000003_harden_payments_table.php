<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Hai lỗ hổng trong bảng thanh toán.
 *
 * 1. **Số hóa đơn có thể trùng.** `generateInvoiceNumber()` đọc số lớn nhất rồi
 *    cộng một, không có ràng buộc duy nhất nào đứng sau. Hai người bấm thanh
 *    toán cùng lúc thì cùng đọc ra một số và cùng ghi được — hai hóa đơn khác
 *    nhau mang cùng một số.
 *
 * 2. **Không có chống trùng.** Bấm nút hai lần là hai khoản thanh toán chờ, và
 *    `breakdownByOwner` cộng cả khoản chờ vào phần "đã trả", nên owner trông
 *    như đã nhận đủ tiền trong khi chưa nhận đồng nào.
 *
 * `idempotency_key` cho phép lần bấm thứ hai tìm lại đúng khoản đã tạo thay vì
 * tạo khoản mới.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        if (! Schema::hasColumn('payments', 'idempotency_key')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('idempotency_key', 191)->nullable()->after('transaction_ref');
                $table->unique('idempotency_key');
            });
        }

        // Số hóa đơn đã trùng sẵn trong dữ liệu cũ thì không thể thêm ràng buộc
        // duy nhất, mà cũng KHÔNG được tự ý sửa số hóa đơn đã phát hành. Trường
        // hợp đó: tạo index thường và ghi cảnh báo, để người vận hành xử lý rồi
        // thêm ràng buộc sau — thà nói thật còn hơn im lặng bỏ qua.
        $duplicates = DB::table('payments')
            ->select('invoice_number')
            ->whereNotNull('invoice_number')
            ->groupBy('invoice_number')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            Log::warning('Không thêm được ràng buộc duy nhất cho payments.invoice_number', [
                'so_gia_tri_bi_trung' => $duplicates,
                'viec_can_lam'        => 'Xử lý số hóa đơn trùng rồi thêm unique index thủ công.',
            ]);

            if (! $this->hasIndex('payments', 'payments_invoice_number_index')) {
                Schema::table('payments', fn (Blueprint $table) => $table->index('invoice_number'));
            }

            return;
        }

        if (! $this->hasIndex('payments', 'payments_invoice_number_unique')) {
            Schema::table('payments', fn (Blueprint $table) => $table->unique('invoice_number'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        if ($this->hasIndex('payments', 'payments_invoice_number_unique')) {
            Schema::table('payments', fn (Blueprint $table) => $table->dropUnique('payments_invoice_number_unique'));
        }

        if (Schema::hasColumn('payments', 'idempotency_key')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index])) > 0;
    }
};
