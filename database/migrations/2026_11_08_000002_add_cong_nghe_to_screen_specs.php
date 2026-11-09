<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Công nghệ màn hình — lát 1 của `docs/PLAN-CONG-NGHE-MAN-HINH.md`.
 *
 * ══ Vì sao cột này tồn tại ══
 *
 * Trước hôm nay sàn **không có một trường nào ghi công nghệ màn hình**. Người
 * mua OOH hỏi "LED hay in bạt" trước cả khi hỏi giá, và câu trả lời duy nhất
 * sàn có là `screen_type` — một giá trị **suy ra** từ kích thước và loại điểm
 * đặt, tức trả lời một câu hỏi khác:
 *
 * | Câu hỏi | Trường trả lời được |
 * |---|---|
 * | Biển lớn hay màn hình nhỏ? | kích thước — **đo được**, đã có |
 * | Ở ngoài đường hay trong nhà? | `vn_category_id` — **khai**, đã có |
 * | LED, LCD hay in bạt? | **không có gì** — cột này |
 *
 * Phép suy ấy gộp hai câu đầu rồi gọi kết quả là "loại hình", nên nó không thể
 * đúng cho một màn LED ngoài trời cỡ lớn: `OOHX_LED_CTO_CAN_THO` là LED nhưng
 * vượt ngưỡng kích thước nên mang nhãn "Billboard", còn 19 vị trí
 * `OOHX_LED_<tỉnh>` ra "LED" **chỉ vì chúng chưa khai kích thước** — khai đúng
 * thì chúng cũng thành "Billboard" và loại hình LED về 0 lần nữa.
 *
 * Xem docblock `SuyRaLoaiManHinh::resolveScreenType()` cho cả lịch sử.
 *
 * ══ `null` là "chưa khai", và nó phải khác được với mọi câu trả lời ══
 *
 * Cột **nullable không mặc định**. Cả 104 bản ghi hiện có bắt đầu từ `null`, và
 * đó là câu trả lời đúng cho chúng: sàn chưa hỏi media owner câu này bao giờ.
 *
 * Đặt một mặc định — kể cả `'led'`, kể cả vì "phần lớn là LED" — là biến một
 * khoảng trống thành một lời khẳng định về 104 vị trí mà không ai kiểm. Đó
 * đúng là loại lỗi `screen_type` đang mắc, và cả cột này tồn tại để thôi mắc
 * nó.
 *
 * ══ Ba giá trị, không hơn ══
 *
 * `led`, `lcd`, `in_bat` — ba thứ thật sự có trên thị trường OOH Việt Nam và
 * chủ dự án đã chốt ngày 08/11/2026. Thêm giá trị thứ tư (`hop_den` chẳng hạn)
 * là một migration một dòng, nên không cần đoán trước.
 *
 * Là enum thật của MySQL, không phải `varchar`: `NhanEnumMotNoiTest` tự tìm mọi
 * hằng `<CỘT>_LABELS` trên model rồi đối chiếu với **định nghĩa enum trong
 * CSDL**. Để `varchar` thì bảng chữ không còn gì đối chiếu, và cột phải khai
 * vào `COT_KHONG_PHAI_ENUM` kèm lý do — một ngoại lệ không cần thiết.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('screen_specs', 'cong_nghe')) {
            return;
        }

        Schema::table('screen_specs', function (Blueprint $table) {
            $table->enum('cong_nghe', ['led', 'lcd', 'in_bat'])
                ->nullable()
                ->after('height_cm');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('screen_specs', 'cong_nghe')) {
            return;
        }

        Schema::table('screen_specs', function (Blueprint $table) {
            $table->dropColumn('cong_nghe');
        });
    }
};
