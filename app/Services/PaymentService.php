<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\Owner;
use App\Models\Payment;
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

            $payment = $this->createWithUniqueInvoice([
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
        $cost = (float) $campaign->bookingLines()
            ->whereIn('status', ['approved', 'active', 'completed'])
            ->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->sum('estimated_cost');

        $due = (int) round($cost * (1 + $this->vatRate()));

        $counted = (int) round((float) $campaign->payments()
            ->where('owner_id', $ownerId)
            ->whereIn('status', ['completed', 'pending', 'processing'])
            ->sum('amount'));

        return max(0, $due - $counted);
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

        if (! $gate->isReadyToAir($campaign)) {
            CampaignActivity::log(
                $campaign,
                'activation_blocked',
                'Đã đủ tiền nhưng chưa lên sóng: còn dòng đặt chỗ chưa có nội dung đã duyệt',
            );

            return;
        }

        $activatedOwners = [];

        foreach ($this->breakdownByOwner($campaign) as $row) {
            if (! $row['is_paid'] || ! $row['owner']) {
                continue;
            }

            $updated = $campaign->bookingLines()
                ->where('owner_id', $row['owner']->id)
                ->where('status', 'approved')
                ->update(['status' => 'active']);

            if ($updated > 0) {
                $activatedOwners[] = $row['owner']->name;
            }
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

        $totalPaid = $campaign->payments()
            ->where('status', 'completed')
            ->sum('amount');

        $pending = $campaign->payments()
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');

        return [
            'total_cost'     => (float) $totalCost,
            'total_cost_vat' => (float) ($totalCost * (1 + $this->vatRate())),
            'vat'            => (float) ($totalCost * $this->vatRate()),
            'total_paid'     => (float) $totalPaid,
            'pending'        => (float) $pending,
            'remaining'      => (float) max(0, $totalCost * (1 + $this->vatRate()) - $totalPaid - $pending),
            'is_fully_paid'  => $totalPaid >= ($totalCost * (1 + $this->vatRate())),
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

        return $costs->map(function ($cost, $ownerId) use ($owners, $paidByOwner, $pendingByOwner) {
            $cost    = (float) $cost;
            $vat     = $cost * $this->vatRate();
            $total   = $cost + $vat;
            $paid    = (float) ($paidByOwner[$ownerId] ?? 0);
            $pending = (float) ($pendingByOwner[$ownerId] ?? 0);

            return [
                'owner'     => $owners[$ownerId] ?? null,
                'cost'      => $cost,
                'vat'       => $vat,
                'total'     => $total,
                'paid'      => $paid,
                'pending'   => $pending,
                'remaining' => max(0, $total - $paid - $pending),
                'is_paid'   => $paid >= $total,
            ];
        })->filter(fn ($row) => $row['owner'] !== null)->values();
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

        $last = Payment::where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $num = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) ($num + $attempt), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Ghi khoản thanh toán, nhường số hóa đơn nếu vừa bị người khác lấy mất.
     *
     * @throws UniqueConstraintViolationException khi thử hết số lần vẫn đụng
     */
    private function createWithUniqueInvoice(array $attributes, int $tries = 5): Payment
    {
        for ($attempt = 0; $attempt < $tries; $attempt++) {
            try {
                return Payment::create($attributes + [
                    'invoice_number' => $this->generateInvoiceNumber($attempt),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if (! str_contains($e->getMessage(), 'invoice_number')) {
                    throw $e;
                }
                // vòng sau lấy số kế tiếp
            }
        }

        throw new HttpException(503, 'Không cấp được số hóa đơn, vui lòng thử lại.');
    }
}
