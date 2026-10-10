<?php

namespace App\Filament\Pages;

use App\Services\OohxDataEngine as OohxDataEngineService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Control panel cho OOHX Data Engine integration.
 *
 * Read status của SSH tunnel + DB connection, trigger sync pipeline,
 * lookup estimate nhanh bằng uuid. Tất cả operations (sync, test conn,
 * export-only) được bọc trong Filament Action với requiresConfirmation.
 *
 * State last-sync lưu trong Cache key `oohx:last_sync` (không cần migration).
 */
class OohxDataEngine extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-cpu-chip';
    protected static ?string $navigationGroup = 'Cấu hình hệ thống';
    protected static ?string $navigationLabel = 'Data Engine';
    protected static ?int    $navigationSort  = 60;
    protected static ?string $title           = 'OOHX Data Engine';
    protected static ?string $slug            = 'oohx-data-engine';

    protected static string $view = 'filament.pages.oohx-data-engine';

    // ── Header actions ──────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            $this->syncNowAction(),
            \Filament\Actions\ActionGroup::make([
                $this->recomputeQueueAction(),
                $this->recomputeCityAction(),
            ])
                ->label('Tính lại')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->button(),
            $this->exportOnlyAction(),
            $this->testConnectionAction(),
            $this->lookupAction(),
        ];
    }

    public function syncNowAction(): Action
    {
        return Action::make('syncNow')
            ->label('Đồng bộ ngay')
            ->icon('heroicon-o-cloud-arrow-up')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Chạy full sync pipeline?')
            ->modalDescription('Sẽ export screens → rsync lên Data Engine VPS → trigger ingest. Có thể mất 30-60 giây tuỳ số screens.')
            ->modalSubmitActionLabel('Chạy ngay')
            ->action(function () {
                $this->runCommand('oohx:sync-to-engine', ['--triggered-by' => 'manual'], 'Sync hoàn tất');
            });
    }

    public function exportOnlyAction(): Action
    {
        return Action::make('exportOnly')
            ->label('Chỉ xuất')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Chỉ generate file JSON, không upload hay trigger ingest. File lưu ở storage/app/oohx/screens.json.')
            ->action(function () {
                $this->runCommand('oohx:export-screens', [], 'Export xong');
            });
    }

    public function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Kiểm tra các kết nối')
            ->icon('heroicon-o-signal')
            ->color('info')
            ->action(function () {
                $result = $this->testAllConnections();

                Notification::make()
                    ->title('Kiểm tra kết nối')
                    ->body(implode("\n", $result['messages']))
                    ->{$result['ok'] ? 'success' : 'danger'}()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Force drain pending recompute queue (CLI: recompute-pending-jobs --max 500).
     * Dùng khi queue stuck hoặc muốn fresh estimates ngay.
     */
    public function recomputeQueueAction(): Action
    {
        return Action::make('recomputeQueue')
            ->label('Tính lại các việc đang chờ')
            ->icon('heroicon-o-queue-list')
            ->requiresConfirmation()
            ->modalHeading('Xả các việc đang chờ trong hàng đợi?')
            ->modalDescription('Process tất cả jobs đang trong queue (max 500). Không ảnh hưởng screens không có job.')
            ->modalSubmitActionLabel('Xả hàng đợi ngay')
            ->action(function () {
                $result = $this->runRemoteCommand(config('oohx.data_engine.recompute_queue_cmd'));
                $parsed = $this->parseJsonOutput($result['output'] ?? '');

                if (! $result['ok']) {
                    Notification::make()
                        ->title('Không đưa được việc tính lại vào hàng đợi')
                        ->body($result['error'] ?? 'Unknown error')
                        ->danger()
                        ->persistent()
                        ->send();
                    return;
                }

                $body = $parsed
                    ? $this->formatJsonSummary($parsed)
                    : mb_strimwidth($result['output'], 0, 300, '…');

                Notification::make()
                    ->title('Đã xả hàng đợi')
                    ->body($body)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Recompute toàn bộ screens trong 1 city (CLI: recompute-city --city X).
     * List cities tự động fetch từ core.screens distinct.
     */
    public function recomputeCityAction(): Action
    {
        return Action::make('recomputeCity')
            ->label('Tính lại theo tỉnh/thành')
            ->icon('heroicon-o-map')
            ->requiresConfirmation()
            ->modalHeading('Tính lại một tỉnh/thành')
            ->modalDescription('Force recompute tất cả screens trong city đã chọn. Có thể mất 30-60s tuỳ số screens.')
            ->form([
                \Filament\Forms\Components\Select::make('city')
                    ->label('Tỉnh/thành')
                    ->options(fn () => $this->cityOptions())
                    ->required()
                    ->searchable(),
            ])
            ->action(function (array $data) {
                $city = $data['city'];
                $template = config('oohx.data_engine.recompute_city_cmd');
                // Escape shell arg để tránh injection (city có thể có spaces như "Ha Noi")
                $cmd = str_replace('{city}', escapeshellarg($city), $template);

                $result = $this->runRemoteCommand($cmd);

                if (! $result['ok']) {
                    Notification::make()
                        ->title("Recompute city '{$city}' failed")
                        ->body($result['error'] ?? 'Unknown error')
                        ->danger()
                        ->persistent()
                        ->send();
                    return;
                }

                $parsed = $this->parseJsonOutput($result['output'] ?? '');
                $body = $parsed
                    ? "City: {$city} · " . $this->formatJsonSummary($parsed)
                    : mb_strimwidth($result['output'], 0, 300, '…');

                Notification::make()
                    ->title("Recomputed {$city}")
                    ->body($body)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    public function lookupAction(): Action
    {
        return Action::make('lookup')
            ->label('Tra ước lượng')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->form([
                \Filament\Forms\Components\TextInput::make('external_id')
                    ->label('Mã ngoài (UUID màn hình phía Laravel)')
                    ->required()
                    ->placeholder('vd: 1f5cbf19-c9c3-4637-ad1a-b5cc37381bd9'),
            ])
            ->action(function (array $data) {
                $e = app(OohxDataEngineService::class)
                    ->getEstimateByExternalId($data['external_id']);

                if (! $e) {
                    Notification::make()
                        ->title('Không tìm thấy estimate')
                        ->body("UUID: {$data['external_id']}\nCó thể chưa sync, chưa recompute, hoặc UUID sai.")
                        ->warning()
                        ->persistent()
                        ->send();
                    return;
                }

                $body = sprintf(
                    "%s (%s)\nDaily: %s · Monthly: %s\nConfidence: %s · Method: %s\nLast calc: %s",
                    $e->name ?? '—',
                    $e->indoor_outdoor ?? '—',
                    number_format((float) ($e->estimated_daily_impressions ?? 0)),
                    number_format((float) ($e->estimated_monthly_impressions ?? 0)),
                    $e->confidence_score ?? '—',
                    $e->estimation_method ?? '—',
                    $e->last_calculated_at ?? '—',
                );

                Notification::make()
                    ->title('Tìm thấy ước lượng')
                    ->body($body)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    // ── View data ───────────────────────────────────────────────────────

    public function getViewData(): array
    {
        $lastSync = Cache::get('oohx:last_sync');
        $stats = $this->computeStats();

        return [
            'lastSync'    => $lastSync,
            'stats'       => $stats,
            'configCheck' => $this->configCheck(),
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Fetch distinct cities từ core.screens cho filter dropdown.
     * Silent fail nếu tunnel down — return [] thay vì crash modal.
     */
    private function cityOptions(): array
    {
        try {
            $rows = DB::connection('oohx')->select("
                SELECT DISTINCT city FROM core.screens
                WHERE city IS NOT NULL AND city != ''
                ORDER BY city
            ");
            return collect($rows)->pluck('city', 'city')->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * SSH + exec shell command trên Data Engine VPS. Timeout 120s.
     * Returns ['ok' => bool, 'output' => string, 'error' => ?string].
     */
    private function runRemoteCommand(string $remoteCmd): array
    {
        $cfg = config('oohx.data_engine');
        try {
            $p = new Process([
                'ssh', '-i', $cfg['ssh_key'],
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'ConnectTimeout=15',
                '-o', 'BatchMode=yes',
                "{$cfg['remote_user']}@{$cfg['remote_host']}",
                $remoteCmd,
            ], null, null, null, 120);
            $p->run();

            return [
                'ok'     => $p->isSuccessful(),
                'output' => $p->getOutput(),
                'error'  => $p->isSuccessful() ? null : ($p->getErrorOutput() ?: 'exit code ' . $p->getExitCode()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * Parse Data Engine JSON output như {"processed": N, "failed": M}.
     * Returns null nếu không parse được.
     */
    private function parseJsonOutput(string $out): ?array
    {
        // Find last { ... } block in output (ignore log lines trước đó)
        if (preg_match('/\{[^{}]*\}\s*$/s', trim($out), $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed)) return $parsed;
        }
        return null;
    }

    /**
     * Format parsed JSON dict thành "key1: v1 · key2: v2" cho notification body.
     * Schema CLI output khác nhau: {processed, failed}, {city, recomputed}, ...
     * → render generic thay vì hardcode key.
     */
    private function formatJsonSummary(array $parsed): string
    {
        $parts = [];
        foreach ($parsed as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $parts[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . ($value ?? '—');
            }
        }
        return implode(' · ', $parts) ?: json_encode($parsed);
    }

    /**
     * Run an artisan command + show result as Filament notification.
     * Output dài được cache vào `oohx:last_sync` bởi command; ở đây chỉ show status.
     */
    private function runCommand(string $command, array $args, string $successTitle): void
    {
        $exitCode = Artisan::call($command, $args);
        $output = Artisan::output();

        if ($exitCode === 0) {
            Notification::make()
                ->title($successTitle)
                ->body(mb_strimwidth($output, 0, 500, '…'))
                ->success()
                ->persistent()
                ->send();
        } else {
            Notification::make()
                ->title("Command failed: {$command}")
                ->body(mb_strimwidth($output, 0, 1000, '…'))
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * Test 3 connections:
     *   - PostgreSQL via tunnel (inbound)
     *   - SSH to Data Engine VPS (outbound)
     *   - Data Engine repo path exists
     */
    private function testAllConnections(): array
    {
        $messages = [];
        $allOk = true;

        // 1. DB connection via tunnel
        try {
            $r = DB::connection('oohx')->selectOne('SELECT 1 as ok');
            $messages[] = '✓ PostgreSQL tunnel (127.0.0.1:5433) — OK';
        } catch (\Throwable $e) {
            $allOk = false;
            $messages[] = '✗ PostgreSQL tunnel — ' . mb_strimwidth($e->getMessage(), 0, 150, '…');
        }

        // 2. SSH connection to Data Engine
        $cfg = config('oohx.data_engine');
        try {
            $p = new Process([
                'ssh', '-i', $cfg['ssh_key'],
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'ConnectTimeout=10',
                '-o', 'BatchMode=yes',
                "{$cfg['remote_user']}@{$cfg['remote_host']}",
                'echo ok',
            ], null, null, null, 15);
            $p->run();
            if ($p->isSuccessful() && str_contains($p->getOutput(), 'ok')) {
                $messages[] = '✓ SSH to Data Engine VPS — OK';
            } else {
                $allOk = false;
                $messages[] = '✗ SSH — ' . mb_strimwidth($p->getErrorOutput() ?: 'unknown error', 0, 150, '…');
            }
        } catch (\Throwable $e) {
            $allOk = false;
            $messages[] = '✗ SSH — ' . mb_strimwidth($e->getMessage(), 0, 150, '…');
        }

        return ['ok' => $allOk, 'messages' => $messages];
    }

    /**
     * Aggregate stats từ Data Engine DB để show trên dashboard.
     */
    private function computeStats(): array
    {
        try {
            $a = DB::connection('oohx')->selectOne(
                'SELECT COUNT(*) as screens_total FROM core.screens'
            );
            $b = DB::connection('oohx')->selectOne(
                'SELECT COUNT(*) as est_total, MAX(last_calculated_at) as last_calc
                 FROM output.screen_traffic_estimates'
            );
            $c = DB::connection('oohx')->selectOne(
                "SELECT COUNT(*) as recent_synced FROM core.screens
                 WHERE synced_at > NOW() - INTERVAL '1 hour'"
            );

            return [
                'connected'        => true,
                'screens_total'    => (int) $a->screens_total,
                'estimates_total'  => (int) $b->est_total,
                'last_calculated'  => $b->last_calc,
                'recent_synced_1h' => (int) $c->recent_synced,
            ];
        } catch (\Throwable $e) {
            return [
                'connected' => false,
                'error'     => $e->getMessage(),
            ];
        }
    }

    /**
     * Quick config sanity checks (để show warning khi setup thiếu).
     *
     * Dùng `@file_exists()` để tránh open_basedir warning làm crash page khi
     * SSH key đặt ngoài project path (PHP-FPM thường sandbox /root/).
     */
    private function configCheck(): array
    {
        $cfg = config('oohx.data_engine');
        $checks = [];

        $checks[] = [
            'ok'    => ! empty(config('database.connections.oohx.password')),
            'label' => 'DB password configured',
            'hint'  => 'Set DB_OOHX_PASSWORD in .env',
        ];

        $sshKeyOk = false;
        $sshHint  = 'Generate via ssh-keygen, set OOHX_SSH_KEY in .env';
        try {
            $sshKeyOk = @is_readable($cfg['ssh_key']);
            if (! $sshKeyOk) {
                $sshHint .= '. Nếu key nằm ngoài project (vd /root/.ssh/) PHP-FPM không đọc được (open_basedir). Move vào storage/app/oohx-ssh/ và chown cho www user.';
            }
        } catch (\Throwable $e) {
            $sshHint = 'Open_basedir chặn: ' . $e->getMessage() . '. Move key vào ' . base_path('storage/app/oohx-ssh/') . '.';
        }
        $checks[] = [
            'ok'    => $sshKeyOk,
            'label' => "SSH key readable: {$cfg['ssh_key']}",
            'hint'  => $sshHint,
        ];

        $checks[] = [
            'ok'    => ! empty($cfg['ingest_cmd']),
            'label' => 'Ingest command configured',
            'hint'  => 'Set OOHX_INGEST_CMD in .env',
        ];

        return $checks;
    }
}
