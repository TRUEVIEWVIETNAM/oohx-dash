<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tham số cho `GET /api/v2/screens/{slug}/availability` — lịch trống theo ngày.
 *
 * ══ `months` có giới hạn cứng, và giới hạn nằm ở ĐÂY ══
 *
 * Endpoint trả một phần tử cho **mỗi ngày**. Không có trần thì `?months=240`
 * là 7.300 phần tử mỗi lần gọi, trên một đường công khai không cần đăng nhập —
 * cùng loại cửa mà `MapViewportRequest` đóng bằng khung nhìn bắt buộc
 * (CLAUDE.md mục 2: *danh sách phải có giới hạn cứng*).
 *
 * Trần là **3 tháng**. Bản Blade vẽ 2 tháng; để 3 cho client có chỗ xin thêm
 * một tháng mà không phải đổi hợp đồng, nhưng không hơn: lịch xa hơn thế là
 * một câu hỏi về kế hoạch bán, không phải về chỗ trống.
 *
 * Và trần phải chặn ở tầng request, không phải ở controller bằng `min()`:
 * `months=99` phải là **422 nhìn thấy được**, chứ không phải lặng lẽ trả 3
 * tháng rồi để client tưởng mình đã có 99.
 */
class ScreenAvailabilityRequest extends FormRequest
{
    public const MAX_MONTHS = 3;

    public const DEFAULT_MONTHS = 2;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'months' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_MONTHS],
        ];
    }

    public function months(): int
    {
        $months = $this->integer('months', self::DEFAULT_MONTHS);

        return max(1, min(self::MAX_MONTHS, $months));
    }
}
