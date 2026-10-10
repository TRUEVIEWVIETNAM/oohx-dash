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
     * Chữ tiếng Việt cho 6 vai trò trong một media owner, quyền giảm dần.
     *
     * ══ Hai lỗi bảng này sửa ══
     *
     *  1. Chữ là **tiếng Anh** — "Owner", "Manager", "Scheduler", "Read only",
     *     "Reporting only", "Sales manager". Docblock cũ nói chúng "khớp với ảnh
     *     thiết kế", nhưng chỗ chúng ra tới không phải chỉ một bảng quản trị:
     *     `InvitationController` và `UserInvitationNotification` đóng chúng vào
     *     **thư mời**, nên nhân sự của media owner nhận một email tiếng Việt kết
     *     thúc bằng "với vai trò **Read only**". Đúng hình dạng lỗi đã sửa ở
     *     `OrganizationUser::ROLE_LABELS` ("Admin", "Planner", "Viewer").
     *  2. Hằng tên `ROLES`, **không** theo quy ước `<CỘT>_LABELS`, nên bộ tự tìm
     *     của `NhanEnumMotNoiTest` không thấy: `owner_users.role` là enum 6 giá
     *     trị, có bảng chữ, mà không ai đối chiếu hai thứ đó với nhau. Đổi tên
     *     là để nó vào tầm — và từ nay thêm một giá trị vào enum mà quên chữ là
     *     **đỏ**.
     *
     * ══ `scheduler` → `operator`: mã nói sai việc, nên đổi cả mã ══
     *
     * Tên cũ hứa việc lên lịch phát, nhưng bộ quyền của vai trò này chỉ có
     * `manage_inventory`, `import_inventory` và `view_inventory` — không
     * `manage_pricing`, không `view_reports`, không `manage_bookings`. Nó là
     * vai trò **vận hành kho điểm phát**.
     *
     * Lần dịch chữ trước giữ nghĩa của mã (`scheduler` → "Lập lịch") và ghi
     * chỗ lệch vào đây thay vì tự quyết, vì đổi tên một vai trò là quyết định
     * nghiệp vụ kéo theo một migration enum. Quyết định đó đã chốt 10/10/2026:
     * mã thành `operator`, chữ thành "Vận hành".
     *
     * Migration `2026_10_10_000001` map dữ liệu đang có, cả `owner_users.role`
     * lẫn lời mời **chưa nhận** ở `user_invitations.role` — hàng đã nhận là
     * lịch sử, không sửa. `down()` đi về được mà không mất thông tin, và có ca
     * test đi cả hai chiều với dữ liệu thật của cả sáu vai trò.
     */
    public const ROLE_LABELS = [
        'owner'          => 'Chủ sở hữu',
        'manager'        => 'Quản lý',
        'operator'       => 'Vận hành',
        'read_only'      => 'Chỉ xem',
        'reporting_only' => 'Chỉ báo cáo',
        'sales_manager'  => 'Quản lý bán hàng',
    ];

    /**
     * Mô tả ngắn về quyền của từng role — dùng làm helperText/Placeholder trong forms.
     *
     * Hai dòng dưới đây từng **nói sai** bộ quyền thật ở `PERMISSIONS`, và đây
     * là chữ người gán vai trò đọc ngay lúc gán:
     *
     *  - `manager` và `sales_manager` đều có `manage_bookings` — tức duyệt / từ
     *    chối yêu cầu đặt chỗ — mà mô tả của `sales_manager` lại kết thúc bằng
     *    "Không chỉnh sửa". Duyệt một đơn là quyết định có hệ quả tiền, không
     *    phải một việc "chỉ xem".
     *  - `manager` còn có `settle_refunds` (khai đã hoàn tiền cho người mua),
     *    cũng không được nhắc.
     */
    public const ROLE_DESCRIPTIONS = [
        'owner'          => '👑 Toàn quyền: quản lý team, inventory, pricing, reports.',
        'manager'        => '🔧 Quản lý inventory & pricing, duyệt đặt chỗ, khai hoàn tiền, xem reports. Không quản lý được users.',
        'operator'       => '🔨 Thêm/sửa screens và import inventory. Không sửa được giá, không xem được reports.',
        'read_only'      => '👁 Chỉ xem screens và sites, không chỉnh sửa.',
        'reporting_only' => '📊 Chỉ xem và export reports, không thấy inventory.',
        'sales_manager'  => '💼 Xem inventory, sales dashboard và duyệt đặt chỗ. Không sửa được inventory, không khai hoàn tiền.',
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
        'manage_inventory' => ['owner', 'manager', 'operator'],
        // Bật/tắt programmatic, sửa floor CPM
        'manage_pricing'   => ['owner', 'manager'],
        // Xem screens, sites (read)
        'view_inventory'   => ['owner', 'manager', 'operator', 'read_only', 'sales_manager'],
        // Import bulk xlsx
        'import_inventory' => ['owner', 'manager', 'operator'],
        // Xem revenue & impression reports
        'view_reports'     => ['owner', 'manager', 'reporting_only', 'sales_manager'],
        // Export report CSV/Excel
        'export_reports'   => ['owner', 'manager', 'reporting_only'],
        // Xem sales dashboard (CPM, deals)
        'view_sales'       => ['owner', 'manager', 'sales_manager'],
        // Duyệt / từ chối yêu cầu đặt chỗ. Đây là quyết định thương mại có hệ
        // quả tiền, nên không mở cho operator hay read_only — trước đây action
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
        return self::ROLE_LABELS[$this->role] ?? $this->role;
    }

    /** Static helper: lấy danh sách options cho Select */
    public static function roleOptions(): array
    {
        return self::ROLE_LABELS;
    }

    /**
     * Roles mà $actor được phép gán cho người khác.
     * Chỉ super_admin mới được gán role 'owner' (tránh tự nhân bản owner trong tenant).
     */
    public static function assignableRolesFor(?User $actor): array
    {
        if ($actor?->hasRole('super_admin')) {
            return self::ROLE_LABELS;
        }
        $roles = self::ROLE_LABELS;
        unset($roles['owner']);
        return $roles;
    }

    /** Role hiện tại có phải owner không */
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }
}
