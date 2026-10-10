<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SiteResource\Pages;
use App\Filament\Resources\SiteResource\RelationManagers\ScreensRelationManager;
use App\Filament\Shared\Resources\BaseSiteResource;
use Filament\Forms;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;

class SiteResource extends BaseSiteResource
{
    // Admin xem tất cả owners, không scope theo tenant
    protected static ?int $navigationSort = 2;

    // ── Phân quyền: admin luôn có toàn quyền ─────────────────────────────────

    public static function canViewAny(): bool  { return true; }
    public static function canView($r): bool   { return true; }
    public static function canCreate(): bool   { return true; }
    public static function canEdit($r): bool   { return true; }
    public static function canDelete($r): bool { return true; }

    // getEloquentQuery() không override → admin thấy toàn bộ sites

    // ── Owner field trong form ────────────────────────────────────────────────

    protected static function ownerFormField(): Forms\Components\Component
    {
        return Forms\Components\Select::make('owner_id')
            ->label('Media owner')
            ->relationship('owner', 'name')
            ->searchable()
            ->preload()
            ->required();
    }

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

    // ── Relation managers ─────────────────────────────────────────────────────

    public static function getRelations(): array
    {
        return [
            ScreensRelationManager::class,
        ];
    }

    // ── Pages ─────────────────────────────────────────────────────────────────

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSites::route('/'),
            'create' => Pages\CreateSite::route('/create'),
            'edit'   => Pages\EditSite::route('/{record}/edit'),
            'view'   => Pages\ViewSite::route('/{record}'),
        ];
    }
}
