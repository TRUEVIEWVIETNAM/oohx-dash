<?php

namespace App\Notifications;

use App\Models\OwnerUser;
use App\Models\OrganizationUser;
use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email lời mời tham gia tenant. Gửi qua AnonymousNotifiable::route('mail', $email)
 * vì invitee có thể chưa có User record.
 */
class UserInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private UserInvitation $invitation,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tenant = $this->invitation->tenant();
        $tenantName = $tenant?->name ?? 'OOHX';

        $roleLabel = match ($this->invitation->tenant_type) {
            UserInvitation::TENANT_OWNER        => OwnerUser::ROLES[$this->invitation->role] ?? $this->invitation->role,
            UserInvitation::TENANT_ORGANIZATION => OrganizationUser::ROLE_LABELS[$this->invitation->role] ?? $this->invitation->role,
            default                             => $this->invitation->role,
        };

        $dashUrl    = config('domains.dash', 'dash.oohx.net');
        $acceptUrl  = "https://{$dashUrl}/invitations/{$this->invitation->token}/accept";
        $expiresFmt = $this->invitation->expires_at?->format('d/m/Y H:i') ?? '—';
        $inviter    = $this->invitation->invitedBy?->name ?? 'Quản trị viên';

        return (new MailMessage)
            ->subject("Lời mời tham gia {$tenantName} trên OOHX")
            ->greeting('Xin chào,')
            ->line("**{$inviter}** đã mời bạn tham gia **{$tenantName}** với vai trò **{$roleLabel}** trên hệ thống OOHX.")
            ->action('Chấp nhận lời mời', $acceptUrl)
            ->line("Đường link sẽ hết hạn vào {$expiresFmt}.")
            ->line('Nếu bạn không nhận ra lời mời này, có thể bỏ qua email.');
    }
}
