<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddToCartRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Models\CartItem;
use App\Services\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(private CartService $cartService) {}

    /**
     * GET /cart — cart page
     */
    public function index(Request $request): View
    {
        $cart = $this->cartService->getOrCreateCart($request->user());
        $items = $cart->items()
            ->with(['screen.spec', 'screen.inventory', 'screen.owner', 'screen.site.network', 'screen.site.province', 'screen.site.commune'])
            ->get();

        return view('buyer.cart', [
            'cart' => $cart,
            'items' => $items,
        ]);
    }

    /**
     * POST /cart/add — add screen or product to cart (AJAX or redirect)
     */
    public function add(AddToCartRequest $request): JsonResponse|RedirectResponse
    {
        // Luật kiểm chuyển sang `AddToCartRequest`, dùng chung với
        // `/api/v2/cart/items`. Giữ hai bộ luật cho cùng một thao tác là cách
        // để một ngày nào đó API nhận thứ mà trang từ chối, và không ai biết
        // bên nào đúng.
        $cart = $this->cartService->getOrCreateCart($request->user());
        $data = $request->validated();

        $item = $request->buysProduct()
            ? $this->cartService->addProduct($cart, $data['product_id'], $data)
            : $this->cartService->addItem($cart, $data['screen_id'], $data);

        $count = $cart->items()->count();

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'item_id' => $item->id,
                'count' => $count,
                'message' => 'Đã thêm vào plan',
            ]);
        }

        return redirect()->route('buyer.cart')->with('success', 'Đã thêm vào plan');
    }

    /**
     * PUT /cart/{item} — update cart item
     */
    public function update(UpdateCartItemRequest $request, CartItem $item): JsonResponse|RedirectResponse
    {
        // Qua policy thay cho phép so `user_id` bằng tay — cùng luật, một chỗ.
        abort_unless($request->user()?->can('update', $item) ?? false, 403);

        $item = $this->cartService->updateItem($item, $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'estimated_cost' => $item->estimated_cost,
                'estimated_impressions' => $item->estimated_impressions,
            ]);
        }

        return redirect()->route('buyer.cart');
    }

    /**
     * DELETE /cart/{item} — remove from cart
     */
    public function remove(Request $request, CartItem $item): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()?->can('delete', $item) ?? false, 403);

        $this->cartService->removeItem($item);

        if ($request->wantsJson()) {
            $count = $item->cart->items()->count();
            return response()->json(['success' => true, 'count' => $count]);
        }

        return redirect()->route('buyer.cart');
    }

    /**
     * GET /cart/count — AJAX badge count
     */
    public function count(Request $request): JsonResponse
    {
        return response()->json([
            'count' => $this->cartService->getItemCount($request->user()),
        ]);
    }
}
