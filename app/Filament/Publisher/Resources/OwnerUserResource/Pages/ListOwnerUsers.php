<?php

namespace App\Filament\Publisher\Resources\OwnerUserResource\Pages;

use App\Filament\Publisher\Resources\OwnerUserResource;
use App\Models\OwnerUser;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListOwnerUsers extends ListRecords
{
    protected static string $resource = OwnerUserResource::class;

    protected function getHeaderActions(): array
    {
        if (! Gate::allows('create', OwnerUser::class)) {
            return [];
        }

        return [
            Actions\Action::make('invite_user')
                ->label('Invite User')
                ->icon('heroicon-o-user-plus')
                ->color('primary')
                ->form([
                    Forms\Components\TextInput::make('email')
                        ->label('Email address')
                        ->email()
                        ->required()
                        ->placeholder('user@example.com')
                        ->helperText('Email lời mời sẽ được gửi đến địa chỉ này. Nếu chưa có tài khoản, user sẽ tự tạo password khi accept.'),

                    Forms\Components\Select::make('role')
                        ->label('Role')
                        ->options(fn() => OwnerUser::assignableRolesFor(auth()->user()))
                        ->default('read_only')
                        ->required()
                        ->live()
                        ->helperText(fn(?string $state) => OwnerUser::ROLE_DESCRIPTIONS[$state] ?? ''),

                    Forms\Components\CheckboxList::make('allowed_network_ids')
                        ->label('Giới hạn Networks (để trống = tất cả)')
                        ->options(fn() => \App\Models\Network::where(
                            'owner_id', auth()->user()->current_owner_id
                        )->pluck('name', 'id'))
                        ->columns(2)
                        ->visible(fn(Forms\Get $get) => in_array($get('role'), ['scheduler', 'read_only'])),
                ])
                ->action(function (array $data): void {
                    try {
                        app(UserInvitationService::class)->invite(
                            email:             $data['email'],
                            tenantType:        UserInvitation::TENANT_OWNER,
                            tenantId:          auth()->user()->current_owner_id,
                            role:              $data['role'],
                            allowedNetworkIds: in_array($data['role'], ['scheduler', 'read_only'])
                                ? ($data['allowed_network_ids'] ?? null)
                                : null,
                            invitedBy:         auth()->user(),
                        );

                        Notification::make()
                            ->title("✅ Đã gửi lời mời tới {$data['email']}")
                            ->body('Role: ' . (OwnerUser::ROLE_LABELS[$data['role']] ?? $data['role']) . ' · hết hạn sau 7 ngày')
                            ->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Không gửi được lời mời')
                            ->body($e->getMessage())
                            ->danger()->send();
                    }
                }),
        ];
    }
}
