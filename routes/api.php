<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V2\CatalogController as V2CatalogController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\OohxEstimateController;
use App\Http\Controllers\Api\V1\OwnerController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\ScreenController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

// ── Public: OAuth2 client_credentials ───────────────────────
Route::prefix('v1')->group(function () {
    Route::post('auth/token', [AuthController::class, 'token'])->middleware('throttle:token');
});

// ── OOHX Public API — scope-protected ───────────────────────
Route::prefix('v1')->middleware(['auth:sanctum', 'ability:inventory'])->group(function () {
    // Stats & Owners (static routes trước dynamic routes)
    Route::get('inventory/stats',               [InventoryController::class, 'stats']);
    Route::get('inventory/venue-types',         [InventoryController::class, 'venueTypes']);
    Route::get('inventory/networks',            [InventoryController::class, 'networks']);
    Route::get('inventory/locations',           [InventoryController::class, 'locations']);
    Route::get('inventory/owners',              [InventoryController::class, 'owners']);
    Route::get('inventory/owners/{slug}',       [InventoryController::class, 'ownerDetail']);

    // Screens — /map phải đứng TRƯỚC /{screen_id} để tránh route conflict
    Route::get('inventory/screens/map',         [InventoryController::class, 'map']);
    Route::get('inventory/screens',             [InventoryController::class, 'index']);
    Route::get('inventory/screens/{screen_id}', [InventoryController::class, 'show']);

    Route::post('inventory/webhook/register',   [WebhookController::class, 'register']);

    // ── OOHX Data Engine estimates (read-only, qua SSH tunnel PostgreSQL) ──
    Route::get('oohx/estimates',                [OohxEstimateController::class, 'topByCity']);
    Route::get('oohx/estimates/{externalId}',   [OohxEstimateController::class, 'show']);
});

// ── Public player endpoints (screen UUID auth) ──────────────
Route::prefix('v1/player')->middleware('throttle:player')->group(function () {
    Route::post('heartbeat',  [PlayerController::class, 'heartbeat']);
    Route::post('impression', [PlayerController::class, 'impression']);
});

// ── Management API — ghi dữ liệu ────────────────────────────
// Yêu cầu ability 'manage' NGOÀI việc đăng nhập: token đối tác chỉ có 'inventory'
// nên không vào được nhóm này. Mỗi action vẫn phải tự gọi policy — ability chỉ là
// lớp chặn đầu tiên, không thay cho phân quyền theo bản ghi (audit F01).
Route::prefix('v1')->middleware(['auth:sanctum', 'ability:manage'])->group(function () {

    // Owner management (super_admin only)
    Route::apiResource('owners', OwnerController::class);
    Route::post('owners/{owner}/switch',   [OwnerController::class, 'switchContext']);
    Route::get('owners/{owner}/stats',     [OwnerController::class, 'stats']);

    // Sites (scoped to current owner via GlobalScope)
    Route::apiResource('sites', SiteController::class);

    // Screens
    Route::apiResource('screens', ScreenController::class);
    Route::get ('screens/{screen}/multipliers',    [ScreenController::class, 'multipliers']);
    Route::put ('screens/{screen}/multipliers',    [ScreenController::class, 'updateMultipliers']);
    Route::post('screens/{screen}/toggle-programmatic', [ScreenController::class, 'toggleProgrammatic']);
});

// ── /api/v2 — danh mục công khai cho trang công khai và Next.js ────────────
//
// Không đụng gì tới /api/v1: đó là hợp đồng đang chạy với đối tác. Tính năng
// mới cho ứng dụng nội bộ đi vào v2 (CLAUDE.md mục 1).
//
// Dữ liệu ở đây là dữ liệu công khai nên không đòi đăng nhập, nhưng vẫn có
// giới hạn tần suất và giới hạn cứng số bản ghi mỗi trang. Các nhóm cần quyền
// (giỏ hàng, đặt chỗ, thanh toán) sẽ vào nhóm riêng dùng Sanctum dạng SPA.
Route::prefix('v2')->middleware('throttle:api')->group(function () {
    Route::get('stats',           [V2CatalogController::class, 'stats']);
    Route::get('filters',         [V2CatalogController::class, 'filters']);

    // `screens/map` phải đứng TRƯỚC `screens/{slug}`, nếu không Laravel khớp
    // "map" vào {slug} và endpoint bản đồ biến thành một lần tra slug hỏng.
    Route::get('screens/map',     [V2CatalogController::class, 'map']);
    Route::get('screens',         [V2CatalogController::class, 'screens']);
    Route::get('screens/{slug}',  [V2CatalogController::class, 'screen']);

    Route::get('owners',          [V2CatalogController::class, 'owners']);
    Route::get('owners/{slug}',   [V2CatalogController::class, 'owner']);
});
