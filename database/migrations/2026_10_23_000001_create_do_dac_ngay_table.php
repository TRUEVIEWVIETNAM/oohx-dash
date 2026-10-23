<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Máy đếm theo ngày — lát 1 của `docs/PLAN-DO-DAC-TOI-THIEU.md`.
 *
 * ══ Đây là một MÁY ĐẾM, không phải một nhật ký sự kiện ══
 *
 * Khác biệt nằm ở khoá duy nhất `(ngay, su_kien, gia_tri, thiet_bi)`. Nó làm
 * bảng này **không thể** lớn theo số lượt truy cập: mỗi ngày sinh ra nhiều
 * nhất là `số sự kiện × số giá trị × 2` hàng — hôm nay là 6 × 2 = 12.
 *
 * | | Bảng này | Nhật ký sự kiện (KHÔNG làm) |
 * |---|---|---|
 * | Lưu gì | `(ngày, sự kiện, giá trị, thiết bị) → số lượt` | một hàng mỗi lần xảy ra |
 * | Định danh ai | **không** — không id, không IP, không user agent | có, kể cả khi "ẩn danh" |
 * | Trả lời được | "bao nhiêu phần trăm" | "người này đã làm gì" |
 *
 * Khác biệt đó không phải chuyện kỹ thuật. Một bảng đếm gộp **không chứa dữ
 * liệu cá nhân**, nên câu hỏi "được phép thu không, phải xin đồng ý không,
 * giữ bao lâu" không phát sinh. Một bảng sự kiện thì có, và nó kéo theo Nghị
 * định 13/2023/NĐ-CP — việc cần người có thẩm quyền pháp lý xem.
 *
 * > **Ranh giới phải giữ:** ngày nào có người muốn biết *"người dùng X đã xem
 * > những màn hình nào"*, đó là một bảng khác, một plan khác, có đồng ý của
 * > người dùng và có mục trong chính sách bảo mật. Đừng thêm cột vào đây để
 * > làm việc đó — cấu trúc bảng này là thứ đang giữ lời hứa ấy.
 *
 * ══ Vì sao không có khoá chính tự tăng ══
 *
 * Khoá duy nhất bốn cột **chính là** danh tính của một hàng, và đường ghi duy
 * nhất là `INSERT … ON DUPLICATE KEY UPDATE` (xem `DoDacService`). Một cột
 * `id` chỉ thêm 8 byte mỗi hàng và một thứ tự không ai đọc.
 *
 * ══ `thiet_bi` do client tự khai ══
 *
 * `matchMedia('(pointer: coarse)')`, không suy từ user agent — chuỗi user
 * agent là dữ liệu nhận dạng được, và cả mục đích của bảng này là không giữ
 * thứ như thế. Hệ quả: giá trị này nói "màn hình cảm ứng hay không", không
 * nói "điện thoại hay máy tính". Với câu hỏi đang cần trả lời (tỉ lệ bấm
 * "Chặn" khác nhau rõ rệt giữa hai loại) thì đó là đúng phép chia.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('do_dac_ngay')) {
            return;
        }

        Schema::create('do_dac_ngay', function (Blueprint $table) {
            $table->date('ngay');

            // 40 ký tự: tên sự kiện và giá trị là hằng trong danh sách trắng
            // của `DoDacService`, không phải chuỗi người dùng nhập. Dài nhất
            // hôm nay là `da_chan_tu_truoc` (16).
            $table->string('su_kien', 40);
            $table->string('gia_tri', 40);

            $table->enum('thiet_bi', ['mobile', 'desktop']);

            $table->unsignedInteger('so_luot')->default(0);

            // Khoá duy nhất LÀ danh tính của hàng — xem docblock trên. Nó
            // cũng là chỉ mục mà `ON DUPLICATE KEY UPDATE` dùng để tìm hàng
            // cần cộng, nên không có nó thì đường ghi không chạy đúng chứ
            // không chỉ là chậm.
            $table->unique(['ngay', 'su_kien', 'gia_tri', 'thiet_bi'], 'do_dac_ngay_khoa');

            // Widget lát 3 đọc "30 ngày gần nhất của một sự kiện". Không có
            // chỉ mục này thì nó quét cả bảng — hôm nay là 12 hàng/ngày nên
            // không ai thấy gì, và đó đúng là lúc nên thêm.
            $table->index(['su_kien', 'ngay'], 'do_dac_ngay_su_kien_ngay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('do_dac_ngay');
    }
};
