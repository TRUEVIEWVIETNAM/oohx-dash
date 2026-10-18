<div
    x-data="{
        lat: $wire.data?.headquarters_lat || 21.0285,
        lng: $wire.data?.headquarters_lng || 105.8542,
        map: null,
        marker: null,
        searchQuery: '',

        init() {
            this.$nextTick(() => this.initMap());

            // Watch for Livewire state changes (e.g. user types lat/lng manually)
            $watch('$wire.data.headquarters_lat', (val) => {
                if (val && this.marker) {
                    this.lat = parseFloat(val);
                    this.updateMarker();
                }
            });
            $watch('$wire.data.headquarters_lng', (val) => {
                if (val && this.marker) {
                    this.lng = parseFloat(val);
                    this.updateMarker();
                }
            });
        },

        initMap() {
            if (this.map) return;
            this.map = L.map(this.$refs.mapEl, {
                center: [this.lat, this.lng],
                zoom: 14,
                zoomControl: true,
            });
            L.tileLayer('{{ config('map.tile_url') }}', {
                attribution: '{!! config('map.tile_attribution') !!}',
                maxZoom: {{ config('map.tile_max_zoom') }},
                subdomains: '{{ config('map.tile_subdomains') }}',
                key: '{{ config('map.tile_key') }}',
            }).addTo(this.map);

            this.marker = L.marker([this.lat, this.lng], { draggable: true }).addTo(this.map);

            // Drag marker → update lat/lng
            this.marker.on('dragend', () => {
                var pos = this.marker.getLatLng();
                this.lat = parseFloat(pos.lat.toFixed(7));
                this.lng = parseFloat(pos.lng.toFixed(7));
                $wire.set('data.headquarters_lat', this.lat);
                $wire.set('data.headquarters_lng', this.lng);
            });

            // Click map → move marker
            this.map.on('click', (e) => {
                this.lat = parseFloat(e.latlng.lat.toFixed(7));
                this.lng = parseFloat(e.latlng.lng.toFixed(7));
                this.marker.setLatLng([this.lat, this.lng]);
                $wire.set('data.headquarters_lat', this.lat);
                $wire.set('data.headquarters_lng', this.lng);
            });

            // Fix map render in hidden tab
            setTimeout(() => this.map.invalidateSize(), 300);
        },

        updateMarker() {
            if (!this.map || !this.marker) return;
            this.marker.setLatLng([this.lat, this.lng]);
            this.map.panTo([this.lat, this.lng]);
        },

        async searchAddress() {
            if (!this.searchQuery.trim()) return;
            try {
                var res = await fetch('https://nominatim.openstreetmap.org/search?format=json&q=' + encodeURIComponent(this.searchQuery + ', Việt Nam') + '&limit=1');
                var data = await res.json();
                if (data.length > 0) {
                    this.lat = parseFloat(parseFloat(data[0].lat).toFixed(7));
                    this.lng = parseFloat(parseFloat(data[0].lon).toFixed(7));
                    this.marker.setLatLng([this.lat, this.lng]);
                    this.map.setView([this.lat, this.lng], 16);
                    $wire.set('data.headquarters_lat', this.lat);
                    $wire.set('data.headquarters_lng', this.lng);
                }
            } catch (e) {
                console.warn('Geocode error:', e);
            }
        },
    }"
    x-init="init()"
    wire:ignore
>
    {{-- Search bar --}}
    <div style="display:flex;gap:6px;margin-bottom:10px">
        <input
            type="text"
            x-model="searchQuery"
            @keydown.enter.prevent="searchAddress()"
            placeholder="Tìm địa chỉ trên bản đồ..."
            style="flex:1;height:36px;padding:0 12px;border-radius:8px;border:1.5px solid #e5e5ea;font-size:13px;outline:none"
        >
        <button
            type="button"
            @click="searchAddress()"
            style="height:36px;padding:0 14px;border-radius:8px;background:#2A4FF6;color:#fff;border:none;font-size:13px;font-weight:600;cursor:pointer"
        >
            Tìm
        </button>
    </div>

    {{-- Map --}}
    <div x-ref="mapEl" style="width:100%;height:300px;border-radius:12px;border:1px solid #e5e5ea;overflow:hidden"></div>

    <div style="margin-top:8px;font-size:11px;color:#8e8e93">
        Click vào bản đồ hoặc kéo pin để chọn vị trí trụ sở. Tìm kiếm bằng tên đường, tòa nhà...
    </div>
</div>

@once
@push('scripts')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
@endpush
@endonce
