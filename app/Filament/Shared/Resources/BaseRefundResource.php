<?php

namespace App\Filament\Shared\Resources;

use App\Models\Refund;
use App\Services\Booking\CancellationService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Nghĩa vụ hoàn tiền — lớp base dùng chung cho `/admin` và `/publisher`.
 *
 * Sàn không giữ tiền: người mua chuyển thẳng cho media owner, nên bản ghi này
 * là **nghĩa vụ**, không phải giao dịch. "Đã hoàn" là lời khai của owner về một
 * lần chuyển khoản thật ở ngoài hệ thống.
 *
 * Hai điều cố ý:
 *
 * - **Không có form tạo và form sửa.** Khoản hoàn tiền chỉ sinh ra từ đường
 *   hủy đặt chỗ, với số tiền do `CancellationService` tính theo chính sách.
 *   Cho sửa `amount` bằng tay là mở đúng cái cửa mà "số tiền do máy chủ tính"
 *   (CLAUDE.md mục 5) đóng lại.
 * - Chỉ có một hành động: `settle`. Việc chặn thật nằm trong
 *   `CancellationService::settle()`, hàm này tự từ chối khoản không ở trạng
 *   thái chờ — ẩn nút chỉ là phép lịch sự với người dùng.
 */
abstract class BaseRefundResource extends Resource
{
    protected static ?string $model           = Refund::class;
    protected static ?string $navigationIcon  = 'heroicon-o-receipt-refund';
    protected static ?string $navigationLabel = 'Hoàn tiền';

    // ── Hooks cho subclass ────────────────────────────────────────────────────

    /** Admin thêm cột owner và tổ chức mua; publisher không cần vì đã scope. */
    protected static function additionalTableColumns(): array
    {
        return [];
    }

    /** Admin thêm filter theo owner. */
    protected static function additionalFilters(): array
    {
        return [];
    }

    /**
     * Panel con quyết định ai được khai "đã hoàn".
     *
     * Mặc định **đóng**: một panel mới quên override thì không mở toang, đúng
     * nguyên tắc của `HasOwnerScope`.
     */
    protected static function canSettleRecord(Refund $refund): bool
    {
        return false;
    }

    // ── Table ────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns(array_merge([
                Tables\Columns\TextColumn::make('campaign.code')
                    ->label('Chiến dịch')
                    ->searchable()
                    ->description(fn (Refund $r): string => $r->campaign?->name ?? ''),

                Tables\Columns\TextColumn::make('bookingLine.screen.name')
                    ->label('Màn hình')
                    ->searchable()
                    ->default('—'),
            ], static::additionalTableColumns(), [
                Tables\Columns\TextColumn::make('paid_amount')
                    ->label('Đã trả')
                    ->money('VND')
                    ->tooltip('Phần tiền người mua đã trả được phân bổ cho dòng này'),

                Tables\Columns\TextColumn::make('refund_pct')
                    ->label('Tỉ lệ')
                    ->suffix('%')
                    ->description(fn (Refund $r): string => 'còn ' . $r->days_before_start . ' ngày'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Phải hoàn')
                    ->money('VND')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Refund::STATUS_PENDING => 'Chờ hoàn',
                        Refund::STATUS_SETTLED => 'Đã hoàn',
                        Refund::STATUS_WAIVED  => 'Không hoàn',
                        default                => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Refund::STATUS_PENDING => 'warning',
                        Refund::STATUS_SETTLED => 'success',
                        default                => 'gray',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Hủy lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('settled_at')
                    ->label('Hoàn lúc')
                    ->dateTime('d/m/Y H:i')
                    ->default('—')
                    ->toggleable(),
            ]))
            ->filters(array_merge([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        Refund::STATUS_PENDING => 'Chờ hoàn',
                        Refund::STATUS_SETTLED => 'Đã hoàn',
                        Refund::STATUS_WAIVED  => 'Không hoàn',
                    ]),
            ], static::additionalFilters()))
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('settle')
                    ->label('Đánh dấu đã hoàn')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Refund $record): bool => $record->status === Refund::STATUS_PENDING
                        && static::canSettleRecord($record))
                    ->requiresConfirmation()
                    ->modalHeading('Đã chuyển tiền hoàn cho người mua?')
                    ->modalDescription(fn (Refund $record): string => sprintf(
                        'Xác nhận đã hoàn %s ₫ cho chiến dịch %s. Hành động này được ghi vào lịch sử chiến dịch kèm tên người thực hiện.',
                        number_format((float) $record->amount, 0, ',', '.'),
                        $record->campaign?->code ?? '—',
                    ))
                    ->action(function (Refund $record): void {
                        try {
                            app(CancellationService::class)->settle($record, auth()->user());
                        } catch (HttpException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Đã ghi nhận hoàn tiền')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Chưa có khoản hoàn tiền nào')
            ->emptyStateDescription('Khoản hoàn tiền sinh ra khi một dòng đặt chỗ bị hủy.');
    }

    // Khoản hoàn tiền không được tạo hay sửa bằng tay — xem docblock của lớp.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
