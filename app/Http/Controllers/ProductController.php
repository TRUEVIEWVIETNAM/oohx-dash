<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductListingRequest;
use App\Services\ProductService;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function __construct(private ProductService $productService) {}

    /**
     * GET /products — product listing (new explore)
     *
     * Dùng `ProductListingRequest` thay cho `Request` trần: trang này từng
     * nhận `?q[]=x` rồi nối thẳng vào `"%{$q}%"` trong service, cho "Array to
     * string conversion" và một trang công khai trả 500. Đúng lỗi Codex R40
     * nêu ở `/api/v2/owners`, chỉ khác chỗ.
     */
    public function index(ProductListingRequest $request): View
    {
        $products = $this->productService->getProductsPaginated($request);
        $filters = $this->productService->getFilterAggregates();

        return view('frontpage.products.index', [
            'products' => $products,
            'filters'  => $filters,
        ]);
    }

    /**
     * GET /products/{slug} — product detail
     */
    public function show(string $slug): View
    {
        $product = $this->productService->getProductBySlug($slug);
        abort_unless($product, 404);

        $similar = $this->productService->getSimilarProducts($product);

        return view('frontpage.products.show', [
            'product' => $product,
            'similar' => $similar,
        ]);
    }
}
