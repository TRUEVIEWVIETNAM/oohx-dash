@php
    /** @var array $features */
    $features = $getRecord()->features ?? [];
    $total      = $features['total_pois'] ?? 0;
    $categories = $features['categories'] ?? [];
    $named      = $features['named'] ?? [];
@endphp

<div class="fi-section-content space-y-4">
    <div class="text-sm text-gray-700 dark:text-gray-300">
        <span class="font-semibold">{{ number_format($total) }}</span> POIs được aggregate vào
        <span class="font-semibold">{{ count($categories) }}</span> danh mục,
        <span class="font-semibold">{{ count($named) }}</span> có tên.
    </div>

    @if($categories)
    <div>
        <div class="text-xs font-semibold uppercase text-gray-500 mb-2">Danh mục</div>
        <div class="flex flex-wrap gap-2">
            @foreach($categories as $cat => $count)
                <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 dark:bg-gray-800 px-2 py-1 text-xs">
                    <span class="font-medium">{{ $cat }}</span>
                    <span class="rounded bg-primary-100 dark:bg-primary-900 text-primary-700 dark:text-primary-300 px-1.5 py-0.5 font-semibold">{{ $count }}</span>
                </span>
            @endforeach
        </div>
    </div>
    @endif

    @if($named)
    <div>
        <div class="text-xs font-semibold uppercase text-gray-500 mb-2">Nhiều nhất {{ min(20, count($named)) }} Named POIs (gần nhất)</div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800/50 text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-3 py-2 text-left">Tên</th>
                        <th class="px-3 py-2 text-left">Danh mục</th>
                        <th class="px-3 py-2 text-left">Thẻ</th>
                        <th class="px-3 py-2 text-right">Khoảng cách</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach(array_slice($named, 0, 20) as $p)
                    <tr>
                        <td class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100">{{ $p['name'] ?? '' }}</td>
                        <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $p['category'] ?? '' }}</td>
                        <td class="px-3 py-2 text-gray-500 text-xs">{{ $p['tag'] ?? '' }}</td>
                        <td class="px-3 py-2 text-right text-gray-600 dark:text-gray-400">
                            {{ isset($p['dist_m']) ? $p['dist_m'].'m' : '—' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
