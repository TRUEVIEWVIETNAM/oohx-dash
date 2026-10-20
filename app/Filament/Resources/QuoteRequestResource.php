<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuoteRequestResource\Pages;
use App\Filament\Shared\Resources\BaseQuoteRequestResource;
use App\Models\QuoteRequest;
use App\Services\QuoteRequestService;
use Filament\Notifications\Notification;
use Filament\Tables;

/**
 * Yêu cầu báo giá ở `/admin`.
 *
 * Quản trị sàn thấy mọi yêu cầu kèm liên hệ người mua, và là bên **duy nhất**
 * duyệt được. Duyệt là hành động mở dữ liệu cá nhân sang bên thứ ba (media
 * owner), nên nó không thể nằm ở tay bên hưởng lợi từ việc mở.
 */
class QuoteRequestResource extends BaseQuoteRequestResource
{
    protected static ?string $navigationGroup = 'Đặt chỗ';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $so = QuoteRequest::where('status', QuoteRequest::STATUS_PENDING)->count();

        return $so > 0 ? (string) $so : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', QuoteRequest::class) ?? false;
    }

    protected static function xemDuocLienHe(QuoteRequest $yeuCau): bool
    {
        return auth()->user()?->can('xemDuocLienHe', $yeuCau) ?? false;
    }

    protected static function cotThem(): array
    {
        return [
            Tables\Columns\TextColumn::make('so_owner')
                ->label('Media owner')
                ->getStateUsing(fn (QuoteRequest $r): int => $r->items()->distinct('owner_id')->count('owner_id'))
                ->alignCenter()
                ->tooltip('Số media owner phải trả lời yêu cầu này'),

            Tables\Columns\TextColumn::make('contact_company')
                ->label('Đơn vị')
                ->searchable()
                ->default('—'),
        ];
    }

    protected static function hanhDongThem(): array
    {
        return [
            Tables\Actions\Action::make('duyet')
                ->label('Duyệt')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (QuoteRequest $record): bool => ! $record->lienHeDaMo()
                    && (auth()->user()?->can('duyet', $record) ?? false))
                ->requiresConfirmation()
                ->modalHeading('Mở liên hệ người mua cho media owner?')
                ->modalDescription(
                    'Sau khi duyệt, mọi media owner có vị trí trong yêu cầu này sẽ '
                    . 'thấy tên, email và số điện thoại người gửi. Việc mở không '
                    . 'rút lại được.',
                )
                ->action(function (QuoteRequest $record): void {
                    app(QuoteRequestService::class)->duyet($record, auth()->id());

                    Notification::make()->title('Đã duyệt — owner thấy liên hệ')->success()->send();
                }),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuoteRequests::route('/'),
            'view'  => Pages\ViewQuoteRequest::route('/{record}'),
        ];
    }
}
