<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\V2\SettingsResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Cài đặt khu người mua — đọc và ghi, từ trình duyệt.
 *
 * ══ Tôi đã TỪ CHỐI việc này một lần, và vì sao bản này khác ══
 *
 * Lần trước tôi nêu ba lý do không chuyển trang cài đặt sang đọc API, và cả ba
 * đều về việc nó là một **biểu mẫu**:
 *
 *  1. giá trị ban đầu của ô nhập là thứ render máy chủ làm đúng; lấy qua API
 *     nghĩa là ô trống trong một nhịp rồi mới điền;
 *  2. trong nhịp đó người dùng gõ được, và lượt điền sau ghi đè thứ họ vừa gõ;
 *  3. `old('name', $user->name)` giữ lại thứ họ vừa nhập khi validate thất bại.
 *
 * Quyết định là chuyển. Nên bản này xử từng lý do thay vì bỏ qua:
 *
 *  1. trang vẽ **khung chờ** và `disabled` mọi ô cho tới khi dữ liệu về — không
 *     có nhịp nào hiện ô trống như thể đó là giá trị;
 *  2. `disabled` nghĩa là không gõ được trong nhịp đó, nên không có gì để ghi
 *     đè;
 *  3. biểu mẫu **gửi qua API luôn**, nên không có vòng chuyển hướng nào và
 *     `old()` không còn vai trò. Lỗi validate về dưới dạng `details[]` và hiện
 *     ngay cạnh từng ô, giữ nguyên thứ người dùng đã gõ — chặt hơn `old()`,
 *     vì `old()` dựng lại cả trang còn cách này không làm mất con trỏ.
 *
 * Ba đường `PUT /my/settings/*` bên web đã GỠ cùng lượt. Giữ chúng lại là có
 * hai nơi định nghĩa cùng một luật ghi (CLAUDE.md §1), và nơi không ai gọi sẽ
 * là nơi không ai sửa khi luật đổi.
 *
 * ══ Nằm sau `buyer`, không sau `auth` ══
 *
 * Khác `MeController`: endpoint này cần một tổ chức để có gì mà trả. Người
 * chưa có tổ chức không vào được trang `/my/settings` ngay từ đầu.
 */
class SettingsController extends Controller
{
    /**
     * Tổ chức đang chọn, đã kiểm tư cách thành viên.
     *
     * `currentOrganization` một mình KHÔNG đủ: nó chỉ đọc cột
     * `current_organization_id` — một giá trị client đặt được, và không ai dọn
     * khi một người bị gỡ khỏi tổ chức. Không có `Gate::authorize` ở đây thì
     * người đã bị gỡ vẫn đọc được email thanh toán và mã số thuế của tổ chức
     * cũ. Xem `OrganizationPolicy`.
     */
    private function toChuc(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;

        abort_if($org === null, 404);
        abort_unless($request->user()->can('view', $org), 403);

        return $org;
    }

    public function show(Request $request): JsonResponse
    {
        $org = $this->toChuc($request);

        return response()->json([
            'data' => (new SettingsResource(
                $request->user(),
                $org,
                $request->user()->can('update', $org),
            ))->resolve(),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        // Kiểm tư cách thành viên kể cả ở đường chỉ sửa hồ sơ cá nhân: trang
        // này là trang của khu người mua, và người đã bị gỡ khỏi mọi tổ chức
        // không nên tiếp tục dùng nó. (Mọi vai trò CÒN trong tổ chức thì sửa
        // được hồ sơ của mình — có ca test riêng cho điều đó.)
        $this->toChuc($request);

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $request->user()->id],
        ]);

        $request->user()->update($data);

        return response()->json([
            'data' => ['message' => 'Thông tin cá nhân đã được cập nhật'],
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $this->toChuc($request);

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update([
            'password' => Hash::make($request->input('password')),
        ]);

        return response()->json([
            'data' => ['message' => 'Mật khẩu đã được đổi'],
        ]);
    }

    /**
     * Sửa thông tin tổ chức — chỉ vai trò có `manage_team`.
     *
     * `$data` gồm `tax_id` và `billing_*`, nên thiếu policy ở đây là để một vai
     * trò `viewer` ghi lại mã số thuế của tổ chức. Đó đúng là lỗ đã bịt ở PR
     * #55, và ca test của nó chuyển sang đường này cùng lượt — một chốt canh
     * một đường không ai gọi thì không canh gì.
     */
    public function updateOrganization(Request $request): JsonResponse
    {
        $org = $this->toChuc($request);

        abort_unless($request->user()->can('update', $org), 403);

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'billing_phone' => ['nullable', 'string', 'max:30'],
            'tax_id'        => ['nullable', 'string', 'max:50'],
            'website'       => ['nullable', 'url', 'max:255'],
        ]);

        $org->update($data);

        return response()->json([
            'data' => ['message' => 'Thông tin tổ chức đã được cập nhật'],
        ]);
    }
}
