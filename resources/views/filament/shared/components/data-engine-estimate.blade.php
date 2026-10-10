@php
    /** @var \App\Models\Screen $screen */
    $screen = $getRecord();
    $estimate = app(\App\Services\OohxDataEngine::class)->getEstimateByExternalId($screen->uuid);

    $confidenceTier = null;
    $confidenceColor = 'gray';
    if ($estimate && $estimate->confidence_score !== null) {
        $c = (float) $estimate->confidence_score;
        if ($c >= 0.7) { $confidenceTier = 'high';   $confidenceColor = 'success'; }
        elseif ($c >= 0.5) { $confidenceTier = 'medium'; $confidenceColor = 'warning'; }
        else { $confidenceTier = 'low'; $confidenceColor = 'danger'; }
    }
@endphp

@if(! $estimate)
    <div class="flex items-start gap-3 rounded-lg border border-warning-200 dark:border-warning-800 bg-warning-50 dark:bg-warning-950/30 p-4">
        <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5 text-warning-500 flex-shrink-0 mt-0.5" />
        <div>
            <div class="text-sm font-medium text-warning-900 dark:text-warning-100">
                Chưa có estimate cho screen này
            </div>
            <div class="text-xs text-warning-700 dark:text-warning-300 mt-1">
                Có thể chưa được sync hoặc Data Engine cron chưa recompute. Kiểm tra
                <a href="{{ route('filament.admin.pages.oohx-data-engine') }}" class="underline">Bảng điều khiển Data Engine</a>
                hoặc trigger sync thủ công.
            </div>
            <div class="text-xs text-gray-500 mt-2 font-mono">UUID: {{ $screen->uuid }}</div>
        </div>
    </div>
@else
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
            <div class="text-xs font-medium text-gray-500 uppercase">Lượt hiển thị mỗi ngày</div>
            <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">
                {{ $estimate->estimated_daily_impressions !== null ? number_format((float) $estimate->estimated_daily_impressions) : '—' }}
            </div>
            @if($estimate->estimated_daily_passby)
                <div class="text-xs text-gray-500 mt-0.5">
                    Passby: {{ number_format((float) $estimate->estimated_daily_passby) }}
                </div>
            @endif
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 uppercase">Lượt hiển thị mỗi tháng</div>
            <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">
                {{ $estimate->estimated_monthly_impressions !== null ? number_format((float) $estimate->estimated_monthly_impressions) : '—' }}
            </div>
            @if($estimate->estimated_weekly_impressions)
                <div class="text-xs text-gray-500 mt-0.5">
                    Weekly: {{ number_format((float) $estimate->estimated_weekly_impressions) }}
                </div>
            @endif
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 uppercase">OTS mỗi ngày</div>
            <div class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">
                {{ $estimate->estimated_daily_ots !== null ? number_format((float) $estimate->estimated_daily_ots) : '—' }}
            </div>
            @if($estimate->estimated_daily_screen_flow)
                <div class="text-xs text-gray-500 mt-0.5">
                    Screen flow: {{ number_format((float) $estimate->estimated_daily_screen_flow) }}
                </div>
            @endif
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 uppercase">Độ tin cậy</div>
            <div class="mt-1 flex items-center gap-2">
                <span class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                    {{ $estimate->confidence_score !== null ? number_format((float) $estimate->confidence_score, 2) : '—' }}
                </span>
                @if($confidenceTier)
                    <span class="fi-badge inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset
                        @switch($confidenceColor)
                            @case('success') bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-950/50 dark:text-success-400 @break
                            @case('warning') bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-950/50 dark:text-warning-400 @break
                            @default         bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-950/50 dark:text-danger-400
                        @endswitch">
                        {{ $confidenceTier }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700 grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
        <div>
            <span class="text-xs text-gray-500">Cách tính:</span>
            <span class="ml-1 font-medium text-gray-900 dark:text-gray-100">{{ $estimate->estimation_method ?? '—' }}</span>
        </div>
        <div>
            <span class="text-xs text-gray-500">Phiên bản mô hình:</span>
            <span class="ml-1 font-medium text-gray-900 dark:text-gray-100">{{ $estimate->model_version ?? '—' }}</span>
        </div>
        <div>
            <span class="text-xs text-gray-500">Tính lần cuối:</span>
            <span class="ml-1 font-medium text-gray-900 dark:text-gray-100">
                @if($estimate->last_calculated_at)
                    {{ \Illuminate\Support\Carbon::parse($estimate->last_calculated_at)->diffForHumans() }}
                @else
                    —
                @endif
            </span>
        </div>
    </div>
@endif
