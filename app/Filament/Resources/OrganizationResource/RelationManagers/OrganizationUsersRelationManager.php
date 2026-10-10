<?php

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Models\OrganizationUser;
use App\Models\UserInvitation;
use App\Services\UserInvitationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class OrganizationUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'organizationUsers';
    protected static ?string $title = 'Thành viên';
    protected static ?string $icon  = 'heroicon-o-users';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(1)->schema([
                Forms\Components\Placeholder::make('user_email')
                    ->label('Email')
                    ->content(fn(?OrganizationUser $record) => $record?->user?->email ?? '—')
                    ->visibleOn('edit'),

                Forms\Components\Select::make('role')
                    ->label('Vai trò')
                    ->options(fn() => OrganizationUser::assignableRolesFor(auth()->user()))
                    ->default('viewer')
                    ->required()
                    ->live()
                    ->helperText(fn(?string $state) => OrganizationUser::ROLE_DESCRIPTIONS[$state] ?? ''),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('user.email')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable()
                    ->placeholder('(chưa có tên)'),
                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\BadgeColumn::make('role')
                    ->formatStateUsing(fn($state) => OrganizationUser::ROLE_LABELS[$state] ?? $state)
                    ->colors([
                        'danger'  => 'admin',
                        'primary' => 'planner',
                        'gray'    => 'viewer',
                    ]),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tham gia từ')
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('invite')
                    ->label('Mời thành viên')
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->visible(fn() => Gate::allows('create', OrganizationUser::class))
                    ->form([
                        Forms\Components\TextInput::make('email')
                            ->label('Địa chỉ email')
                            ->email()
                            ->required()
                            ->placeholder('user@example.com')
                            ->helperText('Email lời mời sẽ được gửi đến địa chỉ này.'),

                        Forms\Components\Select::make('role')
                            ->label('Vai trò')
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
                                tenantId:          $this->getOwnerRecord()->id,
                                role:              $data['role'],
                                allowedNetworkIds: null,
                                invitedBy:         auth()->user(),
                            );
                            Notification::make()
                                ->title("✅ Đã gửi lời mời tới {$data['email']}")
                                ->body('Vai trò: ' . (OrganizationUser::ROLE_LABELS[$data['role']] ?? $data['role']) . ' · hết hạn sau 7 ngày')
                                ->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Không gửi được lời mời')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->visible(fn(OrganizationUser $record) => Gate::allows('update', $record))
                        ->before(function (OrganizationUser $record) {
                            if ($record->role === 'admin' && ! auth()->user()->hasRole('super_admin')) {
                                Notification::make()->title('Không thể sửa quyền Admin')->danger()->send();
                                throw new Halt();
                            }
                        }),

                    Tables\Actions\Action::make('remove')
                        ->label('Xoá khỏi team')
                        ->icon('heroicon-o-user-minus')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Xoá thành viên này khỏi team?')
                        ->modalDescription('User sẽ không còn truy cập organization này.')
                        ->visible(fn(OrganizationUser $record) => Gate::allows('delete', $record))
                        ->action(function (OrganizationUser $record): void {
                            if ($record->user_id === auth()->id()) {
                                Notification::make()->title('Không thể tự xoá chính mình')->danger()->send();
                                return;
                            }
                            $record->delete();
                            Notification::make()->title('Đã xoá khỏi team')->success()->send();
                        }),
                ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Chưa có team member')
            ->emptyStateDescription('Mời thành viên vào organization này.')
            ->emptyStateIcon('heroicon-o-users');
    }
}
