<?php

namespace App\Http\Controllers;

use App\Http\Requests\FrontpageListingRequest;
use App\Services\FrontpageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FrontpageController extends Controller
{
    public function __construct(private FrontpageService $fp) {}

    public function index(Request $request): View|JsonResponse
    {
        $citySlug = $request->input('_map_city', $request->cookie('oohx_city', 'hanoi'));

        if ($request->ajax() && $request->has('_map_city')) {
            return response()->json($this->fp->getHomepageMapPins($citySlug, 50));
        }

        $mapData = $this->fp->getHomepageMapPins($citySlug, 50);

        return view('frontpage.index', [
            'stats'             => $this->fp->getHeroStats(),
            'venueTypes'        => $this->fp->getVenueTypesWithCounts(),
            'topCities'         => $this->fp->getTopCities(),
            'featuredScreens'   => $featuredScreens = $this->fp->getFeaturedScreens(4),
            'availability'      => $this->availabilityFor($featuredScreens),
            'featuredOwners'    => $this->fp->getFeaturedOwners(6),
            'locationsByRegion' => $this->fp->getLocationsByRegion(),
            'filters'           => $this->fp->getFilterAggregates(),
            'mapData'           => $mapData,
            'vnCatLabels'       => $this->fp->getVnCategoryLabels(),
        ]);
    }

    public function listing(FrontpageListingRequest $request): View
    {
        $screens = $this->fp->getScreensPaginated($request);

        return view('frontpage.listing', [
            'screens'          => $screens,
            'availability'     => $this->availabilityFor($screens->getCollection()),
            'filters'          => $this->fp->getFilterAggregates(),
            'vnCatLabels'      => $this->fp->getVnCategoryLabels(),
            'locationsByRegion' => $this->fp->getLocationsByRegion(),
        ]);
    }

    /**
     * Suất còn lại của các màn hình đang hiển thị, trong 30 ngày tới.
     *
     * Tính theo LÔ: hai truy vấn tổng hợp bất kể bao nhiêu thẻ. Gọi lẻ từng thẻ
     * là một truy vấn mỗi thẻ; in badge vô điều kiện thì mời người mua vào một
     * suất đã bán (audit F-15). Thẻ nào không có trong mảng này thì không hiện
     * badge — fail-closed.
     *
     * @param  iterable<int, \App\Models\Screen>  $screens
     * @return array<string, int>
     */
    private function availabilityFor(iterable $screens): array
    {
        $ids = collect($screens)->pluck('id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return app(\App\Services\InventoryHoldService::class)->remainingSovForScreens(
            $ids,
            now()->toDateString(),
            now()->addDays(30)->toDateString(),
        );
    }

    public function detail(string $screen): View|\Illuminate\Http\RedirectResponse
    {
        $screenModel = $this->fp->getScreenDetail($screen);
        abort_unless($screenModel, 404);

        // 301 redirect old UUID/ID URLs to canonical slug URL
        if ($screenModel->slug && $screen !== $screenModel->slug) {
            return redirect()->route('fp.detail', $screenModel->slug, 301);
        }

        // Get booked dates for next 2 months
        $bookedDates = \App\Models\BookingLine::where('screen_id', $screenModel->id)
            ->whereIn('status', ['approved', 'active'])
            ->where('end_date', '>=', now())
            ->where('start_date', '<=', now()->addMonths(2)->endOfMonth())
            ->get(['start_date', 'end_date', 'share_of_voice_pct'])
            ->flatMap(function ($line) {
                $dates = [];
                $start = $line->start_date->copy();
                $end = $line->end_date->copy();
                while ($start->lte($end)) {
                    $dates[$start->format('Y-m-d')] = ($dates[$start->format('Y-m-d')] ?? 0) + ($line->share_of_voice_pct ?? 100);
                    $start->addDay();
                }
                return $dates;
            })->toArray();

        // Còn suất trong 30 ngày tới hay không — tính thật, không in vô điều kiện.
        //
        // Badge "Còn trống" trước đây được in ở ba chỗ trên trang này mà không
        // hề kiểm gì: một màn hình đã bán kín vẫn hiện "Còn trống" cho người
        // mua (audit F-15). Từ giai đoạn 1 đã có nguồn thật để tính, gồm cả
        // suất người khác đang giữ trong giỏ.
        $availableSovNext30Days = app(\App\Services\AvailabilityService::class)->getRemainingSOV(
            $screenModel->id,
            now()->toDateString(),
            now()->addDays(30)->toDateString(),
        );

        $isSaved = auth()->check()
            ? \App\Models\SavedItem::where('user_id', auth()->id())->where('screen_id', $screenModel->id)->exists()
            : false;

        // Nearby POIs from OSM cache (populated by AI enrichment) — for map markers
        $nearbyPois = $this->resolveNearbyPois($screenModel);

        return view('frontpage.detail', [
            'screen'         => $screenModel,
            'similarScreens' => $similar = $this->fp->getSimilarScreens($screenModel),
            'availability'   => $this->availabilityFor($similar),
            'vnCatLabels'    => $this->fp->getVnCategoryLabels(),
            'bookedDates'    => $bookedDates,
            'availableSov'   => $availableSovNext30Days,
            'isSaved'        => $isSaved,
            'nearbyPois'     => $nearbyPois,
        ]);
    }

    /**
     * Read OSM POIs from poi_snapshots table (populated by PoiContextEnricher).
     * Returns enriched array via PoiProjection: {id, lat, lon, name, group, gLabel, icon, color, dist}
     * Capped at 60 markers for map perf.
     */
    private function resolveNearbyPois(\App\Models\Screen $screen): array
    {
        $lat = $screen->site?->lat;
        $lon = $screen->site?->lon;
        if (! $lat || ! $lon) return [];

        $snapshot = \App\Models\PoiSnapshot::freshFor((float) $lat, (float) $lon, 500, 'osm')->first();
        if (! $snapshot || ! is_array($snapshot->pois)) return [];

        return \App\Support\PoiProjection::project(
            $snapshot->pois,
            (float) $lat,
            (float) $lon,
            60,
        );
    }

    public function map(FrontpageListingRequest $request): View
    {
        $vnCatLabels = $this->fp->getVnCategoryLabels();
        $vnCatSlugs  = $this->fp->getVnCategorySlugs();
        $vnCatIcons  = $this->fp->getVnCategoryIcons();
        $pins = $this->fp->getMapPins($request);

        // Build screen-level data first
        $screens = $pins->map(function ($p) use ($vnCatLabels, $vnCatSlugs, $vnCatIcons) {
            $catId = $p->inventory?->vn_category_id;
            $product = $p->relationLoaded('products') ? $p->products->first() : null;
            $ownerLogo = $p->owner?->logo_url;
            $wCm = $p->spec?->width_cm;
            $hCm = $p->spec?->height_cm;
            $size = ($wCm && $hCm) ? round($wCm / 100, 1) . '×' . round($hCm / 100, 1) . 'm' : '';
            return [
                'siteId'    => $p->site?->id ?? null,
                'id'        => $p->slug ?? $p->uuid ?? $p->id,
                'name'      => $p->name,
                'lat'       => (float) ($p->site?->lat ?? 0),
                'lng'       => (float) ($p->site?->lon ?? 0),
                'city'      => $p->site?->city ?? '',
                'addr'      => $p->site?->address ?? '',
                'photo'     => $p->display_photo ?? '',
                'price'     => (float) ($p->inventory?->display_price ?? 0),
                'priceUnit' => $p->inventory?->display_price_unit ?? '',
                'type'      => $vnCatSlugs[$catId] ?? '',
                'typeLabel' => $vnCatLabels[$catId] ?? '',
                'icon'      => $vnCatIcons[$catId] ?? 'tv',
                'ownerName' => $p->owner?->name ?? '',
                'ownerLogo' => $ownerLogo ? asset('storage/' . $ownerLogo) : '',
                'ownerInitials' => $p->owner ? strtoupper(mb_substr($p->owner->name, 0, 2)) : '',
                'networkName' => $p->site?->network?->name ?? '',
                'siteName'    => $p->site?->name ?? '',
                'size'        => $size,
                'product'   => $product ? [
                    'slug'        => $product->slug,
                    'name'        => $product->name,
                    'total_units' => $product->total_units,
                    'can_buy_individual' => $product->allowsIndividual(),
                ] : null,
            ];
        })->filter(fn ($s) => $s['lat'] != 0 && $s['lng'] != 0)->values();

        // Group by site_id into site-level markers
        $pinsJson = $screens->groupBy('siteId')->map(function ($group) {
            $first = $group->first();
            return [
                'siteId'     => $first['siteId'],
                'lat'        => $first['lat'],
                'lng'        => $first['lng'],
                'siteName'   => $first['siteName'] ?: $first['name'],
                'city'       => $first['city'],
                'addr'       => $first['addr'],
                'photo'      => $first['photo'],
                'ownerName'  => $first['ownerName'],
                'ownerLogo'  => $first['ownerLogo'],
                'ownerInitials' => $first['ownerInitials'],
                'networkName' => $first['networkName'],
                'type'       => $first['type'],
                'typeLabel'  => $first['typeLabel'],
                'icon'       => $first['icon'],
                'screenCount' => $group->count(),
                'screens'    => $group->map(fn ($s) => [
                    'id'        => $s['id'],
                    'name'      => $s['name'],
                    'photo'     => $s['photo'],
                    'price'     => $s['price'],
                    'priceUnit' => $s['priceUnit'],
                    'size'      => $s['size'],
                    'typeLabel' => $s['typeLabel'],
                    'product'   => $s['product'],
                ])->values(),
            ];
        })->values();

        return view('frontpage.map', [
            'pins'              => $pins,
            'pinsJson'          => $pinsJson,
            'filters'           => $this->fp->getFilterAggregates(),
            'locationsByRegion' => $this->fp->getLocationsByRegion(),
            'vnCatLabels'       => $vnCatLabels,
        ]);
    }

    public function agency(Request $request): View
    {
        return view('frontpage.agency', [
            'agencies' => $this->fp->getAgenciesPaginated($request),
        ]);
    }

    public function owners(Request $request): View
    {
        return view('frontpage.owners', [
            'owners'     => $this->fp->getOwnersPaginated($request),
            'venueTypes' => $this->fp->getVenueTypesWithCounts(),
        ]);
    }

    public function ownerDetail(Request $request, string $owner): View
    {
        $ownerModel = $this->fp->getOwnerBySlug($owner);
        abort_unless($ownerModel, 404);

        $reviews = app(\App\Services\OwnerReviewService::class);

        return view('frontpage.owner-detail', [
            'owner'            => $ownerModel,
            'ownerScreens'     => $ownerScreens = $this->fp->getOwnerScreens($ownerModel->id, $request),
            'availability'     => $this->availabilityFor(
                $ownerScreens instanceof \Illuminate\Contracts\Pagination\Paginator
                    ? $ownerScreens->getCollection()
                    : $ownerScreens
            ),
            'filters'          => $this->fp->getOwnerFilterAggregates($ownerModel->id),
            'locationsByRegion' => $this->fp->getLocationsByRegion(),
            'vnCatLabels'      => $this->fp->getVnCategoryLabels(),
            'ratingSummary'    => $reviews->summaryFor($ownerModel->id),
            'ownerReviews'     => $reviews->publishedFor($ownerModel->id),
        ]);
    }
}
