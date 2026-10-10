@php
    $site = $getRecord();
    $siteKey = 'ssp_' . preg_replace('/[^a-z0-9]/i', '_', $site->id);

    $screens = $site->screens()->with(['inventory'])->get();

    $screensData = $screens->map(fn($s) => [
        'id'          => $s->id,
        'external_id' => $s->external_id ?? '—',
        'name'        => $s->name ?? '—',
        'floor_cpm'   => $s->inventory?->floor_cpm
            ? number_format((float) $s->inventory->floor_cpm, 2) . ' ' . ($s->inventory->floor_cpm_currency ?? 'VND')
            : '—',
        'active'      => (bool) $s->active,
        'description' => $s->description ?? '',
        'view_url'    => \App\Filament\Resources\ScreenResource::getUrl('view', ['record' => $s->id]),
    ])->values()->toArray();

    $siteLat  = $site->lat  ? (float) $site->lat  : null;
    $siteLon  = $site->lon  ? (float) $site->lon  : null;
    $siteName = e($site->name ?? 'Site');
@endphp

{{-- Single root wrapper required by Livewire --}}
<div>

<script>
    window['{{ $siteKey }}'] = {
        screens: @json($screensData),
        lat:     {{ $siteLat ?? 'null' }},
        lon:     {{ $siteLon ?? 'null' }},
        name:    @json($siteName),
    };
</script>

@pushOnce('styles')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
@endPushOnce

@once
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    <script src="{{ asset('js/site-screens-panel.js') }}?v={{ filemtime(public_path('js/site-screens-panel.js')) }}"></script>
@endonce

<div
    x-data="siteScreensPanel('{{ $siteKey }}')"
    class="space-y-3"
>
    {{-- Filter + Tab bar --}}
    <div class="fi-ta-ctn divide-y divide-gray-200 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">

        {{-- Toolbar row --}}
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
            {{-- Left: filters --}}
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2">
                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">Trạng thái</label>
                    <select x-model="filterActive"
                            class="rounded-lg border border-gray-300 dark:border-white/20 bg-white dark:bg-white/5 text-gray-950 dark:text-white text-sm py-1.5 px-2.5 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 focus:outline-none">
                        <option value="">Tất cả</option>
                        <option value="active">Đang bật</option>
                        <option value="inactive">Đã tắt</option>
                    </select>
                </div>

                <button
                    @click="filterActive = ''"
                    x-show="filterActive !== ''"
                    x-cloak
                    class="text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 underline">
                    Xoá bộ lọc
                </button>
            </div>

            {{-- Right: tab switcher + count --}}
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    <span x-text="filtered.length" class="font-semibold text-gray-700 dark:text-gray-200"></span>
                    <span x-show="filtered.length < allScreens.length">
                        / <span x-text="allScreens.length"></span>
                    </span>
                    màn hình
                </span>

                <div class="flex gap-0.5 rounded-lg bg-gray-100 dark:bg-white/5 p-0.5">
                    <button @click="tab = 'list'"
                            :class="tab === 'list' ? 'bg-white dark:bg-white/10 shadow-sm text-gray-950 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                        </svg>
                        Danh sách
                    </button>
                    <button @click="switchTab('map')"
                            :class="tab === 'map' ? 'bg-white dark:bg-white/10 shadow-sm text-gray-950 dark:text-white' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200'"
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                        </svg>
                        Bản đồ
                    </button>
                </div>
            </div>
        </div>

        {{-- List view --}}
        <div x-show="tab === 'list'">
            <div class="overflow-x-auto">
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
                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white whitespace-nowrap">Mô tả</span>
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
                                        <span class="text-sm text-gray-500 dark:text-gray-400" x-text="s.description || '—'"></span>
                                    </div>
                                </td>
                                <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                    <div class="px-3 py-3">
                                        <span class="font-mono text-sm text-gray-500 dark:text-gray-400" x-text="s.floor_cpm"></span>
                                    </div>
                                </td>
                                <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                    <div class="px-3 py-3">
                                        <span :class="s.active
                                                ? 'bg-green-50 text-green-600 ring-green-600/10 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/30'
                                                : 'bg-red-50 text-red-600 ring-red-600/10 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/30'"
                                              class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 py-0.5"
                                              x-text="s.active ? 'Active' : 'Inactive'">
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </template>

                        <template x-if="filtered.length === 0">
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center">
                                    <p class="text-sm font-medium text-gray-950 dark:text-white">Không có màn hình nào</p>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Thử thay đổi bộ lọc</p>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div x-show="totalPages > 1" class="fi-ta-pagination px-3 py-3 sm:px-6">
                <nav class="fi-pagination grid grid-cols-[1fr_auto_1fr] items-center gap-x-3">
                    <button
                        @click="page = Math.max(1, page - 1)"
                        :disabled="page === 1"
                        class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg shadow-sm gap-1 px-2.5 py-1.5 text-sm bg-white text-gray-950 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20 disabled:opacity-50 disabled:cursor-not-allowed justify-self-start"
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
                        class="fi-btn relative grid-flow-col items-center justify-center font-semibold outline-none transition duration-75 focus-visible:ring-2 rounded-lg shadow-sm gap-1 px-2.5 py-1.5 text-sm bg-white text-gray-950 hover:bg-gray-50 dark:bg-white/5 dark:text-white dark:hover:bg-white/10 ring-1 ring-gray-950/10 dark:ring-white/20 disabled:opacity-50 disabled:cursor-not-allowed justify-self-end"
                    >
                        Sau
                    </button>
                </nav>
            </div>
        </div>

        {{-- Map view --}}
        <div x-show="tab === 'map'" x-cloak>
            <template x-if="!siteLat || !siteLon">
                <p class="px-6 py-8 text-sm italic text-gray-500 dark:text-gray-400 text-center">Site này chưa có tọa độ GPS.</p>
            </template>
            <template x-if="siteLat && siteLon">
                <div class="p-4" wire:ignore>
                    <div x-ref="mapEl"
                         style="height:400px;width:100%;z-index:0;border-radius:0.75rem;overflow:hidden;border:1px solid rgba(229,231,235,1)">
                    </div>
                </div>
            </template>
            <p class="px-6 pb-3 text-xs text-gray-400 dark:text-gray-500 text-center">
                Nhấn vào marker để xem danh sách màn hình.
            </p>
        </div>

    </div>{{-- /fi-ta-ctn --}}
</div>

</div>{{-- /single root wrapper --}}
