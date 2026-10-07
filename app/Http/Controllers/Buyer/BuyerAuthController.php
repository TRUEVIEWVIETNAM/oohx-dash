<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\RegisterBuyerRequest;
use App\Services\BuyerLoginService;
use App\Services\BuyerRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Đăng nhập, đăng ký, đăng xuất cho người mua — bản Blade.
 *
 * Phần nghiệp vụ nằm ở `BuyerLoginService` và `BuyerRegistrationService`, dùng
 * chung với `Api\V2\BuyerAuthController`. Bộ luật kiểm nằm ở
 * `RegisterBuyerRequest`, cũng dùng chung.
 *
 * Controller này chỉ còn làm đúng việc của controller: nhận request, gọi
 * service, trả response — ở đây là chuyển hướng, còn bản API trả JSON.
 */
class BuyerAuthController extends Controller
{
    public function __construct(
        private readonly BuyerLoginService $logins,
        private readonly BuyerRegistrationService $registrations,
    ) {}

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check() && Auth::user()->organizations()->exists()) {
            return redirect('/my');
        }
        return view('buyer.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ]);

        $user = $this->logins->attempt(
            $credentials['email'],
            $credentials['password'],
            $request->boolean('remember'),
            $request,
        );

        if (! $user) {
            return back()->withErrors(['email' => 'Email hoặc mật khẩu không đúng.'])->onlyInput('email');
        }

        return redirect()->intended(route('buyer.dashboard'));
    }

    public function showRegister(): View|RedirectResponse
    {
        if (Auth::check() && Auth::user()->organizations()->exists()) {
            return redirect('/my');
        }
        return view('buyer.auth.register');
    }

    public function register(RegisterBuyerRequest $request): RedirectResponse
    {
        $user = $this->registrations->register($request->validated(), $request);

        Auth::login($user);

        return redirect()->route('buyer.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('fp.index');
    }
}
