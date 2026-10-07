<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PolicyConsent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Tạo tài khoản người mua: người dùng, vai trò, tổ chức, và bằng chứng chấp
 * thuận chính sách — trong MỘT giao dịch.
 *
 * ══ Vì sao là service, không để trong controller ══
 *
 * CLAUDE.md mục 1: "Mọi nghiệp vụ nằm ở Service/Action. Filament, API, lệnh
 * artisan, job đều gọi **cùng một** service."
 *
 * Trước đây khối này nằm trong `Buyer\BuyerAuthController::register()`. Khi
 * trang đăng ký chuyển sang Next, bản API phải làm đúng từng ấy việc — và nếu
 * chép sang thì hai bản sẽ trôi khỏi nhau. Thứ trôi mất ở đây không phải một
 * nút bấm: nó là `assignRole('buyer')` (thiếu thì người dùng vào được `/my`
 * nhưng không vào được panel `/buyer` — audit Codex F15) và bản ghi chấp thuận
 * chính sách.
 *
 * ══ Một giao dịch, không hai ══
 *
 * Hoặc có cả tài khoản lẫn bằng chứng chấp thuận, hoặc không có gì. Một tài
 * khoản tồn tại mà không có bản ghi đồng ý là đúng thứ không trả lời được khi
 * bị hỏi — và `version` của chính sách được đóng dấu vào bản ghi đó, nên nó là
 * bằng chứng người dùng đã đồng ý với ĐÚNG chữ nào.
 */
class BuyerRegistrationService
{
    public function __construct(private readonly PolicyConsentService $consents) {}

    /**
     * @param  array{name: string, email: string, password: string, organization_name: string, organization_type: string}  $data
     */
    public function register(array $data, Request $request): User
    {
        return DB::transaction(function () use ($data, $request) {
            $user = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            // Người tự đăng ký cũng phải có vai trò hệ thống 'buyer' như người
            // được mời. Thiếu dòng này thì họ vào được /my nhưng không vào được
            // panel /buyer, và hai lối onboarding cho ra hai kết quả khác nhau
            // (Codex F15).
            //
            // `findOrCreate` chứ không `assignRole` trần: nếu bản ghi vai trò
            // chưa có trong môi trường đó, `assignRole` ném lỗi và cả giao dịch
            // đăng ký bị hủy — người dùng không tạo được tài khoản chỉ vì thiếu
            // một hàng seed.
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

            $this->consents->record(
                ['privacy'],
                PolicyConsent::CONTEXT_REGISTER,
                $request,
                userId: $user->id,
            );

            return $user;
        });
    }
}
