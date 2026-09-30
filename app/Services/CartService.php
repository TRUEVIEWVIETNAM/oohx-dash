<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Screen;
use App\Models\User;
use App\Services\Pricing\BillablePeriodCalculator;
use App\Services\Pricing\DurationDiscount;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CartService
{
    public function __construct(
        private readonly PurchaseEligibilityService $eligibility = new PurchaseEligibilityService(),
        private readonly BillablePeriodCalculator $periods = new BillablePeriodCalculator(),
        private readonly InventoryHoldService $holds = new InventoryHoldService(),
        private readonly DurationDiscount $discounts = new DurationDiscount(),
    ) {
    }

    /**
     * Get or create active cart for user.
     */
    public function getOrCreateCart(User $user): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $user->id, 'status' => 'active'],
            [
                'organization_id' => $user->current_organization_id,
                'name' => 'My Plan',
            ]
        );
    }

    /**
     * Add product to cart. Supports 3 listing modes:
     * - package_only: mua cả gói (no screen selection)
     * - individual_only: chọn từng screen
     * - both: chọn gói hoặc chọn lẻ
     */
    public function addProduct(Cart $cart, string $productId, array $data = []): CartItem
    {
        // Qua cổng bán hàng, không findOrFail trần: sản phẩm bị gỡ/owner tạm ngưng
        // vẫn có thể bị đặt nếu biết ID (audit F10).
        $product = $this->eligibility->findPurchasableProduct($productId);

        $selectedScreenIds = $data['selected_screen_ids'] ?? null;
        $buyMode = $data['buy_mode'] ?? ($product->listing_mode === 'package_only' ? 'package' : 'individual');
        $startDate = $data['start_date'] ?? now()->addDays(7)->toDateString();
        $endDate = $data['end_date'] ?? now()->addDays(37)->toDateString();
        $sovPct = $data['share_of_voice_pct'] ?? 100;

        // Cổng bán hàng chốt tập màn hình: kiểm thuộc sản phẩm, kiểm từng màn hình
        // còn bán được, kiểm min/max. Không tin danh sách client gửi.
        $screens = $this->eligibility->resolveProductScreens(
            $product,
            $buyMode,
            is_array($selectedScreenIds) ? $selectedScreenIds : null
        );

        // Giá sản phẩm đi qua BundleExpander — nguồn duy nhất cho cả thêm giỏ,
        // sửa giỏ và chốt đơn. Xem chú thích ở `productTotal`.
        $cost = app(BundleExpander::class)->productTotal($product, $buyMode, $screens);

        if ($buyMode === 'package') {
            $quantity = 1;
            $impressions = 0;
            $selectedScreenIds = null;
        } else {
            $quantity = $screens->count();
            $impressions = (int) $screens->sum(fn ($s) => $s->inventory?->weekly_impressions ?? 0);
            // Ghi lại đúng tập đã được kiểm, không phải mảng thô từ request.
            $selectedScreenIds = $screens->pluck('id')->all();
        }

        // Determine pricing model from first screen's inventory
        $firstScreen = $screens->first();
        $inv = $firstScreen?->inventory;
        $pricingModel = $inv?->pricing_model ?? 'io';
        if ($pricingModel === 'both') {
            $pricingModel = 'io'; // Products default to I/O
        }

        return DB::transaction(function () use ($cart, $productId, $firstScreen, $screens, $buyMode, $startDate, $endDate, $data, $quantity, $selectedScreenIds, $sovPct, $impressions, $cost, $pricingModel, $inv) {
            // Khóa mọi màn hình của gói TRƯỚC khi chèn, theo thứ tự id — xem
            // chú thích ở `addItem` và `InventoryHoldService::lockScreen`.
            foreach ($screens->sortBy('id') as $screenToLock) {
                $this->holds->lockScreen($screenToLock->id);
            }

            $item = CartItem::updateOrCreate(
            ['cart_id' => $cart->id, 'product_id' => $productId],
            [
                'screen_id' => $firstScreen?->id,
                // Ghi thẳng kiểu mua: lúc chốt đơn phải mở gói ra thành nhiều
                // dòng, và việc đó cần biết chắc khách mua cả gói hay mua lẻ,
                // không suy đoán từ danh sách màn hình đã chọn.
                'buy_mode' => $buyMode,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'spot_length' => $data['spot_length'] ?? 15,
                'quantity' => $quantity,
                'selected_screen_ids' => $selectedScreenIds,
                'selected_region' => $data['selected_region'] ?? null,
                'share_of_voice_pct' => $sovPct,
                'estimated_impressions' => $impressions,
                'estimated_cost' => $cost,
                'notes' => $data['notes'] ?? null,
                // Pricing model fields
                'pricing_model' => $pricingModel,
                'unit_price' => $cost, // product price = total cost
                'screen_count' => $quantity,
                'duration_units' => 1,
                'duration_unit' => $inv?->io_rate_unit ?? 'month',
            ]
            );

            // Nhả hết giữ chỗ cũ trước: khách có thể vừa đổi tập màn hình đã
            // chọn, và những màn hình bị bỏ ra phải trả suất về kho ngay.
            $this->holds->releaseForCartItem($item->fresh());

            // Một gói chiếm suất trên TẤT CẢ màn hình của nó, không chỉ màn hình
            // đầu tiên. Thiếu vòng lặp này thì các màn hình còn lại vẫn báo
            // trống và bán tiếp cho người khác.
            //
            // Sắp theo id trước khi giành: mỗi lần giành là một lần khóa hàng
            // màn hình, và hai gói có màn hình chung nhưng thứ tự khác nhau sẽ
            // khóa chéo rồi deadlock. Thứ tự id là thứ tự chung của toàn hệ thống.
            foreach ($screens->sortBy('id') as $screen) {
                $this->holds->acquireForCartItem($item->fresh(), $screen, (int) $sovPct);
            }

            return $item->fresh();
        });
    }

    /**
     * Add single screen to cart (direct screen booking).
     *
     * Supports 2 pricing models:
     * - CPM:  buyer specifies booked_cpms → cost = floor_cpm × booked_cpms
     * - I/O:  buyer specifies duration_units + screen_count → cost = io_rate × screen_count × duration_units
     */
    public function addItem(Cart $cart, string $screenId, array $data = []): CartItem
    {
        $screen = $this->eligibility->findPurchasableScreen($screenId);
        $inv = $screen->inventory;
        $invModel = $inv?->pricing_model ?? 'io';

        // When screen supports 'both', buyer chooses. Otherwise use screen's model.
        $buyerChoice = $data['pricing_model'] ?? null;
        if ($invModel === 'both' && in_array($buyerChoice, ['cpm', 'io'])) {
            $pricingModel = $buyerChoice;
        } elseif ($invModel === 'both') {
            $pricingModel = 'io'; // default to I/O
        } else {
            $pricingModel = $invModel;
        }
        $data['_resolved_pricing_model'] = $pricingModel;

        $startDate = $data['start_date'] ?? now()->addDays(7)->toDateString();
        $endDate = $data['end_date'] ?? now()->addDays(37)->toDateString();
        $spotLength = $data['spot_length'] ?? $inv?->spot_length ?? 15;
        $sovPct = $data['share_of_voice_pct'] ?? 100;

        $estimated = $this->estimateCost($screen, $data);

        // Trong transaction: nếu suất đã hết thì dòng giỏ không được nằm lại.
        // Không có transaction thì updateOrCreate đã ghi xong trước khi giữ chỗ
        // báo 422, và khách thấy một dòng giỏ không có suất nào đứng sau.
        return DB::transaction(function () use ($cart, $screenId, $screen, $data, $estimated, $startDate, $endDate, $spotLength, $sovPct, $pricingModel, $inv) {
            // Khóa màn hình TRƯỚC khi chèn dòng giỏ.
            //
            // `cart_items` có khóa ngoại tới `screens`, nên chèn trước là nhận
            // shared lock trên hàng màn hình; xin exclusive sau đó là nâng cấp
            // khóa, và hai người cùng thêm một màn hình vào giỏ sẽ khóa chéo
            // nhau (Codex R05). Thứ tự này là thứ duy nhất tránh được.
            $this->holds->lockScreen($screenId);

            $item = CartItem::updateOrCreate(
            ['cart_id' => $cart->id, 'screen_id' => $screenId],
            [
                'product_id' => $data['product_id'] ?? null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'spot_length' => $spotLength,
                'quantity' => (int) ($data['quantity'] ?? 1),
                'selected_screen_ids' => $data['selected_screen_ids'] ?? null,
                'selected_region' => $data['selected_region'] ?? null,
                'share_of_voice_pct' => $sovPct,
                'estimated_impressions' => $estimated['impressions'],
                'estimated_cost' => $estimated['cost'],
                'notes' => $data['notes'] ?? null,
                // Pricing model — buyer's actual choice (cpm or io, never 'both')
                'pricing_model' => $pricingModel,
                'booked_cpms' => $estimated['booked_cpms'] ?? null,
                'screen_count' => $estimated['screen_count'] ?? 1,
                'duration_units' => $estimated['duration_units'] ?? 1,
                'duration_unit' => $estimated['duration_unit'] ?? ($inv?->io_rate_unit ?? 'month'),
                'unit_price' => $estimated['unit_price'] ?? 0,
                'duration_discount_pct' => $estimated['duration_discount_pct'] ?? 0,
                'rate_captured_at' => now(),
                'rate_snapshot' => $this->rateSnapshot($inv),
            ]
            );

            // Giành suất ngay khi bỏ vào giỏ. Đây là chỗ duy nhất quyết định
            // "suất này của ai" — trước đây không có chỗ nào cả.
            $this->holds->acquireForCartItem($item->fresh(), $screen, (int) $sovPct);

            return $item->fresh();
        });
    }

    /**
     * Tập màn hình mà một dòng giỏ đang chiếm suất.
     *
     * Dòng mua lẻ: đúng một màn hình. Dòng thuộc gói: **toàn bộ** màn hình của
     * gói, đọc lại từ sản phẩm chứ không tin danh sách đã lưu.
     *
     * @return \Illuminate\Support\Collection<int, Screen>
     */
    private function screensToHold(CartItem $item, ?Screen $fallback): \Illuminate\Support\Collection
    {
        if (! $item->product_id) {
            return collect(array_filter([$fallback]));
        }

        $expander = app(\App\Services\Booking\BundleExpander::class);
        $product  = $this->eligibility->findPurchasableProduct($item->product_id);

        // Sắp theo id: nhiều hàng bị khóa trong cùng một transaction thì thứ
        // tự khóa phải cố định, nếu không hai đơn có màn hình chung sẽ khóa
        // chéo nhau.
        return $expander->resolveScreens($item, $product)->sortBy('id')->values();
    }

    /**
     * Ảnh chụp giá của kho tại thời điểm tính. Dùng để phát hiện media owner đổi
     * giá trong lúc giỏ hàng còn nằm đó.
     */
    public function rateSnapshot(?\App\Models\ScreenInventory $inv): array
    {
        return [
            'pricing_model' => $inv?->pricing_model,
            'floor_cpm'     => $inv?->floor_cpm !== null ? (string) $inv->floor_cpm : null,
            'io_rate'       => $inv?->io_rate !== null ? (string) $inv->io_rate : null,
            'io_rate_unit'  => $inv?->io_rate_unit,
            // Bậc chiết khấu cũng là giá: owner sửa bậc trong lúc giỏ còn nằm đó
            // thì tiền đổi, nên nó phải nằm trong ảnh chụp để bước chốt đơn phát
            // hiện được.
            'duration_discounts' => $inv?->duration_discounts,
        ];
    }

    /**
     * Remove item from cart.
     */
    public function removeItem(CartItem $item): void
    {
        DB::transaction(function () use ($item) {
            // Nhả suất về kho ngay, không chờ hết hạn. Khóa ngoại có cascade nên
            // giữ chỗ cũng biến mất theo, nhưng ghi 'released' tường minh để lịch
            // sử đọc được: suất này đã được nhả vì khách bỏ khỏi giỏ.
            $this->holds->releaseForCartItem($item);
            $item->delete();
        });
    }

    /**
     * Update cart item and recalculate cost.
     */
    public function updateItem(CartItem $item, array $data): CartItem
    {
        $screen = $item->screen()->with('inventory')->first();

        // Sửa dòng giỏ cũng phải qua cổng: màn hình có thể đã bị gỡ bán kể từ lúc
        // thêm vào (audit F10 / Codex R05).
        if ($screen) {
            $this->eligibility->assertScreenPurchasable($screen);
        }

        // Preserve pricing_model from item (already resolved on addItem)
        $pricingModel = $item->pricing_model ?? 'io';
        // KHÔNG mang theo booked_cpms / duration_units / screen_count cũ.
        // Trước đây đổi ngày dài thêm vẫn giữ số kỳ cũ, nên tiền không đổi trong khi
        // chỗ giữ vẫn kéo dài (audit F-01). Ngày đổi thì giá phải tính lại từ ngày.
        $mergedData = array_merge([
            '_resolved_pricing_model' => $pricingModel,
            'start_date' => $item->start_date->toDateString(),
            'end_date' => $item->end_date->toDateString(),
            'share_of_voice_pct' => $item->share_of_voice_pct,
        ], array_filter($data, fn ($v) => $v !== null));

        $estimated = $this->estimateCost($screen, $mergedData);

        // Dòng giỏ thuộc SẢN PHẨM thì tiền theo giá sản phẩm, không theo giá
        // kho của màn hình đầu tiên.
        //
        // Trước đây `updateItem` ghi đè `estimated_cost` bằng giá kho ngay cả
        // với dòng sản phẩm, nên sửa một dòng giỏ dạng sản phẩm là làm lệch
        // con số mà guard lúc chốt đơn đang canh — và chính guard tôi thêm ở
        // R06 chặn đường mua hàng bình thường (Codex R28).
        if ($item->product_id) {
            $expander = app(BundleExpander::class);
            $product  = $this->eligibility->findPurchasableProduct($item->product_id);
            $buyMode  = $expander->buyModeOf($item);
            $screens  = $expander->resolveScreens($item, $product);

            $estimated['cost']         = $expander->productTotal($product, $buyMode, $screens);
            $estimated['unit_price']   = $estimated['cost'];
            $estimated['screen_count'] = $buyMode === 'package' ? 1 : $screens->count();
            $estimated['impressions']  = $buyMode === 'package'
                ? 0
                : (int) $screens->sum(fn ($s) => $s->inventory?->weekly_impressions ?? 0);
        }

        // Sửa dòng giỏ và đổi suất đang giữ phải cùng thành hoặc cùng không:
        // nếu khoảng ngày mới đã có người lấy, dòng giỏ không được đổi theo.
        return DB::transaction(function () use ($item, $mergedData, $data, $estimated, $pricingModel, $screen) {
            $item->update([
            'start_date' => $mergedData['start_date'],
            'end_date' => $mergedData['end_date'],
            'spot_length' => $data['spot_length'] ?? $item->spot_length,
            'share_of_voice_pct' => $mergedData['share_of_voice_pct'],
            'estimated_impressions' => $estimated['impressions'],
            'estimated_cost' => $estimated['cost'],
            'pricing_model' => $pricingModel,
            'booked_cpms' => $estimated['booked_cpms'] ?? $item->booked_cpms,
            'screen_count' => $estimated['screen_count'] ?? $item->screen_count,
            'duration_units' => $estimated['duration_units'] ?? $item->duration_units,
            'duration_unit' => $estimated['duration_unit'] ?? $item->duration_unit,
            'unit_price' => $estimated['unit_price'] ?? $item->unit_price,
            'duration_discount_pct' => $estimated['duration_discount_pct'] ?? 0,
            'notes' => $data['notes'] ?? $item->notes,
            'rate_captured_at' => now(),
            'rate_snapshot' => $this->rateSnapshot($screen?->inventory),
            ]);

            // Ngày hoặc SOV đổi thì suất đang giữ cũng phải đổi theo. Giành lại
            // ở khoảng ngày mới: khoảng mới đã có người lấy thì 422 ở đây và
            // toàn bộ thay đổi phía trên bị hủy.
            //
            // Với dòng giỏ thuộc GÓI thì phải giành lại cho **mọi** màn hình
            // của gói, không chỉ màn hình đầu. Trước đây chỉ đổi hold của
            // `item->screen_id`, nên các màn hình còn lại giữ nguyên ngày cũ:
            // khách sửa ngày sang tháng sau vẫn "giữ" tháng trước, rồi lúc
            // chốt đơn nhánh chuyển hold chỉ đòi hai khoảng GIAO NHAU nên nó
            // nhận luôn hold cũ và bỏ qua phép kiểm sức chứa (Codex R03).
            foreach ($this->screensToHold($item->fresh(), $screen) as $target) {
                $this->holds->acquireForCartItem($item->fresh(), $target);
            }

            return $item->fresh();
        });
    }

    /**
     * Estimate cost for a screen booking.
     *
     * CPM model:  cost = floor_cpm × booked_cpms
     * I/O model:  cost = io_rate × screen_count × duration_units
     */
    public function estimateCost(Screen $screen, array $data = []): array
    {
        $inv = $screen->inventory;
        // Use resolved model from addItem(), or determine from inventory
        $pricingModel = $data['_resolved_pricing_model'] ?? $inv?->pricing_model ?? 'io';
        // If screen allows 'both' but no explicit choice, default to 'io'
        if ($pricingModel === 'both') {
            $pricingModel = 'io';
        }

        $startDate = $data['start_date'] ?? now()->addDays(7)->toDateString();
        $endDate = $data['end_date'] ?? now()->addDays(37)->toDateString();
        $sovPct = (int) ($data['share_of_voice_pct'] ?? 100);

        $start = now()->parse($startDate);
        $end = now()->parse($endDate);
        $days = max(1, $start->diffInDays($end) + 1);

        $dailyImpressions = $inv?->daily_impressions ?? 0;
        $totalImpressions = (int) round($dailyImpressions * $days * ($sovPct / 100));

        if ($pricingModel === 'cpm') {
            // ── CPM: buyer mua số CPM, cost = đơn giá CPM × số CPM ──
            $floorCpm = (float) ($inv?->floor_cpm ?? 0);

            // Số CPM tối thiểu do MÁY CHỦ suy từ khoảng ngày và SOV.
            // Client được phép mua THÊM, không được mua ít hơn mức đó.
            $minimumCpms = $this->periods->minimumCpms($totalImpressions);
            $bookedCpms  = $minimumCpms;

            if (isset($data['booked_cpms'])) {
                $requested = (int) $data['booked_cpms'];

                // Không tự nâng lên rồi tính tiền: báo lỗi để người mua thấy con số
                // thật và xác nhận lại (Codex R03).
                if ($requested < $minimumCpms) {
                    throw new HttpException(422, sprintf(
                        'Khoảng ngày và tỷ lệ thời lượng đã chọn tương ứng tối thiểu %s CPM, không thể đặt %s CPM.',
                        number_format($minimumCpms),
                        number_format($requested)
                    ));
                }

                $bookedCpms = $requested;
            }

            $cost = round($floorCpm * $bookedCpms, 2);

            return [
                'impressions' => $totalImpressions,
                'cost' => $cost,
                'days' => $days,
                'unit_price' => $floorCpm,
                'booked_cpms' => $bookedCpms,
                'screen_count' => 1,
                'duration_units' => null,
                'duration_unit' => null,
            ];
        }

        // ── I/O: cost = io_rate × screen_count × duration_units ──
        $ioRate   = (float) ($inv?->io_rate ?? 0);
        $rateUnit = $inv?->io_rate_unit ?? 'month';

        // screen_count KHÔNG nhận từ client. Một dòng giỏ ứng với một màn hình;
        // màn hình là cụm nhiều thiết bị thì lấy từ cấu hình kho, không phải từ request.
        $screenCount = max(1, (int) ($inv?->effective_screen_count ?? 1));

        // Số kỳ do MÁY CHỦ suy từ ngày. Client gửi ít hơn thì báo lỗi, không âm thầm sửa.
        $derivedUnits  = $this->periods->ioUnits($start, $end, $rateUnit);
        $durationUnits = $derivedUnits;

        if (isset($data['duration_units'])) {
            $requested = (int) $data['duration_units'];

            if ($requested < $derivedUnits) {
                throw new HttpException(422, sprintf(
                    'Khoảng ngày %s – %s tương ứng %d kỳ, không thể đặt %d kỳ.',
                    $start->format('d/m/Y'),
                    $end->format('d/m/Y'),
                    $derivedUnits,
                    $requested
                ));
            }

            $durationUnits = $requested;
        }

        // Chiết khấu theo số kỳ: thuê dài được giảm, và mức giảm nằm trong kho
        // chứ không nằm trong tin nhắn của người bán. Trước đây 12 kỳ đúng bằng
        // 12 lần một kỳ, nên mọi thỏa thuận giảm giá đều ở ngoài hệ thống.
        $discountPct = $this->discounts->pctFor($inv?->duration_discounts, $durationUnits);
        $cost = $this->discounts->apply($ioRate * $screenCount * $durationUnits, $discountPct);

        return [
            'impressions' => $totalImpressions,
            'cost' => $cost,
            'days' => $days,
            'unit_price' => $ioRate,
            'booked_cpms' => null,
            'screen_count' => $screenCount,
            'duration_units' => $durationUnits,
            'duration_unit' => $rateUnit,
            'duration_discount_pct' => $discountPct,
        ];
    }

    /**
     * Get cart item count for user (for badge).
     */
    public function getItemCount(User $user): int
    {
        $cart = Cart::where('user_id', $user->id)->where('status', 'active')->first();
        return $cart ? $cart->items()->count() : 0;
    }
}
