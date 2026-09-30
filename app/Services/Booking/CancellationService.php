<?php

namespace App\Services\Booking;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\Refund;
use App\Models\User;
use App\Services\InventoryHoldService;
use App\Services\PaymentService;
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
                    'tiers'        => config('pricing.refund_tiers'),
                    'tier_applied' => $quote['tier'],
                    'captured_at'  => now()->toIso8601String(),

                    // Hai căn cứ để đối soát được khi tiền vào muộn hơn lệnh
                    // hủy (xem `reconcileAfterPayment()`).
                    //
                    // `allocation_basis` là mẫu số chia tỉ lệ tại **thời điểm
                    // hủy**: tổng `estimated_cost` của các dòng còn mở của
                    // owner này. Không lưu thì sau đó không dựng lại được, và
                    // đoán lại là cách sinh lỗi tiền khó thấy.
                    //
                    // `committed_payment_ids` là các khoản chuyển **đã tồn
                    // tại** lúc hủy, kể cả khoản còn chờ. Đây là ranh giới
                    // phân biệt hai tình huống mà R24 và R36 nói tới: tiền từ
                    // khoản đã cam kết trước khi hủy thì thuộc dòng bị hủy;
                    // tiền từ khoản tạo **sau** khi hủy thì thuộc các dòng
                    // còn sống. Lưu id thay vì so mốc thời gian vì
                    // `created_at` chỉ tới giây — hai sự kiện trong cùng một
                    // giây sẽ cho kết quả tùy lúc chạy.
                    'allocation_basis'      => $this->openCostFor($line),
                    'committed_payment_ids' => $this->committedPaymentIdsFor($line),
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

    /**
     * Đối soát lại nghĩa vụ hoàn tiền sau khi một khoản thanh toán được xác
     * nhận muộn (Codex R36).
     *
     * **Lỗ hổng bản trước.** `quote()` chỉ tính khoản đã `completed`, đúng —
     * khoản còn chờ thì chưa có đồng nào chuyển đi. Nhưng đường hủy mới cho
     * người mua hủy **trong khi** khoản chuyển của họ còn `pending`: lúc đó
     * hệ thống ghi `paid_amount = 0`, `amount = 0`, `status = waived`. Sau đó
     * sàn đối chiếu ngân hàng và xác nhận đúng khoản ấy — tiền thật đã vào,
     * nhưng nghĩa vụ hoàn vẫn là 0, và người mua không hủy lại được để tính
     * lại. Người mua hủy trong kỳ hoàn 100% mà mất trắng.
     *
     * Toàn bộ test hoàn tiền cũ đều xác nhận tiền **trước** rồi mới hủy, nên
     * không ca nào đi qua đường này.
     *
     * **Ranh giới quan trọng, và là chỗ bản sửa đầu của tôi sai.** Bản đầu
     * phân bổ *mọi* tiền chưa phân bổ cho các dòng đã hủy. Điều đó mâu thuẫn
     * với một quyết định có chủ ý từ vòng hai: **tiền trả thêm cho dòng còn
     * sống không được chia lại vào dòng đã hủy** (R24), và nó làm đỏ đúng hai
     * ca chống hồi quy R24 và R34.
     *
     * Phân biệt không nằm ở "tiền vào lúc nào" mà ở **khoản chuyển đó đã tồn
     * tại chưa khi hủy**:
     *
     * - Khoản đã tồn tại lúc hủy (kể cả còn `pending`) là tiền người mua đã
     *   cam kết cho tình trạng đơn **lúc đó** → thuộc cả dòng bị hủy. Đây là
     *   R36.
     * - Khoản tạo **sau** khi hủy là tiền trả cho các dòng còn sống → không
     *   chạm tới dòng đã hủy. Đây là R24.
     *
     * `cancelLine()` chụp lại hai căn cứ này vào `policy_snapshot`:
     * `committed_payment_ids` và `allocation_basis` (mẫu số chia tỉ lệ lúc
     * hủy). Lưu id thay vì so mốc thời gian vì `created_at` chỉ tới giây.
     *
     * **Công thức**, cùng công thức `paidForLine()` dùng, chỉ khác ở chỗ tổng
     * tiền được giới hạn trong các khoản đã cam kết:
     *
     *     entitled = min( withVat(line.estimated_cost),
     *                     (committed − allocatedTrước) × line.cost / basis )
     *
     * Trần `withVat(estimated_cost)` giữ cho một dòng không nhận nhiều hơn
     * phần chính nó bị tính. `refund_pct` giữ theo ảnh chụp lúc hủy: chính
     * sách áp theo ngày hủy, không theo ngày tiền vào.
     *
     * Luật này chỉ tăng, không giảm, và khi tiền đã phân bổ đủ thì nó là phép
     * không làm gì — nên các ca "xác nhận tiền trước rồi hủy" không đổi.
     *
     * Bản ghi hoàn tiền tạo trước khi có hai căn cứ trên thì **bỏ qua**, không
     * đoán: bảng `refunds` mới có từ giai đoạn 1 và chưa có dữ liệu thật, nên
     * không đáng đánh cược một phép đoán về tiền để xử lý hàng cũ.
     *
     * @return int số dòng đã hủy được điều chỉnh
     */
    public function reconcileAfterPayment(Campaign $campaign, ?string $ownerId): int
    {
        // Khoản không gắn owner không được `paidForLine()` tính cho dòng nào,
        // nên cũng không có gì để đối soát. Đây là dữ liệu trước khi thanh
        // toán tách theo owner.
        if (empty($ownerId)) {
            return 0;
        }

        return DB::transaction(function () use ($campaign, $ownerId) {
            // Khóa chiến dịch: hai lần xác nhận song song, hoặc một lần xác
            // nhận trùng với một lần hủy, đều đọc cùng con số "đã phân bổ" và
            // đều phân bổ tiếp — tức hoàn quá.
            Campaign::withoutGlobalScopes()->whereKey($campaign->getKey())->lockForUpdate()->first();

            // Số tiền từng khoản đã `completed`, tra được theo id — vì mỗi dòng
            // đã hủy chỉ được tính các khoản có trong ảnh chụp của nó.
            $completedById = $campaign->payments()
                ->where('owner_id', $ownerId)
                ->where('status', 'completed')
                ->pluck('amount', 'id')
                ->map(fn ($amount) => (int) round((float) $amount));

            $refunds = Refund::where('campaign_id', $campaign->id)
                ->where('owner_id', $ownerId)
                ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_SETTLED, Refund::STATUS_WAIVED])
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $allocated = (int) round((float) $refunds->sum('paid_amount'));
            $available = max(0, (int) $completedById->sum() - $allocated);

            if ($available <= 0) {
                return 0;
            }

            $payments = app(PaymentService::class);
            $adjusted = 0;

            // `allocatedBefore` cho dòng đang xét: tổng đã phân bổ cho các dòng
            // hủy **trước** nó, gồm cả phần vừa phân bổ trong lần chạy này.
            $allocatedBefore = 0;

            // Gom theo dòng, vì trần và mẫu số là của **dòng**, không của từng
            // bản ghi: một dòng có thể có nhiều bản ghi khi tiền vào nhiều lần.
            // `groupBy` trên tập đã sắp xếp giữ đúng thứ tự hủy.
            foreach ($refunds->groupBy('booking_line_id') as $lineRefunds) {
                $linePaid = (int) round((float) $lineRefunds->sum('paid_amount'));

                if ($available <= 0) {
                    $allocatedBefore += $linePaid;
                    continue;
                }

                // Bản ghi đầu của dòng mang ảnh chụp căn cứ; các bản sau là
                // phần bổ sung do chính hàm này tạo.
                $first    = $lineRefunds->first();
                $template = $lineRefunds->last();
                $snapshot = $first->policy_snapshot ?? [];

                $basis       = (int) ($snapshot['allocation_basis'] ?? 0);
                $committedIds = $snapshot['committed_payment_ids'] ?? null;
                $lineCost    = (int) round((float) ($template->bookingLine?->estimated_cost ?? 0));

                if ($basis <= 0 || ! is_array($committedIds) || $lineCost <= 0) {
                    $allocatedBefore += $linePaid;
                    continue;
                }

                $committed = (int) $completedById->only($committedIds)->sum();

                $entitled = min(
                    $payments->withVat($lineCost),
                    max(0, (int) round(($committed - $allocatedBefore) * $lineCost / $basis)),
                );

                $room = $entitled - $linePaid;

                if ($room <= 0) {
                    $allocatedBefore += $linePaid;
                    continue;
                }

                $take = min($room, $available);

                $open = $lineRefunds
                    ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_WAIVED])
                    ->first();

                if ($open) {
                    $newPaid   = (int) round((float) $open->paid_amount) + $take;
                    $newAmount = (int) round($newPaid * (int) $open->refund_pct / 100);

                    $open->update([
                        'paid_amount' => $newPaid,
                        'amount'      => $newAmount,
                        'status'      => $newAmount > 0 ? Refund::STATUS_PENDING : Refund::STATUS_WAIVED,
                    ]);
                } else {
                    // Mọi bản ghi của dòng này đã `settled`: tiền đã chuyển
                    // xong ở ngoài hệ thống, ghi đè là sửa một giao dịch đã
                    // hoàn tất. Nhưng bỏ qua thì phần tiền vào muộn **mất
                    // luôn** — người mua trả thêm mà không được hoàn thêm.
                    //
                    // Nên ghi một **nghĩa vụ mới** cho cùng dòng đó: hai lần
                    // chuyển khoản, hai bản ghi, lịch sử không bị sửa.
                    // `refund_pct` và ảnh chụp chính sách lấy từ bản ghi cũ vì
                    // chính sách áp theo ngày hủy, không theo ngày tiền vào.
                    $newAmount = (int) round($take * (int) $template->refund_pct / 100);

                    Refund::create([
                        'campaign_id'       => $template->campaign_id,
                        'booking_line_id'   => $template->booking_line_id,
                        'owner_id'          => $template->owner_id,
                        'organization_id'   => $template->organization_id,
                        'paid_amount'       => $take,
                        'amount'            => $newAmount,
                        'refund_pct'        => $template->refund_pct,
                        'days_before_start' => $template->days_before_start,
                        'policy_snapshot'   => $template->policy_snapshot,
                        'reason'            => 'Đối soát bổ sung: thanh toán được xác nhận sau khi dòng đã bị hủy và khoản hoàn trước đó đã chuyển xong.',
                        'status'            => $newAmount > 0 ? Refund::STATUS_PENDING : Refund::STATUS_WAIVED,
                    ]);
                }

                $available -= $take;
                $allocatedBefore += $linePaid + $take;
                $adjusted++;

                CampaignActivity::log(
                    $campaign,
                    'refund_reconciled',
                    sprintf(
                        'Đối soát hoàn tiền sau khi xác nhận thanh toán: dòng đã hủy trên "%s" được phân bổ thêm %s ₫, nghĩa vụ hoàn thêm %s ₫ (%d%%)',
                        $template->bookingLine?->screen?->name ?? $template->booking_line_id,
                        number_format($take, 0, ',', '.'),
                        number_format($newAmount, 0, ',', '.'),
                        (int) $template->refund_pct,
                    ),
                );
            }

            return $adjusted;
        });
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
        // Trừ TOÀN BỘ phần đã phân bổ cho các dòng đã hủy, không chỉ phần đã
        // hoàn.
        //
        // Bản trước chỉ trừ `refunds.amount`. Phần **giữ lại theo chính sách**
        // (phí hủy) vẫn nằm trong pool, mà dòng đã hủy thì ra khỏi mẫu số — nên
        // số tiền đó biến thành "đã trả" cho các dòng khác (Codex R30). Với
        // hai dòng 1 triệu, trả đủ 2.160.000: hủy A ở mức 50% rồi hủy B ở mức
        // 100% cho ra tổng hoàn 2.160.000, tức **mất sạch phí hủy 540.000 của
        // A** — và đổi thứ tự hủy lại ra tổng khác.
        //
        // `refunds.paid_amount` chính là phần đã phân bổ cho dòng đó, gồm cả
        // phần hoàn và phần giữ lại. Bản ghi `waived` (hoàn 0%) cũng có
        // `paid_amount`, nên trừ theo cột này xử lý luôn trường hợp đó.
        $ownerPaid = (int) round((float) $campaign->payments()
            ->where('owner_id', $line->owner_id)
            ->where('status', 'completed')
            ->sum('amount'));

        $alreadyAllocated = (int) round((float) Refund::where('campaign_id', $campaign->id)
            ->where('owner_id', $line->owner_id)
            ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_SETTLED, Refund::STATUS_WAIVED])
            ->sum('paid_amount'));

        $availableToAllocate = max(0, $ownerPaid - $alreadyAllocated);

        if ($availableToAllocate <= 0) {
            return 0;
        }

        $openCost = $this->openCostFor($line);

        if ($openCost <= 0) {
            return 0;
        }

        $share = (int) round($availableToAllocate * ((float) $line->estimated_cost) / $openCost);

        // Không phân bổ nhiều hơn số thực còn lại.
        return min($share, $availableToAllocate);
    }

    /**
     * Mẫu số chia tỉ lệ: tổng chi phí các dòng **còn mở** của owner này.
     *
     * Gồm cả chính dòng đang hủy — lúc gọi hàm này nó chưa chuyển trạng thái.
     * Một định nghĩa duy nhất, dùng cho cả lúc hủy và lúc đối soát; hai định
     * nghĩa lệch nhau ở đây là hai con số tiền khác nhau.
     */
    private function openCostFor(BookingLine $line): int
    {
        return (int) round((float) $line->campaign->bookingLines()
            ->where('owner_id', $line->owner_id)
            ->whereIn('status', ['approved', 'active', 'completed'])
            ->sum('estimated_cost'));
    }

    /**
     * Id các khoản chuyển của owner này **đã tồn tại** ở thời điểm gọi.
     *
     * Gồm khoản còn chờ và đang xử lý, vì đó chính là tiền người mua đã cam
     * kết nhưng sàn chưa đối chiếu xong. Không gồm `failed` và `refunded`:
     * những khoản đó không mang tiền nào vào.
     *
     * @return array<int, string>
     */
    private function committedPaymentIdsFor(BookingLine $line): array
    {
        return $line->campaign->payments()
            ->where('owner_id', $line->owner_id)
            ->whereIn('status', ['pending', 'processing', 'completed'])
            ->pluck('id')
            ->all();
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
