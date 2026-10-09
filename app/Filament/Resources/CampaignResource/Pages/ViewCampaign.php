<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\Payment;
use App\Services\Booking\CancellationService;
use App\Services\PaymentService;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ViewCampaign extends ViewRecord
{
    protected static string $resource = CampaignResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin Campaign')
                ->schema([
                    Infolists\Components\Grid::make(3)->schema([
                        Infolists\Components\TextEntry::make('code')->label('Mã'),
                        Infolists\Components\TextEntry::make('name')->label('Tên'),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Trạng thái')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                Campaign::STATUS_DRAFT => 'gray',
                                Campaign::STATUS_PENDING => 'warning',
                                Campaign::STATUS_APPROVED => 'info',
                                Campaign::STATUS_ACTIVE => 'success',
                                Campaign::STATUS_PAUSED => 'warning',
                                Campaign::STATUS_REJECTED,
                                Campaign::STATUS_CANCELLED => 'danger',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn (string $state): string => Campaign::STATUS_LABELS[$state] ?? $state),
                    ]),
                    Infolists\Components\Grid::make(3)->schema([
                        Infolists\Components\TextEntry::make('organization.name')->label('Tổ chức'),
                        Infolists\Components\TextEntry::make('brand_name')->label('Brand')->default('—'),
                        Infolists\Components\TextEntry::make('category')->label('Ngành hàng')->default('—'),
                    ]),
                    Infolists\Components\Grid::make(4)->schema([
                        Infolists\Components\TextEntry::make('start_date')->label('Bắt đầu')->date('d/m/Y'),
                        Infolists\Components\TextEntry::make('end_date')->label('Kết thúc')->date('d/m/Y'),
                        Infolists\Components\TextEntry::make('total_screens')->label('Màn hình'),
                        Infolists\Components\TextEntry::make('total_budget')->label('Budget')->money('VND')->default('—'),
                    ]),
                    Infolists\Components\TextEntry::make('notes')->label('Ghi chú')->default('—')->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Booking Lines')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('bookingLines')
                        ->label('')
                        ->schema([
                            Infolists\Components\Grid::make(6)->schema([
                                Infolists\Components\TextEntry::make('screen.name')->label('Màn hình'),
                                Infolists\Components\TextEntry::make('owner.name')->label('Owner'),
                                Infolists\Components\TextEntry::make('start_date')->label('Từ')->date('d/m'),
                                Infolists\Components\TextEntry::make('end_date')->label('Đến')->date('d/m'),
                                Infolists\Components\TextEntry::make('estimated_cost')->label('Chi phí')->money('VND'),
                                // `BookingLine::`, KHÔNG `Campaign::` — dòng đặt
                                // chỗ dùng `pending`, chiến dịch dùng
                                // `pending_approval`. Dùng một bảng cho cả hai
                                // là đúng lỗi đã làm dòng `pending` hiện ra
                                // nguyên văn tiếng Anh (xem PR #41).
                                Infolists\Components\TextEntry::make('status')->label('Trạng thái')->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        BookingLine::STATUS_PENDING => 'warning',
                                        BookingLine::STATUS_APPROVED => 'info',
                                        BookingLine::STATUS_ACTIVE => 'success',
                                        BookingLine::STATUS_PAUSED => 'warning',
                                        BookingLine::STATUS_REJECTED,
                                        BookingLine::STATUS_CANCELLED => 'danger',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn (string $state): string => BookingLine::STATUS_LABELS[$state] ?? $state),
                            ]),
                        ]),
                ])
                ->collapsible(),

            Infolists\Components\Section::make('Payments')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('payments')
                        ->label('')
                        ->schema([
                            Infolists\Components\Grid::make(5)->schema([
                                Infolists\Components\TextEntry::make('invoice_number')->label('Invoice'),
                                Infolists\Components\TextEntry::make('amount')->label('Số tiền')->money('VND'),
                                Infolists\Components\TextEntry::make('method')->label('Phương thức')
                                    ->formatStateUsing(fn (string $state): string => Payment::METHOD_LABELS[$state] ?? $state),
                                Infolists\Components\TextEntry::make('status')->label('Trạng thái')->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        Payment::STATUS_PENDING,
                                        Payment::STATUS_PROCESSING => 'warning',
                                        Payment::STATUS_COMPLETED => 'success',
                                        Payment::STATUS_FAILED => 'danger',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn (string $state): string => Payment::STATUS_LABELS[$state] ?? $state),
                                Infolists\Components\TextEntry::make('paid_at')->label('Thanh toán lúc')->dateTime('d/m/Y H:i')->default('—'),
                            ]),
                        ]),
                ])
                ->collapsible(),

            // Hiện nghĩa vụ hoàn tiền ngay tại chiến dịch phát sinh nó. Để
            // riêng ở danh sách hoàn tiền thì khi có khiếu nại phải mở hai
            // trang mới ghép được câu chuyện.
            Infolists\Components\Section::make('Hoàn tiền')
                ->visible(fn (Campaign $record): bool => $record->refunds()->exists())
                ->schema([
                    Infolists\Components\RepeatableEntry::make('refunds')
                        ->label('')
                        ->schema([
                            Infolists\Components\Grid::make(5)->schema([
                                Infolists\Components\TextEntry::make('bookingLine.screen.name')->label('Màn hình')->default('—'),
                                Infolists\Components\TextEntry::make('owner.name')->label('Owner')->default('—'),
                                Infolists\Components\TextEntry::make('refund_pct')->label('Tỉ lệ')->suffix('%'),
                                Infolists\Components\TextEntry::make('amount')->label('Phải hoàn')->money('VND'),
                                Infolists\Components\TextEntry::make('status')->label('Trạng thái')->badge()
                                    ->formatStateUsing(fn (string $state): string => match ($state) {
                                        'pending' => 'Chờ hoàn', 'settled' => 'Đã hoàn',
                                        'waived' => 'Không hoàn', default => $state,
                                    })
                                    ->color(fn (string $state): string => match ($state) {
                                        'pending' => 'warning', 'settled' => 'success', default => 'gray',
                                    }),
                            ]),
                            Infolists\Components\TextEntry::make('reason')->label('Lý do')->default('—')->columnSpanFull(),
                        ]),
                ])
                ->collapsible(),

            Infolists\Components\Section::make('Lịch sử hoạt động')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('activities')
                        ->label('')
                        ->schema([
                            Infolists\Components\Grid::make(3)->schema([
                                Infolists\Components\TextEntry::make('description')->label('Hoạt động'),
                                Infolists\Components\TextEntry::make('user.name')->label('Người thực hiện')->default('Hệ thống'),
                                Infolists\Components\TextEntry::make('created_at')->label('Thời gian')->dateTime('d/m/Y H:i'),
                            ]),
                        ]),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('confirmPayment')
                ->label('Xác nhận thanh toán')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn () => $this->record->payments()->where('status', 'pending')->exists())
                ->form([
                    Forms\Components\TextInput::make('gateway_ref')
                        ->label('Mã giao dịch ngân hàng')
                        ->placeholder('VD: VCB-123456789'),
                ])
                ->requiresConfirmation()
                ->modalHeading('Xác nhận đã nhận thanh toán?')
                ->action(function (array $data) {
                    $payment = $this->record->payments()->where('status', 'pending')->latest()->first();
                    if ($payment) {
                        app(PaymentService::class)->confirmBankTransfer($payment, $data['gateway_ref'] ?? null);
                        Notification::make()->title('Thanh toán đã xác nhận')->success()->send();
                    }
                }),

            // Hủy đặt chỗ từ phía sàn — cho những ca người mua không tự làm
            // được (mất tài khoản, yêu cầu qua điện thoại, tranh chấp).
            //
            // Nhãn của mỗi lựa chọn mang sẵn số tiền hoàn **do máy chủ tính**,
            // nên người bấm thấy hệ quả trước khi bấm chứ không sau.
            Actions\Action::make('cancelLine')
                ->label('Hủy một màn hình')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->cancellableLines()->isNotEmpty())
                ->form([
                    Forms\Components\Select::make('booking_line_id')
                        ->label('Màn hình cần hủy')
                        ->options(fn () => $this->cancellableLineOptions())
                        ->required()
                        ->searchable(),
                    Forms\Components\Textarea::make('reason')
                        ->label('Lý do hủy')
                        ->required()
                        ->maxLength(500)
                        ->placeholder('VD: Người mua yêu cầu hủy qua điện thoại ngày 30/09'),
                ])
                ->requiresConfirmation()
                ->modalHeading('Hủy đặt chỗ trên màn hình này?')
                ->modalDescription('Suất sẽ được nhả về kho ngay và hệ thống ghi nghĩa vụ hoàn tiền theo chính sách hủy.')
                ->action(function (array $data) {
                    $line = $this->cancellableLines()->firstWhere('id', $data['booking_line_id']);

                    // Đọc lại từ tập dòng còn hủy được, không tin `booking_line_id`
                    // gửi lên: form Filament vẫn là dữ liệu từ trình duyệt.
                    if (! $line) {
                        Notification::make()->title('Dòng đặt chỗ không còn hủy được.')->danger()->send();

                        return;
                    }

                    try {
                        $refund = app(CancellationService::class)->cancelLine($line, auth()->user(), $data['reason']);
                    } catch (HttpException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    $amount = (int) round((float) $refund->amount);

                    Notification::make()
                        ->title($amount > 0
                            ? 'Đã hủy. Nghĩa vụ hoàn ' . number_format($amount, 0, ',', '.') . ' ₫ (' . $refund->refund_pct . '%)'
                            : 'Đã hủy. Theo chính sách, lần hủy này không được hoàn tiền.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /** Các dòng còn hủy được của chiến dịch này. */
    private function cancellableLines(): Collection
    {
        return $this->record->bookingLines()
            ->whereNotIn('status', ['cancelled', 'completed', 'rejected'])
            ->with(['screen:id,name', 'campaign'])
            ->get();
    }

    /** @return array<string, string> */
    private function cancellableLineOptions(): array
    {
        $cancellations = app(CancellationService::class);

        return $this->cancellableLines()
            ->mapWithKeys(function ($line) use ($cancellations) {
                $quote = $cancellations->quote($line);

                return [$line->id => sprintf(
                    '%s · %s → %s · hoàn dự kiến %s ₫ (%d%%, còn %d ngày)',
                    $line->screen?->name ?? $line->screen_id,
                    $line->start_date->format('d/m'),
                    $line->end_date->format('d/m'),
                    number_format($quote['refundable'], 0, ',', '.'),
                    $quote['refund_pct'],
                    $quote['days_before'],
                )];
            })
            ->all();
    }
}
