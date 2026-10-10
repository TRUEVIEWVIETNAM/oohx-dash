@php
    /** @var \App\Models\Oohx\RecomputeJob $record */
    $record = $getRecord();
    $result = $record->preview_result;
    $fmt    = \App\Services\Oohx\PreviewResultFormatter::class;
@endphp

@if(! $result)
    <div class="rounded-lg bg-info-50 dark:bg-info-950/30 border border-info-200 dark:border-info-800 p-4 text-sm text-info-800 dark:text-info-200">
        <x-filament::icon icon="heroicon-m-information-circle" class="inline h-4 w-4" />
        Preview result chưa có. Status: <strong>{{ $record->status }}</strong>.
        @if($record->is_active) Đang poll mỗi 5s... @endif
    </div>
@else
    @php
        $overallWarning = $fmt::overallWarning($result);
        $bgClass = match ($overallWarning['color']) {
            'success' => 'bg-success-50 dark:bg-success-950/30 border-success-200 dark:border-success-800 text-success-800 dark:text-success-200',
            'warning' => 'bg-warning-50 dark:bg-warning-950/30 border-warning-200 dark:border-warning-800 text-warning-800 dark:text-warning-200',
            'danger'  => 'bg-danger-50  dark:bg-danger-950/30  border-danger-200  dark:border-danger-800  text-danger-800  dark:text-danger-200',
            default   => 'bg-gray-50 dark:bg-gray-900 border-gray-200 dark:border-gray-700 text-gray-800 dark:text-gray-200',
        };
    @endphp

    {{-- ── Overall warning banner ─────────────────────────────────── --}}
    <div class="rounded-lg border p-4 {{ $bgClass }}">
        <div class="flex items-start gap-3">
            <x-filament::icon
                :icon="match($overallWarning['color']) {
                    'success' => 'heroicon-m-check-circle',
                    'warning' => 'heroicon-m-exclamation-triangle',
                    'danger'  => 'heroicon-m-exclamation-circle',
                    default   => 'heroicon-m-question-mark-circle',
                }"
                class="h-6 w-6 flex-shrink-0 mt-0.5" />
            <div>
                <div class="font-semibold text-base">{{ $overallWarning['label'] }} tác động</div>
                <div class="text-sm mt-1">{{ $overallWarning['message'] }}</div>
            </div>
        </div>
    </div>

    {{-- ── Version summary ────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 p-4 rounded-lg bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700">
        <div>
            <div class="text-xs font-medium text-gray-500 uppercase">Baseline (active)</div>
            <div class="mt-1 font-mono text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $result['baseline_version_tag'] ?? '?' }}
            </div>
            <div class="text-xs text-gray-500">#{{ $result['baseline_version_id'] ?? '?' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 uppercase">Target (preview)</div>
            <div class="mt-1 font-mono text-sm font-semibold text-primary-600">
                {{ $result['target_version_tag'] ?? '?' }}
            </div>
            <div class="text-xs text-gray-500">#{{ $result['target_version_id'] ?? '?' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 uppercase">Mẫu</div>
            <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $fmt::num($result['sample_size_computed'] ?? 0) }}
                <span class="text-gray-500">/ {{ $fmt::num($result['sample_size_requested'] ?? 0) }}</span>
            </div>
            @if(($result['skipped'] ?? 0) > 0)
                <div class="text-xs text-warning-600">{{ $result['skipped'] }} bỏ qua</div>
            @endif
        </div>
        <div>
            <div class="text-xs font-medium text-gray-500 uppercase">Lọc</div>
            <div class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                {{ $result['city'] ?? 'All cities' }}
            </div>
            @if(isset($result['seed']) && $result['seed'] !== null)
                <div class="text-xs text-gray-500">nạp: {{ $result['seed'] }}</div>
            @endif
        </div>
    </div>

    {{-- ── Skipped examples (if any) ──────────────────────────────── --}}
    @if(! empty($result['skipped_examples']))
        <details class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
            <summary class="text-sm font-medium cursor-pointer text-gray-700 dark:text-gray-300">
                Skipped examples ({{ count($result['skipped_examples']) }})
            </summary>
            <ul class="mt-2 space-y-1 text-xs text-gray-600 dark:text-gray-400 font-mono">
                @foreach($result['skipped_examples'] as $ex)
                    <li>Màn hình #{{ $ex['screen_id'] ?? '?' }}: {{ $ex['reason'] ?? '?' }}</li>
                @endforeach
            </ul>
        </details>
    @endif

    {{-- ── Per-metric breakdown (3 cards) ─────────────────────────── --}}
    <div class="space-y-4">
        @foreach(['estimated_daily_impressions', 'estimated_daily_ots', 'confidence_score'] as $metricKey)
            @php
                $m = $result['metrics'][$metricKey] ?? null;
                if (! $m) continue;
                $warn = $fmt::warningTier($m['delta_pct_p99'] ?? null);
                $mBgClass = match ($warn['color']) {
                    'success' => 'border-success-300 dark:border-success-700',
                    'warning' => 'border-warning-300 dark:border-warning-700',
                    'danger'  => 'border-danger-300 dark:border-danger-700',
                    default   => 'border-gray-200 dark:border-gray-700',
                };
            @endphp

            <div class="rounded-lg border-2 {{ $mBgClass }} overflow-hidden">
                <div class="px-4 py-2 bg-gray-50 dark:bg-gray-900/40 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $fmt::metricLabel($metricKey) }}</div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500">p99</span>
                        <span @class([
                            'fi-badge inline-flex items-center px-2 py-0.5 rounded-md text-xs font-semibold ring-1 ring-inset',
                            'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-950/50 dark:text-success-400' => $warn['color'] === 'success',
                            'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-950/50 dark:text-warning-400' => $warn['color'] === 'warning',
                            'bg-danger-50  text-danger-700  ring-danger-600/20  dark:bg-danger-950/50  dark:text-danger-400'  => $warn['color'] === 'danger',
                            'bg-gray-50 text-gray-700 ring-gray-600/20' => $warn['color'] === 'gray',
                        ])>
                            {{ $fmt::pct($m['delta_pct_p99'] ?? null, 1) }} · {{ $warn['label'] }}
                        </span>
                    </div>
                </div>

                <div class="p-4 space-y-3">
                    {{-- Counts ──── --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-m-arrow-trending-up" class="h-4 w-4 text-success-500" />
                            <div>
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $fmt::num($m['screens_increased'] ?? 0) }}</div>
                                <div class="text-xs text-gray-500">tăng</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-m-arrow-trending-down" class="h-4 w-4 text-danger-500" />
                            <div>
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $fmt::num($m['screens_decreased'] ?? 0) }}</div>
                                <div class="text-xs text-gray-500">giảm</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-m-minus" class="h-4 w-4 text-gray-400" />
                            <div>
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $fmt::num($m['screens_unchanged'] ?? 0) }}</div>
                                <div class="text-xs text-gray-500">không đổi</div>
                            </div>
                        </div>
                        @if(($m['screens_undefined'] ?? 0) > 0)
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-m-question-mark-circle" class="h-4 w-4 text-gray-400" />
                            <div>
                                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $fmt::num($m['screens_undefined'] ?? 0) }}</div>
                                <div class="text-xs text-gray-500">chưa có</div>
                            </div>
                        </div>
                        @endif
                    </div>

                    {{-- Sums ──── --}}
                    @if(isset($m['baseline_sum']) || isset($m['target_sum']))
                        <div class="pt-3 border-t border-gray-100 dark:border-gray-800 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <div class="text-xs text-gray-500">Tổng mức nền</div>
                                <div class="font-mono font-semibold text-gray-900 dark:text-gray-100">{{ $fmt::num($m['baseline_sum'] ?? null) }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500">Tổng mục tiêu</div>
                                <div class="font-mono font-semibold text-primary-600">{{ $fmt::num($m['target_sum'] ?? null) }}</div>
                            </div>
                        </div>
                    @endif

                    {{-- Delta distribution — text-based percentile lines --}}
                    <div class="pt-3 border-t border-gray-100 dark:border-gray-800">
                        <div class="text-xs font-medium text-gray-500 uppercase mb-2">Phân bố chênh lệch</div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-sm">
                            <div><span class="text-xs text-gray-500">p50</span><div class="font-mono font-semibold">{{ $fmt::pct($m['delta_pct_p50'] ?? null, 1) }}</div></div>
                            <div><span class="text-xs text-gray-500">p90</span><div class="font-mono font-semibold">{{ $fmt::pct($m['delta_pct_p90'] ?? null, 1) }}</div></div>
                            <div><span class="text-xs text-gray-500">p99</span><div class="font-mono font-semibold">{{ $fmt::pct($m['delta_pct_p99'] ?? null, 1) }}</div></div>
                            <div><span class="text-xs text-gray-500">nhỏ nhất</span><div class="font-mono text-danger-600">{{ $fmt::pct($m['delta_pct_min'] ?? null, 1) }}</div></div>
                            <div><span class="text-xs text-gray-500">lớn nhất</span><div class="font-mono text-success-600">{{ $fmt::pct($m['delta_pct_max'] ?? null, 1) }}</div></div>
                            <div><span class="text-xs text-gray-500">trung bình</span><div class="font-mono font-semibold">{{ $fmt::pct($m['delta_pct_mean'] ?? null, 1) }}</div></div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Top deltas table ───────────────────────────────────────── --}}
    @if(! empty($result['top_deltas']))
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-2 bg-gray-50 dark:bg-gray-900/40 border-b border-gray-200 dark:border-gray-700">
                <div class="font-semibold text-gray-900 dark:text-gray-100">Nhiều nhất {{ count($result['top_deltas']) }} chênh lệch</div>
                <div class="text-xs text-gray-500">Sorted by |delta_pct| — biggest changes đầu tiên. Click screen ID để xem estimate detail.</div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800/30 text-xs uppercase text-gray-500">
                        <tr>
                            <th class="px-4 py-2 text-left">Mã màn hình</th>
                            <th class="px-4 py-2 text-right">Mức nền</th>
                            <th class="px-4 py-2 text-right">Mục tiêu</th>
                            <th class="px-4 py-2 text-right">Chênh lệch</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($result['top_deltas'] as $row)
                            @php
                                $screenId = $row['screen_id'] ?? null;
                                $delta    = $row['delta_pct'] ?? null;
                                $deltaColor = $delta === null ? 'text-gray-500'
                                    : ($delta > 0 ? 'text-success-600' : 'text-danger-600');
                                $viewUrl = $screenId
                                    ? \App\Filament\Resources\OohxEstimateResource::getUrl('view', ['record' => $screenId])
                                    : null;
                            @endphp
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30">
                                <td class="px-4 py-2 font-mono">
                                    @if($viewUrl)
                                        <a href="{{ $viewUrl }}" class="text-primary-600 hover:underline">
                                            Màn hình #{{ $screenId }}
                                        </a>
                                    @else
                                        Màn hình #{{ $screenId ?? '?' }}
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-gray-700 dark:text-gray-300">
                                    {{ $fmt::num($row['baseline'] ?? null) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-gray-900 dark:text-gray-100">
                                    {{ $fmt::num($row['target'] ?? null) }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono font-semibold {{ $deltaColor }}">
                                    {{ $fmt::pct($delta, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endif
