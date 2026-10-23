<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Máy đếm `/explore` — lát 1 của `docs/PLAN-DO-DAC-TOI-THIEU.md`.
 *
 * ══ Vì sao nó tồn tại ══
 *
 * Ngày 22/10/2026 `/explore` bắt đầu **xin quyền vị trí khi mở trang** (#159).
 * Khi thuyết phục rằng cách hỏi đó chấp nhận được, có ba lưới an toàn được
 * hứa; lưới thứ ba là *"đếm tỉ lệ bấm Chặn"*. Hai lưới đầu đã làm, lưới thứ
 * ba thì không — app không có một đường đo đạc nào.
 *
 * Nên tính năng đang chạy trên production và **không ai biết nó tốn bao
 * nhiêu**:
 *
 *  · hỏi khi mở trang mà **90% bấm Chặn** → đã mất tính năng với 90% người
 *    dùng **vĩnh viễn**, vì trình duyệt nhớ cú chặn cho tới khi họ tự vào cài
 *    đặt site mà gỡ;
 *  · hỏi khi mở trang mà **70% cho phép** → quyết định đúng, không cần bàn lại.
 *
 * Hai thế giới đó khác nhau hoàn toàn, và hôm nay chúng trông giống hệt nhau.
 *
 * ══ Danh sách trắng là LỚP CHẶN, không phải phép kiểm lịch sự ══
 *
 * `POST /api/v2/do-dac` là một **đường ghi mở cho người lạ**, không đăng
 * nhập. Không có danh sách trắng thì bất cứ ai cũng dựng được vài triệu hàng
 * rác, và khi đó bảng mất đúng tính chất đang giữ nó lành mạnh: nó không còn
 * nhỏ, và không con số nào trong đó còn đọc được.
 *
 * Danh sách là **cặp** `sự kiện → các giá trị của nó`, không phải hai danh
 * sách rời. `cho_phep` chỉ có nghĩa dưới `quyen_vi_tri`; khi lát 4 thêm sự
 * kiện khác, giá trị của nó cũng chỉ có nghĩa dưới sự kiện đó. Hai danh sách
 * rời sẽ nhận `('doi_kieu_xem', 'da_chan_tu_truoc')` — một hàng vô nghĩa mà
 * không gì đỏ.
 *
 * ══ Năm kết quả, và cái khó nằm ở một trong số đó ══
 *
 * | Giá trị | Nghĩa |
 * |---|---|
 * | `hoi` | **mẫu số** — mỗi lần thật sự gọi `getCurrentPosition` ở nhánh có thể bật hộp thoại |
 * | `cho_phep` | người dùng bấm "Cho phép" |
 * | `tu_choi` | bấm "Chặn" |
 * | `da_chan_tu_truoc` | trình duyệt đã nhớ "Chặn" từ phiên trước — **không** hộp thoại nào bật |
 * | `loi` | hết thời gian chờ, không lấy được vị trí |
 * | `chua_tra_loi` | hộp thoại bật ra và người dùng **bỏ qua** |
 *
 * `chua_tra_loi` là một phép **suy đoán**, không phải phép đo: trình duyệt
 * không báo gì khi người dùng bấm ra ngoài hộp thoại. Tên trường nói đúng
 * điều đó. Người mở trang rồi chuyển tab cũng rơi vào ô này.
 *
 * Nó cũng là ô quan trọng nhất, vì nó là trạng thái **duy nhất còn cứu
 * được**: `tu_choi` thì trình duyệt đã khoá, `chua_tra_loi` thì lần sau vẫn
 * hỏi lại được — và nếu ô này lớn, câu trả lời không phải "bỏ tính năng" mà
 * là "hỏi ở thời điểm khác".
 *
 * Mẫu số là `hoi`, **không** phải số lượt mở `/explore`: phần lớn lượt mở
 * không hỏi gì cả, vì lưới an toàn số 1 chỉ hỏi một lần cho mỗi trình duyệt.
 */
class DoDacService
{
    public const SU_KIEN_QUYEN_VI_TRI = 'quyen_vi_tri';

    /**
     * Danh sách trắng: sự kiện → các giá trị hợp lệ của riêng nó.
     *
     * Thêm một dòng ở đây là thêm một thứ được đếm. Đừng nới nó cho một lần
     * thử nghiệm rồi quên thu lại.
     */
    public const CHO_PHEP = [
        self::SU_KIEN_QUYEN_VI_TRI => [
            'hoi',
            'cho_phep',
            'tu_choi',
            'da_chan_tu_truoc',
            'loi',
            'chua_tra_loi',
        ],
    ];

    public const THIET_BI = ['mobile', 'desktop'];

    /**
     * Múi giờ của cột `ngay`.
     *
     * `config('app.timezone')` là `UTC`, nên `now()->toDateString()` sẽ dồn
     * **bảy giờ đầu mỗi ngày ở Việt Nam vào ngày hôm trước** — tức widget
     * "hôm nay" thiếu hẳn buổi sáng, đều đặn, mãi mãi. Người đọc những con số
     * này ở Việt Nam, nên "ngày" phải là ngày Việt Nam.
     *
     * Viết thẳng chuỗi theo tiền lệ sẵn có trong repo (`Screen::354`,
     * `ScreenWriter::123`) chứ không đổi `app.timezone`: đổi cấu hình đó là
     * dịch mọi dấu thời gian của cả ứng dụng, một thay đổi lớn hơn nhiều so
     * với câu hỏi đang cần trả lời.
     */
    public const MUI_GIO = 'Asia/Ho_Chi_Minh';

    public function hopLe(string $suKien, string $giaTri, string $thietBi): bool
    {
        return in_array($thietBi, self::THIET_BI, true)
            && in_array($giaTri, self::CHO_PHEP[$suKien] ?? [], true);
    }

    /**
     * Cộng một lượt vào ô `(hôm nay, sự kiện, giá trị, thiết bị)`.
     *
     * Một câu SQL, và đó là chủ ý. Cách viết hiển nhiên — đọc hàng ra, cộng
     * một, ghi lại — có một khoảng giữa hai lời gọi, và hai người dùng rơi
     * vào đúng khoảng ấy làm **mất một lượt**. Với một máy đếm thì mất lượt
     * không gây lỗi nào nhìn thấy được: con số chỉ thấp hơn sự thật, im lặng,
     * và càng đông người càng sai nhiều — tức sai nặng nhất đúng lúc số liệu
     * bắt đầu đáng tin.
     *
     * `ON DUPLICATE KEY UPDATE` để MySQL khoá hàng và cộng trong một lượt,
     * nên không có khoảng nào để rơi vào.
     *
     * Không dùng `upsert()` của Laravel: nó sinh `so_luot = VALUES(so_luot)`,
     * tức **ghi đè bằng 1** thay vì cộng thêm 1. Mỗi ô sẽ vĩnh viễn bằng 1 và
     * trông hoàn toàn bình thường.
     */
    public function ghiNhan(string $suKien, string $giaTri, string $thietBi): void
    {
        DB::statement(
            'INSERT INTO do_dac_ngay (ngay, su_kien, gia_tri, thiet_bi, so_luot)'
            . ' VALUES (?, ?, ?, ?, 1)'
            . ' ON DUPLICATE KEY UPDATE so_luot = so_luot + 1',
            [$this->homNay(), $suKien, $giaTri, $thietBi],
        );
    }

    public function homNay(): string
    {
        return Carbon::now(self::MUI_GIO)->toDateString();
    }
}
