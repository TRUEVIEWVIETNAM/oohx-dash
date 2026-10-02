<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Giới hạn tần suất **trước** xác thực.
 *
 * ## Vấn đề
 *
 * Laravel sắp lại middleware theo một danh sách ưu tiên cố định, bất kể thứ tự
 * khai ở route. Danh sách mặc định (`Foundation\Http\Kernel::$middlewarePriority`)
 * đặt `AuthenticatesRequests` **trước** `ThrottleRequests`. Hệ quả: mọi endpoint
 * đòi đăng nhập bị `auth` chặn trước khi throttle kịp đếm — tức **không có giới
 * hạn nào** với lưu lượng chưa đăng nhập. Ai cũng dội được vào
 * `/api/v2/cart` không hạn, và mỗi lần dội vẫn tốn công dựng phiên và giải mã
 * cookie. Trái CLAUDE.md mục 2: "mọi endpoint có giới hạn tần suất".
 *
 * ## Ba cách tôi đã thử và vì sao chúng không chạy
 *
 * 1. **Đổi thứ tự khai báo ở `routes/api.php`** — vô tác dụng, phép sắp lại ghi
 *    đè thứ tự khai báo.
 * 2. **`$middleware->prependToPriorityList()`** — cũng vô tác dụng.
 *    `Kernel::addToMiddlewarePriorityRelative()` mở đầu bằng
 *    `if (! in_array($middleware, $this->middlewarePriority))`, mà
 *    `ThrottleRequests` **đã có** trong danh sách, nên cả khối bị bỏ qua. Hàm
 *    đó chỉ dùng để chèn middleware MỚI.
 * 3. **Kế thừa `ThrottleRequests`** — cũng bị sắp về đúng chỗ ấy, vì
 *    `SortedMiddleware::middlewareNames()` yield cả `class_parents()`.
 *
 * ## Cách đang dùng
 *
 * Lớp này **không** kế thừa `ThrottleRequests` và không cài interface nào có
 * trong danh sách ưu tiên, nên nó giữ đúng vị trí mình được khai. Việc đếm thì
 * **uỷ quyền nguyên vẹn** cho `ThrottleRequests` — không viết lại logic hạn
 * mức, không tự sinh header `Retry-After`/`X-RateLimit-*`. Viết lại là cách để
 * hai bộ luật trôi khỏi nhau.
 *
 * Chọn cách này thay vì `$middleware->priority([...])` (khai lại cả danh sách)
 * để **không** đụng tới `/api/v1`: khai lại cả danh sách là nhận trách nhiệm
 * đồng bộ nó với framework ở mỗi lần nâng cấp, và một middleware mới của
 * Laravel lọt ra ngoài danh sách sẽ chạy sai thứ tự mà không ai biết.
 *
 * Dùng như `throttle` thường: `'throttle.preauth:api'`.
 */
class ThrottleBeforeAuth
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next, ...$args): Response
    {
        // Truyền thẳng tham số sang: `throttle.preauth:api` phải hành xử đúng
        // như `throttle:api`, kể cả nhánh "named limiter" mà `handle()` của
        // framework nhận ra qua `func_num_args() === 3`.
        return $this->throttle->handle($request, $next, ...$args);
    }
}
