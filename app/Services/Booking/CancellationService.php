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
            // Khóa và ĐỌC LẠI trong transaction trước khi quyết định.
            //
            // Phép kiểm trạng thái ở trên chạy trên đối tượng người gọi truyền
            // vào, có thể đã cũ. Hai yêu cầu hủy song song — hoặc hai lần bấm
            // trên hai tab — đều thấy 'approved' và đều tạo nghĩa vụ hoàn tiền,
            // tức hoàn hai lần cho một dòng (Codex R13).
            $line = BookingLine::withoutGlobalScopes()
                ->whereKey($line->getKey())
                ->lockForUpdate()
                ->first();

            if (! $line) {
                throw new HttpException(422, 'Dòng đặt chỗ không còn tồn tại.');
            }

            if (in_array($line->status, self::UNCANCELLABLE, true)) {
                throw new HttpException(422, 'Dòng đặt chỗ đã được xử lý bởi một yêu cầu khác.');
            }

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

        // Chỉ phân bổ phần tiền CÒN LẠI cho các dòng CÒN MỞ.
        //
        // Cách cũ chia tổng tiền gộp theo tỉ lệ trên **tất cả** dòng, kể cả
        // dòng đã hủy và đã hoàn. Nên sau khi hủy A rồi trả thêm cho B, tiền
        // mới vẫn bị chia một phần vào A — và lúc hủy B thì nghĩa vụ hoàn tính
        // thiếu (Codex R24: trả 1.620.000, hai lần hủy đều trong kỳ hoàn 100%,
        // nhưng tổng hoàn chỉ ra 1.350.000).
        //
        // Phần đã hoàn cho những dòng hủy trước đó bị trừ khỏi tiền còn lại;
        // phần đó đã chốt xong, không được đem chia lại.
        $ownerPaid = (int) round((float) $campaign->payments()
            ->where('owner_id', $line->owner_id)
            ->where('status', 'completed')
            ->sum('amount'));

        $alreadyRefunded = (int) round((float) Refund::where('campaign_id', $campaign->id)
            ->where('owner_id', $line->owner_id)
            ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_SETTLED])
            ->sum('amount'));

        $availableToAllocate = max(0, $ownerPaid - $alreadyRefunded);

        if ($availableToAllocate <= 0) {
            return 0;
        }

        // Dòng còn mở gồm cả chính dòng đang hủy — lúc gọi hàm này nó chưa
        // chuyển trạng thái.
        $openCost = (float) $campaign->bookingLines()
            ->where('owner_id', $line->owner_id)
            ->whereIn('status', ['approved', 'active', 'completed'])
            ->sum('estimated_cost');

        if ($openCost <= 0) {
            return 0;
        }

        $share = (int) round($availableToAllocate * ((float) $line->estimated_cost) / $openCost);

        // Không phân bổ nhiều hơn số thực còn lại.
        return min($share, $availableToAllocate);
    }

    /**
     * Chiến dịch không còn dòng nào sống thì chính nó cũng là đã hủy.
     */
    private function syncCampaignStatus(Campaign $campaign): void
    {
        $alive = $campaign->bookingLines()
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->exists();

        if (! $alive) {
            if ($campaign->status !== Campaign::STATUS_CANCELLED) {
                $campaign->update(['status' => Campaign::STATUS_CANCELLED]);
                CampaignActivity::log($campaign, 'cancelled', 'Chiến dịch chuyển sang đã hủy vì không còn dòng đặt chỗ nào');
            }

            return;
        }

        // Còn dòng sống, nhưng không còn dòng nào ĐANG CHẠY thì chiến dịch
        // không còn là "đang chạy".
        //
        // Trước đây chỉ xét "còn dòng chưa hủy hay không", nên hủy dòng active
        // cuối cùng vẫn để chiến dịch ở trạng thái đang chạy trong khi không có
        // màn hình nào phát (Codex R14).
        $hasActive = $campaign->bookingLines()->where('status', 'active')->exists();

        if (! $hasActive && $campaign->status === Campaign::STATUS_ACTIVE) {
            $campaign->update(['status' => Campaign::STATUS_APPROVED]);

            CampaignActivity::log(
                $campaign,
                'deactivated',
                'Chiến dịch quay lại trạng thái đã duyệt: không còn dòng đặt chỗ nào đang chạy',
            );
        }
    }
}
