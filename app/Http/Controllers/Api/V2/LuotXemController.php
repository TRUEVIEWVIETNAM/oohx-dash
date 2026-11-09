<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Services\FrontpageService;
use App\Services\LuotXemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Đường nhận của máy đếm lượt xem — lát 1 của `docs/PLAN-LUOT-XEM.md`.
 *
 * ══ Vì sao một POST riêng, KHÔNG đếm trong `GET /screens/{slug}` ══
 *
 * Đếm ngay trong phép đọc trang chi tiết là cách viết ít mã nhất và là cách
 * sai. Trang `/explore/{slug}` là một trang Next.js **render ở máy chủ**, nên
 * mỗi lượt gọi `GET /api/v2/screens/{slug}` là một trong những thứ sau:
 *
 *  · một người thật mở trang;
 *  · một lượt render lại của Next khi cache hết hạn;
 *  · một con bot đọc `sitemap.xml` — sàn có 104 URL chi tiết trong sitemap;
 *  · một lượt `next build` prerender.
 *
 * Chỉ dạng đầu là một lượt xem. Ba dạng còn lại là máy chủ tự nói chuyện với
 * chính nó, và chúng **đông hơn** người thật ở một sàn mới. Con số sẽ đo lưu
 * lượng crawler, và nó sai theo đúng chiều tệ nhất: vị trí nào Google ghé
 * nhiều thì trông "phổ biến" nhất.
 *
 * Còn một cái bẫy thứ hai, độc lập: `getScreenDetail()` bọc trong
 * `Cache::remember` 300 giây. Một phép cộng đặt bên trong closure đó chỉ chạy
 * khi cache hết hạn — tức tối đa 12 lượt mỗi giờ cho mỗi màn hình, bất kể bao
 * nhiêu người mở. Đặt bên ngoài thì nó đếm cả ba dạng trên. Không có chỗ nào
 * trong phép đọc ấy là chỗ đúng.
 *
 * Một POST từ trình duyệt loại hết bốn dạng trên bằng chính cơ chế của nó:
 * chỉ mã chạy trong máy người dùng mới gọi được. Bot **chạy JS** vẫn đếm
 * được — ghi lại ở mục rủi ro của plan, chưa chặn.
 *
 * ══ 204, thân rỗng ══
 *
 * Không có gì để dò. Một phản hồi mang `{"so_luot": 318}` biến đường này
 * thành chỗ đọc số liệu kinh doanh của sàn — vị trí nào đang được quan tâm —
 * bằng cách gửi vào một lượt, và nó công khai, không đăng nhập. Số lượt xem là
 * thứ lát 3 quyết định có công bố hay không, và quyết định đó thuộc giao diện,
 * không thuộc một đường ghi.
 *
 * Thân rỗng cũng là điều đúng cho phía gọi: client `fetch` mà **không**
 * `await`, nuốt lỗi im lặng. Không ai chờ phản hồi này.
 *
 * ══ 404 khi slug không phải màn hình công khai ══
 *
 * Không lộ gì hơn `GET /screens/{slug}` đã lộ — cùng một phép kiểm
 * `publiclyVisible()`, cùng một câu trả lời. Nhưng nó chặn một thứ thật: không
 * có nó thì ai cũng dựng được số lượt xem cho những màn hình **đang tắt**, và
 * những con số ấy nằm sẵn trong bảng chờ ngày một màn hình được bật lại.
 *
 * ══ Ba lớp chặn, vì đây là đường GHI mở cho người lạ ══
 *
 *  1. `throttle:60,60` ở route.
 *  2. Phải là một màn hình công khai thật — slug lạ thì 404, không ghi hàng nào.
 *  3. Bảng đích không lớn theo số lượt gửi: khoá duy nhất `(ngay, screen_id)`
 *     gộp mọi lượt của cùng một ô vào một hàng.
 *
 * Lớp thứ ba là lớp quan trọng nhất, vì hai lớp đầu chỉ làm việc bơm số
 * **khó hơn**, còn lớp ba làm nó không phình được bảng — chỉ làm vài con số
 * cao hơn sự thật.
 */
class LuotXemController extends Controller
{
    public function __construct(
        private readonly FrontpageService $catalog,
        private readonly LuotXemService $luotXem,
    ) {
    }

    public function store(string $slug): Response|JsonResponse
    {
        // Phép tra sống ở danh mục, dùng chung đúng một định nghĩa "màn hình
        // công khai" với trang chi tiết (CLAUDE.md mục 1). Viết lại phép kiểm
        // ở đây là bản luật thứ hai, và nó sẽ lệch đúng vào ngày cổng hiển thị
        // công khai đổi.
        $id = $this->catalog->timIdCongKhai($slug);

        if ($id === null) {
            // Dạng lỗi thống nhất của `/api/v2` (CLAUDE.md mục 2), không phải
            // một 404 thân rỗng: client ở đây không đọc thân, nhưng hợp đồng
            // của tiền tố này thì có, và một đường trả khác dạng là một ngoại
            // lệ không ai biết cho tới lúc có người gọi nó bằng tay.
            return response()->json([
                'error'   => 'not_found',
                'message' => 'Không tìm thấy màn hình công khai với slug này.',
                'code'    => 404,
                'details' => [],
            ], 404);
        }

        $this->luotXem->ghiNhan($id);

        return response()->noContent();
    }
}
