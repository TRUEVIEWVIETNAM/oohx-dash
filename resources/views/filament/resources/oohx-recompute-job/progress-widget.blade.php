@php
    /** @var \App\Models\Oohx\RecomputeJob $record */
    $record = $getRecord();
    $p = $record->progress_data ?? [];

    $total   = (int) ($p['total'] ?? 0);
    $done    = (int) ($p['done'] ?? 0);
    $failed  = (int) ($p['failed'] ?? 0);
    $label   = $p['label']        ?? $record->action ?? 'bulk';
    $started = $p['started_at']   ?? null;
    $updated = $p['updated_at']   ?? null;
    $finished= (bool) ($p['finished'] ?? false);
    $cancelledAt = $p['cancelled_at_index'] ?? null;

    $percent = $total > 0 ? (int) round(($done + $failed) / $total * 100) : 0;

    // ETA estimate nếu đang processing và có throughput
    $eta = null;
    if (! $finished && $started && $done > 0 && $total > 0) {
        $startedTs = \Illuminate\Support\Carbon::parse($started);
        $elapsed = max(1, $startedTs->diffInSeconds(now()));
        $throughput = $done / $elapsed; // screens/sec
        $remaining = $total - $done - $failed;
        if ($throughput > 0 && $remaining > 0) {
            $etaSec = (int) round($remaining / $throughput);
            $eta = $etaSec < 60
                ? "~{$etaSec}s remaining"
                : "~" . \Illuminate\Support\Carbon::now()->addSeconds($etaSec)->diffForHumans(now(), true) . ' remaining';
        }
    }

    // Bar color theo trạng thái
    $barColor = match (true) {
        $record->status === 'cancelled' => 'bg-gray-400',
        $record->status === 'failed'    => 'bg-danger-500',
        $finished                        => 'bg-success-500',
        default                          => 'bg-primary-500',
    };
@endphp

<div class="space-y-4">
    {{-- Label + timestamps --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
        <div>
            <div class="text-xs font-medium text-gray-500 uppercase">Hành động</div>
            <div class="mt-1 font-mono text-gray-900 dark:text-gray-100">{{ $label }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 uppercase">Bắt đầu</div>
            <div class="mt-1 text-gray-900 dark:text-gray-100">
                @if($started)
                    {{ \Illuminate\Support\Carbon::parse($started)->diffForHumans() }}
                @else
                    —
                @endif
            </div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 uppercase">Cập nhật</div>
            <div class="mt-1 text-gray-900 dark:text-gray-100">
                @if($updated)
                    {{ \Illuminate\Support\Carbon::parse($updated)->diffForHumans() }}
                @else
                    —
                @endif
            </div>
        </div>
    </div>

    {{-- Progress bar --}}
    <div>
        <div class="flex items-center justify-between text-sm mb-2">
            <div class="font-semibold text-gray-900 dark:text-gray-100">
                {{ number_format($done + $failed) }} / {{ number_format($total) }}
                @if($failed > 0)
                    · <span class="text-danger-600">{{ $failed }} lỗi</span>
                @endif
            </div>
            <div class="text-sm font-mono text-gray-600 dark:text-gray-400">{{ $percent }}%</div>
        </div>
        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3 overflow-hidden">
            <div class="{{ $barColor }} h-3 transition-all duration-500" style="width: {{ $percent }}%"></div>
        </div>
        @if($eta)
            <div class="mt-2 text-xs text-gray-500">{{ $eta }}</div>
        @endif
        @if($cancelledAt !== null)
            <div class="mt-2 text-xs text-warning-600">
                Worker dừng ở màn hình #{{ $cancelledAt }} (cooperative cancel).
            </div>
        @endif
    </div>

    {{-- State summary --}}
    <div class="flex items-center gap-4 text-xs text-gray-500 pt-2 border-t border-gray-200 dark:border-gray-700">
        @if($finished)
            <span class="flex items-center gap-1">
                <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4 text-success-500" />
                Xong
            </span>
        @elseif($record->status === 'processing')
            <span class="flex items-center gap-1">
                <x-filament::icon icon="heroicon-m-arrow-path" class="h-4 w-4 text-info-500 animate-spin" />
                Đang chạy · tự làm mới 10 giây
            </span>
        @elseif($record->status === 'cancelled')
            <span class="flex items-center gap-1">
                <x-filament::icon icon="heroicon-m-x-circle" class="h-4 w-4 text-gray-500" />
                Đã huỷ
            </span>
        @endif

        <span>Ghi xuống CSDL mỗi 25 màn hình</span>
    </div>
</div>
