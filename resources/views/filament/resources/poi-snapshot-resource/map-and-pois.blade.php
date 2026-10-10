@php
    /** @var \App\Models\PoiSnapshot $record */
    $record = $getRecord();
    $centerLat = (float) $record->lat_key;
    $centerLon = (float) $record->lon_key;
    $rawPois   = is_array($record->pois) ? $record->pois : [];

    // Shared projection helper — same logic as frontpage detail
    $projected   = \App\Support\PoiProjection::project($rawPois, $centerLat, $centerLon);
    $grouped     = \App\Support\PoiProjection::groupByCategory($projected);
    $groupLabels = \App\Support\PoiProjection::GROUP_LABELS;
    $totalNamed  = count($projected);
    $totalRaw    = count($rawPois);
@endphp

{{-- Single root wrapper — Livewire requires exactly 1 root element per component.
     wire:ignore prevents Livewire diffing of map content khi page re-render. --}}
<div wire:ignore class="poi-snap-wrap">
{{-- Leaflet + Material Symbols (load once per page; harmless if duplicated) --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap">

<style>
.material-symbols-outlined{font-family:'Material Symbols Outlined';font-weight:normal;font-style:normal;line-height:1;letter-spacing:normal;text-transform:none;display:inline-block;white-space:nowrap;word-wrap:normal;direction:ltr;-webkit-font-feature-settings:'liga';-webkit-font-smoothing:antialiased;font-variation-settings:'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24}
.poi-browser{display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:16px;height:680px}
@media(max-width:1024px){.poi-browser{grid-template-columns:1fr;height:auto}.poi-browser-map{height:480px}}
.poi-browser-list{overflow-y:auto;border:1px solid rgb(229 231 235 / var(--tw-border-opacity, 1));border-radius:8px;background:#fff}
.dark .poi-browser-list{border-color:rgb(55 65 81);background:rgb(17 24 39)}
.poi-browser-summary{padding:10px 12px;border-bottom:1px solid rgb(229 231 235);font-size:12px;color:rgb(75 85 99);background:rgb(249 250 251);position:sticky;top:0;z-index:5}
.dark .poi-browser-summary{background:rgb(31 41 55);border-color:rgb(55 65 81);color:rgb(156 163 175)}
.poi-browser-grp-h{padding:8px 12px;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.4px;background:rgb(243 244 246);color:rgb(55 65 81);border-top:1px solid rgb(229 231 235);position:sticky;top:36px;z-index:4}
.dark .poi-browser-grp-h{background:rgb(31 41 55);color:rgb(209 213 219);border-color:rgb(55 65 81)}
.poi-browser-grp-h .ct{float:right;font-size:11px;background:rgb(229 231 235);color:rgb(55 65 81);padding:1px 6px;border-radius:980px;font-weight:600;letter-spacing:0}
.dark .poi-browser-grp-h .ct{background:rgb(55 65 81);color:rgb(229 231 235)}
.poi-browser-item{display:flex;align-items:center;gap:10px;padding:8px 12px;cursor:pointer;border-top:1px solid rgb(243 244 246);transition:background 120ms}
.dark .poi-browser-item{border-color:rgb(55 65 81)}
.poi-browser-item:hover{background:rgb(239 246 255)}
.dark .poi-browser-item:hover{background:rgb(30 58 138 / .25)}
.poi-browser-item.is-active{background:rgb(219 234 254);box-shadow:inset 3px 0 0 rgb(37 99 235)}
.dark .poi-browser-item.is-active{background:rgb(30 58 138 / .4);box-shadow:inset 3px 0 0 rgb(96 165 250)}
.poi-browser-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0;border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.poi-browser-dot .material-symbols-outlined{font-size:16px}
.poi-browser-grp-h .material-symbols-outlined{font-size:16px;vertical-align:-3px;margin-right:6px;color:rgb(75 85 99)}
.dark .poi-browser-grp-h .material-symbols-outlined{color:rgb(156 163 175)}
.poi-browser-meta{min-width:0;flex:1}
.poi-browser-name{font-weight:600;font-size:13px;color:rgb(17 24 39);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dark .poi-browser-name{color:rgb(243 244 246)}
.poi-browser-sub{font-size:11px;color:rgb(107 114 128);display:flex;align-items:center;gap:6px;margin-top:1px}
.poi-browser-sub b{color:rgb(37 99 235);font-weight:600}
.dark .poi-browser-sub b{color:rgb(96 165 250)}
.poi-browser-empty{padding:40px 20px;text-align:center;color:rgb(107 114 128);font-size:13px}
.poi-browser-filter{display:flex;flex-wrap:wrap;gap:6px;padding:10px 12px;border-bottom:1px solid rgb(229 231 235);background:#fff;position:sticky;top:36px;z-index:5}
.dark .poi-browser-filter{background:rgb(17 24 39);border-color:rgb(55 65 81)}
.poi-browser-filter-pill{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;font-size:11px;font-weight:600;color:rgb(75 85 99);background:#fff;border:1px solid rgb(229 231 235);border-radius:980px;cursor:pointer;transition:all 140ms;line-height:1;font-family:inherit}
.dark .poi-browser-filter-pill{background:rgb(17 24 39);border-color:rgb(55 65 81);color:rgb(156 163 175)}
.poi-browser-filter-pill .material-symbols-outlined{font-size:14px;color:inherit;vertical-align:0;margin-right:0}
.poi-browser-filter-pill .poi-browser-filter-ct{margin-left:2px;font-size:10px;background:rgb(243 244 246);color:rgb(75 85 99);padding:1px 6px;border-radius:980px;font-weight:700;letter-spacing:0;border:1px solid rgb(229 231 235)}
.dark .poi-browser-filter-pill .poi-browser-filter-ct{background:rgb(31 41 55);color:rgb(156 163 175);border-color:rgb(55 65 81)}
.poi-browser-filter-pill:hover{border-color:rgb(37 99 235);color:rgb(37 99 235)}
.poi-browser-filter-pill.is-active{background:rgb(37 99 235);color:#fff;border-color:rgb(37 99 235)}
.poi-browser-filter-pill.is-active .poi-browser-filter-ct{background:rgba(255,255,255,.25);color:#fff;border-color:transparent}
.poi-browser-map{width:100%;height:100%;border-radius:8px;border:1px solid rgb(229 231 235);background:#f3f4f6;overflow:hidden}
.dark .poi-browser-map{border-color:rgb(55 65 81)}
.psm-pin{background:none;border:none}
.psm-pin-inner{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,.25);cursor:pointer;transition:transform 160ms}
.psm-pin-inner .material-symbols-outlined{font-size:16px}
.psm-pin-inner:hover{transform:scale(1.3);z-index:1000}
.psm-center{background:none;border:none;position:relative}
.psm-center-inner{position:absolute;top:0;left:0;width:36px;height:36px;border-radius:50%;background:rgb(37 99 235);color:#fff;display:flex;align-items:center;justify-content:center;border:3px solid #fff;box-shadow:0 4px 12px rgba(37,99,235,.4);z-index:2}
.psm-center-inner .material-symbols-outlined{font-size:20px}
.psm-center-pulse{position:absolute;top:-4px;left:-4px;width:44px;height:44px;border-radius:50%;background:rgb(37 99 235 / .25);animation:psm-pulse 2s ease-in-out infinite;z-index:1}
@keyframes psm-pulse{0%,100%{transform:scale(1);opacity:.6}50%{transform:scale(1.4);opacity:.1}}
</style>

<div class="poi-browser">
    {{-- ── LEFT: grouped list ─────────────────────────────────────────── --}}
    <div class="poi-browser-list" id="poi-browser-list">
        <div class="poi-browser-summary">
            <strong>{{ $totalNamed }}</strong> POIs có tên / {{ $totalRaw }} thô ·
            <strong>{{ count($grouped) }}</strong> nhóm
        </div>

        @if($totalNamed === 0)
            <div class="poi-browser-empty">
                Snapshot không có POI nào có tên (raw: {{ $totalRaw }}).
            </div>
        @endif

        @if($totalNamed > 0)
            <div class="poi-browser-filter" data-poi-filters>
                <button type="button" class="poi-browser-filter-pill is-active" data-poi-filter-all>
                    <span class="material-symbols-outlined">apps</span>Tất cả
                    <span class="poi-browser-filter-ct">{{ $totalNamed }}</span>
                </button>
                @foreach($grouped as $groupKey => $items)
                    @php [$grpIcon, $grpLabel] = $groupLabels[$groupKey] ?? ['place', $groupKey]; @endphp
                    <button type="button" class="poi-browser-filter-pill is-active" data-poi-filter="{{ $groupKey }}">
                        <span class="material-symbols-outlined">{{ $grpIcon }}</span>{{ $grpLabel }}
                        <span class="poi-browser-filter-ct">{{ count($items) }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        @foreach($grouped as $groupKey => $items)
            @php
                [$grpIcon, $grpLabel] = $groupLabels[$groupKey] ?? ['place', $groupKey];
            @endphp
            <div class="poi-browser-grp-h" data-poi-group="{{ $groupKey }}">
                <span class="material-symbols-outlined">{{ $grpIcon }}</span>{{ $grpLabel }}
                <span class="ct">{{ count($items) }}</span>
            </div>
            @foreach($items as $p)
                <div class="poi-browser-item" data-poi-id="{{ $p['id'] }}" data-poi-group="{{ $groupKey }}">
                    <div class="poi-browser-dot" style="background:{{ $p['color'] }}">
                        <span class="material-symbols-outlined">{{ $p['icon'] }}</span>
                    </div>
                    <div class="poi-browser-meta">
                        <div class="poi-browser-name">{{ $p['name'] }}</div>
                        <div class="poi-browser-sub">
                            <span>{{ $p['gLabel'] }}</span>
                            <span>·</span>
                            <b>{{ $p['dist'] }}m</b>
                        </div>
                    </div>
                </div>
            @endforeach
        @endforeach
    </div>

    {{-- ── RIGHT: map ─────────────────────────────────────────────────── --}}
    <div>
        <div id="poi-snap-map" class="poi-browser-map"></div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
(function(){
    var POIS = @json($projected);
    var CENTER = { lat: {{ $centerLat }}, lon: {{ $centerLon }}, radius: {{ (int) $record->radius }} };
    var mapEl = document.getElementById('poi-snap-map');
    if (!mapEl || typeof L === 'undefined') return;

    // Avoid double-init nếu Livewire re-render
    if (mapEl._psmInited) return;
    mapEl._psmInited = true;

    var map = L.map(mapEl, {
        center: [CENTER.lat, CENTER.lon],
        zoom: 17,
        zoomControl: true,
        scrollWheelZoom: true,
        preferCanvas: true,
    });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap',
        maxZoom: 19,
    }).addTo(map);

    // Center pin (snapshot location)
    // Note: escape `<` to `\x3c` trong tất cả HTML strings — DOMDocument (Livewire root-detector)
    // mis-parse `<div>` trong script content → tách thành multi-root false-positive.
    // JS engine hiểu \x3c = '<', nhưng HTML parser không thấy tag.
    var centerIcon = L.divIcon({
        className: 'psm-center',
        html: '\x3cdiv class="psm-center-pulse">\x3c/div>'
            + '\x3cdiv class="psm-center-inner">\x3cspan class="material-symbols-outlined">my_location\x3c/span>\x3c/div>',
        iconSize: [36, 36], iconAnchor: [18, 18],
    });
    L.marker([CENTER.lat, CENTER.lon], { icon: centerIcon, zIndexOffset: 1000 })
        .addTo(map)
        .bindPopup('\x3cdiv style="font-weight:700;font-size:13px">Tâm snapshot\x3c/div>'
                 + '\x3cdiv style="font-size:11px;color:#888">Bán kính ' + CENTER.radius + 'm\x3c/div>');

    // Radius circle for visual context
    L.circle([CENTER.lat, CENTER.lon], {
        radius: CENTER.radius,
        color: '#2563eb',
        weight: 1,
        fillOpacity: 0.04,
        dashArray: '4 4',
    }).addTo(map);

    // POI markers — store handles by id để click list pan/popup
    var markers = {};
    POIS.forEach(function(p){
        var icon = L.divIcon({
            className: 'psm-pin',
            html: '\x3cdiv class="psm-pin-inner" style="background:'+p.color+'">'
                + '\x3cspan class="material-symbols-outlined">'+p.icon+'\x3c/span>\x3c/div>',
            iconSize: [28, 28], iconAnchor: [14, 14],
        });
        var m = L.marker([p.lat, p.lon], { icon: icon, zIndexOffset: 100 })
            .addTo(map)
            .bindPopup(
                '\x3cdiv style="font-weight:600;font-size:13px">'+p.name+'\x3c/div>'
              + '\x3cdiv style="font-size:11px;color:#666;margin-top:2px">'+p.gLabel+' · '+p.dist+'m từ tâm\x3c/div>'
            );
        markers[p.id] = m;
    });

    // Wire click on left list → pan + popup
    var list = document.getElementById('poi-browser-list');
    if (list) {
        list.addEventListener('click', function(e){
            var item = e.target.closest('.poi-browser-item');
            if (!item) return;
            var id = item.getAttribute('data-poi-id');
            var m = markers[id];
            if (!m) return;

            // Highlight active row
            list.querySelectorAll('.poi-browser-item.is-active').forEach(function(el){ el.classList.remove('is-active'); });
            item.classList.add('is-active');

            map.flyTo(m.getLatLng(), 18, { duration: 0.6 });
            setTimeout(function(){ m.openPopup(); }, 650);
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

    // Fix tile load on hidden container
    setTimeout(function(){ map.invalidateSize(); }, 100);
})();
</script>
</div>{{-- /poi-snap-wrap --}}
