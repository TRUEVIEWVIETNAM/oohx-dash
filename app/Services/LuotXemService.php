<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Máy đếm lượt xem trang chi tiết — lát 1 của `docs/PLAN-LUOT-XEM.md`.
 *
 * ══ Vì sao nó tồn tại ══
 *
 * Mockup sàn có ba chỗ cần biết một vị trí được xem nhiều hay ít: phép sắp
 * *"Phổ biến nhất"* trên `/explore`, một dòng số trên trang chi tiết, và một ô
 * cho media owner. Hôm nay cả ba đều không dựng được, vì sàn **không có một
 * bản ghi lượt xem nào** — nên trong mockup chúng là ba ô xám ghi "chưa có dữ
 * liệu", và tuỳ chọn sắp xếp bị khoá lại.
 *
 * Thiếu đường đo này thì ba ô đó không bao giờ bật được, và cái thay thế duy
 * nhất là một con số bịa.
 *
 * ══ Đếm, không ghi nhật ký — ranh giới đã có từ `do_dac_ngay` ══
 *
 * Bảng đích gộp theo `(ngày, màn hình)`, không theo người. Nó **không thể**
 * trả lời "người dùng X đã xem những vị trí nào", và đó là một lựa chọn, không
 * phải một thiếu sót: trả lời được câu đó là dữ liệu cá nhân, tức Nghị định
 * 13/2023/NĐ-CP, tức cần đồng ý của người dùng và một mục trong chính sách
 * bảo mật. Xem docblock của migration `create_luot_xem_ngay_table`.
 *
 * ══ `so_luot` KHÔNG phải số người ══
 *
 * Máy chủ không biết người gửi là ai, nên nó không gộp được hai lượt của cùng
 * một người. Phép gộp duy nhất nằm ở client (`webapp/lib/luot-xem.ts`, một
 * lần cho mỗi màn hình trong mỗi phiên trình duyệt) và nó **thiện chí, phá
 * được bằng tay**.
 *
 * Nên nhãn trên giao diện phải là **"lượt xem"**. Bất cứ nhãn nào ngụ ý một
 * con người — "người quan tâm", "khách đã xem" — là sai, và sai theo chiều
 * thổi phồng. Lát 3 phải giữ đúng chữ này.
 *
 * ══ Múi giờ của cột `ngay` ══
 *
 * `config('app.timezone')` là `UTC`, nên `now()->toDateString()` dồn **bảy giờ
 * đầu mỗi ngày ở Việt Nam vào ngày hôm trước**. Với một cửa sổ 30 ngày thì sai
 * số ấy nhỏ; với một ô "hôm nay" thì nó là cả một buổi sáng, mỗi ngày, mãi
 * mãi. Cùng lựa chọn và cùng lý do như `DoDacService::MUI_GIO`, và viết thẳng
 * chuỗi theo tiền lệ sẵn có chứ không đổi `app.timezone` — đổi cấu hình đó là
 * dịch mọi dấu thời gian của cả ứng dụng.
 */
class LuotXemService
{
    /** @see DoDacService::MUI_GIO — cùng lý do, cùng giá trị. */
    public const MUI_GIO = 'Asia/Ho_Chi_Minh';

    /**
     * Cộng một lượt vào ô `(hôm nay, màn hình)`.
     *
     * Một câu SQL, và đó là chủ ý — cùng lý do như `DoDacService::ghiNhan()`.
     * Cách viết hiển nhiên (đọc hàng ra, cộng một, ghi lại) có một khoảng giữa
     * hai lời gọi, và hai người mở cùng một trang trong đúng khoảng ấy làm
     * **mất một lượt**. Với một máy đếm thì mất lượt không gây lỗi nào nhìn
     * thấy được: con số chỉ thấp hơn sự thật, im lặng, và càng đông người càng
     * sai nhiều — tức sai nặng nhất đúng lúc số liệu bắt đầu đáng tin.
     *
     * `ON DUPLICATE KEY UPDATE` để MySQL khoá hàng và cộng trong một lượt, nên
     * không có khoảng nào để rơi vào.
     *
     * **Không** dùng `upsert()` của Laravel: nó sinh `so_luot = VALUES(so_luot)`,
     * tức ghi đè bằng 1 thay vì cộng thêm 1. Mỗi ô sẽ vĩnh viễn bằng 1 và
     * trông hoàn toàn bình thường — mọi vị trí "được xem đúng một lượt", và
     * phép xếp hạng của lát 3 trả về một thứ tự tuỳ ý mà không gì đỏ.
     *
     * Nhận **id** chứ không nhận slug: phép tra "slug nào là màn hình công
     * khai nào" thuộc danh mục, và nó sống ở `FrontpageService::timIdCongKhai()`
     * để dùng chung đúng một định nghĩa với trang chi tiết. Một service nhận
     * slug sẽ phải tự tra, tức bản thứ hai của cùng một luật.
     */
    public function ghiNhan(string $screenId): void
    {
        DB::statement(
            'INSERT INTO luot_xem_ngay (ngay, screen_id, so_luot)'
            . ' VALUES (?, ?, 1)'
            . ' ON DUPLICATE KEY UPDATE so_luot = so_luot + 1',
            [$this->homNay(), $screenId],
        );
    }

    public function homNay(): string
    {
        return Carbon::now(self::MUI_GIO)->toDateString();
    }
}
