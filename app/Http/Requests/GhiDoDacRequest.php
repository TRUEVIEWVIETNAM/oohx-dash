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
 * ══ Danh sách trắng kiểm theo CẶP, và nó sống ở service ══
 *
 * `Rule::in()` trên từng trường rời sẽ nhận `('doi_kieu_xem',
 * 'da_chan_tu_truoc')` — một cặp vô nghĩa mà cả hai nửa đều hợp lệ. Nên phép
 * kiểm đi qua `DoDacService::loiDauTien()`, nơi danh sách là `sự kiện → giá
 * trị của riêng nó`.
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

                // Luật sống ở service, không ở đây. Bản đầu của tệp này tự
                // tra `DoDacService::CHO_PHEP` và tự dựng lại cùng ba phép
                // kiểm — tức hai bản luật cho một thứ, đúng loại trôi khỏi
                // nhau mà CLAUDE.md mục 1 cấm. Nó cũng làm `hopLe()` của
                // service thành mã chết, không ai gọi và không ai canh.
                $loi = app(DoDacService::class)->loiDauTien(
                    (string) $this->input('su_kien'),
                    (string) $this->input('gia_tri'),
                    (string) $this->input('thiet_bi'),
                );

                if ($loi !== null) {
                    $validator->errors()->add($loi[0], $loi[1]);
                }
            },
        ];
    }
}
