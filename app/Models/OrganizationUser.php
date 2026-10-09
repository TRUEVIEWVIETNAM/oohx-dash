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

    /** Mô tả ngắn về quyền của từng role — dùng làm helperText. */
    public const ROLE_DESCRIPTIONS = [
        'admin'   => '👑 Toàn quyền: quản lý team, tạo campaign, duyệt booking, payments.',
        'planner' => '📋 Tạo campaign, submit booking, upload creative, xem reports. Không quản lý team.',
        'viewer'  => '👁 Chỉ xem campaigns và reports, không chỉnh sửa.',
    ];

    public const PERMISSIONS = [
        'admin'   => ['manage_team', 'create_campaign', 'submit_booking', 'upload_creative', 'view_campaigns', 'view_reports', 'manage_payments'],
        'planner' => ['create_campaign', 'submit_booking', 'upload_creative', 'view_campaigns', 'view_reports'],
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
