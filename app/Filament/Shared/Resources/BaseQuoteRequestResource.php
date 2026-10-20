<?php

namespace App\Filament\Shared\Resources;

use App\Models\QuoteRequest;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Yêu cầu báo giá — lớp base dùng chung cho `/admin` và `/publisher` (lát 5).
 *
 * ══ Khác biệt giữa hai panel chỉ có hai thứ ══
 *
 * 1. **Phạm vi**: admin thấy mọi yêu cầu; publisher chỉ thấy yêu cầu có vị
 *    trí của owner đang chọn, và chỉ những dòng vị trí của owner đó.
 * 2. **Liên hệ người mua**: admin luôn thấy; publisher chỉ thấy **sau khi
 *    quản trị duyệt** (chủ dự án chốt 19/10/2026).
 *
 * Cả hai đi qua `QuoteRequestPolicy`, không qua một phép kiểm viết lại ở đây
 * — CLAUDE.md mục 4. `static::xemDuocLienHe()` dưới đây chỉ là chỗ hỏi lại.
 *
 * ══ Không có form tạo, không có form sửa ══
 *
 * Yêu cầu sinh ra từ đúng một đường: `POST /api/v2/quote-requests`. Một nút
 * "Tạo" trong Filament tạo ra bản ghi không có người gửi thật, và chúng nằm
 * lẫn với bản ghi thật trong mọi báo cáo về sau.
 */
abstract class BaseQuoteRequestResource extends Resource
{
    protected static ?string $model = QuoteRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Yêu cầu báo giá';

    protected static ?string $modelLabel = 'Yêu cầu báo giá';

    protected static ?string $pluralModelLabel = 'Yêu cầu báo giá';

    // ── Hooks cho panel con ──────────────────────────────────────────────────

    /**
     * Panel con quyết định ai thấy liên hệ người mua.
     *
     * Mặc định **đóng**: một panel mới quên override thì không mở toang, cùng
     * nguyên tắc với `HasOwnerScope` (chặn mặc định khi không xác định được
     * tenant).
     */
    protected static function xemDuocLienHe(QuoteRequest $yeuCau): bool
    {
        return false;
    }

    /** Cột riêng của từng panel (admin thêm số owner, publisher thì không). */
    protected static function cotThem(): array
    {
        return [];
    }

    /** Hành động riêng của từng panel (admin có nút duyệt). */
    protected static function hanhDongThem(): array
    {
        return [];
    }

    // ── Bảng ─────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns(array_merge([
                Tables\Columns\TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Gửi lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Vị trí')
                    ->counts('items')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('campaign_start')
                    ->label('Cửa sổ chiến dịch')
                    ->date('d/m/Y')
                    ->description(fn (QuoteRequest $r): string => $r->campaign_end
                        ? 'đến ' . $r->campaign_end->format('d/m/Y')
                        : '')
                    ->default('—'),

                Tables\Columns\TextColumn::make('budget_vnd')
                    ->label('Ngân sách')
                    ->money('VND')
                    ->default('—')
                    ->toggleable(),
            ], static::cotThem(), [
                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => QuoteRequest::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        QuoteRequest::STATUS_PENDING  => 'warning',
                        QuoteRequest::STATUS_APPROVED => 'success',
                        QuoteRequest::STATUS_REJECTED => 'danger',
                        default                       => 'gray',
                    }),
            ]))
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(QuoteRequest::STATUS_LABELS),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions(array_merge([
                Tables\Actions\ViewAction::make()->label('Xem'),
            ], static::hanhDongThem()))
            ->emptyStateHeading('Chưa có yêu cầu báo giá nào')
            ->emptyStateDescription('Yêu cầu đến từ nút "Yêu cầu báo giá" trên trang khám phá kho.');
    }

    // ── Chi tiết ─────────────────────────────────────────────────────────────

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Yêu cầu')
                ->schema([
                    Infolists\Components\TextEntry::make('code')->label('Mã'),
                    Infolists\Components\TextEntry::make('created_at')->label('Gửi lúc')->dateTime('d/m/Y H:i'),
                    Infolists\Components\TextEntry::make('campaign_start')->label('Từ ngày')->date('d/m/Y')->default('—'),
                    Infolists\Components\TextEntry::make('campaign_end')->label('Đến ngày')->date('d/m/Y')->default('—'),
                    Infolists\Components\TextEntry::make('budget_vnd')->label('Ngân sách dự kiến')->money('VND')->default('—'),
                    Infolists\Components\TextEntry::make('notes')->label('Ghi chú')->columnSpanFull()->default('—'),
                ])
                ->columns(2),

            /*
             * Khối liên hệ hoặc hiện đủ, hoặc **không hiện gì** — không có
             * trạng thái "hiện tên nhưng che email". Một nửa liên hệ vẫn là
             * liên hệ: tên công ty cộng ngành hàng là đủ để tìm ra số điện
             * thoại ở chỗ khác.
             */
            Infolists\Components\Section::make('Người gửi')
                ->schema([
                    Infolists\Components\TextEntry::make('contact_name')->label('Họ tên'),
                    Infolists\Components\TextEntry::make('contact_email')->label('Email')->copyable(),
                    Infolists\Components\TextEntry::make('contact_phone')->label('Điện thoại')->default('—')->copyable(),
                    Infolists\Components\TextEntry::make('contact_company')->label('Đơn vị')->default('—'),
                ])
                ->columns(2)
                ->visible(fn (QuoteRequest $record): bool => static::xemDuocLienHe($record)),

            Infolists\Components\Section::make('Liên hệ người mua chưa mở')
                ->schema([
                    Infolists\Components\TextEntry::make('trang_thai_lien_he')
                        ->hiddenLabel()
                        ->state('Quản trị sàn chưa duyệt yêu cầu này. Sau khi duyệt, '
                            . 'bạn sẽ thấy tên, email và số điện thoại người gửi tại đây.'),
                ])
                ->visible(fn (QuoteRequest $record): bool => ! static::xemDuocLienHe($record)),

            Infolists\Components\Section::make('Vị trí được hỏi')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\TextEntry::make('screen_name')->label('Màn hình'),
                            Infolists\Components\TextEntry::make('city')->label('Thành phố')->default('—'),
                            Infolists\Components\TextEntry::make('screen_slug')->label('Slug')->copyable(),
                        ])
                        ->columns(3),
                ]),
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
