<?php

namespace App\Rules;

use App\Services\AnhDanXuatService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Ảnh tải lên phải nằm dưới trần **số điểm ảnh**, không chỉ dưới trần dung lượng.
 *
 * ══ Vì sao dung lượng không đủ ══
 *
 * Lát 2 đặt `maxSize(8192)` — 8MB mỗi tệp. Nhưng dung lượng tệp và số điểm ảnh
 * là hai thang khác nhau: một PNG 8MB toàn một màu có thể là 20.000×20.000 =
 * **400 triệu điểm**. Nén lossless của PNG xoá sạch mọi vùng phẳng, nên tệp
 * nhỏ mà ảnh khổng lồ. WebP lossless cũng vậy.
 *
 * Lát 2 ghi lỗ hổng này ra và để mở, với lý do đúng ở thời điểm đó: không mã
 * nào phía máy chủ giải nén những ảnh này nên nó chưa gây hại.
 *
 * ══ Vì sao bây giờ phải đóng, và hai chiều hại khác nhau ══
 *
 * **Chiều thứ nhất — máy chủ.** Lát 4 là mã giải nén ảnh. GD giữ ảnh ở
 * truecolor, 4 byte một điểm, nên 400 triệu điểm là 1,6GB. `AnhDanXuatService`
 * đã chặn chiều này: nó đo kích thước từ đầu tệp trước khi mở, và từ chối trên
 * trần. Nên hôm nay một ảnh bom **không** làm sập gì.
 *
 * **Chiều thứ hai — người mua, và chiều này chưa ai chặn.** Ảnh bom vẫn **vào
 * kho và ra trang**: `photo_urls` trả nó, `<img src>` trỏ vào nó, và vì không
 * sinh được bản dẫn xuất nên `srcset` vắng mặt — tức mọi trình duyệt tải đúng
 * bản gốc. Một ảnh 400 triệu điểm làm treo tab trên điện thoại. Lớp dưới
 * (`AnhDanXuatService`) hỏng **theo chiều an toàn cho máy chủ** nhưng **theo
 * chiều nguy hiểm cho người xem**, và một lớp phòng thủ hỏng theo hai chiều
 * trái nhau thì không phải một lớp phòng thủ đủ.
 *
 * ══ Vì sao là luật riêng, không phải `dimensions:` của Laravel ══
 *
 * `dimensions:max_width=4000,max_height=4000` chặn theo **từng cạnh**. Trần
 * thật là số điểm ảnh, vì đó là thứ quyết định bộ nhớ GD. Hai thang ấy không
 * thay nhau được: 20.000×100 qua được mọi trần điểm ảnh hợp lý mà vẫn vượt
 * `max_width`, còn 3.900×3.900 lọt `max_width=4000` mà là 15 triệu điểm — trên
 * trần 12 triệu, nên nó sẽ **vào kho rồi không bao giờ có bản dẫn xuất**. Đó
 * đúng là khoảng hở mà luật này sinh ra để đóng.
 *
 * Và một chi tiết nữa của luật đó: nó **cho SVG qua vô điều kiện**
 * (`ValidatesAttributes::validateDimensions()` dòng 760-762). Ở đây vô hại vì
 * `acceptedFileTypes` chỉ nhận jpeg/png/webp, nhưng nó cho thấy `dimensions:`
 * không phải một hàng rào kích thước tổng quát.
 *
 * ══ Vì sao dùng chung ĐÚNG hằng số của `AnhDanXuatService` ══
 *
 * Biểu mẫu nhận ⟹ trần cố định của service cho qua. Hai con số riêng là hai
 * con số sẽ lệch, và lệch theo chiều nào cũng tệ: biểu mẫu rộng hơn thì ảnh
 * vào kho mà vĩnh viễn không có bản dẫn xuất và không ai biết; biểu mẫu hẹp
 * hơn thì người dùng bị từ chối một ảnh hệ thống xử lý được. Có một ca chốt
 * đòi hai chỗ đọc cùng một hằng.
 *
 * Service **vẫn** có thêm phép kiểm bộ nhớ còn lại lúc chạy, và luật này
 * không nhắc lại nó: bộ nhớ còn lại là điều kiện nhất thời của một tiến trình,
 * không phải một chính sách. Từ chối một ảnh vì lúc ấy máy đang tải cao là từ
 * chối sai.
 */
class AnhKhongQuaNhieuDiem implements ValidationRule
{
    public function __construct(
        /** Tiêm vào được để chốt đo cả hai chiều mà không cần dựng ảnh thật khổng lồ. */
        private readonly int $toiDaPixel = AnhDanXuatService::TOI_DA_PIXEL,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            // Không phải tệp: `file`, `mimetypes:` và cổng upload của Livewire
            // đã lo. Luật này chỉ trả lời **một** câu hỏi, và trả lời thêm câu
            // khác là hai chỗ nói về cùng một thứ.
            return;
        }

        $tt = $this->doKichThuoc($value);

        if ($tt === false || ($tt[0] ?? 0) < 1 || ($tt[1] ?? 0) < 1) {
            // Tới được đây nghĩa là `mimetypes:` đã nhận tệp theo nội dung,
            // nên một tệp không đo được kích thước là tệp hỏng hoặc bị cắt.
            // Từ chối, không cho qua: một ảnh không đo được là một ảnh không
            // chặn được.
            $fail('Không đọc được kích thước của ảnh này. Tệp có thể bị hỏng hoặc tải lên chưa xong — vui lòng thử lại.');

            return;
        }

        [$w, $h] = [(int) $tt[0], (int) $tt[1]];
        $diem = $w * $h;

        if ($diem <= $this->toiDaPixel) {
            return;
        }

        $fail(sprintf(
            'Ảnh %s×%s điểm là quá lớn (%s triệu điểm, trần là %s triệu). '
            . 'Kích thước tệp không phải vấn đề — số điểm ảnh mới là. '
            . 'Vui lòng xuất lại ảnh với cạnh dài khoảng 2000px.',
            // Dấu phân cách nghìn theo kiểu Việt: `2.000`, không `2,000`.
            number_format($w, 0, ',', '.'),
            number_format($h, 0, ',', '.'),
            $this->trieu($diem),
            $this->trieu($this->toiDaPixel),
        ));
    }

    /**
     * Bề rộng × bề cao, đọc từ **đầu tệp** — chưa giải nén.
     *
     * Nếu phép kiểm chống ảnh bom tự nó phải mở ảnh ra thì nó chính là lỗ hổng
     * nó đi vá. `getimagesize()` chỉ đọc header.
     *
     * Hai nhánh, chép đúng `ValidatesAttributes::validateDimensions()` của
     * Laravel (dòng 768-770): `TemporaryUploadedFile` của Livewire có hàm
     * `dimensions()` **riêng** vì tệp tạm có thể không nằm trên đĩa cục bộ —
     * nó sao stream ra một `tmpfile()` rồi mới đo. Gọi thẳng `getRealPath()`
     * cho mọi trường hợp là đúng với disk `local` và **im lặng sai** với một
     * disk từ xa: `getimagesize()` nhận một đường dẫn không mở được, trả
     * `false`, và luật này từ chối **mọi** ảnh.
     *
     * @return array<int, mixed>|false
     */
    private function doKichThuoc(UploadedFile $tep): array|false
    {
        if (method_exists($tep, 'dimensions')) {
            return $tep->dimensions() ?: false;
        }

        $duong = $tep->getRealPath();

        return is_string($duong) && $duong !== '' ? @getimagesize($duong) : false;
    }

    /** "12" cho 12.000.000, "0,5" cho 500.000 — không in "0 triệu". */
    private function trieu(int $diem): string
    {
        $t = $diem / 1_000_000;

        return $t >= 1
            ? number_format($t, $t === floor($t) ? 0 : 1, ',', '.')
            : number_format($t, 2, ',', '.');
    }
}
