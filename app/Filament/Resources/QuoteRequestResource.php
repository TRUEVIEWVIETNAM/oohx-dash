<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuoteRequestResource\Pages;
use App\Filament\Shared\Resources\BaseQuoteRequestResource;
use App\Models\QuoteRequest;
use App\Services\QuoteRequestService;
use Closure;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Support\Facades\Gate;

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

            /*
             * Xoá hẳn — chủ dự án chốt 20/10/2026, lý do ở
             * `QuoteRequestPolicy::delete()`.
             *
             * Hai lớp chắn thay cho việc đóng cứng quyền:
             *
             * 1. **Gõ lại mã.** `->requiresConfirmation()` một mình chỉ đòi
             *    một cú bấm nữa, và một cú bấm nữa là thứ người ta làm theo
             *    quán tính. Gõ `YCB-202610-0007` buộc phải nhìn lại đang xoá
             *    bản ghi nào — nó chặn đúng lỗi "xoá nhầm dòng kế bên".
             * 2. **Lý do bắt buộc**, đi vào nhật ký (CLAUDE.md mục 5).
             *
             * Hỏi lại policy trong `->action()` chứ không chỉ ở `->visible()`:
             * CLAUDE.md mục 2 — không dựa vào việc giao diện ẩn nút.
             */
            Tables\Actions\Action::make('xoa')
                ->label('Xoá')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (QuoteRequest $record): bool => auth()->user()?->can('delete', $record) ?? false)
                ->modalHeading('Xoá hẳn yêu cầu báo giá?')
                ->modalDescription(
                    'Bản ghi và toàn bộ dòng vị trí của nó bị xoá khỏi cơ sở dữ '
                    . 'liệu, kể cả liên hệ người gửi. Không khôi phục được, và '
                    . 'mọi media owner trong yêu cầu mất đầu mối này.',
                )
                ->modalSubmitActionLabel('Xoá hẳn')
                ->form(fn (QuoteRequest $record): array => [
                    Forms\Components\TextInput::make('xac_nhan_ma')
                        ->label('Gõ lại mã yêu cầu')
                        ->helperText('Phải đúng ' . $record->code)
                        ->required()
                        ->autocomplete(false)
                        ->rules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                if (! static::maKhop($value, $record)) {
                                    $fail('Mã không khớp. Gõ đúng ' . $record->code . ' để xoá.');
                                }
                            },
                        ]),

                    Forms\Components\Textarea::make('ly_do')
                        ->label('Lý do xoá')
                        ->helperText('Vào nhật ký hệ thống. Ví dụ: bản ghi thử khi kiểm tra đường gửi.')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500)
                        ->rows(2),
                ])
                ->action(function (QuoteRequest $record, array $data): void {
                    Gate::authorize('delete', $record);

                    $ma = $record->code;

                    app(QuoteRequestService::class)->xoa($record, auth()->id(), $data['ly_do']);

                    Notification::make()->title('Đã xoá yêu cầu ' . $ma)->success()->send();
                }),
        ];
    }

    /**
     * Mã người dùng gõ có khớp bản ghi đang xoá.
     *
     * Một hàm riêng để chốt gọi được: nằm trong closure validate của Filament
     * thì chỉ chạy khi có cả form, cả Livewire, cả phiên đăng nhập — và phép
     * so sánh này là thứ duy nhất đứng giữa một cú bấm theo quán tính và một
     * bản ghi mất hẳn.
     *
     * So **đúng tuyệt đối** sau khi cắt khoảng trắng: mã in hoa hết nên phân
     * biệt chữ hoa chữ thường không làm khó ai, còn nới ra thì mất luôn tác
     * dụng "phải nhìn lại đang xoá bản ghi nào".
     */
    public static function maKhop(mixed $goVao, QuoteRequest $yeuCau): bool
    {
        return trim((string) $goVao) === $yeuCau->code;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuoteRequests::route('/'),
            'view'  => Pages\ViewQuoteRequest::route('/{record}'),
        ];
    }
}
