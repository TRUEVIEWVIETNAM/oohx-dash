<?php

namespace App\Filament\Publisher\Resources\BookingInboxResource\Pages;

use App\Filament\Publisher\Resources\BookingInboxResource;
use App\Models\Campaign;
use App\Services\CampaignService;
use App\Services\TenantPermission;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewBookingInbox extends ViewRecord
{
    protected static string $resource = BookingInboxResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        $ownerId = auth()->user()->current_owner_id;

        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin Campaign')
                ->schema([
                    Infolists\Components\Grid::make(3)->schema([
                        Infolists\Components\TextEntry::make('code')->label('Mã'),
                        Infolists\Components\TextEntry::make('name')->label('Tên Campaign'),
                        Infolists\Components\TextEntry::make('organization.name')->label('Agency/Client'),
                    ]),
                    Infolists\Components\Grid::make(4)->schema([
                        Infolists\Components\TextEntry::make('brand_name')->label('Brand')->default('—'),
                        Infolists\Components\TextEntry::make('category')->label('Ngành hàng')->default('—'),
                        Infolists\Components\TextEntry::make('start_date')->label('Bắt đầu')->date('d/m/Y'),
                        Infolists\Components\TextEntry::make('end_date')->label('Kết thúc')->date('d/m/Y'),
                    ]),
                    Infolists\Components\TextEntry::make('notes')->label('Ghi chú')->default('—'),
                ]),

            // Media owner liên hệ trực tiếp người mua để trao đổi trước khi duyệt
            // (phản hồi review TMĐT, comment 7). Chỉ owner có màn hình trong booking
            // mới mở được trang này — getEloquentQuery() của resource đã chặn.
            // Việc chia sẻ được nêu trong Chính sách bảo mật mục 4.
            Infolists\Components\Section::make('Liên hệ người mua')
                ->description('Thông tin được chia sẻ theo Chính sách bảo mật để trao đổi về booking này. Không dùng cho mục đích khác.')
                ->icon('heroicon-o-user-circle')
                ->schema([
                    Infolists\Components\Grid::make(2)->schema([
                        Infolists\Components\TextEntry::make('organization.name')->label('Đơn vị'),
                        Infolists\Components\TextEntry::make('createdBy.name')->label('Người liên hệ')->default('—'),
                        Infolists\Components\TextEntry::make('createdBy.email')
                            ->label('Email người liên hệ')
                            ->default('—')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('organization.billing_phone')
                            ->label('Điện thoại')
                            ->default('—')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('organization.billing_email')
                            ->label('Email đơn vị')
                            ->default('—')
                            ->copyable(),
                    ]),
                ]),

            Infolists\Components\Section::make('Màn hình booking')
                ->description(fn (Campaign $record) => $record->bookingLines()->where('owner_id', $ownerId)->count() . ' màn hình thuộc inventory của bạn')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('bookingLines')
                        ->label('')
                        ->schema([
                            Infolists\Components\Grid::make(5)->schema([
                                Infolists\Components\TextEntry::make('screen.name')->label('Màn hình'),
                                Infolists\Components\TextEntry::make('start_date')->label('Từ')->date('d/m'),
                                Infolists\Components\TextEntry::make('end_date')->label('Đến')->date('d/m'),
                                Infolists\Components\TextEntry::make('estimated_cost')
                                    ->label('Giá trị')
                                    ->money('VND'),
                                Infolists\Components\TextEntry::make('status')
                                    ->label('Trạng thái')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'pending' => 'warning',
                                        'approved' => 'success',
                                        'rejected' => 'danger',
                                        default => 'gray',
                                    }),
                            ]),
                        ])
                        ->getStateUsing(function (Campaign $record) use ($ownerId) {
                            return $record->bookingLines()
                                ->where('owner_id', $ownerId)
                                ->with('screen')
                                ->get()
                                ->toArray();
                        }),
                ]),

            Infolists\Components\Section::make('Creatives')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('creatives')
                        ->label('')
                        ->schema([
                            Infolists\Components\Grid::make(3)->schema([
                                Infolists\Components\TextEntry::make('name')->label('Tên'),
                                Infolists\Components\TextEntry::make('type')->label('Loại')->badge(),
                                Infolists\Components\TextEntry::make('status')
                                    ->label('Trạng thái')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'approved' => 'success',
                                        'rejected' => 'danger',
                                        default => 'warning',
                                    }),
                            ]),
                        ]),
                ])
                ->collapsible(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $ownerId = auth()->user()->current_owner_id;
        $hasPending = $this->record->bookingLines()
            ->where('owner_id', $ownerId)
            ->where('status', 'pending')
            ->exists();

        if (! $hasPending) return [];

        // Giao diện khớp với quyền. Việc CHẶN thật nằm trong CampaignService —
        // ẩn nút chỉ là phép lịch sự với người dùng, không phải cơ chế bảo vệ.
        if (! TenantPermission::for(auth()->user(), $ownerId)->can('manage_bookings')) {
            return [];
        }

        return [
            Actions\Action::make('approveAll')
                ->label('Duyệt tất cả')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Duyệt tất cả màn hình?')
                ->modalDescription('Tất cả màn hình pending thuộc inventory của bạn sẽ được duyệt.')
                ->action(function () use ($ownerId) {
                    $service = app(CampaignService::class);
                    $count = $service->approveAllForOwner($this->record, $ownerId, auth()->user());

                    Notification::make()
                        ->title("Đã duyệt $count màn hình")
                        ->success()
                        ->send();
                }),

            Actions\Action::make('rejectAll')
                ->label('Từ chối tất cả')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Từ chối tất cả màn hình?')
                ->form([
                    \Filament\Forms\Components\Textarea::make('reason')
                        ->label('Lý do từ chối')
                        ->required()
                        ->placeholder('VD: Không còn slot trống trong thời gian này'),
                ])
                ->action(function (array $data) use ($ownerId) {
                    $service = app(CampaignService::class);
                    $count = $service->rejectAllForOwner($this->record, $ownerId, $data['reason'], auth()->user());

                    Notification::make()
                        ->title("Đã từ chối $count màn hình")
                        ->warning()
                        ->send();
                }),
        ];
    }
}
