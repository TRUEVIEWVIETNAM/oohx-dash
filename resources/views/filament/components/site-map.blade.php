@php
    $record = $getRecord();
    $lat = $record->lat ?? null;
    $lon = $record->lon ?? null;
    $siteName = e($record->name ?? 'Site');
    $hasLocation = $lat && $lon;
@endphp

@if($hasLocation)
    @pushOnce('styles')
        <link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}" />
    @endPushOnce
    @once
        <script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
    @endonce

    <div wire:ignore
         x-data="{
             initMap() {
                 const el = this.$el;
                 if (el._leaflet_id) return;

                 delete L.Icon.Default.prototype._getIconUrl;
                 L.Icon.Default.mergeOptions({
                     iconUrl:       '/vendor/leaflet/images/marker-icon.png',
                     iconRetinaUrl: '/vendor/leaflet/images/marker-icon-2x.png',
                     shadowUrl:     '/vendor/leaflet/images/marker-shadow.png',
                 });

                 const map = L.map(el).setView([{{ $lat }}, {{ $lon }}], 16);
                 L.tileLayer('{{ config('map.tile_url') }}', {
                     attribution: '{!! config('map.tile_attribution') !!}',
                     maxZoom: {{ config('map.tile_max_zoom') }},
                     subdomains: '{{ config('map.tile_subdomains') }}',
                 }).addTo(map);
                 L.marker([{{ $lat }}, {{ $lon }}])
                     .addTo(map)
                     .bindPopup('{{ $siteName }}')
                     .openPopup();
             }
         }"
         x-init="initMap()"
         style="height:320px;width:100%;z-index:0;border-radius:0.5rem;overflow:hidden;">
    </div>
@else
    <p class="text-sm italic text-gray-400">Không có tọa độ GPS.</p>
@endif
