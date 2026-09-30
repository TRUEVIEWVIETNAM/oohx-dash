<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerUser extends Model
{
    protected $table = 'owner_users';

    protected $fillable = [
        'owner_id', 'user_id', 'role', 'allowed_network_ids',
    ];

    protected $casts = [
        'allowed_network_ids' => 'array',
    ];

    // ── Role definitions ──────────────────────────────────────────────────────

    /**
     * 6 roles theo thứ tự quyền giảm dần.
     * Label hiển thị trong UI (khớp với ảnh thiết kế).
     */
    public const ROLES = [
        'owner'          => 'Owner',
        'manager'        => 'Manager',
        'scheduler'      => 'Scheduler',
        'read_only'      => 'Read only',
        'reporting_only' => 'Reporting only',
        'sales_manager'  => 'Sales manager',
    ];

    /** Mô tả ngắn về quyền của từng role — dùng làm helperText/Placeholder trong forms. */
    public const ROLE_DESCRIPTIONS = [
        'owner'          => '👑 Toàn quyền: quản lý team, inventory, pricing, reports.',
        'manager'        => '🔧 Quản lý inventory & pricing, xem reports. Không quản lý được users.',
        'scheduler'      => '📅 Thêm/sửa screens và import inventory. Không xem được reports.',
        'read_only'      => '👁 Chỉ xem screens và sites, không chỉnh sửa.',
        'reporting_only' => '📊 Chỉ xem và export reports, không thấy inventory.',
        'sales_manager'  => '💼 Xem inventory và sales dashboard. Không chỉnh sửa.',
    ];

    /**
     * Quyền theo từng role.
     * key = action, value = array roles được phép.
     */
    public const PERMISSIONS = [
        // Quản lý users trong owner
        'manage_users'     => ['owner'],
        // Sửa thông tin owner (name, billing...)
        'edit_owner'       => ['owner', 'manager'],
        // Thêm/sửa/xoá site & screen
        'manage_inventory' => ['owner', 'manager', 'scheduler'],
        // Bật/tắt programmatic, sửa floor CPM
        'manage_pricing'   => ['owner', 'manager'],
        // Xem screens, sites (read)
        'view_inventory'   => ['owner', 'manager', 'scheduler', 'read_only', 'sales_manager'],
        // Import bulk xlsx
        'import_inventory' => ['owner', 'manager', 'scheduler'],
        // Xem revenue & impression reports
        'view_reports'     => ['owner', 'manager', 'reporting_only', 'sales_manager'],
        // Export report CSV/Excel
        'export_reports'   => ['owner', 'manager', 'reporting_only'],
        // Xem sales dashboard (CPM, deals)
        'view_sales'       => ['owner', 'manager', 'sales_manager'],
        // Duyệt / từ chối yêu cầu đặt chỗ. Đây là quyết định thương mại có hệ
        // quả tiền, nên không mở cho scheduler hay read_only — trước đây action
        // duyệt không kiểm quyền nào cả, chỉ dựa vào việc giao diện có hiện nút.
        'manage_bookings'  => ['owner', 'manager', 'sales_manager'],
        // Đánh dấu đã hoàn tiền cho người mua. Sàn không giữ tiền — người mua
        // chuyển thẳng cho media owner — nên "đã hoàn" là lời khai của owner về
        // một lần chuyển khoản thật. Hẹp hơn `manage_bookings`: sales_manager
        // chốt được đơn nhưng không khai thay phòng kế toán là tiền đã đi.
        'settle_refunds'   => ['owner', 'manager'],
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Permission helpers ────────────────────────────────────────────────────

    /** Kiểm tra role có quyền thực hiện action không */
    public function can(string $action): bool
    {
        $allowed = self::PERMISSIONS[$action] ?? [];
        return in_array($this->role, $allowed);
    }

    /** Có quyền quản lý network cụ thể không */
    public function canAccessNetwork(string|int $networkId): bool
    {
        if (in_array($this->role, ['owner', 'manager'])) return true;
        if (is_null($this->allowed_network_ids)) return true;
        return in_array($networkId, $this->allowed_network_ids);
    }

    /** Role label để hiển thị UI */
    public function getRoleLabelAttribute(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    /** Static helper: lấy danh sách options cho Select */
    public static function roleOptions(): array
    {
        return self::ROLES;
    }

    /**
     * Roles mà $actor được phép gán cho người khác.
     * Chỉ super_admin mới được gán role 'owner' (tránh tự nhân bản owner trong tenant).
     */
    public static function assignableRolesFor(?User $actor): array
    {
        if ($actor?->hasRole('super_admin')) {
            return self::ROLES;
        }
        $roles = self::ROLES;
        unset($roles['owner']);
        return $roles;
    }

    /** Role hiện tại có phải owner không */
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }
}
