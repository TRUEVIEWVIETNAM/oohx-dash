<?php

namespace App\Services;

use App\Models\Screen;
use Illuminate\Support\Facades\Storage;

/**
 * Soát **ảnh** của kho màn hình công khai: vị trí nào có ảnh của chính nó, vị
 * trí nào đang mượn một ảnh thay thế, vị trí nào không có gì.
 *
 * ══ Vì sao có lớp này ══
 *
 * Lát 5 của `docs/PLAN-CHI-TIET-VI-TRI.md` thêm `photo_source` vào
 * `GET /api/v2/screens/{slug}`, và đo ngay sau đó trên production cho
 * **104/104** vị trí dùng ảnh bìa chủ sở hữu — cả kho hiện ra bằng đúng hai
 * tấm ảnh. Nhưng phép đo ấy đi qua đường công khai, và đường công khai chỉ
 * trả **ảnh đầu tiên**. Nó không nói được vị trí nào có 1 ảnh và vị trí nào có
 * 10, phần tử nào là URL ngoài, hay tệp nào đã mất khỏi đĩa.
 *
 * Ở 104 vị trí còn đếm tay được. Kho sắp lên 10.000 (`docs/PLAN-CHI-TIET-VI-TRI.md`
 * → `plan nhập kho`), và ở quy mô đó một lượt đếm tay không lặp lại được.
 *
 * ══ Dùng lại đúng chuỗi dự phòng, không viết lại ══
 *
 * Phân loại ba trạng thái đọc `Screen::displayPhotoWithSource()` — cùng cái
 * hàm mà `photo_source` của API trả về. Viết lại luật ở đây là tạo bản thứ
 * hai của một phép phân loại, và hai bản sẽ lệch: đúng hình dạng lỗi R38 mà
 * `CLAUDE.md` mục 1 dặn đừng lặp lại. Nên **con số của lệnh này và con số
 * người mua thấy trên trang luôn là một**.
 *
 * ══ Hai thứ chỉ CSDL biết, đường công khai không ══
 *
 *  - **Phần tử là URL đầy đủ.** `ScreenDetailResource` bọc mọi phần tử bằng
 *    `asset('storage/' . $p)` trong khi hai accessor của `ScreenSpec` xử lý
 *    đúng `http(s)://`. Nên một hàng `photos: ["https://…"]` hiện bình thường
 *    ở thẻ danh sách và **vỡ** ở trang chi tiết. Con số này là đầu vào của
 *    lát 1, và nó phải đo được **trước** khi sửa để biết đã có bao nhiêu hàng
 *    như vậy trong CSDL.
 *  - **Tệp đã mất khỏi đĩa.** Một hàng khai có ảnh mà tệp không còn thì tệ hơn
 *    một hàng không ảnh: trang dựng `<img>` trỏ vào 404 và không khối nào nói
 *    gì cả.
 *
 * ══ Phạm vi: màn hình công khai ══
 *
 * `Screen::publiclyVisible()` — màn hình đang bật của chủ sở hữu đang hoạt
 * động. Đó là tập người mua nhìn thấy, nên nó là tập có ảnh hay không ảnh gây
 * hậu quả. Số màn hình **ngoài** phạm vi vẫn được trả về (`ngoai_pham_vi`), vì
 * một báo cáo im lặng về phạm vi của mình bị đọc như một báo cáo đầy đủ.
 */
class SoatAnhKhoService
{
    public const CO_ANH    = 'anh_that';
    public const THAY_THE  = 'thay_the';
    public const KHONG_CO  = 'khong_co';

    /** Chữ cho ba trạng thái. Nằm ở đây để lệnh và (sau này) `/admin` dùng chung. */
    public const NHAN = [
        self::CO_ANH   => 'Có ảnh của chính vị trí',
        self::THAY_THE => 'Đang mượn ảnh thay thế',
        self::KHONG_CO => 'Không có ảnh nào',
    ];

    /** Chữ cho từng bậc thay thế — cùng bộ giá trị với `photo_source` của API. */
    public const NHAN_NGUON = [
        'site'           => 'banner địa điểm',
        'network'        => 'banner mạng lưới',
        'owner_cover'    => 'ảnh bìa chủ sở hữu',
        'venue_category' => 'ảnh mặc định loại điểm đặt',
    ];

    /** Thư mục mà biểu mẫu tải ảnh của Filament ghi vào. */
    public const THU_MUC_ANH = 'screen-photos';

    /** Số màn hình nạp mỗi lượt. Kho 10.000 không được nạp hết vào bộ nhớ. */
    private const CO_LO = 500;

    /**
     * @param  bool  $doDia  đi bộ qua thư mục ảnh để đo dung lượng. Ở 10.000 vị
     *                       trí × 10 ảnh là 100.000 lần `stat`, nên gọi được
     *                       mà không cần nó.
     */
    public function soat(bool $doDia = true): array
    {
        $tong      = [self::CO_ANH => 0, self::THAY_THE => 0, self::KHONG_CO => 0];
        $theoNguon = [];
        $theoOwner = [];
        $soAnh     = [];

        $urlNgoai = ['so_man_hinh' => 0, 'so_phan_tu' => 0, 'vi_du' => []];
        $mat      = ['so_man_hinh' => 0, 'so_phan_tu' => 0, 'vi_du' => []];

        $dia = Storage::disk('public');

        Screen::publiclyVisible()
            // Nạp sẵn đúng những quan hệ mà chuỗi dự phòng đi qua. Thiếu một
            // cái là 10.000 truy vấn thêm — và `displayPhotoWithSource()` chỉ
            // chạm tới chúng khi bậc trước không có, nên một lượt chạy trên
            // kho sạch ảnh sẽ chạm **cả bốn**.
            ->with([
                'spec:id,screen_id,photos,photo_url',
                'site:id,name,banner,network_id',
                'site.network:id,banner',
                'owner:id,name,cover_url',
                'inventory:id,screen_id,vn_category_id',
                'inventory.vnCategory:id,thumb',
            ])
            ->chunkById(self::CO_LO, function ($lo) use (
                &$tong, &$theoNguon, &$theoOwner, &$soAnh, &$urlNgoai, &$mat, $dia, $doDia
            ) {
                foreach ($lo as $man) {
                    $nguon    = $man->displayPhotoWithSource()['source'];
                    $trangThai = match (true) {
                        $nguon === 'screen' => self::CO_ANH,
                        $nguon === null     => self::KHONG_CO,
                        default             => self::THAY_THE,
                    };

                    $tong[$trangThai]++;

                    if ($trangThai === self::THAY_THE) {
                        $theoNguon[$nguon] = ($theoNguon[$nguon] ?? 0) + 1;
                    }

                    $khoa = (string) $man->owner_id;
                    $theoOwner[$khoa] ??= [
                        'owner_id'   => $man->owner_id,
                        'owner_name' => trim((string) $man->owner?->name),
                        'tong'       => 0,
                        self::CO_ANH => 0, self::THAY_THE => 0, self::KHONG_CO => 0,
                        'theo_nguon' => [],
                    ];
                    $theoOwner[$khoa]['tong']++;
                    $theoOwner[$khoa][$trangThai]++;

                    if ($trangThai === self::THAY_THE) {
                        $theoOwner[$khoa]['theo_nguon'][$nguon]
                            = ($theoOwner[$khoa]['theo_nguon'][$nguon] ?? 0) + 1;
                    }

                    $duong = $this->duongAnh($man);

                    if ($duong !== []) {
                        $soAnh[] = count($duong);
                    }

                    $this->demUrlNgoai($man, $duong, $urlNgoai);

                    if ($doDia) {
                        $this->demTepMat($man, $duong, $dia, $mat);
                    }
                }
            });

        return [
            'luc'           => now(),
            'tong_man_hinh' => array_sum($tong),

            // Màn hình đã tắt, hoặc của chủ sở hữu không còn hoạt động. Không
            // ai thấy ảnh của chúng, nên chúng không nằm trong phép đo — nhưng
            // số của chúng phải nói ra, không thì tổng ở trên bị đọc thành
            // "cả kho".
            //
            // `withoutGlobalScope('owner_scope')` chứ không `withoutGlobalScopes()`:
            // dạng số nhiều gỡ luôn scope của `SoftDeletes`, nên nó đếm cả màn
            // hình đã xoá — và một con số "ngoài phạm vi" phình lên vì bản ghi
            // đã xoá thì không nói gì về kho đang sống.
            'ngoai_pham_vi' => Screen::query()->withoutGlobalScope('owner_scope')->count() - array_sum($tong),

            'tong'       => $tong,
            'theo_nguon' => $theoNguon,
            'theo_owner' => array_values($theoOwner),
            'so_anh'     => $this->thongKeSoAnh($soAnh),
            'url_ngoai'  => $urlNgoai,
            'tep_mat'    => $doDia ? $mat : null,
            'dia'        => $doDia ? $this->doDia($dia) : null,
        ];
    }

    /**
     * Danh sách đường dẫn ảnh **thô** của một màn hình, đúng như trong CSDL.
     *
     * Không đi qua accessor `photoUrls`: accessor trả URL đã dựng xong, và
     * lệnh này cần biết trong cột có gì — một đường dẫn tương đối hay một URL
     * đầy đủ. Đó chính là thứ phân biệt hai đường đọc đang lệch nhau.
     *
     * @return list<string>
     */
    private function duongAnh(Screen $man): array
    {
        $tho = $man->spec?->photos;

        if (! is_array($tho) || $tho === []) {
            $tho = $man->spec?->photo_url ? [$man->spec->photo_url] : [];
        }

        return array_values(array_filter(
            array_map(fn ($p) => is_string($p) ? trim($p) : '', $tho),
            fn (string $p) => $p !== '',
        ));
    }

    private function laUrl(string $duong): bool
    {
        return str_starts_with($duong, 'http://') || str_starts_with($duong, 'https://');
    }

    /** @param list<string> $duong */
    private function demUrlNgoai(Screen $man, array $duong, array &$ra): void
    {
        $url = array_values(array_filter($duong, fn (string $p) => $this->laUrl($p)));

        if ($url === []) {
            return;
        }

        $ra['so_man_hinh']++;
        $ra['so_phan_tu'] += count($url);

        if (count($ra['vi_du']) < 5) {
            $ra['vi_du'][] = ['slug' => $man->slug, 'duong' => $url[0]];
        }
    }

    /** @param list<string> $duong */
    private function demTepMat(Screen $man, array $duong, $dia, array &$ra): void
    {
        // URL ngoài không kiểm được ở đây: kiểm nó là đi gọi mạng, và một lệnh
        // soát không được treo vì máy chủ của người khác chậm.
        $noBo = array_values(array_filter($duong, fn (string $p) => ! $this->laUrl($p)));
        $thieu = array_values(array_filter($noBo, fn (string $p) => ! $dia->exists($p)));

        if ($thieu === []) {
            return;
        }

        $ra['so_man_hinh']++;
        $ra['so_phan_tu'] += count($thieu);

        if (count($ra['vi_du']) < 5) {
            $ra['vi_du'][] = ['slug' => $man->slug, 'duong' => $thieu[0]];
        }
    }

    /**
     * Trung vị, không trung bình.
     *
     * Một vị trí có 10 ảnh kéo trung bình của cả kho lên trong khi 99 vị trí
     * còn lại có 1 ảnh. Trung vị nói đúng "vị trí điển hình có mấy ảnh".
     *
     * @param  list<int>  $soAnh
     */
    private function thongKeSoAnh(array $soAnh): array
    {
        if ($soAnh === []) {
            return ['so_vi_tri_co_anh' => 0, 'trung_vi' => null, 'lon_nhat' => null, 'tong_phan_tu' => 0];
        }

        sort($soAnh);
        $n = count($soAnh);
        $g = intdiv($n, 2);

        return [
            'so_vi_tri_co_anh' => $n,
            'trung_vi'         => $n % 2 === 1 ? $soAnh[$g] : ($soAnh[$g - 1] + $soAnh[$g]) / 2,
            'lon_nhat'         => $soAnh[$n - 1],
            'tong_phan_tu'     => array_sum($soAnh),
        ];
    }

    private function doDia($dia): array
    {
        if (! $dia->exists(self::THU_MUC_ANH)) {
            return ['thu_muc' => self::THU_MUC_ANH, 'ton_tai' => false, 'so_tep' => 0, 'bytes' => 0, 'lon_nhat' => null];
        }

        $soTep   = 0;
        $bytes   = 0;
        $lonNhat = null;

        foreach ($dia->allFiles(self::THU_MUC_ANH) as $tep) {
            $co = $dia->size($tep);
            $soTep++;
            $bytes += $co;

            if ($lonNhat === null || $co > $lonNhat['bytes']) {
                $lonNhat = ['duong' => $tep, 'bytes' => $co];
            }
        }

        return [
            'thu_muc'  => self::THU_MUC_ANH,
            'ton_tai'  => true,
            'so_tep'   => $soTep,
            'bytes'    => $bytes,
            'lon_nhat' => $lonNhat,
        ];
    }
}
