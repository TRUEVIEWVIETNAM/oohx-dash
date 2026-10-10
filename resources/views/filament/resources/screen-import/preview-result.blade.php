@php
    /** @var array $preview */
    /** @var array $errors */
    /** @var int $totalRows */

    $validTotal = $totalRows - count($errors);
    $pctValid = $totalRows ? round(($validTotal / $totalRows) * 100) : 0;
@endphp

<div class="space-y-4">
    {{-- Stats banner --}}
    <div class="grid grid-cols-3 gap-4">
        <div class="rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 p-3">
            <div class="text-xs text-green-700 dark:text-green-400 uppercase tracking-wide">Hợp lệ</div>
            <div class="text-2xl font-bold text-green-900 dark:text-green-100">{{ number_format($validTotal) }}</div>
            <div class="text-xs text-green-700 dark:text-green-400">{{ $pctValid }}%</div>
        </div>
        <div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3">
            <div class="text-xs text-red-700 dark:text-red-400 uppercase tracking-wide">Lỗi</div>
            <div class="text-2xl font-bold text-red-900 dark:text-red-100">{{ number_format(count($errors)) }}</div>
            <div class="text-xs text-red-700 dark:text-red-400">{{ $totalRows ? round((count($errors) / $totalRows) * 100) : 0 }}%</div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/20 p-3">
            <div class="text-xs text-gray-700 dark:text-gray-400 uppercase tracking-wide">Tổng</div>
            <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($totalRows) }}</div>
        </div>
    </div>

    {{-- Preview rows table --}}
    @if(count($preview) > 0)
        <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="p-2 text-left font-semibold border-b dark:border-gray-700 w-16">Dòng</th>
                        <th class="p-2 text-left font-semibold border-b dark:border-gray-700">external_id</th>
                        <th class="p-2 text-left font-semibold border-b dark:border-gray-700">tên</th>
                        <th class="p-2 text-left font-semibold border-b dark:border-gray-700">địa điểm</th>
                        <th class="p-2 text-left font-semibold border-b dark:border-gray-700">kích thước</th>
                        <th class="p-2 text-left font-semibold border-b dark:border-gray-700">cpm</th>
                        <th class="p-2 text-left font-semibold border-b dark:border-gray-700">Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($preview as $row)
                        @php
                            $d = $row['data'] ?? [];
                            $rowColor = $row['is_valid'] ? '' : 'bg-red-50 dark:bg-red-900/10';
                            $wPx = $d['spec']['width_px']  ?? '—';
                            $hPx = $d['spec']['height_px'] ?? '—';
                            $siteKey = $d['site']['external_id'] ?? ($d['site']['name'] ?? '—');
                        @endphp
                        <tr class="border-b dark:border-gray-700 {{ $rowColor }}">
                            <td class="p-2 text-gray-500 font-mono text-xs">{{ $row['spreadsheet_row'] }}</td>
                            <td class="p-2 font-mono text-xs">{{ $d['screens']['external_id'] ?? '—' }}</td>
                            <td class="p-2">{{ $d['screens']['name'] ?? '—' }}</td>
                            <td class="p-2 text-xs text-gray-600">{{ $siteKey }}</td>
                            <td class="p-2 text-xs">{{ $wPx }}×{{ $hPx }}</td>
                            <td class="p-2 text-xs">{{ isset($d['inventory']['floor_cpm']) ? number_format($d['inventory']['floor_cpm']) : '—' }}</td>
                            <td class="p-2">
                                @if($row['is_valid'])
                                    <span class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-700">hợp lệ</span>
                                @else
                                    <div class="text-xs space-y-0.5">
                                        @foreach($row['errors'] as $err)
                                            <div class="text-red-700 dark:text-red-400">• {{ $err }}</div>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($totalRows > count($preview))
            <div class="text-xs text-gray-500">
                Hiển thị {{ count($preview) }} rows đầu tiên. Tổng {{ number_format($totalRows) }} dòng trong tệp.
            </div>
        @endif
    @endif

    {{-- All errors summary (if more errors than shown in preview) --}}
    @if(count($errors) > count(array_filter($preview, fn ($r) => ! $r['is_valid'])))
        <details class="mt-4">
            <summary class="cursor-pointer text-sm font-medium text-red-700 dark:text-red-400">
                Xem tất cả {{ count($errors) }} rows có lỗi
            </summary>
            <div class="mt-2 max-h-96 overflow-y-auto text-xs font-mono space-y-1 bg-red-50 dark:bg-red-900/10 p-3 rounded">
                @foreach($errors as $rowNum => $rowErrors)
                    <div class="border-b border-red-200 dark:border-red-800 py-1">
                        <strong>Dòng {{ $rowNum }}:</strong>
                        {{ implode(' · ', $rowErrors) }}
                    </div>
                @endforeach
            </div>
        </details>
    @endif
</div>
