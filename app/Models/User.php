<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'current_owner_id',
        'current_organization_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function ownerUsers(): HasMany
    {
        return $this->hasMany(OwnerUser::class);
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(Owner::class, 'owner_users')
            ->withPivot(['role', 'allowed_network_ids'])
            ->withTimestamps();
    }

    // SAU
    public function currentOwner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Owner::class, 'current_owner_id');
    }

    // ── Organization (buyer) relationships ──

    public function organizationUsers(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function currentOrganization(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function switchOrganization(string $orgId): bool
    {
        $hasAccess = $this->organizations()->where('organizations.id', $orgId)->exists()
            || $this->hasRole('super_admin');
        if (! $hasAccess) return false;
        $this->update(['current_organization_id' => $orgId]);
        return true;
    }

    public function isBuyer(): bool
    {
        return $this->organizations()->exists();
    }

    public function switchOwner(string $ownerId): bool
    {
        $hasAccess = $this->owners()->where('owners.id', $ownerId)->exists()
            || $this->hasRole('super_admin');
        if (! $hasAccess) return false;
        $this->update(['current_owner_id' => $ownerId]);
        return true;
    }

    public function getRoleInOwner(string $ownerId): ?string
    {
        return $this->ownerUsers()
            ->where('owner_id', $ownerId)
            ->value('role');
    }

    /**
     * Cổng access cho từng Filament panel.
     *
     * Strict matrix — mỗi user chỉ thuộc đúng MỘT panel theo Spatie system role,
     * publisher/buyer còn phải có active tenant membership tương ứng:
     *
     *   /admin     ⟵ Spatie role `super_admin`
     *   /publisher ⟵ Spatie role `publisher` + owner_users.role IS NOT NULL + owners.status='active'
     *   /buyer     ⟵ Spatie role `buyer`     + organization_users.role IS NOT NULL + organizations.status='active'
     *
     * Method này chỉ được Filament gọi nhờ class implement `FilamentUser`.
     * Không bỏ contract → mọi authenticated user sẽ vào được mọi panel (default).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin'     => $this->hasRole('super_admin'),

            // Kiểm **tenant đang chọn**, không chỉ "có tenant nào đó còn hoạt
            // động". Người thuộc ba owner mà owner đang chọn bị tạm ngưng thì
            // vẫn vào được panel và làm việc trên owner bị ngưng đó — cách kiểm
            // cũ cho qua vì hai owner kia vẫn active (Codex R02).
            'publisher' => $this->hasRole('publisher')
                && $this->hasActiveTenant($this->owners(), 'owners', $this->current_owner_id),

            'buyer'     => $this->hasRole('buyer')
                && $this->hasActiveTenant($this->organizations(), 'organizations', $this->current_organization_id),

            default     => false,
        };
    }

    /**
     * Tenant đang chọn có còn hoạt động không.
     *
     * Chưa chọn tenant nào thì xét "có ít nhất một tenant còn hoạt động" — đó là
     * người vừa được mời, chưa kịp chọn, và họ cần vào được để chọn.
     */
    private function hasActiveTenant(BelongsToMany $relation, string $table, ?string $currentId): bool
    {
        $query = $relation->wherePivot('role', '!=', null)->where("{$table}.status", 'active');

        if ($currentId) {
            return (clone $query)->where("{$table}.id", $currentId)->exists();
        }

        return $query->exists();
    }
}
