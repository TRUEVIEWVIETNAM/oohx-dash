<?php

namespace App\Http\Controllers;

use App\Models\OrganizationUser;
use App\Models\OwnerUser;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function __construct(
        private UserInvitationService $service,
    ) {}

    /** GET /invitations/{token}/accept — render accept form */
    public function show(string $token): View|Response
    {
        $invitation = UserInvitation::where('token', $token)->first();

        if (! $invitation) {
            return $this->errorPage('Lời mời không tồn tại hoặc đã bị thu hồi.');
        }

        if ($invitation->isAccepted()) {
            return $this->errorPage('Lời mời đã được sử dụng. Vui lòng đăng nhập trực tiếp.');
        }

        if ($invitation->isExpired()) {
            return $this->errorPage('Lời mời đã hết hạn. Liên hệ quản trị viên để được mời lại.');
        }

        $tenant = $invitation->tenant();
        $roleLabel = match ($invitation->tenant_type) {
            UserInvitation::TENANT_OWNER        => OwnerUser::ROLES[$invitation->role] ?? $invitation->role,
            UserInvitation::TENANT_ORGANIZATION => OrganizationUser::ROLES[$invitation->role] ?? $invitation->role,
            default                             => $invitation->role,
        };

        $existingUser = \App\Models\User::where('email', $invitation->email)->first();

        return view('invitations.accept', [
            'invitation'    => $invitation,
            'tenant'        => $tenant,
            'roleLabel'     => $roleLabel,
            'isExistingUser' => (bool) $existingUser,
        ]);
    }

    /**
     * Trang báo lời mời không dùng được, kèm mã 410.
     *
     * Trước đây gọi view('invitations.error', [...], 410): tham số thứ ba của
     * view() là DỮ LIỆU GỘP THÊM, không phải mã trạng thái, nên Laravel ném
     * TypeError ở array_merge và người nhận lời mời hết hạn thấy trang 500 thay
     * vì lời nhắn. Phải đi qua response()->view() mới đặt được mã.
     */
    private function errorPage(string $message): Response
    {
        return response()->view('invitations.error', ['message' => $message], 410);
    }

    /** POST /invitations/{token}/accept — chấp nhận và login */
    public function store(Request $request, string $token): RedirectResponse
    {
        $existingUser = null;
        $invitation = UserInvitation::where('token', $token)->first();
        if ($invitation) {
            $existingUser = \App\Models\User::where('email', $invitation->email)->first();
        }

        $rules = [
            'name'     => $existingUser ? 'nullable|string|max:255' : 'required|string|max:255',
            'password' => $existingUser ? 'nullable|string|min:8|confirmed' : 'required|string|min:8|confirmed',
        ];
        $data = $request->validate($rules);

        try {
            $user = $this->service->accept(
                $token,
                $data['name'] ?? '',
                $data['password'] ?? '',
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        Auth::login($user);

        $redirectPath = match ($invitation?->tenant_type) {
            UserInvitation::TENANT_OWNER        => '/publisher',
            UserInvitation::TENANT_ORGANIZATION => '/buyer',
            default                             => '/admin',
        };

        return redirect($redirectPath);
    }
}
