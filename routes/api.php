<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V2\BookingController as V2BookingController;
use App\Http\Controllers\Api\V2\BuyerAuthController as V2BuyerAuthController;
use App\Http\Controllers\Api\V2\CartController as V2CartController;
use App\Http\Controllers\Api\V2\CatalogController as V2CatalogController;
use App\Http\Controllers\Api\V2\MeController;
use App\Http\Controllers\Api\V2\PaymentController as V2PaymentController;
use App\Http\Controllers\Api\V2\PaymentRecipientController as V2PaymentRecipientController;
use App\Http\Controllers\Api\V2\PublicContentController as V2PublicContentController;
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
// Không khai `throttle:api` ở đây nữa.
//
// Nhóm `api` đã mang giới hạn tần suất. Trước đây khai lại ở route là vô hại
// vì `Route::gatherMiddleware()` có `array_unique` và hai chuỗi giống nhau bị
// gộp. Nay entry trong nhóm là `throttle.preauth:api` (để chạy trước xác
// thực), nên hai chuỗi KHÁC nhau và sẽ không gộp — tức đếm hai lần mỗi yêu
// cầu, và hạn mức thật chỉ còn một nửa mà không ai nhận ra.
Route::prefix('v2')->group(function () {
    Route::get('stats',           [V2CatalogController::class, 'stats']);
    Route::get('filters',         [V2CatalogController::class, 'filters']);

    // `screens/map` phải đứng TRƯỚC `screens/{slug}`, nếu không Laravel khớp
    // "map" vào {slug} và endpoint bản đồ biến thành một lần tra slug hỏng.
    // THỨ TỰ QUAN TRỌNG: mọi đường cố định phải đứng trước `{slug}`.
    //
    // Laravel khớp route theo thứ tự khai. Đặt `screens/featured` sau
    // `screens/{slug}` thì nó không bao giờ được gọi — thay vào đó
    // `screen('featured')` chạy, không tìm thấy màn hình nào có slug đó, và
    // trả 404. Một lỗi đọc log không ra nguyên nhân, vì 404 là câu trả lời
    // hợp lệ của endpoint kia.
    Route::get('screens/map',      [V2CatalogController::class, 'map']);
    Route::get('screens/pins',     [V2CatalogController::class, 'pins']);
    Route::get('screens/featured', [V2CatalogController::class, 'featuredScreens']);
    Route::get('screens',          [V2CatalogController::class, 'screens']);
    Route::get('screens/{slug}',   [V2CatalogController::class, 'screen']);

    Route::get('owners/featured',  [V2CatalogController::class, 'featuredOwners']);
    Route::get('owners',           [V2CatalogController::class, 'owners']);
    Route::get('owners/{slug}',    [V2CatalogController::class, 'owner']);

    Route::get('locations',        [V2CatalogController::class, 'locations']);

    // Agency/brand — bên MUA, khác hẳn /owners là bên bán. DTO hẹp hơn vì
    // nguồn là bảng Organization; xem AgencySummaryResource.
    Route::get('agencies',         [V2CatalogController::class, 'agencies']);

    Route::get('products',        [V2CatalogController::class, 'products']);
    Route::get('products/{slug}', [V2CatalogController::class, 'product']);

    // Nội dung công khai ngoài danh mục, cho những route của giai đoạn 6 không
    // phải màn hình hay sản phẩm.
    Route::get ('policies',    [V2PublicContentController::class, 'policies']);
    // Chi tiết kèm `body_html`. Slug chính sách là chuỗi cố định trong
    // `config/policies.php`, không phải khóa CSDL — nên không có ràng buộc
    // model binding ở đây, controller tự tra config và tự trả 404.
    Route::get ('policies/{slug}', [V2PublicContentController::class, 'policy']);
    Route::get ('reflections', [V2PublicContentController::class, 'reflections']);
    // Gửi phản ánh có hạn mức RIÊNG, chặt hơn `api`: đây là đường ghi, mở cho
    // người lạ, và trang Blade tương ứng cũng đã có `throttle:5,60`.
    Route::post('reflections', [V2PublicContentController::class, 'storeReflection'])
        ->middleware('throttle:5,60');
});

// ── /api/v2 — nhóm cần quyền (khu người mua) ──────────────────────────────
//
// Xác thực **Sanctum dạng SPA**: cookie phiên, cho người dùng trình duyệt
// (CLAUDE.md mục 2). Không dùng token đối tác ở đây.
//
// `EnsureFrontendRequestsAreStateful` gắn ở ĐÚNG NHÓM NÀY, không gọi
// `$middleware->statefulApi()` ở bootstrap/app.php. Gọi ở đó là thêm middleware
// phiên và CSRF cho **toàn bộ** `/api/*`, tức đụng vào `/api/v1` — hợp đồng
// đang chạy với đối tác, và CLAUDE.md mục 1 cấm đổi hành vi của nó. Phạm vi
// hẹp thì rủi ro hẹp.
//
// `buyer` kiểm người dùng có tổ chức; mỗi action vẫn tự gọi policy — middleware
// chỉ là lớp chặn đầu tiên, không thay cho phân quyền theo bản ghi.
// ── /api/v2 — đã đăng nhập, KHÔNG cần tổ chức ─────────────────────────────
//
// Chỉ có `/me`, và nó tách khỏi nhóm dưới vì một lý do cụ thể: nhóm dưới có
// middleware `buyer`, yêu cầu người dùng thuộc một tổ chức. Nhưng một người
// vừa đăng ký và chưa tạo tổ chức vẫn cần thanh điều hướng vẽ đúng — đặt
// `/me` sau `buyer` là trả 403 cho họ, và header hiện ra như thể họ chưa đăng
// nhập.
Route::prefix('v2')
    ->middleware([
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        'auth:sanctum',
    ])
    ->group(function () {
        Route::get('me', MeController::class);
    });

Route::prefix('v2')
    // Không khai `throttle` ở đây: giới hạn tần suất đã nằm trong nhóm `api`
    // và được đặt để chạy TRƯỚC xác thực (xem `bootstrap/app.php`). Khai thêm
    // một throttle cùng limiter ở route là cộng đôi số đếm, tức hạn mức thật
    // chỉ còn một nửa mà không ai nhận ra.
    ->middleware([
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        'auth:sanctum',
        'buyer',
    ])
    ->group(function () {
        Route::get   ('cart',              [V2CartController::class, 'show']);
        Route::post  ('cart/items',        [V2CartController::class, 'store']);
        Route::patch ('cart/items/{item}', [V2CartController::class, 'update']);
        Route::delete('cart/items/{item}', [V2CartController::class, 'destroy']);

        // ── Đặt chỗ (mốc 3) ───────────────────────────────────────────────
        Route::get('campaigns/{campaign}', [V2BookingController::class, 'show']);

        // Hai đường GHI của đặt chỗ có hạn mức RIÊNG, chặt hơn nhóm `api`.
        //
        // `throttle:10,1` là một limiter inline, tức một khóa đếm khác với
        // `throttle.preauth:api` của nhóm — nên nó KHÔNG cộng đôi số đếm như
        // chú thích ở đầu nhóm cảnh báo. Khai thêm cùng một limiter mới cộng
        // đôi.
        //
        // Vì sao chặt hơn: mỗi lần `POST campaigns` tạo một campaign kèm toàn
        // bộ `booking_lines` từ giỏ, tức tạo nghĩa vụ tiền và giữ suất. Hạn
        // mức 300/phút của nhóm `api` là quá rộng cho một thao tác như vậy.
        Route::post('campaigns', [V2BookingController::class, 'store'])
            ->middleware('throttle:10,1');

        // Tải tệp lên: hạn mức chặt hơn nữa. Đây là đường duy nhất của
        // `/api/v2` nhận tệp, và mỗi lần gọi có thể ghi 50MB vào đĩa.
        Route::post('campaigns/{campaign}/creatives', [V2BookingController::class, 'storeCreative'])
            ->middleware('throttle:20,10');

        Route::post('campaigns/{campaign}/submit', [V2BookingController::class, 'submit'])
            ->middleware('throttle:10,1');

        // ── Thanh toán (mốc 3) ────────────────────────────────────────────
        Route::get('campaigns/{campaign}/payments', [V2PaymentController::class, 'index']);

        // Nơi chuyển tiền tới — đường DUY NHẤT của cả hệ thống mang `bank_*`
        // và `tax_code` ra ngoài. Ngoại lệ được duyệt 08/10/2026; lý do nghiệp
        // vụ ở `OwnerRemittanceResource`, năm lớp chặn ở controller.
        //
        // `throttle:20,1` là limiter inline, tức khóa đếm riêng — không cộng
        // đôi số đếm của nhóm như chú thích đầu nhóm cảnh báo.
        //
        // Vì sao 20/phút chứ không để hạn mức 300/phút của nhóm: một trang
        // thanh toán gọi đúng một lần, tải lại vài lần là cùng. Con số nào cao
        // hơn thế đều là dấu hiệu của một script đang quét, và đây là đường
        // không nên quét được.
        Route::get('campaigns/{campaign}/payment-recipients', V2PaymentRecipientController::class)
            ->middleware('throttle:20,1');

        // Chặt hơn nữa: đây là đường ghi vào bảng tiền. Khóa chống trùng đã
        // chặn lần gửi lặp của cùng một biểu mẫu, nhưng hạn mức chặn thứ khác
        // — một script gọi liên tục với mã khác nhau mỗi lần.
        Route::post('campaigns/{campaign}/payments', [V2PaymentController::class, 'store'])
            ->middleware('throttle:10,1');
    });

// ── /api/v2/auth — đăng nhập, đăng ký, đăng xuất cho app Next.js ───────────
//
// ══ Vì sao nhóm RIÊNG, không gộp vào nhóm công khai ══
//
// Nhóm này mang `EnsureFrontendRequestsAreStateful`: nó TẠO PHIÊN, nên nó cần
// middleware phiên và CSRF. Nhóm công khai (`screens`, `reflections`) thì
// không — chúng không tạo phiên, và bắt chúng đi qua CSRF là buộc biểu mẫu
// phản ánh phải lấy cookie trước khi gửi, đổi lại không được gì.
//
// Nhưng nó KHÔNG mang `auth:sanctum`: người đang đăng nhập thì cần gì đăng
// nhập nữa. `logout` thì ngược lại, nên nó nằm ở nhóm dưới.
//
// ══ `throttle:login` ══
//
// Cùng hạn mức với đường Blade, và nó đếm theo HAI khóa: theo IP, và theo
// `email|IP`. Chỉ đếm theo IP thì một văn phòng chung IP tự chặn nhau; chỉ đếm
// theo email thì một script đổi email mỗi lần không bị chặn gì.
Route::prefix('v2/auth')
    ->middleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class])
    ->group(function () {
        Route::post('login', [V2BuyerAuthController::class, 'login'])
            ->middleware('throttle:login');

        // Đăng ký cũng chặt: nó là đường TẠO bản ghi, mở cho người lạ, và mỗi
        // lần gọi tạo một người dùng cộng một tổ chức.
        Route::post('register', [V2BuyerAuthController::class, 'register'])
            ->middleware('throttle:login');
    });

Route::prefix('v2/auth')
    ->middleware([
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        'auth:sanctum',
    ])
    ->group(function () {
        Route::post('logout', [V2BuyerAuthController::class, 'logout']);
    });
