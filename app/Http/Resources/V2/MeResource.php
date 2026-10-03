<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Người đang đăng nhập — DTO danh sách trắng, dùng để dựng header.
 *
 * ══ Phạm vi hẹp có chủ ý ══
 *
 * Đây là endpoint mà **mọi trang công khai** gọi tới, từ trình duyệt, trên mọi
 * lượt duyệt. Nên nó chỉ trả đúng những gì thanh điều hướng cần vẽ: tên, chữ
 * cái đầu cho avatar, email, tổ chức đang chọn, và số món trong giỏ.
 *
 * Không trả `id`, không trả danh sách tổ chức, không trả vai trò, không trả
 * `email_verified_at`. Mỗi trường thêm vào đây là một trường bị phát ra hàng
 * nghìn lần mỗi ngày cho một việc nó không phục vụ — và là một trường phải
 * nghĩ lại khi có người hỏi "ai được thấy cái này".
 *
 * ══ `organization` chỉ có `name` ══
 *
 * Header chỉ in tên tổ chức. Trả `id` ra là mời bên tiêu thụ dùng nó để gọi
 * tiếp, và khi đó phạm vi của endpoint này âm thầm rộng ra.
 */
class MeResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Số món giỏ nhận qua constructor, không tự đi lấy.
     *
     * Resource gọi service là mở một đường truy vấn thứ hai từ tầng trình bày,
     * và nó sẽ chạy mỗi lần resource được dựng — kể cả trong một vòng lặp.
     * Controller tính một lần rồi truyền vào.
     */
    public function __construct($resource, private readonly int $cartCount)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'name'  => $this->name,
            'email' => $this->email,

            // Chữ cái đầu, tính ở máy chủ.
            //
            // `mb_substr` chứ không `substr`: tên tiếng Việt có ký tự nhiều
            // byte, và cắt theo byte cho ra nửa ký tự — hiện thành dấu hỏi
            // trong ô avatar. Bản Blade dùng `mb_substr`, giữ nguyên.
            'initial' => mb_strtoupper(mb_substr((string) $this->name, 0, 1)),

            'organization' => $this->currentOrganization
                ? ['name' => $this->currentOrganization->name]
                : null,

            // Số món trong giỏ, để vẽ badge. Controller lấy từ `CartService` —
            // cùng hàm header Blade gọi, nên hai bản không thể lệch số.
            'cart_count' => $this->cartCount,
        ];
    }
}
