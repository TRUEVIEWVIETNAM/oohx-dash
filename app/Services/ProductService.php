<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ProductService
{
    /**
     * Get paginated products for /explore with filters.
     */
    public function getProductsPaginated(Request $request, int $perPage = 20): LengthAwarePaginator
    {
        $query = Product::query()
            ->publiclyVisible()
            ->with(['owner:id,name,slug', 'network:id,name', 'site:id,name,city'])
            ->withCount('screens');

        // Filter: category
        if ($categories = $request->input('category', [])) {
            if (is_string($categories)) $categories = [$categories];
            $query->whereIn('category', $categories);
        }

        // Filter: type
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        // Filter: city
        if ($cities = $request->input('city', [])) {
            if (is_string($cities)) $cities = [$cities];
            $query->where(function ($q) use ($cities) {
                foreach ($cities as $city) {
                    $q->orWhere('city', 'like', '%' . $city . '%');
                }
            });
        }

        // Filter: owner
        if ($ownerId = $request->input('owner')) {
            $query->where('owner_id', $ownerId);
        }

        // Filter: price range
        if ($minPrice = $request->input('min_price')) {
            $query->where('floor_price', '>=', $minPrice);
        }
        if ($maxPrice = $request->input('max_price')) {
            $query->where('floor_price', '<=', $maxPrice);
        }

        // Search
        if ($q = $request->input('q')) {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('city', 'like', "%{$q}%")
                    ->orWhereHas('owner', fn ($oq) => $oq->where('name', 'like', "%{$q}%"));
            });
        }

        // Sort
        $sort = $request->input('sort', '');
        match ($sort) {
            'price_asc'  => $query->orderBy('floor_price', 'asc'),
            'price_desc' => $query->orderBy('floor_price', 'desc'),
            'newest'     => $query->orderByDesc('created_at'),
            default      => $query->orderByDesc('featured')->orderBy('sort_order')->orderByDesc('created_at'),
        };

        return $query->paginate($perPage);
    }

    /**
     * Get product detail by slug.
     */
    public function getProductBySlug(string $slug): ?Product
    {
        return Product::where('slug', $slug)
            ->publiclyVisible()
            ->with([
                'owner:id,name,slug,logo_url',
                'network:id,name',
                'site:id,name,city,address,lat,lon',
                'screens.spec',
                'screens.inventory',
                // `network_id` phải có trong select, nếu không quan hệ
                // `site->network` luôn trả null và DTO màn hình mất `network` —
                // đúng lớp lỗi Codex R38 đã bắt ở `getScreensPaginated()`.
                'screens.site:id,network_id,name,city,address',
                'screens.site.network:id,name,code,banner',
            ])
            ->first();
    }

    /**
     * Get similar products.
     */
    public function getSimilarProducts(Product $product, int $limit = 4): Collection
    {
        return Product::query()
            ->publiclyVisible()
            ->where('id', '!=', $product->id)
            ->where(function ($q) use ($product) {
                $q->where('category', $product->category)
                    ->orWhere('city', $product->city);
            })
            ->with(['owner:id,name,slug'])
            ->withCount('screens')
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }

    /**
     * Get featured products for homepage.
     */
    public function getFeaturedProducts(int $limit = 6): Collection
    {
        return Cache::remember('fp:featured_products', 900, function () use ($limit) {
            return Product::query()
                ->publiclyVisible()
                ->featured()
                ->with(['owner:id,name,slug', 'network:id,name'])
                ->withCount('screens')
                ->orderBy('sort_order')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Get filter aggregates for sidebar.
     */
    public function getFilterAggregates(): array
    {
        return Cache::remember('fp:product_filters', 1800, function () {
            $categories = Product::publiclyVisible()
                ->selectRaw('category, count(*) as count')
                ->groupBy('category')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($r) => [
                    'slug'  => $r->category,
                    'label' => Product::CATEGORIES[$r->category] ?? $r->category,
                    'count' => (int) $r->count,
                ]);

            $types = Product::publiclyVisible()
                ->selectRaw('type, count(*) as count')
                ->groupBy('type')
                ->get()
                ->map(fn ($r) => [
                    'slug'  => $r->type,
                    'label' => match ($r->type) { 'single'=>'Đơn lẻ','package'=>'Gói','custom'=>'Tùy chỉnh',default=>$r->type },
                    'count' => (int) $r->count,
                ]);

            $cities = Product::publiclyVisible()
                ->whereNotNull('city')
                ->selectRaw('city, count(*) as count')
                ->groupBy('city')
                ->orderByDesc('count')
                ->limit(20)
                ->get()
                ->map(fn ($r) => [
                    'name'  => $r->city,
                    'count' => (int) $r->count,
                ]);

            return [
                'categories' => $categories,
                'types'      => $types,
                'cities'     => $cities,
            ];
        });
    }
}
