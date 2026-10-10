@php
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
        'active'        => (bool) $s->active,
        'view_url'      => \App\Filament\Resources\ScreenResource::getUrl('view', ['record' => $s->id]),
    ])->values()->all();

    $siteOpts = $screens
        ->filter(fn($s) => $s->site_id && $s->site)
        ->mapWithKeys(fn($s) => [(string) $s->site_id => $s->site->name])
        ->sortBy(fn($v) => $v)->all();

    $provinceOpts = $screens
        ->filter(fn($s) => $s->site?->province_id)
        ->mapWithKeys(fn($s) => [(string) $s->site->province_id => $s->site->province?->name ?? '?'])
        ->filter()->sortBy(fn($v) => $v)->all();
@endphp

{{--
    Pure vanilla JS — no Alpine for the map component.
    Avoids Alpine.data() timing race with Livewire's script injection order.
    All DOM references use IDs scoped by $mapKey.
    @script ensures Livewire re-evaluates this on every morphdom update,
    which is needed because Filament pre-renders modal content hidden on page load.
--}}
@script
<script>
(function () {
    var KEY       = @json($mapKey);
    var SCREENS   = @json($screensData);
    var SITE_OPTS = @json($siteOpts);
    var PROV_OPTS = @json($provinceOpts);

    var filterSite = '';
    var filterProv = '';
    var mapInst    = null;
    var markerLyr  = null;

    function filtered() {
        return SCREENS.filter(function (s) {
            if (filterSite && s.site_id !== filterSite) return false;
            if (filterProv && s.province_id !== filterProv) return false;
            return true;
        });
    }

    function el(suffix) { return document.getElementById(KEY + suffix); }

    function updateCount() {
        var list = filtered();
        var withCoords = list.filter(function (s) { return s.site_lat && s.site_lon; }).length;
        var cw = el('_gw'); if (cw) cw.textContent = withCoords;
        var ct = el('_gt'); if (ct) ct.textContent = list.length;
    }

    function updateClearBtn() {
        var btn = el('_clear');
        if (btn) btn.style.display = (filterSite || filterProv) ? '' : 'none';
    }

    function refreshMarkers() {
        if (!mapInst || !markerLyr) return;
        markerLyr.clearLayers();
        var sites = {};
        filtered().forEach(function (s) {
            if (!s.site_lat || !s.site_lon) return;
            if (!sites[s.site_id]) {
                sites[s.site_id] = { lat: s.site_lat, lon: s.site_lon, name: s.site_name, screens: [] };
            }
            sites[s.site_id].screens.push(s);
        });
        var bounds = [];
        Object.values(sites).forEach(function (site) {
            var rows = site.screens.map(function (s) {
                var bg    = s.active ? '#dcfce7' : '#fee2e2';
                var color = s.active ? '#166534' : '#991b1b';
                var label = s.active ? 'Active' : 'Inactive';
                return '<tr>' +
                    '<td style="padding:2px 6px;font-family:monospace;font-size:11px">' + s.external_id + '</td>' +
                    '<td style="padding:2px 6px;font-size:12px"><a href="' + s.view_url + '" target="_blank" style="color:#3b82f6">' + s.name + '</a></td>' +
                    '<td style="padding:2px 6px"><span style="padding:1px 6px;border-radius:9999px;font-size:11px;background:' + bg + ';color:' + color + '">' + label + '</span></td>' +
                    '</tr>';
            }).join('');
            var popup = '<div style="min-width:280px">' +
                '<b style="font-size:13px">' + site.name + '</b>' +
                '<div style="font-size:11px;color:#6b7280;margin-bottom:6px">' + site.screens.length + ' màn hình</div>' +
                '<table style="width:100%;border-collapse:collapse">' +
                '<thead><tr style="background:#f3f4f6">' +
                '<th style="padding:2px 6px;text-align:left;font-size:11px">ID</th>' +
                '<th style="padding:2px 6px;text-align:left;font-size:11px">Tên</th>' +
                '<th style="padding:2px 6px;text-align:left;font-size:11px">Trạng thái</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table></div>';
            L.marker([site.lat, site.lon]).addTo(markerLyr).bindPopup(popup, { maxWidth: 360 });
            bounds.push([site.lat, site.lon]);
        });
        if (bounds.length === 1)    mapInst.setView(bounds[0], 15);
        else if (bounds.length > 1) mapInst.fitBounds(bounds, { padding: [40, 40] });
        mapInst.invalidateSize();
        updateCount();
    }

    function initMap() {
        var mapEl = el('_map');
        if (!mapEl || !window.L) return;
        // Remove any Leaflet instance left by a previous IIFE (modal close → reopen).
        // mapInst is local to this IIFE scope so it won't catch the prior run's instance;
        // we persist the reference on the DOM element instead.
        if (mapEl._mapInstance) {
            try { mapEl._mapInstance.remove(); } catch (e) {}
            mapEl._mapInstance = null;
        }
        if (mapInst) { try { mapInst.remove(); } catch (e) {} mapInst = null; }
        delete L.Icon.Default.prototype._getIconUrl;
        L.Icon.Default.mergeOptions({
            iconUrl:       '/vendor/leaflet/images/marker-icon.png',
            iconRetinaUrl: '/vendor/leaflet/images/marker-icon-2x.png',
            shadowUrl:     '/vendor/leaflet/images/marker-shadow.png',
        });
        var map = L.map(mapEl).setView([16.0, 106.0], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '\u00a9 OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);
        mapInst        = map;
        mapEl._mapInstance = map;   // persist for cross-IIFE cleanup on next open
        markerLyr = L.layerGroup().addTo(map);
        refreshMarkers();
        setTimeout(function () { map.invalidateSize(); }, 100);
    }

    function loadLeaflet(cb) {
        if (window.L) { cb(); return; }
        var link = document.createElement('link');
        link.rel  = 'stylesheet';
        link.href = '/vendor/leaflet/leaflet.css';
        document.head.appendChild(link);
        var js = document.createElement('script');
        js.src    = '/vendor/leaflet/leaflet.js';
        js.onload = cb;
        document.head.appendChild(js);
    }

    function start() {
        // Populate province filter
        var provSel = el('_prov');
        if (provSel) {
            Object.entries(PROV_OPTS).forEach(function (pair) {
                var opt = document.createElement('option');
                opt.value = pair[0]; opt.textContent = pair[1];
                provSel.appendChild(opt);
            });
            provSel.addEventListener('change', function () {
                filterProv = this.value;
                filterSite = '';
                var s = el('_site'); if (s) s.value = '';
                refreshMarkers(); updateClearBtn();
            });
        }

        // Populate site filter
        var siteSel = el('_site');
        if (siteSel) {
            Object.entries(SITE_OPTS).forEach(function (pair) {
                var opt = document.createElement('option');
                opt.value = pair[0]; opt.textContent = pair[1];
                siteSel.appendChild(opt);
            });
            siteSel.addEventListener('change', function () {
                filterSite = this.value;
                refreshMarkers(); updateClearBtn();
            });
        }

        // Clear button
        var clearBtn = el('_clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                filterSite = ''; filterProv = '';
                if (siteSel) siteSel.value = '';
                if (provSel) provSel.value = '';
                refreshMarkers(); updateClearBtn();
            });
        }

        updateCount();
        updateClearBtn();

        // Already waited for offsetWidth > 0; a short extra tick lets the
        // slide-over finish any remaining CSS transition before Leaflet measures.
        loadLeaflet(function () { setTimeout(initMap, 100); });
    }

    // Poll until the map element is in the DOM AND visible (offsetWidth > 0).
    // Filament pre-renders modal content hidden; we must wait for the slide-over
    // animation to make the container measurable before Leaflet can init.
    (function waitAndStart() {
        var mapEl = el('_map');
        if (mapEl && mapEl.offsetWidth > 0) { start(); }
        else                                { setTimeout(waitAndStart, 50); }
    }());
}());
</script>
@endscript

<div class="space-y-3 -mx-6 -mb-6">

    {{-- Filter bar --}}
    <div class="flex flex-wrap items-center gap-3 px-6 pt-1">

        {{-- Province filter --}}
        <div class="flex items-center gap-2">
            <label class="text-xs font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap">Tỉnh/Thành:</label>
            <div class="fi-input-wrp flex rounded-lg shadow-sm ring-1 transition duration-75 bg-white dark:bg-white/5 ring-gray-950/10 dark:ring-white/20">
                <select
                    id="{{ $mapKey }}_prov"
                    class="fi-select-input block border-none bg-transparent py-1 ps-2.5 pe-7 text-sm text-gray-950 transition duration-75 focus:ring-0 dark:text-white [&_option]:bg-white [&_option]:dark:bg-gray-900"
                >
                    <option value="">Tất cả tỉnh</option>
                </select>
            </div>
        </div>

        {{-- Site filter --}}
        <div class="flex items-center gap-2">
            <label class="text-xs font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap">Địa điểm:</label>
            <div class="fi-input-wrp flex rounded-lg shadow-sm ring-1 transition duration-75 bg-white dark:bg-white/5 ring-gray-950/10 dark:ring-white/20">
                <select
                    id="{{ $mapKey }}_site"
                    class="fi-select-input block border-none bg-transparent py-1 ps-2.5 pe-7 text-sm text-gray-950 transition duration-75 focus:ring-0 dark:text-white [&_option]:bg-white [&_option]:dark:bg-gray-900"
                >
                    <option value="">Tất cả site</option>
                </select>
            </div>
        </div>

        {{-- Clear button --}}
        <button
            id="{{ $mapKey }}_clear"
            type="button"
            style="display:none"
            class="text-xs font-semibold text-danger-600 hover:text-danger-500 dark:text-danger-400 transition duration-75"
        >
            Xoá bộ lọc
        </button>

        {{-- GPS count --}}
        <span class="ms-auto text-xs text-gray-400 dark:text-gray-500">
            <span id="{{ $mapKey }}_gw">0</span> / <span id="{{ $mapKey }}_gt">0</span> màn hình có tọa độ GPS
        </span>
    </div>

    {{-- Map container — wire:ignore prevents Livewire from clobbering Leaflet DOM --}}
    <div wire:ignore>
        <div id="{{ $mapKey }}_map" style="height:560px;width:100%;z-index:0;"></div>
    </div>

    {{-- Footer --}}
    <p class="px-6 pb-3 text-xs text-gray-400 dark:text-gray-500 text-center">
        Nhấn vào marker để xem danh sách màn hình tại site đó.
    </p>

</div>
