<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Đăng xuất cho khu người mua trên Blade.
 *
 * ══ Chỉ còn một việc ══
 *
 * `showLogin`, `showRegister`, `login`, `register` gỡ ở giai đoạn 7
 * (07/10/2026). `/login` và `/register` do Next phục vụ, và biểu mẫu của chúng
 * gửi sang `/api/v2/auth/*`.
 *
 * Giữ lại bốn method đó nghĩa là giữ một đường vào thứ hai cho cùng một việc —
 * mà đường thứ hai không có ai đi, nên cũng không ai biết khi nó trôi khỏi
 * đường thứ nhất.
 *
 * Phần nghiệp vụ không mất: nó ở `BuyerLoginService` và
 * `BuyerRegistrationService`, dùng chung với `Api\V2\BuyerAuthController`.
 *
 * ══ `logout` ở lại ══
 *
 * Nó là một POST từ khu người mua trên Blade (`/my/*`), và khu đó chưa chuyển —
 * giai đoạn 8 đang hoãn. `nextjs.conf` cố ý không proxy `/logout`: proxy nó
 * sang Next là trả 405 cho nút đăng xuất.
 *
 * Ở đây `Auth::logout()` trần là ĐÚNG, khác với bản API. Guard mặc định trong
 * ngữ cảnh web là `web`; còn trong nhóm `auth:sanctum` của API thì nó là
 * `RequestGuard` của Sanctum, và lớp đó không có `logout()`.
 */
class BuyerAuthController extends Controller
{
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
