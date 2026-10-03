<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CreativeResource\Pages;
use App\Models\Creative;
use App\Services\Booking\CreativeGate;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CreativeResource extends Resource
{
    protected static ?string $model = Creative::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?string $navigationLabel = 'Creatives';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = Creative::where('status', 'pending_review')->count();
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
                // Đọc `file_url` (accessor sinh URL ký hạn qua route có kiểm
                // policy), KHÔNG đọc `file_path` trên disk `public`.
                //
                // `->disk('public')` cũ là thứ giữ nội dung quảng cáo trên disk
                // công khai: đổi chỗ lưu mà quên cột này thì bảng duyệt hiện
                // toàn ảnh vỡ, nên hai thứ phải đi cùng nhau.
                Tables\Columns\ImageColumn::make('file_url')
                    ->label('Preview')
                    ->width(60)
                    ->height(45)
                    ->defaultImageUrl('https://placehold.co/60x45/F5F5F7/6E6E73?text=—'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Tên')
                    ->searchable()
                    ->limit(35),

                Tables\Columns\TextColumn::make('campaign.code')
                    ->label('Campaign')
                    ->sortable()
                    ->url(fn (Creative $r) => CampaignResource::getUrl('view', ['record' => $r->campaign_id])),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label('Tổ chức')
                    ->limit(25),

                Tables\Columns\TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'image' => 'info',
                        'video' => 'success',
                        'html5' => 'warning',
                        'vast_tag' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('dimensions')
                    ->label('Kích thước')
                    ->getStateUsing(fn (Creative $r) => $r->dimensions),

                Tables\Columns\TextColumn::make('file_size')
                    ->label('Dung lượng')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 1024) . ' KB' : '—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending_review' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending_review' => 'Chờ duyệt',
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Upload')
                    ->dateTime('d/m H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending_review' => 'Chờ duyệt',
                        'approved' => 'Đã duyệt',
                        'rejected' => 'Từ chối',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'image' => 'Image',
                        'video' => 'Video',
                        'html5' => 'HTML5',
                        'vast_tag' => 'VAST Tag',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('approve')
                        ->label('Duyệt')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Creative $r) => $r->status === 'pending_review')
                        ->requiresConfirmation()
                        ->action(function (Creative $record) {
                            // Đi qua CreativeGate chứ không update thẳng: duyệt
                            // còn kéo theo gắn nội dung vào dòng đặt chỗ và ghi
                            // nhật ký. Hai đường duyệt song song thì sớm muộn
                            // một đường quên mất phần việc của đường kia.
                            app(CreativeGate::class)->approve($record, auth()->id());
                            Notification::make()->title('Creative đã duyệt')->success()->send();
                        }),

                    Tables\Actions\Action::make('reject')
                        ->label('Từ chối')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Creative $r) => $r->status === 'pending_review')
                        ->requiresConfirmation()
                        ->modalHeading('Từ chối creative?')
                        ->action(function (Creative $record) {
                            app(CreativeGate::class)->reject($record, auth()->id());
                            Notification::make()->title('Creative đã từ chối')->warning()->send();
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('bulkApprove')
                    ->label('Duyệt tất cả')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        $gate = app(CreativeGate::class);
                        $records->each(fn ($r) => $gate->approve($r, auth()->id()));
                        Notification::make()->title($records->count() . ' creatives đã duyệt')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCreatives::route('/'),
        ];
    }
}
