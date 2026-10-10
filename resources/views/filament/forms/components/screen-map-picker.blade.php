{{-- Map Picker for Screen form
     Reads lat/lon from the Screen's related Site.
     Updates site.lat / site.lon in Livewire form state.
     Search via Nominatim for location lookup.
--}}

@pushOnce('styles')
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
@endPushOnce
@once
    <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
@endonce

<div
    x-data="{
        map: null,
        marker: null,
        searchQuery: '',
        searching: false,
        searchError: '',
        suggestions: [],
        showSuggestions: false,
        _skipWatch: false,
        _debounceTimer: null,

        lat: null,
        lon: null,
        get hasCoords() { return this.lat !== null && this.lon !== null },

        init() {
            this.$nextTick(() => {
                this.lat = this._read('_site_lat') ?? this._read('lat');
                this.lon = this._read('_site_lon') ?? this._read('lon');

                if (typeof L === 'undefined') return;
                this.initMap();

                // Watch for manual input changes on lat/lon fields
                const latKey = this._exists('_site_lat') ? '_site_lat' : 'lat';
                const lonKey = this._exists('_site_lon') ? '_site_lon' : 'lon';

                this.$watch(() => this.$wire.get('data.' + latKey), (v) => {
                    if (this._skipWatch) { this._skipWatch = false; return; }
                    const n = (v !== null && v !== '') ? parseFloat(v) : null;
                    if (n !== this.lat) { this.lat = n; this.syncMarkerFromForm(); }
                });
                this.$watch(() => this.$wire.get('data.' + lonKey), (v) => {
                    if (this._skipWatch) return;
                    const n = (v !== null && v !== '') ? parseFloat(v) : null;
                    if (n !== this.lon) { this.lon = n; this.syncMarkerFromForm(); }
                });
            });
        },

        _read(key) {
            const v = this.$wire.get('data.' + key);
            return (v !== null && v !== '' && v !== undefined) ? parseFloat(v) : null;
        },
        _exists(key) {
            return this.$wire.get('data.' + key) !== undefined;
        },

        initMap() {
            delete L.Icon.Default.prototype._getIconUrl;
            L.Icon.Default.mergeOptions({
                iconUrl:       '/vendor/leaflet/images/marker-icon.png',
                iconRetinaUrl: '/vendor/leaflet/images/marker-icon-2x.png',
                shadowUrl:     '/vendor/leaflet/images/marker-shadow.png',
            });

            const lat = this.lat || 10.7769;
            const lon = this.lon || 106.7009;
            const zoom = this.hasCoords ? 15 : 6;

            this.map = L.map(this.$refs.mapEl, { zoomControl: true }).setView([lat, lon], zoom);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19,
            }).addTo(this.map);

            if (this.hasCoords) {
                this._placeMarker(this.lat, this.lon);
            }

            this.map.on('click', (e) => this.onMapClick(e.latlng));
        },

        onMapClick(latlng) {
            const lat = parseFloat(latlng.lat.toFixed(7));
            const lon = parseFloat(latlng.lng.toFixed(7));
            this._placeMarker(lat, lon);
            this._updateForm(lat, lon);
        },

        _placeMarker(lat, lon) {
            if (this.marker) {
                this.marker.setLatLng([lat, lon]);
            } else {
                this.marker = L.marker([lat, lon], { draggable: true }).addTo(this.map);
                this.marker.on('dragend', (e) => {
                    const p = e.target.getLatLng();
                    this._updateForm(parseFloat(p.lat.toFixed(7)), parseFloat(p.lng.toFixed(7)));
                });
            }
        },

        _updateForm(lat, lon) {
            this._skipWatch = true;
            this.lat = lat;
            this.lon = lon;

            // Determine which field keys exist in the form
            const latKey = this._exists('_site_lat') ? '_site_lat' : 'lat';
            const lonKey = this._exists('_site_lon') ? '_site_lon' : 'lon';

            this.$wire.set('data.' + latKey, String(lat));
            this.$wire.set('data.' + lonKey, String(lon));
        },

        syncMarkerFromForm() {
            if (!this.lat || !this.lon || !this.map) return;
            this._placeMarker(this.lat, this.lon);
            this.map.setView([this.lat, this.lon], Math.max(this.map.getZoom(), 14));
        },

        onSearchInput() {
            clearTimeout(this._debounceTimer);
            const q = this.searchQuery.trim();
            if (q.length < 3) {
                this.suggestions = [];
                this.showSuggestions = false;
                return;
            }
            this._debounceTimer = setTimeout(() => this.fetchSuggestions(q), 400);
        },

        async fetchSuggestions(q) {
            this.searching = true;
            this.searchError = '';
            try {
                const resp = await fetch('/geocode/search?q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!resp.ok) {
                    this.searchError = 'HTTP ' + resp.status;
                    return;
                }
                this.suggestions = await resp.json();
                this.showSuggestions = this.suggestions.length > 0;
            } catch (err) {
                this.searchError = err.message;
            } finally {
                this.searching = false;
            }
        },

        selectSuggestion(item) {
            const lat = parseFloat(parseFloat(item.lat).toFixed(7));
            const lon = parseFloat(parseFloat(item.lon).toFixed(7));
            this.searchQuery = item.display_name;
            this.suggestions = [];
            this.showSuggestions = false;
            this._placeMarker(lat, lon);
            this._updateForm(lat, lon);
            this.map.setView([lat, lon], 16);
            if (this.marker) {
                this.marker.bindPopup(item.display_name).openPopup();
            }
        },

        closeSuggestions() {
            setTimeout(() => { this.showSuggestions = false; }, 150);
        },
    }"
    x-init="init()"
    class="col-span-full fi-fo-field-wrp"
>
    {{-- Search Box --}}
    <div class="relative mb-2">
        <div class="relative flex gap-2">
            <div class="relative flex-1">
                <input
                    x-model="searchQuery"
                    @input="onSearchInput()"
                    @keydown.enter.prevent="fetchSuggestions(searchQuery.trim())"
                    @keydown.escape="showSuggestions = false"
                    @blur="closeSuggestions()"
                    type="text"
                    placeholder="Tim dia chi... (vi du: 105 Lac Long Quan, Ha Noi)"
                    autocomplete="off"
                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm
                           placeholder-gray-400
                           focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500
                           dark:border-gray-600 dark:bg-gray-800 dark:text-white dark:placeholder-gray-500"
                />

                <ul
                    x-show="showSuggestions"
                    x-transition
                    class="absolute left-0 right-0 top-full z-50 mt-1 max-h-60 overflow-y-auto rounded-lg border
                           border-gray-200 bg-white shadow-lg dark:border-gray-600 dark:bg-gray-800"
                >
                    <template x-for="(item, index) in suggestions" :key="index">
                        <li
                            @mousedown.prevent="selectSuggestion(item)"
                            class="cursor-pointer px-3 py-2 text-sm text-gray-700 hover:bg-primary-50 hover:text-primary-700
                                   dark:text-gray-200 dark:hover:bg-gray-700"
                            x-text="item.display_name"
                        ></li>
                    </template>
                </ul>
            </div>

            <button
                type="button"
                @click="fetchSuggestions(searchQuery.trim())"
                :disabled="searching || searchQuery.trim().length < 3"
                class="inline-flex items-center gap-1 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium
                       text-white shadow-sm hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <template x-if="!searching">
                    <x-heroicon-m-magnifying-glass class="w-4 h-4" />
                </template>
                <template x-if="searching">
                    <x-filament::loading-indicator class="w-4 h-4" />
                </template>
            </button>
        </div>

        <p x-show="searchError" x-text="searchError" class="mt-1 text-xs text-danger-600"></p>
    </div>

    {{-- Map --}}
    <div wire:ignore>
        <div
            x-ref="mapEl"
            style="height: 320px; width: 100%; border-radius: 8px; border: 1px solid #d1d5db; z-index: 0;"
        ></div>
    </div>

    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
        Bấm bản đồ hoặc kéo ghim để đặt toạ độ. Tìm kiếm dùng
        <a href="https://www.openstreetmap.org" target="_blank" class="underline">OpenStreetMap</a> Nominatim.
    </p>
</div>
