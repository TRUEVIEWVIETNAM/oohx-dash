<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Screen;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Một nơi duy nhất trả lời câu hỏi "thứ này có bán được không".
 *
 * Trang công khai đã ẩn màn hình của owner chưa duyệt hoặc bị tạm ngưng, nhưng
 * trước đây ai biết ID vẫn thêm được vào giỏ và đặt được (audit 23/09, F10).
 * Ẩn khỏi danh sách không phải là cổng kiểm soát.
 *
 * Gọi ở MỌI lối vào: thêm giỏ, sửa giỏ, tạo chiến dịch, gửi booking.
 */
class PurchaseEligibilityService
{
    /**
     * Màn hình đang mở bán? Ném 422 kèm lý do đọc được nếu không.
     */
    public function assertScreenPurchasable(Screen $screen): void
    {
        // publiclyVisible() = owner active + screen active + chưa xoá mềm
        $visible = Screen::publiclyVisible()->whereKey($screen->id)->exists();

        $this->deny(
            ! $visible,
            "Màn hình \"{$screen->name}\" hiện không nhận đặt chỗ."
        );

        $this->deny(
            $screen->status === 'maintenance',
            "Màn hình \"{$screen->name}\" đang bảo trì, chưa nhận đặt chỗ."
        );
    }

    /**
     * Sản phẩm đang mở bán?
     */
    public function assertProductPurchasable(Product $product): void
    {
        $visible = Product::publiclyVisible()->whereKey($product->id)->exists();

        $this->deny(
            ! $visible,
            "Sản phẩm \"{$product->name}\" hiện không nhận đặt chỗ."
        );
    }

    /**
     * Các màn hình được chọn phải thuộc đúng sản phẩm và từng cái phải bán được.
     *
     * @param  string[]  $screenIds
     */
    /**
     * Cách mua phải hợp với chế độ bán đã khai của sản phẩm.
     * Trước đây `buy_mode` do client gửi và chỉ dùng listing_mode để chọn mặc định,
     * nên gói `package_only` vẫn mua lẻ được (Codex follow-up T1, mục 4).
     */
    public function assertBuyModeAllowed(Product $product, string $buyMode): void
    {
        $this->deny(
            ! in_array($buyMode, ['package', 'individual'], true),
            'Cách mua không hợp lệ.'
        );

        $listingMode = $product->listing_mode ?? 'both';

        $this->deny(
            $listingMode === 'package_only' && $buyMode !== 'package',
            "Sản phẩm \"{$product->name}\" chỉ bán trọn gói."
        );

        $this->deny(
            $listingMode === 'individual_only' && $buyMode !== 'individual',
            "Sản phẩm \"{$product->name}\" chỉ bán lẻ từng màn hình."
        );
    }

    public function resolveProductScreens(Product $product, string $buyMode, ?array $selectedIds): \Illuminate\Support\Collection
    {
        $this->assertBuyModeAllowed($product, $buyMode);

        // Luôn đọc danh sách màn hình của sản phẩm không qua owner_scope: người mua
        // không có tenant, và publisher kiêm buyer sẽ nhận tập rỗng nếu để scope
        // (Codex review T1, F6).
        $productScreens = $product->screens()
            ->withoutGlobalScope('owner_scope')
            ->with('inventory')
            ->get();

        $this->deny(
            $productScreens->isEmpty(),
            "Sản phẩm \"{$product->name}\" chưa có màn hình nào để bán."
        );

        if ($buyMode === 'package') {
            // Mua cả gói: tập màn hình là toàn bộ thành phần của gói, không nhận
            // danh sách do client gửi.
            $screens = $productScreens;
        } else {
            $selectedIds = array_values(array_unique($selectedIds ?? []));

            $this->deny(empty($selectedIds), 'Chưa chọn màn hình nào.');

            $screens = $productScreens->whereIn('id', $selectedIds);

            $this->deny(
                $screens->count() !== count($selectedIds),
                'Danh sách màn hình được chọn không thuộc sản phẩm này.'
            );

            $min = (int) ($product->min_quantity ?? 0);
            $max = (int) ($product->max_quantity ?? 0);

            $this->deny(
                $min > 0 && $screens->count() < $min,
                "Sản phẩm này yêu cầu chọn tối thiểu {$min} màn hình."
            );
            $this->deny(
                $max > 0 && $screens->count() > $max,
                "Sản phẩm này chỉ cho chọn tối đa {$max} màn hình."
            );
        }

        // Từng màn hình trong tập phải thực sự bán được.
        foreach ($screens as $screen) {
            $this->assertScreenPurchasable($screen);
        }

        return $screens->values();
    }

    /**
     * Tìm màn hình để mua — qua cổng, không dùng findOrFail trần.
     */
    public function findPurchasableScreen(string $screenId): Screen
    {
        $screen = Screen::withoutGlobalScope('owner_scope')
            ->with('inventory')
            ->find($screenId);

        $this->deny(! $screen, 'Màn hình không tồn tại hoặc đã bị gỡ.');

        $this->assertScreenPurchasable($screen);

        return $screen;
    }

    /**
     * Tìm sản phẩm để mua.
     */
    public function findPurchasableProduct(string $productId): Product
    {
        $product = Product::withoutGlobalScope('owner_scope')->find($productId);

        $this->deny(! $product, 'Sản phẩm không tồn tại hoặc đã bị gỡ.');

        $this->assertProductPurchasable($product);

        return $product;
    }

    private function deny(bool $condition, string $message): void
    {
        if (! $condition) {
            return;
        }

        throw new HttpException(422, $message);
    }
}
