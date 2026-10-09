<?php

namespace App\Services;

use App\Models\OwnerUser;
use App\Models\User;

/**
 * Helper tập trung để kiểm tra quyền trong Publisher panel.
 * Dùng thay vì gọi trực tiếp OwnerUser::PERMISSIONS khắp nơi.
 *
 * Cách dùng:
 *   TenantPermission::check('manage_inventory')       // user hiện tại, owner hiện tại
 *   TenantPermission::check('view_reports', $userId, $ownerId)
 *   TenantPermission::for(auth()->user())->can('import_inventory')
 */
class TenantPermission
{
    public function __construct(
        private readonly User   $user,
        private readonly string $ownerId,
    ) {}

    // ── Static entry points ───────────────────────────────────────────────────

    /** Kiểm tra user hiện tại + current_owner_id */
    public static function check(string $action): bool
    {
        $user = auth()->user();
        if (! $user) return false;
        if ($user->hasRole('super_admin')) return true;

        return static::for($user)->can($action);
    }

    /** Tạo instance cho user cụ thể */
    public static function for(User $user, ?string $ownerId = null): static
    {
        return new static($user, $ownerId ?? (string) $user->current_owner_id);
    }

    // ── Instance methods ──────────────────────────────────────────────────────

    public function can(string $action): bool
    {
        if ($this->user->hasRole('super_admin')) return true;

        $ownerUser = $this->getOwnerUser();
        return $ownerUser?->can($action) ?? false;
    }

    public function cannot(string $action): bool
    {
        return ! $this->can($action);
    }

    public function role(): ?string
    {
        return $this->getOwnerUser()?->role;
    }

    public function roleLabel(): string
    {
        $role = $this->role();
        return $role ? (OwnerUser::ROLE_LABELS[$role] ?? $role) : '—';
    }

    /**
     * Membership phải còn tồn tại VÀ owner phải đang hoạt động.
     *
     * Trước đây chỉ kiểm pivot: owner bị tạm ngưng nhưng thành viên vẫn ghi được
     * inventory qua API (Codex review T1, F2). Tạm ngưng một media owner phải có
     * hiệu lực ngay ở mọi cửa, không chỉ ở chỗ hiển thị công khai.
     */
    private function getOwnerUser(): ?OwnerUser
    {
        if (empty($this->ownerId)) return null;

        return OwnerUser::where('owner_id', $this->ownerId)
            ->where('user_id', $this->user->id)
            ->whereHas('owner', fn ($q) => $q->where('status', 'active'))
            ->first();
    }
}
