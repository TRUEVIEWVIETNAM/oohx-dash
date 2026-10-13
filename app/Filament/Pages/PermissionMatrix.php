<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PermissionStatsWidget;
use App\Models\Owner;
use App\Models\OwnerUser;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class PermissionMatrix extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'Tổ chức';
    protected static ?string $navigationLabel = 'Quyền';
    protected static ?int    $navigationSort  = 7;
    protected static ?string $title           = 'Quản lý vai trò và quyền';

    protected static string $view = 'filament.pages.permission-matrix';

    protected function getHeaderWidgets(): array
    {
        return [
            PermissionStatsWidget::class,
        ];
    }

    public function getViewData(): array
    {
        $roles       = OwnerUser::ROLE_LABELS;
        $permissions = OwnerUser::PERMISSIONS;

        // Bảng nhãn phải phủ **mọi** khóa của `OwnerUser::PERMISSIONS`.
        //
        // View rơi về `['label' => $action, 'desc' => '']` khi thiếu nhãn, nên
        // một quyền chưa khai hiện ra bằng **mã tiếng Anh** và **không có dòng
        // mô tả** — trên đúng trang có việc giải thích quyền. Hai quyền từng
        // rơi vào đó là `manage_bookings` và `settle_refunds`, tức hai quyền có
        // hệ quả tiền: duyệt đơn đặt chỗ, và khai đã hoàn tiền cho người mua.
        //
        // `NhanQuyenOwnerPhaiDuTest` canh việc này tự tìm phạm vi: thêm một
        // quyền vào `PERMISSIONS` mà quên nhãn là ca đó đỏ.
        $permissionLabels = [
            'manage_users'     => ['label' => 'Quản lý users',     'icon' => 'heroicon-o-users',           'desc' => 'Invite, edit role, remove team members'],
            'edit_owner'       => ['label' => 'Sửa owner',         'icon' => 'heroicon-o-building-office', 'desc' => 'Chỉnh sửa thông tin Media Owner (name, billing...)'],
            'manage_inventory' => ['label' => 'Quản lý inventory', 'icon' => 'heroicon-o-squares-2x2',    'desc' => 'Thêm/sửa/xoá sites, screens, networks'],
            'manage_pricing'   => ['label' => 'Quản lý pricing',   'icon' => 'heroicon-o-currency-dollar', 'desc' => 'Sửa floor CPM, bật/tắt programmatic'],
            'view_inventory'   => ['label' => 'Xem inventory',     'icon' => 'heroicon-o-eye',             'desc' => 'Xem danh sách screens, sites (read-only)'],
            'import_inventory' => ['label' => 'Import inventory',  'icon' => 'heroicon-o-arrow-up-tray',   'desc' => 'Import bulk từ file Excel'],
            'view_reports'     => ['label' => 'Xem reports',       'icon' => 'heroicon-o-chart-bar',       'desc' => 'Xem revenue & impression reports'],
            'export_reports'   => ['label' => 'Export reports',    'icon' => 'heroicon-o-document-arrow-down', 'desc' => 'Export report CSV/Excel'],
            'view_sales'       => ['label' => 'Xem sales',        'icon' => 'heroicon-o-banknotes',       'desc' => 'Xem sales dashboard (CPM, deals)'],
            // Chữ lấy theo đúng docblock của hai quyền này trong
            // `OwnerUser::PERMISSIONS`, không đặt nghĩa mới.
            'manage_bookings'  => ['label' => 'Duyệt đặt chỗ',    'icon' => 'heroicon-o-check-badge',     'desc' => 'Duyệt / từ chối yêu cầu đặt chỗ — quyết định thương mại có hệ quả tiền'],
            'settle_refunds'   => ['label' => 'Khai đã hoàn tiền', 'icon' => 'heroicon-o-receipt-refund',  'desc' => 'Đánh dấu đã chuyển khoản hoàn cho người mua. Sàn không giữ tiền, nên đây là lời khai về một lần chuyển thật'],
        ];

        $roleStats = DB::table('owner_users')
            ->select('role', DB::raw('COUNT(*) as count'))
            ->groupBy('role')
            ->pluck('count', 'role')
            ->toArray();

        $ownerRoleStats = DB::table('owner_users')
            ->select('role', DB::raw('COUNT(DISTINCT owner_id) as owner_count'))
            ->groupBy('role')
            ->pluck('owner_count', 'role')
            ->toArray();

        $topOwners = Owner::query()
            ->withCount('users as team_size')
            ->having('team_size', '>', 0)
            ->orderByDesc('team_size')
            ->limit(10)
            ->get()
            ->map(function (Owner $owner) {
                $roleCounts = DB::table('owner_users')
                    ->where('owner_id', $owner->id)
                    ->select('role', DB::raw('COUNT(*) as count'))
                    ->groupBy('role')
                    ->pluck('count', 'role')
                    ->toArray();

                return [
                    'id'          => $owner->id,
                    'name'        => $owner->name,
                    'status'      => $owner->status,
                    'team_size'   => $owner->team_size,
                    'role_counts' => $roleCounts,
                ];
            });

        return [
            'roles'            => $roles,
            'permissions'      => $permissions,
            'permissionLabels' => $permissionLabels,
            'roleStats'        => $roleStats,
            'ownerRoleStats'   => $ownerRoleStats,
            'topOwners'        => $topOwners,
        ];
    }
}
