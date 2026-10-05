<?php

namespace App\Policies;

use App\Models\Creative;
use App\Models\User;

/**
 * Quyền đọc **tệp nội dung quảng cáo**.
 *
 * Tệp này trước đây nằm trên disk `public`, nên bất kỳ ai biết đường dẫn đều
 * tải được, không cần đăng nhập (CLAUDE.md mục 5 nêu đúng "nội dung quảng cáo"
 * trong nhóm phải để disk riêng). Nay nó nằm trên disk `private` và chỉ ra
 * ngoài qua một route có kiểm policy — tức phép kiểm này là **toàn bộ** phân
 * quyền của tệp, không còn lớp nào khác đỡ.
 *
 * Ba nhóm được xem, và phạm vi của nhóm thứ hai hẹp có chủ ý:
 *
 * 1. **Người mua của chiến dịch** — nội dung của chính họ.
 * 2. **Media owner** — nhưng chỉ nội dung đã gán vào dòng đặt chỗ **của họ**,
 *    không phải mọi nội dung trong chiến dịch.
 * 3. **Quản trị sàn** (`super_admin`) — duyệt nội dung là việc của họ.
 *
 * ══ Vì sao nhóm 2 không dùng `CampaignPolicy::viewAsOwner()` ══
 *
 * Dùng nó sẽ gọn hơn một dòng, nhưng nó trả lời câu "owner này có dòng nào
 * trong chiến dịch không" — rộng hơn câu đang cần hỏi. Một chiến dịch có thể
 * có nội dung riêng cho từng màn hình: nhạc hiệu cho sân bay, bản không tiếng
 * cho thang máy. Owner A thấy nội dung gán cho màn hình của owner B là rò đúng
 * kiểu Codex R01 đã mắc — ở đó một quyền đọc dùng chung cho hai màn hình có
 * phạm vi dữ liệu khác nhau, và màn hình rộng hơn đã rò.
 *
 * Nên ở đây hỏi hẹp: nội dung này có nằm trên dòng đặt chỗ của owner đang
 * chọn hay không. Chưa gán vào dòng nào thì owner **không** thấy gì — mặc định
 * đóng, vì chưa gán nghĩa là chưa ai nói nó dành cho màn hình nào.
 */
class CreativePolicy
{
    public function view(User $user, Creative $creative): bool
    {
        $campaign = $creative->campaign;

        if (! $campaign) {
            return false;
        }

        // 1. Người mua của chiến dịch.
        if ($user->can('view', $campaign)) {
            return true;
        }

        // 2. Media owner, chỉ với nội dung đã gán vào dòng của chính họ.
        if ($this->attachedToOwnerLine($user, $creative)) {
            return true;
        }

        // 3. Quản trị sàn. Không có `Gate::before` nào trong dự án này, nên
        // quyền của quản trị phải khai ở đây chứ không tự có.
        return $user->hasRole('super_admin');
    }

    private function attachedToOwnerLine(User $user, Creative $creative): bool
    {
        $ownerId = $user->current_owner_id;

        if (! $ownerId) {
            return false;
        }

        // Chỉ owner đang được chọn, và phải thật sự thuộc về người dùng này —
        // `current_owner_id` là lựa chọn giao diện, không phải bằng chứng
        // quyền.
        if (! $user->owners()->whereKey($ownerId)->exists()) {
            return false;
        }

        return $creative->bookingLines()
            ->where('booking_lines.owner_id', $ownerId)
            ->exists();
    }
}
