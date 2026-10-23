<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một ô lưới trên bản đồ: toạ độ tâm và số màn hình trong ô.
 *
 * Ba trường, và đó là toàn bộ. Không tên, không slug, không giá, không owner —
 * một cụm **không** là một đối tượng trong kho, nó là một phép đếm. Thêm bất
 * cứ thứ gì nhận dạng được vào đây là bắt đầu rò dữ liệu qua một đường mà
 * không ai nghĩ là một đường danh sách: ở mức thu phóng rộng, một ô có thể chứa
 * đúng một màn hình, và khi đó "tâm cụm" là toạ độ của nó.
 *
 * Giữ nguyên nguyên tắc của `MapPinResource`: liệt kê trường muốn trả, không
 * loại trường muốn ẩn.
 */
class MapClusterResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,

            // `count`, không `so` — phần còn lại của `/api/v2` dùng tiếng Anh
            // cho khoá JSON (`total`, `returned`, `venue_type`), và một khoá
            // tiếng Việt lẻ ở đây sẽ đi vào TypeScript sinh từ OpenAPI.
            'count' => (int) $this->so,
        ];
    }
}
