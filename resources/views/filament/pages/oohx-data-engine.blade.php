<x-filament-panels::page>
    {{-- ── Config sanity checks ─────────────────────────────────────── --}}
    <x-filament::section>
        <x-slot name="heading">Kiểm tra cấu hình</x-slot>
        <x-slot name="description">Verify env + file prerequisites trước khi sync.</x-slot>

        <ul class="space-y-2">
            @foreach($configCheck as $c)
                <li class="flex items-start gap-3">
                    <x-filament::icon
                        :icon="$c['ok'] ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'"
                        class="h-5 w-5 flex-shrink-0 {{ $c['ok'] ? 'text-success-500' : 'text-danger-500' }}"
                    />
                    <div>
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ $c['label'] }}</div>
                        @unless($c['ok'])
                            <div class="text-xs text-gray-500 mt-0.5">{{ $c['hint'] }}</div>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    {{-- ── Live stats từ Data Engine DB ─────────────────────────────── --}}
    <x-filament::section>
        <x-slot name="heading">Trạng thái Data Engine</x-slot>
        <x-slot name="description">Counts query trực tiếp từ oohx_data DB qua SSH tunnel.</x-slot>

        @if($stats['connected'] ?? false)
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-xs font-medium text-gray-500 uppercase">Màn hình trong core</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        {{ number_format($stats['screens_total']) }}
                    </div>
                </div>
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-xs font-medium text-gray-500 uppercase">Số ước lượng đã tính</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        {{ number_format($stats['estimates_total']) }}
                    </div>
                    @if($stats['estimates_total'] < $stats['screens_total'])
                        <div class="text-xs text-warning-600 mt-1">
                            {{ number_format($stats['screens_total'] - $stats['estimates_total']) }} screens chưa có estimate
                        </div>
                    @endif
                </div>
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-xs font-medium text-gray-500 uppercase">Synced (1h qua)</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">
                        {{ number_format($stats['recent_synced_1h']) }}
                    </div>
                </div>
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="text-xs font-medium text-gray-500 uppercase">Tính lần cuối</div>
                    <div class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ $stats['last_calculated'] ? \Illuminate\Support\Carbon::parse($stats['last_calculated'])->diffForHumans() : '—' }}
                    </div>
                    @if($stats['last_calculated'])
                        <div class="text-xs text-gray-500 mt-0.5">
                            {{ \Illuminate\Support\Carbon::parse($stats['last_calculated'])->format('Y-m-d H:i') }}
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="rounded-lg bg-danger-50 dark:bg-danger-950/30 border border-danger-200 dark:border-danger-800 p-4">
                <div class="flex items-start gap-3">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-danger-500 flex-shrink-0" />
                    <div>
                        <div class="text-sm font-medium text-danger-900 dark:text-danger-100">
                            Không kết nối được Data Engine DB
                        </div>
                        <div class="text-xs text-danger-700 dark:text-danger-300 mt-1 font-mono">
                            {{ $stats['error'] ?? 'unknown error' }}
                        </div>
                        <div class="text-xs text-danger-700 dark:text-danger-300 mt-2">
                            Check: SSH tunnel service (<code>systemctl status oohx-pg-tunnel</code>), port 5433 listening, DB_OOHX_PASSWORD đúng.
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-filament::section>

    {{-- ── Last sync run ────────────────────────────────────────────── --}}
    <x-filament::section>
        <x-slot name="heading">Lần đồng bộ cuối</x-slot>
        <x-slot name="description">Metadata của lần chạy <code>oohx:sync-to-engine</code> gần nhất (cached 7 ngày).</x-slot>

        @if($lastSync)
            <div class="space-y-4">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div>
                        <div class="text-xs font-medium text-gray-500 uppercase">Trạng thái</div>
                        <div class="mt-1">
                            @if($lastSync['status'] === 'success')
                                <span class="fi-badge inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-950/50 dark:text-success-400">
                                    <x-filament::icon icon="heroicon-m-check-circle" class="h-3.5 w-3.5" />
                                    Thành công
                                </span>
                            @else
                                <span class="fi-badge inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-950/50 dark:text-danger-400">
                                    <x-filament::icon icon="heroicon-m-x-circle" class="h-3.5 w-3.5" />
                                    Lỗi
                                </span>
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-gray-500 uppercase">Chạy bởi</div>
                        <div class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ $lastSync['triggered_by'] ?? '—' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-gray-500 uppercase">Thời lượng</div>
                        <div class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ $lastSync['duration_sec'] ?? 0 }}s
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-medium text-gray-500 uppercase">Lúc</div>
                        <div class="mt-1 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ \Illuminate\Support\Carbon::parse($lastSync['finished_at'])->diffForHumans() }}
                        </div>
                    </div>
                </div>

                @if($lastSync['status'] === 'success' && isset($lastSync['ingest_total']))
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 p-4">
                        <div class="text-xs font-medium text-gray-500 uppercase mb-2">Kết quả nạp</div>
                        <div class="flex items-center gap-6 text-sm">
                            <span>Tổng: <strong>{{ $lastSync['ingest_total'] }}</strong></span>
                            <span class="text-success-600">OK: <strong>{{ $lastSync['ingest_ok'] }}</strong></span>
                            @if($lastSync['ingest_fail'] > 0)
                                <span class="text-danger-600">Lỗi: <strong>{{ $lastSync['ingest_fail'] }}</strong></span>
                            @endif
                            @if($lastSync['file_size_kb'])
                                <span class="text-gray-500">Tệp: {{ $lastSync['file_size_kb'] }} KB</span>
                            @endif
                        </div>
                    </div>
                @endif

                @if(! empty($lastSync['error']))
                    <div class="rounded-lg bg-danger-50 dark:bg-danger-950/30 border border-danger-200 dark:border-danger-800 p-4">
                        <div class="text-xs font-medium text-danger-900 dark:text-danger-100 mb-1">Thông báo lỗi</div>
                        <pre class="text-xs text-danger-700 dark:text-danger-300 font-mono whitespace-pre-wrap">{{ $lastSync['error'] }}</pre>
                    </div>
                @endif

                @if(! empty($lastSync['output']))
                    <details class="group">
                        <summary class="cursor-pointer text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-primary-600">
                            Output log (last 3 KB) ▸
                        </summary>
                        <pre class="mt-2 rounded-lg bg-gray-900 text-gray-100 p-4 text-xs font-mono whitespace-pre-wrap overflow-x-auto max-h-96">{{ $lastSync['output'] }}</pre>
                    </details>
                @endif
            </div>
        @else
            <div class="text-sm text-gray-500 italic">Chưa có sync run nào được ghi nhận. Ấn <strong>Đồng bộ ngay</strong> ở header để bắt đầu.</div>
        @endif
    </x-filament::section>

    {{-- ── Documentation / links ────────────────────────────────────── --}}
    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Về integration này</x-slot>

        <div class="prose prose-sm dark:prose-invert max-w-none">
            <p><strong>Data Engine</strong> chạy Python + PostgreSQL + PostGIS trên VPS riêng ({{ config('oohx.data_engine.remote_host') }}), precompute traffic estimates (daily_passby, OTS, impressions, reach) cho mỗi screen từ OSM POI + road network data.</p>

            <p>Luồng xử lý:</p>
            <ol>
                <li><strong>Xuất</strong> (Laravel): SELECT active screens → JSON với <code>external_id = uuid</code></li>
                <li><strong>Rsync</strong>: upload JSON lên {{ config('oohx.data_engine.remote_inbox') }}</li>
                <li><strong>Nạp dữ liệu</strong> (Data Engine): parse JSON → upsert <code>core.screens</code></li>
                <li><strong>Tính lại</strong> (cron every 10 min): rule-based estimates → <code>output.screen_traffic_estimates</code></li>
                <li><strong>Đọc</strong> (Laravel): SELECT qua SSH tunnel port 5433 → hiển thị</li>
            </ol>

            <p>Scheduler cron Laravel tự gọi <code>oohx:sync-to-engine</code> mỗi 30 phút (không cần trigger thủ công trừ khi cần gấp).</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
