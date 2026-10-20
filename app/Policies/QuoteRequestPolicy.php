<?php

namespace App\Policies;

use App\Models\QuoteRequest;
use App\Models\User;
use App\Services\TenantPermission;

/**
 * Quyền trên yêu cầu báo giá — **một bộ luật cho cả Filament và API** (lát 5).
 *
 * CLAUDE.md mục 4. Hai vai, hai phạm vi:
 *
 * - **Quản trị sàn** thấy mọi yêu cầu, kể cả liên hệ người mua, và là bên duy
 *   nhất **duyệt** được. Duyệt là hành động mở dữ liệu cá nhân sang bên thứ
 *   ba, nên nó không thể nằm ở tay bên hưởng lợi.
 * - **Media owner** thấy yêu cầu **có vị trí của mình**, và chỉ phần của
 *   mình. Liên hệ người mua thì chỉ sau khi duyệt — xem `xemDuocLienHe()`.
 *
 * Người mua chưa có mặt ở đây: lát 5 không dựng trang tra cứu cho họ. Khi có,
 * nó vào đúng lớp này chứ không phải một phép kiểm thứ hai ở controller.
 */
class QuoteRequestPolicy
{
    /**
     * Kiểm theo `$user` được truyền vào, **không** qua `TenantPermission::check()`:
     * `check()` đọc `auth()->user()`, nên policy dùng nó sẽ trả lời về người
     * đang đăng nhập chứ không về người được hỏi.
     */
    public function viewAny(User $user): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return TenantPermission::for($user)->can('manage_bookings');
    }

    public function view(User $user, QuoteRequest $yeuCau): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        $ownerId = (string) $user->current_owner_id;

        if ($ownerId === '') {
            return false;
        }

        // Owner chỉ thấy yêu cầu có ít nhất một vị trí của mình. `HasOwnerScope`
        // chặn mặc định khi không xác định được tenant, và dòng trên giữ
        // nguyên tinh thần đó.
        return TenantPermission::for($user)->can('manage_bookings')
            && $yeuCau->items()->where('owner_id', $ownerId)->exists();
    }

    /**
     * Ai thấy tên, email, điện thoại người mua.
     *
     * Quản trị: luôn. Media owner: **chỉ sau khi duyệt** — chủ dự án chốt
     * ngày 19/10/2026. Nếu không, sàn tự biến mình thành danh bạ khách hàng
     * miễn phí: owner nhận mười yêu cầu là có mười liên hệ, không cần trả lời
     * cái nào.
     */
    public function xemDuocLienHe(User $user, QuoteRequest $yeuCau): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->view($user, $yeuCau) && $yeuCau->lienHeDaMo();
    }

    /** Chỉ quản trị sàn duyệt. */
    public function duyet(User $user, QuoteRequest $yeuCau): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Không ai tạo yêu cầu từ khu quản trị.
     *
     * Yêu cầu sinh ra từ đúng một đường: `POST /api/v2/quote-requests`. Một
     * nút "Tạo yêu cầu" trong Filament là cách tạo ra bản ghi không có người
     * gửi thật, và chúng sẽ nằm lẫn với bản ghi thật trong mọi báo cáo.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Xoá — **chỉ quản trị sàn**, và là xoá thật (chủ dự án chốt 20/10/2026).
     *
     * ══ Vì sao mở ══
     *
     * Lát 5 đóng cứng `delete` để không ai tay trượt làm mất một đầu mối của
     * media owner. Hệ quả: không ai dám thử đường gửi thật trên production,
     * vì bản ghi thử sẽ nằm vĩnh viễn trong danh sách việc của owner và trong
     * mọi con số báo cáo. Một tính năng không ai thử được trên môi trường
     * thật là một tính năng chưa biết có chạy.
     *
     * ══ Vì sao media owner không có ══
     *
     * Owner chỉ thấy phần của mình trong yêu cầu, nhưng xoá thì xoá cả bản
     * ghi — tức xoá luôn phần của owner khác. Không có cách nào cho owner
     * "xoá một phần" mà không biến `quote_requests` thành dữ liệu lịch sử bị
     * sửa (CLAUDE.md mục 6). Owner muốn đóng một yêu cầu thì đổi trạng thái,
     * không xoá.
     *
     * ══ Vì sao xoá thật, không xoá mềm ══
     *
     * Bản ghi này chứa tên, email, điện thoại người gửi. Xoá mềm giữ nguyên
     * cả ba trong CSDL — đó không phải xoá, đó là ẩn. Khi người mua yêu cầu
     * xoá dữ liệu của họ, một cột `deleted_at` không trả lời được.
     *
     * Hàng `quote_request_items` đi theo, và `screen_id` ở đó là
     * `nullOnDelete` nên xoá yêu cầu không chạm tới màn hình nào.
     *
     * Bù lại bằng hai lớp ở tầng trên: hộp thoại đòi gõ đúng mã yêu cầu cùng
     * một lý do, và một dòng nhật ký ghi ai–lúc nào–lý do–xoá cái gì. Nhật ký
     * **không** mang dữ liệu cá nhân: nó đi qua nhiều tay hơn CSDL.
     */
    public function delete(User $user, QuoteRequest $yeuCau): bool
    {
        return $user->hasRole('super_admin');
    }
}
