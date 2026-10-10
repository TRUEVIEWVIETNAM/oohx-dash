@php
    /** @var \App\Models\Screen $screen */
    $spec = $screen->spec;
    $inv  = $screen->inventory;
    $site = $screen->site;
    $hasGps = $site?->lat && $site?->lon;
    $photos = $spec?->photos ?? [];
@endphp

<div class="space-y-3">
    {{-- Photos gallery --}}
    @if(count($photos))
        <div class="flex gap-2 overflow-x-auto pb-2">
            @foreach($photos as $photo)
                <img
                    src="{{ asset('storage/' . $photo) }}"
                    alt="Ảnh màn hình"
                    class="h-28 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-gray-700"
                />
            @endforeach
        </div>
        <div class="border-t border-gray-100 dark:border-gray-800"></div>
    @endif

    {{-- Row 1: Identity --}}
    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
        <div>
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Chủ sở hữu</div>
            <div class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ $screen->owner?->name ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Địa điểm</div>
            <div class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ $site?->name ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Mạng lưới</div>
            <div class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ $site?->network?->name ?? '—' }}</div>
        </div>
    </div>

    <div class="border-t border-gray-100 dark:border-gray-800"></div>

    {{-- Row 2: Classification --}}
    <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
        <div>
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Loại biển</div>
            <div class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ $inv?->vnCategory?->name_vi ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Thành phố</div>
            <div class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ $site?->city ?? '—' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Mã màn hình</div>
            <div class="mt-0.5 text-sm font-mono text-gray-900 dark:text-white">{{ $screen->external_id ?? '—' }}</div>
        </div>
    </div>

    <div class="border-t border-gray-100 dark:border-gray-800"></div>

    {{-- Row 3: Specs (left) + Map (right) --}}
    <div style="display:grid; grid-template-columns: {{ $hasGps ? '5fr 4fr' : '1fr' }}; gap: 16px; align-items: start;">

        {{-- Left: Specs --}}
        <div>
            <div style="display:grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Độ phân giải</div>
                    <div class="mt-0.5 text-sm text-gray-900 dark:text-white">
                        {{ $spec?->width_px ? $spec->width_px . '×' . $spec->height_px . ' px' : '—' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Kích thước</div>
                    <div class="mt-0.5 text-sm text-gray-900 dark:text-white">
                        {{ $spec?->width_cm ? $spec->width_cm . '×' . $spec->height_cm . ' cm' : '—' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Giá sàn CPM</div>
                    <div class="mt-0.5 text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $inv?->floor_cpm ? number_format((float) $inv->floor_cpm, 0, '.', ',') . ' ' . ($inv->floor_cpm_currency ?? 'VND') : '—' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Lượt xem/tuần</div>
                    <div class="mt-0.5 text-sm text-gray-900 dark:text-white">
                        {{ $inv?->weekly_impressions ? number_format((int) $inv->weekly_impressions) : '—' }}
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Thời lượng QC</div>
                    <div class="mt-0.5 text-sm text-gray-900 dark:text-white">{{ ($inv?->spot_length ?? 15) }}s</div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 mt-3">
                <x-filament::badge :color="$screen->active ? 'success' : 'danger'">
                    {{ $screen->active ? 'Active' : 'Inactive' }}
                </x-filament::badge>
                <x-filament::badge :color="match($screen->status) { 'online' => 'success', 'offline' => 'danger', default => 'warning' }">
                    {{ ucfirst($screen->status ?? 'unknown') }}
                </x-filament::badge>
                @if($inv?->programmatic_enabled)
                    <x-filament::badge color="info">RTB</x-filament::badge>
                @endif
            </div>
        </div>

        {{-- Right: Map — uses x-data/x-init so Alpine executes it inside Livewire modal --}}
        @if($hasGps)
            <div
                x-data
                x-init="
                    function boot() {
                        if (typeof L === 'undefined') {
                            if (!document.querySelector('link[href*=\'leaflet.css\']')) {
                                var l = document.createElement('link');
                                l.rel = 'stylesheet'; l.href = '/vendor/leaflet/leaflet.css';
                                document.head.appendChild(l);
                            }
                            if (!document.querySelector('script[src*=\'leaflet.js\']')) {
                                var s = document.createElement('script');
                                s.src = '/vendor/leaflet/leaflet.js';
                                s.onload = function() { boot(); };
                                document.head.appendChild(s);
                                return;
                            }
                            // script tag exists but L not ready — retry
                            setTimeout(boot, 100);
                            return;
                        }

                        var el = $refs.mapEl;
                        if (!el) return;
                        if (el._lmap) { try { el._lmap.remove(); } catch(e){} el._lmap = null; }

                        delete L.Icon.Default.prototype._getIconUrl;
                        L.Icon.Default.mergeOptions({
                            iconUrl:       '/vendor/leaflet/images/marker-icon.png',
                            iconRetinaUrl: '/vendor/leaflet/images/marker-icon-2x.png',
                            shadowUrl:     '/vendor/leaflet/images/marker-shadow.png',
                        });

                        var map = L.map(el, { zoomControl: true, scrollWheelZoom: false })
                            .setView([{{ (float) $site->lat }}, {{ (float) $site->lon }}], 16);
                        el._lmap = map;

                        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            attribution: '\u00a9 OpenStreetMap', maxZoom: 19,
                        }).addTo(map);

                        L.marker([{{ (float) $site->lat }}, {{ (float) $site->lon }}]).addTo(map)
                            .bindPopup({{ Js::from($site->name ?? $screen->name) }})
                            .openPopup();

                        // invalidateSize after modal animation completes
                        requestAnimationFrame(function() {
                            map.invalidateSize();
                            setTimeout(function() { map.invalidateSize(); }, 300);
                            setTimeout(function() { map.invalidateSize(); }, 600);
                        });
                    }

                    $nextTick(() => boot());
                "
            >
                <div class="flex items-center gap-1.5 mb-1">
                    <x-heroicon-m-map-pin class="w-3.5 h-3.5 text-gray-400" />
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ number_format((float) $site->lat, 5) }}, {{ number_format((float) $site->lon, 5) }}
                    </span>
                </div>
                @if($site->address)
                    <p class="mb-1 text-xs text-gray-500 dark:text-gray-400">{{ $site->address }}</p>
                @endif
                <div x-ref="mapEl" style="height: 200px; width: 100%; border-radius: 8px; border: 1px solid #d1d5db;"></div>
            </div>
        @endif
    </div>
</div>
