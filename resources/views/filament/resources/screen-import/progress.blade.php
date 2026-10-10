@php
    /** @var int|null $processedCount */
    /** @var int|null $successCount */
    /** @var int|null $failedCount */
    /** @var int|null $totalRows */
    /** @var int $progressPct */
    /** @var \Carbon\Carbon|null $startedAt */

    $elapsed = $startedAt ? $startedAt->diffInSeconds(now()) : 0;
    $rate = $elapsed > 0 && $processedCount > 0 ? $processedCount / $elapsed : null;
    $etaSec = ($rate && $totalRows && $processedCount < $totalRows)
        ? (int) round(($totalRows - $processedCount) / $rate)
        : null;
@endphp

<div class="space-y-4">
    <div>
        <div class="flex justify-between text-sm mb-1">
            <span class="font-medium">Đang import {{ number_format($processedCount ?? 0) }} / {{ number_format($totalRows ?? 0) }}</span>
            <span class="text-gray-600 dark:text-gray-400">{{ $progressPct }}%</span>
        </div>
        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3 overflow-hidden">
            <div class="bg-primary-500 h-3 transition-all duration-500"
                 style="width: {{ $progressPct }}%"></div>
        </div>
    </div>

    <div class="grid grid-cols-4 gap-4 text-sm">
        <div class="rounded border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 p-2">
            <div class="text-xs text-green-700 dark:text-green-400 uppercase">Thành công</div>
            <div class="text-lg font-bold text-green-900 dark:text-green-100">{{ number_format($successCount ?? 0) }}</div>
        </div>
        <div class="rounded border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-2">
            <div class="text-xs text-red-700 dark:text-red-400 uppercase">Lỗi</div>
            <div class="text-lg font-bold text-red-900 dark:text-red-100">{{ number_format($failedCount ?? 0) }}</div>
        </div>
        <div class="rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-2">
            <div class="text-xs text-gray-700 dark:text-gray-400 uppercase">Đã chạy</div>
            <div class="text-lg font-bold">{{ $elapsed < 60 ? $elapsed . 's' : round($elapsed / 60, 1) . 'm' }}</div>
        </div>
        <div class="rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-2">
            <div class="text-xs text-gray-700 dark:text-gray-400 uppercase">Còn lại</div>
            <div class="text-lg font-bold">
                @if($etaSec !== null)
                    {{ $etaSec < 60 ? $etaSec . 's' : round($etaSec / 60, 1) . 'm' }}
                @else
                    —
                @endif
            </div>
        </div>
    </div>

    @if($rate)
        <div class="text-xs text-gray-500">Tốc độ: {{ round($rate, 1) }} dòng/giây</div>
    @endif
</div>
