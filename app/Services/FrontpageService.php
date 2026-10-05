<?php

namespace App\Services;

use App\Models\Network;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\Site;
use App\Models\VenueCategory;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FrontpageService
{
    private const CITY_SLUG_MAP = [
        'hanoi'    => 'Hà Nội',
        'hcm'      => 'Hồ Chí Minh',
        'danang'   => 'Đà Nẵng',
        'haiphong' => 'Hải Phòng',
        'cantho'   => 'Cần Thơ',
    ];

    private const CITY_DISPLAY_NAMES = [
        'hanoi'    => 'Hà Nội',
        'hcm'      => 'TP. Hồ Chí Minh',
        'danang'   => 'Đà Nẵng',
        'haiphong' => 'Hải Phòng',
        'cantho'   => 'Cần Thơ',
    ];

    // ── Phase 1: Homepage ─────────────────────────────────

    public function getHeroStats(): array
    {
        return Cache::remember('fp:hero_stats', 1800, function () {
            $totalScreens = Screen::publiclyVisible()->count();

            $totalCities = DB::table('screens')
                ->join('sites', 'screens.site_id', '=', 'sites.id')
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->whereNull('sites.deleted_at')
                ->whereNotNull('sites.city')
                ->where('sites.city', '!=', '')
                ->tap(fn ($q) => Owner::gateActive($q))
                ->selectRaw("COUNT(DISTINCT TRIM(SUBSTRING_INDEX(sites.city, '>', 1))) as cnt")
                ->value('cnt');

            $totalOwners = Screen::publiclyVisible()
                ->distinct()
                ->count('owner_id');

            return [
                'total_screens' => $totalScreens,
                'total_cities'  => $totalCities,
                'total_owners'  => $totalOwners,
            ];
        });
    }

    /**
     * Get venue types grouped by VN category (12 categories).
     * Used for frontpage filters — simpler than raw OpenOOH types.
     */
    public function getVenueTypesWithCounts(): Collection
    {
        return Cache::remember('fp:venue_types', 1800, function () {
            return DB::table('screens')
                ->join('screen_inventory', 'screens.id', '=', 'screen_inventory.screen_id')
                ->join('venue_categories', 'screen_inventory.vn_category_id', '=', 'venue_categories.id')
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->where('venue_categories.is_active', true)
                ->tap(fn ($q) => Owner::gateActive($q))
                ->selectRaw('
                    venue_categories.slug as type,
                    COALESCE(venue_categories.name_vi, venue_categories.name) as label,
                    venue_categories.icon,
                    venue_categories.thumb,
                    venue_categories.sort_order,
                    count(*) as count
                ')
                ->groupBy('venue_categories.slug', 'venue_categories.name_vi', 'venue_categories.name', 'venue_categories.icon', 'venue_categories.thumb', 'venue_categories.sort_order')
                ->orderBy('venue_categories.sort_order')
                ->get()
                ->map(fn ($r) => [
                    'type'  => $r->type,
                    'label' => $r->label,
                    'icon'  => $r->icon ?? 'tv',
                    'thumb' => $r->thumb ? asset('storage/' . $r->thumb) : null,
                    'count' => (int) $r->count,
                ]);
        });
    }

    /**
     * Venue categories with nested networks (for mega-search dropdown).
     * Returns: [{slug, label, icon, count, networks: [{code, name, count}]}]
     */
    public function getCategoriesWithNetworks(): Collection
    {
        return Cache::remember('fp:categories_networks', 1800, function () {
            // Get venue categories with screen counts
            $categories = $this->getVenueTypesWithCounts();

            // Get networks grouped by vn_category_id with screen counts
            $networks = DB::table('networks')
                ->join('sites', 'networks.id', '=', 'sites.network_id')
                ->join('screens', 'sites.id', '=', 'screens.site_id')
                ->join('venue_categories', 'networks.vn_category_id', '=', 'venue_categories.id')
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->whereNull('sites.deleted_at')
                ->whereNull('networks.deleted_at')
                ->where('networks.status', 'active')
                ->tap(fn ($q) => Owner::gateActive($q))
                ->selectRaw('venue_categories.slug as cat_slug, networks.code, networks.name, COUNT(DISTINCT screens.id) as count')
                ->groupBy('venue_categories.slug', 'networks.code', 'networks.name')
                ->orderByDesc('count')
                ->get()
                ->groupBy('cat_slug');

            return $categories->map(function ($cat) use ($networks) {
                $cat['networks'] = ($networks[$cat['type']] ?? collect())
                    ->map(fn ($n) => ['code' => $n->code, 'name' => $n->name, 'count' => (int) $n->count])
                    ->values()
                    ->toArray();
                return $cat;
            });
        });
    }

    /**
     * Cached lookup: vn_category_id → VN category name.
     * Used by Blade views to display VN label for individual screens.
     */
    public function getVnCategoryLabels(): array
    {
        return Cache::remember('fp:vn_category_labels', 3600, fn () =>
            DB::table('venue_categories')
                ->where('is_active', true)
                ->pluck(DB::raw('COALESCE(name_vi, name)'), 'id')
                ->all()
        );
    }

    /**
     * Cached lookup: vn_category_id → slug.
     * Used for map pin filtering.
     */
    public function getVnCategorySlugs(): array
    {
        return Cache::remember('fp:vn_category_slugs', 3600, fn () =>
            DB::table('venue_categories')
                ->where('is_active', true)
                ->pluck('slug', 'id')
                ->all()
        );
    }

    /**
     * Cached lookup: vn_category_id → icon (Material Icons name).
     */
    public function getVnCategoryIcons(): array
    {
        return Cache::remember('fp:vn_category_icons', 3600, fn () =>
            DB::table('venue_categories')
                ->where('is_active', true)
                ->whereNotNull('icon')
                ->pluck('icon', 'id')
                ->all()
        );
    }

    public function getTopCities(int $limit = 8): Collection
    {
        return Cache::remember('fp:top_cities', 1800, function () use ($limit) {
            // sites.city may store "Hà Nội > Phường Dương Nội" — extract province part
            $rawCityCounts = DB::table('screens')
                ->join('sites', 'screens.site_id', '=', 'sites.id')
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->whereNull('sites.deleted_at')
                ->whereNotNull('sites.city')
                ->where('sites.city', '!=', '')
                ->tap(fn ($q) => Owner::gateActive($q))
                ->selectRaw("TRIM(SUBSTRING_INDEX(sites.city, '>', 1)) as province, count(*) as count")
                ->groupByRaw("TRIM(SUBSTRING_INDEX(sites.city, '>', 1))")
                ->orderByDesc('count')
                ->limit($limit)
                ->get();

            // Load province photos
            $provincePhotos = DB::table('vietnam_provinces')
                ->whereNotNull('photo_url')
                ->pluck('photo_url', 'name');

            return $rawCityCounts->map(fn ($r) => [
                'code'  => $this->cityToSlug($r->province),
                'name'  => $r->province,
                'count' => (int) $r->count,
                'photo' => isset($provincePhotos[$r->province])
                    ? asset('storage/' . $provincePhotos[$r->province])
                    : null,
            ]);
        });
    }

    public function getLocationsByRegion(): array
    {
        return Cache::remember('fp:locations', 1800, function () {
            $regionConfig = config('regions');
            $cityToCode   = array_flip(self::CITY_SLUG_MAP);

            // Extract province from "Hà Nội > Phường..." format
            $rawProvinceCounts = DB::table('screens')
                ->join('sites', 'screens.site_id', '=', 'sites.id')
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->whereNotNull('sites.city')
                ->where('sites.city', '!=', '')
                ->tap(fn ($q) => Owner::gateActive($q))
                ->selectRaw("TRIM(SUBSTRING_INDEX(sites.city, '>', 1)) as province, count(*) as count")
                ->groupByRaw("TRIM(SUBSTRING_INDEX(sites.city, '>', 1))")
                ->get();

            $provinces = $rawProvinceCounts->map(function ($r) use ($cityToCode, $regionConfig) {
                $slugged  = Str::slug($r->province);
                $noDash   = str_replace('-', '', $slugged);
                $code     = $cityToCode[$r->province] ?? $slugged;

                // Match against regions config (codes are no-dash: dongnai, bacninh...)
                $region = null;
                foreach ($regionConfig as $rc => $cfg) {
                    if (in_array($code, $cfg['provinces']) || in_array($noDash, $cfg['provinces'])) {
                        $region = $rc;
                        // Use the config code for consistency
                        if (in_array($noDash, $cfg['provinces'])) {
                            $code = $noDash;
                        }
                        break;
                    }
                }
                return [
                    'code'   => $code,
                    'name'   => $r->province,
                    'region' => $region,
                    'count'  => (int) $r->count,
                ];
            })->filter(fn ($p) => $p['region'] !== null)->values();

            $grouped = [];
            foreach ($regionConfig as $regionCode => $cfg) {
                $regionProvinces = $provinces->where('region', $regionCode)->sortByDesc('count')->values();
                if ($regionProvinces->isNotEmpty()) {
                    $grouped[$cfg['name']] = $regionProvinces->toArray();
                }
            }

            return $grouped;
        });
    }

    public function getFeaturedScreens(int $limit = 4): Collection
    {
        return Cache::remember('fp:featured_screens', 900, function () use ($limit) {
            return Screen::publiclyVisible()
                ->whereHas('spec', fn ($q) => $q->where(fn ($sq) =>
                    $sq->whereNotNull('photos')->where('photos', '!=', '[]')
                       ->orWhere(fn ($sq2) => $sq2->whereNotNull('photo_url')->where('photo_url', '!=', ''))
                ))
                ->whereHas('inventory', fn ($q) => $q->where('floor_cpm', '>', 0))
                ->with([
                    'spec:screen_id,photo_url,photos,width_cm,height_cm',
                    'inventory:screen_id,floor_cpm,floor_cpm_currency,venue_type,vn_category_id,pricing_model,io_rate,io_rate_unit,io_kpi_spots_per_day',
                    'inventory.vnCategory:id,thumb',
                    'owner:id,name,slug,cover_url',
                    'site:id,network_id,name,city,address,banner',
                    'site.network:id,name,code,banner',
                    'products:id,slug,name,total_units,listing_mode',
                ])
                ->inRandomOrder()
                ->limit($limit)
                ->get();
        });
    }

    public function getFeaturedOwners(int $limit = 6): Collection
    {
        return Cache::remember('fp:featured_owners', 1800, function () use ($limit) {
            $owners = Owner::where('status', 'active')
                ->where('featured', true)
                ->withCount(['screens as screen_count' => fn ($q) => $q->where('active', true)])
                ->orderByDesc('screen_count')
                ->limit($limit)
                ->get();

            if ($owners->isEmpty()) {
                $owners = Owner::where('status', 'active')
                    ->withCount(['screens as screen_count' => fn ($q) => $q->where('active', true)])
                    ->having('screen_count', '>', 0)
                    ->orderByDesc('screen_count')
                    ->limit($limit)
                    ->get();
            }

            $this->loadOwnerExtras($owners);

            return $owners;
        });
    }

    // ── Phase 2: Owners ───────────────────────────────────

    public function getOwnersPaginated(Request $request, int $perPage = 12): LengthAwarePaginator
    {
        $owners = Owner::query()
            ->where('status', 'active')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'LIKE', '%' . $request->input('q') . '%'))
            // `type` nhận SLUG của venue_categories — cùng từ vựng mà
            // `getVenueTypesWithCounts()` phát ra cho chip lọc, và cùng từ vựng
            // `/explore` dùng (xem nhánh `venue_type` trong `getListing`).
            //
            // Bản cũ so slug đó với cột `screen_inventory.venue_type`, là một
            // cột chuỗi KHÁC: nó giữ mã venue type của OpenOOH do Filament ghi,
            // không phải slug nhóm. Hai từ vựng không giao nhau, nên filter
            // `type` khớp không gì cả — kể cả tham số `type` đã công bố ở
            // `/api/v2/owners`. Lỗi im lặng: không báo gì, chỉ trả danh sách
            // rỗng, và trước đây không ai gọi tới nó vì chip lọc chưa phải link.
            ->when($request->filled('type'), function ($q) use ($request) {
                $catIds = DB::table('venue_categories')
                    ->where('slug', $request->input('type'))
                    ->pluck('id');

                // Slug lạ thì bỏ qua filter, giống `/explore`, chứ không trả
                // rỗng — hai danh sách phải trả lời giống nhau cho cùng một
                // tham số sai.
                if ($catIds->isNotEmpty()) {
                    $q->whereHas('screens', fn ($sq) => $sq
                        ->where('active', true)
                        ->whereHas('inventory', fn ($iq) => $iq->whereIn('vn_category_id', $catIds)));
                }
            })
            ->withCount(['screens as screen_count' => fn ($q) => $q->where('active', true)])
            ->orderByDesc('featured')
            ->orderByDesc('screen_count')
            ->paginate($perPage);

        $this->loadOwnerExtras($owners);

        return $owners;
    }

    /**
     * Paginated list of Agencies (Organizations with type=agency).
     */
    public function getAgenciesPaginated(Request $request, int $perPage = 12): LengthAwarePaginator
    {
        return \App\Models\Organization::query()
            ->where('type', 'agency')
            ->where('status', 'active')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'LIKE', '%' . $request->input('q') . '%'))
            ->withCount(['campaigns as campaign_count'])
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function getOwnerBySlug(string $slug): ?Owner
    {
        return Cache::remember("fp:owner:{$slug}", 900, function () use ($slug) {
            $owner = Owner::where('slug', $slug)
                ->where('status', 'active')
                ->with(['province:id,full_name', 'commune:id,full_name'])
                ->withCount(['screens as screen_count' => fn ($q) => $q->where('active', true)])
                ->first();

            if (! $owner) {
                return null;
            }

            $owner->city_count = (int) DB::table('screens')
                ->join('sites', 'screens.site_id', '=', 'sites.id')
                ->where('screens.owner_id', $owner->id)
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->whereNull('sites.deleted_at')
                ->whereNotNull('sites.city')
                ->selectRaw("COUNT(DISTINCT TRIM(SUBSTRING_INDEX(sites.city, '>', 1))) as cnt")
                ->value('cnt');

            $owner->venue_types_list = DB::table('screens')
                ->join('screen_inventory', 'screens.id', '=', 'screen_inventory.screen_id')
                ->join('venue_categories', 'screen_inventory.vn_category_id', '=', 'venue_categories.id')
                ->where('screens.owner_id', $owner->id)
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->selectRaw('DISTINCT COALESCE(venue_categories.name_vi, venue_categories.name) as label')
                ->orderBy('label')
                ->pluck('label')
                ->values()
                ->toArray();

            return $owner;
        });
    }

    public function getOwnerScreens(string $ownerId, Request $request = null, int $perPage = 12): LengthAwarePaginator
    {
        $query = Screen::publiclyVisible()->where('owner_id', $ownerId);

        // Apply filters if request provided
        if ($request) {
            // City
            if (! empty($citySlugs = $this->resolveArrayParam($request, 'city'))) {
                $cityNames = $this->expandCitySlugs($citySlugs);
                $query->whereHas('site', function ($sq) use ($cityNames) {
                    $sq->where(function ($or) use ($cityNames) {
                        foreach ($cityNames as $name) {
                            $or->orWhere('city', 'LIKE', $name . '%');
                        }
                    });
                });
            }
            // Region
            if (! empty($regionCodes = $this->resolveArrayParam($request, 'region'))) {
                $regionConfig = config('regions');
                $provinceCodes = collect($regionCodes)
                    ->flatMap(fn ($r) => $regionConfig[$r]['provinces'] ?? [])
                    ->unique()->values()->all();
                $cityNames = array_map(fn ($p) => self::CITY_SLUG_MAP[$p] ?? $p, $provinceCodes);
                $query->whereHas('site', function ($sq) use ($cityNames) {
                    $sq->where(function ($or) use ($cityNames) {
                        foreach ($cityNames as $name) {
                            $or->orWhere('city', 'LIKE', $name . '%');
                        }
                    });
                });
            }
            // Network
            if (! empty($networks = $this->resolveArrayParam($request, 'network'))) {
                $query->whereHas('site.network', fn ($nq) => $nq->whereIn('code', $networks));
            }
            // Venue type
            if (! empty($venueTypes = $this->resolveArrayParam($request, 'venue_type'))) {
                $catIds = DB::table('venue_categories')->whereIn('slug', $venueTypes)->pluck('id');
                if ($catIds->isNotEmpty()) {
                    $query->whereHas('inventory', fn ($iq) => $iq->whereIn('vn_category_id', $catIds));
                }
            }
            // Search
            if ($request->filled('q')) {
                $search = $request->input('q');
                $query->where(function ($sub) use ($search) {
                    $sub->where('name', 'LIKE', "%{$search}%")
                        ->orWhereHas('site', fn ($sq) => $sq->where('address', 'LIKE', "%{$search}%"));
                });
            }
        }

        return $query->with([
                'spec:screen_id,photo_url,photos,width_cm,height_cm',
                'inventory:screen_id,floor_cpm,floor_cpm_currency,venue_type,vn_category_id,pricing_model,io_rate,io_rate_unit,io_kpi_spots_per_day',
                'owner:id,name,slug,cover_url',
                'site:id,network_id,name,city,address,banner',
                'site.network:id,name,code,banner',
                'products:id,slug,name,total_units,listing_mode',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Get filter aggregates scoped to a specific owner (for owner detail page).
     */
    public function getOwnerFilterAggregates(string $ownerId): array
    {
        return Cache::remember("fp:owner_filters:{$ownerId}", 900, function () use ($ownerId) {
            $networks = DB::table('networks')
                ->join('sites', 'networks.id', '=', 'sites.network_id')
                ->join('screens', 'sites.id', '=', 'screens.site_id')
                ->where('screens.owner_id', $ownerId)
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->whereNull('networks.deleted_at')
                ->selectRaw('networks.id, networks.code, networks.name, COUNT(DISTINCT screens.id) as count')
                ->groupBy('networks.id', 'networks.code', 'networks.name')
                ->orderByDesc('count')
                ->get();

            $formats = DB::table('screen_inventory')
                ->join('screens', 'screen_inventory.screen_id', '=', 'screens.id')
                ->join('venue_categories', 'screen_inventory.vn_category_id', '=', 'venue_categories.id')
                ->where('screens.owner_id', $ownerId)
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->where('venue_categories.is_active', true)
                ->selectRaw('venue_categories.id, venue_categories.slug as type, COALESCE(venue_categories.name_vi, venue_categories.name) as label, COUNT(*) as count')
                ->groupBy('venue_categories.id', 'venue_categories.slug', 'label')
                ->orderByDesc('count')
                ->get();

            return [
                'networks' => $networks,
                'formats'  => $formats,
            ];
        });
    }

    // ── Phase 3: Listing (multi-level browse) ───────────────

    /**
     * Get networks with aggregated stats for group=network view.
     */
    public function getNetworksPaginated(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $query = Owner::gateActive(
                Network::withoutGlobalScope('owner_scope')->where('networks.status', 'active'),
                'networks.owner_id'
            )
            ->withCount(['screens as screen_count' => fn ($q) => $q->where('active', true)])
            ->with(['owner:id,name,slug,cover_url']);

        // Search
        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where('name', 'LIKE', "%{$search}%");
        }

        // Owner filter
        if (! empty($ownerSlugs = $this->resolveArrayParam($request, 'owner'))) {
            $query->whereHas('owner', fn ($oq) => $oq->whereIn('slug', $ownerSlugs));
        }

        // Enrich: site_count, cities list
        $query->withCount('sites')
            ->having('screen_count', '>', 0)
            ->orderByDesc('screen_count');

        return $query->paginate($perPage);
    }

    /**
     * Get sites with aggregated stats for group=site view.
     */
    public function getSitesPaginated(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $query = Owner::gateActive(
                Site::withoutGlobalScope('owner_scope')->where('sites.status', 'active'),
                'sites.owner_id'
            )
            ->withCount(['screens as screen_count' => fn ($q) => $q->where('active', true)])
            ->with(['owner:id,name,slug,cover_url', 'network:id,name']);

        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('address', 'LIKE', "%{$search}%")
                    ->orWhere('city', 'LIKE', "%{$search}%");
            });
        }

        if (! empty($ownerSlugs = $this->resolveArrayParam($request, 'owner'))) {
            $query->whereHas('owner', fn ($oq) => $oq->whereIn('slug', $ownerSlugs));
        }

        if (! empty($networks = $this->resolveArrayParam($request, 'network'))) {
            $query->whereHas('network', fn ($nq) => $nq->whereIn('code', $networks));
        }

        if (! empty($citySlugs = array_unique(array_merge(
            $this->resolveArrayParam($request, 'city'),
            $this->resolveArrayParam($request, 'province')
        )))) {
            $cityNames = $this->expandCitySlugs($citySlugs);
            $query->where(function ($q) use ($cityNames) {
                foreach ($cityNames as $name) {
                    $q->orWhere('city', 'LIKE', $name . '%');
                }
            });
        }

        $query->having('screen_count', '>', 0)
            ->orderByDesc('screen_count');

        return $query->paginate($perPage);
    }

    public function getScreensPaginated(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->buildScreenQuery($request)
            ->with([
                'spec:screen_id,photo_url,photos,width_cm,height_cm',
                'inventory:screen_id,floor_cpm,floor_cpm_currency,venue_type,vn_category_id,pricing_model,io_rate,io_rate_unit,io_kpi_spots_per_day',
                'owner:id,name,slug,cover_url',
                'site:id,network_id,name,city,address,banner',
                'site.network:id,name,code,banner',
                'products:id,slug,name,total_units,listing_mode',
            ]);

        $query = $this->applySort($query, $request);

        return $query->paginate($perPage);
    }

    public function getFilterAggregates(): array
    {
        return Cache::remember('fp:filters', 1800, function () {
            $formats = $this->getVenueTypesWithCounts();
            $cities = $this->getTopCities(20);

            // Khoảng giá tính trên giá **quy đổi về VND**, không phải số thô.
            //
            // Hai bước, ghi lại cả hai vì bước đầu của tôi cũng sai:
            //
            // 1. Ban đầu MIN/MAX chạy trên toàn bộ `floor_cpm` bất kể đơn vị,
            //    nên một hàng USD 2,50 kéo `min_price` xuống 2,50 và thanh lọc
            //    giá thành vô nghĩa (Codex R39).
            // 2. Tôi sửa bằng cách **loại hàng USD** ra khỏi phép tính. Sai
            //    hướng khác: dữ liệu thật CÓ hàng USD (chốt 01/10/2026), nên
            //    loại chúng là làm chúng vô hình với thanh lọc — người mua kéo
            //    thanh giá rồi không bao giờ thấy những màn hình đó.
            //
            // Nay dùng đúng biểu thức quy đổi mà bộ lọc và phép sắp xếp dùng,
            // nên khoảng giá và kết quả lọc không thể lệch nhau.
            $priceRange = DB::table('screen_inventory')
                ->join('screens', 'screen_inventory.screen_id', '=', 'screens.id')
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->where('screen_inventory.floor_cpm', '>', 0)
                ->tap(fn ($q) => Owner::gateActive($q))
                ->selectRaw(
                    'MIN(' . self::vndEquivalentSql() . ') as min_price, MAX(' . self::vndEquivalentSql() . ') as max_price',
                    [
                        (float) config('pricing.usd_vnd_rate', 25000),
                        (float) config('pricing.usd_vnd_rate', 25000),
                    ],
                )
                ->first();

            // Networks with screen counts
            $networks = DB::table('networks')
                ->join('sites', 'networks.id', '=', 'sites.network_id')
                ->join('screens', 'sites.id', '=', 'screens.site_id')
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->whereNull('sites.deleted_at')
                ->whereNull('networks.deleted_at')
                ->where('networks.status', 'active')
                ->tap(fn ($q) => Owner::gateActive($q))
                ->selectRaw('networks.id, networks.code, networks.name, COUNT(DISTINCT screens.id) as count')
                ->groupBy('networks.id', 'networks.code', 'networks.name')
                ->orderByDesc('count')
                ->limit(30)
                ->get();

            // Owners with screen counts
            $owners = DB::table('owners')
                ->join('screens', 'owners.id', '=', 'screens.owner_id')
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->whereNull('owners.deleted_at')
                ->where('owners.status', 'active')
                ->selectRaw('owners.id, owners.slug, owners.name, COUNT(DISTINCT screens.id) as count')
                ->groupBy('owners.id', 'owners.slug', 'owners.name')
                ->orderByDesc('count')
                ->limit(20)
                ->get();

            return [
                'formats'              => $formats,
                'categories_networks'  => $this->getCategoriesWithNetworks(),
                'cities'               => $cities,
                'networks'             => $networks,
                'owners'               => $owners,
                'min_price'            => (float) ($priceRange->min_price ?? 0),
                'max_price'            => (float) ($priceRange->max_price ?? 0),
                // Nói ra đơn vị thay vì để người đọc tự đoán. Đây là VND **quy
                // đổi**, gồm cả hàng niêm yết bằng USD — xem `vndEquivalentSql()`.
                'price_currency'       => 'VND',
            ];
        });
    }

    // ── Phase 4: Detail ───────────────────────────────────

    public function getScreenDetail(string $id): ?Screen
    {
        return Cache::remember("fp:screen:{$id}", 300, function () use ($id) {
            return Screen::publiclyVisible()
                ->with([
                    'spec:screen_id,photo_url,photos,width_px,height_px,width_cm,height_cm,allow_image,allow_video',
                    'inventory:screen_id,floor_cpm,floor_cpm_currency,venue_type,vn_category_id,pricing_model,io_rate,io_rate_unit,io_kpi_spots_per_day',
                    'inventory.vnCategory:id,thumb',
                    'owner:id,name,slug,logo_url,cover_url,verified',
                    'site:id,network_id,name,city,address,lat,lon,banner',
                    'site.network:id,name,code,banner',
                ])
                ->where(function ($q) use ($id) {
                    $q->where('slug', $id)
                      ->orWhere('id', $id)
                      ->orWhere('uuid', $id)
                      ->orWhere('external_id', $id);
                })
                ->first();
        });
    }

    public function getSimilarScreens(Screen $screen, int $limit = 4): Collection
    {
        return Cache::remember("fp:similar:{$screen->id}", 600, function () use ($screen, $limit) {
            return Screen::publiclyVisible()
                ->where('id', '!=', $screen->id)
                ->where(function ($q) use ($screen) {
                    $q->whereHas('site', fn ($sq) => $sq->where('city', $screen->site?->city))
                      ->orWhereHas('inventory', fn ($iq) => $iq->where('venue_type', $screen->inventory?->venue_type));
                })
                ->with([
                    'spec:screen_id,photo_url,photos,width_cm,height_cm',
                    'inventory:screen_id,floor_cpm,floor_cpm_currency,venue_type,vn_category_id,pricing_model,io_rate,io_rate_unit,io_kpi_spots_per_day',
                    'inventory.vnCategory:id,thumb',
                    'owner:id,name,slug,cover_url',
                    'site:id,network_id,name,city,address,banner',
                    'site.network:id,name,code,banner',
                ])
                ->inRandomOrder()
                ->limit($limit)
                ->get();
        });
    }

    // ── Phase 5: Map ──────────────────────────────────────

    /**
     * Pin bản đồ.
     *
     * `$viewport` là khung nhìn `['north','south','east','west']`. Trang Blade
     * gọi không kèm khung nhìn nên **hành vi cũ giữ nguyên**; `/api/v2` bắt
     * buộc phải có, và chỗ bắt buộc nằm ở tầng request chứ không ở đây — như
     * vậy một đường gọi quên khung nhìn là lỗi 422 nhìn thấy được, không phải
     * một truy vấn âm thầm trả cả kho.
     *
     * `$limit` là giới hạn cứng số pin. Khi bị cắt thì phải có thứ tự xác
     * định, nếu không mỗi lần kéo bản đồ lại ra một tập pin khác nhau mà
     * không vì lý do gì.
     */
    public function getMapPins(Request $request, ?array $viewport = null, ?int $limit = null): Collection
    {
        $query = $this->mapPinQuery($request, $viewport)
            ->with([
                'spec:screen_id,photo_url,photos,width_cm,height_cm',
                'inventory:screen_id,floor_cpm,floor_cpm_currency,venue_type,vn_category_id,pricing_model,io_rate,io_rate_unit,io_kpi_spots_per_day',
                'inventory.vnCategory:id,thumb',
                'owner:id,name,slug,logo_url,cover_url',
                'site:id,network_id,name,lat,lon,city,address,banner',
                'site.network:id,name,code,banner',
                'products:id,slug,name,total_units,listing_mode',
            ]);

        if ($limit !== null) {
            $query->orderBy('screens.id')->limit($limit);
        }

        return $query->get();
    }

    /**
     * Đếm pin khớp bộ lọc và khung nhìn — để client biết mình đang bị cắt bao
     * nhiêu và cần thu nhỏ khung nhìn tới đâu. Dùng chung đúng một builder với
     * `getMapPins()`, nên con số đếm không thể lệch khỏi tập trả về.
     */
    public function countMapPins(Request $request, ?array $viewport = null): int
    {
        return $this->mapPinQuery($request, $viewport)->count();
    }

    /**
     * Builder dùng chung cho pin bản đồ: cùng bộ lọc với danh sách, thêm điều
     * kiện có toạ độ và (nếu có) khung nhìn.
     */
    private function mapPinQuery(Request $request, ?array $viewport = null)
    {
        return $this->buildScreenQuery($request)
            ->whereHas('site', function ($q) use ($viewport) {
                $q->whereNotNull('lat')->whereNotNull('lon')
                    ->where('lat', '!=', 0)->where('lon', '!=', 0);

                if ($viewport === null) {
                    return;
                }

                $q->whereBetween('lat', [$viewport['south'], $viewport['north']]);

                // Khung nhìn vắt qua kinh tuyến 180 thì `west` lớn hơn `east`
                // và `whereBetween` trả rỗng. Dữ liệu hiện chỉ có Việt Nam nên
                // ca này không xảy ra, nhưng để `whereBetween` trần ở đây là
                // để lại một cái bẫy im lặng cho người dùng bản đồ thế giới.
                if ($viewport['west'] <= $viewport['east']) {
                    $q->whereBetween('lon', [$viewport['west'], $viewport['east']]);
                } else {
                    $q->where(fn ($or) => $or
                        ->where('lon', '>=', $viewport['west'])
                        ->orWhere('lon', '<=', $viewport['east']));
                }
            });
    }

    /**
     * Những slug thành phố mà bộ chọn trang chủ nhận.
     *
     * Công khai vì `Api\V2\CityPinsRequest` kiểm tham số `city` theo đúng danh
     * sách này. Để request tự khai một danh sách thứ hai là mở đúng cái cửa mà
     * phép kiểm đó đang đóng: `resolveCityName()` trả `null` cho slug lạ, và
     * khi đó `getCityMapPins()` KHÔNG áp filter thành phố nào — tức một slug
     * sai cho ra pin của cả nước thay vì một lỗi.
     *
     * @return list<string>
     */
    public static function citySlugs(): array
    {
        return array_keys(self::CITY_SLUG_MAP);
    }

    /**
     * Màn hình có toạ độ trong một thành phố, trả về **model** chứ không phải
     * mảng đã dựng sẵn.
     *
     * Tách ra khỏi `getHomepageMapPins()` để `/api/v2/screens/pins` dùng lại
     * đúng truy vấn này rồi tự dựng DTO bằng `MapPinResource`. Nếu endpoint tự
     * viết truy vấn riêng thì hai đường sẽ trôi khỏi nhau — đúng lỗi R38 đã
     * mắc một lần, khi truy vấn riêng nạp đủ cột network còn đường dùng chung
     * thiếu `code`.
     *
     * `inRandomOrder()` giữ nguyên theo bản Blade: bản đồ nhỏ trên trang chủ
     * đổi pin mỗi lần tải là có chủ ý. Hệ quả cho bên tiêu thụ: thứ tự **không
     * định trước**, nên đừng dựa vào nó để phân trang hay so sánh hai lần gọi.
     */
    public function getCityMapPins(string $citySlug, int $limit = 50): Collection
    {
        $cityName = $this->resolveCityName($citySlug);

        $query = Screen::publiclyVisible()
            ->whereHas('site', fn ($q) => $q->whereNotNull('lat')->whereNotNull('lon')
                ->where('lat', '!=', 0)->where('lon', '!=', 0));

        if ($cityName) {
            $this->applyCityFilter($query, $cityName);
        }

        return $query
            ->with(['spec:screen_id,photo_url,photos,width_cm,height_cm', 'inventory:screen_id,floor_cpm,floor_cpm_currency,venue_type,vn_category_id,pricing_model,io_rate,io_rate_unit,io_kpi_spots_per_day', 'inventory.vnCategory:id,thumb', 'owner:id,name,slug,logo_url,cover_url', 'site:id,network_id,name,lat,lon,city,address,banner', 'site.network:id,name,code,banner'])
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }

    /**
     * Get pins for the homepage mini-map, scoped to a city.
     * Returns lightweight data for up to $limit screens.
     */
    public function getHomepageMapPins(string $citySlug = 'hanoi', int $limit = 50): array
    {
        $screens = $this->getCityMapPins($citySlug, $limit);

        // Build screen-level data first (full fields for rich marker + popup)
        $vnCatLabels = $this->getVnCategoryLabels();
        $vnCatSlugs  = $this->getVnCategorySlugs();
        $vnCatIcons  = $this->getVnCategoryIcons();

        $screenData = $screens->map(function ($s) use ($vnCatLabels, $vnCatSlugs, $vnCatIcons) {
            $catId = $s->inventory?->vn_category_id;
            $wCm = $s->spec?->width_cm;
            $hCm = $s->spec?->height_cm;
            $size = ($wCm && $hCm) ? round($wCm / 100, 1) . '×' . round($hCm / 100, 1) . 'm' : '';
            return [
                'siteId'     => $s->site?->id,
                'id'         => $s->slug ?? $s->uuid ?? $s->id,
                'name'       => $s->name,
                'lat'        => (float) ($s->site?->lat ?? 0),
                'lng'        => (float) ($s->site?->lon ?? 0),
                'city'       => $s->site?->city ?? '',
                'addr'       => $s->site?->address ?? '',
                'photo'      => $s->display_photo ?? '',
                'price'      => (float) ($s->inventory?->display_price ?? 0),
                'priceUnit'  => $s->inventory?->display_price_unit ?? '',
                'type'       => $vnCatSlugs[$catId] ?? '',
                'typeLabel'  => $vnCatLabels[$catId] ?? '',
                'icon'       => $vnCatIcons[$catId] ?? 'tv',
                'ownerName'  => $s->owner?->name ?? '',
                'ownerLogo'  => $s->owner?->logo_url ? asset('storage/' . $s->owner->logo_url) : '',
                'ownerInitials' => $s->owner ? strtoupper(mb_substr($s->owner->name, 0, 2)) : '',
                'networkName' => $s->site?->network?->name ?? '',
                'siteName'   => $s->site?->name ?? '',
                'size'       => $size,
            ];
        })->filter(fn ($s) => $s['lat'] != 0 && $s['lng'] != 0)->values();

        // Group by site_id for site-level markers
        $pins = $screenData->groupBy('siteId')->map(function ($group) {
            $first = $group->first();
            return [
                'siteId'      => $first['siteId'],
                'lat'         => $first['lat'],
                'lng'         => $first['lng'],
                'siteName'    => $first['siteName'] ?: $first['name'],
                'city'        => $first['city'],
                'addr'        => $first['addr'],
                'photo'       => $first['photo'],
                'ownerName'   => $first['ownerName'],
                'ownerLogo'   => $first['ownerLogo'],
                'ownerInitials' => $first['ownerInitials'],
                'networkName' => $first['networkName'],
                'type'        => $first['type'],
                'typeLabel'   => $first['typeLabel'],
                'icon'        => $first['icon'],
                'screenCount' => $group->count(),
                'screens'     => $group->map(fn ($s) => [
                    'id'        => $s['id'],
                    'name'      => $s['name'],
                    'photo'     => $s['photo'],
                    'price'     => $s['price'],
                    'priceUnit' => $s['priceUnit'],
                    'size'      => $s['size'],
                    'typeLabel' => $s['typeLabel'],
                ])->values(),
            ];
        })->values();

        return [
            'pins'      => $pins,
            'total'     => $this->getHomepageMapCount($citySlug),
            'citySlug'  => $citySlug,
            'cityName'  => $cityName ?? 'Toàn quốc',
        ];
    }

    /**
     * Resolve city slug to province name for LIKE filtering.
     * sites.city stores "Hà Nội > Phường Dương Nội" — we match by province prefix.
     * Returns null for 'all' (no filter = nationwide).
     */
    private function resolveCityName(string $slug): ?string
    {
        if ($slug === 'all') {
            return null;
        }

        // Check hardcoded map first
        if (isset(self::CITY_SLUG_MAP[$slug])) {
            return self::CITY_SLUG_MAP[$slug];
        }

        // Fallback: find province name from DB by matching slug
        $province = DB::table('sites')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->selectRaw("DISTINCT TRIM(SUBSTRING_INDEX(city, '>', 1)) as province")
            ->get()
            ->first(fn ($r) => Str::slug($r->province) === $slug);

        return $province?->province;
    }

    /**
     * Apply city/province filter using LIKE prefix match.
     * Handles "Hà Nội > Phường..." format in sites.city.
     */
    private function applyCityFilter($query, string $cityName)
    {
        return $query->whereHas('site', fn ($q) => $q->where('city', 'LIKE', $cityName . '%'));
    }

    private function getHomepageMapCount(string $citySlug): int
    {
        return Cache::remember("fp:map_count:{$citySlug}", 1800, function () use ($citySlug) {
            $cityName = $this->resolveCityName($citySlug);

            $query = Screen::publiclyVisible()
                ->whereHas('site', fn ($q) => $q->whereNotNull('lat')->whereNotNull('lon')
                    ->where('lat', '!=', 0)->where('lon', '!=', 0));

            if ($cityName) {
                $this->applyCityFilter($query, $cityName);
            }

            return $query->count();
        });
    }

    // ── Private helpers ───────────────────────────────────

    private function loadOwnerExtras($owners): void
    {
        $ownerIds = $owners->pluck('id');
        if ($ownerIds->isEmpty()) {
            return;
        }

        $cityCounts = DB::table('screens')
            ->join('sites', 'screens.site_id', '=', 'sites.id')
            ->whereIn('screens.owner_id', $ownerIds)
            ->where('screens.active', true)
            ->whereNull('screens.deleted_at')
            ->whereNull('sites.deleted_at')
            ->whereNotNull('sites.city')
            ->where('sites.city', '!=', '')
            ->selectRaw("screens.owner_id, COUNT(DISTINCT TRIM(SUBSTRING_INDEX(sites.city, '>', 1))) as city_count")
            ->groupBy('screens.owner_id')
            ->pluck('city_count', 'owner_id');

        $venueTypesByOwner = DB::table('screens')
            ->join('screen_inventory', 'screens.id', '=', 'screen_inventory.screen_id')
            ->join('venue_categories', 'screen_inventory.vn_category_id', '=', 'venue_categories.id')
            ->whereIn('screens.owner_id', $ownerIds)
            ->where('screens.active', true)
            ->whereNull('screens.deleted_at')
            ->selectRaw('screens.owner_id, COALESCE(venue_categories.name_vi, venue_categories.name) as label')
            ->distinct()
            ->get()
            ->groupBy('owner_id')
            ->map(fn ($rows) => $rows->pluck('label')->sort()->values()->toArray());

        $owners->each(function ($owner) use ($cityCounts, $venueTypesByOwner) {
            $owner->city_count       = (int) ($cityCounts[$owner->id] ?? 0);
            $owner->venue_types_list = $venueTypesByOwner[$owner->id] ?? [];
        });
    }

    private function buildScreenQuery(Request $request)
    {
        return Screen::publiclyVisible()
            ->when($request->filled('q'), function ($q) use ($request) {
                $search = $request->input('q');
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'LIKE', "%{$search}%")
                        ->orWhereHas('site', fn ($sq) => $sq->where('address', 'LIKE', "%{$search}%")
                            ->orWhere('city', 'LIKE', "%{$search}%"));
                });
            })
            ->when(! empty($citySlugs = array_unique(array_merge(
                $this->resolveArrayParam($request, 'city'),
                $this->resolveArrayParam($request, 'province')
            ))),
                fn ($q) => $q->whereHas('site', function ($sq) use ($citySlugs) {
                    $cityNames = $this->expandCitySlugs($citySlugs);
                    $sq->where(function ($or) use ($cityNames) {
                        foreach ($cityNames as $name) {
                            $or->orWhere('city', 'LIKE', $name . '%');
                        }
                    });
                })
            )
            ->when(! empty($venueTypes = $this->resolveArrayParam($request, 'venue_type')),
                fn ($q) => $q->whereHas('inventory', function ($iq) use ($venueTypes) {
                    // Resolve VN category slugs to IDs
                    $catIds = DB::table('venue_categories')->whereIn('slug', $venueTypes)->pluck('id');
                    if ($catIds->isNotEmpty()) {
                        $iq->whereIn('vn_category_id', $catIds);
                    } else {
                        // Fallback: raw venue_type match
                        $iq->whereIn('venue_type', $venueTypes);
                    }
                })
            )
            ->when(! empty($screenTypes = $this->resolveArrayParam($request, 'screen_type')),
                fn ($q) => $this->applyScreenTypeFilter($q, $screenTypes)
            )
            ->when(! empty($orientations = $this->resolveArrayParam($request, 'orientation')),
                fn ($q) => $this->applyOrientationFilter($q, $orientations)
            )
            ->when(! empty($networks = $this->resolveArrayParam($request, 'network')),
                fn ($q) => $q->whereHas('site.network', fn ($nq) => $nq->whereIn('code', $networks))
            )
            ->when(! empty($ownerSlugs = $this->resolveArrayParam($request, 'owner')),
                fn ($q) => $q->whereHas('owner', fn ($oq) => $oq->whereIn('slug', $ownerSlugs))
            )
            // Lọc theo giá **quy đổi về VND**: khoảng giá người dùng gõ là VND,
            // nhưng `floor_cpm` có hàng USD, nên so trực tiếp thì một màn hình
            // 2,50 USD lọt vào khoảng "dưới 1.000 ₫" (Codex R39).
            ->when($request->filled('min_price'),
                fn ($q) => $q->whereHas('inventory', fn ($iq) => $iq->whereRaw(
                    self::vndEquivalentSql() . ' >= ?',
                    [(float) config('pricing.usd_vnd_rate', 25000), (float) $request->input('min_price')],
                ))
            )
            ->when($request->filled('max_price'),
                fn ($q) => $q->whereHas('inventory', fn ($iq) => $iq->whereRaw(
                    self::vndEquivalentSql() . ' <= ?',
                    [(float) config('pricing.usd_vnd_rate', 25000), (float) $request->input('max_price')],
                ))
            )
            ->when($request->filled('site'),
                fn ($q) => $q->where('site_id', $request->input('site'))
            )
            ->when(! empty($districtCodes = $this->resolveArrayParam($request, 'district')),
                fn ($q) => $q->whereIn('location_district_code', $districtCodes)
            )
            ->when(! empty($regionCodes = $this->resolveArrayParam($request, 'region')), function ($q) use ($regionCodes) {
                $validRegions = array_intersect($regionCodes, ['north', 'central', 'south']);
                if (empty($validRegions)) {
                    return;
                }
                $regionConfig  = config('regions');
                $provinceCodes = collect($validRegions)
                    ->flatMap(fn ($r) => $regionConfig[$r]['provinces'] ?? [])
                    ->unique()->values()->all();
                $cityNames = array_map(fn ($p) => self::CITY_SLUG_MAP[$p] ?? $p, $provinceCodes);
                $q->whereHas('site', function ($sq) use ($cityNames) {
                    $sq->where(function ($or) use ($cityNames) {
                        foreach ($cityNames as $name) {
                            $or->orWhere('city', 'LIKE', $name . '%');
                        }
                    });
                });
            });
    }


    private function expandCitySlugs(array $slugs): array
    {
        // Build code → Vietnamese name map from DB + hardcoded
        $codeToName = Cache::remember('fp:code_to_city_name', 3600, function () {
            $map = self::CITY_SLUG_MAP; // hanoi => Hà Nội, hcm => Hồ Chí Minh, etc.
            // Add all vietnam_provinces: no-dash slug → name
            DB::table('vietnam_provinces')->select('name')->get()->each(function ($p) use (&$map) {
                $code = str_replace('-', '', Str::slug($p->name));
                if (! isset($map[$code])) {
                    $map[$code] = $p->name;
                }
            });
            return $map;
        });

        return collect($slugs)
            ->flatMap(function ($slug) use ($codeToName) {
                $noDash = str_replace('-', '', $slug);
                return array_unique(array_filter([
                    $codeToName[$slug] ?? null,
                    $codeToName[$noDash] ?? null,
                    $slug,
                ]));
            })
            ->unique()
            ->values()
            ->all();
    }

    private function resolveArrayParam(Request $request, string $key): array
    {
        $value = $request->input($key);

        if (is_array($value)) {
            return array_values(array_filter($value, fn ($v) => $v !== '' && $v !== null));
        }
        if (is_string($value) && str_contains($value, '|')) {
            return array_values(array_filter(explode('|', $value), fn ($v) => $v !== ''));
        }
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return [];
    }

    private function applyScreenTypeFilter($query, array $types)
    {
        return $query->where(function ($or) use ($types) {
            foreach ($types as $type) {
                $or->orWhere(function ($sub) use ($type) {
                    if ($type === 'billboard') {
                        $sub->whereHas('spec', fn ($sq) => $sq->where('width_cm', '>=', 300)->orWhere('height_cm', '>=', 300));
                    } elseif ($type === 'led') {
                        $sub->whereHas('inventory', fn ($iq) => $iq->where('venue_type', 'outdoor'))
                            ->whereHas('spec', fn ($sq) => $sq->where('width_cm', '<', 300)->where('height_cm', '<', 300));
                    } else {
                        $sub->whereHas('inventory', fn ($iq) => $iq->where('venue_type', '!=', 'outdoor'))
                            ->whereHas('spec', fn ($sq) => $sq->where('width_cm', '<', 300)->where('height_cm', '<', 300));
                    }
                });
            }
        });
    }

    private function applyOrientationFilter($query, array $orientations)
    {
        $valid = array_intersect($orientations, ['landscape', 'portrait', 'square']);
        if (empty($valid)) {
            return $query;
        }

        return $query->whereHas('spec', function ($sq) use ($valid) {
            $sq->where(function ($or) use ($valid) {
                foreach ($valid as $o) {
                    $or->orWhere(function ($sub) use ($o) {
                        match ($o) {
                            'landscape' => $sub->whereColumn('width_px', '>', 'height_px'),
                            'portrait'  => $sub->whereColumn('width_px', '<', 'height_px'),
                            'square'    => $sub->whereColumn('width_px', '=', 'height_px'),
                        };
                    });
                }
            });
        });
    }

    /**
     * Biểu thức SQL quy đổi `floor_cpm` về VND, **chỉ để so sánh và sắp xếp**.
     *
     * Một định nghĩa duy nhất cho lọc giá, sắp xếp giá và khoảng giá của bộ
     * lọc — ba chỗ dùng ba công thức là ba kết quả khác nhau cho cùng một câu
     * hỏi. Tỷ giá truyền vào dưới dạng binding, không nhúng vào chuỗi SQL.
     *
     * Hàng `NULL` currency coi là VND vì cột đó mặc định VND.
     */
    private static function vndEquivalentSql(string $table = 'screen_inventory'): string
    {
        return "(CASE WHEN {$table}.floor_cpm_currency = 'USD' THEN {$table}.floor_cpm * ? ELSE {$table}.floor_cpm END)";
    }

    private function applySort($query, Request $request)
    {
        $sort = $request->input('sort');

        if ($sort === 'price_asc' || $sort === 'price_desc') {
            // Sắp xếp theo giá **quy đổi về VND**, không theo số thô.
            //
            // `floor_cpm_currency` có hàng USD trong dữ liệu thật, nên so trực
            // tiếp hai đơn vị cho ra thứ tự vô nghĩa: 2,50 USD xếp dưới
            // 1.000 ₫ (Codex R39). Quy đổi chỉ để so sánh; giá hiển thị vẫn
            // giữ nguyên đơn vị gốc.
            $query->leftJoin('screen_inventory as sort_inv', 'screens.id', '=', 'sort_inv.screen_id')
                  ->orderByRaw(
                      self::vndEquivalentSql('sort_inv') . ' ' . ($sort === 'price_asc' ? 'asc' : 'desc'),
                      [(float) config('pricing.usd_vnd_rate', 25000)],
                  )
                  ->select('screens.*');

            return $query;
        }

        return match ($sort) {
            'newest' => $query->orderBy('screens.created_at', 'desc'),
            default  => $query->orderBy('screens.created_at', 'desc'),
        };
    }

    private function cityToSlug(string $cityName): string
    {
        $flipped = array_flip(self::CITY_SLUG_MAP);

        return $flipped[$cityName] ?? Str::slug($cityName);
    }
}
