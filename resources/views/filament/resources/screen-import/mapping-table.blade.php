@php
    /** @var array $headers */
    /** @var array $mapping */
    /** @var array $sampleRows */
    use App\Services\ScreenImport\FieldCatalog;

    $fieldLabels = FieldCatalog::flatOptions();
@endphp

<div class="overflow-x-auto">
    <table class="w-full text-sm border-collapse">
        <thead class="bg-gray-50 dark:bg-gray-800">
            <tr>
                <th class="p-2 text-left font-semibold border-b dark:border-gray-700">#</th>
                <th class="p-2 text-left font-semibold border-b dark:border-gray-700">Cột trong tệp</th>
                <th class="p-2 text-left font-semibold border-b dark:border-gray-700">Giá trị mẫu</th>
                <th class="p-2 text-left font-semibold border-b dark:border-gray-700">→ trường CSDL</th>
                <th class="p-2 text-left font-semibold border-b dark:border-gray-700">Độ tin cậy</th>
                <th class="p-2 text-left font-semibold border-b dark:border-gray-700">Lý do</th>
            </tr>
        </thead>
        <tbody>
            @foreach($headers as $idx => $header)
                @php
                    $m = $mapping[$idx] ?? [];
                    $field = $m['field'] ?? null;
                    $compound = $m['compound'] ?? null;
                    $confidence = $m['confidence'] ?? null;
                    $reason = $m['reason'] ?? '';

                    $samples = [];
                    foreach (($sampleRows ?? []) as $row) {
                        $v = $row[$idx] ?? null;
                        if ($v !== null && trim((string) $v) !== '') {
                            $samples[] = mb_substr((string) $v, 0, 30);
                            if (count($samples) >= 3) break;
                        }
                    }

                    $confColor = match (true) {
                        $confidence === null        => 'text-gray-400',
                        $confidence >= 0.85         => 'text-green-600 dark:text-green-400',
                        $confidence >= 0.60         => 'text-yellow-600 dark:text-yellow-400',
                        default                     => 'text-red-600 dark:text-red-400',
                    };

                    $fieldDisplay = null;
                    if ($compound) {
                        $fieldDisplay = 'compound → ' . implode(' + ', array_map(fn ($k) => $fieldLabels[$k] ?? $k, $compound));
                    } elseif ($field) {
                        $fieldDisplay = $fieldLabels[$field] ?? $field;
                    }
                @endphp

                <tr class="border-b dark:border-gray-700 {{ $field || $compound ? '' : 'opacity-60' }}">
                    <td class="p-2 text-gray-500 font-mono text-xs">{{ $idx }}</td>
                    <td class="p-2 font-medium">{{ $header }}</td>
                    <td class="p-2 text-gray-500 text-xs">
                        @if(count($samples) > 0)
                            {{ implode(', ', $samples) }}
                        @else
                            <em>(empty)</em>
                        @endif
                    </td>
                    <td class="p-2">
                        @if($fieldDisplay)
                            <span class="font-mono text-xs px-2 py-1 rounded bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300">
                                {{ $fieldDisplay }}
                            </span>
                        @else
                            <span class="text-gray-400 italic">— bỏ qua —</span>
                        @endif
                    </td>
                    <td class="p-2 {{ $confColor }} text-xs font-mono">
                        @if($confidence !== null)
                            {{ (int) round($confidence * 100) }}%
                        @else
                            —
                        @endif
                    </td>
                    <td class="p-2 text-xs text-gray-600 dark:text-gray-400">{{ $reason }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
