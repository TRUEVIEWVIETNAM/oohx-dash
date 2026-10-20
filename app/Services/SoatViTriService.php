<?php

namespace App\Services;

use App\Models\Screen;
use App\Models\VietnamProvince;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Soát chất lượng **dữ liệu vị trí** của kho màn hình.
 *
 * ══ Vì sao có lớp này ══
 *
 * `docs/PLAN-EXPLORE-QUYET-DINH-MUA.md` mục "Ba thứ trong mock sẽ KHÔNG lên
 * trang công khai" chốt: nhãn cảnh báo dữ liệu **không** in ra trang bán
 * hàng, vì nói với người mua rằng dữ liệu của sàn không đáng tin mà họ không
 * sửa được gì là tệ hơn im lặng. Chỗ đúng của chúng là một báo cáo trong
 * `/admin`. Đây là báo cáo đó, phần vị trí.
 *
 * ══ Nguyên tắc: thà im lặng hơn là báo bừa ══
 *
 * Soát ngày 20/10/2026 trên 104 bản ghi công khai thử một phép đo hiển nhiên
 * — "tên màn hình không có token nào chung với tên điểm đặt, địa chỉ hay
 * tỉnh" — và nó cho **15 cảnh báo, 14 trong đó là báo sai**: một media owner
 * đặt tên bằng chữ viết tắt (`Billboard CV 23/9` cho `Công viên 23 Tháng 9`,
 * `Billboard LTT-SVH` cho `Giao lộ Lý Thái Tổ - Sư Vạn Hạnh`). Một bảng mà
 * 14/15 dòng là rác thì không ai đọc nó lần thứ hai, và dòng thật chết cùng
 * những dòng kia.
 *
 * Nên mọi phép đo ở đây hoặc **đúng tuyệt đối** (thiếu toạ độ, toạ độ ngoài
 * Việt Nam, khoá ghép lệch), hoặc được dán nhãn `xem_tay` và không bao giờ
 * tự nhận là lỗi.
 *
 * ══ Độ phủ là một phần của báo cáo ══
 *
 * Mỗi phép đo trả kèm số bản ghi nó **đối chiếu được**. Một phép đo chạy trên
 * 0 bản ghi vì cột nguồn rỗng cũng trả "không có lỗi" y như một phép đo chạy
 * trên 104 bản ghi và thấy kho sạch — hai điều đó khác nhau hoàn toàn, và chỉ
 * con số độ phủ nói ra được.
 */
class SoatViTriService
{
    public const MUC_CAO     = 'cao';
    public const MUC_XEM_TAY = 'xem_tay';

    /** Khoảng cách từ trung vị nhóm cùng tỉnh mà vẫn coi là bình thường. */
    public const NGUONG_XA_KM = 50;

    /**
     * Nhóm nhỏ hơn số này thì trung vị không nói gì.
     *
     * Hai bản ghi thì trung vị nằm giữa chúng, và "cả hai đều xa trung vị
     * bằng nhau" là một câu không có nội dung.
     */
    public const TOI_THIEU_NHOM = 3;

    /**
     * Số màn hình dùng chung một toạ độ mà vẫn hợp lý.
     *
     * Hai thì bình thường: hai mặt của một bảng, hai bảng ở một giao lộ, hai
     * bảng ở một cổng sân bay — kho thật có cả ba kiểu. Từ ba trở lên thì
     * đáng xem lại, nhưng không tự kết luận: `LED Yên Bái` có 6 màn hình ở
     * một điểm và tên chúng chỉ **ba** con đường khác nhau.
     */
    public const TOI_DA_CHUNG_TOA_DO = 2;

    /**
     * Số màn hình tối thiểu của một owner để nói được "quy ước đặt tên".
     *
     * Với ba bản ghi, "hai cái thế này một cái thế kia" chưa phải quy ước —
     * chỉ là ba cái tên.
     */
    public const TOI_THIEU_QUY_UOC = 10;

    /** Tỉ lệ cùng một kiểu tên để coi là quy ước của owner. */
    public const NGUONG_QUY_UOC = 0.9;

    /**
     * Hộp giới hạn lãnh thổ Việt Nam, kể cả Phú Quốc và Côn Đảo.
     *
     * Không mở rộng tới Trường Sa: không có màn hình quảng cáo ở đó, nên nới
     * hộp ra chỉ làm phép đo bỏ sót một toạ độ hỏng.
     */
    public const VN_BAC  = 23.5;
    public const VN_NAM  = 8.1;
    public const VN_DONG = 109.6;
    public const VN_TAY  = 102.1;

    /** Chữ tiếng Việt cho từng mã lỗi, dùng ở cả lệnh artisan và `/admin`. */
    public const NHAN = [
        'thieu_toa_do'         => 'Thiếu toạ độ',
        'toa_do_ngoai_vn'      => 'Toạ độ không ở Việt Nam',
        'thieu_tinh'           => 'Thiếu tỉnh/thành',
        'ghep_lech_khoa'       => 'Khoá ghép lệch điểm đặt',
        'toa_do_xa_nhom'       => 'Toạ độ xa hẳn nhóm cùng tỉnh',
        'ten_noi_tinh_khac'    => 'Tên nói một tỉnh khác',
        'ten_lech_quy_uoc'     => 'Tên lệch quy ước của chính owner',
        'nhieu_man_mot_toa_do' => 'Nhiều màn hình dùng chung một toạ độ',
        'city_hai_cap'         => 'Ô tỉnh/thành chứa hai cấp hành chính',
    ];

    /** Hệ quả với người mua — câu này là lý do tồn tại của từng phép đo. */
    public const HE_QUA = [
        'thieu_toa_do'         => 'Không lên bản đồ. Người mua khoanh vùng theo khu vực sẽ không bao giờ thấy vị trí này.',
        'toa_do_ngoai_vn'      => 'Pin rơi ra ngoài lãnh thổ. Bản đồ kết quả vẽ sai, và bộ lọc khung nhìn bỏ qua vị trí này.',
        'thieu_tinh'           => 'Bộ lọc tỉnh/thành không tìm được. Vị trí chỉ đến được bằng đường liên kết trực tiếp.',
        'ghep_lech_khoa'       => 'Dữ liệu nguồn nói màn hình thuộc điểm đặt khác. Người mua đọc một địa chỉ không phải của nó.',
        'toa_do_xa_nhom'       => 'Pin nằm xa hẳn mọi vị trí khác cùng tỉnh — dấu hiệu kinh điển của một lần ghép sai.',
        'ten_noi_tinh_khac'    => 'Tên và vị trí nói hai nơi. Cần người đọc quyết định cái nào đúng.',
        'ten_lech_quy_uoc'     => 'Bản ghi lẻ loi trong kho của chính owner — hình dạng thường thấy của một hàng nhập tay vào sai chỗ.',
        'nhieu_man_mot_toa_do' => 'Một pin đại diện nhiều vị trí thật. Người mua chọn trên bản đồ không biết mình đang chọn cái nào.',
        'city_hai_cap'         => 'Ô này giữ cả tỉnh mới lẫn phường mang tên tỉnh cũ. Bộ lọc khớp theo tiền tố nên phần sau là chữ chết.',
    ];

    /** @var string[]|null Tên tỉnh đã bỏ dấu, nạp một lần cho mỗi lượt soát. */
    private ?array $tenTinh = null;

    /**
     * Chạy toàn bộ phép đo.
     *
     * Chỉ soát màn hình **đang công khai**: đó là tập người mua thật sự chạm
     * tới, và một bản ghi hỏng trong đó tốn tiền ngay. Màn hình chưa bật thì
     * còn thời gian sửa trước khi nó ra ngoài.
     *
     * @return array{
     *     luc: \Carbon\CarbonInterface,
     *     tong_man_hinh: int,
     *     loi: array<int, array<string, mixed>>,
     *     theo_loai: array<string, array{so: int, do_phu: int, muc: string}>,
     * }
     */
    public function soat(): array
    {
        $manHinh = Screen::publiclyVisible()
            ->with(['site:id,name,external_id,address,city,lat,lon', 'owner:id,name'])
            ->get();

        $loi = array_merge(
            $this->thieuToaDo($manHinh),
            $this->toaDoNgoaiVietNam($manHinh),
            $this->thieuTinh($manHinh),
            $this->ghepLechKhoa($manHinh),
            $this->toaDoXaNhom($manHinh),
            $this->tenNoiTinhKhac($manHinh),
            $this->tenLechQuyUoc($manHinh),
            $this->nhieuManMotToaDo($manHinh),
            $this->cityHaiCap($manHinh),
        );

        return [
            'luc'           => now(),
            'tong_man_hinh' => $manHinh->count(),
            'loi'           => $loi,
            'theo_loai'     => $this->tongHop($loi, $manHinh),
        ];
    }

    // ── Phép đo đúng tuyệt đối ───────────────────────────────────────────────

    /** @param Collection<int, Screen> $manHinh */
    private function thieuToaDo(Collection $manHinh): array
    {
        return $manHinh
            ->filter(fn (Screen $s) => $s->site === null || $s->site->lat === null || $s->site->lon === null)
            ->map(fn (Screen $s) => $this->dong($s, 'thieu_toa_do', self::MUC_CAO, 'Điểm đặt không có lat/lon.'))
            ->values()
            ->all();
    }

    /** @param Collection<int, Screen> $manHinh */
    private function toaDoNgoaiVietNam(Collection $manHinh): array
    {
        return $manHinh
            ->filter(function (Screen $s): bool {
                if ($s->site?->lat === null || $s->site?->lon === null) {
                    return false; // Đã bắt ở `thieu_toa_do`; không đếm hai lần.
                }

                $lat = (float) $s->site->lat;
                $lon = (float) $s->site->lon;

                // (0, 0) là giá trị mặc định lọt qua, không phải một điểm trên
                // Vịnh Guinea mà ai đó chọn.
                return ($lat === 0.0 && $lon === 0.0)
                    || $lat > self::VN_BAC || $lat < self::VN_NAM
                    || $lon > self::VN_DONG || $lon < self::VN_TAY;
            })
            ->map(fn (Screen $s) => $this->dong(
                $s,
                'toa_do_ngoai_vn',
                self::MUC_CAO,
                sprintf('lat %s, lon %s.', $s->site->lat, $s->site->lon),
            ))
            ->values()
            ->all();
    }

    /** @param Collection<int, Screen> $manHinh */
    private function thieuTinh(Collection $manHinh): array
    {
        return $manHinh
            ->filter(fn (Screen $s) => trim((string) $s->site?->city) === '')
            ->map(fn (Screen $s) => $this->dong(
                $s,
                'thieu_tinh',
                self::MUC_CAO,
                $s->site?->address
                    ? 'Địa chỉ có sẵn thông tin: ' . $s->site->address
                    : 'Không có cả địa chỉ để suy ra.',
            ))
            ->values()
            ->all();
    }

    /**
     * Khoá ghép lệch — phép đo trực tiếp nhất cho "ghép sai vị trí".
     *
     * `screens.site_external_id` là mã điểm đặt **do nguồn dữ liệu nói**, còn
     * `site_id` là chỗ bản ghi thật sự được gắn vào. Hai thứ đó lệch nhau
     * nghĩa là lần ghép đã đi chệch, và đó là một khẳng định chứ không phải
     * một phỏng đoán.
     *
     * Chỉ `POST /api/v1/screens` điền cột này; `SiteImportService` tra mã để
     * tìm `site_id` rồi **không lưu lại mã**. Nên độ phủ của phép đo này có
     * thể rất thấp, và con số đó phải hiện trong báo cáo — xem `tongHop()`.
     *
     * @param Collection<int, Screen> $manHinh
     */
    private function ghepLechKhoa(Collection $manHinh): array
    {
        return $manHinh
            ->filter(function (Screen $s): bool {
                $khoa = trim((string) $s->site_external_id);

                return $khoa !== ''
                    && $s->site !== null
                    && $khoa !== trim((string) $s->site->external_id);
            })
            ->map(fn (Screen $s) => $this->dong(
                $s,
                'ghep_lech_khoa',
                self::MUC_CAO,
                sprintf(
                    'Nguồn nói điểm đặt [%s], bản ghi đang gắn vào [%s].',
                    $s->site_external_id,
                    $s->site->external_id,
                ),
            ))
            ->values()
            ->all();
    }

    /**
     * Toạ độ xa hẳn nhóm cùng tỉnh.
     *
     * Dùng **trung vị** chứ không trung bình: một bản ghi ghép sai kéo trung
     * bình về phía nó, nên chính nó bớt xa "tâm" và có thể lọt. Trung vị
     * không bị một điểm lạc làm dịch chuyển.
     *
     * @param Collection<int, Screen> $manHinh
     */
    private function toaDoXaNhom(Collection $manHinh): array
    {
        $loi = [];

        $coToaDo = $manHinh->filter(
            fn (Screen $s) => $s->site?->lat !== null
                && $s->site?->lon !== null
                && trim((string) $s->site->city) !== '',
        );

        foreach ($coToaDo->groupBy(fn (Screen $s) => $s->site->city) as $tinh => $nhom) {
            if ($nhom->count() < self::TOI_THIEU_NHOM) {
                continue;
            }

            $latGiua = $this->trungVi($nhom->map(fn (Screen $s) => (float) $s->site->lat)->all());
            $lonGiua = $this->trungVi($nhom->map(fn (Screen $s) => (float) $s->site->lon)->all());

            foreach ($nhom as $s) {
                $kc = $this->kmGiua((float) $s->site->lat, (float) $s->site->lon, $latGiua, $lonGiua);

                if ($kc <= self::NGUONG_XA_KM) {
                    continue;
                }

                $loi[] = $this->dong($s, 'toa_do_xa_nhom', self::MUC_CAO, sprintf(
                    'Cách %s km khỏi tâm của %d vị trí cùng ô "%s".',
                    number_format($kc, 0, ',', '.'),
                    $nhom->count(),
                    $tinh,
                ));
            }
        }

        return $loi;
    }

    // ── Phép đo cần người đọc ────────────────────────────────────────────────

    /**
     * Tên màn hình chứa tên một tỉnh khác tỉnh của điểm đặt.
     *
     * Đo trên kho thật ngày 20/10/2026: 25/104 bản ghi có tên tỉnh trong tên,
     * và **0** lệch. Nên hôm nay nó im lặng — đó là một phép canh cho lần
     * nhập tới, không phải một phép tìm cho hôm nay.
     *
     * Mức `xem_tay` chứ không `cao`: tên tỉnh cũng là tên đường và tên chợ
     * ("Đại lộ Hoà Bình" ở Cần Thơ, "Chợ Long An"). Kho hôm nay không có ca
     * nào như vậy, nhưng nó sẽ có, và một phép đo tự nhận là lỗi trong khi
     * đúng một nửa thì phá lòng tin vào cả bảng.
     *
     * @param Collection<int, Screen> $manHinh
     */
    private function tenNoiTinhKhac(Collection $manHinh): array
    {
        $tinh = $this->tenTinh();

        if ($tinh === []) {
            return [];
        }

        $loi = [];

        foreach ($manHinh as $s) {
            $ten = $this->chuanHoa($s->name);
            $noi = $this->chuanHoa(implode(' ', [
                $s->site?->city,
                $s->site?->address,
                $s->site?->name,
            ]));

            foreach ($tinh as $t) {
                if (! str_contains($ten, ' ' . $t . ' ') || str_contains($noi, ' ' . $t . ' ')) {
                    continue;
                }

                $loi[] = $this->dong($s, 'ten_noi_tinh_khac', self::MUC_XEM_TAY, sprintf(
                    'Tên chứa "%s", nhưng tỉnh/địa chỉ/điểm đặt không nhắc tới nơi đó.',
                    $t,
                ));
            }
        }

        return $loi;
    }

    /**
     * Tên lệch quy ước đặt tên của chính media owner.
     *
     * ══ Phép đo duy nhất bắt được hàng "Aeon Mall Tân Phú" ══
     *
     * Hàng đó là lý do việc này có trong hàng đợi: một màn hình tên "Màn Hình
     * Quảng Cáo Aeon Mall Tân Phú" gắn vào điểm đặt "LED Bắc Kạn", ở một nút
     * giao tại Bắc Kạn. Bảy phép đo trên **không** bắt được nó: toạ độ đúng,
     * tỉnh đúng, nhóm đúng, không khoá nào để đối chiếu, và trong tên không
     * có tên tỉnh nào. Chỉ con người đọc ra được — hoặc phép đo này.
     *
     * Điều đo được: 20/21 màn hình của owner ấy tên dạng mã
     * (`OOHX_LED_BKN_BAC_KAN`), đúng một cái thì không. Owner còn lại có
     * 83/83 tên dạng chữ thường, nên không có thiểu số nào để chỉ ra. Đo trên
     * kho thật ngày 20/10/2026: **1 phát hiện, 0 báo sai**.
     *
     * Đòi ít nhất `TOI_THIEU_QUY_UOC` màn hình: với ba bản ghi thì "hai cái
     * thế này, một cái thế kia" chưa phải một quy ước, chỉ là ba cái tên.
     *
     * Mức `xem_tay`: phép đo nói "bản ghi này lẻ loi", không nói "ghép sai".
     * Một owner đổi cách đặt tên giữa đường là chuyện bình thường, và khi đó
     * cả bảng này sai — nên nó không bao giờ được tự nhận là lỗi.
     *
     * @param Collection<int, Screen> $manHinh
     */
    private function tenLechQuyUoc(Collection $manHinh): array
    {
        $loi = [];

        foreach ($manHinh->groupBy(fn (Screen $s) => (string) $s->owner_id) as $nhom) {
            if ($nhom->count() < self::TOI_THIEU_QUY_UOC) {
                continue;
            }

            $dangMa = $nhom->filter(fn (Screen $s) => $this->tenDangMa($s->name));
            $tyLe   = $dangMa->count() / $nhom->count();

            // Không có thiểu số nào thì không có gì để chỉ ra.
            if ($dangMa->count() === 0 || $dangMa->count() === $nhom->count()) {
                continue;
            }

            if ($tyLe >= self::NGUONG_QUY_UOC) {
                $leLoi = $nhom->reject(fn (Screen $s) => $this->tenDangMa($s->name));
                $kieu  = 'dạng mã';
            } elseif ($tyLe <= 1 - self::NGUONG_QUY_UOC) {
                $leLoi = $dangMa;
                $kieu  = 'dạng chữ';
            } else {
                continue; // Owner dùng lẫn hai kiểu: không có quy ước nào.
            }

            foreach ($leLoi as $s) {
                $loi[] = $this->dong($s, 'ten_lech_quy_uoc', self::MUC_XEM_TAY, sprintf(
                    '%d/%d màn hình của owner này đặt tên %s; tên này không. Kiểm xem bản ghi có gắn đúng điểm đặt.',
                    $kieu === 'dạng mã' ? $dangMa->count() : $nhom->count() - $dangMa->count(),
                    $nhom->count(),
                    $kieu,
                ));
            }
        }

        return $loi;
    }

    /** Tên dạng mã: chỉ chữ in hoa, số và gạch dưới — `OOHX_LED_BKN_BAC_KAN`. */
    private function tenDangMa(?string $ten): bool
    {
        return (bool) preg_match('/^[A-Z0-9][A-Z0-9_]*$/', trim((string) $ten));
    }

    /** @param Collection<int, Screen> $manHinh */
    private function nhieuManMotToaDo(Collection $manHinh): array
    {
        $loi = [];

        $coToaDo = $manHinh->filter(
            fn (Screen $s) => $s->site?->lat !== null && $s->site?->lon !== null,
        );

        $cum = $coToaDo->groupBy(fn (Screen $s) => $s->site->lat . ',' . $s->site->lon);

        foreach ($cum as $toaDo => $nhom) {
            if ($nhom->count() <= self::TOI_DA_CHUNG_TOA_DO) {
                continue;
            }

            foreach ($nhom as $s) {
                $loi[] = $this->dong($s, 'nhieu_man_mot_toa_do', self::MUC_XEM_TAY, sprintf(
                    '%d màn hình cùng ở (%s): %s.',
                    $nhom->count(),
                    $toaDo,
                    $nhom->map(fn (Screen $x) => $x->name)->implode(' · '),
                ));
            }
        }

        return $loi;
    }

    /**
     * Ô `city` chứa hai cấp, kiểu "Thái Nguyên > Phường Bắc Kạn".
     *
     * Không phải lỗi: đó là hệ quả của lần sáp nhập tỉnh 2025, và phần trước
     * dấu `>` đúng là tỉnh mới. Nhưng bộ lọc tỉnh khớp theo **tiền tố**
     * (`city LIKE 'Thái Nguyên%'`), nên phần sau dấu `>` là chữ không ai tìm
     * tới được — và tên điểm đặt vẫn mang tên tỉnh cũ ("LED Bắc Kạn"), nên
     * cùng một bảng LED có hai cái tên tỉnh khác nhau trên một trang.
     *
     * @param Collection<int, Screen> $manHinh
     */
    private function cityHaiCap(Collection $manHinh): array
    {
        return $manHinh
            ->filter(fn (Screen $s) => str_contains((string) $s->site?->city, '>'))
            ->map(fn (Screen $s) => $this->dong(
                $s,
                'city_hai_cap',
                self::MUC_XEM_TAY,
                sprintf('Ô tỉnh/thành: "%s". Tên điểm đặt: "%s".', $s->site->city, $s->site->name),
            ))
            ->values()
            ->all();
    }

    // ── Dựng kết quả ─────────────────────────────────────────────────────────

    private function dong(Screen $s, string $loai, string $muc, string $chiTiet): array
    {
        return [
            'loai'        => $loai,
            'nhan'        => self::NHAN[$loai] ?? $loai,
            'muc'         => $muc,
            'screen_slug' => $s->slug,
            'screen_name' => $s->name,
            'site_name'   => $s->site?->name,
            'city'        => $s->site?->city,
            'owner_name'  => $s->owner?->name,
            'chi_tiet'    => $chiTiet,
        ];
    }

    /**
     * Số lỗi và **độ phủ** của từng phép đo.
     *
     * Độ phủ là số bản ghi phép đo thật sự đối chiếu được. Không có nó thì
     * "0 lỗi" của một phép đo chạy trên 0 bản ghi đọc giống hệt "0 lỗi" của
     * một phép đo chạy trên cả kho.
     *
     * @param  array<int, array<string, mixed>>  $loi
     * @param  Collection<int, Screen>  $manHinh
     */
    private function tongHop(array $loi, Collection $manHinh): array
    {
        $tong = $manHinh->count();

        $coToaDo = $manHinh->filter(
            fn (Screen $s) => $s->site?->lat !== null && $s->site?->lon !== null,
        )->count();

        $coKhoa = $manHinh->filter(
            fn (Screen $s) => trim((string) $s->site_external_id) !== '',
        )->count();

        $doPhu = [
            'thieu_toa_do'         => $tong,
            'toa_do_ngoai_vn'      => $coToaDo,
            'thieu_tinh'           => $tong,
            'ghep_lech_khoa'       => $coKhoa,
            'toa_do_xa_nhom'       => $this->soTrongNhomDuLon($manHinh),
            'ten_noi_tinh_khac'    => $this->tenTinh() === [] ? 0 : $tong,
            'ten_lech_quy_uoc'     => $this->soTrongKhoDuLon($manHinh),
            'nhieu_man_mot_toa_do' => $coToaDo,
            'city_hai_cap'         => $tong,
        ];

        $theoLoai = [];

        foreach (array_keys(self::NHAN) as $loai) {
            $cua = array_values(array_filter($loi, fn (array $l) => $l['loai'] === $loai));

            $theoLoai[$loai] = [
                'so'     => count($cua),
                'do_phu' => $doPhu[$loai] ?? 0,
                'muc'    => $cua !== [] ? $cua[0]['muc'] : self::MUC_CAO,
            ];
        }

        return $theoLoai;
    }

    /** @param Collection<int, Screen> $manHinh */
    private function soTrongNhomDuLon(Collection $manHinh): int
    {
        return $manHinh
            ->filter(
                fn (Screen $s) => $s->site?->lat !== null
                    && $s->site?->lon !== null
                    && trim((string) $s->site->city) !== '',
            )
            ->groupBy(fn (Screen $s) => $s->site->city)
            ->filter(fn (Collection $nhom) => $nhom->count() >= self::TOI_THIEU_NHOM)
            ->flatten(1)
            ->count();
    }

    /**
     * Số màn hình thuộc owner có đủ bản ghi để nói về quy ước đặt tên.
     *
     * @param Collection<int, Screen> $manHinh
     */
    private function soTrongKhoDuLon(Collection $manHinh): int
    {
        return $manHinh
            ->groupBy(fn (Screen $s) => (string) $s->owner_id)
            ->filter(fn (Collection $nhom) => $nhom->count() >= self::TOI_THIEU_QUY_UOC)
            ->flatten(1)
            ->count();
    }

    // ── Công cụ ──────────────────────────────────────────────────────────────

    /**
     * Tên tỉnh/thành, bỏ dấu, có khoảng trắng kẹp hai đầu khi so khớp.
     *
     * Lấy từ `vietnam_provinces` chứ không viết tay: danh sách đó đã đổi một
     * lần (sáp nhập 2025) và sẽ đổi nữa, còn một mảng hằng trong mã nguồn thì
     * không ai nhớ cập nhật. Bảng rỗng thì phép đo tự tắt, và `tongHop()` ghi
     * độ phủ 0 để không ai đọc nó như "kho sạch".
     *
     * @return string[]
     */
    private function tenTinh(): array
    {
        // Nhớ trên **thực thể**, không `static`: một `static` sống qua cả tiến
        // trình, nên ca test nào seed tỉnh sau lượt soát đầu sẽ đọc lại danh
        // sách rỗng của ca trước và đỏ vì một lý do không liên quan.
        if ($this->tenTinh !== null) {
            return $this->tenTinh;
        }

        return $this->tenTinh = VietnamProvince::query()
            ->pluck('name')
            ->map(fn ($t) => trim($this->chuanHoa($t)))
            ->filter(fn (string $t) => $t !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** Bỏ dấu, hạ chữ thường, mọi thứ không phải chữ/số thành một khoảng trắng. */
    private function chuanHoa(?string $s): string
    {
        $s = Str::lower(Str::ascii((string) $s));

        return ' ' . trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s)) . ' ';
    }

    /** @param float[] $so */
    private function trungVi(array $so): float
    {
        sort($so);

        $n = count($so);

        if ($n === 0) {
            return 0.0;
        }

        return $n % 2 === 1
            ? $so[intdiv($n, 2)]
            : ($so[$n / 2 - 1] + $so[$n / 2]) / 2;
    }

    /** Haversine, km. */
    private function kmGiua(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
