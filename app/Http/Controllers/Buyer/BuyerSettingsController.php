<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class BuyerSettingsController extends Controller
{
    /**
     * Trang cài đặt — **vẫn do máy chủ render**, có chủ ý.
     *
     * Năm trang khu người mua đã chuyển sang đọc `/api/v2` từ trình duyệt.
     * Trang này thì không, và ba lý do đều thuộc về việc nó là một **biểu mẫu**:
     *
     *  1. Giá trị ban đầu của ô nhập là thứ render phía máy chủ làm đúng. Lấy
     *     qua API nghĩa là ô trống trong một nhịp, rồi mới điền.
     *  2. Trong nhịp đó người dùng gõ được — và lượt điền sau sẽ ghi đè thứ họ
     *     vừa gõ.
     *  3. `old('name', $user->name)` giữ lại thứ họ vừa nhập khi validate thất
     *     bại. Chuyển sang đọc API là mất việc đó, hoặc phải dựng lại nó bằng
     *     JS — thêm mã để làm điều Laravel đang làm sẵn.
     *
     * Khoảng trống mà giai đoạn 5 muốn đóng (tầng DTO/lỗi có lưu lượng thật
     * chạy qua) đã đóng bằng năm trang kia. Chuyển thêm một trang biểu mẫu
     * không đóng thêm gì, mà làm trang tệ hơn.
     *
     * ══ Thứ trang này THẬT SỰ cần sửa ══
     *
     * `currentOrganization` là một `belongsTo` thuần trên
     * `current_organization_id`, nên bản cũ cho người đã bị gỡ khỏi tổ chức
     * **xem** email thanh toán, số điện thoại và mã số thuế của tổ chức cũ.
     * Xem `OrganizationPolicy`.
     */
    public function index(Request $request): View
    {
        $org = $this->toChuc($request);

        return view('buyer.dashboard.settings', [
            'user' => $request->user(),
            'org'  => $org,
        ]);
    }

    /**
     * Tổ chức đang chọn, đã kiểm tư cách thành viên.
     *
     * `currentOrganization` một mình không đủ: nó chỉ đọc một cột mà client đổi
     * được và không ai dọn khi một người bị gỡ khỏi tổ chức.
     */
    private function toChuc(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;

        abort_unless($org !== null, 404);
        abort_unless($request->user()->can('view', $org), 403);

        return $org;
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $request->user()->id],
        ]);

        $request->user()->update($data);

        return back()->with('success', 'Thông tin cá nhân đã được cập nhật');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update([
            'password' => Hash::make($request->input('password')),
        ]);

        return back()->with('success', 'Mật khẩu đã được đổi');
    }

    /**
     * Sửa thông tin tổ chức — **chỉ vai trò quản trị**.
     *
     * Bản cũ chạy `$request->user()->currentOrganization->update($data)` mà
     * không policy, không kiểm tư cách thành viên, không kiểm vai trò. `$data`
     * gồm `tax_id` và `billing_*`, nên một vai trò `viewer` — "chỉ xem, không
     * chỉnh sửa" theo chính mô tả trong mã — ghi lại được mã số thuế của tổ
     * chức. Chi tiết ở `OrganizationPolicy`.
     */
    public function updateOrganization(Request $request): RedirectResponse
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

        return back()->with('success', 'Thông tin tổ chức đã được cập nhật');
    }
}
