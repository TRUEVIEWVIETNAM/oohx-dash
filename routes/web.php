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
use Illuminate\Http\Request;
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
    // Laravel ở nhóm này chỉ còn sinh `sitemap.xml`.
    //
    // `robots.txt` thì KHÔNG phải route — nó là file tĩnh ở `public/robots.txt`
    // và OpenLiteSpeed phục vụ trực tiếp. Chú thích cũ gộp nó với `sitemap.xml`,
    // nên ai đi tìm route cho nó sẽ không tìm thấy gì và tưởng là bị sót.
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
        // Ba bước đặt chỗ — chỉ còn đường ĐỌC, và chúng chỉ trả khung trang.
        //
        // Ba đường GHI (`POST /booking/create`, `POST .../creative`,
        // `POST .../submit`) đã gỡ 10/10/2026: ba trang gửi qua
        // `POST /api/v2/campaigns`, `.../creatives` và `.../submit`. Cả ba
        // endpoint đó dùng ĐÚNG `StoreCampaignRequest`, `UploadCreativeRequest`
        // và `SubmitCampaignRequest` mà bản Blade dùng, nên không có hai bộ
        // luật validate.
        //
        // Giữ lại cả hai đường cho cùng một việc ghi là có hai nơi định nghĩa
        // một luật (CLAUDE.md §1), và nơi không ai gọi sẽ là nơi không ai sửa.
        Route::get('/booking/create',                  [BookingController::class, 'create'])->name('buyer.booking.create');
        Route::get('/booking/{campaign}/creative',     [BookingController::class, 'creative'])->name('buyer.booking.creative');
        Route::get('/booking/{campaign}/review',       [BookingController::class, 'review'])->name('buyer.booking.review');
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
        // Chỉ còn đường ĐỌC, và nó chỉ trả khung trang.
        //
        // Ba đường `PUT /my/settings/*` đã gỡ 10/10/2026: trang gửi qua
        // `PUT /api/v2/me/{profile,password,organization}`. Giữ lại cả hai là
        // có hai nơi định nghĩa cùng một luật ghi (CLAUDE.md §1), và nơi không
        // ai gọi sẽ là nơi không ai sửa khi luật đổi.
        //
        // Ca test bảo mật của chúng (`CaiDatToChucTest`) chuyển sang đường API
        // cùng lượt — một chốt canh một đường không ai gọi thì không canh gì.
        Route::get('/settings',                        [BuyerSettingsController::class, 'index'])->name('settings');
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

// ── Fallback ────────────────────────────────────────────────────────────────
//
// Bản trước chuyển hướng MỌI đường không khớp về trang chủ:
//
//     Route::fallback(fn () => redirect('https://' . config('domains.frontpage')));
//
// Chú thích của nó nói mục đích là "không match domain nào (www.oohx.net, IP)".
// Nhưng `fallback` không phân biệt được hai chuyện khác hẳn nhau:
//
//   1. Host lạ — mọi route của site đều nằm trong `Route::domain()`, nên không
//      cái nào khớp và đường dẫn nào cũng rơi xuống đây. Chuyển hướng là ĐÚNG.
//   2. Host đúng, đường dẫn không tồn tại. Chuyển hướng là SAI: Google đọc
//      302-về-trang-chủ như một "soft 404" và giữ URL chết trong chỉ mục thay
//      vì bỏ nó đi.
//
// Sau giai đoạn 6–7 trường hợp 2 nặng hơn hẳn: Laravel không còn route công
// khai nào, nên mọi đường proxy không nhận đều rơi xuống đây. Đo trên
// production 07/10/2026: `/gioi-thieu`, `/lien-he`, `/blog/bai-1` đều ra 302
// về `/`.
//
// Và nó không chỉ là chuyện SEO. `/api/*` không khoá theo domain nhưng đường
// SAI vẫn rơi xuống fallback, nên `/api/v1/khong-ton-tai` trả **302 sang một
// trang HTML**. Một client đối tác bật `followRedirects` — mặc định ở phần lớn
// thư viện HTTP — nhận 200 kèm HTML trang chủ và có thể đọc đó là thành công.
// Gõ sai tên endpoint mà được báo "ổn" là kiểu lỗi im lặng tệ nhất.
Route::fallback(function (Request $request) {
    // ── API xét TRƯỚC host ─────────────────────────────────────────────────
    //
    // Thứ tự này có chủ ý. `/api/*` không nằm trong `Route::domain()`, nên một
    // đường API ĐÚNG khớp route thật và không bao giờ tới được fallback — thứ
    // tới đây luôn là đường SAI. Với một đường sai thì câu trả lời hữu ích là
    // "endpoint này không tồn tại", kể cả khi người gọi đồng thời dùng sai
    // host. Xét host trước thì họ nhận một chuyển hướng sang HTML và mất hẳn
    // thông tin đó.
    if ($request->is('api/v1/*')) {
        // Trả thẳng ở đây, KHÔNG thêm renderer cho `api/v1/*` trong
        // `bootstrap/app.php`. Một renderer ở đó sẽ bắt mọi 404 của v1, kể cả
        // `abort(404)` bên trong controller — tức đổi hình dạng lỗi của những
        // endpoint đối tác đang gọi thật. Ở đây phạm vi đúng bằng "đường dẫn
        // không khớp route nào", và không đường nào trong hợp đồng v1 rơi vào
        // đó được.
        //
        // Hai khoá `error` + `message`, giống `invalid_client` và `unauthorized`
        // của v1. KHÔNG thêm `code`/`details` — đó là envelope của v2.
        return response()->json([
            'error'   => 'not_found',
            'message' => 'Endpoint không tồn tại.',
        ], 404);
    }

    if ($request->is('api/*')) {
        // `/api/v2/*` tự ra đúng envelope `{error, message, code, details}`:
        // khối `HttpExceptionInterface` trong `bootstrap/app.php` đã bắt sẵn.
        // Nên ở đây không viết lại định dạng đó lần thứ hai — hai bản của một
        // định dạng là hai bản sẽ trôi khỏi nhau.
        abort(404);
    }

    // ── Host lạ: đưa về tên miền chính, GIỮ NGUYÊN đường dẫn ────────────────
    //
    // Bản trước vứt đường dẫn đi, nên ai mở `<ip>/explore` cũng rơi về trang
    // chủ. Giữ lại thì họ tới đúng trang; còn nếu đường đó cũng không tồn tại
    // thì họ nhận 404 thật ở nhánh dưới — vẫn đúng hơn một trang chủ im lặng.
    //
    // Không có nguy cơ chuyển hướng ra ngoài: đích luôn ghép từ hằng số tên
    // miền trong config, phần lấy từ request chỉ nằm SAU dấu `/` đầu tiên.
    // `ltrim` gộp mọi dấu `/` thừa nên `//evil.example` thành
    // `https://oohx.net/evil.example` — một đường dẫn trên chính site này.
    //
    // `$chinh !== ''` không phải phép kiểm thừa: nếu `FRONTPAGE_DOMAIN` bị đặt
    // rỗng thì `config()` trả chuỗi rỗng (giá trị mặc định chỉ dùng khi KHÔNG
    // có khoá), mọi host thành "lạ", và đích `https:///...` tạo một vòng
    // chuyển hướng vô tận. Một 404 ở cấu hình sai thì còn gỡ được.
    $chinh = (string) config('domains.frontpage', 'oohx.net');
    $biet  = array_filter([$chinh, (string) config('domains.dash')]);

    if ($chinh !== '' && ! in_array($request->getHost(), $biet, true)) {
        return redirect()->away(
            'https://' . $chinh . '/' . ltrim($request->getRequestUri(), '/')
        );
    }

    // ── Host đúng, đường dẫn chết: 404 thật ────────────────────────────────
    abort(404);
});
