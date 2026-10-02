<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
// Không `use Throwable;`: file này ở namespace toàn cục nên câu đó vô tác dụng
// và PHP in cảnh báo ở MỌI lần boot.

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        // Bật throttle cho nhóm api. Trước đây không có dòng này nên Laravel không
        // chèn middleware throttle, và toàn bộ /api/* chạy không giới hạn — kể cả
        // /auth/token (dò client_secret) và endpoint player (audit F-10).
        $middleware->throttleApi();

        // Giới hạn tần suất phải chạy TRƯỚC xác thực.
        //
        // Laravel có một danh sách ưu tiên cố định và **sắp lại** middleware
        // theo nó, bất kể thứ tự mình khai ở route. Trong danh sách mặc định,
        // `AuthenticatesRequests` đứng trước `ThrottleRequests` — nên mọi
        // endpoint đòi đăng nhập bị `auth` chặn trước khi throttle kịp đếm,
        // tức **không có giới hạn nào** với lưu lượng chưa đăng nhập. Tôi đã
        // thử đổi thứ tự khai báo ở `routes/api.php` và nó vô tác dụng đúng vì
        // lý do này.
        //
        // Trái CLAUDE.md mục 2 ("mọi endpoint có giới hạn tần suất"), và một
        // endpoint chỉ bị hạn SAU khi đăng nhập thì phần dễ bị dội nhất của nó
        // không được bảo vệ.
        //
        // PHẠM VI: đây là thay đổi toàn cục, áp cả `/api/v1`. Với đối tác có
        // token hợp lệ thì hành vi **không đổi** — cả hai middleware đều chạy,
        // chỉ khác thứ tự. Thứ đổi là client gửi token sai sẽ gặp 429 sau một
        // số lần, thay vì 401 không giới hạn. Đó là siết chặt, không phải đổi
        // hợp đồng.
        $middleware->prependToPriorityList(
            before: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            prepend: \Illuminate\Routing\Middleware\ThrottleRequests::class,
        );
        $middleware->alias([
            'ability' => \App\Http\Middleware\CheckTokenAbility::class,
            'buyer'   => \App\Http\Middleware\EnsureBuyerAuth::class,
        ]);
        $middleware->encryptCookies(except: ['oohx_city']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API routes luôn trả JSON 401 thay vì redirect về route('login')
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            // v2 dùng ĐÚNG envelope thống nhất, kể cả ở 401.
            //
            // Trước đây mọi `api/*` dùng chung nhánh dưới, nên 401 của v2
            // thiếu `code` và `details` — bên tiêu thụ viết một hàm xử lý lỗi
            // rồi vẫn phải thêm một nhánh đặc biệt cho đúng mã này.
            if ($request->is('api/v2/*')) {
                return response()->json([
                    'error'   => 'unauthorized',
                    'message' => 'Bạn cần đăng nhập để dùng chức năng này.',
                    'code'    => 401,
                    'details' => [],
                ], 401);
            }

            if ($request->is('api/*')) {
                return response()->json([
                    'error'   => 'unauthorized',
                    'message' => 'Token không hợp lệ hoặc đã hết hạn',
                ], 401);
            }
        });

        // ── Định dạng lỗi thống nhất cho /api/v2 ─────────────────────────────
        //
        // `{error, message, code, details[]}` ở MỌI lỗi, không chỉ lỗi validate.
        // Lý do đặt ở đây chứ không trong từng controller: bên tiêu thụ viết
        // một hàm xử lý lỗi duy nhất, còn một endpoint quên định dạng là một
        // nhánh xử lý đặc biệt phải viết mãi mãi.
        //
        // KHÔNG áp cho /api/v1: đó là hợp đồng đang chạy với đối tác, đổi hình
        // dạng lỗi là làm hỏng code của họ mà không báo trước.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/v2/*')) {
                return null;
            }

            return response()->json([
                'error'   => 'validation_failed',
                'message' => 'Dữ liệu gửi lên không hợp lệ.',
                'code'    => 422,
                'details' => collect($e->errors())
                    ->map(fn (array $messages, string $field) => [
                        'field'   => $field,
                        'message' => $messages[0] ?? '',
                    ])
                    ->values()
                    ->all(),
            ], 422);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/v2/*')) {
                return null;
            }

            $status = $e->getStatusCode();

            return response()->json([
                'error'   => match ($status) {
                    401     => 'unauthorized',
                    403     => 'forbidden',
                    404     => 'not_found',
                    409     => 'conflict',
                    422     => 'unprocessable',
                    429     => 'too_many_requests',
                    default => 'error',
                },
                'message' => $e->getMessage() ?: 'Yêu cầu không thực hiện được.',
                'code'    => $status,
                'details' => [],
                // Giữ header của HttpException (Codex R41).
                //
                // `ThrottleRequests` gắn `Retry-After` và `X-RateLimit-*` vào
                // exception, còn 405 mang `Allow`. Bản trước tạo JsonResponse
                // chỉ với body và status nên các header đó biến mất: client
                // gặp 429 mà không biết chờ bao lâu. Đổi định dạng **body**
                // không được phá thông tin điều khiển của giao thức.
            ], $status, $e->getHeaders());
        });

        // Lỗi hệ thống của /api/v2 cũng phải có envelope (Codex R40).
        //
        // Hai renderer trên chỉ bắt `ValidationException` và
        // `HttpExceptionInterface`. Một `ErrorException` — ví dụ "Array to
        // string conversion" khi client gửi `?q[]=x` — đi ngoài cả hai, nên
        // tuyên bố "mọi lỗi v2 có định dạng thống nhất" của tôi là sai.
        //
        // Renderer này **không** dùng để che lỗi: khi `app.debug` bật thì
        // thông điệp thật vẫn ra để còn gỡ được, còn ở production chỉ có câu
        // chung và không bao giờ có stack trace.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/v2/*')) {
                return null;
            }

            if ($e instanceof ValidationException || $e instanceof HttpExceptionInterface) {
                return null;
            }

            // Không gọi `report()` ở đây: Laravel đã báo lỗi trước khi
            // render, thêm nữa chỉ làm log đôi.
            return response()->json([
                'error'   => 'server_error',
                'message' => config('app.debug')
                    ? $e::class . ': ' . $e->getMessage()
                    : 'Đã có lỗi phía máy chủ. Vui lòng thử lại sau.',
                'code'    => 500,
                'details' => [],
            ], 500);
        });
    })->create();
