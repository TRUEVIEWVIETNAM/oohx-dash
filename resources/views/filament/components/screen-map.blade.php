@php
    $record = $getRecord();
    // Support cả Screen ($record->site) và Site ($record chính là site)
    $isSite = $record instanceof \App\Models\Site;
    $site = $isSite ? $record : ($record->relationLoaded('site') ? $record->site : $record->site()->first());
    $lat = $site?->lat ?? $record->lat ?? null;
    $lon = $site?->lon ?? $record->lon ?? null;
    $screenName = $record->name ?? 'Location';
    $hasLocation = $lat && $lon;

    // Resolve POIs từ poi_snapshots (nếu đã enrich)
    $poiBrowserId = 'sm' . substr(md5(($lat ?? '') . ',' . ($lon ?? '') . ',' . $screenName), 0, 8);
    $projected = [];
    $grouped   = [];
    if ($hasLocation) {
        $snapshot = \App\Models\PoiSnapshot::freshFor((float) $lat, (float) $lon, 500, 'osm')->first();
        if ($snapshot && is_array($snapshot->pois)) {
            $projected = \App\Support\PoiProjection::project($snapshot->pois, (float) $lat, (float) $lon, 60);
            $grouped   = \App\Support\PoiProjection::groupByCategory($projected);
        }
    }
    $hasPois     = ! empty($projected);
    $groupLabels = \App\Support\PoiProjection::GROUP_LABELS;
@endphp

@if(! $hasLocation)
    <div><p class="text-sm italic text-gray-400">Không có tọa độ GPS.</p></div>
@else
    @pushOnce('styles')
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap">
        <style>
        .material-symbols-outlined{font-family:'Material Symbols Outlined';font-weight:normal;font-style:normal;line-height:1;letter-spacing:normal;text-transform:none;display:inline-block;white-space:nowrap;word-wrap:normal;direction:ltr;-webkit-font-feature-settings:'liga';-webkit-font-smoothing:antialiased;font-variation-settings:'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24}
        .sm-browser{display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:14px;height:520px}
        .sm-browser--simple{grid-template-columns:1fr;height:340px}
        @media(max-width:1024px){.sm-browser{grid-template-columns:1fr;height:auto}.sm-browser .sm-list{max-height:280px}.sm-browser .sm-map-wrap{height:380px}}
        .sm-list{overflow-y:auto;border:1px solid rgb(229 231 235);border-radius:8px;background:#fff}
        .dark .sm-list{border-color:rgb(55 65 81);background:rgb(17 24 39)}
        .sm-summary{padding:9px 12px;border-bottom:1px solid rgb(229 231 235);font-size:11px;color:rgb(75 85 99);background:rgb(249 250 251);position:sticky;top:0;z-index:5}
        .dark .sm-summary{background:rgb(31 41 55);border-color:rgb(55 65 81);color:rgb(156 163 175)}
        .sm-filter{display:flex;flex-wrap:wrap;gap:5px;padding:9px 10px;border-bottom:1px solid rgb(229 231 235);background:#fff;position:sticky;top:32px;z-index:5}
        .dark .sm-filter{background:rgb(17 24 39);border-color:rgb(55 65 81)}
        .sm-filter-pill{display:inline-flex;align-items:center;gap:3px;padding:4px 9px;font-size:10px;font-weight:600;color:rgb(75 85 99);background:#fff;border:1px solid rgb(229 231 235);border-radius:980px;cursor:pointer;transition:all 140ms;line-height:1;font-family:inherit}
        .dark .sm-filter-pill{background:rgb(17 24 39);border-color:rgb(55 65 81);color:rgb(156 163 175)}
        .sm-filter-pill .material-symbols-outlined{font-size:13px;color:inherit}
        .sm-filter-pill .sm-filter-ct{margin-left:2px;font-size:9px;background:rgb(243 244 246);color:rgb(75 85 99);padding:1px 5px;border-radius:980px;font-weight:700;letter-spacing:0;border:1px solid rgb(229 231 235)}
        .dark .sm-filter-pill .sm-filter-ct{background:rgb(31 41 55);color:rgb(156 163 175);border-color:rgb(55 65 81)}
        .sm-filter-pill:hover{border-color:rgb(37 99 235);color:rgb(37 99 235)}
        .sm-filter-pill.is-active{background:rgb(37 99 235);color:#fff;border-color:rgb(37 99 235)}
        .sm-filter-pill.is-active .sm-filter-ct{background:rgba(255,255,255,.25);color:#fff;border-color:transparent}
        .sm-grp-h{padding:7px 12px;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px;background:rgb(243 244 246);color:rgb(55 65 81);border-top:1px solid rgb(229 231 235);position:sticky;top:32px;z-index:4;display:flex;align-items:center;gap:6px}
        .dark .sm-grp-h{background:rgb(31 41 55);color:rgb(209 213 219);border-color:rgb(55 65 81)}
        .sm-grp-h .material-symbols-outlined{font-size:15px;color:rgb(37 99 235)}
        .sm-grp-ct{margin-left:auto;font-size:10px;background:rgb(229 231 235);color:rgb(55 65 81);padding:1px 6px;border-radius:980px;font-weight:700;letter-spacing:0}
        .dark .sm-grp-ct{background:rgb(55 65 81);color:rgb(229 231 235)}
        .sm-item{display:flex;align-items:center;gap:10px;padding:7px 12px;cursor:pointer;border-top:1px solid rgb(243 244 246);transition:background 120ms}
        .dark .sm-item{border-color:rgb(55 65 81)}
        .sm-item:hover{background:rgb(239 246 255)}
        .dark .sm-item:hover{background:rgb(30 58 138 / .25)}
        .sm-item.is-active{background:rgb(219 234 254);box-shadow:inset 3px 0 0 rgb(37 99 235)}
        .dark .sm-item.is-active{background:rgb(30 58 138 / .4);box-shadow:inset 3px 0 0 rgb(96 165 250)}
        .sm-dot{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.2)}
        .sm-dot .material-symbols-outlined{font-size:14px}
        .sm-meta{min-width:0;flex:1}
        .sm-name{font-weight:600;font-size:12px;color:rgb(17 24 39);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .dark .sm-name{color:rgb(243 244 246)}
        .sm-sub{font-size:10px;color:rgb(107 114 128);margin-top:1px}
        .sm-sub b{color:rgb(37 99 235);font-weight:700}
        .dark .sm-sub b{color:rgb(96 165 250)}
        .sm-empty{padding:30px 16px;text-align:center;color:rgb(107 114 128);font-size:12px}
        .sm-map-wrap{height:520px;border-radius:8px;border:1px solid rgb(229 231 235);background:#f3f4f6;overflow:hidden}
        .sm-browser--simple .sm-map-wrap{height:340px}
        .dark .sm-map-wrap{border-color:rgb(55 65 81)}
        .sm-map{width:100%;height:100%}
        .sm-pin{background:none;border:none}
        .sm-pin-inner{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,.25);cursor:pointer;transition:transform 160ms}
        .sm-pin-inner .material-symbols-outlined{font-size:14px}
        .sm-pin-inner:hover{transform:scale(1.3);z-index:1000}
        .sm-center{background:none;border:none;position:relative}
        .sm-center-inner{position:absolute;top:0;left:0;width:32px;height:32px;border-radius:50%;background:rgb(37 99 235);color:#fff;display:flex;align-items:center;justify-content:center;border:3px solid #fff;box-shadow:0 4px 10px rgba(37,99,235,.4);z-index:2}
        .sm-center-inner .material-symbols-outlined{font-size:18px}
        .sm-center-pulse{position:absolute;top:-4px;left:-4px;width:40px;height:40px;border-radius:50%;background:rgb(37 99 235 / .25);animation:sm-pulse 2s ease-in-out infinite;z-index:1}
        @keyframes sm-pulse{0%,100%{transform:scale(1);opacity:.6}50%{transform:scale(1.4);opacity:.1}}
        </style>
    @endPushOnce
    {{-- Single root wrapper — Livewire requires exactly 1 root element per component --}}
    <div wire:ignore class="sm-wrap">
    @once
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    @endonce

    <div class="sm-browser{{ $hasPois ? '' : ' sm-browser--simple' }}">
        @if($hasPois)
            <div class="sm-list" id="{{ $poiBrowserId }}-list">
                <div class="sm-summary">
                    <strong>{{ count($projected) }}</strong> POIs lân cận · <strong>{{ count($grouped) }}</strong> nhóm
                </div>
                <div class="sm-filter" data-poi-filters>
                    <button type="button" class="sm-filter-pill is-active" data-poi-filter-all>
                        <span class="material-symbols-outlined">apps</span>Tất cả
                        <span class="sm-filter-ct">{{ count($projected) }}</span>
                    </button>
                    @foreach($grouped as $groupKey => $items)
                        @php [$grpIcon, $grpLabel] = $groupLabels[$groupKey] ?? ['place', $groupKey]; @endphp
                        <button type="button" class="sm-filter-pill is-active" data-poi-filter="{{ $groupKey }}">
                            <span class="material-symbols-outlined">{{ $grpIcon }}</span>{{ $grpLabel }}
                            <span class="sm-filter-ct">{{ count($items) }}</span>
                        </button>
                    @endforeach
                </div>
                @foreach($grouped as $groupKey => $items)
                    @php [$grpIcon, $grpLabel] = $groupLabels[$groupKey] ?? ['place', $groupKey]; @endphp
                    <div class="sm-grp-h" data-poi-group="{{ $groupKey }}">
                        <span class="material-symbols-outlined">{{ $grpIcon }}</span>{{ $grpLabel }}
                        <span class="sm-grp-ct">{{ count($items) }}</span>
                    </div>
                    @foreach($items as $p)
                        <div class="sm-item" data-poi-id="{{ $p['id'] }}" data-poi-group="{{ $groupKey }}">
                            <div class="sm-dot" style="background:{{ $p['color'] }}">
                                <span class="material-symbols-outlined">{{ $p['icon'] }}</span>
                            </div>
                            <div class="sm-meta">
                                <div class="sm-name">{{ $p['name'] }}</div>
                                <div class="sm-sub">{{ $p['gLabel'] }} · <b>{{ $p['dist'] }}m</b></div>
                            </div>
                        </div>
                    @endforeach
                @endforeach
            </div>
        @endif

        <div class="sm-map-wrap">
            <div id="{{ $poiBrowserId }}-map" class="sm-map"></div>
        </div>
    </div>

    <script>
    (function(){
        var initFn = function(){
            if (typeof L === 'undefined') { setTimeout(initFn, 50); return; }
            var mapEl = document.getElementById('{{ $poiBrowserId }}-map');
            if (!mapEl || mapEl._smInited) return;
            mapEl._smInited = true;

            var POIS  = @json($projected);
            var CENTER = { lat: {{ (float) $lat }}, lon: {{ (float) $lon }}, name: @json($screenName) };

            var map = L.map(mapEl, {
                center: [CENTER.lat, CENTER.lon],
                zoom: 17,
                zoomControl: true,
                scrollWheelZoom: true,
            });
            L.tileLayer('{{ config('map.tile_url') }}', {
                attribution: '{!! config('map.tile_attribution') !!}',
                maxZoom: {{ config('map.tile_max_zoom') }},
                subdomains: '{{ config('map.tile_subdomains') }}',
                key: '{{ config('map.tile_key') }}',
            }).addTo(map);

            // Center pin (screen location)
            // Note: escape `<` to `\x3c` — DOMDocument (Livewire root-detector) misparse `<div>` trong script content.
            // JS engine hiểu \x3c = '<', nhưng HTML parser không thấy tag → tránh false-positive multi-root.
            var centerIcon = L.divIcon({
                className: 'sm-center',
                html: '\x3cdiv class="sm-center-pulse">\x3c/div>'
                    + '\x3cdiv class="sm-center-inner">\x3cspan class="material-symbols-outlined">my_location\x3c/span>\x3c/div>',
                iconSize: [32, 32], iconAnchor: [16, 16],
            });
            L.marker([CENTER.lat, CENTER.lon], { icon: centerIcon, zIndexOffset: 1000 })
                .addTo(map);

            L.circle([CENTER.lat, CENTER.lon], {
                radius: 100,
                color: '#3B82F6',
                fillColor: '#93C5FD',
                fillOpacity: 0.15,
                weight: 1,
            }).addTo(map);

            // POI markers
            var markers = {};
            POIS.forEach(function(p){
                var icon = L.divIcon({
                    className: 'sm-pin',
                    html: '\x3cdiv class="sm-pin-inner" style="background:'+p.color+'">'
                        + '\x3cspan class="material-symbols-outlined">'+p.icon+'\x3c/span>\x3c/div>',
                    iconSize: [26, 26], iconAnchor: [13, 13],
                });
                var m = L.marker([p.lat, p.lon], { icon: icon, zIndexOffset: 100 })
                    .addTo(map)
                    .bindPopup(
                        '\x3cdiv style="font-weight:600;font-size:12px">'+p.name+'\x3c/div>'
                      + '\x3cdiv style="font-size:11px;color:#666;margin-top:2px">'+p.gLabel+' · '+p.dist+'m\x3c/div>'
                    );
                markers[p.id] = m;
            });

            // Wire click POI list → flyTo + popup
            var list = document.getElementById('{{ $poiBrowserId }}-list');
            if (list) {
                list.addEventListener('click', function(e){
                    var item = e.target.closest('.sm-item');
                    if (!item) return;
                    var id = item.getAttribute('data-poi-id');
                    var marker = markers[id];
                    if (!marker) return;
                    list.querySelectorAll('.sm-item.is-active').forEach(function(el){ el.classList.remove('is-active'); });
                    item.classList.add('is-active');
                    map.flyTo(marker.getLatLng(), 18, { duration: 0.6 });
                    setTimeout(function(){ marker.openPopup(); }, 650);
                });
            }

            // Filter pills: ẩn/hiện nhóm
            var filterEl = list ? list.querySelector('[data-poi-filters]') : null;
            if (filterEl) {
                var allGroups = Array.from(filterEl.querySelectorAll('[data-poi-filter]'))
                                     .map(function(b){ return b.getAttribute('data-poi-filter'); });
                var activeGroups = new Set(allGroups);

                function applyFilter(){
                    list.querySelectorAll('[data-poi-group]').forEach(function(el){
                        el.style.display = activeGroups.has(el.getAttribute('data-poi-group')) ? '' : 'none';
                    });
                    POIS.forEach(function(p){
                        var m = markers[p.id];
                        if (!m) return;
                        if (activeGroups.has(p.group)) {
                            if (!map.hasLayer(m)) map.addLayer(m);
                        } else {
                            if (map.hasLayer(m)) map.removeLayer(m);
                        }
                    });
                    var allBtn = filterEl.querySelector('[data-poi-filter-all]');
                    if (allBtn) allBtn.classList.toggle('is-active', activeGroups.size === allGroups.length);
                }

                filterEl.addEventListener('click', function(e){
                    var allBtn = e.target.closest('[data-poi-filter-all]');
                    if (allBtn) {
                        activeGroups = new Set(allGroups);
                        filterEl.querySelectorAll('[data-poi-filter]').forEach(function(p){ p.classList.add('is-active'); });
                        applyFilter();
                        return;
                    }
                    var pill = e.target.closest('[data-poi-filter]');
                    if (!pill) return;
                    var grp = pill.getAttribute('data-poi-filter');
                    if (activeGroups.has(grp)) { activeGroups.delete(grp); pill.classList.remove('is-active'); }
                    else { activeGroups.add(grp); pill.classList.add('is-active'); }
                    applyFilter();
                });
            }

            setTimeout(function(){ map.invalidateSize(); }, 100);
        };
        initFn();
    })();
    </script>
    </div>{{-- /sm-wrap (single root) --}}
@endif
