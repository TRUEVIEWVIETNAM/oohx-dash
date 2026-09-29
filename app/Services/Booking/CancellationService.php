<?php

namespace App\Services\Booking;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\Refund;
use App\Models\User;
use App\Services\InventoryHoldService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Hủy đặt chỗ và tính tiền hoàn.
 *
 * Trước thay đổi này, `cancelled` chỉ là một giá trị trong enum: không có đường
 * nào đi tới nó, nên khách muốn hủy thì không ai xử lý được và không có chỗ nào
 * ghi lại họ được hoàn bao nhiêu.
 *
 * Chính sách (chốt 29/09/2026, để trong `config/pricing.php`): bậc thang theo
 * số ngày còn lại tính tới ngày chạy đầu tiên của **chính dòng bị hủy** — hủy
 * trước ≥14 ngày hoàn 100%, 7–13 ngày hoàn 50%, dưới 7 ngày không hoàn.
 *
 * Hai điều cố ý:
 *
 * - Tính theo ngày chạy của **từng dòng**, không theo ngày bắt đầu chiến dịch.
 *   Một chiến dịch chạy từ tháng 1 tới tháng 6 mà hủy dòng của tháng 6 vào
 *   tháng 2 thì đó là hủy sớm, không phải hủy muộn.
 * - Hủy **nhả suất về kho ngay**. Không nhả thì màn hình vẫn bị chiếm bởi một
 *   đơn không còn tồn tại, và đó là mất doanh thu thật.
 */
class CancellationService
{
    /** Đã chạy xong thì không hủy được nữa. */
    private const UNCANCELLABLE = ['cancelled', 'completed', 'rejected'];

    /**
     * Tính trước số tiền hoàn mà không thay đổi gì.
     *
     * @return array{days_before: int, refund_pct: int, paid: int, refundable: int, tier: array}
     */
    public function quote(BookingLine $line, ?Carbon $at = null): array
    {
        $at   = $at ?: now();
        $days = $this->daysBeforeStart($line, $at);
        $tier = $this->tierFor($days);

        $paid = $this->paidForLine($line);
        $pct  = (int) $tier['refund_pct'];

        return [
            'days_before' => $days,
            'refund_pct'  => $pct,
            'paid'        => $paid,
            'refundable'  => (int) round($paid * $pct / 100),
            'tier'        => $tier,
        ];
    }

    /**
     * Hủy một dòng đặt chỗ, nhả suất và ghi nghĩa vụ hoàn tiền.
     */
    public function cancelLine(BookingLine $line, ?User $actor = null, ?string $reason = null): Refund
    {
        if (in_array($line->status, self::UNCANCELLABLE, true)) {
            throw new HttpException(422, match ($line->status) {
                'completed' => 'Dòng đặt chỗ đã chạy xong, không hủy được.',
                'cancelled' => 'Dòng đặt chỗ đã bị hủy trước đó.',
                default     => 'Dòng đặt chỗ ở trạng thái không thể hủy.',
            });
        }

        return DB::transaction(function () use ($line, $actor, $reason) {
            $quote = $this->quote($line);

            $line->update([
                'status'          => 'cancelled',
                'rejected_reason' => $reason,
            ]);

            // Suất phải về kho ngay. Giữ lại là chiếm chỗ cho một đơn không còn
            // tồn tại — mất doanh thu thật, không phải chuyện sổ sách.
            app(InventoryHoldService::class)->releaseForBookingLine($line);

            $refund = Refund::create([
                'campaign_id'       => $line->campaign_id,
                'booking_line_id'   => $line->id,
                'owner_id'          => $line->owner_id,
                'organization_id'   => $line->campaign->organization_id,
                'paid_amount'       => $quote['paid'],
                'amount'            => $quote['refundable'],
                'refund_pct'        => $quote['refund_pct'],
                'days_before_start' => $quote['days_before'],
                'policy_snapshot'   => [
                    'tiers'       => config('pricing.refund_tiers'),
                    'tier_applied' => $quote['tier'],
                    'captured_at' => now()->toIso8601String(),
                ],
                'reason'       => $reason,
                'status'       => $quote['refundable'] > 0 ? Refund::STATUS_PENDING : Refund::STATUS_WAIVED,
                'requested_by' => $actor?->id,
            ]);

            CampaignActivity::log(
                $line->campaign,
                'line_cancelled',
                sprintf(
                    'Hủy đặt chỗ trên "%s" (còn %d ngày tới ngày chạy, hoàn %d%% = %s ₫)',
                    $line->screen?->name ?? $line->screen_id,
                    $quote['days_before'],
                    $quote['refund_pct'],
                    number_format($quote['refundable'], 0, ',', '.'),
                ),
                $actor?->id,
            );

            $this->syncCampaignStatus($line->campaign->fresh());

            return $refund;
        });
    }

    /**
     * Hủy cả chiến dịch: hủy từng dòng còn hủy được.
     *
     * @return Collection<int, Refund>
     */
    public function cancelCampaign(Campaign $campaign, ?User $actor = null, ?string $reason = null): Collection
    {
        $lines = $campaign->bookingLines()
            ->whereNotIn('status', self::UNCANCELLABLE)
            ->with(['screen', 'campaign'])
            ->get();

        if ($lines->isEmpty()) {
            throw new HttpException(422, 'Chiến dịch không còn dòng đặt chỗ nào để hủy.');
        }

        return DB::transaction(fn () => $lines->map(fn (BookingLine $line) => $this->cancelLine($line, $actor, $reason)));
    }

    /** Đánh dấu nghĩa vụ hoàn tiền đã xử lý xong ngoài hệ thống. */
    public function settle(Refund $refund, ?User $actor = null): Refund
    {
        if ($refund->status !== Refund::STATUS_PENDING) {
            throw new HttpException(422, 'Khoản hoàn tiền này không ở trạng thái chờ.');
        }

        $refund->update(['status' => Refund::STATUS_SETTLED, 'settled_at' => now()]);

        CampaignActivity::log(
            $refund->campaign,
            'refund_settled',
            'Đã hoàn ' . number_format((float) $refund->amount, 0, ',', '.') . ' ₫',
            $actor?->id,
        );

        return $refund->fresh();
    }

    /**
     * Số ngày còn lại tới ngày chạy đầu tiên của dòng. Đã quá ngày thì bằng 0.
     */
    private function daysBeforeStart(BookingLine $line, Carbon $at): int
    {
        $start = $line->start_date->copy()->startOfDay();
        $today = $at->copy()->startOfDay();

        return $start->lessThanOrEqualTo($today) ? 0 : (int) $today->diffInDays($start);
    }

    /**
     * Mốc chính sách áp dụng. Đọc từ trên xuống, mốc đầu tiên khớp thì lấy.
     *
     * @return array{min_days_before: int, refund_pct: int}
     */
    private function tierFor(int $daysBefore): array
    {
        $tiers = config('pricing.refund_tiers', []);

        foreach ($tiers as $tier) {
            if ($daysBefore >= (int) $tier['min_days_before']) {
                return ['min_days_before' => (int) $tier['min_days_before'], 'refund_pct' => (int) $tier['refund_pct']];
            }
        }

        // Không cấu hình gì thì mặc định không hoàn — thà chặt tay còn hơn tự
        // ý hứa hoàn tiền thay media owner.
        return ['min_days_before' => 0, 'refund_pct' => 0];
    }

    /**
     * Người mua đã thực sự trả bao nhiêu cho dòng này.
     *
     * Tiền trả theo owner chứ không theo dòng, nên chia theo tỉ lệ giá của dòng
     * trong tổng của owner đó. Chỉ tính khoản đã xác nhận: khoản còn chờ thì
     * chưa có đồng nào chuyển đi, không có gì để hoàn.
     */
    private function paidForLine(BookingLine $line): int
    {
        $campaign = $line->campaign;

        $ownerTotal = (float) $campaign->bookingLines()
            ->where('owner_id', $line->owner_id)
            ->whereIn('status', ['approved', 'active', 'completed', 'cancelled'])
            ->sum('estimated_cost');

        if ($ownerTotal <= 0) {
            return 0;
        }

        $ownerPaid = (float) $campaign->payments()
            ->where('owner_id', $line->owner_id)
            ->where('status', 'completed')
            ->sum('amount');

        return (int) round($ownerPaid * ((float) $line->estimated_cost) / $ownerTotal);
    }

    /**
     * Chiến dịch không còn dòng nào sống thì chính nó cũng là đã hủy.
     */
    private function syncCampaignStatus(Campaign $campaign): void
    {
        $alive = $campaign->bookingLines()
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->exists();

        if (! $alive && $campaign->status !== Campaign::STATUS_CANCELLED) {
            $campaign->update(['status' => Campaign::STATUS_CANCELLED]);

            CampaignActivity::log($campaign, 'cancelled', 'Chiến dịch chuyển sang đã hủy vì không còn dòng đặt chỗ nào');
        }
    }
}
