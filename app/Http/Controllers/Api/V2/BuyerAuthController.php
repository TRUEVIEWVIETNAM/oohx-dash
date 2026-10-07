<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Buyer\RegisterBuyerRequest;
use App\Models\User;
use App\Services\BuyerLoginService;
use App\Services\BuyerRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Đăng nhập, đăng ký, đăng xuất cho app Next.js.
 *
 * ══ Phiên, không phải token ══
 *
 * Nhóm route này mang `EnsureFrontendRequestsAreStateful`, tức Sanctum dạng
 * SPA: đăng nhập thành công đặt **cookie phiên Laravel**, đúng cookie mà khu
 * người mua trên Blade (`/my/*`) đang dùng. Nên người dùng đăng nhập ở trang
 * Next rồi đi tiếp vào Blade mà không phải đăng nhập lại — điều kiện bắt buộc,
 * vì giai đoạn 8 đang hoãn và khu đó vẫn là Blade.
 *
 * Hệ quả của việc stateful: nhóm này **có** CSRF. Bên gọi phải lấy
 * `/sanctum/csrf-cookie` trước rồi gửi lại trong header `X-XSRF-TOKEN`. Đó là
 * chủ ý — một endpoint TẠO PHIÊN mà không có CSRF là một endpoint người khác
 * đăng nhập hộ được.
 *
 * Nhóm công khai (`/api/v2/screens`, `/api/v2/reflections`) KHÔNG mang
 * middleware này, nên biểu mẫu phản ánh vẫn gọi thẳng được. Hai nhóm, hai mức
 * yêu cầu, có lý do riêng cho từng nhóm.
 *
 * ══ Nghiệp vụ không nằm ở đây ══
 *
 * `BuyerLoginService` và `BuyerRegistrationService` dùng chung với bản Blade,
 * `RegisterBuyerRequest` cũng vậy. Controller này chỉ đổi hình dạng response:
 * Blade chuyển hướng, bản này trả JSON.
 *
 * Nếu chép logic sang đây thì thứ trôi mất không phải một nút bấm — nó là
 * `session()->regenerate()` (chống session fixation), `assignRole('buyer')`
 * (thiếu thì vào được `/my` mà không vào được panel `/buyer`), và bản ghi chấp
 * thuận chính sách có đóng dấu phiên bản.
 */
class BuyerAuthController extends Controller
{
    public function __construct(
        private readonly BuyerLoginService $logins,
        private readonly BuyerRegistrationService $registrations,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = $this->logins->attempt(
            $data['email'],
            $data['password'],
            $request->boolean('remember'),
            $request,
        );

        if (! $user) {
            // MỘT thông điệp cho cả hai trường hợp sai. Nói "email không tồn
            // tại" là cho người lạ một cách dò xem địa chỉ nào có tài khoản
            // trên sàn, và danh sách đó tự nó đã là dữ liệu.
            return response()->json([
                'error'   => 'invalid_credentials',
                'message' => 'Email hoặc mật khẩu không đúng.',
                'code'    => 401,
                'details' => [],
            ], 401);
        }

        return response()->json(['data' => $this->nguoiDung($user)]);
    }

    public function register(RegisterBuyerRequest $request): JsonResponse
    {
        $user = $this->registrations->register($request->validated(), $request);

        Auth::login($user);

        // Phiên mới sau khi đăng nhập, cùng lý do như ở đăng nhập: phiên khách
        // đang có thể do người khác đặt trước.
        $request->session()->regenerate();

        return response()->json(['data' => $this->nguoiDung($user)], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        // `guard('web')` đích danh, KHÔNG `Auth::logout()` trần.
        //
        // Middleware `auth:sanctum` đặt guard mặc định thành guard của Sanctum,
        // và đó là một `RequestGuard` — nó không có `logout()`. Gọi trần thì
        // nhận `BadMethodCallException` và endpoint trả 500.
        //
        // Bản Blade dùng `Auth::logout()` trần và chạy đúng, vì ở đó guard mặc
        // định vẫn là `web`. Cùng một dòng, hai ngữ cảnh, hai kết quả — nên nó
        // không chép sang được.
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['message' => 'Đã đăng xuất.']]);
    }

    /**
     * Danh sách trắng, không trả thẳng model.
     *
     * `User` có `password`, `remember_token`, và quan hệ tới tổ chức kèm
     * `billing_info`/`tax_code`. CLAUDE.md mục 2 cấm lộ chúng, và cách chắc
     * chắn nhất là liệt kê thứ ĐƯỢC trả chứ không liệt kê thứ phải giấu.
     */
    private function nguoiDung(User $user): array
    {
        return [
            'name'  => $user->name,
            'email' => $user->email,

            // Đủ để app biết nên đưa người dùng đi đâu: có tổ chức thì vào khu
            // người mua, chưa có thì đi bước tạo tổ chức.
            'has_organization' => $user->organizations()->exists(),
        ];
    }
}
