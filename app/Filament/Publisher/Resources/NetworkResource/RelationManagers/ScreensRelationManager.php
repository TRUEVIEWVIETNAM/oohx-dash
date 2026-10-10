<?php

namespace App\Filament\Publisher\Resources\NetworkResource\RelationManagers;

use App\Models\Screen;
use App\Models\Site;
use App\Models\VietnamCommune;
use App\Models\VietnamProvince;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScreensRelationManager extends RelationManager
{
    protected static string $relationship = 'screens';

    protected static ?string $title = 'Màn hình';

    public function table(Table $table): Table
    {
        return $table
            ->heading(null)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tên màn hình')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('site.name')
                    ->label('Địa điểm')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('site.province.name')
                    ->label('Tỉnh/thành')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('site.commune.full_name')
                    ->label('Quận/huyện')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('active')
                    ->label('Đang bật')
                    ->boolean(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Thiết bị')
                    ->colors([
                        'success' => 'online',
                        'danger'  => 'offline',
                        'warning' => 'maintenance',
                    ])
                    ->formatStateUsing(fn($state) => ucfirst($state ?? 'unknown')),
            ])
            ->filters([
                SelectFilter::make('province_id')
                    ->label('Tỉnh/thành')
                    ->options(fn() => VietnamProvince::toSelectOptions())
                    ->query(fn(Builder $query, array $data) =>
                        $data['value']
                            ? $query->whereHas('site', fn($q) => $q->where('province_id', $data['value']))
                            : $query
                    )
                    ->searchable(),

                SelectFilter::make('commune_id')
                    ->label('Quận/huyện')
                    ->options(fn() => VietnamCommune::orderBy('full_name')->pluck('full_name', 'id')->toArray())
                    ->query(fn(Builder $query, array $data) =>
                        $data['value']
                            ? $query->whereHas('site', fn($q) => $q->where('commune_id', $data['value']))
                            : $query
                    )
                    ->searchable(),

                TernaryFilter::make('active')
                    ->label('Đang bật'),

                SelectFilter::make('status')
                    ->label('Trạng thái thiết bị')
                    ->options(\App\Models\Screen::STATUS_LABELS),
            ])
            ->filtersFormColumns(3)
            ->headerActions([])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('view')
                        ->label('Xem')
                        ->icon('heroicon-o-eye')
                        ->url(fn(Screen $record) =>
                            \App\Filament\Publisher\Resources\ScreenResource::getUrl('view', ['record' => $record])
                        ),
                    Tables\Actions\Action::make('edit')
                        ->label('Sửa')
                        ->icon('heroicon-o-pencil')
                        ->url(fn(Screen $record) =>
                            \App\Filament\Publisher\Resources\ScreenResource::getUrl('edit', ['record' => $record])
                        ),
                ]),
            ])
            ->emptyStateHeading('Mạng lưới này chưa có màn hình nào')
            ->emptyStateIcon('heroicon-o-device-tablet');
    }
}
