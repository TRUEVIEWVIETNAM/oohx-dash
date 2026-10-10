<x-filament-panels::page>

    {{-- ── Tenant Permission Matrix ──────────────────────────────────────────── --}}
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-shield-check class="w-5 h-5 text-primary-500" />
                Ma trận quyền theo tenant
            </div>
        </x-slot>
        <x-slot name="description">
            Ma trận phân quyền trong mỗi Media Owner. Mỗi user khi thuộc 1 Owner sẽ được gán 1 trong 6 roles bên dưới.
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="border-b-2 border-gray-200 dark:border-gray-700">
                        <th class="text-left py-3 pr-4 font-semibold text-gray-700 dark:text-gray-200 min-w-[200px]">
                            Quyền
                        </th>
                        @foreach($roles as $roleKey => $roleLabel)
                            <th class="text-center py-3 px-2 font-semibold text-gray-700 dark:text-gray-200 min-w-[100px]">
                                <div>{{ $roleLabel }}</div>
                                <div class="text-xs font-normal text-gray-400 dark:text-gray-500 mt-0.5">
                                    {{ $roleStats[$roleKey] ?? 0 }} người dùng
                                </div>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($permissions as $action => $allowedRoles)
                        @php $meta = $permissionLabels[$action] ?? ['label' => $action, 'icon' => 'heroicon-o-key', 'desc' => '']; @endphp
                        <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900/50 transition-colors">
                            <td class="py-2.5 pr-4">
                                <div class="flex items-center gap-2">
                                    <x-dynamic-component :component="$meta['icon']" class="w-4 h-4 text-gray-400 dark:text-gray-500 shrink-0" />
                                    <div>
                                        <div class="font-medium text-gray-700 dark:text-gray-300">{{ $meta['label'] }}</div>
                                        <div class="text-xs text-gray-400 dark:text-gray-500">{{ $meta['desc'] }}</div>
                                    </div>
                                </div>
                            </td>
                            @foreach($roles as $roleKey => $roleLabel)
                                <td class="text-center py-2.5 px-2">
                                    @if(in_array($roleKey, $allowedRoles))
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-success-50 dark:bg-success-500/10">
                                            <x-heroicon-s-check class="w-4 h-4 text-success-600 dark:text-success-400" />
                                        </span>
                                    @else
                                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-gray-50 dark:bg-gray-800">
                                            <x-heroicon-s-minus class="w-3.5 h-3.5 text-gray-300 dark:text-gray-600" />
                                        </span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- ── Tenant Role Distribution ──────────────────────────────────────────── --}}
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-user-group class="w-5 h-5 text-primary-500" />
                Phân bố vai trò theo tenant
            </div>
        </x-slot>
        <x-slot name="description">
            Phân bố roles của users trong hệ thống tenant (Media Owners).
        </x-slot>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach($roles as $roleKey => $roleLabel)
                @php
                    $count = $roleStats[$roleKey] ?? 0;
                    $ownerCount = $ownerRoleStats[$roleKey] ?? 0;
                    $colorClass = match($roleKey) {
                        'owner'          => 'text-danger-600 dark:text-danger-400 bg-danger-50 dark:bg-danger-500/10 ring-danger-200 dark:ring-danger-500/30',
                        'manager'        => 'text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-primary-500/10 ring-primary-200 dark:ring-primary-500/30',
                        'operator'       => 'text-warning-600 dark:text-warning-400 bg-warning-50 dark:bg-warning-500/10 ring-warning-200 dark:ring-warning-500/30',
                        'read_only'      => 'text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-500/10 ring-gray-200 dark:ring-gray-500/30',
                        'reporting_only' => 'text-info-600 dark:text-info-400 bg-info-50 dark:bg-info-500/10 ring-info-200 dark:ring-info-500/30',
                        'sales_manager'  => 'text-success-600 dark:text-success-400 bg-success-50 dark:bg-success-500/10 ring-success-200 dark:ring-success-500/30',
                        default          => 'text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-gray-500/10 ring-gray-200 dark:ring-gray-500/30',
                    };
                @endphp
                <div class="rounded-xl p-4 ring-1 {{ $colorClass }}">
                    <div class="text-2xl font-bold">{{ $count }}</div>
                    <div class="text-sm font-medium mt-1">{{ $roleLabel }}</div>
                    <div class="text-xs opacity-70 mt-0.5">{{ $ownerCount }} owner(s)</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- ── Top Owners by Team Size ───────────────────────────────────────────── --}}
    @if($topOwners->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-building-office-2 class="w-5 h-5 text-primary-500" />
                    Media owner có nhóm lớn nhất
                </div>
            </x-slot>
            <x-slot name="description">
                Media Owners có nhiều team members nhất.
            </x-slot>

            <div class="overflow-x-auto">
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="border-b-2 border-gray-200 dark:border-gray-700">
                            <th class="text-left py-2.5 pr-4 font-semibold text-gray-700 dark:text-gray-200">Chủ sở hữu</th>
                            <th class="text-center py-2.5 px-2 font-semibold text-gray-700 dark:text-gray-200">Trạng thái</th>
                            <th class="text-center py-2.5 px-2 font-semibold text-gray-700 dark:text-gray-200">Nhóm</th>
                            @foreach($roles as $roleKey => $roleLabel)
                                <th class="text-center py-2.5 px-2 font-semibold text-gray-700 dark:text-gray-200 text-xs">{{ $roleLabel }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($topOwners as $owner)
                            <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900/50 transition-colors">
                                <td class="py-2 pr-4">
                                    <a href="{{ \App\Filament\Resources\OwnerResource::getUrl('edit', ['record' => $owner['id']]) }}"
                                       class="font-medium text-primary-600 dark:text-primary-400 hover:underline">
                                        {{ $owner['name'] }}
                                    </a>
                                </td>
                                <td class="text-center py-2 px-2">
                                    @php
                                        $statusColor = match($owner['status']) {
                                            'active'    => 'success',
                                            'pending'   => 'warning',
                                            'suspended' => 'danger',
                                            default     => 'gray',
                                        };
                                    @endphp
                                    <x-filament::badge :color="$statusColor">
                                        {{ $owner['status'] }}
                                    </x-filament::badge>
                                </td>
                                <td class="text-center py-2 px-2 font-semibold">
                                    {{ $owner['team_size'] }}
                                </td>
                                @foreach($roles as $roleKey => $roleLabel)
                                    <td class="text-center py-2 px-2 text-gray-500 dark:text-gray-400">
                                        {{ $owner['role_counts'][$roleKey] ?? '—' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    {{-- ── System Roles Info ─────────────────────────────────────────────────── --}}
    <x-filament::section collapsible collapsed>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-information-circle class="w-5 h-5 text-primary-500" />
                System Roles (Spatie)
            </div>
        </x-slot>
        <x-slot name="description">
            Roles cấp hệ thống — xác định panel nào user được truy cập.
        </x-slot>

        <div class="space-y-4">
            <div class="overflow-x-auto">
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="border-b-2 border-gray-200 dark:border-gray-700">
                            <th class="text-left py-2.5 pr-4 font-semibold text-gray-700 dark:text-gray-200">Vai trò</th>
                            <th class="text-left py-2.5 pr-4 font-semibold text-gray-700 dark:text-gray-200">Panel</th>
                            <th class="text-left py-2.5 pr-4 font-semibold text-gray-700 dark:text-gray-200">Mô tả</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4">
                                <x-filament::badge color="danger">super_admin</x-filament::badge>
                            </td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">/admin</td>
                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                Full access to admin panel. Bypass tất cả tenant permission checks.
                            </td>
                        </tr>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-4">
                                <x-filament::badge color="primary">publisher</x-filament::badge>
                            </td>
                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">/publisher</td>
                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                Truy cập publisher panel. Quyền cụ thể phụ thuộc vào tenant role trong mỗi Owner.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex gap-2">
                <x-filament::button
                    tag="a"
                    href="{{ \App\Filament\Resources\RoleResource::getUrl('index') }}"
                    color="gray"
                    size="sm"
                    icon="heroicon-o-cog-6-tooth"
                >
                    Quản lý System Roles
                </x-filament::button>

                <x-filament::button
                    tag="a"
                    href="{{ \App\Filament\Resources\UserResource::getUrl('index') }}"
                    color="gray"
                    size="sm"
                    icon="heroicon-o-users"
                >
                    Quản lý Users
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>

</x-filament-panels::page>
