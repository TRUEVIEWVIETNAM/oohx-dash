<?php

namespace App\Observers;

use App\Models\ScreenSpec;
use App\Services\AnhDanXuatService;
use Illuminate\Support\Facades\Log;

/**
 * Ảnh vừa vào cột thì bản dẫn xuất phải có ngay sau đó.
 *
 * ══ Vì sao là observer, không phải một móc trong biểu mẫu Filament ══
 *
 * `CLAUDE.md` mục 1: *"Mọi nghiệp vụ nằm ở Service/Action. Filament, API, lệnh
 * artisan, job đều gọi cùng một service."* Móc vào `FileUpload` của
 * `BaseScreenResource` thì chỉ **một** đường ghi có bản dẫn xuất; đường nhập
 * kho (`app/Services/ScreenImport/`), seeder, và một lượt `tinker` thì không —
 * và ảnh của chúng ra trang công khai không có `srcset` mà không gì báo.
 *
 * Observer đứng ở chỗ cột `photos` thật sự đổi, nên mọi đường ghi đi qua nó.
 *
 * ══ Vì sao một lỗi ở đây KHÔNG được làm vỡ lượt lưu ══
 *
 * Bản dẫn xuất là một phép tối ưu: thiếu nó thì `srcsetCho()` trả `null`,
 * `<img>` không có `srcset`, và trang vẫn hiện đúng ảnh gốc. Để một lượt
 * resize thất bại ném ngược ra là biến một phép tối ưu thành điều kiện để lưu
 * được một màn hình — người dùng mất cả biểu mẫu vì một tệp GD không đọc nổi.
 *
 * Nên mọi lỗi vào log ở mức `warning` kèm đủ thứ để tìm lại: id của spec và
 * đường dẫn ảnh. Lệnh `oohx:sinh-anh-dan-xuat --soat` là chỗ đếm lại xem còn
 * bao nhiêu ảnh chưa có bản dẫn xuất, nên một lượt rơi ở đây không im lặng
 * mãi.
 */
class ScreenSpecObserver
{
    public function __construct(private readonly AnhDanXuatService $anh)
    {
    }

    public function created(ScreenSpec $spec): void
    {
        $this->sinh($spec);
    }

    /**
     * Chỉ khi cột ảnh đổi.
     *
     * Một lượt lưu đổi `width_px` không phải lúc đi `stat` mười tệp ảnh.
     *
     * `wasChanged()` chứ không `isDirty()`: trong `updated` thì thay đổi đã
     * được áp, và `isDirty()` lúc đó đã rỗng.
     */
    public function updated(ScreenSpec $spec): void
    {
        if ($spec->wasChanged('photos') || $spec->wasChanged('photo_url')) {
            $this->sinh($spec);
        }
    }

    private function sinh(ScreenSpec $spec): void
    {
        foreach ($spec->photoPaths() as $duong) {
            try {
                $ra = $this->anh->sinhCho($duong);
            } catch (\Throwable $e) {
                Log::warning('Không sinh được bản dẫn xuất của ảnh màn hình.', [
                    'screen_spec_id' => $spec->getKey(),
                    'duong'          => $duong,
                    'loi'            => $e::class . ': ' . $e->getMessage(),
                ]);

                continue;
            }

            // Ba kết quả dưới đây là bình thường và không cần nói gì: đã có
            // đủ, bản gốc nhỏ hơn mọi bề rộng, hoặc ảnh nằm ở máy chủ khác.
            if (in_array($ra['ket_qua'], [
                AnhDanXuatService::KQ_DA_SINH,
                AnhDanXuatService::KQ_DA_CO,
                AnhDanXuatService::KQ_GOC_NHO,
                AnhDanXuatService::KQ_URL_NGOAI,
            ], true)) {
                continue;
            }

            Log::warning('Ảnh màn hình không có bản dẫn xuất.', [
                'screen_spec_id' => $spec->getKey(),
                'duong'          => $duong,
                'ket_qua'        => $ra['ket_qua'],
                'ly_do'          => $ra['ly_do'],
            ]);
        }
    }
}
