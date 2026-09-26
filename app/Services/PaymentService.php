<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\Owner;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
     * Create a payment record for a campaign.
     */
    public function createPayment(
        Campaign $campaign,
        string $method,
        ?float $amount = null,
        ?string $ownerId = null,
    ): Payment {
        $totalCost = $campaign->bookingLines()
            ->whereIn('status', ['approved', 'active'])
            ->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->sum('estimated_cost');

        $payment = Payment::create([
            'campaign_id'     => $campaign->id,
            'organization_id' => $campaign->organization_id,
            'owner_id'        => $ownerId,
            'amount'          => $amount ?? $totalCost,
            'currency'        => $campaign->currency ?? 'VND',
            'method'          => $method,
            'transaction_ref' => $this->generateTransactionRef(),
            'status'          => 'pending',
            'due_date'        => now()->addDays($campaign->organization?->payment_terms_days ?? 30),
            'invoice_number'  => $this->generateInvoiceNumber(),
        ]);

        CampaignActivity::log(
            $campaign,
            'payment_created',
            "Payment được tạo: " . number_format($payment->amount, 0, ',', '.') . " ₫ via " . $method,
            auth()->id()
        );

        return $payment;
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
     * Check if campaign is fully paid and activate it.
     */
    public function checkAndActivate(Campaign $campaign): void
    {
        if (! in_array($campaign->status, [Campaign::STATUS_APPROVED, Campaign::STATUS_ACTIVE])) {
            return;
        }

        $totalCost = $campaign->bookingLines()
            ->whereIn('status', ['approved', 'active'])
            ->sum('estimated_cost');

        $totalCostWithVat = $totalCost * (1 + $this->vatRate());

        $totalPaid = $campaign->payments()
            ->where('status', 'completed')
            ->sum('amount');

        if ($totalPaid >= $totalCostWithVat && $campaign->status === Campaign::STATUS_APPROVED) {
            $campaign->update([
                'status'       => Campaign::STATUS_ACTIVE,
                'activated_at' => now(),
            ]);

            // Activate approved booking lines
            $campaign->bookingLines()
                ->where('status', 'approved')
                ->update(['status' => 'active']);

            CampaignActivity::log($campaign, 'activated', 'Campaign được kích hoạt sau khi thanh toán đủ');
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

        $paidByOwner = $campaign->payments()
            ->whereIn('status', ['completed', 'pending', 'processing'])
            ->selectRaw('owner_id, SUM(amount) as paid')
            ->groupBy('owner_id')
            ->pluck('paid', 'owner_id');

        return $costs->map(function ($cost, $ownerId) use ($owners, $paidByOwner) {
            $cost  = (float) $cost;
            $vat   = $cost * $this->vatRate();
            $total = $cost + $vat;
            $paid  = (float) ($paidByOwner[$ownerId] ?? 0);

            return [
                'owner'     => $owners[$ownerId] ?? null,
                'cost'      => $cost,
                'vat'       => $vat,
                'total'     => $total,
                'paid'      => $paid,
                'remaining' => max(0, $total - $paid),
                'is_paid'   => $paid >= $total,
            ];
        })->filter(fn ($row) => $row['owner'] !== null)->values();
    }

    private function generateTransactionRef(): string
    {
        return 'TXN-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }

    private function generateInvoiceNumber(): string
    {
        $prefix = 'INV-' . now()->format('Ym') . '-';
        $last = Payment::where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $num = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
}
