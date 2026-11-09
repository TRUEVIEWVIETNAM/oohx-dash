<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Máy đếm lượt xem trang chi tiết — lát 1 của `docs/PLAN-LUOT-XEM.md`.
 *
 * ══ Đây là một MÁY ĐẾM, không phải một nhật ký hành vi ══
 *
 * Cùng hình dạng, cùng lý do và cùng ranh giới như `do_dac_ngay`: khoá duy
 * nhất `(ngay, screen_id)` làm bảng này **không thể** lớn theo số lượt truy
 * cập. Mỗi ngày sinh ra nhiều nhất là *số màn hình công khai* hàng — hôm nay
 * là 104, nên một năm đầy kín cũng chỉ khoảng 38.000 hàng.
 *
 * | | Bảng này | Nhật ký lượt xem (KHÔNG làm) |
 * |---|---|---|
 * | Lưu gì | `(ngày, màn hình) → số lượt` | một hàng mỗi lần ai đó mở trang |
 * | Định danh ai | **không** — không user_id, không id phiên, không IP | có, kể cả khi "ẩn danh" |
 * | Trả lời được | "vị trí nào được mở nhiều" | "người này đã xem những vị trí nào" |
 *
 * Khác biệt đó quyết định bảng này có phải dữ liệu cá nhân hay không, tức
 * quyết định Nghị định 13/2023/NĐ-CP có áp vào hay không. Migration
 * `create_do_dac_ngay_table` đã viết ra ranh giới ấy và nói trước điều sẽ
 * xảy ra ở đây:
 *
 * > *"ngày nào có người muốn biết **người dùng X đã xem những màn hình nào**,
 * > đó là một bảng khác, một plan khác, có đồng ý của người dùng và có mục
 * > trong chính sách bảo mật. Đừng thêm cột vào đây để làm việc đó."*
 *
 * Bảng này **không phải** bảng đó. Nó là bản gộp theo màn hình, không theo
 * người. Ô *"Vị trí đã xem gần đây"* của trang chủ — thứ duy nhất trong
 * mockup thật sự cần biết **một người** đã xem gì — không đọc bảng này và cố
 * ý không có đường nào để đọc: xem lát 4 của plan, nó nằm trong
 * `localStorage` của từng trình duyệt và không có gì rời khỏi máy người dùng.
 *
 * ══ `so_luot` là SỐ LƯỢT MỞ TRANG, không phải SỐ NGƯỜI ══
 *
 * Máy chủ không biết người gửi là ai — đó là cả điểm của thiết kế trên — nên
 * nó **không thể** gộp hai lượt của cùng một người. Phép gộp duy nhất nằm ở
 * client: `luot-xem.ts` gửi nhiều nhất một lần cho mỗi màn hình trong mỗi
 * phiên trình duyệt (`sessionStorage`). Đó là một phép gộp **thiện chí**, phá
 * được bằng tay, không phải một bảo đảm.
 *
 * Hệ quả phải nói ra, vì nó quyết định cách gắn nhãn con số trên giao diện:
 *
 *  · gọi nó là **"lượt xem"** thì đúng;
 *  · gọi nó là *"người quan tâm"*, *"khách đã xem"* hay bất cứ gì ngụ ý một
 *    con người là **sai**, và sai theo chiều thổi phồng.
 *
 * ══ Vì sao không có khoá chính tự tăng ══
 *
 * Khoá duy nhất hai cột **chính là** danh tính của một hàng, và đường ghi duy
 * nhất là `INSERT … ON DUPLICATE KEY UPDATE` (xem `LuotXemService`). Một cột
 * `id` chỉ thêm 8 byte mỗi hàng và một thứ tự không ai đọc. Cùng lựa chọn,
 * cùng lý do như `do_dac_ngay`.
 *
 * ══ Khoá ngoại xoá kèm ══
 *
 * Xoá một màn hình thì số lượt xem của nó không còn nghĩa gì — không có gì để
 * xếp hạng, không có trang nào để dẫn tới. `cascadeOnDelete` theo đúng tiền lệ
 * `saved_items`. `char(26)` vì khoá chính của `screens` là ULID (CLAUDE.md
 * mục 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('luot_xem_ngay')) {
            return;
        }

        Schema::create('luot_xem_ngay', function (Blueprint $table) {
            $table->date('ngay');
            $table->ulid('screen_id');
            $table->unsignedInteger('so_luot')->default(0);

            // Khoá duy nhất LÀ danh tính của hàng — xem docblock trên. Nó cũng
            // là chỉ mục mà `ON DUPLICATE KEY UPDATE` dùng để tìm hàng cần
            // cộng, nên không có nó thì đường ghi **sai** chứ không chỉ chậm:
            // mỗi lượt thành một hàng mới và bảng thành nhật ký hành vi.
            //
            // `ngay` đứng trước có chủ ý: phép đọc của lát 3 là
            // `WHERE ngay >= ? GROUP BY screen_id` (xếp hạng 30 ngày gần nhất
            // trên toàn kho), và chỉ mục này phục vụ đúng nó. Đảo thứ tự hai
            // cột thì phép đọc đó quét cả bảng.
            $table->unique(['ngay', 'screen_id'], 'luot_xem_ngay_khoa');

            // Phép đọc thứ hai: "màn hình NÀY được mở bao nhiêu lượt trong 30
            // ngày" — trang chi tiết và khu media owner. Chỉ mục trên cũng
            // không phục vụ được nó vì `screen_id` không đứng đầu. Nó cũng là
            // chỉ mục mà khoá ngoại dưới đây cần.
            $table->index(['screen_id', 'ngay'], 'luot_xem_ngay_man_hinh_ngay');

            $table->foreign('screen_id')->references('id')->on('screens')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('luot_xem_ngay');
    }
};
