<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddToCartRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Http\Resources\V2\CartItemResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\CartService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Giỏ hàng của người mua — nhóm **cần quyền** của `/api/v2`.
 *
 * Xác thực: Sanctum dạng SPA (cookie phiên), đúng như CLAUDE.md mục 2 nói cho
 * người dùng trình duyệt. Nhóm này **không** dùng token đối tác.
 *
 * Ba nguyên tắc giữ nguyên từ nhóm đọc, và một điều mới:
 *
 * - DTO danh sách trắng; `rate_snapshot` không ra ngoài.
 * - **Số tiền do máy chủ tính.** Không endpoint nào ở đây nhận `estimated_cost`
 *   hay bất kỳ số tiền nào từ client; `CartService` tính lại từ cấu hình kho
 *   sau mỗi lần sửa.
 * - Luật kiểm dùng chung với trang Blade qua `App\Http\Requests\Cart\*`, nên
 *   API không thể nhận thứ mà trang từ chối.
 * - Mới: mỗi thao tác lên một dòng giỏ đều gọi `CartItemPolicy`, thay cho phép
 *   so `user_id` bằng tay mà controller Blade đang dùng.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
        private readonly PaymentService $payments,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->carts->getOrCreateCart($request->user());

        return $this->cartPayload($cart);
    }

    public function store(AddToCartRequest $request): JsonResponse
    {
        $cart = $this->carts->getOrCreateCart($request->user());

        $data = $request->validated();

        $item = $request->buysProduct()
            ? $this->carts->addProduct($cart, $data['product_id'], $data)
            : $this->carts->addItem($cart, $data['screen_id'], $data);

        return $this->cartPayload($cart->fresh(), ['added_item_id' => $item->id], 201);
    }

    public function update(UpdateCartItemRequest $request, CartItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item, 'update');

        $this->carts->updateItem($item, $request->validated());

        return $this->cartPayload($item->cart->fresh());
    }

    public function destroy(Request $request, CartItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item, 'delete');

        $cart = $item->cart;
        $this->carts->removeItem($item);

        return $this->cartPayload($cart->fresh());
    }

    /**
     * 404 chứ không 403 cho dòng giỏ của người khác.
     *
     * Trả 403 là xác nhận "dòng này có tồn tại, chỉ là không phải của bạn" —
     * với khóa tuần tự thì đó là một cách đếm đơn hàng của người khác. Giỏ là
     * dữ liệu riêng của từng người nên sự tồn tại của nó cũng là riêng.
     */
    private function authorizeItem(Request $request, CartItem $item, string $ability): void
    {
        abort_unless($request->user()?->can($ability, $item) ?? false, 404, 'Không tìm thấy dòng giỏ hàng này.');
    }

    private function cartPayload(Cart $cart, array $extra = [], int $status = 200): JsonResponse
    {
        $items = $cart->items()
            ->with([
                'screen.spec',
                'screen.inventory',
                'screen.owner:id,name,slug,cover_url',
                'screen.site:id,network_id,name,city,address,banner',
                'screen.site.network:id,name,code,banner',
                'product:id,slug,name',
            ])
            ->get();

        // `subtotal` chốt về VND nguyên TRƯỚC khi tính VAT, và `vat`/`total`
        // lấy từ `PaymentService::withVat()` chứ không nhân tay ở đây.
        //
        // ══ Vì sao bản trước cố tình KHÔNG trả VAT, và vì sao giờ trả ══
        //
        // Lý do cũ: "cộng VAT ở đây là một phép làm tròn thứ hai". Lý do đúng,
        // nhưng nó nhắm vào việc TÍNH lại công thức ở chỗ thứ hai — không phải
        // việc phơi kết quả của chỗ duy nhất được phép tính.
        //
        // Và chỗ làm tròn thứ hai đó đã tồn tại sẵn: `buyer/cart.blade.php`
        // tự làm `sum * (1 + config('pricing.vat_rate'))` để in dòng VAT và
        // dòng Tổng cộng. Nó còn làm trên tổng dạng float, trong khi `withVat`
        // nhận int — nên hai đường có thể lệch nhau 1₫, và lệch ở đúng con số
        // người mua đọc.
        //
        // Phơi `withVat()` ra đây KHÔNG thêm chỗ làm tròn nào. Nó bỏ chỗ đang
        // có, vì view hết lý do tự nhân.
        $subtotal = (int) round((float) $items->sum('estimated_cost'));
        $total    = $this->payments->withVat($subtotal);

        return response()->json([
            'data' => [
                'items' => CartItemResource::collection($items)->resolve(),
                'summary' => [
                    'currency'    => 'VND',
                    'item_count'  => $items->count(),
                    'subtotal'    => $subtotal,

                    // `vat` là hiệu, không phải một phép nhân riêng. Tính
                    // `round($subtotal * $rate)` ở đây là mở lại đúng chỗ làm
                    // tròn thứ hai vừa đóng: khi ấy `subtotal + vat` có thể
                    // không bằng `total`, và dòng cộng trên trang giỏ sẽ không
                    // khớp với tổng của chính nó.
                    'vat'         => $total - $subtotal,
                    'total'       => $total,

                    'impressions' => (int) $items->sum('estimated_impressions'),
                ],
            ] + $extra,
        ], $status);
    }
}
