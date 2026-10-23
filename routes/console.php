<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
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

// ── Hâm nóng bộ lọc /explore, mỗi 15 phút ────────────────────────────────
//
// Cache của bộ lọc sống 1800 giây, nên không có lệnh này thì **cứ 30 phút
// một người dùng thật trả toàn bộ phép tính**: người đầu tiên tới sau khi
// hết hạn là người tính. Đo 23/10/2026 trên 50.000 bản ghi giả: 1.362ms,
// 11 truy vấn — đường dốc nhất của `/explore` (7,2× khi dữ liệu gấp 5).
//
// 15 phút là **một nửa** thời hạn, có chủ ý: chạy đúng 30 phút thì một lượt
// chạy trượt (máy chủ tải cao, `withoutOverlapping` chặn) để lại một khoảng
// cache nguội, và người dùng lại là người tính. Một nửa thời hạn cho phép
// bỏ hẳn một lượt mà không ai thấy gì.
//
// Không phải cơ chế bảo vệ: `getFilterAggregates()` vẫn tự tính khi cache
// nguội. Lệnh chết nghĩa là quay về hành vi cũ, không phải bộ lọc trống —
// nên nó chỉ cần một dòng log khi hỏng, không cần cảnh báo gắt.
Schedule::command('oohx:ham-nong-bo-loc')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onFailure(fn () => Log::warning(
        'oohx:ham-nong-bo-loc đỏ — bộ lọc /explore quay về tự tính khi cache nguội. '
        . 'Không hỏng gì với người dùng, chỉ chậm hơn cho một người mỗi 30 phút.',
    ));

// ── Prune expired user invitations daily ──
Schedule::command('invitations:prune')->dailyAt('03:00');

// ── Dọn upload tạm Livewire bị bỏ dở (Livewire chỉ tự dọn khi upload hoàn tất) ──
Schedule::command('uploads:prune-livewire-tmp')->hourly()->withoutOverlapping();

// ── Dọn giữ chỗ hết hạn mỗi 5 phút ──
// Không phải cơ chế bảo vệ: phép tính sức chứa đã tự loại giữ chỗ hết hạn theo
// expires_at, nên lệnh này hỏng cũng không giam kho. Xem InventoryHoldService.
Schedule::command('inventory:purge-holds')->everyFiveMinutes()->withoutOverlapping();

// ── Tổng hợp lượt phát theo ngày ──
// Chạy mỗi giờ cho hôm nay + hôm qua: thiết bị mất mạng gửi bù sau nửa đêm là
// chuyện thường, nên chỉ tổng hợp hôm nay sẽ bỏ sót phần gửi muộn của hôm trước.
Schedule::command('impressions:rollup')->hourly()->withoutOverlapping();

// ── Soát nguồn ảnh nền bản đồ, bốn lần một ngày ──────────────────────────
//
// Ngày 18/10/2026 nguồn tile hỏng bốn lần, và **không lần nào** có một dòng
// lỗi nào được ghi ở đâu:
//
//  1. OSM trả 503 — bản đồ nền xám, sống nhiều ngày trước khi ai đó nhìn.
//  2. Carto trả HTTP 200 kèm ảnh "API KEY REQUIRED" — mọi phép kiểm xanh.
//  3. MapTiler chưa có khóa — `deploy.sh` chạy bản cũ của chính nó, deploy
//     báo "success", khóa không tới nơi.
//  4. MapTiler style rỗng → `maps//{z}/…` → 503 mọi ô — dự phòng Esri nhảy
//     vào nên trang trông hoàn hảo.
//
// Điểm chung: bản đồ **trông như đang chạy**. Nên lớp canh không thể là mắt
// người, và cũng không thể là mã trạng thái — phải là một lệnh định kỳ tải
// một ô thật rồi cân nó.
//
// Bốn lần/ngày: đủ để một sự cố không sống qua đêm, và tốn 8 tile/ngày —
// 240 trong tháng, so với hạn mức 100.000 của MapTiler thì không đáng kể.
//
// Hạn mức cạn giữa tháng là kịch bản lệnh này sinh ra để bắt: MapTiler sẽ
// trả 403 kèm ảnh "Invalid key", trình duyệt vẫn vẽ ảnh đó, và `tileerror`
// của Leaflet không bắn một lần nào.
Schedule::command('map:kiem-tile')
    ->everySixHours()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/oohx-map-tile.log'))
    ->onFailure(function () {
        // Ghi ở mức `error` chứ không chỉ vào log riêng ở trên: log riêng là
        // nơi tra cứu sau khi đã biết có chuyện, không phải nơi phát hiện ra
        // chuyện. Mức `error` là thứ một kênh cảnh báo sẽ nhặt được khi dự
        // án nối một kênh — hôm nay chưa có kênh nào, và đó là một lỗ hổng
        // đã biết, không phải một chỗ bị quên.
        Log::error(
            'map:kiem-tile đỏ — nguồn ảnh nền bản đồ không trả về bản đồ. '
            . 'Chi tiết ở storage/logs/oohx-map-tile.log. '
            . 'Nguồn sống ở config/map.php và webapp/lib/ban-do.ts.',
        );
    });
