<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Resources\V2\MeResource;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v2/me` — người đang đăng nhập, chỉ những gì header cần vẽ.
 *
 * ══ Vì sao endpoint này tồn tại ══
 *
 * Trang công khai trên Next.js (giai đoạn 6) phải vẽ thanh điều hướng giống
 * bản Blade, mà header Blade đọc `auth()->user()`: tên, email, tổ chức, số món
 * giỏ. Next **không giải mã được session của Laravel** — cookie có đó nhưng
 * định dạng là của Laravel.
 *
 * ══ Và vì sao trạng thái đăng nhập KHÔNG render ở máy chủ ══
 *
 * Đây là chỗ nguy hiểm nhất của cả việc này. Trang `/explore` bên Next cache
 * 60 giây. Render tên và email vào HTML ở máy chủ nghĩa là bản cache đó phục
 * vụ cho người tiếp theo — **tên và email của người A hiện ra cho người B**.
 * Một lỗi rò dữ liệu, im lặng, và chỉ phát hiện được khi có người báo.
 *
 * Nên khung header render dưới dạng **khách**, rồi một component client gọi
 * endpoint này để điền. Trang vẫn cache được, vì phần cache không chứa gì
 * riêng của ai.
 *
 * ══ Không nằm sau middleware `buyer` ══
 *
 * Nhóm `/api/v2` cần quyền có thêm `buyer`, yêu cầu người dùng thuộc một tổ
 * chức. Nhưng một người đã đăng nhập mà **chưa** có tổ chức vẫn cần header
 * vẽ đúng — họ vừa đăng ký và chưa tạo tổ chức. Đặt endpoint này sau `buyer`
 * là trả 403 cho họ, và header hiện ra như thể họ chưa đăng nhập.
 *
 * ══ 401 là câu trả lời, không phải sự cố ══
 *
 * Khách chưa đăng nhập nhận 401 với envelope thống nhất. Client coi 401 là
 * "khách" và giữ nguyên khung đã render. Không có nhánh nào trả 200 kèm
 * `data: null`, vì khi đó bên tiêu thụ phải phân biệt hai loại null.
 */
class MeController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => (new MeResource($user, $this->carts->getItemCount($user)))->resolve(),
        ]);
    }
}
