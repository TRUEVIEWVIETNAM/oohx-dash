<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\FrontpageListingRequest;
use Illuminate\Validation\Validator;

/**
 * Khung nhìn **bắt buộc** cho endpoint bản đồ (CLAUDE.md mục 2).
 *
 * Lý do bắt buộc chứ không mặc định: không có khung nhìn thì "lấy pin bản đồ"
 * nghĩa là lấy **mọi màn hình có toạ độ**. Đó là một endpoint xuất toàn bộ kho
 * dưới cái tên vô hại, và không ai nhận ra cho tới khi kho đủ lớn để nó sập.
 * Bắt buộc thì đường gọi nào quên cũng đỏ ngay ở 422, không âm thầm chạy được.
 *
 * Kế thừa `FrontpageListingRequest` để **dùng chung một bộ luật lọc** với
 * trang công khai và với `/api/v2/screens`: bản đồ và danh sách lọc khác nhau
 * là một lỗi rất khó thấy, vì cả hai đều trả về thứ trông hợp lý.
 */
class MapViewportRequest extends FrontpageListingRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'north' => 'required|numeric|between:-90,90',
            'south' => 'required|numeric|between:-90,90',
            'east'  => 'required|numeric|between:-180,180',
            'west'  => 'required|numeric|between:-180,180',
            'limit' => 'nullable|integer|min:1',
        ]);
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['north', 'south'])) {
                    return;
                }

                if ((float) $this->input('north') <= (float) $this->input('south')) {
                    $validator->errors()->add(
                        'north',
                        'Cạnh bắc phải lớn hơn cạnh nam. Khung nhìn lộn ngược trả về rỗng mà không báo gì.'
                    );
                }
            },
        ];
    }

    /** @return array{north: float, south: float, east: float, west: float} */
    public function viewport(): array
    {
        return [
            'north' => (float) $this->input('north'),
            'south' => (float) $this->input('south'),
            'east'  => (float) $this->input('east'),
            'west'  => (float) $this->input('west'),
        ];
    }
}
