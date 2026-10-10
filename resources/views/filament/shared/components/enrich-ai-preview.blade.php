@php
    /** @var array $result */
    if (! empty($result['_error'])):
@endphp
<div class="p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 dark:bg-red-900/20 dark:border-red-800 dark:text-red-300">
    <div class="font-bold mb-2">Bổ sung ngữ cảnh không được</div>
    <div class="text-sm font-mono whitespace-pre-wrap break-words">{{ $result['_error'] }}</div>
    <div class="text-xs mt-3 text-red-600 dark:text-red-400">
        Đóng modal, fix lỗi (top up Anthropic credit / set ANTHROPIC_API_KEY / check network), rồi click "Enrich AI Context" lại.
    </div>
</div>
@php
        return;
    endif;

    $loc      = $result['location'] ?? [];
    $features = $result['features'] ?? [];
    $ai       = $result['ai'] ?? null;
    $meta     = $result['meta'] ?? [];

    $audience      = $ai['audience_profile']  ?? [];
    $time          = $ai['time_performance']  ?? [];
    $nearby        = $ai['nearby_context']    ?? [];
    $advertiserFit = $ai['advertiser_fit']    ?? [];
    $confidence    = $ai['confidence']        ?? null;
    $uncertainties = $ai['uncertainty_notes'] ?? [];

    $confColors = ['high' => 'green', 'medium' => 'yellow', 'low' => 'orange'];
    $confColor  = $confColors[$confidence] ?? 'gray';
@endphp

<div class="space-y-6 text-sm">

    {{-- ── Metadata bar ───────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-3 text-xs px-3 py-2 bg-gray-50 dark:bg-gray-900/40 rounded-lg border border-gray-200 dark:border-gray-700">
        <span><strong>{{ $features['total_pois'] ?? 0 }}</strong> POI</span>
        <span class="text-gray-400">·</span>
        <span><strong>{{ count($features['categories'] ?? []) }}</strong> danh mục</span>
        <span class="text-gray-400">·</span>
        <span>{{ $meta['tokens_in'] ?? 0 }} in / {{ $meta['tokens_out'] ?? 0 }} token ra</span>
        <span class="text-gray-400">·</span>
        <span>chi phí ≈ <strong>${{ number_format($meta['cost_usd'] ?? 0, 4) }}</strong></span>
        <span class="text-gray-400">·</span>
        <span>độ trễ {{ $meta['latency_ms'] ?? 0 }}ms</span>
        @if($confidence)
            <span class="text-gray-400">·</span>
            <span class="px-2 py-0.5 rounded-full bg-{{ $confColor }}-100 text-{{ $confColor }}-700 font-semibold uppercase tracking-wide">
                Độ tin cậy: {{ $confidence }}
            </span>
        @endif
    </div>

    @if(! $ai)
        <div class="p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
            <strong>AI không suy luận được.</strong> Kiểm tra ANTHROPIC_API_KEY hoặc Overpass response. Không có data để apply.
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

        {{-- ── POI categories ─────────────────────────────────── --}}
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            <div class="px-3 py-2 bg-gray-50 dark:bg-gray-900/40 font-semibold text-gray-700 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700">
                POI categories (top 15)
            </div>
            <div class="p-3 max-h-72 overflow-y-auto">
                @if(! empty($features['categories']))
                    <table class="w-full text-xs">
                        <tbody>
                        @foreach(array_slice($features['categories'] ?? [], 0, 15, true) as $cat => $n)
                            <tr class="border-b border-gray-100 dark:border-gray-800 last:border-0">
                                <td class="py-1.5 text-gray-600 dark:text-gray-300">{{ $cat }}</td>
                                <td class="py-1.5 text-right font-bold text-gray-900 dark:text-gray-100">{{ $n }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-gray-400 italic">Không có POI nào — vùng OSM thưa.</p>
                @endif
            </div>
        </div>

        {{-- ── Named POIs ─────────────────────────────────────── --}}
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            <div class="px-3 py-2 bg-gray-50 dark:bg-gray-900/40 font-semibold text-gray-700 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700">
                Named POIs (10 gần nhất)
            </div>
            <div class="p-3 max-h-72 overflow-y-auto">
                @if(! empty($features['named']))
                    <ul class="space-y-1.5 text-xs">
                        @foreach(array_slice($features['named'], 0, 10) as $p)
                            <li class="flex justify-between gap-2 border-b border-gray-100 dark:border-gray-800 last:border-0 pb-1.5">
                                <div class="min-w-0 flex-1">
                                    <div class="font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $p['name'] }}</div>
                                    <div class="text-gray-500 text-[11px]">{{ $p['category'] }}</div>
                                </div>
                                <div class="text-gray-400 text-[11px] whitespace-nowrap">{{ $p['dist_m'] }}m</div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-gray-400 italic">Không có POI có name.</p>
                @endif
            </div>
        </div>

    </div>

    @if($ai)

    {{-- ── Audience profile ──────────────────────────────────── --}}
    <div class="border border-primary-200 dark:border-primary-800 rounded-lg overflow-hidden">
        <div class="px-3 py-2 bg-primary-50 dark:bg-primary-900/30 font-semibold text-primary-800 dark:text-primary-100 border-b border-primary-200 dark:border-primary-800">
            🎯 Audience Profile (AI inference)
        </div>
        <div class="p-4 space-y-3">
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                <div><div class="text-[10px] uppercase font-bold text-gray-500">Nam</div><div class="text-lg font-bold">{{ $audience['male_pct'] ?? '—' }}%</div></div>
                <div><div class="text-[10px] uppercase font-bold text-gray-500">Nữ</div><div class="text-lg font-bold">{{ $audience['female_pct'] ?? '—' }}%</div></div>
                <div><div class="text-[10px] uppercase font-bold text-gray-500">18-24</div><div class="text-lg font-bold">{{ $audience['age_18_24_pct'] ?? '—' }}%</div></div>
                <div><div class="text-[10px] uppercase font-bold text-gray-500">25-34</div><div class="text-lg font-bold">{{ $audience['age_25_34_pct'] ?? '—' }}%</div></div>
                <div><div class="text-[10px] uppercase font-bold text-gray-500">35-44</div><div class="text-lg font-bold">{{ $audience['age_35_44_pct'] ?? '—' }}%</div></div>
                <div><div class="text-[10px] uppercase font-bold text-gray-500">45+</div><div class="text-lg font-bold">{{ $audience['age_45_plus_pct'] ?? '—' }}%</div></div>
            </div>

            <div class="flex flex-wrap items-center gap-3 text-xs">
                @if(! empty($audience['income_tier']))
                    <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 font-semibold">Thu nhập: {{ $audience['income_tier'] }}</span>
                @endif
                @foreach($audience['lifestyle_tags'] ?? [] as $tag)
                    <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">{{ $tag }}</span>
                @endforeach
            </div>

            @if(! empty($audience['source_note']))
                <p class="text-xs text-gray-500 italic">↳ {{ $audience['source_note'] }}</p>
            @endif
        </div>
    </div>

    {{-- ── Time performance ──────────────────────────────────── --}}
    <div class="border border-orange-200 dark:border-orange-800 rounded-lg overflow-hidden">
        <div class="px-3 py-2 bg-orange-50 dark:bg-orange-900/30 font-semibold text-orange-800 dark:text-orange-100 border-b border-orange-200 dark:border-orange-800">
            ⏰ Time Performance (AI inference)
        </div>
        <div class="p-4 space-y-3">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                <div><div class="text-[10px] uppercase font-bold text-gray-500">Giờ cao điểm bắt đầu</div><div class="text-lg font-bold">{{ $time['peak_hour_start'] ?? '—' }}</div></div>
                <div><div class="text-[10px] uppercase font-bold text-gray-500">Giờ cao điểm kết thúc</div><div class="text-lg font-bold">{{ $time['peak_hour_end'] ?? '—' }}</div></div>
                <div><div class="text-[10px] uppercase font-bold text-gray-500">Ngày tốt nhất</div><div class="text-lg font-bold uppercase">{{ $time['best_day'] ?? '—' }}</div></div>
                <div><div class="text-[10px] uppercase font-bold text-gray-500">Sáng</div><div class="text-lg font-bold">{{ $time['morning_pct'] ?? '—' }}%</div></div>
                <div><div class="text-[10px] uppercase font-bold text-gray-500">Chiều/Tối</div><div class="text-sm font-bold">{{ $time['afternoon_pct'] ?? '—' }}% / {{ $time['evening_pct'] ?? '—' }}%</div></div>
            </div>
            @if(! empty($time['rationale']))
                <p class="text-xs text-gray-500 italic">↳ {{ $time['rationale'] }}</p>
            @endif
        </div>
    </div>

    {{-- ── Advertiser fit ─────────────────────────────────────── --}}
    @if(! empty($advertiserFit))
    <div class="border border-green-200 dark:border-green-800 rounded-lg overflow-hidden">
        <div class="px-3 py-2 bg-green-50 dark:bg-green-900/30 font-semibold text-green-800 dark:text-green-100 border-b border-green-200 dark:border-green-800">
            💼 Advertiser Fit (top categories)
        </div>
        <div class="p-4">
            <table class="w-full text-xs">
                <tbody>
                @foreach($advertiserFit as $fit)
                    <tr class="border-b border-gray-100 dark:border-gray-800 last:border-0">
                        <td class="py-1.5 font-semibold w-32">{{ $fit['category'] ?? '—' }}</td>
                        <td class="py-1.5 w-20">
                            <div class="flex items-center gap-1">
                                <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-green-500" style="width: {{ ($fit['score'] ?? 0) * 10 }}%"></div>
                                </div>
                                <span class="font-bold text-xs">{{ $fit['score'] ?? 0 }}/10</span>
                            </div>
                        </td>
                        <td class="py-1.5 text-gray-500">{{ $fit['reason'] ?? '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- ── Nearby context ────────────────────────────────────── --}}
    <div class="border border-purple-200 dark:border-purple-800 rounded-lg overflow-hidden">
        <div class="px-3 py-2 bg-purple-50 dark:bg-purple-900/30 font-semibold text-purple-800 dark:text-purple-100 border-b border-purple-200 dark:border-purple-800">
            📍 Bối cảnh xung quanh
        </div>
        <div class="p-4 space-y-3">
            @if(! empty($nearby['highlights']))
                <p class="text-sm leading-relaxed">{{ $nearby['highlights'] }}</p>
            @endif
            @if(! empty($nearby['anchor_brands']))
                <div class="flex flex-wrap gap-1.5">
                    @foreach($nearby['anchor_brands'] as $brand)
                        <span class="px-2 py-0.5 rounded-full bg-purple-100 text-purple-700 text-xs font-semibold">{{ $brand }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ── Uncertainty notes ─────────────────────────────────── --}}
    @if(! empty($uncertainties))
    <div class="border border-yellow-200 dark:border-yellow-800 rounded-lg overflow-hidden">
        <div class="px-3 py-2 bg-yellow-50 dark:bg-yellow-900/30 font-semibold text-yellow-800 dark:text-yellow-100 border-b border-yellow-200 dark:border-yellow-800">
            ⚠ Uncertainty Notes (cần verify)
        </div>
        <div class="p-4">
            <ul class="list-disc list-inside text-xs text-gray-700 dark:text-gray-300 space-y-1">
                @foreach($uncertainties as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </div>
    </div>
    @endif

    @endif {{-- /if $ai --}}

</div>
