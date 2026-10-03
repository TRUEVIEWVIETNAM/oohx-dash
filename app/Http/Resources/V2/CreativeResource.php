<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một nội dung quảng cáo đã tải lên — DTO danh sách trắng.
 *
 * ══ `file_path` KHÔNG ra ngoài, và đây là chỗ cần nói thẳng ══
 *
 * Nội dung quảng cáo nằm trong nhóm tệp nhạy cảm mà CLAUDE.md mục 5 yêu cầu
 * "để disk riêng, truy cập qua URL ký hạn — không để trên disk công khai".
 *
 * Hiện `Buyer\BookingController::uploadCreative()` lưu vào disk **public**
 * (`$file->store('creatives/' . $campaign->id, 'public')`), nên tệp nằm dưới
 * `storage/app/public` và tải được qua `/storage/...` **không cần đăng nhập**.
 * Đường dẫn gồm id campaign cộng tên tệp băm ngẫu nhiên nên khó đoán, nhưng
 * "khó đoán" không phải phân quyền.
 *
 * Nên DTO này **không** trả `file_path` và cũng **không** trả URL. Trả ra là
 * phát đi một đường dẫn công khai tới nội dung chưa duyệt của người mua, và
 * biến một lỗi lưu trữ thành một lỗi lộ dữ liệu.
 *
 * Việc cần làm (ngoài phạm vi mốc này, vì phải chuyển cả tệp đã có): đổi sang
 * disk `private` — đã cấu hình trong `config/filesystems.php` và chưa ai dùng
 * — rồi phát URL ký hạn qua một route có kiểm `CampaignPolicy`.
 */
class CreativeResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'name' => $this->name,
            'type' => $this->type,

            'dimensions' => [
                'width_px'     => $this->width_px !== null ? (int) $this->width_px : null,
                'height_px'    => $this->height_px !== null ? (int) $this->height_px : null,
                'duration_sec' => $this->duration_sec !== null ? (int) $this->duration_sec : null,
            ],

            'file_size' => $this->file_size !== null ? (int) $this->file_size : null,

            'status'      => $this->status,
            'reviewed_at' => $this->reviewed_at?->toJSON(),

            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
