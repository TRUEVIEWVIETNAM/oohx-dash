<?php

namespace App\Filament\Buyer\Resources;

use App\Filament\Buyer\Resources\OrgUserResource\Pages;
use App\Models\OrganizationUser;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Exceptions\Halt;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class OrgUserResource extends Resource
{
    protected static ?string $model = OrganizationUser::class;

    protected static ?string $navigationGroup  = 'Cài đặt';
    protected static ?string $navigationLabel  = 'Thành viên';
    protected static ?string $modelLabel       = 'Thành viên';
    protected static ?string $pluralModelLabel = 'Thành viên';
    protected static ?string $navigationIcon   = 'heroicon-o-users';
    protected static ?int    $navigationSort   = 90;
    protected static ?string $slug             = 'team';

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', OrganizationUser::class);
    }

    public static function canCreate(): bool
    {
        return Gate::allows('create', OrganizationUser::class);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organization_id', auth()->user()->current_organization_id)
            ->with('user');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->heading(fn(string $operation) => $operation === 'edit' ? 'Sửa thành viên' : 'Mời thành viên')
                ->columns(1)
                ->schema([
                    Forms\Components\Placeholder::make('user_email')
                        ->label('Email')
                        ->content(fn(?OrganizationUser $record) => $record?->user?->email ?? '—')
                        ->visibleOn('edit'),

                    Forms\Components\Select::make('role')
                        ->label('Vai trò')
                        ->options(fn() => OrganizationUser::assignableRolesFor(auth()->user()))
                        ->required()
                        ->default('viewer')
                        ->live()
                        ->helperText(fn(?string $state) => OrganizationUser::ROLE_DESCRIPTIONS[$state] ?? ''),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
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
                    ->label('Vai trò')
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
            ->emptyStateIcon('heroicon-o-users');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrgUsers::route('/'),
            'edit'  => Pages\EditOrgUser::route('/{record}/edit'),
        ];
    }
}
