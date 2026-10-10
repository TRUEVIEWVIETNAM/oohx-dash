@php
    $network = $getRecord();
    $networkKey = 'nsp_' . preg_replace('/[^a-z0-9]/i', '_', $network->id);

    $screens = \App\Models\Screen::whereHas('inventory', fn($q) => $q->where('network_id', $network->id))
        ->with(['site.province', 'site.commune', 'inventory'])
        ->get();

    $screensData = $screens->map(fn($s) => [
        'id'            => $s->id,
        'external_id'   => $s->external_id ?? '—',
        'name'          => $s->name ?? '—',
        'site_id'       => (string) ($s->site_id ?? ''),
        'site_name'     => $s->site?->name ?? '—',
        'site_lat'      => $s->site?->lat  ? (float) $s->site->lat  : null,
        'site_lon'      => $s->site?->lon  ? (float) $s->site->lon  : null,
        'province_id'   => $s->site?->province_id ? (string) $s->site->province_id : '',
        'province_name' => $s->site?->province?->name ?? '',
        'commune_id'    => $s->site?->commune_id  ? (string) $s->site->commune_id  : '',
        'commune_name'  => $s->site?->commune?->full_name ?? '',
        'floor_cpm'     => $s->inventory?->floor_cpm
            ? number_format((float) $s->inventory->floor_cpm, 2) . ' ' . ($s->inventory->floor_cpm_currency ?? 'VND')
            : '—',
        'active'        => (bool) $s->active,
        'view_url'      => \App\Filament\Resources\ScreenResource::getUrl('view', ['record' => $s->id]),
    ])->values()->toArray();

    $siteOpts = $screens
        ->filter(fn($s) => $s->site_id && $s->site)
        ->mapWithKeys(fn($s) => [(string) $s->site_id => $s->site->name])
        ->sortBy(fn($v) => $v)->toArray();

    $provinceOpts = $screens
        ->filter(fn($s) => $s->site?->province_id)
        ->mapWithKeys(fn($s) => [(string) $s->site->province_id => $s->site->province?->name ?? '?'])
        ->filter()->sortBy(fn($v) => $v)->toArray();

    $communeOpts = $screens
        ->filter(fn($s) => $s->site?->commune_id)
        ->mapWithKeys(fn($s) => [(string) $s->site->commune_id => $s->site->commune?->full_name ?? '?'])
        ->filter()->sortBy(fn($v) => $v)->toArray();
@endphp

{{-- Single root wrapper required by Livewire --}}
<div>

{{-- Pass data via window variable keyed by network --}}
<script>
    window['{{ $networkKey }}'] = {
        screens:      @json($screensData),
        siteOpts:     @json($siteOpts),
        provinceOpts: @json($provinceOpts),
        communeOpts:  @json($communeOpts),
    };
</script>

@pushOnce('styles')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
@endPushOnce

@once
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset('js/network-screens-panel.js') }}?v={{ filemtime(public_path('js/network-screens-panel.js')) }}"></script>
@endonce

<div
    x-data="networkScreensPanel('{{ $networkKey }}')"
    class="space-y-4"
>

    {{-- Block 1: Filter section --}}
    <section class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <header class="fi-section-header flex items-center justify-between gap-3 px-6 py-4">
            <h3 class="fi-section-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">
                Bộ lọc
            </h3>
            <button
                @click="filterSite = ''; filterProvince = ''; filterCommune = ''"
                x-show="filterSite || filterProvince || filterCommune"
                x-cloak
                type="button"
                class="text-sm font-semibold text-danger-600 hover:text-danger-500 dark:text-danger-400 dark:hover:text-danger-300 transition duration-75"
            >
                Xoá bộ lọc
            </button>
        </header>

        <div class="fi-section-content-ctn border-t border-gray-200 dark:border-white/10">
            <div class="fi-section-content px-6 py-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">

                    {{-- Site --}}
                    <div class="flex flex-col gap-y-1.5">
                        <label class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                            Địa điểm
                        </label>
                        <div class="fi-input-wrp flex rounded-lg shadow-sm ring-1 transition duration-75 bg-white dark:bg-white/5 ring-gray-950/10 dark:ring-white/20 [&:not(:has(.fi-ac-action:focus))]:focus-within:ring-2 [&:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-600 dark:[&:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-500">
                            <select
                                x-model="filterSite"
                                class="fi-select-input block w-full border-none bg-transparent py-1.5 ps-3 pe-8 text-sm text-gray-950 transition duration-75 focus:ring-0 dark:text-white [&_option]:bg-white [&_option]:dark:bg-gray-900"
                            >
                                <option value="">Tất cả</option>
                                <template x-for="[id, name] in Object.entries(siteOpts)" :key="id">
                                    <option :value="id" x-text="name"></option>
                                </template>
                            </select>
                        </div>
                    </div>

                    {{-- Tỉnh / Thành phố --}}
                    <div class="flex flex-col gap-y-1.5">
                        <label class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                            Tỉnh / Thành phố
                        </label>
                        <div class="fi-input-wrp flex rounded-lg shadow-sm ring-1 transition duration-75 bg-white dark:bg-white/5 ring-gray-950/10 dark:ring-white/20 [&:not(:has(.fi-ac-action:focus))]:focus-within:ring-2 [&:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-600 dark:[&:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-500">
                            <select
                                x-model="filterProvince"
                                class="fi-select-input block w-full border-none bg-transparent py-1.5 ps-3 pe-8 text-sm text-gray-950 transition duration-75 focus:ring-0 dark:text-white [&_option]:bg-white [&_option]:dark:bg-gray-900"
                            >
                                <option value="">Tất cả</option>
                                <template x-for="[id, name] in Object.entries(provinceOpts)" :key="id">
                                    <option :value="id" x-text="name"></option>
                                </template>
                            </select>
                        </div>
                    </div>

                    {{-- Phường / Xã --}}
                    <div class="flex flex-col gap-y-1.5">
                        <label class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                            Phường / Xã
                        </label>
                        <div class="fi-input-wrp flex rounded-lg shadow-sm ring-1 transition duration-75 bg-white dark:bg-white/5 ring-gray-950/10 dark:ring-white/20 [&:not(:has(.fi-ac-action:focus))]:focus-within:ring-2 [&:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-600 dark:[&:not(:has(.fi-ac-action:focus))]:focus-within:ring-primary-500">
                            <select
                                x-model="filterCommune"
                                class="fi-select-input block w-full border-none bg-transparent py-1.5 ps-3 pe-8 text-sm text-gray-950 transition duration-75 focus:ring-0 dark:text-white [&_option]:bg-white [&_option]:dark:bg-gray-900"
                            >
                                <option value="">Tất cả</option>
                                <template x-for="[id, name] in Object.entries(availableCommunes)" :key="id">
                                    <option :value="id" x-text="name"></option>
                                </template>
                            </select>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </section>

    {{-- Block 2: Table + Map container --}}
    <div class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">

        {{-- Toolbar: count + tab switcher --}}
        <div class="fi-ta-header-toolbar flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <span class="text-sm text-gray-500 dark:text-gray-400">
                <span x-text="filtered.length" class="font-semibold text-gray-700 dark:text-gray-200"></span>
                <span x-show="filtered.length < allScreens.length">
                    / <span x-text="allScreens.length"></span>
                </span>
                màn hình
            </span>

            <div class="flex gap-0.5 rounded-lg bg-gray-100 dark:bg-white/5 p-0.5">
                <button
                    @click="tab = 'list'"
                    :class="tab === 'list' ? 'bg-white dark:bg-white/10 shadow-sm text-gray-950 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-all"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                    </svg>
                    Danh sách
                </button>
                <button
                    @click="switchTab('map')"
                    :class="tab === 'map' ? 'bg-white dark:bg-white/10 shadow-sm text-gray-950 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-all"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                    </svg>
                    Bản đồ
                </button>
            </div>
        </div>

        {{-- List view --}}
        <div x-show="tab === 'list'">
            <div class="fi-ta-content overflow-x-auto">
                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6">
                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white whitespace-nowrap">Mã màn hình</span>
                            </th>
                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6">
                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white whitespace-nowrap">Tên màn hình</span>
                            </th>
                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6">
                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white whitespace-nowrap">Địa điểm</span>
                            </th>
                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6">
                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white whitespace-nowrap">Tỉnh / Thành</span>
                            </th>
                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6">
                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white whitespace-nowrap">Phường / Xã</span>
                            </th>
                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6">
                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white whitespace-nowrap">Giá sàn CPM</span>
                            </th>
                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6">
                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white whitespace-nowrap">Trạng thái</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        <template x-for="s in paginated" :key="s.id">
                            <tr class="fi-ta-record relative h-full bg-white transition duration-75 dark:bg-gray-900 hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                    <div class="px-3 py-3">
                                        <a :href="s.view_url"
                                           class="font-mono text-sm text-primary-600 dark:text-primary-400 hover:underline"
                                           x-text="s.external_id"></a>
                                    </div>
                                </td>
                                <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                    <div class="px-3 py-3">
                                        <a :href="s.view_url"
                                           class="text-sm font-medium text-gray-950 dark:text-white hover:text-primary-600 dark:hover:text-primary-400 hover:underline"
                                           x-text="s.name"></a>
                                    </div>
                                </td>
                                <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                    <div class="px-3 py-3">
                                        <span class="text-sm text-gray-950 dark:text-white" x-text="s.site_name"></span>
                                    </div>
                                </td>
                                <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                    <div class="px-3 py-3">
                                        <span class="text-sm text-gray-500 dark:text-gray-400" x-text="s.province_name || '—'"></span>
                                    </div>
                                </td>
                                <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                    <div class="px-3 py-3">
                                        <span class="text-sm text-gray-500 dark:text-gray-400" x-text="s.commune_name || '—'"></span>
                                    </div>
                                </td>
                                <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                    <div class="px-3 py-3">
                                        <span class="font-mono text-sm text-gray-500 dark:text-gray-400" x-text="s.floor_cpm"></span>
                                    </div>
                                </td>
                                <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                    <div class="px-3 py-3">
                                        <span
                                            class="fi-badge fi-color-custom flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-w-[1.5rem] py-1 bg-custom-50 text-custom-600 ring-custom-600/10 dark:bg-custom-400/10 dark:text-custom-400 dark:ring-custom-400/30"
                                            :class="s.active ? 'fi-color-success' : 'fi-color-danger'"
                                            :style="s.active
                                                ? '--c-50:var(--success-50);--c-400:var(--success-400);--c-600:var(--success-600)'
                                                : '--c-50:var(--danger-50);--c-400:var(--danger-400);--c-600:var(--danger-600)'"
                                        >
                                            <span class="grid">
                                                <span class="truncate" x-text="s.active ? 'Active' : 'Inactive'"></span>
                                            </span>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </template>

                        <template x-if="filtered.length === 0">
                            <tr>
                                <td colspan="7" class="px-6 py-10 text-center">
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">Không có màn hình nào</p>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Thử thay đổi bộ lọc</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div x-show="totalPages > 1" class="fi-ta-footer border-t border-gray-200 dark:border-white/10 px-4 py-3 sm:px-6">
                <nav class="fi-pagination grid grid-cols-[1fr_auto_1fr] items-center gap-x-3">
                    <button
                        @click="page = Math.max(1, page - 1)"
                        :disabled="page === 1"
                        type="button"
                        class="fi-btn fi-pagination-previous-btn fi-size-md fi-btn-color-gray relative inline-grid grid-flow-col items-center justify-center justify-self-start font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg shadow-sm gap-1.5 px-3 py-2 text-sm bg-white text-gray-950 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20 disabled:opacity-70 disabled:pointer-events-none"
                    >
                        Trước
                    </button>

                    <span class="fi-pagination-overview text-sm font-medium text-gray-700 dark:text-gray-200 text-center">
                        <span x-text="Math.min((page - 1) * perPage + 1, filtered.length)"></span>
                        –
                        <span x-text="Math.min(page * perPage, filtered.length)"></span>
                        /
                        <span x-text="filtered.length"></span>
                    </span>

                    <button
                        @click="page = Math.min(totalPages, page + 1)"
                        :disabled="page >= totalPages"
                        type="button"
                        class="fi-btn fi-pagination-next-btn fi-size-md fi-btn-color-gray relative inline-grid grid-flow-col items-center justify-center justify-self-end font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg shadow-sm gap-1.5 px-3 py-2 text-sm bg-white text-gray-950 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20 disabled:opacity-70 disabled:pointer-events-none"
                    >
                        Sau
                    </button>
                </nav>
            </div>
        </div>

        {{-- Map view --}}
        <div x-show="tab === 'map'" x-cloak>
            <div class="p-4" wire:ignore>
                <div x-ref="mapEl"
                     style="height:520px;width:100%;z-index:0;border-radius:0.75rem;overflow:hidden;border:1px solid rgba(229,231,235,1)">
                </div>
            </div>
            <p class="px-6 pb-3 text-xs text-gray-400 dark:text-gray-500 text-center">
                Nhấn vào marker để xem danh sách màn hình tại site đó.
                <span x-show="filtered.filter(s => s.site_lat && s.site_lon).length < filtered.length">
                    (<span x-text="filtered.length - filtered.filter(s => s.site_lat && s.site_lon).length"></span> màn hình không có tọa độ GPS.)
                </span>
            </p>
        </div>

    </div>{{-- /fi-ta-ctn --}}
</div>

</div>{{-- /single root wrapper --}}
