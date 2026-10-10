<x-filament-panels::page>

{{-- ══════════════════════════════════════════════════════════════════════════
     STEP INDICATOR
══════════════════════════════════════════════════════════════════════════ --}}
<div class="mb-6">
    <ol class="flex items-center gap-0">
        @foreach([
            [1, 'heroicon-o-arrow-up-tray',   'Upload File'],
            [2, 'heroicon-o-eye',              'Preview'],
            [3, 'heroicon-o-arrow-path',       'Importing'],
            [4, 'heroicon-o-check-badge',      'Kết quả'],
        ] as [$n, $icon, $label])
        @php
            $done    = $step > $n;
            $current = $step === $n;
        @endphp
        <li class="flex flex-1 items-center {{ $n < 4 ? 'after:flex-1 after:h-0.5 after:mx-2 after:content-[\'\'] after:bg-gray-200 dark:after:bg-gray-700' : '' }}
                   {{ ($done || $current) && $n < 4 ? 'after:!bg-primary-400' : '' }}">
            <div class="flex flex-col items-center gap-1.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-full border-2 transition-all
                    {{ $done    ? 'border-primary-500 bg-primary-500 text-white' : '' }}
                    {{ $current ? 'border-primary-500 bg-white text-primary-600 dark:bg-gray-900' : '' }}
                    {{ !$done && !$current ? 'border-gray-300 bg-white text-gray-400 dark:bg-gray-800 dark:border-gray-600' : '' }}">
                    @if($done)
                        <x-heroicon-s-check class="h-4 w-4"/>
                    @else
                        @svg($icon, 'h-4 w-4')
                    @endif
                </span>
                <span class="text-xs font-medium whitespace-nowrap
                    {{ $current ? 'text-primary-600 dark:text-primary-400' : '' }}
                    {{ $done    ? 'text-primary-500' : '' }}
                    {{ !$done && !$current ? 'text-gray-400' : '' }}">
                    {{ $label }}
                </span>
            </div>
        </li>
        @endforeach
    </ol>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     STEP 1 — UPLOAD FILE
══════════════════════════════════════════════════════════════════════════ --}}
@if($step === 1)
<x-filament::section>
    <x-slot name="heading">
        <span class="flex items-center gap-2">
            <x-heroicon-o-arrow-up-tray class="h-5 w-5 text-primary-500"/>
            Upload file danh sách Site &amp; Screen
        </span>
    </x-slot>
    <x-slot name="description">
        File chưa được lưu vào database ở bước này — bạn sẽ xem trước trước khi xác nhận.
    </x-slot>

    {{-- Format guide --}}
    <div class="mb-5 space-y-4">
        {{-- Sheet 1: Sites --}}
        <div class="rounded-xl border border-primary-200 bg-primary-50 p-4 dark:border-primary-800 dark:bg-primary-950/40">
            <h4 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-primary-700 dark:text-primary-400">
                <x-heroicon-s-map-pin class="h-4 w-4"/> Sheet: <code class="ml-1 rounded bg-primary-100 px-1 dark:bg-primary-900">Địa điểm</code>
                <span class="ml-auto text-xs font-normal text-primary-500">Row 5+ là dữ liệu</span>
            </h4>
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-primary-800 dark:text-primary-300">
                    <thead>
                        <tr class="border-b border-primary-200 dark:border-primary-700">
                            <th class="pb-1.5 pr-4 text-left font-semibold">Cột</th>
                            <th class="pb-1.5 pr-4 text-left font-semibold">Tiêu đề cột</th>
                            <th class="pb-1.5 text-left font-semibold">Trường</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-primary-100 dark:divide-primary-800">
                        @foreach([
                            ['A', 'Mã site *',   'external_id <span class="rounded bg-danger-100 px-1 text-[10px] font-bold text-danger-600">bắt buộc</span>'],
                            ['B', 'Tên site *',  'name <span class="rounded bg-danger-100 px-1 text-[10px] font-bold text-danger-600">bắt buộc</span>'],
                            ['C', 'Địa chỉ',     'address'],
                            ['D', 'Thành phố',   'city'],
                            ['E', 'Latitude',    'lat'],
                            ['F', 'Longitude',   'lon'],
                            ['G', 'Mô tả',       'description'],
                        ] as [$col, $header, $db])
                        <tr>
                            <td class="py-1 pr-4 font-mono font-bold">{{ $col }}</td>
                            <td class="py-1 pr-4">{{ $header }}</td>
                            <td class="py-1 font-mono text-[11px]">{!! $db !!}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Sheet 2: Screens --}}
        <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-950/40">
            <h4 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-indigo-700 dark:text-indigo-400">
                <x-heroicon-s-computer-desktop class="h-4 w-4"/> Sheet: <code class="ml-1 rounded bg-indigo-100 px-1 dark:bg-indigo-900">Màn hình</code>
                <span class="ml-auto text-xs font-normal text-indigo-500">Row 5+ là dữ liệu</span>
            </h4>
            <div class="overflow-x-auto">
                <table class="w-full text-xs text-indigo-800 dark:text-indigo-300">
                    <thead>
                        <tr class="border-b border-indigo-200 dark:border-indigo-700">
                            <th class="pb-1.5 pr-4 text-left font-semibold">Cột</th>
                            <th class="pb-1.5 pr-4 text-left font-semibold">Tiêu đề cột</th>
                            <th class="pb-1.5 text-left font-semibold">Trường</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-indigo-100 dark:divide-indigo-800">
                        @foreach([
                            ['A', 'Mã screen *',       'external_id <span class="rounded bg-danger-100 px-1 text-[10px] font-bold text-danger-600">bắt buộc</span>'],
                            ['B', 'Tên screen *',      'name <span class="rounded bg-danger-100 px-1 text-[10px] font-bold text-danger-600">bắt buộc</span>'],
                            ['C', 'Mã site *',         'site_external_id <span class="rounded bg-danger-100 px-1 text-[10px] font-bold text-danger-600">bắt buộc</span>'],
                            ['D', 'Loại biển',          'vn_category_id (dropdown 12 loại VN)'],
                            ['E', 'Network',            'network — tạo mới nếu chưa có'],
                            ['F', 'Rộng (px) *',       'spec.width_px <span class="rounded bg-danger-100 px-1 text-[10px] font-bold text-danger-600">bắt buộc</span>'],
                            ['G', 'Cao (px) *',        'spec.height_px <span class="rounded bg-danger-100 px-1 text-[10px] font-bold text-danger-600">bắt buộc</span>'],
                            ['H-I', 'Rộng/Cao (cm)',   'spec.width_cm / height_cm'],
                            ['J', 'Giá (VND/tháng)',   'inventory.floor_cpm'],
                            ['K', 'Lượt xem/tuần',    'inventory.weekly_impressions'],
                            ['L', 'Thời lượng QC',     'inventory.spot_length (giây)'],
                            ['M-N', 'Giờ mở/đóng',    'inventory.operating_hours'],
                        ] as [$col, $header, $db])
                        <tr>
                            <td class="py-1 pr-4 font-mono font-bold">{{ $col }}</td>
                            <td class="py-1 pr-4">{{ $header }}</td>
                            <td class="py-1 font-mono text-[11px]">{!! $db !!}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <form wire:submit="uploadAndPreview">
        {{ $this->form }}
        <div class="mt-5 flex items-center gap-4">
            <x-filament::button
                type="submit"
                icon="heroicon-o-magnifying-glass"
                wire:loading.attr="disabled"
                wire:target="uploadAndPreview">
                <span wire:loading.remove wire:target="uploadAndPreview">Preview trước khi Import</span>
                <span wire:loading wire:target="uploadAndPreview">Đang phân tích file...</span>
            </x-filament::button>
            <p class="text-sm text-gray-400">Tối đa 20MB · Chỉ file .xlsx</p>
        </div>
    </form>
</x-filament::section>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     STEP 2 — PREVIEW
══════════════════════════════════════════════════════════════════════════ --}}
@if($step === 2)
@php
    $s              = $this->getSummary();
    $sitesOk        = ($s['sites_new'] ?? 0) + ($s['sites_update'] ?? 0);
    $screensOk      = ($s['screens_new'] ?? 0) + ($s['screens_update'] ?? 0);
    $hasErr         = ($s['sites_error'] ?? 0) > 0 || ($s['screens_error'] ?? 0) > 0;
    $hasScreens     = !empty($preview['screens']);
    $confirmMsg     = $hasErr
        ? (($s['sites_error'] + $s['screens_error'])) . ' dòng lỗi sẽ bị bỏ qua. Import ' . $sitesOk . ' sites + ' . $screensOk . ' screens?'
        : 'Import ' . ($s['sites_new'] ?? 0) . ' sites mới, ' . ($s['sites_update'] ?? 0) . ' cập nhật, ' . ($s['screens_new'] ?? 0) . ' screens mới, ' . ($s['screens_update'] ?? 0) . ' cập nhật?';
@endphp

{{-- Summary bar --}}
<div class="mb-4 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800">
    <div class="flex flex-wrap items-center justify-between gap-4">
        {{-- Stats inline --}}
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
            {{-- Sites --}}
            <div class="flex items-center gap-2">
                <x-heroicon-s-map-pin class="h-4 w-4 text-primary-500"/>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Địa điểm:</span>
                <span class="rounded-full bg-success-100 px-2 py-0.5 text-xs font-bold text-success-700 dark:bg-success-900/40 dark:text-success-400">{{ $s['sites_new'] ?? 0 }} mới</span>
                <span class="rounded-full bg-warning-100 px-2 py-0.5 text-xs font-bold text-warning-700 dark:bg-warning-900/40 dark:text-warning-400">{{ $s['sites_update'] ?? 0 }} cập nhật</span>
                @if(($s['sites_error'] ?? 0) > 0)
                <span class="rounded-full bg-danger-100 px-2 py-0.5 text-xs font-bold text-danger-700 dark:bg-danger-900/40 dark:text-danger-400">{{ $s['sites_error'] }} lỗi</span>
                @endif
            </div>
            @if($hasScreens)
            <div class="hidden sm:block h-5 w-px bg-gray-200 dark:bg-gray-700"></div>
            {{-- Screens --}}
            <div class="flex items-center gap-2">
                <x-heroicon-s-computer-desktop class="h-4 w-4 text-indigo-500"/>
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Màn hình:</span>
                <span class="rounded-full bg-success-100 px-2 py-0.5 text-xs font-bold text-success-700 dark:bg-success-900/40 dark:text-success-400">{{ $s['screens_new'] ?? 0 }} mới</span>
                <span class="rounded-full bg-warning-100 px-2 py-0.5 text-xs font-bold text-warning-700 dark:bg-warning-900/40 dark:text-warning-400">{{ $s['screens_update'] ?? 0 }} cập nhật</span>
                @if(($s['screens_error'] ?? 0) > 0)
                <span class="rounded-full bg-danger-100 px-2 py-0.5 text-xs font-bold text-danger-700 dark:bg-danger-900/40 dark:text-danger-400">{{ $s['screens_error'] }} lỗi</span>
                @endif
            </div>
            @endif
        </div>
        {{-- Actions --}}
        <div class="flex shrink-0 gap-2">
            <x-filament::button color="gray" size="sm" wire:click="backToUpload" icon="heroicon-o-arrow-left">
                Đổi file
            </x-filament::button>
            {{ $this->confirmImportAction }}
        </div>
    </div>
</div>

{{-- Error banner --}}
@if($hasErr)
<div class="mb-3 flex items-center gap-2 rounded-lg border border-danger-200 bg-danger-50 px-4 py-2.5 text-sm text-danger-700 dark:border-danger-800 dark:bg-danger-950/30 dark:text-danger-400">
    <x-heroicon-s-exclamation-triangle class="h-4 w-4 shrink-0 text-danger-500"/>
    @if(($s['sites_error'] ?? 0) > 0)<strong>{{ $s['sites_error'] }} site lỗi</strong>@endif
    @if(($s['sites_error'] ?? 0) > 0 && ($s['screens_error'] ?? 0) > 0) · @endif
    @if(($s['screens_error'] ?? 0) > 0)<strong>{{ $s['screens_error'] }} screen lỗi</strong>@endif
    — sẽ bị bỏ qua khi import.
</div>
@endif

{{-- ── SITES SECTION ──────────────────────────────────────────────────────── --}}
<div class="mb-2 flex items-center justify-between">
    <div class="flex items-center gap-2">
        <x-heroicon-o-map-pin class="h-5 w-5 text-primary-500"/>
        <h3 class="text-base font-semibold text-gray-800 dark:text-white">Địa điểm</h3>
    </div>
    {{-- Inline filter pills --}}
    <div class="flex gap-1">
        @foreach([
            ['all',    ($s['sites_total'] ?? 0), 'Tất cả',  'gray'],
            ['new',    ($s['sites_new']   ?? 0), 'Mới',     'success'],
            ['update', ($s['sites_update']?? 0), 'Update',  'warning'],
            ['error',  ($s['sites_error'] ?? 0), 'Lỗi',     'danger'],
        ] as [$action, $count, $label, $color])
        @if($count > 0 || $action === 'all')
        <button wire:click="setFilter('{{ $action }}')"
            class="rounded-full px-2.5 py-1 text-xs font-semibold transition-all
            {{ $filterAction === $action
                ? match($color) {
                    'success' => 'bg-success-500 text-white',
                    'warning' => 'bg-warning-500 text-white',
                    'danger'  => 'bg-danger-500 text-white',
                    default   => 'bg-gray-800 text-white dark:bg-gray-200 dark:text-gray-800',
                }
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-400'
            }}">
            {{ $label }} ({{ $count }})
        </button>
        @endif
        @endforeach
    </div>
</div>

{{-- Sites table --}}
<x-filament::section class="mb-6">
    <div class="-mx-1 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-xs dark:border-gray-700 dark:bg-gray-800">
                <tr>
                    <th class="w-12 px-3 py-2.5 text-left font-semibold text-gray-500">Dòng</th>
                    <th class="w-24 px-3 py-2.5 text-left font-semibold text-gray-500">Trạng thái</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Site ID (generated) / Chain ID</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Tên địa điểm</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-gray-500">GPS</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Thay đổi / Lỗi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($this->getFilteredSites() as $site)
                @php
                    [$rowBg, $badge, $badgeLabel] = match($site['action']) {
                        'new'    => ['bg-success-50/50 dark:bg-success-950/20', 'bg-success-100 text-success-700 ring-success-200 dark:bg-success-900/40 dark:text-success-300 dark:ring-success-800', '✦ Mới'],
                        'update' => ['bg-warning-50/50 dark:bg-warning-950/20', 'bg-warning-100 text-warning-700 ring-warning-200 dark:bg-warning-900/40 dark:text-warning-300 dark:ring-warning-800', '↻ Update'],
                        'error'  => ['bg-danger-50/50 dark:bg-danger-950/20',   'bg-danger-100  text-danger-700  ring-danger-200  dark:bg-danger-900/40  dark:text-danger-300  dark:ring-danger-800',  '✕ Lỗi'],
                        default  => ['', 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-gray-700 dark:text-gray-400', $site['action']],
                    };
                @endphp
                <tr class="{{ $rowBg }} transition-colors">
                    <td class="px-3 py-2 text-xs tabular-nums text-gray-400">{{ $site['row'] }}</td>
                    <td class="px-3 py-2">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $badge }}">
                            {{ $badgeLabel }}
                        </span>
                    </td>
                    <td class="max-w-[220px] px-3 py-2">
                        {{-- Generated external_id (từ Site Name) --}}
                        <span class="block truncate font-mono text-xs text-primary-700 dark:text-primary-400"
                              title="{{ $site['external_id'] }}">
                            {{ $site['external_id'] ?: '—' }}
                        </span>
                        @if(!empty($site['duplicate_in_file']))
                            <span class="mt-0.5 inline-flex items-center rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold text-orange-600 dark:bg-orange-900/40 dark:text-orange-400">
                                ⊕ TRÙNG
                            </span>
                        @endif
                    </td>
                    <td class="max-w-[220px] px-3 py-2 font-medium text-gray-800 dark:text-gray-200">
                        <span class="block truncate" title="{{ $site['name'] }}">{{ $site['name'] ?: '—' }}</span>
                    </td>
                    <td class="px-3 py-2 font-mono text-xs text-gray-500 whitespace-nowrap">
                        @if($site['lat'] !== null && $site['lon'] !== null)
                            {{ number_format((float)$site['lat'], 5) }},
                            {{ number_format((float)$site['lon'], 5) }}
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="max-w-[260px] px-3 py-2">
                        @if($site['action'] === 'error')
                            <span class="text-xs leading-relaxed text-danger-600 dark:text-danger-400">{{ $site['error'] }}</span>
                        @elseif(!empty($site['changes']))
                            <ul class="space-y-0.5 text-xs text-warning-700 dark:text-warning-400">
                                @foreach($site['changes'] as $change)
                                    <li class="flex items-start gap-1"><span class="mt-0.5 shrink-0 text-warning-400">•</span>{{ $change }}</li>
                                @endforeach
                            </ul>
                        @else
                            <span class="text-xs italic text-gray-400">Không có thay đổi</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-sm italic text-gray-400">
                        Không có site nào khớp bộ lọc "{{ $filterAction }}"
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($this->getSiteTotal() > $perPage)
    <div class="mt-3 flex items-center justify-between px-1 text-sm text-gray-500">
        <span>Trang {{ $sitePage }} / {{ ceil($this->getSiteTotal() / $perPage) }} · {{ number_format($this->getSiteTotal()) }} bản ghi</span>
        <div class="flex gap-2">
            @if($sitePage > 1)
                <x-filament::button size="sm" color="gray" wire:click="$set('sitePage', {{ $sitePage - 1 }})">← Trước</x-filament::button>
            @endif
            @if($this->hasNextPage())
                <x-filament::button size="sm" color="gray" wire:click="$set('sitePage', {{ $sitePage + 1 }})">Sau →</x-filament::button>
            @endif
        </div>
    </div>
    @endif
</x-filament::section>

{{-- ── SCREENS SECTION ────────────────────────────────────────────────────── --}}
@if($hasScreens)
<div class="mb-2 flex items-center justify-between">
    <div class="flex items-center gap-2">
        <x-heroicon-o-computer-desktop class="h-5 w-5 text-indigo-500"/>
        <h3 class="text-base font-semibold text-gray-800 dark:text-white">Màn hình</h3>
    </div>
    <div class="flex gap-1">
        @foreach([
            ['all',    ($s['screens_total'] ?? 0), 'Tất cả',  'gray'],
            ['new',    ($s['screens_new']   ?? 0), 'Mới',     'success'],
            ['update', ($s['screens_update']?? 0), 'Update',  'warning'],
            ['error',  ($s['screens_error'] ?? 0), 'Lỗi',     'danger'],
        ] as [$action, $count, $label, $color])
        @if($count > 0 || $action === 'all')
        <button wire:click="setScreenFilter('{{ $action }}')"
            class="rounded-full px-2.5 py-1 text-xs font-semibold transition-all
            {{ $screenFilterAction === $action
                ? match($color) {
                    'success' => 'bg-success-500 text-white',
                    'warning' => 'bg-warning-500 text-white',
                    'danger'  => 'bg-danger-500 text-white',
                    default   => 'bg-gray-800 text-white dark:bg-gray-200 dark:text-gray-800',
                }
                : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-400'
            }}">
            {{ $label }} ({{ $count }})
        </button>
        @endif
        @endforeach
    </div>
</div>

{{-- Screens table --}}
<x-filament::section class="mb-6">
    <div class="-mx-1 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-xs dark:border-gray-700 dark:bg-gray-800">
                <tr>
                    <th class="w-12 px-3 py-2.5 text-left font-semibold text-gray-500">Dòng</th>
                    <th class="w-24 px-3 py-2.5 text-left font-semibold text-gray-500">Trạng thái</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Mã màn hình</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Tên màn hình</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Mã địa điểm</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Mạng lưới</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Độ phân giải</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Loại địa điểm</th>
                    <th class="px-3 py-2.5 text-left font-semibold text-gray-500">Thay đổi / Lỗi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse($this->getFilteredScreens() as $screen)
                @php
                    [$rowBg, $badge, $badgeLabel] = match($screen['action']) {
                        'new'    => ['bg-success-50/50 dark:bg-success-950/20', 'bg-success-100 text-success-700 ring-success-200 dark:bg-success-900/40 dark:text-success-300 dark:ring-success-800', '✦ Mới'],
                        'update' => ['bg-warning-50/50 dark:bg-warning-950/20', 'bg-warning-100 text-warning-700 ring-warning-200 dark:bg-warning-900/40 dark:text-warning-300 dark:ring-warning-800', '↻ Update'],
                        'error'  => ['bg-danger-50/50 dark:bg-danger-950/20',   'bg-danger-100  text-danger-700  ring-danger-200  dark:bg-danger-900/40  dark:text-danger-300  dark:ring-danger-800',  '✕ Lỗi'],
                        default  => ['', 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-gray-700 dark:text-gray-400', $screen['action']],
                    };
                @endphp
                <tr class="{{ $rowBg }} transition-colors">
                    <td class="px-3 py-2 text-xs tabular-nums text-gray-400">{{ $screen['row'] }}</td>
                    <td class="px-3 py-2">
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $badge }}">
                            {{ $badgeLabel }}
                        </span>
                    </td>
                    <td class="max-w-[180px] px-3 py-2">
                        <span class="block truncate font-mono text-xs text-indigo-700 dark:text-indigo-400"
                              title="{{ $screen['external_id'] }}">
                            {{ $screen['external_id'] ?: '—' }}
                        </span>
                        @if(!empty($screen['duplicate_in_file']))
                            <span class="mt-0.5 inline-flex items-center rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold text-orange-600 dark:bg-orange-900/40 dark:text-orange-400">
                                ⊕ TRÙNG
                            </span>
                        @endif
                    </td>
                    <td class="max-w-[200px] px-3 py-2 font-medium text-gray-800 dark:text-gray-200">
                        <span class="block truncate" title="{{ $screen['name'] }}">{{ $screen['name'] ?: '—' }}</span>
                    </td>
                    <td class="px-3 py-2">
                        {{-- Resolved site external_id --}}
                        <span class="block truncate font-mono text-xs text-primary-600 dark:text-primary-400"
                              title="{{ $screen['site_external_id'] }}">
                            {{ $screen['site_external_id'] ?: '—' }}
                        </span>
                    </td>
                    <td class="px-3 py-2">
                        @if(!empty($screen['network_name']))
                            <span class="block truncate font-mono text-xs text-indigo-700 dark:text-indigo-400"
                                  title="{{ $screen['network_name'] }}">
                                {{ $screen['network_name'] }}
                            </span>
                            @if(($screen['network_action'] ?? '') === 'new')
                                <span class="mt-0.5 inline-flex items-center rounded bg-success-100 px-1.5 py-0.5 text-[10px] font-bold text-success-700 dark:bg-success-900/40 dark:text-success-300">
                                    ✦ Tạo mới
                                </span>
                            @elseif(($screen['network_action'] ?? '') === 'existing')
                                <span class="mt-0.5 inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                                    ✓ Có sẵn
                                </span>
                            @endif
                        @else
                            <span class="text-gray-300 text-xs">—</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 font-mono text-xs text-gray-600 whitespace-nowrap">
                        @if(($screen['width_px'] ?? 0) > 0)
                            {{ $screen['width_px'] }}×{{ $screen['height_px'] }}
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="max-w-[140px] px-3 py-2 text-xs text-gray-600 dark:text-gray-400">
                        <span class="block truncate" title="{{ $screen['venue_label'] ?? '' }}">
                            {{ ($screen['venue_label'] ?? '') ?: '—' }}
                        </span>
                    </td>
                    <td class="max-w-[240px] px-3 py-2">
                        @if($screen['action'] === 'error')
                            <span class="text-xs leading-relaxed text-danger-600 dark:text-danger-400">{{ $screen['error'] }}</span>
                        @elseif(!empty($screen['changes']))
                            <ul class="space-y-0.5 text-xs text-warning-700 dark:text-warning-400">
                                @foreach($screen['changes'] as $change)
                                    <li class="flex items-start gap-1"><span class="mt-0.5 shrink-0 text-warning-400">•</span>{{ $change }}</li>
                                @endforeach
                            </ul>
                        @else
                            <span class="text-xs italic text-gray-400">Không có thay đổi</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-sm italic text-gray-400">
                        Không có screen nào khớp bộ lọc "{{ $screenFilterAction }}"
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($this->getScreenTotal() > $perPage)
    <div class="mt-3 flex items-center justify-between px-1 text-sm text-gray-500">
        <span>Trang {{ $screenPage }} / {{ ceil($this->getScreenTotal() / $perPage) }} · {{ number_format($this->getScreenTotal()) }} bản ghi</span>
        <div class="flex gap-2">
            @if($screenPage > 1)
                <x-filament::button size="sm" color="gray" wire:click="$set('screenPage', {{ $screenPage - 1 }})">← Trước</x-filament::button>
            @endif
            @if($this->hasScreenNextPage())
                <x-filament::button size="sm" color="gray" wire:click="$set('screenPage', {{ $screenPage + 1 }})">Sau →</x-filament::button>
            @endif
        </div>
    </div>
    @endif
</x-filament::section>
@endif {{-- end hasScreens --}}

{{-- Bottom spacer --}}
<div class="h-4"></div>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     STEP 3 — IMPORTING (auto-trigger via Alpine.js)
══════════════════════════════════════════════════════════════════════════ --}}
@if($step === 3)
<x-filament::section>
    <div x-data x-init="$nextTick(() => $wire.runImport())" class="py-12 text-center">
        <div class="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-primary-100 dark:bg-primary-900/40">
            <x-filament::loading-indicator class="h-10 w-10 text-primary-600 dark:text-primary-400"/>
        </div>
        <h3 class="text-xl font-bold text-gray-800 dark:text-white">Đang import dữ liệu...</h3>
        <p class="mt-2 text-sm text-gray-500">
            Vui lòng chờ, không đóng trang trong lúc đang xử lý.
        </p>
        <div class="mt-6 mx-auto max-w-xs">
            <div class="h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                <div class="h-full animate-pulse rounded-full bg-primary-500"></div>
            </div>
        </div>
        <p class="mt-4 text-xs text-gray-400">
            Hệ thống đang kiểm tra và lưu Sites + Screens vào database...
        </p>
    </div>
</x-filament::section>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     STEP 4 — REPORT
══════════════════════════════════════════════════════════════════════════ --}}
@if($step === 4 && $results !== null)
@php
    $errCount       = count($results['errors'] ?? []);
    $importOk       = $errCount === 0;
    $sitesCreated   = $results['sites_created']   ?? 0;
    $sitesUpdated   = $results['sites_updated']   ?? 0;
    $screensCreated = $results['screens_created'] ?? 0;
    $screensUpdated = $results['screens_updated'] ?? 0;
    $skipped        = $results['skipped']         ?? 0;
    $hasResultScreens = ($screensCreated + $screensUpdated) > 0 || isset($results['screens_created']);
@endphp

<x-filament::section>
    {{-- Status + Summary --}}
    <div class="mb-6 flex items-start gap-4">
        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full
                    {{ $importOk ? 'bg-success-100 dark:bg-success-900/40' : 'bg-warning-100 dark:bg-warning-900/40' }}">
            @if($importOk)
                <x-heroicon-o-check-circle class="h-7 w-7 text-success-600"/>
            @else
                <x-heroicon-o-exclamation-triangle class="h-7 w-7 text-warning-600"/>
            @endif
        </div>
        <div class="flex-1">
            <h3 class="text-lg font-bold text-gray-800 dark:text-white">
                {{ $importOk ? 'Import thành công!' : 'Import hoàn tất (có lỗi)' }}
            </h3>
            <div class="mt-2 flex flex-wrap gap-x-6 gap-y-2">
                {{-- Sites --}}
                <div class="flex items-center gap-2">
                    <x-heroicon-s-map-pin class="h-4 w-4 text-primary-500"/>
                    <span class="text-sm text-gray-600 dark:text-gray-400">Địa điểm:</span>
                    @if($sitesCreated > 0)
                    <span class="rounded-full bg-success-100 px-2 py-0.5 text-xs font-bold text-success-700 dark:bg-success-900/40 dark:text-success-400">{{ $sitesCreated }} mới</span>
                    @endif
                    @if($sitesUpdated > 0)
                    <span class="rounded-full bg-warning-100 px-2 py-0.5 text-xs font-bold text-warning-700 dark:bg-warning-900/40 dark:text-warning-400">{{ $sitesUpdated }} cập nhật</span>
                    @endif
                    @if($sitesCreated === 0 && $sitesUpdated === 0)
                    <span class="text-xs text-gray-400">0</span>
                    @endif
                </div>
                {{-- Screens --}}
                <div class="flex items-center gap-2">
                    <x-heroicon-s-computer-desktop class="h-4 w-4 text-indigo-500"/>
                    <span class="text-sm text-gray-600 dark:text-gray-400">Màn hình:</span>
                    @if($screensCreated > 0)
                    <span class="rounded-full bg-success-100 px-2 py-0.5 text-xs font-bold text-success-700 dark:bg-success-900/40 dark:text-success-400">{{ $screensCreated }} mới</span>
                    @endif
                    @if($screensUpdated > 0)
                    <span class="rounded-full bg-warning-100 px-2 py-0.5 text-xs font-bold text-warning-700 dark:bg-warning-900/40 dark:text-warning-400">{{ $screensUpdated }} cập nhật</span>
                    @endif
                    @if($screensCreated === 0 && $screensUpdated === 0)
                    <span class="text-xs text-gray-400">0</span>
                    @endif
                </div>
                {{-- Errors --}}
                @if($errCount > 0)
                <div class="flex items-center gap-2">
                    <x-heroicon-s-x-circle class="h-4 w-4 text-danger-500"/>
                    <span class="rounded-full bg-danger-100 px-2 py-0.5 text-xs font-bold text-danger-700 dark:bg-danger-900/40 dark:text-danger-400">{{ $errCount }} lỗi bỏ qua</span>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Error list --}}
    @if($errCount > 0)
    <div class="mb-6 rounded-lg border border-danger-200 bg-danger-50 p-4 dark:border-danger-800 dark:bg-danger-950/30">
        <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-danger-700 dark:text-danger-400">
            <x-heroicon-s-x-circle class="h-4 w-4"/>
            Chi tiết lỗi ({{ number_format($errCount) }} dòng bị bỏ qua)
        </h4>
        <ul class="max-h-56 space-y-1.5 overflow-y-auto text-sm text-danger-700 dark:text-danger-400">
            @foreach($results['errors'] as $err)
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 shrink-0 text-danger-400">•</span>
                    {{ $err }}
                </li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- DB Duplicate report --}}
    @php
        $dbDupSites   = $results['duplicates']['sites']   ?? [];
        $dbDupScreens = $results['duplicates']['screens'] ?? [];
        $hasDbDup     = count($dbDupSites) > 0 || count($dbDupScreens) > 0;
    @endphp
    @if($hasDbDup)
    <div class="mb-6 rounded-lg border border-orange-200 bg-orange-50 p-4 dark:border-orange-700 dark:bg-orange-950/30">
        <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-orange-700 dark:text-orange-400">
            <x-heroicon-s-document-duplicate class="h-4 w-4"/>
            Phát hiện dữ liệu trùng lặp trong database
            <span class="ml-auto text-xs font-normal text-orange-500">Cần kiểm tra thủ công</span>
        </h4>

        @if(count($dbDupSites) > 0)
        <div class="mb-3">
            <p class="mb-1.5 text-xs font-semibold text-orange-600 dark:text-orange-400">
                Sites trùng tên ({{ count($dbDupSites) }} nhóm):
            </p>
            <div class="space-y-1.5">
                @foreach($dbDupSites as $dup)
                <div class="rounded border border-orange-200 bg-white px-3 py-2 dark:border-orange-800 dark:bg-gray-800">
                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">
                        "{{ $dup['name'] }}"
                        <span class="ml-1 rounded bg-orange-100 px-1.5 py-0.5 text-[10px] text-orange-600 dark:bg-orange-900/40">{{ $dup['count'] }} bản ghi</span>
                    </p>
                    <p class="mt-0.5 font-mono text-[11px] text-gray-500">
                        Mã: {{ implode(', ', array_column($dup['records'], 'id')) }}
                    </p>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        @if(count($dbDupScreens) > 0)
        <div>
            <p class="mb-1.5 text-xs font-semibold text-orange-600 dark:text-orange-400">
                Screens trùng tên trên cùng Site ({{ count($dbDupScreens) }} nhóm):
            </p>
            <div class="space-y-1.5">
                @foreach($dbDupScreens as $dup)
                <div class="rounded border border-orange-200 bg-white px-3 py-2 dark:border-orange-800 dark:bg-gray-800">
                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">
                        "{{ $dup['name'] }}"
                        <span class="mx-1 text-gray-400">@</span>
                        <span class="text-primary-600 dark:text-primary-400">{{ $dup['site_name'] }}</span>
                        <span class="ml-1 rounded bg-orange-100 px-1.5 py-0.5 text-[10px] text-orange-600 dark:bg-orange-900/40">{{ $dup['count'] }} bản ghi</span>
                    </p>
                    <p class="mt-0.5 font-mono text-[11px] text-gray-500">
                        Mã: {{ implode(', ', array_column($dup['records'], 'id')) }}
                    </p>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        <p class="mt-3 text-xs text-orange-500">
            Vào <strong>Địa điểm</strong> hoặc <strong>Màn hình</strong> để xem và xoá bản ghi trùng lặp không cần thiết.
        </p>
    </div>
    @else
    <div class="mb-6 flex items-center gap-2 rounded-lg border border-success-200 bg-success-50 px-4 py-3 dark:border-success-800 dark:bg-success-950/30">
        <x-heroicon-s-check-circle class="h-4 w-4 text-success-600"/>
        <p class="text-sm text-success-700 dark:text-success-400">Không phát hiện dữ liệu trùng lặp trong database.</p>
    </div>
    @endif

    {{-- Action buttons --}}
    <div class="flex flex-wrap justify-center gap-3 pt-2">
        <x-filament::button
            wire:click="goToSitesList"
            icon="heroicon-o-map-pin"
            color="primary">
            Xem danh sách Sites
        </x-filament::button>
        <x-filament::button
            wire:click="importAnother"
            icon="heroicon-o-arrow-up-tray"
            color="gray">
            Import file khác
        </x-filament::button>
    </div>
</x-filament::section>
@endif

</x-filament-panels::page>
