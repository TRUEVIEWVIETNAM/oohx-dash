<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationGroup = 'Tổ chức';
    protected static ?string $navigationLabel = 'Vai trò';
    protected static ?string $modelLabel      = 'Vai trò hệ thống';
    protected static ?int    $navigationSort  = 6;

    // ── Form ─────────────────────────────────────────────────────────────────

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin vai trò')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(125)
                        ->unique(ignoreRecord: true)
                        ->helperText('Tên role dạng slug, vd: super_admin, publisher, support')
                        ->regex('/^[a-z0-9_]+$/')
                        ->validationMessages([
                            'regex' => 'Chỉ cho phép ký tự thường, số và dấu _',
                        ]),

                    Forms\Components\TextInput::make('guard_name')
                        ->default('web')
                        ->required()
                        ->maxLength(125)
                        ->helperText('Thường là "web"'),
                ]),

            Forms\Components\Section::make('Phạm vi truy cập')
                ->schema([
                    Forms\Components\Placeholder::make('access_info')
                        ->content(function (?Role $record) {
                            if (! $record) return 'Tạo role trước để xem thống kê.';

                            $userCount = $record->users()->count();
                            return "Hiện có {$userCount} user(s) đang sử dụng role này.";
                        }),
                ]),
        ]);
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Vai trò')
                    ->badge()
                    ->color(fn(string $state) => match ($state) {
                        'super_admin' => 'danger',
                        'publisher'   => 'primary',
                        default       => 'gray',
                    })
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('guard_name')
                    ->label('Guard')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Người dùng')
                    ->counts('users')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make()
                        ->before(function (Role $record) {
                            if ($record->users()->exists()) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Không thể xoá')
                                    ->body("Role \"{$record->name}\" đang có {$record->users()->count()} user(s). Hãy chuyển users sang role khác trước.")
                                    ->danger()
                                    ->send();
                                throw new \Filament\Support\Exceptions\Halt();
                            }
                        }),
                ]),
            ])
            ->bulkActions([]);
    }

    // ── Infolist ─────────────────────────────────────────────────────────────

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Chi tiết vai trò')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('name')
                        ->label('Vai trò')
                        ->badge()
                        ->color(fn(string $state) => match ($state) {
                            'super_admin' => 'danger',
                            'publisher'   => 'primary',
                            default       => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('guard_name')
                        ->label('Guard'),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Tạo lúc')
                        ->dateTime(),
                ]),

            Infolists\Components\Section::make('Users với role này')
                ->schema([
                    Infolists\Components\ViewEntry::make('role_users')
                        ->view('filament.resources.role-resource.role-users'),
                ]),
        ]);
    }

    // ── Pages ─────────────────────────────────────────────────────────────────

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'view'   => Pages\ViewRole::route('/{record}'),
            'edit'   => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
