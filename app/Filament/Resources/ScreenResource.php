<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScreenResource\Pages;
use App\Filament\Shared\Resources\BaseScreenResource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;

class ScreenResource extends BaseScreenResource
{
    // Admin xem tất cả owners, không scope theo tenant
    protected static ?int $navigationSort = 2;

    // ── Admin hiển thị field Owner trong form ────────────────────────────────

    protected static function showOwnerField(): bool
    {
        return true;
    }

    // ── Phân quyền: admin luôn có toàn quyền ─────────────────────────────────

    public static function canViewAny(): bool  { return true; }
    public static function canCreate(): bool   { return true; }
    public static function canEdit($r): bool   { return true; }
    public static function canDelete($r): bool { return true; }

    // ── Owner column trong table ──────────────────────────────────────────────

    protected static function additionalTableColumns(): array
    {
        return [];
    }

    // ── Owner filter trong table ──────────────────────────────────────────────

    protected static function additionalFilters(): array
    {
        return [
            SelectFilter::make('owner')
                ->label('Media owner')
                ->relationship('owner', 'name')
                ->searchable(),
        ];
    }

    // ── Pages ─────────────────────────────────────────────────────────────────

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListScreen::route('/'),
            'create' => Pages\CreateScreen::route('/create'),
            'edit'   => Pages\EditScreen::route('/{record}/edit'),
            'view'   => Pages\ViewScreen::route('/{record}'),
        ];
    }
}
