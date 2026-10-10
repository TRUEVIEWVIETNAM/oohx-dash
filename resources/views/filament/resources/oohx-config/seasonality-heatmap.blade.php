<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Hệ số mùa vụ — 12 tháng × N tỉnh/thành</x-slot>
        <x-slot name="description">
            Thang màu: <strong class="text-success-600">xanh</strong> = amplify (&gt; 1.05),
            <strong class="text-warning-600">vàng</strong> = neutral (0.95-1.05),
            <strong class="text-danger-600">đỏ</strong> = dampen (&lt; 0.95),
            gray = no data.
        </x-slot>

        @if(empty($heatmap))
            <div class="p-6 text-center bg-gray-50 dark:bg-gray-900/40 rounded-lg border border-dashed border-gray-300 dark:border-gray-700 text-gray-500">
                Chưa có seasonality data.<br>
                SSH Data Engine VPS và chạy:
                <code class="block mt-2 font-mono text-xs">python -m app.cli seed-seasonality</code>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700">
                            <th class="sticky left-0 z-10 bg-gray-50 dark:bg-gray-800/80 px-4 py-2 text-left text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300">
                                Tỉnh/thành
                            </th>
                            @foreach($months as $m => $label)
                                <th class="px-3 py-2 text-center text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-300">
                                    {{ $label }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($heatmap as $city => $byMonth)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="sticky left-0 z-10 bg-white dark:bg-gray-900 px-4 py-2 font-semibold text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                    {{ $city }}
                                </td>
                                @foreach($months as $m => $label)
                                    @php
                                        $cell = $byMonth[$m] ?? null;
                                        $factor = $cell['factor'] ?? null;

                                        if ($factor === null) {
                                            $bg = 'bg-gray-100 dark:bg-gray-800/30';
                                            $text = 'text-gray-400';
                                            $display = '—';
                                        } else {
                                            // Color scale based on factor magnitude
                                            if ($factor > 1.10) {
                                                $bg = 'bg-success-500';  $text = 'text-white';
                                            } elseif ($factor > 1.05) {
                                                $bg = 'bg-success-300';  $text = 'text-gray-900';
                                            } elseif ($factor >= 0.95) {
                                                $bg = 'bg-warning-200';  $text = 'text-gray-900';
                                            } elseif ($factor >= 0.85) {
                                                $bg = 'bg-danger-300';   $text = 'text-gray-900';
                                            } else {
                                                $bg = 'bg-danger-500';   $text = 'text-white';
                                            }
                                            $display = number_format($factor, 2);
                                        }
                                    @endphp
                                    <td class="px-2 py-2 text-center {{ $bg }} {{ $text }} border border-white dark:border-gray-900"
                                        @if($cell)
                                            title="{{ $city }} · {{ $label }} = {{ $display }}{{ $cell['note'] ? ' — '.$cell['note'] : '' }}{{ $cell['updated_by'] ? ' (by '.$cell['updated_by'].')' : '' }}"
                                        @endif>
                                        <span class="font-mono text-sm font-semibold">{{ $display }}</span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 text-xs text-gray-500 space-y-1">
                <div>Hover cell để xem note + updated_by.</div>
                <div>Chỉnh 1 cell: click <strong>Về danh sách</strong> → Edit row (table).</div>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
