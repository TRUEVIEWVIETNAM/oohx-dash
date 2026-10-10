<x-filament-panels::page>
    {{-- ── Status counters ──────────────────────────────────────────── --}}
    <x-filament::section>
        <x-slot name="heading">Trạng thái hàng đợi</x-slot>

        <div class="flex flex-wrap items-center gap-6 text-sm">
            @php
                $statusMap = [
                    'pending'   => ['color' => 'warning', 'icon' => 'heroicon-m-clock',       'label' => 'Pending'],
                    'running'   => ['color' => 'info',    'icon' => 'heroicon-m-arrow-path',  'label' => 'Running'],
                    'done'      => ['color' => 'success', 'icon' => 'heroicon-m-check-circle','label' => 'Done'],
                    'failed'    => ['color' => 'danger',  'icon' => 'heroicon-m-x-circle',    'label' => 'Failed'],
                    'cancelled' => ['color' => 'gray',    'icon' => 'heroicon-m-minus-circle','label' => 'Cancelled'],
                ];
            @endphp
            @foreach($statusMap as $st => $meta)
                <div class="flex items-center gap-2">
                    <x-filament::icon :icon="$meta['icon']" @class([
                        'h-5 w-5',
                        'text-warning-500' => $meta['color'] === 'warning',
                        'text-info-500'    => $meta['color'] === 'info',
                        'text-success-500' => $meta['color'] === 'success',
                        'text-danger-500'  => $meta['color'] === 'danger',
                        'text-gray-400'    => $meta['color'] === 'gray',
                    ]) />
                    <span class="font-semibold">{{ $meta['label'] }}:</span>
                    <span class="font-mono">{{ number_format($counts[$st] ?? 0) }}</span>
                </div>
            @endforeach

            @if($overdue > 0)
                <div class="ml-auto">
                    <span class="fi-badge inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md text-xs font-semibold ring-1 ring-inset bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-950/50 dark:text-danger-400">
                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3.5 w-3.5" />
                        {{ $overdue }} quá hạn
                    </span>
                </div>
            @endif
        </div>
    </x-filament::section>

    {{-- ── Collector cards — config-driven, loop automatically ─────── --}}
    @foreach($collectors as $name => $meta)
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon :icon="$meta['icon'] ?? 'heroicon-o-cube'" @class([
                        'h-5 w-5',
                        'text-primary-500' => ($meta['color'] ?? 'gray') === 'primary',
                        'text-info-500'    => ($meta['color'] ?? 'gray') === 'info',
                        'text-warning-500' => ($meta['color'] ?? 'gray') === 'warning',
                        'text-gray-500'    => ! in_array(($meta['color'] ?? 'gray'), ['primary', 'info', 'warning']),
                    ]) />
                    <span>{{ $meta['display_name'] ?? $name }}</span>
                    <code class="text-xs text-gray-400 font-mono">{{ $name }}</code>
                </div>
            </x-slot>

            <x-slot name="description">
                {{ $meta['description'] ?? '' }}
            </x-slot>

            {{-- Meta row --}}
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs text-gray-600 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">
                <div><strong class="text-gray-700 dark:text-gray-300">Nguồn:</strong> {{ $meta['provider'] ?? '—' }}</div>
                <div><strong class="text-gray-700 dark:text-gray-300">Chi phí:</strong> {{ $meta['cost'] ?? '—' }}</div>
                <div><strong class="text-gray-700 dark:text-gray-300">Giới hạn tần suất:</strong> {{ $meta['rate_limit'] ?? '—' }}</div>
                <div>
                    <strong class="text-gray-700 dark:text-gray-300">Chu kỳ:</strong>
                    @php
                        $hours = (int) ($meta['cadence_hours'] ?? 0);
                        $cadenceLabel = $hours >= 168
                            ? round($hours / 168, 1) . 'w'
                            : ($hours >= 24 ? round($hours / 24, 1) . 'd' : "{$hours}h");
                    @endphp
                    {{ $cadenceLabel }}
                </div>
                @if(($meta['cache_ttl_hours'] ?? 0) > 0)
                    <div><strong class="text-gray-700 dark:text-gray-300">Bộ đệm:</strong> {{ $meta['cache_ttl_hours'] }}h</div>
                @endif
                @if($meta['expected_runtime_seconds'] ?? null)
                    <div><strong class="text-gray-700 dark:text-gray-300">Thời gian chạy:</strong> ~{{ $meta['expected_runtime_seconds'] }}s</div>
                @endif
            </div>

            {{-- Latest run per city + trigger --}}
            <div class="space-y-2">
                @foreach($builtinCities as $city)
                    @php
                        $s = $staleness[$name][$city] ?? null;
                        $run = $s['run'] ?? null;
                        $info = $s['info'] ?? ['level' => 'gray', 'label' => 'never'];
                        $levelColors = [
                            'green'  => ['bg' => 'bg-success-50 dark:bg-success-950/30', 'text' => 'text-success-700 dark:text-success-400', 'ring' => 'ring-success-600/20'],
                            'yellow' => ['bg' => 'bg-warning-50 dark:bg-warning-950/30', 'text' => 'text-warning-700 dark:text-warning-400', 'ring' => 'ring-warning-600/20'],
                            'red'    => ['bg' => 'bg-danger-50 dark:bg-danger-950/30',   'text' => 'text-danger-700 dark:text-danger-400',   'ring' => 'ring-danger-600/20'],
                            'blue'   => ['bg' => 'bg-info-50 dark:bg-info-950/30',       'text' => 'text-info-700 dark:text-info-400',       'ring' => 'ring-info-600/20'],
                            'gray'   => ['bg' => 'bg-gray-50 dark:bg-gray-900',          'text' => 'text-gray-500 dark:text-gray-400',       'ring' => 'ring-gray-400/20'],
                        ];
                        $lc = $levelColors[$info['level']] ?? $levelColors['gray'];
                    @endphp
                    <div class="flex flex-wrap items-center gap-3 py-2 border-b border-gray-100 dark:border-gray-800 last:border-b-0">
                        {{-- City + status badge --}}
                        <div class="flex items-center gap-2 min-w-[140px]">
                            <span class="fi-badge inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium ring-1 ring-inset {{ $lc['bg'] }} {{ $lc['text'] }} {{ $lc['ring'] }}">
                                {{ $info['label'] }}
                            </span>
                            <strong class="text-sm text-gray-900 dark:text-gray-100">{{ $city }}</strong>
                        </div>

                        {{-- Latest run summary --}}
                        <div class="text-xs text-gray-600 dark:text-gray-400 flex-1 min-w-[200px]">
                            @if($run)
                                @if($run->finished_at)
                                    {{ $run->finished_at->diffForHumans() }}
                                @else
                                    ({{ $run->status }})
                                @endif
                                @if($run->rows_ingested > 0)
                                    · <strong>{{ number_format($run->rows_ingested) }}</strong> rows
                                    · {{ $run->bytes_fetched_human }}
                                @endif
                                @if($run->error_message)
                                    · <span class="text-danger-600">lỗi</span>
                                @endif
                                ·
                                <a href="{{ route('filament.admin.resources.oohx-collector-runs.view', $run->id) }}"
                                   class="text-primary-600 hover:underline">
                                    run #{{ $run->id }}
                                </a>
                            @else
                                <span class="italic">chưa chạy lần nào</span>
                            @endif
                        </div>

                        {{-- Trigger button (Filament mounted action) --}}
                        <div>
                            {{ ($this->triggerAction)(['collector' => $name, 'city' => $city]) }}
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
