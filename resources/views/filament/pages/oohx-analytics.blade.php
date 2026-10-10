<x-filament-panels::page>
    @if(! $available)
        {{-- ── Empty state — MVs chưa apply migration 013 ──────────── --}}
        <x-filament::section>
            <div class="text-center py-8 space-y-3">
                <x-heroicon-o-chart-pie class="w-12 h-12 mx-auto text-gray-400" />
                <h3 class="text-lg font-medium">Analytics chưa available</h3>
                <p class="text-sm text-gray-500">
                    Materialized views chưa migrate. Ops cần apply
                    <code>sql/013_analytics_views.sql</code> trên DE VPS.
                </p>
                <p class="text-xs text-gray-500 font-mono">
                    cd /home/oohx/apps/oohx-matrix/python-data-engine<br>
                    .venv/bin/python -m app.cli init-db --sql-dir sql<br>
                    .venv/bin/python -m app.cli refresh-analytics
                </p>
            </div>
        </x-filament::section>
    @else
        {{-- ── Header banner: staleness + WoW summary ──────────────── --}}
        <x-filament::section>
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <div class="text-xs uppercase text-gray-500 tracking-wide mb-1">Làm mới lần cuối</div>
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                     bg-{{ $staleness['color'] }}-100 text-{{ $staleness['color'] }}-800
                                     dark:bg-{{ $staleness['color'] }}-900/30 dark:text-{{ $staleness['color'] }}-200">
                            {{ $staleness['label'] }}
                        </span>
                        @if($staleness['computed_at'])
                            <span class="text-xs text-gray-500">
                                ({{ $staleness['computed_at']->setTimezone('Asia/Ho_Chi_Minh')->format('Y-m-d H:i') }} GMT+7)
                            </span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        DE refresh daily 04:15 UTC. Cache local 5 phút.
                    </p>
                </div>

                @php
                    $cur = $weekOverWeek['current'] ?? null;
                    $diff = $weekOverWeek['diff'] ?? [];
                @endphp
                @if($cur)
                    <div class="grid grid-cols-3 gap-3 flex-1 max-w-2xl">
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
                            <div class="text-xs text-gray-500 uppercase tracking-wide">Chiến dịch tuần này</div>
                            <div class="text-2xl font-bold">{{ number_format($cur->campaigns_count ?? 0) }}</div>
                            @if(isset($diff['campaigns_count']['absolute']))
                                @php $abs = $diff['campaigns_count']['absolute']; @endphp
                                <div class="text-xs {{ $abs >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $abs >= 0 ? '+' : '' }}{{ $abs }} so với tuần trước
                                </div>
                            @endif
                        </div>
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
                            <div class="text-xs text-gray-500 uppercase tracking-wide">Lượt hiển thị</div>
                            <div class="text-2xl font-bold">{{ number_format($cur->total_impressions ?? 0) }}</div>
                            @if(isset($diff['total_impressions']['pct']))
                                @php $pct = $diff['total_impressions']['pct']; @endphp
                                <div class="text-xs {{ $pct >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $pct >= 0 ? '+' : '' }}{{ $pct }}%
                                </div>
                            @endif
                        </div>
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-center">
                            <div class="text-xs text-gray-500 uppercase tracking-wide">Tiếp cận</div>
                            <div class="text-2xl font-bold">{{ number_format($cur->total_reach ?? 0) }}</div>
                            @if(isset($diff['total_reach']['pct']))
                                @php $pct = $diff['total_reach']['pct']; @endphp
                                <div class="text-xs {{ $pct >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $pct >= 0 ? '+' : '' }}{{ $pct }}%
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- ── Section 1: Weekly trend ──────────────────────────────── --}}
        <x-filament::section icon="heroicon-o-chart-bar" heading="Last 12 weeks — Campaign trend">
            @if($weeklyTrend->isEmpty())
                <p class="text-sm text-gray-500 italic">No campaign data trong 12 tuần qua.</p>
            @else
                @php
                    $maxImp = max(1, $weeklyTrend->max('total_impressions') ?? 1);
                @endphp
                <div class="space-y-1">
                    @foreach($weeklyTrend as $w)
                        @php
                            $pct = (int) round(((int) ($w->total_impressions ?? 0)) / $maxImp * 100);
                        @endphp
                        <div class="flex items-center gap-3 text-sm">
                            <div class="w-24 text-xs text-gray-500 font-mono">
                                {{ $w->week_start instanceof \Carbon\Carbon ? $w->week_start->format('M d') : $w->week_start }}
                            </div>
                            <div class="flex-1 bg-gray-100 dark:bg-gray-800 rounded h-5 relative overflow-hidden">
                                <div class="bg-blue-500 h-full" style="width: {{ $pct }}%"></div>
                                <div class="absolute inset-0 flex items-center justify-end pr-2 text-xs font-medium">
                                    {{ number_format($w->total_impressions ?? 0) }}
                                </div>
                            </div>
                            <div class="w-20 text-right text-xs text-gray-500">
                                {{ $w->campaigns_count ?? 0 }} camp
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            {{-- ── Section 2: Top cities ────────────────────────────── --}}
            <x-filament::section icon="heroicon-o-map" heading="Top cities by daily impressions">
                @if($topCities->isEmpty())
                    <p class="text-sm text-gray-500 italic">Chưa có dữ liệu tỉnh/thành.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                                <tr>
                                    <th class="text-left py-2">Tỉnh/thành</th>
                                    <th class="text-right py-2">Màn hình</th>
                                    <th class="text-right py-2">Lượt HT/ngày</th>
                                    <th class="text-right py-2">Tin cậy TB</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach($topCities as $c)
                                    <tr>
                                        <td class="py-2 font-medium">{{ $c->city ?? '—' }}</td>
                                        <td class="text-right py-2">
                                            {{ $c->screen_count ?? 0 }}
                                            <span class="text-xs text-gray-500">
                                                ({{ $c->outdoor_count ?? 0 }}o / {{ $c->indoor_count ?? 0 }}i)
                                            </span>
                                        </td>
                                        <td class="text-right py-2 font-mono">
                                            {{ number_format($c->total_daily_impressions ?? 0) }}
                                        </td>
                                        <td class="text-right py-2">
                                            @php $conf = $c->avg_confidence; @endphp
                                            @if($conf !== null)
                                                <span class="px-1.5 py-0.5 rounded text-xs
                                                    {{ $conf >= 0.7 ? 'bg-green-100 text-green-800' : ($conf >= 0.5 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') }}">
                                                    {{ number_format($conf, 2) }}
                                                </span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>

            {{-- ── Section 4: Formula versions impact ───────────────── --}}
            <x-filament::section icon="heroicon-o-variable" heading="Formula versions impact">
                @if($formulaVersions->isEmpty())
                    <p class="text-sm text-gray-500 italic">Chưa có dữ liệu công thức.</p>
                @else
                    <div class="space-y-2">
                        @foreach($formulaVersions as $f)
                            <div class="flex items-center gap-3 p-2 rounded {{ $f->is_active ? 'bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800' : 'border border-gray-100 dark:border-gray-800' }}">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-sm font-medium truncate">{{ $f->tag ?? '—' }}</span>
                                        @if($f->is_active)
                                            <span class="text-xs px-1.5 py-0.5 rounded bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200">ĐANG BẬT</span>
                                        @endif
                                    </div>
                                    @if($f->activated_at)
                                        <div class="text-xs text-gray-500">
                                            Activated {{ $f->activated_at->diffForHumans() }}
                                        </div>
                                    @endif
                                </div>
                                <div class="text-right text-xs">
                                    <div><span class="font-mono">{{ $f->screens_with_this_version ?? 0 }}</span> màn hình</div>
                                    <div class="text-gray-500">
                                        Σ {{ number_format($f->total_daily_impressions ?? 0) }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        </div>

        {{-- ── Section 3: Screen utilization ────────────────────────── --}}
        <x-filament::section icon="heroicon-o-tv" heading="Top utilized screens (90 days)">
            <div class="flex items-center gap-4 mb-3 text-sm">
                <span class="text-gray-500">
                    <strong class="text-gray-900 dark:text-gray-100">{{ $utilizationCounts['booked'] }}</strong> màn hình đã đặt / {{ $utilizationCounts['total_with_data'] }} có dữ liệu
                </span>
            </div>
            @if($topUtilizedScreens->isEmpty())
                <p class="text-sm text-gray-500 italic">Chưa có dữ liệu mức sử dụng.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-gray-500 uppercase border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th class="text-left py-2">Mã màn hình phía DE</th>
                                <th class="text-right py-2">Chiến dịch 90 ngày</th>
                                <th class="text-right py-2">Lượt hiển thị đã phân bổ</th>
                                <th class="text-right py-2">Dùng lần cuối</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach($topUtilizedScreens as $s)
                                <tr>
                                    <td class="py-2 font-mono text-xs">#{{ $s->screen_id }}</td>
                                    <td class="text-right py-2">{{ $s->campaign_count_90d ?? 0 }}</td>
                                    <td class="text-right py-2 font-mono">
                                        {{ number_format($s->allocated_impressions_90d ?? 0) }}
                                    </td>
                                    <td class="text-right py-2 text-xs text-gray-500">
                                        {{ $s->last_used_at ? $s->last_used_at->diffForHumans() : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <div class="text-xs text-gray-500 text-center">
            Dữ liệu làm mới hằng ngày 04:15 UTC qua cron của DE <code>refresh-analytics</code>.
        </div>
    @endif
</x-filament-panels::page>
