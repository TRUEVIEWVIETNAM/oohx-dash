<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PoiSnapshotResource\Pages;
use App\Models\PoiSnapshot;
use App\Services\PoiContextEnricher;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only browser cho poi_snapshots.
 *
 * Auto-populated bởi PoiContextEnricher; admin chỉ view + delete + refresh.
 * Form không có (canCreate=false). Refresh action gọi enricher.fetchPoisOnly()
 * để re-fetch từ Overpass.
 */
class PoiSnapshotResource extends Resource
{
    protected static ?string $model = PoiSnapshot::class;

    protected static ?string $navigationIcon  = 'heroicon-o-map-pin';
    protected static ?string $navigationGroup = 'Cấu hình hệ thống';
    protected static ?string $navigationLabel = 'Ảnh chụp POI';
    protected static ?string $modelLabel      = 'Ảnh chụp POI';
    protected static ?int    $navigationSort  = 50;

    public static function canCreate(): bool { return false; }
    public static function canEdit($r): bool { return false; }

    public static function form(Form $form): Form
    {
        return $form->schema([]); // no form — read-only
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('source')
                    ->label('Nguồn')
                    ->badge()
                    ->formatStateUsing(fn ($state) => PoiSnapshot::SOURCE_LABELS[$state] ?? $state ?? '—')
                    ->color(fn (string $state) => match ($state) {
                        'osm'           => 'info',
                        'google_places' => 'success',
                        'foursquare'    => 'warning',
                        default         => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('lat_key')
                    ->label('Vĩ độ / Kinh độ')
                    ->formatStateUsing(fn ($record) => "{$record->lat_key}, {$record->lon_key}")
                    ->copyable()
                    ->copyMessage('Đã copy toạ độ')
                    ->searchable(),

                Tables\Columns\TextColumn::make('radius')
                    ->label('Bán kính')
                    ->formatStateUsing(fn ($state) => "{$state}m")
                    ->sortable(),

                Tables\Columns\TextColumn::make('poi_count')
                    ->label('POI')
                    ->numeric()
                    ->sortable()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger'),

                Tables\Columns\IconColumn::make('has_features')
                    ->label('Đặc trưng')
                    ->state(fn ($record) => ! empty($record->features))
                    ->boolean()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('has_scoring')
                    ->label('Tính điểm')
                    ->state(fn ($record) => ! empty($record->scoring))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('fetched_at')
                    ->label('Tải lúc')
                    ->dateTime()
                    ->since()
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Hết hạn')
                    ->dateTime()
                    ->since()
                    ->placeholder('không bao giờ')
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : 'gray')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('fetched_at', 'desc')
            ->filters([
                SelectFilter::make('source')
                    ->label('Nguồn')
                    ->options(PoiSnapshot::SOURCE_LABELS),
                SelectFilter::make('radius')
                    ->options([
                        '500'  => '500m',
                        '1000' => '1km',
                        '2000' => '2km',
                    ]),
                TernaryFilter::make('expired')
                    ->label('Đã hết hạn?')
                    ->placeholder('Tất cả')
                    ->trueLabel('Expired only')
                    ->falseLabel('Fresh only')
                    ->queries(
                        true:  fn (Builder $q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', now()),
                        false: fn (Builder $q) => $q->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
                    ),
                TernaryFilter::make('has_pois')
                    ->label('Có POI?')
                    ->queries(
                        true:  fn (Builder $q) => $q->where('poi_count', '>', 0),
                        false: fn (Builder $q) => $q->where('poi_count', '=', 0),
                    ),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),

                    Tables\Actions\Action::make('refresh')
                        ->label('Refresh từ OSM')
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalDescription('Re-fetch POI từ Overpass cho location này. Mất ~10-30 giây.')
                        ->action(function (PoiSnapshot $record, PoiContextEnricher $enricher) {
                            try {
                                $pois = $enricher->fetchPoisOnly(
                                    (float) $record->lat_key,
                                    (float) $record->lon_key,
                                    $record->radius,
                                );
                                Notification::make()
                                    ->title('Làm mới lúc')
                                    ->body(count($pois) . ' POIs cập nhật')
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('Làm mới không được')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('refresh_bulk')
                        ->label('Làm mới các mục đã chọn')
                        ->icon('heroicon-o-arrow-path')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalDescription('Re-fetch POI cho tất cả snapshots đã chọn. Có thể mất vài phút.')
                        ->action(function ($records) {
                            $enricher = app(PoiContextEnricher::class);
                            $ok = 0; $fail = 0;
                            foreach ($records as $r) {
                                try {
                                    $enricher->fetchPoisOnly((float) $r->lat_key, (float) $r->lon_key, $r->radius);
                                    $ok++;
                                } catch (\Throwable $e) { $fail++; }
                                sleep(2); // rate-limit safe for Overpass
                            }
                            Notification::make()
                                ->title("Refresh xong: {$ok} OK, {$fail} fail")
                                ->success()
                                ->send();
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Vị trí')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('source')
                        ->label('Nguồn')
                        ->badge()
                        ->formatStateUsing(fn ($state) => PoiSnapshot::SOURCE_LABELS[$state] ?? $state ?? '—')
                        ->color(fn (string $state) => match ($state) {
                            'osm'           => 'info',
                            'google_places' => 'success',
                            'foursquare'    => 'warning',
                            default         => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('lat_key')->label('Vĩ độ'),
                    Infolists\Components\TextEntry::make('lon_key')->label('Kinh độ'),
                    Infolists\Components\TextEntry::make('radius')
                        ->formatStateUsing(fn ($state) => "{$state}m"),
                ]),

            Infolists\Components\Section::make('Số liệu')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('poi_count')
                        ->label('POI')
                        ->numeric(),
                    Infolists\Components\TextEntry::make('fetched_at')
                        ->label('Tải lúc')
                        ->dateTime(),
                    Infolists\Components\TextEntry::make('expires_at')
                        ->label('Hết hạn')
                        ->dateTime()
                        ->placeholder('không bao giờ')
                        ->color(fn ($state) => $state && $state->isPast() ? 'danger' : 'gray'),
                ]),

            Infolists\Components\Section::make('Bản đồ và POI')
                ->schema([
                    Infolists\Components\ViewEntry::make('map_and_pois')
                        ->view('filament.resources.poi-snapshot-resource.map-and-pois'),
                ]),

            Infolists\Components\Section::make('Tính điểm (microservice)')
                ->collapsible()
                ->visible(fn ($record) => ! empty($record->scoring))
                ->schema([
                    Infolists\Components\KeyValueEntry::make('scoring')
                        ->label(false),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPoiSnapshots::route('/'),
            'view'  => Pages\ViewPoiSnapshot::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }
}
