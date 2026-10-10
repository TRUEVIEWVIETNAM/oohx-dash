<?php

use App\Models\ScreenImport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `screen_imports.status`: `varchar(255)` → enum bảy giá trị.
 *
 * ══ Vì sao siết ══
 *
 * Cột này là máy trạng thái của trình nhập màn hình, bảy bước, ghi từ 16 chỗ
 * trong `app/`. `varchar(255)` nhận **mọi** chuỗi: một lỗi chính tả ở một
 * trong 16 chỗ đó ghi được vào CSDL, và chỗ đọc thì lặng lẽ rơi vào nhánh
 * `default` — nút biến mất, màu thành xám, không ai đỏ.
 *
 * Mọi chỗ ghi đã được nối vào bảy hằng `ScreenImport::STATUS_*` trong cùng
 * lượt này. Enum là tầng chặn thứ hai: hằng chặn lỗi lúc viết mã, enum chặn
 * lỗi lúc ghi — kể cả từ một lệnh SQL chạy tay.
 *
 * Siết xong thì `screen_imports.status` ra khỏi danh sách
 * `COT_KHONG_PHAI_ENUM` của `NhanEnumMotNoiTest` và quay về phép kiểm chuẩn:
 * enum của CSDL đối chiếu hai chiều với `STATUS_LABELS`.
 *
 * ══ Kiểm TRƯỚC khi đổi, và thà đỏ còn hơn sửa dữ liệu ══
 *
 * `up()` đọc các giá trị đang có trước khi `ALTER` một thứ gì. Nếu có giá trị
 * ngoài bảy giá trị kia thì nó **dừng** với thông điệp liệt kê đúng những giá
 * trị đó, và CSDL chưa bị đổi chút nào.
 *
 * Cách khác — map giá trị lạ về `failed` — thì migration luôn chạy được, nhưng
 * nó sửa dữ liệu lịch sử của người khác mà không ai biết (CLAUDE.md §6). Một
 * lần deploy đỏ kèm danh sách giá trị lạ là thứ sửa được bằng tay; một lần ghi
 * đè im lặng thì không lấy lại được.
 *
 * `trap … ERR` trong `deploy.sh` vẫn đưa ứng dụng trở lại online nếu nó đỏ.
 *
 * ══ `down()` ══
 *
 * Trả cột về `varchar(255)`. Không mất gì: mọi giá trị enum đều là chuỗi hợp
 * lệ của `varchar`, nên đi về rồi đi lại cho đúng trạng thái ban đầu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('screen_imports') || ! Schema::hasColumn('screen_imports', 'status')) {
            return;
        }

        if ($this->laEnum()) {
            return; // Đã siết rồi — chạy lại được an toàn (CLAUDE.md §6).
        }

        $hopLe = array_keys(ScreenImport::STATUS_LABELS);

        $la = DB::table('screen_imports')
            ->select('status')
            ->distinct()
            ->whereNotIn('status', $hopLe)
            ->pluck('status')
            ->all();

        if ($la !== []) {
            throw new RuntimeException(
                "Không siết được `screen_imports.status` sang enum: trong CSDL có "
                . count($la) . " giá trị ngoài bảy giá trị đã khai — "
                . implode(', ', array_map(fn ($v) => var_export($v, true), $la))
                . ". Xử lý tay trước (xem `ScreenImport::STATUS_LABELS`), rồi deploy lại. "
                . 'CSDL chưa bị đổi gì.',
            );
        }

        Schema::table('screen_imports', function (Blueprint $table) use ($hopLe) {
            $table->enum('status', $hopLe)->default(ScreenImport::STATUS_UPLOADED)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('screen_imports') || ! $this->laEnum()) {
            return;
        }

        Schema::table('screen_imports', function (Blueprint $table) {
            $table->string('status', 255)->default(ScreenImport::STATUS_UPLOADED)->change();
        });
    }

    private function laEnum(): bool
    {
        $hang = DB::selectOne(
            "SELECT COLUMN_TYPE AS kieu FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'screen_imports' AND COLUMN_NAME = 'status'",
        );

        return $hang !== null && str_starts_with(strtolower((string) $hang->kieu), 'enum(');
    }
};
