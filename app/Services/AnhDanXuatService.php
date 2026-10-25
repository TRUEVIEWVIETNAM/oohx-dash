<?php

namespace App\Services;

use App\Models\ScreenSpec;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Bản **dẫn xuất** của một ảnh: cùng ảnh, hẹp hơn, cho `srcset`.
 *
 * ══ Vì sao lớp này tồn tại ══
 *
 * `ScreenCard` và `ThuVienAnh` đều trỏ `<img src>` thẳng vào tệp đã tải lên.
 * Tới trước lát 2 điều đó vô hại: cả kho 104 vị trí hiện ra bằng đúng **hai**
 * tấm ảnh bìa, cả hai đã nhẹ, và Cloudflare giữ chúng. Sau lát 2 thì mỗi thẻ
 * là một ảnh khác nhau, và một trang 24 thẻ là 24 ảnh gốc — mỗi ảnh tới 8MB,
 * cho một ô cao 180px.
 *
 * ══ Vì sao sinh sẵn, không cắt theo yêu cầu ══
 *
 * Một đường `/anh?w=480&src=…` là một dịch vụ cắt ảnh, và `ScreenCard.tsx` đã
 * ghi lý do không mở đường đó: *"không mở `remotePatterns` để khỏi thành đường
 * tải ảnh hộ"*. Sinh sẵn lúc ghi thì đường công khai vẫn chỉ là tệp tĩnh, và
 * Cloudflare giữ nó như mọi tệp tĩnh khác.
 *
 * ══ Vì sao bản dẫn xuất nằm NGOÀI `screen-photos/` ══
 *
 * Lát 0 (`SoatAnhKhoService::doDia()`) đi bộ qua `screen-photos` và đếm tệp để
 * trả lời "kho có bao nhiêu ảnh, nặng bao nhiêu". Đặt bản dẫn xuất vào trong
 * đó thì con số ấy **gấp ba** và báo cáo nói sai: 300 ảnh cho 100 tấm.
 *
 * Thư mục riêng còn đóng hai chuyện nữa:
 *
 *  - **Không trùng tên.** `foo.jpg` và `foo-w480.jpg` cùng thư mục thì một ảnh
 *    người ta đặt tên sẵn là `foo-w480.jpg` sinh ra `foo-w480-w480.jpg`, và
 *    không phép kiểm nào phân biệt được bản gốc với bản dẫn xuất.
 *  - **Dựng lại được.** `rm -r anh-dan-xuat` rồi chạy lại lệnh là về trạng
 *    thái sạch, không chạm một bản gốc nào.
 *
 * ══ Vì sao srcset KHÔNG có bản gốc ══
 *
 * Mỗi mục trong `srcset` phải khai bề rộng thật của nó. Bề rộng của bản gốc
 * chỉ biết được bằng cách mở tệp ra đo — mỗi ảnh, mỗi lượt yêu cầu. Nên
 * `srcset` chỉ liệt những bản mà chính tên thư mục đã nói bề rộng, và `src`
 * vẫn là bản gốc cho trình duyệt không hiểu `srcset`.
 *
 * Hệ quả, nói thẳng: một ảnh gốc 600px chỉ có bản `w480`, nên màn hình rộng
 * nhận 480px thay vì 600px có sẵn. Đổi 600 thành 480 là một mất mát nhỏ đo
 * được; đổi lấy nó là **không bao giờ** phóng to một ảnh nhỏ — đúng bài học
 * `imageResizeUpscale(false)` của lát 2, lần này ở phía máy chủ.
 *
 * ══ Vì sao chạy đồng bộ, không qua queue ══
 *
 * `QUEUE_CONNECTION=database`, và `deploy.sh` có `queue:restart || true` —
 * nhưng không có cách nào từ đây xác nhận trên production đang có một worker
 * sống. Đẩy việc sinh ảnh vào hàng đợi mà không có worker là một tính năng
 * **trông như** đã chạy: biểu mẫu lưu xong, không lỗi nào, và bản dẫn xuất
 * không bao giờ xuất hiện. Đúng thứ `CLAUDE.md` mục 8 cấm.
 *
 * Giá của việc chạy đồng bộ đo được: 10 ảnh × 2 bề rộng, mỗi lượt resize một
 * ảnh ≤ 2000px (lát 2 đã co ở trình duyệt) là vài chục ms — dưới một giây cho
 * một lượt lưu biểu mẫu quản trị.
 */
class AnhDanXuatService
{
    /** Thư mục gốc của mọi bản dẫn xuất, trên disk `public`. */
    public const THU_MUC = 'anh-dan-xuat';

    /**
     * Hai bề rộng, và chỉ hai.
     *
     * `480` cho thẻ danh sách (`.sc-photo` cao 180–190px, rộng tối đa ~1/3
     * khung ở màn hình lớn). `1280` cho ảnh lớn của trang chi tiết và cho thẻ
     * trên màn hình mật độ 2×. Thêm bậc thứ ba là thêm một tệp cho mỗi ảnh
     * trong kho mà không đóng một khoảng nào còn hở.
     *
     * Thứ tự tăng dần, và `srcsetCho()` dựa vào đó.
     */
    public const BE_RONG = [480, 1280];

    /**
     * Trần số điểm ảnh của **bản gốc** mà máy chủ chịu mở ra.
     *
     * Đây là chỗ đóng lỗ hổng mà lát 2 ghi lại và để mở: một tệp 8MB vẫn có
     * thể là ảnh 20.000×20.000. Tới lát 2 không mã nào phía máy chủ giải nén
     * những ảnh ấy nên nó chưa gây hại. Lớp này **là** mã giải nén, nên trần
     * phải ở đây.
     *
     * Phép tính: GD giữ ảnh ở truecolor, 4 byte một điểm ảnh. 12 triệu điểm
     * ≈ 48MB cho bản gốc, cộng bản `w1280` (~1,1 triệu điểm) và 30% chừa cho
     * GD là ~68MB. Vừa trong `memory_limit` 128M khi tiến trình còn trống, và
     * ở trên cả hai cỡ ảnh thật của hệ thống: 4 triệu điểm (2000×2000) mà
     * đường tải ảnh của lát 2 sinh ra, và 12 triệu điểm của một ảnh điện thoại
     * 4000×3000.
     *
     * Trần cố định này **không thay** cho phép kiểm bộ nhớ còn lại: trên một
     * tiến trình đã dùng 90MB thì 68MB không còn, và `quaLon()` từ chối.
     *
     * Quá trần thì **bỏ qua và nói ra**, không phải cố rồi chết: một lượt OOM
     * của PHP là lỗi tử (`Allowed memory size exhausted`) không `catch` được,
     * nên nó giết luôn lượt lưu biểu mẫu của người dùng.
     */
    public const TOI_DA_PIXEL = 12_000_000;

    /** Chất lượng nén cho JPEG và WebP. 82 là chỗ mắt thường thôi thấy khác. */
    private const CHAT_LUONG = 82;

    // ── Kết quả cho một ảnh ────────────────────────────────────────────────

    public const KQ_DA_SINH    = 'da_sinh';
    public const KQ_DA_CO      = 'da_co';
    public const KQ_GOC_NHO    = 'goc_nho';
    public const KQ_URL_NGOAI  = 'url_ngoai';
    public const KQ_THIEU_TEP  = 'thieu_tep';
    public const KQ_QUA_LON    = 'qua_lon';
    public const KQ_KHONG_DOC  = 'khong_doc_duoc';
    public const KQ_KHONG_GD   = 'khong_co_gd';
    public const KQ_DUONG_SAI  = 'duong_sai';

    public const NHAN_KQ = [
        self::KQ_DA_SINH   => 'Đã sinh bản dẫn xuất',
        self::KQ_DA_CO     => 'Đã có đủ, không làm gì',
        self::KQ_GOC_NHO   => 'Bản gốc nhỏ hơn mọi bề rộng — không phóng to',
        self::KQ_URL_NGOAI => 'URL ngoài — không có tệp để mở',
        self::KQ_THIEU_TEP => 'Tệp không còn trên đĩa',
        self::KQ_QUA_LON   => 'Ảnh quá lớn so với trần — bỏ qua',
        self::KQ_KHONG_DOC => 'Mở không ra ảnh',
        self::KQ_KHONG_GD  => 'Máy chủ không có GD cho định dạng này',
        self::KQ_DUONG_SAI => 'Đường dẫn không dùng được',
    ];

    /** @var array<int, int> `IMAGETYPE_*` → hằng `IMG_*` của GD */
    private const LOAI_GD = [
        IMAGETYPE_JPEG => IMG_JPG,
        IMAGETYPE_PNG  => IMG_PNG,
        IMAGETYPE_WEBP => IMG_WEBP,
    ];

    public function __construct(
        /**
         * Trần điểm ảnh, tiêm vào được để chốt đo được **cả hai chiều** của
         * phép kiểm. Dựng một ảnh 12 triệu điểm trong một ca test là tốn đúng
         * số bộ nhớ mà phép kiểm sinh ra để tránh.
         */
        private readonly int $toiDaPixel = self::TOI_DA_PIXEL,
    ) {
    }

    // ══ Phần thuần: đường dẫn và srcset ═══════════════════════════════════

    /**
     * Đường dẫn bản dẫn xuất của một ảnh, hoặc `null` khi không có.
     *
     * Trả `null` — chứ không ném — cho mọi thứ không phải một đường dẫn tương
     * đối trong disk công khai, vì giá trị vào đây đến từ cột `photos` của
     * CSDL và cột đó nhận cả URL đầy đủ (xem `ScreenSpec::photoUrlOf()`).
     *
     * Phép kiểm `..` không phải phòng xa: cột này nhận giá trị từ đường nhập
     * kho, và một `../../` ở đây là một lượt **ghi** ra ngoài
     * `storage/app/public`.
     */
    public static function duongDanXuat(?string $duongGoc, int $beRong): ?string
    {
        if (! in_array($beRong, self::BE_RONG, true)) {
            return null;
        }

        $duong = trim((string) $duongGoc);

        if ($duong === '') {
            return null;
        }

        // URL đầy đủ: tệp nằm ở máy chủ người khác, không có gì để mở.
        if (str_starts_with($duong, 'http://') || str_starts_with($duong, 'https://')) {
            return null;
        }

        // Đường dẫn tuyệt đối, hoặc đi ngược lên — cả hai ra ngoài disk.
        if (str_starts_with($duong, '/') || str_contains($duong, '..') || str_contains($duong, "\0")) {
            return null;
        }

        // Không sinh bản dẫn xuất của một bản dẫn xuất.
        if (str_starts_with($duong, self::THU_MUC . '/')) {
            return null;
        }

        return self::THU_MUC . '/w' . $beRong . '/' . $duong;
    }

    /**
     * Giá trị cho thuộc tính `srcset`, hoặc `null` khi chưa có bản nào.
     *
     * `null` chứ không chuỗi rỗng: `srcset=""` là một thuộc tính rỗng trong
     * HTML mà trình duyệt vẫn phải đọc, và React in nó ra. Không có bản dẫn
     * xuất thì `<img>` phải **không có** `srcset`.
     *
     * Mỗi bề rộng là một lượt `exists()` — một `stat` trên đĩa cục bộ. Một
     * trang 24 thẻ là 48 lượt, so với 164ms SQL của chính trang đó thì không
     * đo được. Không ghi bề rộng nào vào CSDL vì cái đĩa **là** nguồn sự thật
     * ở đây: một cột nói "có w480" trong khi tệp đã bị xoá là một `<img>` trỏ
     * vào 404, và không gì phát hiện ra.
     */
    public static function srcsetCho(?string $duongGoc, ?Filesystem $dia = null): ?string
    {
        $dia ??= Storage::disk('public');
        $muc = [];

        foreach (self::BE_RONG as $beRong) {
            $duong = self::duongDanXuat($duongGoc, $beRong);

            if ($duong === null || ! $dia->exists($duong)) {
                continue;
            }

            // Phép dựng URL đi qua **một** chỗ — bài học lát 1. Bản dẫn xuất
            // luôn là đường dẫn tương đối, nên nhánh `http(s)://` của hàm đó
            // không chạy ở đây; gọi nó vẫn đúng hơn là viết lại `asset()`.
            $muc[] = ScreenSpec::photoUrlOf($duong) . ' ' . $beRong . 'w';
        }

        return $muc === [] ? null : implode(', ', $muc);
    }

    /**
     * `srcset` cho một dãy đường dẫn, **giữ đúng chỉ số**.
     *
     * Phần tử `null` ở đúng vị trí ảnh chưa có bản dẫn xuất. Nén dãy lại cho
     * gọn là làm hai dãy lệch chỉ số, và giao diện ghép `photo_urls[i]` với
     * `photo_srcsets[i]`.
     *
     * @param  list<string>  $duong
     * @return list<string|null>
     */
    public static function srcsetChoDay(array $duong, ?Filesystem $dia = null): array
    {
        $dia ??= Storage::disk('public');

        return array_map(fn (string $p) => self::srcsetCho($p, $dia), array_values($duong));
    }

    // ══ Phần sinh ảnh ═════════════════════════════════════════════════════

    /**
     * Sinh bản dẫn xuất còn thiếu cho **một** ảnh.
     *
     * @param  bool  $lai  sinh lại cả bản đã có. Dùng khi đổi `CHAT_LUONG` hay
     *                     `BE_RONG`, không dùng trong đường chạy thường.
     * @return array{ket_qua: string, da_sinh: list<int>, ly_do: ?string, be_rong_goc: ?int}
     */
    public function sinhCho(string $duongGoc, bool $lai = false, ?Filesystem $dia = null): array
    {
        $dia ??= Storage::disk('public');

        $can = [];
        foreach (self::BE_RONG as $beRong) {
            $duong = self::duongDanXuat($duongGoc, $beRong);

            if ($duong === null) {
                // Một bề rộng không dựng được đường dẫn thì cả ảnh không dựng
                // được: lý do nằm ở bản thân `$duongGoc`, không ở bề rộng.
                return $this->ra(
                    str_starts_with(trim($duongGoc), 'http://') || str_starts_with(trim($duongGoc), 'https://')
                        ? self::KQ_URL_NGOAI
                        : self::KQ_DUONG_SAI,
                );
            }

            if ($lai || ! $dia->exists($duong)) {
                $can[$beRong] = $duong;
            }
        }

        if ($can === []) {
            return $this->ra(self::KQ_DA_CO);
        }

        if (! $dia->exists($duongGoc)) {
            return $this->ra(self::KQ_THIEU_TEP);
        }

        $byte = $dia->get($duongGoc);

        if (! is_string($byte) || $byte === '') {
            return $this->ra(self::KQ_KHONG_DOC);
        }

        // Đọc **đầu tệp**, chưa giải nén: đây là chỗ duy nhất biết được kích
        // thước thật trước khi trả giá bộ nhớ cho nó.
        $thongTin = @getimagesizefromstring($byte);

        if ($thongTin === false || ($thongTin[0] ?? 0) < 1 || ($thongTin[1] ?? 0) < 1) {
            return $this->ra(self::KQ_KHONG_DOC);
        }

        [$wGoc, $hGoc] = [(int) $thongTin[0], (int) $thongTin[1]];
        $loai = (int) ($thongTin[2] ?? 0);

        if (! isset(self::LOAI_GD[$loai])) {
            return $this->ra(self::KQ_KHONG_DOC, 'Định dạng ' . ($thongTin['mime'] ?? '?') . ' không nằm trong ba định dạng nhận.');
        }

        if (! self::gdLam($loai)) {
            return $this->ra(self::KQ_KHONG_GD, 'GD thiếu hỗ trợ cho ' . ($thongTin['mime'] ?? '?') . '.');
        }

        // Bỏ bề rộng nào ≥ bản gốc: phóng to một ảnh nhỏ cho ra một tệp nặng
        // hơn mà không rõ hơn, và khai sai bề rộng trong `srcset`.
        //
        // Lọc **trước** phép kiểm bộ nhớ, không sau: phép kiểm ấy cần biết bản
        // thu nhỏ lớn nhất sẽ là bao nhiêu, và một ảnh 300px thì không có bản
        // nào nên nó không cần kiểm bộ nhớ gì cả.
        $can = array_filter($can, fn (string $_, int $beRong) => $beRong < $wGoc, ARRAY_FILTER_USE_BOTH);

        if ($can === []) {
            return $this->ra(self::KQ_GOC_NHO, null, $wGoc);
        }

        if (($lyDo = $this->quaLon($wGoc, $hGoc, max(array_keys($can)))) !== null) {
            return $this->ra(self::KQ_QUA_LON, $lyDo, $wGoc);
        }

        $anh = @imagecreatefromstring($byte);
        unset($byte);

        if ($anh === false) {
            return $this->ra(self::KQ_KHONG_DOC, 'getimagesizefromstring() đọc được đầu tệp nhưng imagecreatefromstring() không giải nén được.', $wGoc);
        }

        $daSinh = [];

        try {
            foreach ($can as $beRong => $duong) {
                $byteRa = $this->thuNho($anh, $wGoc, $hGoc, $beRong, $loai);

                if ($byteRa === null) {
                    continue;
                }

                $dia->put($duong, $byteRa);
                $daSinh[] = $beRong;
            }
        } finally {
            imagedestroy($anh);
        }

        return $daSinh === []
            ? $this->ra(self::KQ_KHONG_DOC, 'GD không xuất ra được byte nào.', $wGoc)
            : $this->ra(self::KQ_DA_SINH, null, $wGoc, $daSinh);
    }

    /** GD có mặt và biết định dạng này? */
    public static function gdLam(int $imageType): bool
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagetypes')) {
            return false;
        }

        $co = self::LOAI_GD[$imageType] ?? null;

        return $co !== null && (imagetypes() & $co) !== 0;
    }

    /**
     * Ba định dạng nhận, và GD có làm được từng cái không — cho lệnh in ra.
     *
     * Lệnh in bảng này vì **không có đường nào khác** để biết production có GD:
     * `php -i` phải gõ qua ssh, và ba lượt ssh gần nhất không đưa được đầu ra
     * về. Chạy lệnh thì nó tự nói.
     *
     * @return array<string, bool>
     */
    public static function gdLamGi(): array
    {
        return [
            'jpeg' => self::gdLam(IMAGETYPE_JPEG),
            'png'  => self::gdLam(IMAGETYPE_PNG),
            'webp' => self::gdLam(IMAGETYPE_WEBP),
        ];
    }

    /**
     * Ảnh này quá lớn để mở ra? Trả lý do, hoặc `null` khi mở được.
     *
     * Hai phép kiểm, không một:
     *
     *  1. **Trần cố định** `toiDaPixel` — chính sách, không phụ thuộc máy.
     *  2. **Bộ nhớ còn lại thật** — `memory_limit` của production không biết
     *     được từ đây, nên hỏi PHP lúc chạy. Một lượt OOM là lỗi tử không
     *     `catch` được: nó giết luôn request đang lưu biểu mẫu, và người dùng
     *     thấy một trang trắng chứ không thấy câu "ảnh quá lớn".
     *
     * ══ Bản đầu của phép ước lượng này SAI, và một ca test chứng minh ══
     *
     * Bản đầu nhân 2,5 lần cỡ **bản gốc**, lấy lý do "hai bản cùng lúc trong
     * bộ nhớ". Nhưng bản thu nhỏ nhỏ hơn bản gốc nhiều lần — đó là cả mục đích
     * của việc này: 1280 từ 3000 là `(1280/3000)²` ≈ 0,18 lần. Nên hệ số 2,5
     * không phải "có chủ ý thừa", nó là sai gấp đôi, và nó từ chối một ảnh
     * 3000×2000 hoàn toàn bình thường khi `memory_limit` là 128M.
     *
     * Ca `anh lon sinh du hai ban va be rong dung con so` đỏ ở lượt chạy đầu
     * đúng vì chuyện đó. Nay phép tính cộng **bản thu nhỏ thật sẽ dựng**, rồi
     * chừa 30% cho cấu trúc nội bộ của GD và chuỗi byte xuất ra.
     */
    private function quaLon(int $w, int $h, int $beRongLonNhat): ?string
    {
        $diem = $w * $h;

        if ($diem > $this->toiDaPixel) {
            return "Ảnh {$w}×{$h} = " . number_format($diem) . ' điểm, trên trần '
                . number_format($this->toiDaPixel) . '.';
        }

        // GD truecolor: 4 byte một điểm ảnh, cho cả bản gốc và bản thu nhỏ.
        $diemRa = $beRongLonNhat * max(1, (int) round($h * $beRongLonNhat / $w));
        $can    = (int) (($diem + $diemRa) * 4 * 1.3);
        $con    = $this->boNhoConLai();

        if ($con !== null && $can > $con) {
            return "Ảnh {$w}×{$h} cần ~" . round($can / 1048576) . 'MB, còn '
                . round($con / 1048576) . 'MB trong memory_limit.';
        }

        return null;
    }

    /** Byte còn lại trong `memory_limit`, hoặc `null` khi không có giới hạn. */
    private function boNhoConLai(): ?int
    {
        $tran = $this->tranBoNho();

        return $tran === null ? null : max(0, $tran - memory_get_usage(true));
    }

    /** `memory_limit` thành byte. `null` khi `-1` (không giới hạn). */
    private function tranBoNho(): ?int
    {
        $tho = trim((string) ini_get('memory_limit'));

        if ($tho === '' || $tho === '-1') {
            return null;
        }

        $so = (int) $tho;

        return match (strtoupper(substr($tho, -1))) {
            'G'     => $so * 1024 * 1024 * 1024,
            'M'     => $so * 1024 * 1024,
            'K'     => $so * 1024,
            default => $so,
        };
    }

    /**
     * Một lượt thu nhỏ. Trả byte của tệp mới, hoặc `null` khi GD không xuất ra.
     *
     * `imagecopyresampled` chứ không `imagecopyresized`: bản không resample
     * lấy điểm ảnh gần nhất, nên một ảnh thu nhỏ 4× hiện ra lởm chởm.
     */
    private function thuNho(\GdImage $goc, int $wGoc, int $hGoc, int $beRong, int $loai): ?string
    {
        $cao = max(1, (int) round($hGoc * $beRong / $wGoc));
        $moi = imagecreatetruecolor($beRong, $cao);

        if ($moi === false) {
            return null;
        }

        try {
            // PNG và WebP mang kênh alpha. Thiếu ba dòng này thì mọi điểm
            // trong suốt ra màu đen — và ảnh logo trên nền sáng thành một
            // khối đen.
            if ($loai === IMAGETYPE_PNG || $loai === IMAGETYPE_WEBP) {
                imagealphablending($moi, false);
                imagesavealpha($moi, true);
                $trong = imagecolorallocatealpha($moi, 0, 0, 0, 127);

                if ($trong !== false) {
                    imagefilledrectangle($moi, 0, 0, $beRong - 1, $cao - 1, $trong);
                }
            }

            if (! imagecopyresampled($moi, $goc, 0, 0, 0, 0, $beRong, $cao, $wGoc, $hGoc)) {
                return null;
            }

            ob_start();

            $xong = match ($loai) {
                IMAGETYPE_JPEG => imagejpeg($moi, null, self::CHAT_LUONG),
                IMAGETYPE_PNG  => imagepng($moi),
                IMAGETYPE_WEBP => imagewebp($moi, null, self::CHAT_LUONG),
                default        => false,
            };

            $byte = ob_get_clean();

            return $xong && is_string($byte) && $byte !== '' ? $byte : null;
        } finally {
            imagedestroy($moi);
        }
    }

    /**
     * @param  list<int>  $daSinh
     * @return array{ket_qua: string, da_sinh: list<int>, ly_do: ?string, be_rong_goc: ?int}
     */
    private function ra(string $ketQua, ?string $lyDo = null, ?int $beRongGoc = null, array $daSinh = []): array
    {
        return [
            'ket_qua'     => $ketQua,
            'da_sinh'     => $daSinh,
            'ly_do'       => $lyDo,
            'be_rong_goc' => $beRongGoc,
        ];
    }
}
