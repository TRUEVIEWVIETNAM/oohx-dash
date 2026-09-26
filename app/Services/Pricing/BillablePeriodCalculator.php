<?php

namespace App\Services\Pricing;

use Carbon\CarbonInterface;

/**
 * Quy đổi khoảng ngày thành số kỳ tính tiền.
 *
 * Đây là nơi duy nhất biết "một tháng là bao nhiêu ngày". Trước đây phép chia
 * nằm ngay trong CartService cùng với một nhánh `if` cho phép client gửi thẳng
 * số kỳ, nên người mua đặt 6 tháng mà trả tiền 1 tháng (audit F-01 / Codex F04).
 */
class BillablePeriodCalculator
{
    /** Số ngày, tính cả ngày đầu và ngày cuối. */
    public function days(CarbonInterface $start, CarbonInterface $end): int
    {
        // copy() vì startOfDay() làm biến đổi đối tượng gốc — Codex R03 nhắc điểm này.
        $from = $start->copy()->startOfDay();
        $to   = $end->copy()->startOfDay();

        return max(1, (int) $from->diffInDays($to) + 1);
    }

    /** Số kỳ I/O, làm tròn lên. */
    public function ioUnits(CarbonInterface $start, CarbonInterface $end, string $rateUnit): int
    {
        $divisor = $this->divisorFor($rateUnit);

        return max(1, (int) ceil($this->days($start, $end) / $divisor));
    }

    /** Số CPM tối thiểu phải mua, suy từ lượng hiển thị ước tính. */
    public function minimumCpms(int $estimatedImpressions): int
    {
        return max(1, (int) ceil($estimatedImpressions / 1000));
    }

    public function divisorFor(string $rateUnit): int
    {
        return $rateUnit === 'week'
            ? (int) config('pricing.week_days', 7)
            : (int) config('pricing.month_days', 30);
    }
}
