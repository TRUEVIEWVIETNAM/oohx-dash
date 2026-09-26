<?php

namespace App\Services;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\Cart;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\BookingResolvedNotification;
use App\Notifications\BookingSubmittedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CampaignService
{
    /**
     * Create campaign from cart items.
     */
    public function createFromCart(Organization $org, User $user, Cart $cart, array $data): Campaign
    {
        return DB::transaction(function () use ($org, $user, $cart, $data) {
            $items = $cart->items()->with(['screen.inventory', 'screen.owner'])->get();

            // Giá trong giỏ phải còn khớp giá hiện hành. Nếu media owner vừa đổi giá,
            // dừng lại để người mua xem con số mới rồi tự quyết — không im lặng lấy
            // giá mới, cũng không giữ giá cũ đã hết hiệu lực (audit F-01, Codex R03).
            $this->assertCartRatesUnchanged($items);

            $campaign = Campaign::create([
                'organization_id'             => $org->id,
                'created_by'                  => $user->id,
                'code'                        => $this->generateCode(),
                'name'                        => $data['name'],
                'brand_name'                  => $data['brand_name'] ?? null,
                'category'                    => $data['category'] ?? null,
                'objectives'                  => $data['objectives'] ?? null,
                'start_date'                  => $items->min('start_date'),
                'end_date'                    => $items->max('end_date'),
                'total_budget'                => $data['total_budget'] ?? null,
                'currency'                    => 'VND',
                'total_screens'               => $items->count(),
                'total_impressions_estimated' => $items->sum('estimated_impressions'),
                'status'                      => Campaign::STATUS_DRAFT,
                'notes'                       => $data['notes'] ?? null,
            ]);

            // Convert cart items → booking lines (freeze pricing at booking time)
            foreach ($items as $item) {
                $inv = $item->screen->inventory;
                $pricingModel = $item->pricing_model ?? $inv?->pricing_model ?? 'io';

                BookingLine::create([
                    'campaign_id'          => $campaign->id,
                    'screen_id'            => $item->screen_id,
                    'owner_id'             => $item->screen->owner_id,
                    'start_date'           => $item->start_date,
                    'end_date'             => $item->end_date,
                    'spot_length'          => $item->spot_length,
                    'share_of_voice_pct'   => $item->share_of_voice_pct,
                    'floor_cpm_at_booking' => $inv?->floor_cpm ?? 0,
                    'estimated_impressions'=> $item->estimated_impressions,
                    'estimated_cost'       => $item->estimated_cost,
                    'status'               => 'pending',
                    // Pricing model freeze
                    'pricing_model'        => $pricingModel,
                    'io_rate_at_booking'   => $pricingModel === 'io' ? ($inv?->io_rate ?? 0) : null,
                    'io_rate_unit'         => $pricingModel === 'io' ? ($inv?->io_rate_unit ?? 'month') : null,
                    'kpi_spots_per_day'    => $pricingModel === 'io' ? $inv?->io_kpi_spots_per_day : null,
                    'booked_cpms'          => $pricingModel === 'cpm' ? $item->booked_cpms : null,
                    'screen_count'         => $item->screen_count ?? 1,
                ]);
            }

            // Mark cart as converted
            $cart->update(['status' => 'converted']);

            CampaignActivity::log($campaign, 'created', 'Campaign được tạo từ plan với ' . $items->count() . ' màn hình', $user->id);

            return $campaign;
        });
    }

    /**
     * Submit campaign for owner approval.
     */
    public function submit(Campaign $campaign, User $user): Campaign
    {
        abort_unless($campaign->isDraft(), 422, 'Campaign không ở trạng thái nháp');

        $campaign->update([
            'status'       => Campaign::STATUS_PENDING,
            'submitted_at' => now(),
        ]);

        CampaignActivity::log($campaign, 'submitted', 'Campaign được gửi chờ duyệt', $user->id);

        // Notify each owner who has booking lines
        $ownerIds = $campaign->bookingLines()->distinct()->pluck('owner_id');
        foreach ($ownerIds as $ownerId) {
            $lineCount = $campaign->bookingLines()->where('owner_id', $ownerId)->count();
            $ownerUsers = User::whereHas('ownerUsers', fn ($q) => $q->where('owner_id', $ownerId))
                ->get();
            foreach ($ownerUsers as $ownerUser) {
                $ownerUser->notify(new BookingSubmittedNotification($campaign, $lineCount));
            }
        }

        return $campaign->fresh();
    }

    /**
     * Giá đã chụp lúc thêm vào giỏ phải còn khớp giá hiện hành của kho.
     *
     * Dòng giỏ cũ chưa có ảnh chụp (tạo trước đợt này) thì bỏ qua kiểm — không
     * hợp thức hoá chúng bằng cách coi như đã khớp, mà chỉ không chặn; chúng sẽ
     * có ảnh chụp ngay lần cập nhật kế tiếp.
     */
    private function assertCartRatesUnchanged(Collection $items): void
    {
        $cart = app(CartService::class);
        $changed = [];

        foreach ($items as $item) {
            if (empty($item->rate_snapshot)) {
                continue;
            }

            // So sánh không phụ thuộc thứ tự khóa: MySQL lưu cột JSON dưới dạng đã
            // chuẩn hoá và trả về với thứ tự khóa khác lúc ghi. Dùng === trực tiếp
            // sẽ báo "giá đã đổi" cho mọi đơn hàng.
            $current  = $cart->rateSnapshot($item->screen?->inventory);
            $snapshot = $item->rate_snapshot;
            ksort($current);
            ksort($snapshot);

            if ($current !== $snapshot) {
                $changed[] = $item->screen?->name ?? $item->screen_id;
            }
        }

        if ($changed !== []) {
            throw new HttpException(409, sprintf(
                'Giá của %s vừa thay đổi. Vui lòng xem lại giỏ hàng trước khi gửi booking.',
                implode(', ', array_map(fn ($n) => "\"{$n}\"", $changed))
            ));
        }
    }

    /**
     * Approve specific booking lines by owner.
     */
    public function approveLines(Campaign $campaign, array $lineIds, User $user): void
    {
        BookingLine::where('campaign_id', $campaign->id)
            ->whereIn('id', $lineIds)
            ->where('status', 'pending')
            ->update([
                'status'      => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

        $approvedCount = count($lineIds);
        CampaignActivity::log($campaign, 'approved', "$approvedCount màn hình được duyệt bởi " . $user->name, $user->id);

        $this->checkAllLinesResolved($campaign);
    }

    /**
     * Reject specific booking lines by owner.
     */
    public function rejectLines(Campaign $campaign, array $lineIds, string $reason, User $user): void
    {
        BookingLine::where('campaign_id', $campaign->id)
            ->whereIn('id', $lineIds)
            ->where('status', 'pending')
            ->update([
                'status'          => 'rejected',
                'rejected_reason' => $reason,
            ]);

        $rejectedCount = count($lineIds);
        CampaignActivity::log($campaign, 'rejected', "$rejectedCount màn hình bị từ chối: $reason", $user->id);

        $this->checkAllLinesResolved($campaign);
    }

    /**
     * Approve ALL pending lines for this owner in one action.
     */
    public function approveAllForOwner(Campaign $campaign, string $ownerId, User $user): int
    {
        $lines = BookingLine::where('campaign_id', $campaign->id)
            ->where('owner_id', $ownerId)
            ->where('status', 'pending')
            ->get();

        if ($lines->isEmpty()) return 0;

        BookingLine::whereIn('id', $lines->pluck('id'))
            ->update([
                'status'      => 'approved',
                'approved_by' => $user->id,
                'approved_at' => now(),
            ]);

        CampaignActivity::log($campaign, 'approved', $lines->count() . " màn hình được duyệt bởi " . $user->name, $user->id);

        $this->checkAllLinesResolved($campaign);

        return $lines->count();
    }

    /**
     * Reject ALL pending lines for this owner in one action.
     */
    public function rejectAllForOwner(Campaign $campaign, string $ownerId, string $reason, User $user): int
    {
        $lines = BookingLine::where('campaign_id', $campaign->id)
            ->where('owner_id', $ownerId)
            ->where('status', 'pending')
            ->get();

        if ($lines->isEmpty()) return 0;

        BookingLine::whereIn('id', $lines->pluck('id'))
            ->update([
                'status'          => 'rejected',
                'rejected_reason' => $reason,
            ]);

        CampaignActivity::log($campaign, 'rejected', $lines->count() . " màn hình bị từ chối: $reason", $user->id);

        $this->checkAllLinesResolved($campaign);

        return $lines->count();
    }

    /**
     * Check if all lines are resolved (approved/rejected) and update campaign status.
     */
    private function checkAllLinesResolved(Campaign $campaign): void
    {
        $pending = $campaign->bookingLines()->where('status', 'pending')->count();
        if ($pending > 0) return;

        $approved = $campaign->bookingLines()->where('status', 'approved')->count();
        $total = $campaign->bookingLines()->count();

        if ($approved > 0) {
            // At least some approved → campaign approved
            $campaign->update([
                'status'      => Campaign::STATUS_APPROVED,
                'approved_at' => now(),
            ]);
            CampaignActivity::log($campaign, 'approved', "Campaign được duyệt ($approved/$total màn hình)");
            $this->notifyBuyer($campaign, 'approved');
        } else {
            // All rejected
            $campaign->update([
                'status'      => Campaign::STATUS_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => 'Tất cả màn hình bị từ chối bởi media owner',
            ]);
            CampaignActivity::log($campaign, 'rejected', 'Campaign bị từ chối — tất cả màn hình bị từ chối');
            $this->notifyBuyer($campaign, 'rejected');
        }
    }

    /**
     * Notify campaign creator about approval/rejection.
     */
    private function notifyBuyer(Campaign $campaign, string $action): void
    {
        $creator = $campaign->createdBy;
        if ($creator) {
            $creator->notify(new BookingResolvedNotification($campaign->fresh(), $action));
        }
    }

    /**
     * Generate unique campaign code: CPN-YYYYMM-XXXX
     */
    public function generateCode(): string
    {
        $prefix = 'CPN-' . now()->format('Ym') . '-';
        $last = Campaign::where('code', 'like', $prefix . '%')
            ->orderByDesc('code')
            ->value('code');

        if ($last) {
            $num = (int) substr($last, -4) + 1;
        } else {
            $num = 1;
        }

        return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
    }
}
