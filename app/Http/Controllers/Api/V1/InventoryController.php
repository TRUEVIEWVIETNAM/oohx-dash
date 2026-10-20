<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\OwnerPublicResource;
use App\Http\Resources\Api\ScreenCollection;
use App\Http\Resources\Api\ScreenMapCollection;
use App\Http\Resources\Api\ScreenResource;
use App\Models\Network;
use App\Models\Owner;
use App\Models\Screen;
use App\Models\VenueType;
use App\Support\PhanTrangOnDinh;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryController extends Controller
{
    use PhanTrangOnDinh;

    public function index(Request $request): ScreenCollection
    {
        $request->validate([
            'q'        => 'sometimes|nullable|string|max:100',
            'region'   => 'sometimes|array',
            'region.*' => 'in:north,central,south',
        ]);

        $limit = min((int) $request->input('limit', 50), 100);

        $with = $this->resolveRelations($request);
        $query = $this->buildScreenQuery($request)->with($with);
        $query = $this->applySort($query, $request);

        return new ScreenCollection($this->phanTrang($query, $limit));
    }

    public function map(Request $request): ScreenMapCollection
    {
        $request->validate([
            'q'        => 'sometimes|nullable|string|max:100',
            'region'   => 'sometimes|array',
            'region.*' => 'in:north,central,south',
        ]);

        $screens = $this->buildScreenQuery($request)
            ->with([
                'spec:screen_id,photo_url,width_cm,height_cm',
                'inventory:screen_id,venue_type',
                'site:id,address,lat,lon',
            ])
            ->get();

        return new ScreenMapCollection($screens);
    }

    public function show(string $screenId): JsonResponse|ScreenResource
    {
        $with = $this->resolveRelations(request());
        $screen = Screen::with($with)
            ->where('external_id', $screenId)
            ->orWhere('uuid', $screenId)
            ->first();

        if (! $screen) {
            return response()->json([
                'error'   => 'screen_not_found',
                'message' => "Screen {$screenId} does not exist",
            ], 404);
        }

        return new ScreenResource($screen);
    }

    public function stats(): JsonResponse
    {
        $data = Cache::remember('inventory_stats', 1800, function () {
            // Chỉ đếm screens đang active
            $totalScreens = Screen::where('active', true)->count();

            $totalCities = DB::table('screens')
                ->join('sites', 'screens.site_id', '=', 'sites.id')
                ->where('screens.active', true)
                ->whereNotNull('sites.city')
                ->where('sites.city', '!=', '')
                ->distinct()
                ->count('sites.city');

            $totalOwners = Screen::where('active', true)
                ->distinct()
                ->count('owner_id');

            // Venue counts — LEFT JOIN venue_types để lấy label & sort từ taxonomy
            $venueCounts = DB::table('screens')
                ->join('screen_inventory', 'screens.id', '=', 'screen_inventory.screen_id')
                ->leftJoin('venue_types', 'screen_inventory.venue_type', '=', 'venue_types.string_value')
                ->where('screens.active', true)
                ->whereNotNull('screen_inventory.venue_type')
                ->selectRaw('screen_inventory.venue_type as type, venue_types.venue_type as taxonomy_label, venue_types.enumeration_id, count(*) as count')
                ->groupBy('screen_inventory.venue_type', 'venue_types.venue_type', 'venue_types.enumeration_id')
                ->get()
                ->map(fn($r) => [
                    'type'  => $r->type,
                    'label' => $r->taxonomy_label ?? ucfirst(str_replace(['_', '.'], ' ', $r->type)),
                    'count' => (int) $r->count,
                    '_sort' => $r->enumeration_id ?? PHP_INT_MAX,
                ])
                ->sortBy('_sort')
                ->map(fn($v) => ['type' => $v['type'], 'label' => $v['label'], 'count' => $v['count']])
                ->values();

            // 5 thành phố lớn cố định
            $cityNameMap = array_flip(self::CITY_SLUG_MAP); // 'Hà Nội' => 'hanoi', ...
            $cityDisplayNames = [
                'hanoi'    => 'Hà Nội',
                'hcm'      => 'TP. Hồ Chí Minh',
                'danang'   => 'Đà Nẵng',
                'haiphong' => 'Hải Phòng',
                'cantho'   => 'Cần Thơ',
            ];

            $rawCityCounts = DB::table('screens')
                ->join('sites', 'screens.site_id', '=', 'sites.id')
                ->where('screens.active', true)
                ->whereIn('sites.city', array_values(self::CITY_SLUG_MAP))
                ->selectRaw('sites.city, count(*) as count')
                ->groupBy('sites.city')
                ->pluck('count', 'sites.city');

            $cities = collect($cityDisplayNames)->map(fn($displayName, $code) => [
                'code'  => $code,
                'name'  => $displayName,
                'count' => (int) ($rawCityCounts[self::CITY_SLUG_MAP[$code]] ?? 0),
            ])->values();

            return [
                'total_screens' => $totalScreens,
                'total_cities'  => $totalCities,
                'total_owners'  => $totalOwners,
                'venues'        => $venueCounts,
                'cities'        => $cities,
            ];
        });

        return response()->json($data);
    }

    public function venueTypes(): JsonResponse
    {
        $data = Cache::remember('inventory_venue_types', 1800, function () {
            // Đếm screens active theo từng venue_type slug
            $directCounts = DB::table('screens')
                ->join('screen_inventory', 'screens.id', '=', 'screen_inventory.screen_id')
                ->where('screens.active', true)
                ->whereNotNull('screen_inventory.venue_type')
                ->selectRaw('screen_inventory.venue_type as slug, count(*) as cnt')
                ->groupBy('screen_inventory.venue_type')
                ->pluck('cnt', 'slug')
                ->map(fn($c) => (int) $c);

            if ($directCounts->isEmpty()) {
                return [];
            }

            // Load toàn bộ taxonomy (is_active), keyed by id và string_value
            $allNodes   = VenueType::where('is_active', true)->orderBy('enumeration_id')->get()->keyBy('id');
            $nodeBySlug = $allNodes->keyBy('string_value');

            // Roll-up: cộng count của con lên toàn bộ tổ tiên
            $rolledCounts = $directCounts->toArray();
            foreach ($directCounts as $slug => $count) {
                $node = $nodeBySlug->get($slug);
                while ($node?->parent_id) {
                    $parent = $allNodes->get($node->parent_id);
                    if (! $parent?->string_value) break;
                    $rolledCounts[$parent->string_value] = ($rolledCounts[$parent->string_value] ?? 0) + $count;
                    $node = $parent;
                }
            }

            // Build cây đệ quy từ root (parent_id = null)
            $buildTree = function (?int $parentId) use (&$buildTree, $allNodes, $rolledCounts): array {
                return $allNodes
                    ->filter(fn($vt) => $vt->parent_id === $parentId)
                    ->filter(fn($vt) => ($rolledCounts[$vt->string_value] ?? 0) > 0)
                    ->sortBy('enumeration_id')
                    ->map(fn($vt) => [
                        'type'     => $vt->string_value,
                        'label'    => $vt->venue_type,
                        'count'    => $rolledCounts[$vt->string_value],
                        'children' => $buildTree($vt->id),
                    ])
                    ->values()
                    ->toArray();
            };

            $tree = collect($buildTree(null));

            // Orphan slugs: có trong screen_inventory nhưng không có trong taxonomy
            $orphans = $directCounts->keys()
                ->filter(fn($slug) => ! $nodeBySlug->has($slug))
                ->map(fn($slug) => [
                    'type'     => $slug,
                    'label'    => ucfirst(str_replace(['_', '.'], ' ', $slug)),
                    'count'    => $directCounts[$slug],
                    'children' => [],
                ]);

            return $tree->concat($orphans)->values()->toArray();
        });

        return response()->json(['data' => $data]);
    }

    public function networks(): JsonResponse
    {
        $data = Cache::remember('inventory:networks', 1800, function () {
            return DB::table('networks')
                ->join('sites', 'sites.network_id', '=', 'networks.id')
                ->join('screens', 'screens.site_id', '=', 'sites.id')
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->whereNull('sites.deleted_at')
                ->selectRaw('networks.code, networks.name, count(distinct screens.id) as screen_count')
                ->groupBy('networks.code', 'networks.name')
                ->orderByDesc('screen_count')
                ->get()
                ->map(fn($r) => [
                    'code'         => $r->code,
                    'name'         => $r->name,
                    'screen_count' => (int) $r->screen_count,
                ])
                ->values()
                ->toArray();
        });

        return response()->json(['data' => $data]);
    }

    public function locations(): JsonResponse
    {
        $data = Cache::remember('inventory:locations', 1800, function () {
            $regionConfig = config('regions');
            $cityToCode   = array_flip(self::CITY_SLUG_MAP);

            // Provinces: group by sites.city, count active screens
            $rawProvinceCounts = DB::table('screens')
                ->join('sites', 'screens.site_id', '=', 'sites.id')
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->whereNotNull('sites.city')
                ->where('sites.city', '!=', '')
                ->selectRaw('sites.city, count(*) as count')
                ->groupBy('sites.city')
                ->get();

            $provinces = $rawProvinceCounts->map(function ($r) use ($cityToCode, $regionConfig) {
                $code   = $cityToCode[$r->city] ?? Str::slug($r->city);
                $region = null;
                foreach ($regionConfig as $rc => $cfg) {
                    if (in_array($code, $cfg['provinces'])) {
                        $region = $rc;
                        break;
                    }
                }
                return [
                    'code'   => $code,
                    'name'   => $r->city,
                    'region' => $region,
                    'count'  => (int) $r->count,
                ];
            })->filter(fn($p) => $p['region'] !== null)->values();

            // Regions: roll-up count from provinces
            $regionCounts = $provinces->groupBy('region')
                ->map(fn($items) => $items->sum('count'));

            $regions = collect($regionConfig)
                ->filter(fn($cfg, $code) => $regionCounts->has($code))
                ->map(fn($cfg, $code) => [
                    'code'  => $code,
                    'name'  => $cfg['name'],
                    'count' => (int) $regionCounts[$code],
                ])
                ->values();

            // Districts: from screens.location_district_code
            $districts = DB::table('screens')
                ->join('sites', 'screens.site_id', '=', 'sites.id')
                ->where('screens.active', true)
                ->whereNull('screens.deleted_at')
                ->whereNotNull('screens.location_district_code')
                ->where('screens.location_district_code', '!=', '')
                ->selectRaw('screens.location_district_code as code, screens.location_district as name, sites.city, count(*) as count')
                ->groupBy('screens.location_district_code', 'screens.location_district', 'sites.city')
                ->get()
                ->map(function ($r) use ($cityToCode) {
                    $provinceCode = $cityToCode[$r->city] ?? Str::slug($r->city);
                    return [
                        'code'     => $r->code,
                        'name'     => $r->name ?? $r->code,
                        'province' => $provinceCode,
                        'count'    => (int) $r->count,
                    ];
                })
                ->values();

            return [
                'regions'   => $regions->toArray(),
                'provinces' => $provinces->toArray(),
                'districts' => $districts->toArray(),
            ];
        });

        return response()->json($data);
    }

    public function owners(Request $request): JsonResponse
    {
        $request->validate([
            'featured' => 'sometimes|nullable',
            'limit'    => 'sometimes|integer|min:1|max:100',
            'page'     => 'sometimes|integer|min:1',
            'q'        => 'sometimes|nullable|string|max:100',
        ]);

        $limit = min((int) $request->input('limit', 20), 100);

        $query = Owner::query()
            ->where('status', 'active')
            ->when($request->boolean('featured'), fn($q) => $q->where('featured', true))
            ->when($request->filled('q'), fn($q) => $q->where('name', 'LIKE', '%' . $request->input('q') . '%'))
            ->withCount(['screens as screen_count' => fn($q) => $q->where('active', true)])
            ->orderByDesc('featured')
            ->orderBy('name');

        // `featured` là boolean và `name` trùng được, nên hai khoá này chưa
        // phủ hết: `phanTrang()` thêm `owners.id` làm khoá phụ.
        $owners = $this->phanTrang($query, $limit);

        // Batch load city_count và venue_types để tránh N+1
        $ownerIds = $owners->pluck('id');

        $cityCounts = DB::table('screens')
            ->join('sites', 'screens.site_id', '=', 'sites.id')
            ->whereIn('screens.owner_id', $ownerIds)
            ->where('screens.active', true)
            ->whereNotNull('sites.city')
            ->where('sites.city', '!=', '')
            ->selectRaw('screens.owner_id, count(distinct sites.city) as city_count')
            ->groupBy('screens.owner_id')
            ->pluck('city_count', 'owner_id');

        $venueTypesByOwner = DB::table('screens')
            ->join('screen_inventory', 'screens.id', '=', 'screen_inventory.screen_id')
            ->whereIn('screens.owner_id', $ownerIds)
            ->where('screens.active', true)
            ->whereNotNull('screen_inventory.venue_type')
            ->selectRaw('screens.owner_id, screen_inventory.venue_type')
            ->distinct()
            ->get()
            ->groupBy('owner_id')
            ->map(fn($rows) => $rows->pluck('venue_type')->sort()->values()->toArray());

        // Gắn computed values vào từng owner model
        $owners->each(function ($owner) use ($cityCounts, $venueTypesByOwner) {
            $owner->city_count   = (int) ($cityCounts[$owner->id] ?? 0);
            $owner->venue_types_list = $venueTypesByOwner[$owner->id] ?? [];
        });

        return response()->json([
            'total' => $owners->total(),
            'page'  => $owners->currentPage(),
            'limit' => $owners->perPage(),
            'data'  => OwnerPublicResource::collection($owners),
        ]);
    }

    public function ownerDetail(string $slug): JsonResponse
    {
        $owner = Owner::where('slug', $slug)
            ->where('status', 'active')
            ->withCount(['screens as screen_count' => fn($q) => $q->where('active', true)])
            ->first();

        if (! $owner) {
            return response()->json(['error' => 'Owner not found'], 404);
        }

        $owner->city_count = (int) DB::table('screens')
            ->join('sites', 'screens.site_id', '=', 'sites.id')
            ->where('screens.owner_id', $owner->id)
            ->where('screens.active', true)
            ->whereNotNull('sites.city')
            ->distinct('sites.city')
            ->count('sites.city');

        $owner->venue_types_list = DB::table('screens')
            ->join('screen_inventory', 'screens.id', '=', 'screen_inventory.screen_id')
            ->where('screens.owner_id', $owner->id)
            ->where('screens.active', true)
            ->whereNotNull('screen_inventory.venue_type')
            ->distinct()
            ->pluck('screen_inventory.venue_type')
            ->sort()
            ->values()
            ->toArray();

        return response()->json(
            (new OwnerPublicResource($owner))->withAbout()
        );
    }

    // ── Private helpers ──────────────────────────────────────

    /**
     * Xây dựng query chung cho cả index() và map().
     * Resolve eager-load relations based on ?include param.
     * Default: marketplace-only fields. With ?include=adops: full relations.
     */
    private function resolveRelations(Request $request): array
    {
        $includeAdops = str_contains($request->input('include', ''), 'adops');

        if ($includeAdops) {
            return ['spec', 'inventory.vnCategory', 'owner:id,name,slug', 'site'];
        }

        return [
            'spec:screen_id,photo_url,width_px,height_px,width_cm,height_cm',
            'inventory:screen_id,floor_cpm,floor_cpm_currency,venue_type,vn_category_id,operating_hours,spot_length',
            'inventory.vnCategory:id,slug,name,name_vi',
            'owner:id,name,slug',
            'site:id,address,city,region,lat,lon',
        ];
    }

    /**
     * Áp dụng tất cả filters nhưng không paginate/sort.
     */
    private function buildScreenQuery(Request $request)
    {
        return Screen::query()
            ->when($request->filled('status'), function ($q) use ($request) {
                $active = $request->input('status') === 'active';
                $q->where('active', $active);
            })
            ->when($request->filled('updated_after'), function ($q) use ($request) {
                $q->where('updated_at', '>', $request->input('updated_after'));
            })
            ->when(!empty($citySlugs = array_unique(array_merge(
                $this->resolveArrayParam($request, 'city'),
                $this->resolveArrayParam($request, 'province')
            ))),
                fn($q) => $q->whereHas('site', fn($sq) => $sq->whereIn('city', $this->expandCitySlugs($citySlugs)))
            )
            ->when(!empty($venueTypes = $this->resolveArrayParam($request, 'venue_type')),
                fn($q) => $q->whereHas('inventory', fn($iq) => $iq->whereIn('venue_type', $venueTypes))
            )
            ->when(!empty($screenTypes = $this->resolveArrayParam($request, 'screen_type')),
                fn($q) => $this->applyScreenTypeFilter($q, $screenTypes)
            )
            ->when(!empty($orientations = $this->resolveArrayParam($request, 'orientation')),
                fn($q) => $this->applyOrientationFilter($q, $orientations)
            )
            ->when($request->filled('q'), function ($q) use ($request) {
                $search = $request->input('q');
                $q->where(function ($sub) use ($search) {
                    $sub->where('name', 'LIKE', "%{$search}%")
                        ->orWhereHas('site', fn($sq) => $sq->where('address', 'LIKE', "%{$search}%")
                            ->orWhere('city', 'LIKE', "%{$search}%"));
                });
            })
            ->when(!empty($networks = $this->resolveArrayParam($request, 'network')),
                fn($q) => $q->whereHas('site.network', fn($nq) => $nq->whereIn('code', $networks))
            )
            ->when(!empty($ownerSlugs = $this->resolveArrayParam($request, 'owner')),
                fn($q) => $q->whereHas('owner', fn($oq) => $oq->whereIn('slug', $ownerSlugs))
            )
            ->when(!empty($districtCodes = $this->resolveArrayParam($request, 'district')),
                fn($q) => $q->whereIn('location_district_code', $districtCodes)
            )
            ->when(!empty($regionCodes = $this->resolveArrayParam($request, 'region')), function ($q) use ($regionCodes) {
                $validRegions = array_intersect($regionCodes, ['north', 'central', 'south']);
                if (empty($validRegions)) return;
                $regionConfig  = config('regions');
                $provinceCodes = collect($validRegions)
                    ->flatMap(fn($r) => $regionConfig[$r]['provinces'] ?? [])
                    ->unique()->values()->all();
                $cityNames = array_map(fn($p) => self::CITY_SLUG_MAP[$p] ?? $p, $provinceCodes);
                $q->whereHas('site', fn($sq) => $sq->whereIn('city', $cityNames));
            });
    }

    /**
     * Mapping: API city slug → tên tỉnh thực tế trong DB (sites.city được set từ VietnamProvince.name).
     * Hỗ trợ cả slug (API) lẫn tên tiếng Việt đầy đủ (legacy/fallback).
     */
    private const CITY_SLUG_MAP = [
        'hanoi'    => 'Hà Nội',
        'hcm'      => 'Hồ Chí Minh',
        'danang'   => 'Đà Nẵng',
        'haiphong' => 'Hải Phòng',
        'cantho'   => 'Cần Thơ',
    ];

    /**
     * Expand city slugs sang tất cả giá trị có thể có trong DB.
     * VD: 'hanoi' → ['Hà Nội', 'hanoi'] để tương thích cả 2 format.
     */
    private function expandCitySlugs(array $slugs): array
    {
        return collect($slugs)
            ->flatMap(fn($slug) => array_unique([
                self::CITY_SLUG_MAP[$slug] ?? $slug,  // tên tiếng Việt (DB thực tế)
                $slug,                                  // slug gốc (backward compat)
            ]))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Hỗ trợ 3 format: single value, pipe-separated (legacy), PHP array (param[]=val).
     */
    private function resolveArrayParam(Request $request, string $key): array
    {
        $value = $request->input($key);

        if (is_array($value)) {
            return array_values(array_filter($value));
        }

        if (is_string($value) && str_contains($value, '|')) {
            return explode('|', $value);
        }

        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return [];
    }

    /**
     * Filter screen_type (derived field — không có DB column).
     * billboard: width_cm >= 300 OR height_cm >= 300
     * led:       venue_type = outdoor AND không phải billboard
     * lcd:       không outdoor AND không billboard
     */
    private function applyScreenTypeFilter($query, array $types)
    {
        return $query->where(function ($or) use ($types) {
            foreach ($types as $type) {
                $or->orWhere(function ($sub) use ($type) {
                    if ($type === 'billboard') {
                        $sub->whereHas('spec', fn($sq) =>
                            $sq->where('width_cm', '>=', 300)->orWhere('height_cm', '>=', 300)
                        );
                    } elseif ($type === 'led') {
                        $sub->whereHas('inventory', fn($iq) => $iq->where('venue_type', 'outdoor'))
                            ->whereHas('spec', fn($sq) =>
                                $sq->where('width_cm', '<', 300)->where('height_cm', '<', 300)
                            );
                    } else { // lcd
                        $sub->whereHas('inventory', fn($iq) => $iq->where('venue_type', '!=', 'outdoor'))
                            ->whereHas('spec', fn($sq) =>
                                $sq->where('width_cm', '<', 300)->where('height_cm', '<', 300)
                            );
                    }
                });
            }
        });
    }

    /**
     * Filter orientation bằng WHERE trực tiếp trên screen_specs (không dùng computed attribute).
     * landscape: width_px > height_px
     * portrait:  width_px < height_px
     * square:    width_px = height_px
     */
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
     * Áp dụng sort. Dùng subquery để tránh column ambiguity với eager loading.
     */
    /**
     * Khoá sắp xếp chính, **không** kèm khoá phụ: `phanTrang()` thêm
     * `screens.id` sau đó cho cả ba nhánh. Nhánh mặc định vì thế không còn
     * `orderBy('screens.id')` của riêng nó — SQL sinh ra y hệt như trước.
     */
    private function applySort($query, Request $request)
    {
        $sort = $request->input('sort');

        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $query->leftJoin('screen_inventory as sort_inv', 'screens.id', '=', 'sort_inv.screen_id')
                  ->orderBy('sort_inv.floor_cpm', $sort === 'price_asc' ? 'asc' : 'desc')
                  ->select('screens.*');

            return $query;
        }

        return match ($sort) {
            'newest' => $query->orderBy('screens.updated_at', 'desc'),
            default  => $query,
        };
    }
}
