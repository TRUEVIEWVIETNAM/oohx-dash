<?php

namespace App\Filament\Publisher\Resources;

use App\Filament\Publisher\Resources\OwnerUserResource\Pages;
use App\Models\OwnerUser;
use App\Services\TenantPermission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class OwnerUserResource extends Resource
{
    protected static ?string $model = OwnerUser::class;


    protected static ?string $navigationGroup = 'Cài đặt';
    protected static ?string $navigationLabel = 'Thành viên';
    protected static ?string $modelLabel      = 'Thành viên';
    protected static ?string $pluralModelLabel = 'Thành viên';
    protected static ?int    $navigationSort  = 90;

    /**
     * Vai trò có thể bị giới hạn theo network — một định nghĩa.
     *
     * Ba chỗ trong tệp này đọc cùng danh sách (hiện ô, lọc lúc lưu, câu
     * helperText), và câu helperText trước đây viết **tay** tên hai vai trò đó
     * bằng tiếng Anh. Đổi chữ ở `ROLE_LABELS` mà câu kia không đổi theo là một
     * chỗ trôi im lặng, nên câu đó giờ dựng từ chính bảng chữ.
     */
    private const GIOI_HAN_THEO_NETWORK = ['operator', 'read_only'];

    // ── Chỉ hiển thị nếu user có quyền manage_users ──────────────────────────

    public static function canViewAny(): bool
    {
        // Chỉ owner-role và super_admin được xem danh sách team members
        // (sales_manager / read_only / operator / reporting_only không có manage_users).
        return TenantPermission::check('manage_users');
    }

    public static function canCreate(): bool
    {
        return Gate::allows('create', OwnerUser::class);
    }
    // ── Chỉ scope data theo current_owner_id ─────────────────────────────────

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('owner_id', auth()->user()->current_owner_id)
            ->with('user');
    }

    // ── Form ─────────────────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->heading(fn(string $operation) => $operation === 'edit' ? 'Edit Team Member' : 'Invite Team Member')
                ->schema([

                Forms\Components\Placeholder::make('user_email')
                    ->label('Email')
                    ->content(fn(?OwnerUser $record) => $record?->user?->email ?? '—')
                    ->visibleOn('edit'),

                Forms\Components\Select::make('role')
                    ->label('Vai trò')
                    ->options(fn() => OwnerUser::assignableRolesFor(auth()->user()))
                    ->required()
                    ->default('read_only')
                    ->helperText(fn(?string $state) => OwnerUser::ROLE_DESCRIPTIONS[$state] ?? '')
                    ->live(),

                Forms\Components\CheckboxList::make('allowed_network_ids')
                    ->label('Giới hạn theo mạng lưới (không bắt buộc)')
                    ->helperText('Để trống = truy cập tất cả networks. Chỉ áp dụng cho '
                        . implode(' và ', array_map(
                            fn (string $r) => OwnerUser::ROLE_LABELS[$r] ?? $r,
                            self::GIOI_HAN_THEO_NETWORK,
                        )) . '.')
                    ->options(fn() => \App\Models\Network::where('owner_id', auth()->user()->current_owner_id)
                        ->pluck('name', 'id'))
                    ->columns(2)
                    ->visible(fn(Forms\Get $get) => in_array($get('role'), self::GIOI_HAN_THEO_NETWORK))
                    // Khi role không thuộc operator/read_only, dehydrate null để xoá restriction cũ.
                    ->dehydrateStateUsing(fn($state, Forms\Get $get) => in_array($get('role'), self::GIOI_HAN_THEO_NETWORK) ? $state : null),

            ])->columns(1),
        ]);
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Tên')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\BadgeColumn::make('role')
                    ->label('Vai trò')
                    ->formatStateUsing(fn($state) => OwnerUser::ROLE_LABELS[$state] ?? $state)
                    ->colors([
                        'danger'  => 'owner',
                        'primary' => 'manager',
                        'warning' => 'operator',
                        'gray'    => 'read_only',
                        'info'    => 'reporting_only',
                        'success' => 'sales_manager',
                    ]),

                Tables\Columns\TextColumn::make('allowed_network_ids')
                    ->label('Mạng lưới')
                    ->formatStateUsing(fn($state) => $state ? count($state) . ' restricted' : 'All')
                    ->badge()
                    ->color(fn($state) => $state ? 'warning' : 'success'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tham gia từ')
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()
                        ->visible(fn(OwnerUser $record) => Gate::allows('update', $record))
                        ->before(function (OwnerUser $record) {
                            if ($record->role === 'owner' && ! auth()->user()->hasRole('super_admin')) {
                                Notification::make()
                                    ->title('Không thể sửa quyền Owner')
                                    ->danger()->send();
                                throw new Halt();
                            }
                        }),

                    Tables\Actions\Action::make('remove')
                        ->label('Xoá khỏi team')
                        ->icon('heroicon-o-user-minus')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Xoá thành viên này khỏi team?')
                        ->modalDescription('User sẽ không còn truy cập được vào owner này.')
                        ->visible(fn(OwnerUser $record) => Gate::allows('delete', $record))
                        ->action(function (OwnerUser $record) {
                            if ($record->user_id === auth()->id()) {
                                Notification::make()->title('Không thể tự xoá chính mình')->danger()->send();
                                return;
                            }
                            $record->delete();
                            Notification::make()->title('Đã xoá khỏi team')->success()->send();
                        }),
                ]),
            ])
            ->bulkActions([])  // không cho bulk delete để tránh nhầm
            ->emptyStateHeading('Chưa có team member')
            ->emptyStateDescription('Mời thành viên vào owner này để cộng tác.')
            ->emptyStateIcon('heroicon-o-users');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOwnerUsers::route('/'),
            'edit'  => Pages\EditOwnerUser::route('/{record}/edit'),
        ];
    }
}
