@php
    /** @var \App\Models\Oohx\Config\FormulaVersion $record */
    $record = $getRecord();
    $snap = $record->snapshot ?? [];

    $groupLabels = [
        'base_city_traffic'      => ['City baseline traffic',     'building-office-2'],
        'road_class_multipliers' => ['Road class multipliers',    'truck'],
        'zone_factors'           => ['Zone factors',              'map-pin'],
        'delivery_defaults'      => ['Delivery defaults',         'adjustments-horizontal'],
    ];
@endphp

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    @foreach($groupLabels as $groupKey => [$label, $icon])
        @php $entries = (array) ($snap[$groupKey] ?? []); @endphp
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-2 bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2">
                <x-filament::icon :icon="'heroicon-o-' . $icon" class="h-4 w-4 text-gray-500" />
                <span class="font-semibold text-sm text-gray-900 dark:text-gray-100">{{ $label }}</span>
                <span class="ml-auto text-xs text-gray-500">{{ count($entries) }} mục</span>
            </div>
            @if(empty($entries))
                <div class="p-4 text-sm text-gray-500 italic">— trống —</div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800/30 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-2 text-left">Khoá</th>
                                <th class="px-4 py-2 text-right">Giá trị</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach($entries as $key => $value)
                                <tr>
                                    <td class="px-4 py-2 font-medium text-gray-900 dark:text-gray-100">{{ $key }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-gray-700 dark:text-gray-300">
                                        {{ is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') : (string) $value }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach
</div>
