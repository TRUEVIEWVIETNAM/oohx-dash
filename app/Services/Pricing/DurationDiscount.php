<?php

namespace App\Services\Pricing;

/**
 * Chiết khấu theo số kỳ thuê.
 *
 * Trước đây 12 kỳ đúng bằng 12 lần một kỳ, nên mọi thỏa thuận giảm giá cho hợp
 * đồng dài đều nằm ngoài hệ thống — tức là nằm trong tin nhắn của người bán, và
 * không ai đối soát được.
 *
 * Bậc khai trên từng kho màn hình, dạng:
 *
 *     [{"min_units": 3, "discount_pct": 5}, {"min_units": 12, "discount_pct": 15}]
 *
 * Áp bậc **cao nhất mà số kỳ đạt tới**, không cộng dồn các bậc.
 */
class DurationDiscount
{
    /** @param mixed $tiers Dữ liệu khai trên kho, có thể là null hoặc rác. */
    public function pctFor(mixed $tiers, int $units): int
    {
        if (! is_array($tiers) || $tiers === [] || $units <= 0) {
            return 0;
        }

        $best = 0;

        foreach ($tiers as $tier) {
            if (! is_array($tier)) {
                continue;
            }

            $minUnits = (int) ($tier['min_units'] ?? 0);
            $pct      = (int) ($tier['discount_pct'] ?? 0);

            // Bậc vô lý thì bỏ qua thay vì tính ra giá âm: dữ liệu này do người
            // bán tự khai, và một lần gõ nhầm 150% không được thành hóa đơn âm.
            if ($minUnits <= 0 || $pct <= 0 || $pct > 100) {
                continue;
            }

            if ($units >= $minUnits && $pct > $best) {
                $best = $pct;
            }
        }

        return $best;
    }

    /** Áp chiết khấu lên số tiền, trả về VND nguyên. */
    public function apply(float $amount, int $pct): int
    {
        $pct = max(0, min(100, $pct));

        return (int) round($amount * (100 - $pct) / 100);
    }
}
