<?php

namespace App\Filament\Buyer\Resources\OrgUserResource\Pages;

use App\Filament\Buyer\Resources\OrgUserResource;
use App\Models\OrganizationUser;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListOrgUsers extends ListRecords
{
    protected static string $resource = OrgUserResource::class;

    protected function getHeaderActions(): array
    {
        if (! Gate::allows('create', OrganizationUser::class)) {
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
                        ->helperText('Email lời mời sẽ được gửi đến địa chỉ này.'),

                    Forms\Components\Select::make('role')
                        ->label('Role')
                        ->options(fn() => OrganizationUser::assignableRolesFor(auth()->user()))
                        ->default('viewer')
                        ->required()
                        ->live()
                        ->helperText(fn(?string $state) => OrganizationUser::ROLE_DESCRIPTIONS[$state] ?? ''),
                ])
                ->action(function (array $data): void {
                    try {
                        app(UserInvitationService::class)->invite(
                            email:             $data['email'],
                            tenantType:        UserInvitation::TENANT_ORGANIZATION,
                            tenantId:          auth()->user()->current_organization_id,
                            role:              $data['role'],
                            allowedNetworkIds: null,
                            invitedBy:         auth()->user(),
                        );
                        Notification::make()
                            ->title("✅ Đã gửi lời mời tới {$data['email']}")
                            ->body('Role: ' . (OrganizationUser::ROLE_LABELS[$data['role']] ?? $data['role']) . ' · hết hạn sau 7 ngày')
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
