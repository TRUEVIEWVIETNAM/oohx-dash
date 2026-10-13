<?php

namespace App\Filament\Publisher\Resources;

use App\Filament\Publisher\Resources\NetworkResource\Pages;
use App\Filament\Publisher\Resources\NetworkResource\RelationManagers\ScreensRelationManager;
use App\Filament\Shared\Resources\BaseNetworkResource;
use App\Services\GioiHanNetwork;
use App\Services\TenantPermission;
use Illuminate\Database\Eloquent\Builder;

class NetworkResource extends BaseNetworkResource
{
    // ── Phân quyền theo tenant ────────────────────────────────────────────────

    public static function canViewAny(): bool  { return TenantPermission::check('view_inventory'); }
    public static function canEdit($r): bool   { return TenantPermission::check('manage_inventory'); }
    public static function canDelete($r): bool { return TenantPermission::check('manage_inventory'); }

    /**
     * Thêm điều kiện hàng rào mạng lưới — luật ở `NetworkPolicy::create()`.
     *
     * Một thành viên bị giới hạn mà tạo mạng lưới mới thì mạng lưới đó không
     * nằm trong tập được giao, nên ngay sau khi lưu chính anh ta không còn
     * thấy nó. Nút "Tạo mới" ở đây phải tắt để không mời người ta làm một việc
     * tự biến mất.
     */
    public static function canCreate(): bool
    {
        return TenantPermission::check('manage_inventory')
            && ! GioiHanNetwork::dangBiGioiHan();
    }

    // ── Multi-tenant scoping ──────────────────────────────────────────────────

    public static function getEloquentQuery(): Builder
    {
        $ownerId = auth()->user()?->current_owner_id;

        return parent::getEloquentQuery()
            ->when($ownerId, fn($q) => $q->where('owner_id', $ownerId));
    }

    // ── Publisher có trang View riêng ─────────────────────────────────────────

    protected static function hasViewPage(): bool
    {
        return true;
    }

    // ── Relations ─────────────────────────────────────────────────────────────

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
            'index'  => Pages\ListNetwork::route('/'),
            'create' => Pages\CreateNetwork::route('/create'),
            'edit'   => Pages\EditNetwork::route('/{record}/edit'),
            'view'   => Pages\ViewNetwork::route('/{record}'),
        ];
    }
}
