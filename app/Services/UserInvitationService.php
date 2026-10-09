<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class UserInvitationService
{
    public const TOKEN_TTL_DAYS = 7;

    /**
     * Tạo invitation cho một email vào tenant + gửi email.
     *
     * @throws InvalidArgumentException nếu role không hợp lệ với tenant_type.
     * @throws RuntimeException nếu user đã là member.
     */
    public function invite(
        string $email,
        string $tenantType,
        string $tenantId,
        string $role,
        ?array $allowedNetworkIds,
        User $invitedBy,
    ): UserInvitation {
        $email = strtolower(trim($email));

        $this->guardRole($tenantType, $role);
        $this->guardNotAlreadyMember($email, $tenantType, $tenantId);

        // Revoke pending invitations cũ cho cùng email + tenant để tránh confused tokens
        UserInvitation::where('email', $email)
            ->where('tenant_type', $tenantType)
            ->where('tenant_id', $tenantId)
            ->whereNull('accepted_at')
            ->delete();

        $invitation = UserInvitation::create([
            'email'               => $email,
            'tenant_type'         => $tenantType,
            'tenant_id'           => $tenantId,
            'role'                => $role,
            'allowed_network_ids' => $allowedNetworkIds,
            'token'               => $this->generateToken(),
            'invited_by_user_id'  => $invitedBy->id,
            'expires_at'          => now()->addDays(self::TOKEN_TTL_DAYS),
        ]);

        Notification::route('mail', $email)->notify(new UserInvitationNotification($invitation));

        return $invitation;
    }

    /**
     * Chấp nhận invitation. Atomic: tạo/find user, gán Spatie role, tạo pivot, set current_*_id.
     *
     * @throws RuntimeException nếu token không hợp lệ / hết hạn / đã accept.
     */
    public function accept(string $token, string $name, string $password): User
    {
        return DB::transaction(function () use ($token, $name, $password) {
            $invitation = UserInvitation::where('token', $token)->lockForUpdate()->first();

            if (! $invitation) {
                throw new RuntimeException('Lời mời không tồn tại.');
            }
            if ($invitation->isAccepted()) {
                throw new RuntimeException('Lời mời đã được sử dụng.');
            }
            if ($invitation->isExpired()) {
                throw new RuntimeException('Lời mời đã hết hạn.');
            }

            $user = User::firstOrCreate(
                ['email' => $invitation->email],
                [
                    'name'     => $name ?: Str::before($invitation->email, '@'),
                    'password' => Hash::make($password),
                ],
            );

            // Update password + name nếu user vừa được tạo (firstOrCreate trả existing nếu có)
            if (! $user->wasRecentlyCreated) {
                // Không reset password user đã tồn tại — họ vẫn login bằng pass cũ
                if ($name) {
                    $user->update(['name' => $name]);
                }
            }

            $this->attachToTenant($user, $invitation);

            $invitation->update(['accepted_at' => now()]);

            return $user->fresh();
        });
    }

    /**
     * Gắn user vào tenant pivot + Spatie role + set current_*_id.
     */
    private function attachToTenant(User $user, UserInvitation $invitation): void
    {
        if ($invitation->tenant_type === UserInvitation::TENANT_OWNER) {
            $tenant = Owner::findOrFail($invitation->tenant_id);

            if (! $user->hasRole('publisher')) {
                $user->assignRole('publisher');
            }

            OwnerUser::firstOrCreate(
                ['owner_id' => $tenant->id, 'user_id' => $user->id],
                [
                    'role'                => $invitation->role,
                    'allowed_network_ids' => in_array($invitation->role, ['scheduler', 'read_only'])
                        ? $invitation->allowed_network_ids
                        : null,
                ],
            );

            if (! $user->current_owner_id) {
                $user->update(['current_owner_id' => $tenant->id]);
            }
            return;
        }

        if ($invitation->tenant_type === UserInvitation::TENANT_ORGANIZATION) {
            $tenant = Organization::findOrFail($invitation->tenant_id);

            if (! $user->hasRole('buyer')) {
                $user->assignRole('buyer');
            }

            OrganizationUser::firstOrCreate(
                ['organization_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => $invitation->role],
            );

            if (! $user->current_organization_id) {
                $user->update(['current_organization_id' => $tenant->id]);
            }
            return;
        }

        throw new InvalidArgumentException("Tenant type không hợp lệ: {$invitation->tenant_type}");
    }

    private function guardRole(string $tenantType, string $role): void
    {
        $valid = match ($tenantType) {
            UserInvitation::TENANT_OWNER        => array_keys(OwnerUser::ROLE_LABELS),
            UserInvitation::TENANT_ORGANIZATION => array_keys(OrganizationUser::PERMISSIONS),
            default                             => [],
        };

        if (! in_array($role, $valid, true)) {
            throw new InvalidArgumentException("Role '{$role}' không hợp lệ cho tenant '{$tenantType}'.");
        }
    }

    private function guardNotAlreadyMember(string $email, string $tenantType, string $tenantId): void
    {
        $user = User::where('email', $email)->first();
        if (! $user) return;

        $exists = match ($tenantType) {
            UserInvitation::TENANT_OWNER => OwnerUser::where('owner_id', $tenantId)
                ->where('user_id', $user->id)->exists(),
            UserInvitation::TENANT_ORGANIZATION => OrganizationUser::where('organization_id', $tenantId)
                ->where('user_id', $user->id)->exists(),
            default => false,
        };

        if ($exists) {
            throw new RuntimeException("{$email} đã là thành viên của tenant này.");
        }
    }

    private function generateToken(): string
    {
        return Str::random(64);
    }
}
