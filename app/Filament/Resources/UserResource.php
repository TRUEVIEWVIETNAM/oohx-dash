<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Owner;
use App\Models\OwnerUser;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationGroup = 'Organizations';
    protected static ?string $navigationLabel = 'Users';
    protected static ?int    $navigationSort  = 5;

    // ── Form ─────────────────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([

            Forms\Components\Section::make('Account Info')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->unique(User::class, 'email', ignoreRecord: true)
                        ->maxLength(255),

                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->dehydrateStateUsing(fn($state) => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn($state) => filled($state))
                        ->required(fn(string $operation) => $operation === 'create')
                        ->helperText(fn(string $operation) => $operation === 'edit'
                            ? 'Để trống nếu không muốn đổi mật khẩu'
                            : null)
                        ->maxLength(255),

                    Forms\Components\TextInput::make('password_confirmation')
                        ->password()
                        ->revealable()
                        ->same('password')
                        ->required(fn(string $operation) => $operation === 'create')
                        ->dehydrated(false)
                        ->label('Confirm Password'),
                ]),

            Forms\Components\Section::make('System Role')
                ->description('Role Spatie — xác định panel nào user được truy cập.')
                ->schema([
                    Forms\Components\Select::make('roles')
                        ->label('System Role')
                        ->options(fn() => Role::pluck('name', 'name'))
                        ->multiple(false)
                        ->required()
                        ->default('publisher')
                        ->helperText('super_admin → /admin | publisher → /publisher')
                        ->live()
                        ->afterStateHydrated(function (Forms\Components\Select $component, ?User $record) {
                            if ($record) {
                                $component->state($record->roles->first()?->name);
                            }
                        })
                        ->dehydrated(false),
                ]),

            Forms\Components\Section::make('Owner Memberships')
                ->description('Gán user vào một hoặc nhiều Media Owners.')
                ->schema([
                    Forms\Components\Repeater::make('owner_memberships')
                        ->label('')
                        ->schema([
                            Forms\Components\Select::make('owner_id')
                                ->label('Media Owner')
                                ->options(Owner::active()->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->columnSpan(1),

                            Forms\Components\Select::make('role')
                                ->label('Role')
                                ->options(OwnerUser::ROLE_LABELS)
                                ->default('read_only')
                                ->required()
                                ->columnSpan(1),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Thêm Owner')
                        ->reorderable(false)
                        ->afterStateHydrated(function (Forms\Components\Repeater $component, ?User $record) {
                            if (! $record) return;
                            $memberships = $record->ownerUsers()->with('owner')->get()
                                ->map(fn(OwnerUser $ou) => [
                                    'owner_id' => $ou->owner_id,
                                    'role'     => $ou->role,
                                ])->values()->toArray();
                            $component->state($memberships);
                        })
                        ->dehydrated(false),
                ]),
        ]);
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('system_role')
                    ->label('System Role')
                    ->badge()
                    ->getStateUsing(fn(User $record) => $record->roles->first()?->name ?? '—')
                    ->color(fn(string $state) => match ($state) {
                        'super_admin' => 'danger',
                        'publisher'   => 'primary',
                        default       => 'gray',
                    }),

                Tables\Columns\TextColumn::make('owners_list')
                    ->label('Owners')
                    ->getStateUsing(function (User $record) {
                        $items = $record->ownerUsers->map(function (OwnerUser $ou) {
                            $ownerName = $ou->owner?->name ?? '?';
                            $roleLabel = OwnerUser::ROLE_LABELS[$ou->role] ?? $ou->role;
                            return "{$ownerName} ({$roleLabel})";
                        });
                        return $items->isEmpty() ? '—' : $items->implode(', ');
                    })
                    ->wrap()
                    ->limit(80),

                Tables\Columns\TextColumn::make('owners_count')
                    ->label('Owners')
                    ->counts('ownerUsers')
                    ->sortable()
                    ->badge()
                    ->color(fn(int $state) => $state > 0 ? 'success' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->label('System Role')
                    ->options(fn() => Role::pluck('name', 'name'))
                    ->query(fn($query, $data) => $data['value']
                        ? $query->role($data['value'])
                        : $query),

                Tables\Filters\SelectFilter::make('owner')
                    ->label('Owner')
                    ->options(Owner::active()->pluck('name', 'id'))
                    ->query(fn($query, $data) => $data['value']
                        ? $query->whereHas('ownerUsers', fn($q) => $q->where('owner_id', $data['value']))
                        : $query),

                Tables\Filters\SelectFilter::make('owner_role')
                    ->label('Owner Role')
                    ->options(OwnerUser::ROLE_LABELS)
                    ->query(fn($query, $data) => $data['value']
                        ? $query->whereHas('ownerUsers', fn($q) => $q->where('role', $data['value']))
                        : $query),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\Action::make('impersonate')
                        ->label('Login As')
                        ->icon('heroicon-o-arrow-right-on-rectangle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Login as user này?')
                        ->modalDescription(fn(User $record) => "Bạn sẽ đăng nhập với tài khoản {$record->email}.")
                        ->action(function (User $record) {
                            auth()->login($record);
                            $panel = $record->hasRole('super_admin') ? '/admin' : '/publisher';
                            return redirect($panel);
                        }),

                    Tables\Actions\Action::make('reset_password')
                        ->label('Reset Password')
                        ->icon('heroicon-o-key')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(function (User $record) {
                            \Password::broker()->sendResetLink(['email' => $record->email]);
                            Notification::make()
                                ->title("Đã gửi email reset password đến {$record->email}")
                                ->success()->send();
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    // ── Infolist (View page) ────────────────────────────────────────────────

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([

            Infolists\Components\Section::make('Account Info')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('name'),
                    Infolists\Components\TextEntry::make('email')
                        ->copyable(),
                    Infolists\Components\TextEntry::make('system_role')
                        ->label('System Role')
                        ->badge()
                        ->getStateUsing(fn(User $record) => $record->roles->first()?->name ?? '—')
                        ->color(fn(string $state) => match ($state) {
                            'super_admin' => 'danger',
                            'publisher'   => 'primary',
                            default       => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Created')
                        ->dateTime(),
                    Infolists\Components\TextEntry::make('email_verified_at')
                        ->label('Email Verified')
                        ->dateTime()
                        ->placeholder('Not verified'),
                ]),

            Infolists\Components\Section::make('Owner Memberships')
                ->description('Danh sách tất cả Media Owners mà user thuộc về.')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('ownerUsers')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('owner.name')
                                ->label('Owner'),
                            Infolists\Components\TextEntry::make('role')
                                ->label('Role')
                                ->badge()
                                ->formatStateUsing(fn($state) => OwnerUser::ROLE_LABELS[$state] ?? $state)
                                ->color(fn($state) => match ($state) {
                                    'owner'          => 'danger',
                                    'manager'        => 'primary',
                                    'scheduler'      => 'warning',
                                    'read_only'      => 'gray',
                                    'reporting_only' => 'info',
                                    'sales_manager'  => 'success',
                                    default          => 'gray',
                                }),
                            Infolists\Components\TextEntry::make('permissions_list')
                                ->label('Permissions')
                                ->getStateUsing(function (OwnerUser $record) {
                                    return collect(OwnerUser::PERMISSIONS)
                                        ->filter(fn($roles) => in_array($record->role, $roles))
                                        ->keys()
                                        ->implode(', ');
                                })
                                ->columnSpan(2),
                            Infolists\Components\TextEntry::make('allowed_network_ids')
                                ->label('Network Restriction')
                                ->getStateUsing(fn(OwnerUser $record) => $record->allowed_network_ids
                                    ? count($record->allowed_network_ids) . ' networks restricted'
                                    : 'All networks')
                                ->badge()
                                ->color(fn(OwnerUser $record) => $record->allowed_network_ids ? 'warning' : 'success'),
                        ])
                        ->columns(5)
                        ->contained(false),
                ]),

            Infolists\Components\Section::make('Permission Matrix')
                ->description('Tổng hợp quyền trên tất cả owners.')
                ->schema([
                    Infolists\Components\ViewEntry::make('permission_matrix')
                        ->view('filament.resources.user-resource.permission-matrix'),
                ]),
        ]);
    }

    // ── Pages ─────────────────────────────────────────────────────────────────

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view'   => Pages\ViewUser::route('/{record}'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
