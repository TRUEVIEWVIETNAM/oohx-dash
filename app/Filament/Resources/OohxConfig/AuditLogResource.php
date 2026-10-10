<?php

namespace App\Filament\Resources\OohxConfig;

use App\Filament\Resources\OohxConfig\AuditLogResource\Pages;
use App\Models\Oohx\Config\AuditLog;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only viewer cho config.audit_log — append-only immutable history của mọi
 * config change (Laravel + Python CLI cùng ghi vào đây).
 *
 * Convention actor:
 *   - Laravel: email user (vd "admin@oohx.net") hoặc "web:<id>"
 *   - Python:  "cli:<unix_user>" (vd "cli:oohx")
 *   - System:  "system" khi không có actor xác định
 *
 * Permission: oohx_control có SELECT + INSERT, KHÔNG có UPDATE/DELETE → resource này
 * luôn read-only, no mutation allowed.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon  = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'OOHX · Data Engine';
    protected static ?string $navigationLabel = 'Nhật ký thay đổi cấu hình';
    protected static ?string $modelLabel      = 'Mục nhật ký';
    protected static ?int    $navigationSort  = 79;

    public static function canCreate(): bool { return false; }
    public static function canEdit($r): bool { return false; }
    public static function canDelete($r): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Lúc')
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->tooltip(fn ($state) => $state?->format('Y-m-d H:i:s')),

                Tables\Columns\TextColumn::make('actor')
                    ->searchable()
                    ->badge()
                    ->color(fn (string $state) => str_starts_with($state, 'cli:')
                        ? 'gray'
                        : (str_starts_with($state, 'web:') ? 'info' : 'success')
                    ),

                Tables\Columns\TextColumn::make('action')
                    ->badge()
                    ->color(fn (string $state) => match (true) {
                        str_starts_with($state, 'activate_') => 'success',
                        str_starts_with($state, 'publish_')  => 'primary',
                        str_starts_with($state, 'update_')   => 'warning',
                        default                              => 'gray',
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('target')
                    ->searchable()
                    ->wrap()
                    ->limit(40),

                Tables\Columns\TextColumn::make('old_value_display')
                    ->label('Trước')
                    ->getStateUsing(fn ($record) => $record->old_value
                        ? json_encode($record->old_value, JSON_UNESCAPED_UNICODE)
                        : '—')
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('new_value_display')
                    ->label('Sau')
                    ->getStateUsing(fn ($record) => $record->new_value
                        ? json_encode($record->new_value, JSON_UNESCAPED_UNICODE)
                        : '—')
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('note')
                    ->wrap()
                    ->limit(60)
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('actor')
                    ->options(fn () => AuditLog::query()
                        ->distinct()
                        ->orderBy('actor')
                        ->pluck('actor', 'actor')
                        ->toArray()),

                SelectFilter::make('action')
                    ->options(fn () => AuditLog::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->toArray()),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('Từ'),
                        \Filament\Forms\Components\DatePicker::make('until')->label('Đến'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Mục nhật ký')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('id')->label('ID'),
                    Infolists\Components\TextEntry::make('actor')->badge(),
                    Infolists\Components\TextEntry::make('created_at')->dateTime(),
                    Infolists\Components\TextEntry::make('action')->badge()->columnSpan(1),
                    Infolists\Components\TextEntry::make('target')->columnSpan(2),
                    Infolists\Components\TextEntry::make('note')
                        ->columnSpan(3)
                        ->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Trước / Sau')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('old_value_json')
                        ->label('Trước')
                        ->getStateUsing(fn ($record) => $record->old_value
                            ? json_encode($record->old_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                            : '—')
                        ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap break-all']),
                    Infolists\Components\TextEntry::make('new_value_json')
                        ->label('Sau')
                        ->getStateUsing(fn ($record) => $record->new_value
                            ? json_encode($record->new_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                            : '—')
                        ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap break-all']),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLog::route('/'),
            'view'  => Pages\ViewAuditEntry::route('/{record}'),
        ];
    }

    /**
     * Badge: số entries trong 24h qua. Silent fail nếu tunnel down.
     */
    public static function getNavigationBadge(): ?string
    {
        try {
            $n = AuditLog::query()->where('created_at', '>=', now()->subDay())->count();
            return $n > 0 ? (string) $n : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Số config changes trong 24h qua';
    }
}
