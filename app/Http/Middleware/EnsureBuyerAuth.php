<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn đầu tiên cho khu người mua: phải đăng nhập, và phải thuộc một tổ chức.
 *
 * Đây **không** thay cho phân quyền theo bản ghi. Mỗi action vẫn tự gọi policy
 * (`CampaignPolicy`, `CartItemPolicy`) — middleware chỉ trả lời "người này có
 * tư cách người mua hay không", không trả lời "có được chạm vào campaign này
 * hay không".
 *
 * ══ Vì sao có nhánh JSON ══
 *
 * Bản cũ luôn `redirect()`. Với trang Blade thì đúng, nhưng nhóm `/api/v2` cần
 * quyền dùng **chính middleware này**, nên một người dùng đã đăng nhập mà chưa
 * có tổ chức sẽ nhận **302 sang /register** thay vì envelope lỗi. Client JSON
 * đi theo redirect, nhận về HTML trang đăng ký, và lỗi hiện ra dưới dạng "JSON
 * parse error" — không liên quan gì tới nguyên nhân thật.
 *
 * CLAUDE.md mục 2 đòi định dạng lỗi thống nhất `{error, message, code,
 * details[]}` cho `/api/v2`. Lỗi này đã có từ mốc giỏ hàng; nó lộ ra khi thêm
 * nhóm đặt chỗ vì đó là lúc có test gọi vào bằng một người dùng không tổ chức.
 */
class EnsureBuyerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $this->wantsEnvelope($request)
                ? $this->envelope('unauthenticated', 'Bạn cần đăng nhập để dùng chức năng này.', 401)
                : redirect('/login');
        }

        // Phải thuộc ít nhất một tổ chức — tài khoản người mua.
        if (! $request->user()->organizations()->exists()) {
            return $this->wantsEnvelope($request)
                ? $this->envelope(
                    'organization_required',
                    'Tài khoản này chưa thuộc tổ chức nào. Cần tạo tổ chức để đặt chỗ.',
                    403,
                )
                : redirect('/register')
                    ->withErrors(['organization' => 'Bạn cần tạo tổ chức để sử dụng tính năng này.']);
        }

        // Đặt `current_organization_id` nếu còn trống.
        if (! $request->user()->current_organization_id) {
            $request->user()->update([
                'current_organization_id' => $request->user()->organizations()->first()->id,
            ]);
        }

        return $next($request);
    }

    /**
     * Chỉ `api/v2/*` nhận envelope.
     *
     * Không dùng `expectsJson()` một mình: một yêu cầu AJAX từ trang Blade cũng
     * `expectsJson()`, và trang đó đang chờ đúng hành vi redirect cũ. Phạm vi
     * hẹp thì không đụng vào thứ đang chạy.
     */
    private function wantsEnvelope(Request $request): bool
    {
        return $request->is('api/v2/*');
    }

    private function envelope(string $error, string $message, int $status): Response
    {
        return response()->json([
            'error'   => $error,
            'message' => $message,
            'code'    => $status,
            'details' => [],
        ], $status);
    }
}
