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
        if ($rateUnit !== 'week' && config('pricing.month_mode', 'calendar') === 'calendar') {
            return $this->calendarMonths($start, $end);
        }

        $divisor = $this->divisorFor($rateUnit);

        return max(1, (int) ceil($this->days($start, $end) / $divisor));
    }

    /**
     * Số kỳ theo tháng lịch, tính từ mốc cùng ngày của tháng sau.
     *
     * 01/01 – 30/06 = 6 kỳ (mỗi kỳ chạy tới hôm trước mốc tháng sau).
     * 01/01 – 01/07 = 7 kỳ (đã bước sang ngày đầu kỳ thứ bảy).
     * 15/01 – 14/02 = 1 kỳ. 15/01 – 15/02 = 2 kỳ.
     *
     * Ngày 31 được xử lý bằng addMonthsNoOverflow: 31/01 + 1 tháng = 28/02
     * (hoặc 29/02 năm nhuận), không nhảy sang tháng 3.
     */
    public function calendarMonths(CarbonInterface $start, CarbonInterface $end): int
    {
        $from = $start->copy()->startOfDay();
        $to   = $end->copy()->startOfDay();

        if ($to->lessThanOrEqualTo($from)) {
            return 1;
        }

        $units  = 0;
        $cursor = $from->copy();

        // Mỗi vòng là một kỳ: [cursor, cursor + 1 tháng - 1 ngày].
        while ($cursor->lessThanOrEqualTo($to)) {
            $units++;
            $cursor = $cursor->copy()->addMonthNoOverflow();
        }

        return max(1, $units);
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
