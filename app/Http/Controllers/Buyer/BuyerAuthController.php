<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PolicyConsent;
use App\Models\User;
use App\Services\PolicyConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class BuyerAuthController extends Controller
{
    public function __construct(private readonly PolicyConsentService $consents) {}

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

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Email hoặc mật khẩu không đúng.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        $user = Auth::user();

        // Set current_organization_id if user has organizations
        if (! $user->current_organization_id && $user->organizations()->exists()) {
            $user->update([
                'current_organization_id' => $user->organizations()->first()->id,
            ]);
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

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'email'             => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'          => ['required', 'confirmed', Password::min(8)],
            'organization_name' => ['required', 'string', 'max:255'],
            'organization_type' => ['required', 'in:agency,client,brand'],

            // 'accepted' chứ không phải 'required': một checkbox không tick thì
            // trình duyệt không gửi gì cả, mà 'required' lại chỉ chặn giá trị rỗng
            // khi trường CÓ mặt. Thuộc tính required trên thẻ input là gợi ý cho
            // người dùng, không phải cổng kiểm soát.
            'accept_privacy'    => ['accepted'],
        ], [
            'accept_privacy.accepted' => 'Bạn cần đồng ý với Chính sách bảo mật thông tin để tạo tài khoản.',
        ]);

        $user = DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            // Người tự đăng ký cũng phải có vai trò hệ thống 'buyer' như người được
            // mời. Thiếu dòng này thì họ vào được /my nhưng không vào được panel
            // /buyer, và hai lối onboarding cho ra hai kết quả khác nhau (Codex F15).
            //
            // findOrCreate chứ không assignRole trần: nếu bản ghi vai trò chưa có
            // trong môi trường đó, assignRole ném lỗi và cả giao dịch đăng ký bị
            // hủy — người dùng không tạo được tài khoản chỉ vì thiếu một hàng seed.
            $user->assignRole(Role::findOrCreate('buyer', 'web'));

            $org = Organization::create([
                'name' => $data['organization_name'],
                'slug' => Str::slug($data['organization_name']) . '-' . Str::random(4),
                'type' => $data['organization_type'],
            ]);

            OrganizationUser::create([
                'organization_id' => $org->id,
                'user_id'         => $user->id,
                'role'            => OrganizationUser::ROLE_ADMIN,
            ]);

            $user->update(['current_organization_id' => $org->id]);

            // Trong cùng transaction: hoặc có cả tài khoản lẫn bằng chứng chấp
            // thuận, hoặc không có gì. Một tài khoản tồn tại mà không có bản ghi
            // đồng ý là đúng thứ không trả lời được khi bị hỏi.
            $this->consents->record(
                ['privacy'],
                PolicyConsent::CONTEXT_REGISTER,
                $request,
                userId: $user->id,
            );

            return $user;
        });

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
