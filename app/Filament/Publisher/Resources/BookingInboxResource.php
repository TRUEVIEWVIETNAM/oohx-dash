<?php

namespace App\Filament\Publisher\Resources;

use App\Filament\Publisher\Resources\BookingInboxResource\Pages;
use App\Models\Campaign;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingInboxResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationGroup = 'Bookings';

    protected static ?string $navigationLabel = 'Booking Inbox';

    protected static ?string $modelLabel = 'Booking';

    protected static ?string $pluralModelLabel = 'Bookings';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $ownerId = auth()->user()?->current_owner_id;
        if (! $ownerId) return null;

        $count = Campaign::where('status', 'pending_approval')
            ->whereHas('bookingLines', fn ($q) => $q->where('owner_id', $ownerId)->where('status', 'pending'))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->size('sm'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Campaign')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label('Agency/Client')
                    ->limit(30),

                Tables\Columns\TextColumn::make('owner_lines_count')
                    ->label('Màn hình')
                    ->getStateUsing(function (Campaign $record) {
                        $ownerId = auth()->user()?->current_owner_id;
                        return $record->bookingLines()->where('owner_id', $ownerId)->count();
                    })
                    ->suffix(' screens')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('owner_estimated_cost')
                    ->label('Giá trị')
                    ->getStateUsing(function (Campaign $record) {
                        $ownerId = auth()->user()?->current_owner_id;
                        return $record->bookingLines()->where('owner_id', $ownerId)->sum('estimated_cost');
                    })
                    ->money('VND')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('start_date')
                    ->label('Bắt đầu')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending_approval' => 'warning',
                        'approved' => 'info',
                        'active' => 'success',
                        'rejected' => 'danger',
                        'completed' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'draft' => 'Nháp',
                        'pending_approval' => 'Chờ duyệt',
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                        'active' => 'Đang chạy',
                        'completed' => 'Hoàn thành',
                        'cancelled' => 'Đã hủy',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Gửi lúc')
                    ->dateTime('d/m H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending_approval' => 'Chờ duyệt',
                        'approved' => 'Đã duyệt',
                        'active' => 'Đang chạy',
                        'rejected' => 'Từ chối',
                        'completed' => 'Hoàn thành',
                    ]),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->poll('30s');
    }

    public static function getEloquentQuery(): Builder
    {
        $ownerId = auth()->user()?->current_owner_id;

        return parent::getEloquentQuery()
            ->with(['organization', 'createdBy'])
            ->whereHas('bookingLines', fn ($q) => $q->where('owner_id', $ownerId))
            ->whereIn('status', [
                'pending_approval', 'approved', 'rejected',
                'active', 'paused', 'completed',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBookingInbox::route('/'),
            'view'  => Pages\ViewBookingInbox::route('/{record}'),
        ];
    }
}
