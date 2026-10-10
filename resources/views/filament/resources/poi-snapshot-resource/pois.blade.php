@php
    /** @var array $pois */
    $pois = $getRecord()->pois ?? [];
    $named = array_filter($pois, fn ($p) => ! empty($p['tags']['name'] ?? $p['tags']['name:vi'] ?? null));
    $unnamed = count($pois) - count($named);
@endphp

<div class="fi-section-content space-y-3">
    <div class="text-sm text-gray-600 dark:text-gray-400">
        Tổng <span class="font-semibold">{{ count($pois) }}</span> raw POIs từ Overpass —
        {{ count($named) }} có tên, {{ $unnamed }} không tên.
    </div>

    <div class="overflow-x-auto max-h-[600px] overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-xs uppercase text-gray-500 sticky top-0">
                <tr>
                    <th class="px-3 py-2 text-left">Loại</th>
                    <th class="px-3 py-2 text-left">Tên</th>
                    <th class="px-3 py-2 text-left">Thẻ</th>
                    <th class="px-3 py-2 text-right">Vĩ độ / Kinh độ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($pois as $p)
                    @php
                        $tags = $p['tags'] ?? [];
                        $name = $tags['name'] ?? $tags['name:vi'] ?? '—';
                        $primary = $tags['amenity'] ?? $tags['shop'] ?? $tags['leisure']
                            ?? $tags['tourism'] ?? $tags['office'] ?? $tags['public_transport']
                            ?? $tags['building'] ?? '—';
                        $lat = $p['lat'] ?? ($p['center']['lat'] ?? null);
                        $lon = $p['lon'] ?? ($p['center']['lon'] ?? null);
                        $type = $p['type'] ?? '—';
                    @endphp
                    <tr>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $type }}</td>
                        <td class="px-3 py-2 {{ $name === '—' ? 'text-gray-400 italic' : 'text-gray-900 dark:text-gray-100 font-medium' }}">{{ $name }}</td>
                        <td class="px-3 py-2 text-xs">
                            <span class="inline-block rounded bg-gray-100 dark:bg-gray-800 px-1.5 py-0.5">{{ $primary }}</span>
                        </td>
                        <td class="px-3 py-2 text-right text-xs text-gray-500 font-mono">
                            {{ $lat ? number_format((float)$lat, 5) : '—' }}, {{ $lon ? number_format((float)$lon, 5) : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
