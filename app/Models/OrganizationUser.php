<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationUser extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'role',
    ];

    public const ROLE_ADMIN   = 'admin';
    public const ROLE_PLANNER = 'planner';
    public const ROLE_VIEWER  = 'viewer';

    /**
     * Chữ tiếng Việt cho vai trò trong tổ chức — **một định nghĩa**.
     *
     * ══ Hai lỗi bảng này sửa ══
     *
     *  1. Chữ là **tiếng Anh** ("Admin", "Planner", "Viewer") trong một panel
     *     còn lại đều tiếng Việt, và nó ra tới cả thư mời (`InvitationController`,
     *     `UserInvitationNotification`) — tức tới hộp thư của người được mời.
     *  2. Hằng tên `ROLES`, **không** theo quy ước `<CỘT>_LABELS`, nên bộ tự
     *     tìm của `NhanEnumMotNoiTest` không thấy nó: cột `role` có bảng chữ mà
     *     không ai đối chiếu với enum CSDL. Đổi tên là để nó vào tầm.
     *
     * Chữ lấy theo `ROLE_DESCRIPTIONS` ngay dưới — "Toàn quyền", "Không quản lý
     * team", "Chỉ xem" — chứ không đặt từ mới cho cùng một thứ.
     */
    public const ROLE_LABELS = [
        self::ROLE_ADMIN   => 'Quản trị',
        self::ROLE_PLANNER => 'Lập kế hoạch',
        self::ROLE_VIEWER  => 'Chỉ xem',
    ];

    /**
     * Mô tả ngắn về quyền của từng role — dùng làm helperText.
     *
     * Chữ này là thứ người gán vai trò đọc **ngay lúc gán**, nên nó phải nói
     * đúng `PERMISSIONS`. Bản trước nói `planner` chỉ "Không quản lý team" mà
     * không nhắc gì tới thông tin tổ chức — đúng vào lúc `planner` không sửa
     * được thông tin tổ chức. Nay nó sửa được, nên chữ phải kể ra.
     * `OwnerUser::ROLE_DESCRIPTIONS` đã có đúng loại lỗi này một lần.
     */
    public const ROLE_DESCRIPTIONS = [
        'admin'   => '👑 Toàn quyền: quản lý team, tạo campaign, duyệt booking, payments.',
        'planner' => '📋 Tạo campaign, submit booking, upload creative, xem reports, sửa thông tin tổ chức. Không quản lý team.',
        'viewer'  => '👁 Chỉ xem campaigns và reports, không chỉnh sửa.',
    ];

    /**
     * ══ `manage_org_profile` tách khỏi `manage_team` (13/10/2026) ══
     *
     * PR #55 bịt một lỗ thật: `BuyerSettingsController::updateOrganization()`
     * chạy `update($data)` không qua policy nào, nên vai trò `viewer` ghi lại
     * được `tax_id` và `billing_*` của tổ chức. Cách bịt khi đó là đòi
     * `manage_team` — quyền duy nhất lúc ấy chỉ `admin` có.
     *
     * Nhưng `manage_team` trả lời câu **"ai quản lý thành viên"**, không phải
     * câu "ai sửa thông tin tổ chức". Dùng nó cho cả hai làm `planner` mất
     * quyền sửa thông tin tổ chức mà nó đang có, và đó là một tác dụng phụ của
     * lần bịt lỗ, không phải một quyết định.
     *
     * Chủ dự án quyết **trả lại** quyền đó cho `planner` (13/10/2026). Nên
     * tách ra một quyền riêng chứ không thêm `manage_team` cho `planner`: thêm
     * `manage_team` sẽ mở luôn việc quản lý thành viên, đúng điều mô tả vai
     * trò nói là **không**.
     *
     * `viewer` vẫn **không** có quyền này. Mô tả của nó là "Chỉ xem campaigns
     * và reports, không chỉnh sửa", và chính nó là lỗ mà #55 bịt.
     *
     * **Lưu ý cho người đọc sau:** sau lần tách này, `manage_team` không còn
     * được kiểm ở đâu cả — `OrganizationUserPolicy` gác việc quản lý thành
     * viên bằng cách so `role === 'admin'` **trực tiếp**, không qua
     * `PERMISSIONS`. Hai bộ luật cho một câu hỏi; chưa gom vì đó là đường phân
     * quyền của chính việc mời/gỡ người, không thuộc phạm vi lần sửa này.
     */
    public const PERMISSIONS = [
        'admin'   => ['manage_team', 'manage_org_profile', 'create_campaign', 'submit_booking', 'upload_creative', 'view_campaigns', 'view_reports', 'manage_payments'],
        'planner' => ['manage_org_profile', 'create_campaign', 'submit_booking', 'upload_creative', 'view_campaigns', 'view_reports'],
        'viewer'  => ['view_campaigns', 'view_reports'],
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function can(string $permission): bool
    {
        return in_array($permission, self::PERMISSIONS[$this->role] ?? []);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Roles mà $actor được phép gán cho người khác.
     * Chỉ super_admin mới được gán role 'admin' (tránh org admin tự nhân bản).
     */
    public static function assignableRolesFor(?User $actor): array
    {
        if ($actor?->hasRole('super_admin')) {
            return self::ROLE_LABELS;
        }

        $roles = self::ROLE_LABELS;
        unset($roles[self::ROLE_ADMIN]);

        return $roles;
    }
}
