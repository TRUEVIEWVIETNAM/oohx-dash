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
 * ## Văn bản pháp lý: MỘT nguồn, MỘT bộ render
 *
 * Nội dung bốn trang chính sách nằm trong Blade, và `PolicyController` ghi rõ
 * lý do: *"bản chính sách nào đang có hiệu lực là chuyện phải truy được bằng
 * lịch sử git, không phải một hàng trong bảng mà ai đó sửa xong không còn dấu
 * vết."*
 *
 * Điều đó đặt ra một ràng buộc cho mọi đường đọc văn bản: **không bên nào được
 * dựng lại nó**. Nếu API tự tổ chức lại văn bản pháp lý thành HTML, hoặc app
 * Next.js chép nội dung sang component riêng, ta có hai bản — và với tài liệu
 * được đóng dấu `version` vào từng bản ghi đồng ý thì hai bản trôi khỏi nhau
 * nghĩa là mất bằng chứng, không phải lỗi hiển thị.
 *
 * Cách giữ ràng buộc đó:
 *
 * - Phần thân mỗi văn bản nằm ở MỘT partial: `policies.pages.*.body`.
 * - Trang Blade `@include` partial đó; `policy()` `view()` đúng partial đó.
 *   Một nguồn, một bộ render — Laravel và Next.js không thể hiện ra chữ khác
 *   nhau vì chúng chạy cùng một template.
 * - Luật *"bump `version` ở cùng commit đổi văn bản"* vẫn neo vào file Blade,
 *   vì văn bản vẫn ở file Blade.
 *
 * `policies()` (danh sách) chỉ trả siêu dữ liệu: nó phục vụ liên kết chân
 * trang, và nhét thân của bốn văn bản vào một response là phát hàng trăm dòng
 * HTML cho một việc không ai cần. `policy()` (chi tiết) trả thêm
 * `body_html`.
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
     * `GET /policies/{slug}` — siêu dữ liệu **và** phần thân văn bản.
     *
     * ══ Vì sao endpoint danh sách không trả nội dung mà endpoint này thì có ══
     *
     * Nguyên tắc đã đặt ở `policies()` là *"văn bản pháp lý chỉ có MỘT nơi phát
     * ra; API dựng lại nó thành HTML là tạo đường render thứ hai"*. Nguyên tắc
     * đó **vẫn giữ**, và endpoint này không vi phạm nó:
     *
     * - Nó KHÔNG dựng lại văn bản. Nó render đúng partial mà trang Blade
     *   render (`config('policies.pages.*.body')`), nên vẫn một bộ render và
     *   một nguồn.
     * - Danh sách vẫn chỉ có siêu dữ liệu: trả thân của bốn văn bản trong một
     *   response là phát ra hàng trăm dòng HTML cho một việc không ai cần.
     *
     * Thứ thay đổi là phần thân **đọc được qua HTTP**, và nó cần đọc được vì
     * app Next.js phải dựng trang chính sách mà không chép văn bản sang một
     * component riêng. Chép là tạo bản thứ hai, và với tài liệu được đóng dấu
     * phiên bản vào từng bản ghi đồng ý thì hai bản trôi khỏi nhau nghĩa là
     * mất bằng chứng.
     *
     * ══ Cái neo của luật `version` vẫn còn ══
     *
     * `config/policies.php` ghi: bump `version` ở CÙNG commit đổi file Blade.
     * Luật đó neo vào file Blade, và sau thay đổi này văn bản vẫn nằm trong
     * file Blade — chỉ thêm một đường đọc nó.
     *
     * ══ `body_html` là HTML tin được, nhưng không phải vì nó của mình ══
     *
     * Nội dung do chính repo này viết và đi qua git review, nên không có dữ
     * liệu người dùng trong đó. Nhưng bên tiêu thụ không nên tin điều đó một
     * cách mặc nhiên: nếu sau này ai đưa một biến người dùng nhập vào partial
     * thân, nó sẽ chảy thẳng vào `dangerouslySetInnerHTML`. Có test canh phần
     * thân không chứa `<script`.
     *
     * ══ Liên kết trong thân là URL TUYỆT ĐỐI, nên `APP_URL` phải đúng ══
     *
     * `bodies/terms.blade.php` có `url('/giai-quyet-tranh-chap')`,
     * và `route()` sinh URL tuyệt đối theo host của request đang xử lý. Khi
     * Next gọi vào `https://oohx.net/api/v2` thì liên kết ra đúng
     * `https://oohx.net/...`.
     *
     * Nhưng nếu ai đổi `OOHX_API_BASE` thành `http://127.0.0.1/api/v2` thì
     * những liên kết đó thành `http://127.0.0.1/...` và **được nướng vào HTML
     * tĩnh** của trang Next — một liên kết không ai ngoài máy chủ mở được,
     * trên một trang vẫn hiện bình thường. `webapp/lib/api.ts` ghi rõ vì sao
     * base phải là tên miền; đây là hệ quả thứ hai của cùng quy tắc đó.
     */
    public function policy(string $slug): JsonResponse
    {
        // Tra trong mảng, KHÔNG dùng `config("policies.pages.{$slug}")`.
        //
        // `config()` coi dấu chấm là dấu phân cấp, và `$slug` đến thẳng từ URL
        // (route param mặc định khớp cả dấu chấm). Với đường dẫn, slug
        // `quy-che-hoat-dong.view` tra ra chuỗi `"frontpage.policies.terms"` —
        // không rỗng, nên đi qua được chốt 404, rồi `$page['title']` trên một
        // chuỗi nổ TypeError 500. Người gọi nhận 500 cho một slug sai.
        $page = config('policies.pages', [])[$slug] ?? null;

        if (! is_array($page)) {
            return response()->json([
                'error'   => 'not_found',
                'message' => "Không có trang chính sách '{$slug}'.",
                'code'    => 404,
                'details' => [],
            ], 404);
        }

        return response()->json([
            'data' => [
                'slug'           => $slug,
                'title'          => $page['title'] ?? null,
                'version'        => $page['version'] ?? null,
                'effective_from' => $page['effective_from'] ?? null,
                'is_effective'   => ! empty($page['effective_from']),
                'url'            => url('/' . $slug),

                // Render đúng partial trang Blade dùng. Thiếu khóa `body` trong
                // config là lỗi cấu hình, không phải trường hợp hợp lệ — trả
                // chuỗi rỗng ở đây là để một trang chính sách trống đi ra ngoài
                // mà không ai báo.
                'body_html' => view($page['body'])->render(),
            ],
        ]);
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

                // Nhãn đi kèm mã, không để bên tiêu thụ tự tra.
                //
                // Bốn nhãn này là từ vựng nghiệp vụ và chúng nằm ở
                // `PublicReflection::STATUS_LABELS`. Nếu API chỉ trả mã thì mỗi
                // bên tiêu thụ phải chép bảng tra của riêng mình — Blade một
                // bản, Next một bản — và khi thêm một trạng thái, bản nào quên
                // sửa sẽ hiện mã thô ra cho người đọc.
                'status_label'      => $row->statusLabel(),
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
