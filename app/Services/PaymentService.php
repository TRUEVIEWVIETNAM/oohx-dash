<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\Owner;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Booking\CancellationService;
use App\Services\Booking\CreativeGate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentService
{
    /** VAT áp cho dịch vụ quảng cáo. */
    /**
     * Thuế suất lấy từ config/pricing.php, không viết cứng.
     * Trước đây 0.1 nằm rải ở 5 chỗ trong service này và 8 chỗ trong blade, nên
     * đổi mức thuế là phải đi sửa từng chỗ và chắc chắn sót.
     */
    private function vatRate(): float
    {
        return (float) config('pricing.vat_rate', 0.08);
    }

    /**
     * Tạo một khoản thanh toán cho một media owner trong chiến dịch.
     *
     * Ba điểm khác trước:
     *
     * - **Số tiền do máy chủ tính.** Trước đây `amount` đến thẳng từ request và
     *   chỉ bị chặn `min:1000`, nên người mua khai bao nhiêu cũng được. Nay mặc
     *   định là phần còn nợ của owner đó, và số khách khai không được vượt quá.
     * - **Chống trùng.** Bấm nút hai lần không tạo hai khoản: khoản chờ của
     *   cùng chiến dịch + cùng owner được dùng lại.
     * - **Số hóa đơn có ràng buộc duy nhất** và sinh có thử lại.
     */
    public function createPayment(
        Campaign $campaign,
        string $method,
        ?float $amount = null,
        ?string $ownerId = null,
        ?string $idempotencyKey = null,
    ): Payment {
        return DB::transaction(function () use ($campaign, $method, $amount, $ownerId, $idempotencyKey) {
            // Khóa chiến dịch để hai yêu cầu cùng lúc phải xếp hàng.
            //
            // Không khóa thì hai người trong cùng tổ chức, hai phiên khác nhau,
            // cùng bấm trả toàn bộ cho một owner: cả hai đều thấy chưa có khoản
            // chờ, cùng tính ra một số nợ, và cùng tạo payment. Ràng buộc duy
            // nhất của số hóa đơn không ngăn được chuyện đó vì hai số hóa đơn
            // vẫn khác nhau — thứ bị nhân đôi là NGHĨA VỤ (Codex R08).
            Campaign::withoutGlobalScopes()->whereKey($campaign->getKey())->lockForUpdate()->first();

            if ($idempotencyKey) {
                $existing = Payment::where('idempotency_key', $idempotencyKey)->first();

                if ($existing) {
                    return $existing;
                }
            }

            // Khoản chờ của cùng owner trong cùng chiến dịch: lần bấm thứ hai
            // nhận lại đúng khoản đó thay vì sinh thêm một dòng công nợ ma.
            $pending = Payment::where('campaign_id', $campaign->id)
                ->where('owner_id', $ownerId)
                ->whereIn('status', ['pending', 'processing'])
                ->first();

            if ($pending) {
                return $pending;
            }

            $outstanding = $this->outstandingForOwner($campaign, $ownerId);

            if ($outstanding <= 0) {
                throw new HttpException(422, 'Khoản này đã được thanh toán đủ, không cần tạo thêm.');
            }

            $requested = $amount !== null ? (int) round($amount) : $outstanding;

            if ($requested <= 0) {
                throw new HttpException(422, 'Số tiền thanh toán phải lớn hơn 0.');
            }

            if ($requested > $outstanding) {
                throw new HttpException(422, sprintf(
                    'Số tiền vượt quá phần còn nợ (%s ₫).',
                    number_format($outstanding, 0, ',', '.'),
                ));
            }

            $payment = $this->createWithUniqueInvoice($idempotencyKey, [
                'campaign_id'     => $campaign->id,
                'organization_id' => $campaign->organization_id,
                'owner_id'        => $ownerId,
                'amount'          => $requested,
                'currency'        => $campaign->currency ?? 'VND',
                'method'          => $method,
                'transaction_ref' => $this->generateTransactionRef(),
                'idempotency_key' => $idempotencyKey,
                'status'          => 'pending',
                'due_date'        => now()->addDays($campaign->organization?->payment_terms_days ?? 30),
            ]);

            CampaignActivity::log(
                $campaign,
                'payment_created',
                "Payment được tạo: " . number_format($payment->amount, 0, ',', '.') . " ₫ via " . $method,
                auth()->id()
            );

            return $payment;
        });
    }

    /**
     * Phần một media owner còn phải nhận trong chiến dịch, đã gồm VAT.
     *
     * Khoản đang chờ xác nhận cũng bị trừ ra: nếu không, khách bấm tạo khoản
     * thanh toán nhiều lần sẽ tạo ra tổng công nợ lớn hơn giá trị đơn hàng.
     */
    public function outstandingForOwner(Campaign $campaign, ?string $ownerId): int
    {
        // ĐỌC THẲNG từ breakdownByOwner, không tự tính lại.
        //
        // Vòng trước tôi đổi `CancellationService` và `breakdownByOwner` sang
        // `refunds.paid_amount` rồi viết trong phản hồi là "ba nơi cùng một
        // nguồn". Sai: hàm này vẫn cộng `refunds.amount` và bỏ qua bản ghi
        // `waived` — mà nó chính là hàm `createPayment` gọi để cho phép hay từ
        // chối trả thêm (Codex R34).
        //
        // Hệ quả cụ thể: trả một nửa rồi hủy một dòng ở mức 50% thì công nợ
        // báo 270.000 trong khi dòng còn sống thiếu 540.000 — biểu mẫu gửi
        // đúng con số 540.000 và bị 422 "vượt quá phần còn nợ". Hủy ở mức 0%
        // còn tệ hơn: công nợ về 0 ngay, không trả nốt được.
        //
        // Nên nay không còn "ba nơi cùng một nguồn" như một lời hứa; chỉ còn
        // MỘT phép tính, hai nơi kia gọi vào nó.
        if ($ownerId !== null) {
            $row = $this->breakdownByOwner($campaign)
                ->first(fn ($r) => $r['owner']?->id === $ownerId);

            return $row ? (int) $row['remaining'] : 0;
        }

        // Phần chưa gắn owner (dữ liệu trước khi thanh toán theo owner) không
        // có trong breakdown, nên tính riêng — cùng công thức.
        $cost = (int) round((float) $campaign->bookingLines()
            ->whereIn('status', ['approved', 'active', 'completed'])
            ->whereNull('owner_id')
            ->sum('estimated_cost'));

        $counted = (int) round((float) $campaign->payments()
            ->whereNull('owner_id')
            ->whereIn('status', ['completed', 'pending', 'processing'])
            ->sum('amount'));

        return max(0, $this->withVat($cost) - $counted);
    }

    /**
     * Confirm a bank transfer payment (admin/manual action).
     */
    public function confirmBankTransfer(Payment $payment, ?string $gatewayRef = null): Payment
    {
        abort_unless($payment->isPending() || $payment->status === 'processing', 422, 'Payment không ở trạng thái chờ');

        $payment->update([
            'status'      => 'completed',
            'gateway_ref' => $gatewayRef,
            'paid_at'     => now(),
        ]);

        CampaignActivity::log(
            $payment->campaign,
            'payment_received',
            "Thanh toán " . number_format($payment->amount, 0, ',', '.') . " ₫ đã được xác nhận",
            auth()->id()
        );

        // Tiền vào muộn hơn lệnh hủy thì nghĩa vụ hoàn tiền phải được tính
        // lại (Codex R36).
        //
        // Người mua hủy được trong khi khoản chuyển của họ còn `pending`. Lúc
        // đó chưa có đồng nào `completed` nên nghĩa vụ hoàn ghi bằng 0. Nếu
        // không đối soát ở đây thì sau khi xác nhận, tiền thật đã vào mà
        // không có nghĩa vụ hoàn nào — và người mua không hủy lại được để
        // tính lại.
        app(CancellationService::class)->reconcileAfterPayment($payment->campaign, $payment->owner_id);

        // Auto-activate campaign if fully paid
        $this->checkAndActivate($payment->campaign);

        return $payment->fresh();
    }

    /**
     * Mark payment as failed.
     */
    public function markFailed(Payment $payment, ?string $reason = null): Payment
    {
        $payment->update([
            'status' => 'failed',
            'notes'  => $reason,
        ]);

        CampaignActivity::log(
            $payment->campaign,
            'payment_failed',
            "Thanh toán thất bại" . ($reason ? ": $reason" : ''),
            auth()->id()
        );

        return $payment->fresh();
    }

    /**
     * Kích hoạt phần đã đủ tiền — **theo từng media owner**.
     *
     * Chốt 29/09/2026: mỗi owner là một quan hệ mua bán riêng, nên owner nào đã
     * nhận đủ tiền thì các dòng của owner đó chạy, không phải chờ owner khác.
     * Trước đây cả chiến dịch chỉ chạy khi TỔNG tiền đủ, nên một owner chậm xác
     * nhận là cả chiến dịch đứng — và tiền của những owner đã nhận thì nằm im.
     *
     * Điều kiện thứ hai: **nội dung phải được duyệt**. Trả tiền xong mà mẫu
     * quảng cáo chưa duyệt thì vẫn chưa được lên sóng (xem CreativeGate).
     */
    public function checkAndActivate(Campaign $campaign): void
    {
        if (! in_array($campaign->status, [Campaign::STATUS_APPROVED, Campaign::STATUS_ACTIVE])) {
            return;
        }

        $gate = app(CreativeGate::class);

        // Danh sách dòng CÒN THIẾU nội dung, tính một lần rồi lọc theo owner.
        //
        // Trước đây cổng nội dung xét cả chiến dịch: owner A có nội dung đã
        // duyệt và đã trả đủ tiền vẫn không được chạy chỉ vì owner B chưa nộp
        // mẫu quảng cáo. Trái hẳn quyết định "chạy theo từng dòng" (Codex R11).
        $blockedLineIds = $gate->linesMissingApprovedCreative($campaign)->pluck('id');

        $activatedOwners = [];
        $blockedOwners   = [];

        foreach ($this->breakdownByOwner($campaign) as $row) {
            if (! $row['is_paid'] || ! $row['owner']) {
                continue;
            }

            $ownerLines = $campaign->bookingLines()
                ->where('owner_id', $row['owner']->id)
                ->where('status', 'approved');

            if ((clone $ownerLines)->whereIn('id', $blockedLineIds)->exists()) {
                $blockedOwners[] = $row['owner']->name;
                continue;
            }

            $updated = $ownerLines->update(['status' => 'active']);

            if ($updated > 0) {
                $activatedOwners[] = $row['owner']->name;
            }
        }

        if ($blockedOwners !== []) {
            CampaignActivity::log(
                $campaign,
                'activation_blocked',
                'Đã đủ tiền nhưng chưa lên sóng vì thiếu nội dung đã duyệt: ' . implode(', ', $blockedOwners),
            );
        }

        if ($activatedOwners !== []) {
            CampaignActivity::log(
                $campaign,
                'lines_activated',
                'Đã kích hoạt dòng đặt chỗ của: ' . implode(', ', $activatedOwners),
            );
        }

        // Chiến dịch coi là đang chạy khi có ít nhất một dòng chạy.
        $hasActiveLine = $campaign->bookingLines()->where('status', 'active')->exists();

        if ($hasActiveLine && $campaign->status === Campaign::STATUS_APPROVED) {
            $campaign->update([
                'status'       => Campaign::STATUS_ACTIVE,
                'activated_at' => now(),
            ]);

            CampaignActivity::log($campaign, 'activated', 'Campaign chuyển sang đang chạy');
        }
    }

    /**
     * Get payment summary for a campaign.
     */
    public function getSummary(Campaign $campaign): array
    {
        $totalCost = $campaign->bookingLines()
            ->whereIn('status', ['approved', 'active', 'completed'])
            ->sum('estimated_cost');

        // Tổng kết phải cộng lại từ CHÍNH các dòng của breakdownByOwner.
        //
        // Trước đây hàm này tự tính lại: nhân VAT dạng số thực và cộng toàn bộ
        // payment `completed` mà không trừ nghĩa vụ hoàn tiền. Hai hệ quả thật,
        // vì giao diện dùng `is_fully_paid` để quyết định có hiện biểu mẫu
        // thanh toán hay không (Codex R23):
        //
        //  - Sau khi hoàn tiền, tổng kết vẫn thấy "đã trả đủ" nên **ẩn** biểu
        //    mẫu, và người mua không còn đường trả phần còn thiếu.
        //  - Giá lẻ 1.000.001 đồng: trả đúng 1.080.001 mà tổng kết vẫn thấy
        //    thiếu 0,08 đồng — đúng lỗi R09 mà tôi mới chỉ sửa một nửa.
        $rows = $this->breakdownByOwner($campaign);

        $due     = (int) $rows->sum('total');
        $paid    = (int) $rows->sum('paid');
        $pending = (int) $rows->sum('pending');
        $cost    = (int) $rows->sum('cost');

        // Còn thiếu = CỘNG phần thiếu của TỪNG owner, không phải hiệu của hai
        // tổng.
        //
        // Sàn không thu hộ: người mua chuyển thẳng cho từng media owner, nên
        // tiền dư của owner X không trả nợ cho owner Y được. So hai tổng thì
        // `sum(max(0, due−paid))` bị thay bằng `max(0, sum(due)−sum(paid))`, và
        // hai biểu thức đó khác nhau ngay khi có một owner dư tiền: owner Y
        // chưa nhận đồng nào vẫn hiện ra "đã trả đủ", trang thanh toán ẩn hết
        // biểu mẫu, và người mua không trả được cho Y (Codex R31).
        $remainingPerOwner = (int) $rows->sum(fn ($r) => (int) $r['remaining']);
        $allOwnersPaid     = $rows->every(fn ($r) => (bool) $r['is_paid']);

        // Dòng chưa gắn owner (dữ liệu cũ, trước khi thanh toán theo owner) vẫn
        // phải hiện trong tổng chi phí.
        $unassignedCost = (int) round((float) $campaign->bookingLines()
            ->whereIn('status', ['approved', 'active', 'completed'])
            ->whereNull('owner_id')
            ->sum('estimated_cost'));

        if ($unassignedCost > 0) {
            $unassignedDue = $this->withVat($unassignedCost);

            $cost += $unassignedCost;
            $due  += $unassignedDue;

            // Phần chưa gắn owner (dữ liệu trước khi thanh toán theo owner)
            // cũng là một nghĩa vụ riêng, cộng vào phần còn thiếu.
            $unassignedPaid = (int) round((float) $campaign->payments()
                ->whereNull('owner_id')
                ->where('status', 'completed')
                ->sum('amount'));

            $remainingPerOwner += max(0, $unassignedDue - $unassignedPaid);
            $allOwnersPaid = $allOwnersPaid && $unassignedPaid >= $unassignedDue;
        }

        return [
            'total_cost'     => (float) $cost,
            'total_cost_vat' => (float) $due,
            'vat'            => (float) ($due - $cost),
            'total_paid'     => (float) $paid,
            'refunded'       => (float) $rows->sum('refunded'),
            'pending'        => (float) $pending,
            'remaining'      => (float) $remainingPerOwner,
            // Đủ tiền nghĩa là MỌI nghĩa vụ theo owner đều đủ, không phải tổng
            // thu bằng tổng phải thu.
            'is_fully_paid'  => $due > 0 && $remainingPerOwner === 0 && $allOwnersPaid,
        ];
    }

    /**
     * Số tiền phải trả cho từng media owner trong campaign.
     *
     * Sàn không thu hộ: người mua chuyển thẳng cho từng media owner, nên một
     * campaign gồm màn hình của ba owner sinh ra ba lần chuyển khoản. Hàm này là
     * nguồn duy nhất cho việc chia số tiền đó — trang thanh toán và phần đối soát
     * phải đọc cùng một chỗ, nếu không hai bên sẽ trôi ra khỏi nhau.
     *
     * @return Collection<int, array{owner: Owner, cost: float, vat: float, total: float, paid: float, remaining: float, is_paid: bool}>
     */
    public function breakdownByOwner(Campaign $campaign): Collection
    {
        $costs = $campaign->bookingLines()
            ->whereIn('status', ['approved', 'active', 'completed'])
            ->selectRaw('owner_id, SUM(estimated_cost) as cost')
            ->groupBy('owner_id')
            ->pluck('cost', 'owner_id');

        if ($costs->isEmpty()) {
            return collect();
        }

        $owners = Owner::whereIn('id', $costs->keys())->get()->keyBy('id');

        // Tiền đã nhận và tiền đang chờ xác nhận là HAI con số khác nhau.
        // Gộp chung là cách cũ, và nó khiến owner hiện ra "đã nhận đủ" ngay khi
        // người mua bấm nút, trước khi một đồng nào thực sự chuyển đi.
        $paidByOwner = $campaign->payments()
            ->where('status', 'completed')
            ->selectRaw('owner_id, SUM(amount) as paid')
            ->groupBy('owner_id')
            ->pluck('paid', 'owner_id');

        $pendingByOwner = $campaign->payments()
            ->whereIn('status', ['pending', 'processing'])
            ->selectRaw('owner_id, SUM(amount) as pending')
            ->groupBy('owner_id')
            ->pluck('pending', 'owner_id');

        // Tiền đã phân bổ cho các dòng ĐÃ HỦY không còn là tiền trả cho các
        // dòng còn lại.
        //
        // Trước đây mọi payment `completed` đều được cộng vào phần "đã trả" kể
        // cả khi dòng tương ứng đã hủy (Codex R12). Sửa lần đầu trừ
        // `refunds.amount`, nhưng như vậy vẫn để phần **giữ lại theo chính
        // sách** (phí hủy) tính là tiền trả cho dòng khác — đúng nguồn nuôi lỗi
        // bù chéo ở R30/R31. Nay trừ `paid_amount`: toàn bộ phần đã phân bổ
        // cho dòng đã hủy, gồm cả phần hoàn và phần giữ lại.
        //
        // `CancellationService` dùng đúng cột này, nên ba nơi — phân bổ khi
        // hủy, công nợ, và tổng kết — cùng một nguồn.
        $allocatedToCancelled = Refund::where('campaign_id', $campaign->id)
            ->whereIn('status', [Refund::STATUS_PENDING, Refund::STATUS_SETTLED, Refund::STATUS_WAIVED])
            ->selectRaw('owner_id, SUM(paid_amount) as allocated, SUM(amount) as refunded')
            ->groupBy('owner_id')
            ->get()
            ->keyBy('owner_id');

        return $costs->map(function ($cost, $ownerId) use ($owners, $paidByOwner, $pendingByOwner, $allocatedToCancelled) {
            // Tiền tính bằng VND nguyên ở MỌI phép so sánh.
            //
            // Trước đây `outstandingForOwner` làm tròn về số nguyên còn chỗ này
            // giữ số lẻ, nên với giá 1.000.001 đồng thì công nợ về 0 mà
            // `is_paid` vẫn false — trả thêm cũng không được vì hệ thống bảo
            // không còn nợ. Chiến dịch kẹt vĩnh viễn (Codex R09).
            $cost     = (int) round((float) $cost);
            $total    = $this->withVat($cost);
            $vat      = $total - $cost;
            $paid     = (int) round((float) ($paidByOwner[$ownerId] ?? 0));
            $pending  = (int) round((float) ($pendingByOwner[$ownerId] ?? 0));

            $row       = $allocatedToCancelled[$ownerId] ?? null;
            $allocated = (int) round((float) ($row->allocated ?? 0));
            $refunded  = (int) round((float) ($row->refunded ?? 0));

            $netPaid = max(0, $paid - $allocated);

            return [
                'owner'     => $owners[$ownerId] ?? null,
                'cost'      => (float) $cost,
                'vat'       => (float) $vat,
                'total'     => (float) $total,
                'paid'      => (float) $netPaid,
                'paid_gross'=> (float) $paid,
                'refunded'  => (float) $refunded,
                'pending'   => (float) $pending,
                'remaining' => (float) max(0, $total - $netPaid - $pending),
                'is_paid'   => $netPaid >= $total,
            ];
        })->filter(fn ($row) => $row['owner'] !== null)->values();
    }

    /**
     * Số tiền đã gồm VAT, tính bằng VND nguyên.
     *
     * Một chỗ duy nhất làm phép này. Hai chỗ làm tròn khác nhau là cách sinh ra
     * "công nợ bằng 0 nhưng chưa trả đủ".
     */
    public function withVat(int $amountVnd): int
    {
        return (int) round($amountVnd * (1 + $this->vatRate()));
    }

    private function generateTransactionRef(): string
    {
        return 'TXN-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }

    /**
     * Số hóa đơn kế tiếp trong tháng.
     *
     * "Đọc số lớn nhất rồi cộng một" là một cuộc đua: hai người bấm cùng lúc
     * cùng đọc ra một số. Ràng buộc duy nhất ở tầng CSDL mới là thứ chặn thật
     * (xem migration harden_payments_table); hàm này chỉ cần nhường và thử lại
     * khi đụng nhau.
     *
     * Đếm theo số dòng thay vì cắt bốn ký tự cuối: cách cũ hỏng từ hóa đơn thứ
     * 10.000 trở đi.
     */
    private function generateInvoiceNumber(int $attempt = 0): string
    {
        $prefix = 'INV-' . now()->format('Ym') . '-';

        // Sắp theo GIÁ TRỊ SỐ của phần hậu tố, không theo chuỗi.
        //
        // Sắp theo chuỗi thì "…-9999" đứng trên "…-10000" (ký tự '9' > '1'),
        // nên từ hóa đơn thứ 10.000 trở đi hàm luôn đọc ra 9999, thử lại năm
        // số đã tồn tại rồi trả 503 — không cấp được hóa đơn nào nữa
        // (Codex R20).
        $last = Payment::where('invoice_number', 'like', $prefix . '%')
            ->orderByRaw('CAST(SUBSTRING(invoice_number, ?) AS UNSIGNED) DESC', [strlen($prefix) + 1])
            ->value('invoice_number');

        $num = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        // str_pad chỉ đệm, không cắt: số vượt bốn chữ số vẫn ra đủ.
        return $prefix . str_pad((string) ($num + $attempt), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Ghi khoản thanh toán, nhường số hóa đơn nếu vừa bị người khác lấy mất.
     *
     * @throws UniqueConstraintViolationException khi thử hết số lần vẫn đụng
     */
    private function createWithUniqueInvoice(?string $idempotencyKey, array $attributes, int $tries = 5): Payment
    {
        for ($attempt = 0; $attempt < $tries; $attempt++) {
            try {
                return Payment::create($attributes + [
                    'invoice_number' => $this->generateInvoiceNumber($attempt),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Đụng khóa chống trùng nghĩa là một yêu cầu song song vừa tạo
                // xong đúng khoản này. Trả lại khoản đó chứ không ném lỗi ra
                // mặt người dùng — đây chính là việc mà khóa sinh ra để làm
                // (Codex R08).
                if ($idempotencyKey && str_contains($e->getMessage(), 'idempotency_key')) {
                    $existing = Payment::where('idempotency_key', $idempotencyKey)->first();

                    if ($existing) {
                        return $existing;
                    }
                }

                if (! str_contains($e->getMessage(), 'invoice_number')) {
                    throw $e;
                }
                // vòng sau lấy số kế tiếp
            }
        }

        throw new HttpException(503, 'Không cấp được số hóa đơn, vui lòng thử lại.');
    }
}
