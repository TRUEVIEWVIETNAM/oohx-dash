<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Screen;
use App\Models\User;
use App\Services\Pricing\BillablePeriodCalculator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CartService
{
    public function __construct(
        private readonly PurchaseEligibilityService $eligibility = new PurchaseEligibilityService(),
        private readonly BillablePeriodCalculator $periods = new BillablePeriodCalculator(),
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

        if ($buyMode === 'package') {
            $quantity = 1;
            $cost = (float) $product->floor_price;
            $impressions = 0;
            $selectedScreenIds = null;
        } else {
            $quantity = $screens->count();
            $unitPrice = (float) ($product->individual_price ?: $product->floor_price);
            $cost = $unitPrice * $quantity;
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

        return CartItem::updateOrCreate(
            ['cart_id' => $cart->id, 'product_id' => $productId],
            [
                'screen_id' => $firstScreen?->id,
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

        return CartItem::updateOrCreate(
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
                'rate_captured_at' => now(),
                'rate_snapshot' => $this->rateSnapshot($inv),
            ]
        );
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
        ];
    }

    /**
     * Remove item from cart.
     */
    public function removeItem(CartItem $item): void
    {
        $item->delete();
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
            'notes' => $data['notes'] ?? $item->notes,
            'rate_captured_at' => now(),
            'rate_snapshot' => $this->rateSnapshot($screen?->inventory),
        ]);

        return $item->fresh();
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

        $cost = round($ioRate * $screenCount * $durationUnits, 2);

        return [
            'impressions' => $totalImpressions,
            'cost' => $cost,
            'days' => $days,
            'unit_price' => $ioRate,
            'booked_cpms' => null,
            'screen_count' => $screenCount,
            'duration_units' => $durationUnits,
            'duration_unit' => $rateUnit,
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
