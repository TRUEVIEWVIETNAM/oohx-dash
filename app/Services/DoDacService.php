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
     * Những kết quả chia được cho mẫu số `hoi`.
     *
     * Tức mọi giá trị của `quyen_vi_tri` **trừ** `hoi` (chính mẫu số) và
     * `da_chan_tu_truoc` (nhánh không có hộp thoại nào, nên không có một lượt
     * `hoi` nào đi kèm — xem `tongHopQuyenViTri()`).
     *
     * Viết tay thay vì `array_diff` vì hằng số PHP không gọi hàm được. Nên có
     * một chốt khẳng định hai danh sách này hợp lại đúng bằng
     * `CHO_PHEP[SU_KIEN_QUYEN_VI_TRI]`: thêm một giá trị ở trên mà quên phân
     * loại nó vào đây thì giá trị đó biến mất khỏi widget **im lặng**, và
     * tổng các tỉ lệ sẽ hụt mà không ai biết vì sao.
     */
    public const KET_QUA_TRONG_MAU_SO = ['cho_phep', 'tu_choi', 'chua_tra_loi', 'loi'];

    /** Mẫu số, và hai giá trị không phải một kết quả đi sau nó. */
    public const NGOAI_MAU_SO = ['hoi', 'da_chan_tu_truoc'];

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

    /** Cửa sổ của bản tổng hợp trên `/admin`, tính cả hôm nay. */
    public const SO_NGAY_TONG_HOP = 30;

    /**
     * Dưới mức này thì không in tỉ lệ nào.
     *
     * Plan đòi điều này, và lý do đáng nhắc lại: một tỉ lệ tính trên 7 lượt
     * **vẫn trông như một tỉ lệ**, nên nó sẽ được mang đi quyết định có bỏ
     * tính năng hay không. Ô này thà nói "chưa đủ số liệu" còn hơn nói
     * "43%" khi 43% nghĩa là 3 trên 7.
     */
    public const NGUONG_DU_LIEU = 100;

    /**
     * Lỗi đầu tiên của một bộ ba, hoặc `null` nếu hợp lệ.
     *
     * Trả về `[tên trường, thông báo]` chứ không phải `bool`: phía gọi cần
     * nói cho người gửi biết **trường nào** sai, và một `false` trơ không
     * nói được điều đó. Nếu trả `bool` thì `GhiDoDacRequest` sẽ phải tự tra
     * lại danh sách để đoán ra trường — tức viết luật lần thứ hai, và hai
     * bản luật sẽ lệch (CLAUDE.md mục 1). Bản đầu của lát này đúng là như
     * thế, và hệ quả là hàm kiểm của service thành mã chết không ai gọi.
     *
     * ══ Vì sao có tham số `$choPhep` ══
     *
     * Chỉ để **chốt tiêm được một bảng giả**. Danh sách thật hôm nay có đúng
     * một sự kiện, nên không ca nào dựng nổi bộ ba *(sự kiện hợp lệ, giá trị
     * thuộc sự kiện khác)* — đúng cái tính chất "theo cặp" này tồn tại để
     * bảo vệ.
     *
     * Điều đó không phải suy đoán: ngày 23/10/2026 một lần đột biến nới phép
     * kiểm từ theo-cặp sang kiểm-rời (`array_merge` mọi giá trị) và **không
     * ca nào đỏ**, 12/12 vẫn xanh. Lỗ sẽ mở ra thật khi lát 4 thêm sự kiện
     * thứ hai, tức đúng lúc không ai còn nhớ chỗ này.
     *
     * Tham số mặc định `null`, nên production không bao giờ truyền gì vào và
     * không có đường nào nới danh sách từ bên ngoài.
     *
     * @param  array<string, list<string>>|null  $choPhep
     * @return array{0: string, 1: string}|null
     */
    public function loiDauTien(
        string $suKien,
        string $giaTri,
        string $thietBi,
        ?array $choPhep = null,
    ): ?array {
        $choPhep ??= self::CHO_PHEP;

        if (! array_key_exists($suKien, $choPhep)) {
            return ['su_kien', 'Sự kiện không nằm trong danh sách được đếm.'];
        }

        if (! in_array($thietBi, self::THIET_BI, true)) {
            return ['thiet_bi', 'Loại thiết bị không hợp lệ.'];
        }

        // Tra trong danh sách CỦA RIÊNG sự kiện đó, không phải trong một
        // bảng gộp mọi giá trị: `cho_phep` chỉ có nghĩa dưới `quyen_vi_tri`.
        if (! in_array($giaTri, $choPhep[$suKien], true)) {
            return ['gia_tri', 'Giá trị không thuộc sự kiện `' . $suKien . '`.'];
        }

        return null;
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

    /**
     * Bản tổng hợp quyền vị trí cho ô trên `/admin` — lát 3.
     *
     * ══ `hoi` là mẫu số, và `da_chan_tu_truoc` KHÔNG nằm trong nó ══
     *
     * Đây là chỗ dễ sai im lặng nhất của cả lát này. `hoi` được ghi *mỗi lần
     * thật sự gọi `getCurrentPosition` ở nhánh có thể bật hộp thoại*. Nhánh
     * `da_chan_tu_truoc` là nhánh `permissions.query` trả `denied` — client
     * **không** gọi `getCurrentPosition`, nên **không** ghi `hoi` nào.
     *
     * Hệ quả: `da_chan_tu_truoc / hoi` là một phân số không có nghĩa, và nó
     * **vượt 100% được** mà không gì đỏ. Nên nó ra ngoài `thiet_bi` như một
     * số đếm tuyệt đối, không phải một tỉ lệ.
     *
     * Bốn kết quả chia cho `hoi` là `cho_phep`, `tu_choi`, `chua_tra_loi`,
     * `loi` — đúng những nhánh đi sau một lời gọi đã đếm.
     *
     * ══ Vì sao có `tong_ket_qua` ══
     *
     * Nó là số đếm bốn kết quả trên, để so với `hoi`. Lệch nhiều nghĩa là
     * client sai, và plan lát 2 đã gọi tên đúng cái sai đó: *"hẹn giờ
     * `chua_tra_loi` phải huỷ được, nếu không một callback về chậm sẽ ghi cả
     * `chua_tra_loi` lẫn `cho_phep` cho cùng một lượt"*. Khi bug ấy xảy ra
     * trên production thì tỉ lệ vẫn trông hợp lý từng cái một; chỉ tổng của
     * chúng là vô lý. Đây là đường duy nhất nhìn ra nó từ xa.
     *
     * Cửa sổ tính theo giờ Việt Nam, cùng lý do như `homNay()`: lấy mốc theo
     * UTC thì bảy giờ đầu mỗi ngày VN nằm ngoài cửa sổ.
     *
     * @return array{
     *     tu_ngay: string,
     *     so_ngay: int,
     *     nguong: int,
     *     hoi: int,
     *     da_chan_tu_truoc: int,
     *     thiet_bi: array<string, array{hoi: int, du_lieu: bool, tong_ket_qua: int, so_luot: array<string, int>, ti_le: array<string, float>}>
     * }
     */
    public function tongHopQuyenViTri(): array
    {
        // `- 1` vì cửa sổ tính cả hôm nay: 30 ngày là hôm nay cộng 29 ngày
        // trước, không phải hôm nay cộng 30.
        $tuNgay = Carbon::now(self::MUI_GIO)
            ->subDays(self::SO_NGAY_TONG_HOP - 1)
            ->toDateString();

        $hang = DB::table('do_dac_ngay')
            ->select('thiet_bi', 'gia_tri', DB::raw('SUM(so_luot) AS tong'))
            ->where('su_kien', self::SU_KIEN_QUYEN_VI_TRI)
            ->where('ngay', '>=', $tuNgay)
            ->groupBy('thiet_bi', 'gia_tri')
            ->get();

        $gop = [];
        foreach ($hang as $h) {
            $gop[(string) $h->thiet_bi][(string) $h->gia_tri] = (int) $h->tong;
        }

        $theoThietBi = [];
        $tongHoi = 0;
        $tongDaChan = 0;

        foreach (self::THIET_BI as $thietBi) {
            $cua = $gop[$thietBi] ?? [];
            $hoi = $cua['hoi'] ?? 0;
            $tongHoi += $hoi;
            $tongDaChan += $cua['da_chan_tu_truoc'] ?? 0;

            $soLuot = [];
            foreach (self::KET_QUA_TRONG_MAU_SO as $ketQua) {
                $soLuot[$ketQua] = $cua[$ketQua] ?? 0;
            }

            $tiLe = [];
            foreach ($soLuot as $ketQua => $so) {
                // `$hoi === 0` đi vào đây được: `du_lieu` chặn widget in tỉ
                // lệ, nhưng nó không chặn mảng này được tính.
                $tiLe[$ketQua] = $hoi > 0 ? round($so / $hoi * 100, 1) : 0.0;
            }

            $theoThietBi[$thietBi] = [
                'hoi' => $hoi,
                'du_lieu' => $hoi >= self::NGUONG_DU_LIEU,
                'tong_ket_qua' => array_sum($soLuot),
                'so_luot' => $soLuot,
                'ti_le' => $tiLe,
            ];
        }

        return [
            'tu_ngay' => $tuNgay,
            'so_ngay' => self::SO_NGAY_TONG_HOP,
            'nguong' => self::NGUONG_DU_LIEU,
            'hoi' => $tongHoi,
            'da_chan_tu_truoc' => $tongDaChan,
            'thiet_bi' => $theoThietBi,
        ];
    }
}
