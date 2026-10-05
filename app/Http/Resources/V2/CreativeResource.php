<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một nội dung quảng cáo đã tải lên — DTO danh sách trắng.
 *
 * ══ `file_path` không ra ngoài, nhưng `download_url` thì có ══
 *
 * `file_path` là đường dẫn trên đĩa — một chi tiết lưu trữ, và đổi chỗ lưu thì
 * nó đổi theo. Thứ bên tiêu thụ cần là một cách lấy tệp, không phải vị trí của
 * nó.
 *
 * `download_url` là URL **ký hạn** tới route `creatives.file`. Route đó kiểm
 * `CreativePolicy` chứ không chỉ kiểm ký, nên URL rò ra ngoài vẫn vô dụng với
 * người không có quyền — xem `CreativeFileController` để biết vì sao hai lớp
 * chứ không một.
 *
 * ══ Lịch sử, để không ai quay lại chỗ cũ ══
 *
 * Tới 03/10/2026 nội dung quảng cáo lưu trên disk **public**, nên tệp tải được
 * qua `/storage/creatives/...` **không cần đăng nhập**. Đường dẫn gồm id chiến
 * dịch và tên tệp băm nên khó đoán, nhưng khó đoán không phải phân quyền — và
 * CLAUDE.md mục 5 nêu đúng "nội dung quảng cáo" trong nhóm phải để disk riêng.
 *
 * Trong thời gian đó DTO này **không** trả URL nào, vì trả ra là phát đi một
 * đường dẫn công khai tới nội dung chưa duyệt của người mua. Giờ chỗ lưu đã
 * đúng, nên URL ra ngoài được.
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

            // URL ký hạn, sống theo `config('creatives.url_ttl_minutes')`.
            // Hết hạn thì gọi lại endpoint này để lấy URL mới — đừng lưu nó
            // vào CSDL hay cache lâu hơn chính cái hạn.
            'download_url' => $this->file_url,

            'status'      => $this->status,
            'reviewed_at' => $this->reviewed_at?->toJSON(),

            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
