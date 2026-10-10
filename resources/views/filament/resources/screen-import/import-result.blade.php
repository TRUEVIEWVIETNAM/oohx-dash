@php
    /** @var int|null $successCount */
    /** @var int|null $failedCount */
    /** @var int|null $totalRows */
    /** @var array $errors */
    /** @var \Carbon\Carbon|null $startedAt */
    /** @var \Carbon\Carbon|null $finishedAt */

    $duration = ($startedAt && $finishedAt)
        ? $startedAt->diffInSeconds($finishedAt)
        : null;
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-4 gap-4">
        <div class="rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 p-3">
            <div class="text-xs text-green-700 dark:text-green-400 uppercase tracking-wide">Đã nhập</div>
            <div class="text-2xl font-bold text-green-900 dark:text-green-100">{{ number_format($successCount ?? 0) }}</div>
        </div>
        <div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3">
            <div class="text-xs text-red-700 dark:text-red-400 uppercase tracking-wide">Lỗi</div>
            <div class="text-2xl font-bold text-red-900 dark:text-red-100">{{ number_format($failedCount ?? 0) }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3">
            <div class="text-xs text-gray-700 dark:text-gray-400 uppercase tracking-wide">Tổng đã xử lý</div>
            <div class="text-2xl font-bold">{{ number_format(($successCount ?? 0) + ($failedCount ?? 0)) }}</div>
            <div class="text-xs text-gray-500">trên {{ number_format($totalRows ?? 0) }} dòng</div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3">
            <div class="text-xs text-gray-700 dark:text-gray-400 uppercase tracking-wide">Thời lượng</div>
            <div class="text-2xl font-bold">
                @if($duration !== null)
                    {{ $duration < 60 ? $duration . 's' : round($duration / 60, 1) . 'm' }}
                @else
                    —
                @endif
            </div>
        </div>
    </div>

    @if(count($errors) > 0)
        <details class="mt-4">
            <summary class="cursor-pointer text-sm font-medium text-red-700 dark:text-red-400">
                Rows có lỗi ({{ count($errors) }})
            </summary>
            <div class="mt-2 max-h-96 overflow-y-auto text-xs font-mono space-y-1 bg-red-50 dark:bg-red-900/10 p-3 rounded">
                @foreach($errors as $rowNum => $rowErrors)
                    <div class="border-b border-red-200 dark:border-red-800 py-1">
                        <strong>Dòng {{ $rowNum }}:</strong>
                        {{ is_array($rowErrors) ? implode(' · ', $rowErrors) : $rowErrors }}
                    </div>
                @endforeach
            </div>
        </details>
    @endif
</div>
