<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrontpageListingRequest;
use App\Http\Resources\V2\OwnerSummaryResource;
use App\Http\Resources\V2\ScreenSummaryResource;
use App\Models\Screen;
use App\Services\FrontpageService;
use App\Services\InventoryHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Danh mục công khai cho `/api/v2` — nguồn dữ liệu cho trang công khai.
 *
 * **Một đường truy vấn, không hai.** Controller này gọi đúng `FrontpageService`
 * mà trang Blade đang dùng, thay vì viết lại truy vấn. Đó là điều kiện để hợp
 * đồng API và trang thật không trôi khỏi nhau — `InventoryController` với
 * `FrontpageService` đã từng trôi và gây rò rỉ dữ liệu chưa duyệt, nên đây
 * không phải lo xa.
 *
 * Khác biệt giữa API và trang nằm ở **DTO**, chứ không ở truy vấn.
 *
 * Endpoint ở đây là dữ liệu công khai nên không đòi đăng nhập, nhưng vẫn có
 * giới hạn tần suất và **giới hạn cứng** cho số bản ghi mỗi trang.
 */
class CatalogController extends Controller
{
    /** Số bản ghi tối đa một trang, kể cả khi client đòi nhiều hơn. */
    private const MAX_PER_PAGE = 50;

    private const DEFAULT_PER_PAGE = 20;

    public function __construct(private readonly FrontpageService $catalog) {}

    public function stats(): JsonResponse
    {
        $stats = $this->catalog->getHeroStats();

        return response()->json([
            'data' => [
                'total_screens' => (int) $stats['total_screens'],
                'total_cities'  => (int) $stats['total_cities'],
                'total_owners'  => (int) $stats['total_owners'],
            ],
        ]);
    }

    /**
     * Danh sách màn hình, phân trang có giới hạn cứng.
     *
     * Dùng `FrontpageListingRequest` — cùng bộ luật kiểm với trang công khai,
     * nên bộ lọc không thể khác nhau giữa hai nơi.
     */
    public function screens(FrontpageListingRequest $request): JsonResponse
    {
        $perPage = min(
            max(1, (int) $request->integer('per_page', self::DEFAULT_PER_PAGE)),
            self::MAX_PER_PAGE,
        );

        $page = $this->catalog->getScreensPaginated($request, $perPage);

        return response()->json([
            'data' => ScreenSummaryResource::collection($page->getCollection())->resolve(),
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
     * Chi tiết một màn hình, kèm suất còn lại trong 30 ngày tới.
     *
     * Suất còn lại là con số tính thật (gồm cả suất người khác đang giữ trong
     * giỏ), không phải nhãn trang trí — xem `InventoryHoldService`.
     */
    public function screen(string $slug): JsonResponse
    {
        $screen = Screen::publiclyVisible()
            ->with(['spec', 'inventory', 'owner:id,name,slug,cover_url', 'site', 'site.network'])
            ->where('slug', $slug)
            ->first();

        if (! $screen) {
            return $this->notFound('Không tìm thấy màn hình với slug này.');
        }

        $remaining = app(InventoryHoldService::class)->remainingSov(
            $screen,
            now()->toDateString(),
            now()->addDays(30)->toDateString(),
        );

        return response()->json([
            'data' => (new ScreenSummaryResource($screen))->resolve() + [
                'availability' => [
                    'window_days'         => 30,
                    'remaining_sov_pct'   => $remaining,
                    'has_capacity'        => $remaining > 0,
                ],
            ],
        ]);
    }

    public function owners(Request $request): JsonResponse
    {
        $perPage = min(
            max(1, (int) $request->integer('per_page', self::DEFAULT_PER_PAGE)),
            self::MAX_PER_PAGE,
        );

        $page = $this->catalog->getOwnersPaginated($request, $perPage);

        return response()->json([
            'data' => OwnerSummaryResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'page'         => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
                'max_per_page' => self::MAX_PER_PAGE,
            ],
        ]);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json([
            'error'   => 'not_found',
            'message' => $message,
            'code'    => 404,
            'details' => [],
        ], 404);
    }
}
