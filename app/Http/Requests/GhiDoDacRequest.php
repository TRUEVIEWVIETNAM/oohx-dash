<?php

namespace App\Http\Requests;

use App\Services\DoDacService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Đầu vào của `POST /api/v2/do-dac` — lát 1 của
 * `docs/PLAN-DO-DAC-TOI-THIEU.md`.
 *
 * Ba trường, không hơn. Không nhận IP, không nhận user agent, không nhận id
 * nào — và đó không phải vì chưa cần, mà vì bảng đích cố ý không có chỗ cho
 * chúng. Xem docblock của migration `create_do_dac_ngay_table`.
 *
 * ══ Danh sách trắng kiểm theo CẶP ══
 *
 * `Rule::in()` trên từng trường rời sẽ nhận `('doi_kieu_xem',
 * 'da_chan_tu_truoc')` — một cặp vô nghĩa mà cả hai nửa đều hợp lệ. Nên phép
 * kiểm đi qua `DoDacService::hopLe()`, nơi danh sách là `sự kiện → giá trị
 * của riêng nó`.
 *
 * Kiểm trong `after()` chứ không bằng `Rule::in`: nếu `su_kien` sai thì
 * thông báo phải nói về `su_kien`, không phải kể ra mọi giá trị hợp lệ của
 * mọi sự kiện.
 */
class GhiDoDacRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // đường công khai, không đăng nhập — xem docblock controller
    }

    public function rules(): array
    {
        return [
            'su_kien'  => ['required', 'string', 'max:40'],
            'gia_tri'  => ['required', 'string', 'max:40'],
            'thiet_bi' => ['required', 'string', 'max:40'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return; // thiếu trường thì chưa tới lượt kiểm danh sách trắng
                }

                $suKien  = (string) $this->input('su_kien');
                $giaTri  = (string) $this->input('gia_tri');
                $thietBi = (string) $this->input('thiet_bi');

                if (! array_key_exists($suKien, DoDacService::CHO_PHEP)) {
                    $validator->errors()->add('su_kien', 'Sự kiện không nằm trong danh sách được đếm.');

                    return;
                }

                if (! in_array($thietBi, DoDacService::THIET_BI, true)) {
                    $validator->errors()->add('thiet_bi', 'Loại thiết bị không hợp lệ.');
                }

                if (! in_array($giaTri, DoDacService::CHO_PHEP[$suKien], true)) {
                    $validator->errors()->add(
                        'gia_tri',
                        'Giá trị không thuộc sự kiện `' . $suKien . '`.',
                    );
                }
            },
        ];
    }
}
