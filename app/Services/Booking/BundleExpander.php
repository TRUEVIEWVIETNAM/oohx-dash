<?php

namespace App\Services\Booking;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Screen;
use App\Services\Pricing\BillablePeriodCalculator;
use App\Services\PurchaseEligibilityService;
use Illuminate\Support\Collection;

/**
 * Mở một dòng giỏ thuộc sản phẩm thành nhiều dòng đặt chỗ — mỗi màn hình một dòng.
 *
 * Vì sao phải có: một gói 10 màn hình trước đây thu về **một** dòng booking trỏ
 * vào màn hình đầu tiên. SOV chỉ bị trừ ở một chỗ nên 9 màn còn lại vẫn bán tiếp,
 * và toàn bộ tiền ghi cho owner của màn hình đầu. Đây là cùng một kiểu lỗi với
 * F-12: một thực thể nghiệp vụ được biểu diễn hai cách, rồi hai cách trôi khỏi nhau.
 *
 * Nguyên tắc chia tiền (chốt 29/09/2026): **chia theo giá niêm yết của từng
 * màn hình quy về một ngày.** Màn hình đắt gánh phần lớn hơn, đúng như khi mua
 * lẻ. Không có giá niêm yết thì chia đều — và nói rõ trong bản chụp là đã chia
 * đều, để sau này đối soát không phải đoán.
 */
class BundleExpander
{
    public function __construct(
        private readonly PurchaseEligibilityService $eligibility,
        private readonly BillablePeriodCalculator $periods,
    ) {}

    public const SPLIT_BY_RATE  = 'theo giá niêm yết quy về ngày';
    public const SPLIT_EQUALLY  = 'chia đều';

    /**
     * Tập màn hình thật sự bán được của một dòng giỏ thuộc sản phẩm.
     *
     * Đọc lại từ sản phẩm chứ không tin `selected_screen_ids` đã lưu: giữa lúc
     * thêm giỏ và lúc chốt đơn, owner có thể đã gỡ một màn hình khỏi gói hoặc
     * ngưng bán nó. Hàm này để nguyên cho ngoại lệ 422 của cổng bán hàng bay ra.
     */
    public function resolveScreens(CartItem $item, Product $product): Collection
    {
        return $this->eligibility->resolveProductScreens(
            $product,
            $this->buyModeOf($item),
            is_array($item->selected_screen_ids) ? $item->selected_screen_ids : null,
        );
    }

    /**
     * Kiểu mua của dòng giỏ.
     *
     * Ưu tiên cột `buy_mode`; các dòng giỏ có trước khi cột này tồn tại thì suy
     * ra từ `selected_screen_ids` — đúng theo cách `CartService::addProduct`
     * từng ghi: mua cả gói thì để null, mua lẻ thì lưu danh sách.
     */
    public function buyModeOf(CartItem $item): string
    {
        if (in_array($item->buy_mode, ['package', 'individual'], true)) {
            return $item->buy_mode;
        }

        return is_array($item->selected_screen_ids) && $item->selected_screen_ids !== []
            ? 'individual'
            : 'package';
    }

    /**
     * Tổng tiền của một dòng giỏ thuộc sản phẩm. **Nguồn duy nhất.**
     *
     * Thêm giỏ, sửa giỏ và chốt đơn phải dùng đúng hàm này. Trước đây ba nơi
     * tính ba kiểu: `addProduct` lấy giá sản phẩm, `updateItem` lại tính theo
     * giá kho của màn hình đầu tiên, còn guard lúc chốt đơn so với giá sản
     * phẩm. Hệ quả: chỉ cần sửa một dòng giỏ dạng sản phẩm là chốt đơn báo
     * "giá vừa thay đổi" dù không ai đổi giá — chính guard tôi thêm ở R06 lại
     * chặn đường mua hàng bình thường (Codex R28).
     *
     * @param  Collection<int, Screen>  $screens
     */
    public function productTotal(Product $product, string $buyMode, Collection $screens): int
    {
        if ($buyMode === 'package') {
            return (int) round((float) $product->floor_price);
        }

        $unitPrice = (int) round((float) ($product->individual_price ?: $product->floor_price));

        return $unitPrice * $screens->count();
    }

    /**
     * Chia số tiền của gói cho từng màn hình.
     *
     * @param  Collection<int, Screen>  $screens
     * @return array{amounts: array<int, int>, weights: array<int, int>, method: string}
     */
    public function splitCost(int $totalVnd, Collection $screens, string $buyMode, int $unitPriceVnd = 0): array
    {
        // Mua lẻ: mỗi màn hình một đơn giá niêm yết, không có gì phải chia.
        if ($buyMode === 'individual') {
            $amounts = array_fill(0, $screens->count(), $unitPriceVnd);

            return [
                'amounts' => $amounts,
                'weights' => $amounts,
                'method'  => 'đơn giá niêm yết từng màn hình',
            ];
        }

        $weights = $screens->map(fn (Screen $s) => $this->splitWeight($s))->values()->all();
        $method  = array_sum($weights) > 0 ? self::SPLIT_BY_RATE : self::SPLIT_EQUALLY;

        return [
            'amounts' => self::splitVnd($totalVnd, $weights),
            'weights' => $weights,
            'method'  => $method,
        ];
    }

    /**
     * Mẫu số chung của mọi kỳ tính giá: bội số chung nhỏ nhất của 7 và 30.
     *
     * Nhân lên thay vì chia xuống. Nếu quy giá về "một ngày" bằng phép chia thì
     * 1.000.000/30 làm tròn thành 33.333, và hai màn hình đáng lẽ tỉ lệ 1:3 hóa
     * ra 1.999.984 với 6.000.016 — test bắt được đúng chỗ này.
     */
    private const PERIOD_LCM = 210;

    /**
     * Trọng số chia tiền của một màn hình: giá niêm yết quy về cùng một mẫu số.
     *
     * Không phải giá bán, chỉ là tỉ lệ. Quy về cùng mẫu số để so được màn hình
     * tính theo tuần với màn hình tính theo tháng, và làm bằng phép nhân nên
     * không mất chữ số nào.
     */
    public function splitWeight(Screen $screen): int
    {
        $inv = $screen->inventory;

        if (! $inv) {
            return 0;
        }

        if (($inv->pricing_model ?? 'io') !== 'cpm' && $inv->io_rate) {
            $divisor = max(1, $this->periods->divisorFor($inv->io_rate_unit ?? 'month'));

            return (int) round(((float) $inv->io_rate) * (self::PERIOD_LCM / $divisor));
        }

        if ($inv->floor_cpm && $inv->weekly_impressions) {
            // CPM là giá cho 1.000 lượt hiển thị; doanh thu ngày × 210.
            return (int) round(
                ((float) $inv->floor_cpm) * ((int) $inv->weekly_impressions) * (self::PERIOD_LCM / 7) / 1000
            );
        }

        return 0;
    }

    /**
     * Chia một số tiền nguyên theo trọng số, **tổng luôn đúng bằng số ban đầu**.
     *
     * Phần dư sau khi chia được cộng vào các phần có trọng số lớn nhất trước.
     * Không dùng số thực: cộng mười phần 1/3 của một số thực lại thì lệch, và
     * lệch trong tiền là thứ người ta phát hiện bằng hóa đơn chứ không bằng test.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    public static function splitVnd(int $total, array $weights): array
    {
        $n = count($weights);

        if ($n === 0) {
            return [];
        }

        $weights = array_map(fn ($w) => max(0, (int) $w), array_values($weights));
        $sum = array_sum($weights);

        if ($sum <= 0) {
            $weights = array_fill(0, $n, 1);
            $sum = $n;
        } else {
            $weights = self::fitWeightsTo($total, self::reduceWeights($weights));
            $sum = array_sum($weights);
        }

        $amounts = [];
        foreach ($weights as $i => $w) {
            $amounts[$i] = intdiv($total * (int) $w, $sum);
        }

        $remainder = $total - array_sum($amounts);

        if ($remainder !== 0) {
            // Sắp thứ tự theo trọng số giảm dần, giữ nguyên thứ tự gốc khi bằng
            // nhau, để cùng đầu vào luôn cho cùng kết quả.
            $order = array_keys($weights);
            usort($order, fn ($a, $b) => ($weights[$b] <=> $weights[$a]) ?: ($a <=> $b));

            $step = $remainder > 0 ? 1 : -1;
            for ($k = 0; $k < abs($remainder); $k++) {
                $amounts[$order[$k % $n]] += $step;
            }
        }

        return $amounts;
    }

    /**
     * Rút gọn trọng số để phép `total * weight` không tràn số nguyên.
     *
     * Chia hết cho ước chung lớn nhất trước — bước này **không** làm mất tỉ lệ.
     * Nếu vẫn còn quá lớn (kho tính theo CPM với hàng chục triệu lượt hiển thị
     * có thể cho trọng số cỡ 10^10) thì mới hạ dần bằng cách chia đôi: tỉ lệ thô
     * đi một chút nhưng vẫn còn hơn 10^8 mức phân giải, và **tổng tiền vẫn đúng
     * tuyệt đối** vì phần dư được phân bổ lại ở bước sau.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private static function reduceWeights(array $weights): array
    {
        $gcd = 0;
        foreach ($weights as $w) {
            $gcd = self::gcd($gcd, $w);
        }

        if ($gcd > 1) {
            $weights = array_map(fn ($w) => intdiv($w, $gcd), $weights);
        }

        return $weights;
    }

    /**
     * Hạ trọng số xuống mức mà `total * weight` chắc chắn không tràn.
     *
     * Ngưỡng phải tính TỪ CHÍNH số tiền, không phải một hằng số. Bản trước
     * chốt cứng 10^8 dựa trên giả định "số tiền lớn nhất khoảng 10^10", nhưng
     * cột tiền là `decimal(15,2)` nên nhận tới 10^13, và biểu mẫu không chặn ở
     * mức tương ứng: `splitVnd(100_000_000_000, [100_000_000, 99_999_999])`
     * cho tích vượt số nguyên 64 bit (Codex R21).
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private static function fitWeightsTo(int $total, array $weights): array
    {
        $total = max(1, abs($total));

        // Giữ biên an toàn: tích lớn nhất không vượt 1/4 khoảng số nguyên.
        $maxWeight = max(1, intdiv(PHP_INT_MAX >> 2, $total));

        while (max($weights) > $maxWeight) {
            $weights = array_map(fn ($w) => max($w > 0 ? 1 : 0, intdiv($w, 2)), $weights);
        }

        return $weights;
    }

    private static function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
