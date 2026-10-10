<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `owner_users.role`: đổi tên vai trò `scheduler` thành `operator`.
 *
 * ══ Vì sao đổi ══
 *
 * Tên `scheduler` hứa việc lên lịch phát, nhưng bộ quyền của nó ở
 * `OwnerUser::PERMISSIONS` chỉ có `manage_inventory`, `import_inventory` và
 * `view_inventory` — không có `manage_pricing`, không `view_reports`, không
 * `manage_bookings`. Nó là vai trò vận hành kho điểm phát, và tên cũ nói sai
 * điều đó ở cả hai phía: mã trong code và chữ trong thư mời.
 *
 * PR #60 dịch chữ nhưng giữ nghĩa của mã (`scheduler` → "Lập lịch") và ghi
 * chỗ lệch đó vào docblock thay vì tự quyết. Đây là lần quyết: đổi tên.
 *
 * ══ Đây là một lần ĐỔI TÊN, nên nó phải đi về được ══
 *
 * `down()` map đúng một chiều ngược `operator` → `scheduler` và **không đụng
 * tới năm vai trò kia**. Migration `2025_01_01_000013` (lần đổi bộ vai trò
 * trước) thì không như vậy: `down()` của nó gộp `scheduler`, `sales_manager`
 * và `manager` về cùng `manager`, nên rollback xong thì không còn biết ai từng
 * là gì. Mất thông tin trong một lần rollback là mất **quyền**, không phải mất
 * một nhãn: một người từng chỉ sửa được kho bỗng thành `manager` có quyền sửa
 * giá.
 *
 * Có một ca test đi cả hai chiều với dữ liệu thật của cả sáu vai trò —
 * `tests/Feature/DoiTenVaiTroOperatorTest.php`.
 *
 * ══ Lời mời đang treo cũng mang mã vai trò ══
 *
 * `user_invitations.role` là `varchar(32)`, không phải enum, nên CSDL không
 * chặn gì — một lời mời chưa ai nhận mà còn mang `scheduler` sẽ đi qua
 * `UserInvitationService::accept()` và ghi thẳng vào pivot, nơi enum mới từ
 * chối. Người được mời thấy một lỗi không liên quan gì tới họ. Nên map luôn,
 * và chỉ map lời mời **chưa nhận**: hàng đã nhận là lịch sử, không sửa
 * (CLAUDE.md §6).
 */
return new class extends Migration
{
    private const CU  = 'scheduler';
    private const MOI = 'operator';

    /** Thứ tự enum giữ nguyên vị trí cũ của `scheduler` — quyền giảm dần. */
    private const VAI_TRO_MOI = [
        'owner',
        'manager',
        'operator',
        'read_only',
        'reporting_only',
        'sales_manager',
    ];

    private const VAI_TRO_CU = [
        'owner',
        'manager',
        'scheduler',
        'read_only',
        'reporting_only',
        'sales_manager',
    ];

    public function up(): void
    {
        $this->doiTen(self::CU, self::MOI, self::VAI_TRO_MOI);
    }

    public function down(): void
    {
        $this->doiTen(self::MOI, self::CU, self::VAI_TRO_CU);
    }

    /**
     * Đổi một giá trị enum, giữ nguyên mọi giá trị khác.
     *
     * MySQL không `ALTER` được một giá trị enum khi cột đang có dữ liệu mang
     * giá trị đó, nên phải đi qua `varchar` tạm — cùng cách
     * `2025_01_01_000013` đã làm. Chạy lại được an toàn: nếu enum đã mang
     * `$den` thì không làm gì (CLAUDE.md §6).
     */
    private function doiTen(string $tu, string $den, array $enumMoi): void
    {
        if (! Schema::hasTable('owner_users') || ! Schema::hasColumn('owner_users', 'role')) {
            return;
        }

        $kieu = $this->kieuCot('owner_users', 'role');

        // Đã đổi rồi (hoặc cột không còn là enum) → không làm gì.
        if ($kieu === null || ! str_contains($kieu, "'{$tu}'")) {
            return;
        }

        Schema::table('owner_users', function (Blueprint $table) {
            $table->string('role_tmp', 30)->nullable()->after('role');
        });

        DB::table('owner_users')->update(['role_tmp' => DB::raw('role')]);
        DB::table('owner_users')->where('role_tmp', $tu)->update(['role_tmp' => $den]);

        Schema::table('owner_users', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('owner_users', function (Blueprint $table) use ($enumMoi) {
            $table->enum('role', $enumMoi)->default('read_only')->after('role_tmp');
        });

        DB::table('owner_users')->update(['role' => DB::raw('role_tmp')]);

        Schema::table('owner_users', function (Blueprint $table) {
            $table->dropColumn('role_tmp');
        });

        // Lời mời CHƯA nhận. Hàng đã nhận là lịch sử.
        if (Schema::hasTable('user_invitations')) {
            DB::table('user_invitations')
                ->where('role', $tu)
                ->whereNull('accepted_at')
                ->update(['role' => $den]);
        }
    }

    private function kieuCot(string $bang, string $cot): ?string
    {
        $hang = DB::selectOne(
            'SELECT COLUMN_TYPE AS kieu FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$bang, $cot],
        );

        return $hang?->kieu;
    }
};
