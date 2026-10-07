<?php

use App\Http\Controllers\Buyer\BookingController;
use App\Http\Controllers\Buyer\BuyerAuthController;
use App\Http\Controllers\Buyer\BuyerCampaignController;
use App\Http\Controllers\Buyer\BuyerDashboardController;
use App\Http\Controllers\Buyer\BuyerReportController;
use App\Http\Controllers\Buyer\BuyerSettingsController;
use App\Http\Controllers\Buyer\CancellationController as BuyerCancellationController;
use App\Http\Controllers\Buyer\CartController;
use App\Http\Controllers\Buyer\OwnerReviewController;
use App\Http\Controllers\Buyer\PaymentController;
use App\Http\Controllers\CreativeFileController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

$fpDomain   = config('domains.frontpage', 'oohx.net');
$dashDomain = config('domains.dash', 'dash.oohx.net');

// ── Dashboard: dash.oohx.net ───────────────────────────
Route::domain($dashDomain)->group(function () {
    Route::get('/', fn () => redirect('/admin'));

    // ── User invitation accept flow (guest accessible) ──
    Route::get('/invitations/{token}/accept',  [InvitationController::class, 'show'])
        ->name('invitations.accept');
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'store'])
        ->name('invitations.accept.store');
});

// ── Frontpage: oohx.net ────────────────────────────────
Route::domain($fpDomain)->group(function () {

    // Sitemap
    Route::get('/sitemap.xml',        [SitemapController::class, 'index'])->name('sitemap');

    // ── Không còn trang công khai nào trên Blade ────────────────────────
    //
    // Giai đoạn 7 xong 07/10/2026. Next.js phục vụ cả 14 đường công khai;
    // Laravel ở nhóm này chỉ còn sinh `sitemap.xml` và `robots.txt`.
    //
    // `frontpage/layouts/app.blade.php` và các partial khung VẪN Ở LẠI, và
    // đó không phải sót: khu người mua (`/my`, `/cart`, `/booking/*`) còn là
    // Blade và `@extends` đúng layout đó. Giai đoạn 8 đang hoãn.

    // ── Buyer auth ──────────────────────────────────────────────────────
    //
    // Chỉ còn `logout`. `/login` và `/register` do Next phục vụ, và biểu mẫu
    // của chúng gửi sang `/api/v2/auth/*`.
    //
    // `logout` ở lại vì nó là POST từ khu người mua trên Blade (`/my/*`), và
    // khu đó chưa chuyển — giai đoạn 8 đang hoãn.
    //
    // Route tên `login` biến mất theo, nên `bootstrap/app.php` phải chỉ đích
    // danh đường dẫn cho khách chưa đăng nhập; không thì mọi khách vào `/my`
    // nhận `RouteNotFoundException` thay vì trang đăng nhập.
    Route::post('/logout', [BuyerAuthController::class, 'logout'])->name('buyer.logout')->middleware('auth');

    // ── Save/Bookmark (auth required) ──
    Route::post('/save/{screen}', function (\Illuminate\Http\Request $request, string $screen) {
        $exists = \App\Models\SavedItem::where('user_id', $request->user()->id)->where('screen_id', $screen)->first();
        if ($exists) {
            $exists->delete();
            return response()->json(['saved' => false]);
        }
        \App\Models\SavedItem::create(['user_id' => $request->user()->id, 'screen_id' => $screen]);
        return response()->json(['saved' => true]);
    })->middleware('auth')->name('screen.save');

    // ── Cart (auth required, no org needed) ──
    Route::middleware(['auth'])->group(function () {
        Route::get('/cart',           [CartController::class, 'index'])->name('buyer.cart');
        Route::post('/cart/add',      [CartController::class, 'add'])->name('buyer.cart.add');
        Route::put('/cart/{item}',    [CartController::class, 'update'])->name('buyer.cart.update');
        Route::delete('/cart/{item}', [CartController::class, 'remove'])->name('buyer.cart.remove');
        Route::get('/cart/count',     [CartController::class, 'count'])->name('buyer.cart.count');
    });

    // ── Booking wizard (auth required) ──
    Route::middleware(['auth'])->group(function () {
        Route::get('/booking/create',                  [BookingController::class, 'create'])->name('buyer.booking.create');
        Route::post('/booking/create',                 [BookingController::class, 'store'])->name('buyer.booking.store');
        Route::get('/booking/{campaign}/creative',     [BookingController::class, 'creative'])->name('buyer.booking.creative');
        Route::post('/booking/{campaign}/creative',    [BookingController::class, 'uploadCreative'])->name('buyer.booking.creative.upload');
        Route::get('/booking/{campaign}/review',       [BookingController::class, 'review'])->name('buyer.booking.review');
        Route::post('/booking/{campaign}/submit',      [BookingController::class, 'submit'])->name('buyer.booking.submit');
        Route::get('/booking/{campaign}/payment',      [PaymentController::class, 'show'])->name('buyer.payment');
        Route::post('/booking/{campaign}/payment',     [PaymentController::class, 'process'])->name('buyer.payment.process');
        Route::get('/booking/{campaign}/payment/success', [PaymentController::class, 'success'])->name('buyer.payment.success');
    });

    // ── Buyer dashboard (auth + buyer middleware) ──
    Route::middleware(['buyer'])->prefix('my')->name('buyer.')->group(function () {
        Route::get('/',                                [BuyerDashboardController::class, 'index'])->name('dashboard');
        Route::get('/campaigns',                       [BuyerCampaignController::class, 'index'])->name('campaigns');
        Route::get('/campaigns/{campaign}',            [BuyerCampaignController::class, 'show'])->name('campaigns.show');
        Route::get('/campaigns/{campaign}/report',   [BuyerReportController::class, 'show'])->name('campaigns.report');
        // Hủy đặt chỗ — kéo theo nghĩa vụ hoàn tiền, nên đi qua policy `cancel`
        // (xếp cùng `manage_payments`), không phải quyền xem.
        Route::post('/campaigns/{campaign}/lines/{line}/cancel', [BuyerCancellationController::class, 'store'])
            ->name('campaigns.lines.cancel');
        Route::post('/campaigns/{campaign}/reviews', [OwnerReviewController::class, 'store'])->name('campaigns.reviews.store');
        Route::get('/settings',                        [BuyerSettingsController::class, 'index'])->name('settings');
        Route::put('/settings/profile',                [BuyerSettingsController::class, 'updateProfile'])->name('settings.profile');
        Route::put('/settings/password',               [BuyerSettingsController::class, 'updatePassword'])->name('settings.password');
        Route::put('/settings/organization',           [BuyerSettingsController::class, 'updateOrganization'])->name('settings.organization');
    });

});

// ── Tệp nội dung quảng cáo (dùng được từ mọi domain) ──
//
// Không đặt trong nhóm domain nào, cùng lý do như `/geocode/search` dưới đây:
// nó phải phát được cho khu người mua trên `oohx.net`, bảng duyệt nội dung của
// Filament trên `dash.oohx.net`, và khu publisher.
//
// `route()` sinh URL theo host của request hiện tại, nên mỗi nơi nhận URL cùng
// host với phiên của chính nó — `SESSION_DOMAIN` để trống nghĩa là cookie phiên
// gắn theo từng host, và một URL trỏ sang host khác sẽ không mang theo phiên.
//
// Hai middleware, không phải một:
//
// - `signed` đặt hạn sống cho URL và chặn việc dò id. Thiếu nó thì
//   `/creatives/{id}/file` là một mặt tiền để thử từng id.
// - `auth` + `CreativePolicy` trong controller chặn người không có quyền.
//   Thiếu nó thì một URL lộ ra ngoài là đường vào dùng được tới khi hết hạn.
Route::get('/creatives/{creative}/file', CreativeFileController::class)
    ->middleware(['auth', 'signed'])
    ->name('creatives.file');

// ── Geocode proxy (accessible from all domains — admin, publisher, frontpage) ──
Route::get('/geocode/search', function () {
    $q = trim((string) request()->input('q', ''));
    if ($q === '') {
        return response()->json([]);
    }

    // Cache 24 giờ: Nominatim là dịch vụ miễn phí có hạn mức, và cùng một từ khoá
    // được tra đi tra lại khi người dùng gõ. Không cache thì proxy công khai này
    // vừa chậm vừa dễ khiến IP máy chủ bị chặn (audit F-10).
    return response()->json(
        Cache::remember('geocode:' . md5(mb_strtolower($q)), now()->addDay(), function () use ($q) {
            $response = Http::timeout(8)->withHeaders([
                'User-Agent' => 'OOHX/1.0',
                'Accept-Language' => 'vi,en',
            ])->get('https://nominatim.openstreetmap.org/search', [
                'format' => 'json',
                'limit'  => 5,
                'q'      => $q,
            ]);

            return $response->successful() ? $response->json() : [];
        })
    );
})->middleware('throttle:geocode');

// ── Fallback: nếu không match domain nào (www.oohx.net, IP, etc.)
Route::fallback(function () {
    return redirect('https://' . config('domains.frontpage', 'oohx.net'));
});
