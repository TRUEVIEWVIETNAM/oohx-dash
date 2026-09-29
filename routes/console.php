<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── OOHX Data Engine: sync screens every 30 minutes ──
// Non-overlapping: nếu export/rsync lần trước chưa xong thì skip lần này.
// Output append vào log riêng để debug nếu cần.
Schedule::command('oohx:sync-to-engine')
    ->everyThirtyMinutes()
    ->withoutOverlapping(15)   // lock 15 phút — đủ cho export vài ngàn screens
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oohx-sync.log'));

// ── Phase 3.A Part 2: fetch DE health digest via scp ──
// Primary target: health-digest-latest.json (DE cron refresh hourly +5min).
// Poll 10 phút để pick up cập nhật DE nhanh. Handoff §4.2.
Schedule::command('oohx:fetch-health')
    ->everyTenMinutes()
    ->withoutOverlapping(5)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/oohx-health.log'));

// ── Prune expired user invitations daily ──
Schedule::command('invitations:prune')->dailyAt('03:00');

// ── Dọn upload tạm Livewire bị bỏ dở (Livewire chỉ tự dọn khi upload hoàn tất) ──
Schedule::command('uploads:prune-livewire-tmp')->hourly()->withoutOverlapping();

// ── Dọn giữ chỗ hết hạn mỗi 5 phút ──
// Không phải cơ chế bảo vệ: phép tính sức chứa đã tự loại giữ chỗ hết hạn theo
// expires_at, nên lệnh này hỏng cũng không giam kho. Xem InventoryHoldService.
Schedule::command('inventory:purge-holds')->everyFiveMinutes()->withoutOverlapping();
