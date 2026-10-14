<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V2\CityPinsRequest;
use App\Http\Requests\Api\V2\MapViewportRequest;
use App\Http\Requests\Api\V2\ScreenAvailabilityRequest;
use App\Http\Requests\Api\V2\OwnerListingRequest;
use App\Http\Requests\FrontpageListingRequest;
use App\Http\Requests\ProductListingRequest;
use App\Http\Resources\V2\MapPinResource;
use App\Http\Resources\V2\OwnerDetailResource;
use App\Http\Requests\Api\V2\AgencyListingRequest;
use App\Http\Resources\V2\AgencySummaryResource;
use App\Http\Resources\V2\OwnerSummaryResource;
use App\Http\Resources\V2\ProductDetailResource;
use App\Http\Resources\V2\ProductSummaryResource;
use App\Http\Resources\V2\ScreenDetailResource;
use App\Http\Resources\V2\ScreenSummaryResource;
use App\Services\AvailabilityService;
use App\Services\FrontpageService;
use App\Services\InventoryHoldService;
use App\Services\ProductService;
use Carbon\CarbonImmutable;
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

    /**
     * Giới hạn cho ba endpoint của trang chủ.
     *
     * Nhỏ hơn hẳn các endpoint danh sách, có lý do: đây là những khối trang chủ
     * hiển thị cố định vài thẻ. Mở rộng giới hạn không giúp ai mà biến một
     * endpoint duyệt nhanh thành một endpoint xuất dữ liệu.
     */
    private const MAX_FEATURED_SCREENS = 12;

    private const DEFAULT_FEATURED_SCREENS = 4;

    private const MAX_FEATURED_OWNERS = 24;

    private const DEFAULT_FEATURED_OWNERS = 6;

    private const MAX_CITY_PINS = 100;

    private const DEFAULT_CITY_PINS = 50;

    /** Khung thời gian tính suất còn lại, giống `/screens/{slug}`. */
    private const AVAILABILITY_WINDOW_DAYS = 30;

    public function __construct(
        private readonly FrontpageService $catalog,
        private readonly ProductService $products,
    ) {}

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
        // Dùng `getScreenDetail()` — đúng đường truy vấn trang chi tiết Blade
        // đang dùng.
        //
        // Bản trước tôi viết truy vấn riêng ngay trong controller, rồi vẫn
        // tuyên bố "mọi endpoint chỉ có một đường truy vấn". Codex chỉ ra là
        // không đúng, và hệ quả đã hiện ra ở R38: truy vấn riêng nạp đủ cột
        // network trong khi đường dùng chung thiếu `code`, nên cùng một màn
        // hình trả dữ liệu khác nhau tùy endpoint.
        $screen = $this->catalog->getScreenDetail($slug);

        if (! $screen) {
            return $this->notFound('Không tìm thấy màn hình với slug này.');
        }

        $remaining = app(InventoryHoldService::class)->remainingSov(
            $screen,
            now()->toDateString(),
            now()->addDays(30)->toDateString(),
        );

        // Màn hình tương tự: `getSimilarScreens()` đã có từ giai đoạn 4 và
        // chưa có endpoint nào mang ra. Trả kèm ở đây chứ không mở một đường
        // riêng, vì nó chỉ có nghĩa **trong ngữ cảnh một màn hình** — một
        // `/screens/similar?of=x` là cùng một truy vấn với một tham số bắt
        // buộc, tức một đường thừa.
        $similar = $this->catalog->getSimilarScreens($screen)
            ->map(fn ($s) => (new ScreenSummaryResource($s))->resolve())
            ->values()
            ->all();

        return response()->json([
            'data' => (new ScreenDetailResource(
                $screen,
                $this->catalog->getVnCategoryLabels(),
                $similar,
                $this->nearbyPois($screen),
                [
                    'window_days'       => 30,
                    'remaining_sov_pct' => $remaining,
                    'has_capacity'      => $remaining > 0,
                ],
            ))->resolve(),
        ]);
    }

    /**
     * Lịch trống theo từng ngày, cho N tháng tới.
     *
     * Vì sao một endpoint riêng, không trả kèm trong chi tiết: nó là dữ liệu
     * **đổi theo từng lượt đặt chỗ**, trong khi phần còn lại của trang chi
     * tiết đổi theo tháng. Gộp vào nhau là buộc cả trang cache theo nhịp của
     * phần động nhất, hoặc buộc lịch cache theo nhịp của phần tĩnh nhất và
     * hiện ngày đã bán là còn trống.
     *
     * Giới hạn cứng: tối đa 3 tháng. Một đường công khai nhận `months=240` là
     * một đường cho người lạ yêu cầu 7.300 ngày dữ liệu mỗi lần gọi.
     */
    public function screenAvailability(string $slug, ScreenAvailabilityRequest $request): JsonResponse
    {
        $screen = $this->catalog->getScreenDetail($slug);

        if (! $screen) {
            return $this->notFound('Không tìm thấy màn hình với slug này.');
        }

        $months = $request->months();
        $from   = CarbonImmutable::today();
        $to     = $from->addMonths($months)->endOfMonth();

        $theoNgay = app(AvailabilityService::class)
            ->getDailyRemainingSOV($screen->id, $from->toDateString(), $to->toDateString());

        $max = (int) ($screen->inventory?->share_of_voice_max_pct ?? 100);

        return response()->json([
            'data' => [
                'from'        => $from->toDateString(),
                'to'          => $to->toDateString(),
                'months'      => $months,
                'sov_max_pct' => $max,

                // `remaining_sov_pct` cho TỪNG ngày, không phải một trạng thái
                // ba màu đã quy đổi sẵn. Màu là việc của tầng hiển thị; API
                // trả con số, nên một client khác có thể vẽ ngưỡng khác mà
                // không cần endpoint mới.
                'days' => collect($theoNgay)
                    ->map(fn (int $con, string $ngay) => [
                        'date'              => $ngay,
                        'remaining_sov_pct' => $con,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * POI quanh màn hình, từ ảnh chụp OSM đã cache.
     *
     * Dùng lại `PoiProjection` — đúng lớp trang Blade và panel Filament dùng,
     * nên nhãn, icon, màu và nhóm không thể lệch giữa ba nơi.
     *
     * Không có toạ độ, hoặc chưa có ảnh chụp nào còn hạn, thì trả mảng rỗng.
     * KHÔNG gọi OSM tại đây: một endpoint công khai không được đi hỏi dịch vụ
     * ngoài trong lúc trả lời.
     */
    private function nearbyPois(\App\Models\Screen $screen): array
    {
        $lat = $screen->site?->lat;
        $lon = $screen->site?->lon;

        if (! $lat || ! $lon) {
            return [];
        }

        $snapshot = \App\Models\PoiSnapshot::freshFor((float) $lat, (float) $lon, 500, 'osm')->first();

        if (! $snapshot || ! is_array($snapshot->pois)) {
            return [];
        }

        return collect(\App\Support\PoiProjection::project($snapshot->pois, (float) $lat, (float) $lon, 60))
            // Danh sách trắng theo khóa: `PoiProjection::project()` giữ lại cả
            // phần tử OSM gốc ở một số khóa, và một endpoint danh mục không
            // phải nơi để dữ liệu thô của dịch vụ khác đi qua.
            ->map(fn (array $poi) => [
                'id'     => $poi['id'] ?? null,
                'name'   => $poi['name'] ?? null,
                'group'  => $poi['group'] ?? null,
                'label'  => $poi['gLabel'] ?? null,
                'icon'   => $poi['icon'] ?? null,
                'color'  => $poi['color'] ?? null,
                'dist_m' => isset($poi['dist']) ? (int) $poi['dist'] : null,
                'lat'    => isset($poi['lat']) ? (float) $poi['lat'] : null,
                'lng'    => isset($poi['lon']) ? (float) $poi['lon'] : null,
            ])
            ->values()
            ->all();
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

                // Cùng danh sách loại hình, nhưng **có network lồng bên trong**
                // (thêm 14/10/2026).
                //
                // Vì sao không bỏ qua và để bên gọi tự ghép: `networks` phẳng ở
                // dưới **không mang khóa loại hình nào**, nên quan hệ cha–con
                // không suy ra được ở phía trình duyệt. Ô tìm kiếm lớn của bản
                // Blade (`partials/mega-search.blade.php`) vẽ đúng cây này —
                // `getFilterAggregates()` đã tính nó sẵn, endpoint chỉ không
                // mang ra.
                //
                // Giữ cả `venue_types` phẳng: hai bên tiêu thụ khác nhau (chip
                // lọc ở `/owners` chỉ cần danh sách phẳng), và bỏ nó đi là đổi
                // hợp đồng đã công bố.
                //
                // Danh sách trắng theo khóa, không trả nguyên phần tử: nhóm
                // loại hình còn mang `thumb` — một đường ảnh — và nó không
                // thuộc về một endpoint bộ lọc.
                'categories_networks' => collect($aggregates['categories_networks'] ?? [])
                    ->map(fn ($cat) => [
                        'code'     => $cat['type'] ?? null,
                        'name'     => $cat['label'] ?? null,
                        'icon'     => $cat['icon'] ?? null,
                        'count'    => (int) ($cat['count'] ?? 0),
                        'networks' => collect($cat['networks'] ?? [])
                            ->map(fn ($net) => [
                                'code'  => $net['code'] ?? null,
                                'name'  => $net['name'] ?? null,
                                'count' => (int) ($net['count'] ?? 0),
                            ])->values()->all(),
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
                //
                // Có `currency` và chỉ tính hàng VND. Bản trước gọi nó là
                // `price_range_vnd` trong khi `getFilterAggregates()` lấy
                // MIN/MAX trên toàn bộ `floor_cpm` bất kể `floor_cpm_currency`,
                // nên một hàng USD kéo `min` xuống vài đơn vị và khoảng lọc
                // thành vô nghĩa (Codex R39).
                'price_range' => [
                    'currency' => $aggregates['price_currency'] ?? 'VND',
                    'min'      => (int) round((float) ($aggregates['min_price'] ?? 0)),
                    'max'      => (int) round((float) ($aggregates['max_price'] ?? 0)),
                ],
            ],
        ]);
    }

    /**
     * `GET /agencies` — agency và brand đang dùng sàn.
     *
     * ══ Khác `/owners` về BẢN CHẤT, không chỉ về dữ liệu ══
     *
     * `/owners` là media owner — bên BÁN, và họ công khai kho màn hình để
     * được tìm thấy. Danh sách này là `Organization` loại `agency` — bên MUA.
     *
     * Vì thế DTO ở đây hẹp hơn hẳn: bảng đó mang `billing_info`, `tax_code`
     * và điều khoản thanh toán. Xem `AgencySummaryResource` để biết ba trường
     * của bản Blade cố ý không mang sang.
     *
     * `withCount` nằm trong service, không ở đây — thiếu nó thì
     * `campaign_count` là null, và null KHÁC 0.
     */
    public function agencies(AgencyListingRequest $request): JsonResponse
    {
        $perPage = min(
            max(1, (int) $request->integer('per_page', self::DEFAULT_PER_PAGE)),
            self::MAX_PER_PAGE,
        );

        $page = $this->catalog->getAgenciesPaginated($request, $perPage);

        return response()->json([
            'data' => AgencySummaryResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'page'         => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
                'max_per_page' => self::MAX_PER_PAGE,
            ],
        ]);
    }

    public function owners(OwnerListingRequest $request): JsonResponse
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

    /**
     * Bộ lọc cho danh sách **sản phẩm**, tách khỏi `/filters` của màn hình.
     *
     * Vì sao một endpoint riêng chứ không thêm khóa vào `/api/v2/filters`:
     * hai bên đếm hai miền khác nhau. `/filters` lấy `cities` từ bảng màn
     * hình; sidebar của `/products` cần `cities` **của gói** — cùng tên khóa,
     * khác con số. Nhét chung một payload là mời người đọc dùng số đếm của
     * màn hình để ghi nhãn cho bộ lọc gói, và không có cách nào nhận ra ở chỗ
     * gọi.
     *
     * `ProductService::getFilterAggregates()` đã tính sẵn ba nhóm này cho
     * trang Blade `/products` — endpoint chỉ mang ra, không tính mới.
     *
     * Danh sách trắng theo khóa như mọi DTO v2: các phần tử từ service là
     * mảng do service tự dựng, nhưng trả thẳng nghĩa là một trường thêm vào
     * service sau này tự động ra ngoài mà không ai duyệt.
     */
    public function productFilters(): JsonResponse
    {
        $aggregates = $this->products->getFilterAggregates();

        return response()->json([
            'data' => [
                // `slug` chứ không `code`: đó là tên trường của bộ lọc sản
                // phẩm (`?category=`, `?type=`), và đổi tên ở DTO chỉ để trông
                // giống `/filters` là bắt bên gọi tự dịch lại.
                'categories' => collect($aggregates['categories'] ?? [])
                    ->map(fn ($row) => [
                        'slug'  => $row['slug'] ?? null,
                        'label' => $row['label'] ?? null,
                        'count' => (int) ($row['count'] ?? 0),
                    ])->values()->all(),

                'types' => collect($aggregates['types'] ?? [])
                    ->map(fn ($row) => [
                        'slug'  => $row['slug'] ?? null,
                        'label' => $row['label'] ?? null,
                        'count' => (int) ($row['count'] ?? 0),
                    ])->values()->all(),

                // Thành phố của gói là **chuỗi tự do** trong cột `products.city`,
                // không phải mã tỉnh thành như bên màn hình. Nên khóa là `name`
                // và chính nó cũng là giá trị gửi lên `?city=` — giống hệt cách
                // `ProductService` lọc (`whereIn('city', ...)`).
                'cities' => collect($aggregates['cities'] ?? [])
                    ->map(fn ($row) => [
                        'name'  => $row['name'] ?? null,
                        'count' => (int) ($row['count'] ?? 0),
                    ])->values()->all(),
            ],
        ]);
    }

    /**
     * Danh sách sản phẩm (gói và vị trí lẻ), phân trang có giới hạn cứng.
     *
     * Dùng chung `ProductService` với trang `/products`, nên bộ lọc và thứ tự
     * không thể khác nhau giữa hai nơi.
     */
    public function products(ProductListingRequest $request): JsonResponse
    {
        $perPage = min(
            max(1, (int) $request->integer('per_page', self::DEFAULT_PER_PAGE)),
            self::MAX_PER_PAGE,
        );

        $page = $this->products->getProductsPaginated($request->forService(), $perPage);

        return response()->json([
            'data' => ProductSummaryResource::collection($page->getCollection())->resolve(),
            'meta' => [
                'page'         => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
                'max_per_page' => self::MAX_PER_PAGE,
            ],
        ]);
    }

    public function product(string $slug): JsonResponse
    {
        $product = $this->products->getProductBySlug($slug);

        if (! $product) {
            return $this->notFound('Không tìm thấy sản phẩm với slug này.');
        }

        return response()->json([
            'data' => (new ProductDetailResource($product))->resolve(),
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
    /**
     * `GET /screens/featured` — vài màn hình nổi bật cho trang chủ.
     *
     * Dùng `getFeaturedScreens()`, đúng hàm trang chủ Blade gọi: lọc màn hình
     * công khai **có ảnh** và **có giá sàn > 0**. Không viết truy vấn riêng ở
     * đây — đó là lỗi R38 đã mắc một lần, khi truy vấn riêng nạp đủ cột network
     * còn đường dùng chung thiếu `code`, nên cùng một màn hình trả dữ liệu khác
     * nhau tùy endpoint.
     *
     * ══ Có `availability`, khác `/screens` ══
     *
     * Endpoint danh sách KHÔNG trả suất còn lại, có lý do: nó trả tới 50 bản
     * ghi mỗi trang và phép tính suất là hai truy vấn tổng hợp trên tập id đó.
     *
     * Ở đây tập nhỏ và cố định (tối đa 12), nên chi phí có hạn. Và nó cần
     * thiết: thẻ màn hình chỉ được in badge "Còn trống" khi CÓ dữ liệu suất —
     * audit F-15, `NoFabricatedMetricsTest` canh đúng điều đó. Không trả suất
     * thì trang chủ không in được badge, hoặc in bừa.
     */
    public function featuredScreens(Request $request): JsonResponse
    {
        $limit = min(
            max(1, (int) $request->integer('limit', self::DEFAULT_FEATURED_SCREENS)),
            self::MAX_FEATURED_SCREENS,
        );

        $screens = $this->catalog->getFeaturedScreens($limit);

        $remaining = app(InventoryHoldService::class)->remainingSovForScreens(
            $screens->pluck('id')->filter()->unique()->values()->all(),
            now()->toDateString(),
            now()->addDays(self::AVAILABILITY_WINDOW_DAYS)->toDateString(),
        );

        return response()->json([
            'data' => $screens->map(function ($screen) use ($remaining) {
                $pct = $remaining[$screen->id] ?? null;

                return (new ScreenSummaryResource($screen))->resolve() + [
                    // `null` khi không tính được, KHÔNG phải 0.
                    //
                    // 0 nghĩa là "đã đặt kín", còn `null` nghĩa là "chưa biết"
                    // — hai điều khác nhau, và gộp chúng là cách in badge
                    // "đã đầy" cho một màn hình không ai đặt.
                    'availability' => $pct === null ? null : [
                        'window_days'       => self::AVAILABILITY_WINDOW_DAYS,
                        'remaining_sov_pct' => (int) $pct,
                        'has_capacity'      => $pct > 0,
                    ],
                ];
            })->values()->all(),
            'meta' => [
                'returned'  => $screens->count(),
                'limit'     => $limit,
                'max_limit' => self::MAX_FEATURED_SCREENS,
            ],
        ]);
    }

    /**
     * `GET /owners/featured` — vài media owner nổi bật cho trang chủ.
     *
     * `getFeaturedOwners()` ưu tiên owner có cờ `featured`, và **lùi về** owner
     * đang hoạt động nhiều màn hình nhất khi chưa ai được đánh dấu. Nên danh
     * sách không bao giờ rỗng chỉ vì chưa ai bật cờ — một trang chủ trống vì
     * thiếu một cờ quản trị là lỗi khó đoán nguyên nhân.
     */
    public function featuredOwners(Request $request): JsonResponse
    {
        $limit = min(
            max(1, (int) $request->integer('limit', self::DEFAULT_FEATURED_OWNERS)),
            self::MAX_FEATURED_OWNERS,
        );

        $owners = $this->catalog->getFeaturedOwners($limit);

        return response()->json([
            'data' => OwnerSummaryResource::collection($owners)->resolve(),
            'meta' => [
                'returned'  => $owners->count(),
                'limit'     => $limit,
                'max_limit' => self::MAX_FEATURED_OWNERS,
            ],
        ]);
    }

    /**
     * `GET /screens/pins` — pin bản đồ cho MỘT thành phố.
     *
     * ══ `city` bắt buộc, cùng lý lẽ với khung nhìn ở `/screens/map` ══
     *
     * `MapViewportRequest` bắt buộc có khung nhìn vì thiếu nó thì "lấy pin bản
     * đồ" nghĩa là lấy mọi màn hình có toạ độ — một lần xuất toàn bộ kho dưới
     * một cái tên vô hại. Endpoint này dùng **thành phố** làm phạm vi thay cho
     * khung nhìn, nên `city` cũng phải bắt buộc. Thiếu nó là mở lại đúng cái
     * cửa kia.
     *
     * ══ Vì sao tách khỏi `/screens/map` ══
     *
     * Trang chủ có bộ chọn thành phố, không có bản đồ kéo được, nên nó không
     * biết toạ độ biên của thành phố để dựng khung nhìn. Gộp hai phạm vi vào
     * một endpoint nghĩa là hai nhóm tham số loại trừ nhau, mỗi nhóm bắt buộc
     * theo điều kiện — một hợp đồng không khai được gọn trong OpenAPI, và
     * không kiểm được bằng một FormRequest.
     *
     * Dùng lại `MapPinResource`, **không** chép hình dạng mà
     * `getHomepageMapPins()` trả về: hàm đó trả `price` là số trần không kèm
     * đơn vị tiền, đúng lỗi R39 mà DTO này được tạo ra để tránh.
     */
    public function pins(CityPinsRequest $request): JsonResponse
    {
        $limit = min(
            max(1, (int) $request->integer('limit', self::DEFAULT_CITY_PINS)),
            self::MAX_CITY_PINS,
        );

        $pins = $this->catalog->getCityMapPins($request->citySlug(), $limit);

        return response()->json([
            'data' => MapPinResource::collection($pins)->resolve(),
            'meta' => [
                'returned'  => $pins->count(),
                'limit'     => $limit,
                'max_limit' => self::MAX_CITY_PINS,
                'city'      => $request->citySlug(),
            ],
        ]);
    }

    /**
     * `GET /locations` — tỉnh thành có màn hình, nhóm theo vùng.
     *
     * ══ Trả MẢNG, không trả object khoá bằng tên vùng ══
     *
     * `getLocationsByRegion()` trả `['Miền Bắc' => [...], 'Miền Trung' => [...]]`
     * — khoá là **chuỗi hiển thị tiếng Việt**. Dùng hình dạng đó làm hợp đồng
     * API thì không gõ kiểu được, và đổi tên vùng trong `config/regions.php` là
     * đổi khoá của response — tức một thay đổi hiển thị làm vỡ bên tiêu thụ.
     *
     * Nên ở đây mỗi vùng là một phần tử có `code` ổn định và `name` để hiển
     * thị.
     */
    public function locations(): JsonResponse
    {
        $grouped = $this->catalog->getLocationsByRegion();
        $config  = config('regions', []);

        // Dò ngược từ tên hiển thị về code. `getLocationsByRegion()` chỉ trả
        // tên, nên đây là chỗ duy nhất quy đổi được — và nó phải chịu được
        // trường hợp tên không khớp config nào, thay vì ném lỗi.
        $nameToCode = [];
        foreach ($config as $code => $cfg) {
            if (isset($cfg['name'])) {
                $nameToCode[$cfg['name']] = $code;
            }
        }

        $regions = [];
        foreach ($grouped as $name => $provinces) {
            $regions[] = [
                'code'      => $nameToCode[$name] ?? null,
                'name'      => $name,
                'provinces' => array_map(fn (array $p) => [
                    'code'  => $p['code'],
                    'name'  => $p['name'],
                    'count' => (int) $p['count'],
                ], $provinces),
            ];
        }

        return response()->json(['data' => $regions]);
    }

}
