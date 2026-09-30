<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\MapViewportRequest;
use App\Http\Requests\FrontpageListingRequest;
use App\Http\Resources\V2\MapPinResource;
use App\Http\Resources\V2\OwnerDetailResource;
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

    /** Số pin tối đa một lần gọi bản đồ, kể cả khi khung nhìn rất rộng. */
    private const MAX_MAP_PINS = 500;

    private const DEFAULT_MAP_PINS = 300;

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

    /**
     * Pin bản đồ trong một khung nhìn.
     *
     * Khung nhìn **bắt buộc** — thiếu là 422, không phải "mặc định cả thế
     * giới". Xem `MapViewportRequest` về lý do.
     *
     * Vẫn có giới hạn cứng số pin bên trên khung nhìn, vì khung nhìn rộng vẫn
     * có thể trùm cả kho. Khi bị cắt thì nói thẳng trong `meta`: client biết
     * mình đang xem thiếu và cần thu nhỏ khung nhìn, thay vì tưởng đã thấy
     * hết. Một bản đồ trả thiếu trong im lặng còn tệ hơn một bản đồ báo lỗi.
     */
    public function map(MapViewportRequest $request): JsonResponse
    {
        $viewport = $request->viewport();

        $limit = min(
            max(1, (int) $request->integer('limit', self::DEFAULT_MAP_PINS)),
            self::MAX_MAP_PINS,
        );

        $pins  = $this->catalog->getMapPins($request, $viewport, $limit);
        $total = $this->catalog->countMapPins($request, $viewport);

        return response()->json([
            'data' => MapPinResource::collection($pins)->resolve(),
            'meta' => [
                'returned'  => $pins->count(),
                'total'     => $total,
                'limit'     => $limit,
                'max_limit' => self::MAX_MAP_PINS,
                'truncated' => $total > $pins->count(),
                'viewport'  => $viewport,
            ],
        ]);
    }

    /**
     * Bộ lọc tổng hợp cho trang khám phá: thành phố, mạng lưới, owner, loại
     * điểm đặt, khoảng giá.
     *
     * Dùng chung `getFilterAggregates()` với trang Blade, nên danh sách lọc
     * hiện ra ở hai nơi không thể khác nhau.
     */
    public function filters(): JsonResponse
    {
        $aggregates = $this->catalog->getFilterAggregates();

        return response()->json([
            'data' => [
                'cities' => collect($aggregates['cities'] ?? [])
                    ->map(fn ($city) => [
                        'code'  => $city['code'] ?? null,
                        'name'  => $city['name'] ?? null,
                        'count' => (int) ($city['count'] ?? 0),
                    ])->values()->all(),

                'venue_types' => collect($aggregates['formats'] ?? [])
                    ->map(fn ($format) => [
                        'code'  => $format['type'] ?? null,
                        'name'  => $format['label'] ?? null,
                        'count' => (int) ($format['count'] ?? 0),
                    ])->values()->all(),

                'networks' => collect($aggregates['networks'] ?? [])
                    ->map(fn ($network) => [
                        'code'  => $network->code ?? null,
                        'name'  => $network->name ?? null,
                        'count' => (int) ($network->count ?? 0),
                    ])->values()->all(),

                'owners' => collect($aggregates['owners'] ?? [])
                    ->map(fn ($owner) => [
                        'slug'  => $owner->slug ?? null,
                        'name'  => $owner->name ?? null,
                        'count' => (int) ($owner->count ?? 0),
                    ])->values()->all(),

                // Khoảng giá lấy từ `floor_cpm` — chính con số trang công khai
                // đang hiển thị, không phải giá sàn nội bộ của gói sản phẩm.
                'price_range_vnd' => [
                    'min' => (int) round((float) ($aggregates['min_price'] ?? 0)),
                    'max' => (int) round((float) ($aggregates['max_price'] ?? 0)),
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

    /**
     * Chi tiết một media owner, kèm màn hình của họ (phân trang, giới hạn cứng).
     *
     * Màn hình đi kèm dùng `ScreenSummaryResource` — cùng hình dạng với
     * `/api/v2/screens`, nên bên tiêu thụ chỉ cần một kiểu dữ liệu cho màn
     * hình chứ không phải hai kiểu gần giống nhau.
     */
    public function owner(FrontpageListingRequest $request, string $slug): JsonResponse
    {
        $owner = $this->catalog->getOwnerBySlug($slug);

        if (! $owner) {
            return $this->notFound('Không tìm thấy media owner với slug này.');
        }

        $perPage = min(
            max(1, (int) $request->integer('per_page', self::DEFAULT_PER_PAGE)),
            self::MAX_PER_PAGE,
        );

        $screens = $this->catalog->getOwnerScreens($owner->id, $request, $perPage);

        return response()->json([
            'data' => (new OwnerDetailResource($owner))->resolve(),
            'screens' => [
                'data' => ScreenSummaryResource::collection($screens->getCollection())->resolve(),
                'meta' => [
                    'page'         => $screens->currentPage(),
                    'per_page'     => $screens->perPage(),
                    'total'        => $screens->total(),
                    'last_page'    => $screens->lastPage(),
                    'max_per_page' => self::MAX_PER_PAGE,
                ],
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
