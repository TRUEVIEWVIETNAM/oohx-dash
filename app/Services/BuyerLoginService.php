<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Đăng nhập người mua — dùng chung giữa trang Blade và `/api/v2/auth/login`.
 *
 * ══ `session()->regenerate()` là phần KHÔNG được bỏ ══
 *
 * Nó cấp ID phiên mới sau khi xác thực. Thiếu nó thì kẻ tấn công đặt trước một
 * ID phiên cho nạn nhân (session fixation) vẫn giữ nguyên ID đó sau khi nạn
 * nhân đăng nhập — tức kẻ tấn công ở trong phiên đã đăng nhập.
 *
 * Nó nằm ở đây chính vì thế: hai đường vào, một chỗ gọi. Để nó trong controller
 * là chờ một ngày ai đó viết đường vào thứ ba và quên.
 */
class BuyerLoginService
{
    /**
     * Trả về người dùng khi đúng thông tin, `null` khi sai.
     *
     * KHÔNG phân biệt "email không tồn tại" với "mật khẩu sai" — phân biệt là
     * cho người lạ một cách dò xem địa chỉ nào có tài khoản trên sàn.
     */
    public function attempt(string $email, string $password, bool $remember, Request $request): ?User
    {
        if (! Auth::attempt(['email' => $email, 'password' => $password], $remember)) {
            return null;
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        // Người được mời vào một tổ chức có thể chưa có tổ chức hiện hành. Thiếu
        // bước này thì họ đăng nhập xong vào `/my` và thấy một khu trống.
        if (! $user->current_organization_id && $user->organizations()->exists()) {
            $user->update([
                'current_organization_id' => $user->organizations()->first()->id,
            ]);
        }

        return $user;
    }
}
