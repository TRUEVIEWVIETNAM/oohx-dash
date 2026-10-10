<x-filament-panels::page>
    @if(! $digestResult)
        {{-- ── Empty state ──────────────────────────────────────────── --}}
        <x-filament::section>
            <div class="text-center py-8 space-y-3">
                <x-heroicon-o-question-mark-circle class="w-12 h-12 mx-auto text-gray-400" />
                <h3 class="text-lg font-medium">Chưa có bản tóm lược sức khoẻ nào</h3>
                <p class="text-sm text-gray-500">
                    Cron của DE ghi tệp <code class="text-xs">health-digest-YYYYMMDD.json</code> hàng ngày 08:00 UTC.
                    Laravel scp về mỗi 30 phút.
                </p>
                <p class="text-xs text-gray-500">
                    Lấy tay: <code>php artisan oohx:fetch-health</code><br>
                    Kiểm tra log: <code>storage/logs/oohx-health.log</code>
                </p>

                @if($lastFetch)
                    <div class="mt-4 inline-block text-xs px-3 py-2 rounded
                                {{ $lastFetch['status'] === 'success'
                                    ? 'bg-green-50 border border-green-200 text-green-800 dark:bg-green-900/20 dark:border-green-800 dark:text-green-200'
                                    : 'bg-red-50 border border-red-200 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-200' }}">
                        Lần cron chạy cuối: <strong>{{ $lastFetch['status'] }}</strong>
                        @if($lastFetch['message'])
                            — {{ $lastFetch['message'] }}
                        @endif
                        <br>
                        <span class="opacity-70">{{ \Carbon\Carbon::parse($lastFetch['at'])->setTimezone('Asia/Ho_Chi_Minh')->diffForHumans() }}</span>
                    </div>
                @else
                    <div class="mt-4 inline-block text-xs px-3 py-2 rounded bg-gray-50 border border-gray-200 text-gray-600 dark:bg-gray-900/40 dark:border-gray-700 dark:text-gray-400">
                        Scheduler chưa chạy lần nào. Chờ tối đa 30 phút hoặc click "Fetch latest digest".
                    </div>
                @endif
            </div>
        </x-filament::section>
    @else
        @php
            $digest = $digestResult['digest'];
            $staleness = $this->stalenessBadge;
            $summary = $this->summaryCounts;
            $checkedAt = $digest['checked_at'] ?? null;
            $host = $digest['host'] ?? 'unknown';
        @endphp

        {{-- ── Overall status banner ──────────────────────────────── --}}
        <x-filament::section>
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-3">
                    @svg($overallBadge['icon'], 'w-10 h-10 text-' . $overallBadge['color'] . '-500')
                    <div>
                        <h2 class="text-xl font-bold">
                            Tổng thể:
                            <span class="text-{{ $overallBadge['color'] }}-600 dark:text-{{ $overallBadge['color'] }}-400">
                                {{ $overallBadge['label'] }}
                            </span>
                        </h2>
                        <p class="text-sm text-gray-500">
                            Máy chủ: <code>{{ $host }}</code> ·
                            Checked: {{ $checkedAt ? \Carbon\Carbon::parse($checkedAt)->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i') : '—' }} (GMT+7)
                        </p>
                    </div>
                </div>

                <div class="text-right">
                    <div class="text-xs uppercase text-gray-500 tracking-wide mb-1">Tuổi bản tóm lược</div>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                 bg-{{ $staleness['color'] }}-100 text-{{ $staleness['color'] }}-800
                                 dark:bg-{{ $staleness['color'] }}-900/30 dark:text-{{ $staleness['color'] }}-200">
                        {{ $staleness['label'] }}
                    </span>
                </div>
            </div>

            {{-- Stale warning banner --}}
            @if(($digestResult['age_minutes'] ?? 0) > 120)
                <div class="mt-4 rounded-lg border border-yellow-200 bg-yellow-50 dark:bg-yellow-900/20 dark:border-yellow-800 p-3 text-sm">
                    <strong class="text-yellow-800 dark:text-yellow-200">⚠ Bản tóm lược đã cũ.</strong>
                    <span class="text-yellow-700 dark:text-yellow-300">
                        Check DE cron (MONITORING-SETUP.md) hoặc Laravel scheduler (`storage/logs/oohx-health.log`).
                    </span>
                </div>
            @endif

            {{-- Summary counts --}}
            <div class="mt-4 grid grid-cols-3 gap-3">
                <div class="rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 p-3 text-center">
                    <div class="text-xs text-green-700 dark:text-green-400 uppercase tracking-wide">OK</div>
                    <div class="text-2xl font-bold text-green-900 dark:text-green-100">{{ $summary['ok'] }}</div>
                </div>
                <div class="rounded-lg border border-yellow-200 dark:border-yellow-800 bg-yellow-50 dark:bg-yellow-900/20 p-3 text-center">
                    <div class="text-xs text-yellow-700 dark:text-yellow-400 uppercase tracking-wide">Cảnh báo</div>
                    <div class="text-2xl font-bold text-yellow-900 dark:text-yellow-100">{{ $summary['warn'] }}</div>
                </div>
                <div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20 p-3 text-center">
                    <div class="text-xs text-red-700 dark:text-red-400 uppercase tracking-wide">Nghiêm trọng</div>
                    <div class="text-2xl font-bold text-red-900 dark:text-red-100">{{ $summary['critical'] }}</div>
                </div>
            </div>
        </x-filament::section>

        {{-- ── Individual check cards ─────────────────────────────── --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
            @foreach($checks as $check)
                <x-filament::section
                    :heading="$check['label']"
                    :icon="$check['icon']"
                    :icon-color="$check['color']"
                    :collapsible="! empty($check['raw']['value']) && is_array($check['raw']['value'])"
                    :collapsed="$check['status'] === 'ok'"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-sm">{{ $check['value'] }}</span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                     bg-{{ $check['color'] }}-100 text-{{ $check['color'] }}-800
                                     dark:bg-{{ $check['color'] }}-900/30 dark:text-{{ $check['color'] }}-200">
                            {{ strtoupper($check['status']) }}
                        </span>
                    </div>

                    {{-- Expandable details --}}
                    @if(! empty($check['raw']['note']))
                        <div class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                            {{ $check['raw']['note'] }}
                        </div>
                    @endif

                    {{-- Per-check specific detail ─────────────────────── --}}
                    @if($check['key'] === 'collector_stale' && is_array($check['raw']['value'] ?? null))
                        <div class="mt-3 space-y-1 text-xs">
                            @foreach($check['raw']['value'] as $c)
                                <div class="flex justify-between border-b border-gray-100 dark:border-gray-700 py-1">
                                    <span class="font-mono">{{ $c['collector'] ?? '?' }}</span>
                                    <span class="text-{{ $check['color'] }}-600">
                                        {{ round($c['overdue_hours'] ?? 0, 1) }}giờ quá hạn
                                        <span class="text-gray-500">(SLA {{ round($c['sla_hours'] ?? 0) }}h)</span>
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($check['key'] === 'weather_freshness' && is_array($check['raw']['value'] ?? null))
                        <div class="mt-3 grid grid-cols-2 gap-2 text-xs">
                            @foreach($check['raw']['value'] as $city => $data)
                                @php
                                    $cityStatus = $data['status'] ?? 'unknown';
                                    $cityColor = match($cityStatus) {
                                        'ok' => 'green', 'warn' => 'yellow', 'critical' => 'red', default => 'gray',
                                    };
                                @endphp
                                <div class="flex justify-between items-center rounded bg-{{ $cityColor }}-50 dark:bg-{{ $cityColor }}-900/20 px-2 py-1">
                                    <span class="font-medium">{{ $city }}</span>
                                    <span class="text-{{ $cityColor }}-700 dark:text-{{ $cityColor }}-300">
                                        {{ round($data['hours_since'] ?? 0, 1) }}h
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($check['key'] === 'formula_coverage')
                        <div class="mt-3 text-xs text-gray-600 dark:text-gray-400 space-y-0.5">
                            <div>Phiên bản đang dùng: <code>{{ $check['raw']['active_version_tag'] ?? '—' }}</code></div>
                            <div>Khớp/cũ/tổng: {{ $check['raw']['matched'] ?? 0 }} / {{ $check['raw']['stale'] ?? 0 }} / {{ $check['raw']['total'] ?? 0 }}</div>
                            @if($check['raw']['in_grace_period'] ?? false)
                                <div class="text-blue-600 dark:text-blue-400">⏱ In grace period (24h after activate)</div>
                            @endif
                        </div>
                    @endif

                    @if($check['key'] === 'job_failure_rate_24h')
                        <div class="mt-3 text-xs text-gray-600 dark:text-gray-400">
                            {{ $check['raw']['ok_count'] ?? 0 }} ok · {{ $check['raw']['fail_count'] ?? 0 }} failed (24h)
                        </div>
                    @endif

                    @if($check['key'] === 'enrichment_stale')
                        <div class="mt-3 text-xs text-gray-600 dark:text-gray-400">
                            Tổng màn hình đang bật: {{ $check['raw']['total_active'] ?? 0 }}
                        </div>
                    @endif

                    @if(! empty($check['raw']['threshold_warn']))
                        <div class="mt-2 text-[10px] text-gray-500">
                            Ngưỡng — cảnh báo: {{ $check['raw']['threshold_warn'] }} · nghiêm trọng: {{ $check['raw']['threshold_critical'] ?? '—' }}
                        </div>
                    @endif
                </x-filament::section>
            @endforeach
        </div>

        <div class="mt-4 text-xs text-gray-500 text-center space-y-1">
            <div>Tệp: <code>{{ basename($digestResult['path']) }}</code></div>
            @if($lastFetch)
                <div>
                    Lần cron lấy cuối:
                    <span class="{{ $lastFetch['status'] === 'success' ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                        {{ $lastFetch['status'] }}
                    </span>
                    · {{ \Carbon\Carbon::parse($lastFetch['at'])->setTimezone('Asia/Ho_Chi_Minh')->diffForHumans() }}
                    @if($lastFetch['status'] !== 'success' && $lastFetch['message'])
                        <br><span class="opacity-70">{{ $lastFetch['message'] }}</span>
                    @endif
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
