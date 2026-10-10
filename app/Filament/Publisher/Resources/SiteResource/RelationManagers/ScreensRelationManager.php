<?php

namespace App\Filament\Publisher\Resources\SiteResource\RelationManagers;

use App\Models\Screen;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

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

                Tables\Columns\TextColumn::make('external_id')
                    ->label('Mã màn hình')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono'),

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
                TernaryFilter::make('active')
                    ->label('Đang bật'),

                SelectFilter::make('status')
                    ->label('Trạng thái thiết bị')
                    ->options(\App\Models\Screen::STATUS_LABELS),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                Tables\Actions\Action::make('create_screen')
                    ->label('Tạo Screen')
                    ->icon('heroicon-o-plus')
                    ->url(fn () => \App\Filament\Publisher\Resources\ScreenResource::getUrl('create', [
                        'site_id' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
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
            ->emptyStateHeading('Địa điểm này chưa có màn hình nào')
            ->emptyStateIcon('heroicon-o-device-tablet');
    }
}
