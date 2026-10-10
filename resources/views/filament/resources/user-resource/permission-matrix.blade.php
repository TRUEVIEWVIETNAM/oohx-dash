@php
    $user = $getRecord();
    $ownerUsers = $user->ownerUsers()->with('owner')->get();
    $permissions = \App\Models\OwnerUser::PERMISSIONS;
    $permissionLabels = [
        'manage_users'     => 'Quản lý users',
        'edit_owner'       => 'Sửa owner',
        'manage_inventory' => 'Quản lý inventory',
        'manage_pricing'   => 'Quản lý pricing',
        'view_inventory'   => 'Xem inventory',
        'import_inventory' => 'Import inventory',
        'view_reports'     => 'Xem reports',
        'export_reports'   => 'Export reports',
        'view_sales'       => 'Xem sales',
    ];
@endphp

@if($ownerUsers->isEmpty())
    <div class="text-sm text-gray-500 dark:text-gray-400 italic">
        User chưa thuộc owner nào.
    </div>
@else
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="text-left py-2 pr-4 font-medium text-gray-600 dark:text-gray-400">Quyền</th>
                    @foreach($ownerUsers as $ou)
                        <th class="text-center py-2 px-3 font-medium text-gray-600 dark:text-gray-400">
                            <div>{{ $ou->owner?->name ?? '?' }}</div>
                            <div class="text-xs font-normal text-gray-400">{{ \App\Models\OwnerUser::ROLE_LABELS[$ou->role] ?? $ou->role }}</div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($permissions as $action => $allowedRoles)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="py-1.5 pr-4 text-gray-700 dark:text-gray-300">
                            {{ $permissionLabels[$action] ?? $action }}
                        </td>
                        @foreach($ownerUsers as $ou)
                            <td class="text-center py-1.5 px-3">
                                @if(in_array($ou->role, $allowedRoles))
                                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-success-100 dark:bg-success-500/20 text-success-600 dark:text-success-400">
                                        <x-heroicon-m-check class="w-3.5 h-3.5" />
                                    </span>
                                @else
                                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-400 dark:text-gray-600">
                                        <x-heroicon-m-minus class="w-3 h-3" />
                                    </span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
