<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePublicReflectionRequest;
use App\Services\PublicReflectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nội dung công khai ngoài danh mục: trang chính sách và phản ánh.
 *
 * Đây là phần `/api/v2` mà giai đoạn 6 cần cho những route không phải màn hình
 * hay sản phẩm.
 *
 * ## Vì sao endpoint chính sách chỉ trả SIÊU DỮ LIỆU, không trả nội dung
 *
 * Nội dung bốn trang chính sách nằm trong Blade, và `PolicyController` ghi rõ
 * lý do: *"bản chính sách nào đang có hiệu lực là chuyện phải truy được bằng
 * lịch sử git, không phải một hàng trong bảng mà ai đó sửa xong không còn dấu
 * vết."*
 *
 * Nếu API dựng lại văn bản pháp lý thành HTML rồi Next.js hiển thị, ta có
 * **hai** đường render cho cùng một văn bản — và lộ trình yêu cầu "giữ nguyên
 * văn bản và đường dẫn" cho nhóm trang này. Hai đường render là hai cơ hội để
 * chúng khác nhau, mà khác nhau ở văn bản pháp lý thì không phải lỗi hiển thị.
 *
 * Nên endpoint này trả danh sách kèm `url` trỏ về trang Laravel đang phục vụ.
 * Next.js dùng nó để dựng liên kết chân trang và điều hướng; văn bản vẫn do
 * một nơi duy nhất phát ra. Lộ trình cũng đã xếp nhóm trang pháp lý chuyển
 * **cuối cùng**, nên đây đúng là thứ tự hợp lý.
 */
class PublicContentController extends Controller
{
    private const MAX_PER_PAGE = 50;

    private const DEFAULT_PER_PAGE = 20;

    public function __construct(private readonly PublicReflectionService $reflections) {}

    /**
     * Danh sách trang chính sách — siêu dữ liệu, không phải nội dung.
     */
    public function policies(): JsonResponse
    {
        $pages = collect(config('policies.pages', []))
            ->map(fn (array $page, string $slug) => [
                'slug'  => $slug,
                'title' => $page['title'] ?? null,
                // Phiên bản và ngày hiệu lực là thứ người đọc cần để biết họ
                // đang xem bản nào. `effective_from` rỗng nghĩa là CHƯA ban
                // hành — nội dung còn là bản nháp, và bên tiêu thụ phải thấy
                // được điều đó chứ không hiển thị như một văn bản đã có hiệu
                // lực.
                'version'        => $page['version'] ?? null,
                'effective_from' => $page['effective_from'] ?? null,
                'is_effective'   => ! empty($page['effective_from']),
                // Đường dẫn do Laravel phục vụ. Xem docblock của lớp.
                'url' => url('/' . $slug),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $pages]);
    }

    /**
     * Phản ánh của tổ chức xã hội đã được công bố.
     *
     * Dùng chung `PublicReflectionService::published()` với trang Blade, nên
     * hai nơi không thể hiện ra tập khác nhau — và quan trọng hơn: phép lọc
     * "đã công bố" chỉ có một định nghĩa.
     */
    public function reflections(Request $request): JsonResponse
    {
        $perPage = min(
            max(1, (int) $request->integer('per_page', self::DEFAULT_PER_PAGE)),
            self::MAX_PER_PAGE,
        );

        $page = $this->reflections->published($perPage);

        return response()->json([
            'data' => collect($page->items())->map(fn ($row) => [
                // `code` là mã công khai (PA-YYYYMM-nnn), không phải khóa nội bộ.
                'code'              => $row->code,
                'organization_name' => $row->organization_name,
                'subject'           => $row->subject,
                'content'           => $row->content,
                'status'            => $row->status,
                'resolution'        => $row->resolution,
                'received_at'       => $row->received_at?->toIso8601String(),
                'resolved_at'       => $row->resolved_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'page'         => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
                'max_per_page' => self::MAX_PER_PAGE,
            ],
        ]);
    }

    /**
     * Gửi một phản ánh mới.
     *
     * Dùng chung `StorePublicReflectionRequest` với trang Blade — cùng một bộ
     * luật kiểm, kể cả bẫy mật `website` => `prohibited`. Viết lại bộ luật thứ
     * hai cho API là mở một cửa vào mà trang web không có.
     *
     * Trả về **mã phản ánh** để người gửi tra cứu, và không trả gì khác: đây
     * là dữ liệu người lạ gửi lên, chưa ai duyệt.
     */
    public function storeReflection(StorePublicReflectionRequest $request): JsonResponse
    {
        $reflection = $this->reflections->record($request->validated(), $request->ip());

        return response()->json([
            'data' => [
                'code'    => $reflection->code,
                'message' => 'Đã tiếp nhận phản ánh. Vui lòng giữ mã này để tra cứu kết quả xử lý.',
            ],
        ], 201);
    }
}
