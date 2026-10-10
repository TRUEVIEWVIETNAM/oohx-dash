<?php

namespace App\Http\Resources\V2;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Trang cài đặt khu người mua — DTO danh sách trắng.
 *
 * ══ `tax_id` ở đây là ĐÚNG, và nó cần một câu giải thích ══
 *
 * CLAUDE.md §2 cấm phát `tax_code` ra ngoài. Trường đó là mã số thuế của
 * **media owner**, và nó chỉ ra qua đúng một đường đã duyệt
 * (`payment-recipients`). `organizations.tax_id` là một trường khác: mã số
 * thuế của **chính tổ chức người đang đăng nhập**, trên trang họ sửa nó. Dữ
 * liệu của họ, trả cho họ.
 *
 * Hai tên gần nhau nên dễ lẫn, và đó chính là lý do câu này ở đây: ai đọc
 * `tax_id` trong một DTO sẽ dừng lại kiểm, và đọc được ngay câu trả lời.
 *
 * ══ `permissions` không phải để ẩn nút cho đẹp ══
 *
 * Bản Blade cũ **luôn** render biểu mẫu tổ chức, kể cả cho vai trò `viewer`
 * mà chính mô tả trong mã nói là "chỉ xem, không chỉnh sửa". Người đó điền
 * xong, bấm Lưu, và nhận 403 — policy chặn đúng, nhưng giao diện đã mời họ
 * làm một việc không được phép.
 *
 * `can_update_organization` tồn tại để sửa đúng chuyện đó. Nó KHÔNG thay
 * policy: `PUT me/organization` vẫn gọi `Gate` và vẫn 403. Nó chỉ để trang
 * thôi mời người ta làm việc vô ích.
 *
 * ══ Không trả `id` của người dùng ══
 *
 * Trang này sửa đúng một người — người đang đăng nhập. Một `id` ở đây là mời
 * bên tiêu thụ gửi nó lên trong `PUT`, và khi đó endpoint phải nghĩ về việc ai
 * sửa được của ai. Không trả thì không có câu hỏi đó.
 */
class SettingsResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        private readonly User $nguoiDung,
        private readonly Organization $toChuc,
        private readonly bool $suaDuocToChuc,
    ) {
        parent::__construct($nguoiDung);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'name'  => (string) $this->nguoiDung->name,
                'email' => (string) $this->nguoiDung->email,
            ],

            'organization' => [
                'name'          => (string) $this->toChuc->name,
                'billing_email' => $this->toChuc->billing_email,
                'billing_phone' => $this->toChuc->billing_phone,
                'tax_id'        => $this->toChuc->tax_id,
                'website'       => $this->toChuc->website,
            ],

            'permissions' => [
                'can_update_organization' => $this->suaDuocToChuc,
            ],
        ];
    }
}
