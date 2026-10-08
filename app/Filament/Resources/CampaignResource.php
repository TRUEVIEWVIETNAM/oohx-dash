<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use App\Models\Campaign;
use App\Models\Payment;
use App\Services\PaymentService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?string $navigationLabel = 'Campaigns';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $count = Payment::where('status', 'pending')->count();
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
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Campaign')
                    ->searchable()
                    ->limit(35),

                Tables\Columns\TextColumn::make('organization.name')
                    ->label('Tổ chức')
                    ->limit(25),

                Tables\Columns\TextColumn::make('total_screens')
                    ->label('Screens')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'pending_approval' => 'warning',
                        'approved' => 'info',
                        'active' => 'success',
                        'rejected' => 'danger',
                        'completed' => 'gray',
                        default => 'gray',
                    })
                    // Cột này **chưa từng** có nhãn chữ, nên bảng quản trị hiện
                    // `pending_approval` nguyên văn tiếng Anh trong khi cùng
                    // trạng thái đó ở hộp thư media owner hiện "Chờ duyệt".
                    // Cùng bảng chữ cho cả hai cửa.
                    ->formatStateUsing(fn (string $state): string => Campaign::STATUS_LABELS[$state] ?? $state),

                Tables\Columns\TextColumn::make('pending_payments_count')
                    ->label('Payment')
                    ->getStateUsing(fn (Campaign $r) => $r->payments()->where('status', 'pending')->count())
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray')
                    ->suffix(fn ($state) => $state > 0 ? ' chờ' : '')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                // Cả tám trạng thái: bảng này không lọc trạng thái nào ở tầng
                // truy vấn, nên mọi trạng thái đều với tới được. Bản cũ chỉ
                // liệt kê năm, tức quản trị sàn **không lọc được** `draft`,
                // `paused` và `cancelled` — ba trạng thái mà người cần tìm một
                // chiến dịch kẹt sẽ tìm trước.
                Tables\Filters\SelectFilter::make('status')
                    ->options(Campaign::statusLabels()),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\Action::make('confirmPayment')
                        ->label('Xác nhận thanh toán')
                        ->icon('heroicon-o-banknotes')
                        ->color('success')
                        ->visible(fn (Campaign $r) => $r->payments()->where('status', 'pending')->exists())
                        ->form([
                            Forms\Components\TextInput::make('gateway_ref')
                                ->label('Mã giao dịch ngân hàng')
                                ->placeholder('VD: VCB-123456789'),
                        ])
                        ->requiresConfirmation()
                        ->modalHeading('Xác nhận đã nhận thanh toán?')
                        ->action(function (Campaign $record, array $data) {
                            $payment = $record->payments()->where('status', 'pending')->latest()->first();
                            if ($payment) {
                                app(PaymentService::class)->confirmBankTransfer($payment, $data['gateway_ref'] ?? null);
                                Notification::make()->title('Thanh toán đã xác nhận')->success()->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'view'  => Pages\ViewCampaign::route('/{record}'),
        ];
    }
}
