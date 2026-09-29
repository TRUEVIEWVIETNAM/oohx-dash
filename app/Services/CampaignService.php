<?php

namespace App\Services;

use App\Models\BookingLine;
use App\Models\BookingLineBundle;
use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Organization;
use App\Models\Screen;
use App\Models\User;
use App\Services\Booking\BundleExpander;
use App\Services\InventoryHoldService;
use App\Services\PurchaseEligibilityService;
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

            // Owner có thể bị tạm ngưng trong lúc giỏ nằm đó — kiểm lại trước khi ghi.
            $eligibility = app(PurchaseEligibilityService::class);
            foreach ($items as $cartItem) {
                if ($cartItem->screen) {
                    $eligibility->assertScreenPurchasable($cartItem->screen);
                }
            }

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
                // Điền lại sau khi mở gói: một dòng giỏ thuộc gói sinh ra nhiều
                // dòng đặt chỗ, nên đếm số dòng giỏ là đếm sai số màn hình.
                'total_screens'               => 0,
                'total_impressions_estimated' => 0,
                'status'                      => Campaign::STATUS_DRAFT,
                'notes'                       => $data['notes'] ?? null,
            ]);

            // Convert cart items → booking lines (freeze pricing at booking time)
            $lineCount = 0;
            foreach ($items as $item) {
                $lineCount += $item->product_id
                    ? count($this->createBundleLines($campaign, $item))
                    : (int) (bool) $this->createScreenLine($campaign, $item);
            }

            $campaign->update([
                'total_screens'               => $lineCount,
                'total_impressions_estimated' => (int) $campaign->bookingLines()->sum('estimated_impressions'),
            ]);

            // Mark cart as converted
            $cart->update(['status' => 'converted']);

            CampaignActivity::log($campaign, 'created', 'Campaign được tạo từ plan với ' . $lineCount . ' màn hình', $user->id);

            return $campaign;
        });
    }

    /**
     * Một dòng giỏ mua màn hình lẻ → một dòng đặt chỗ.
     */
    private function createScreenLine(Campaign $campaign, CartItem $item): ?BookingLine
    {
        if (! $item->screen) {
            return null;
        }

        $line = BookingLine::create($this->linePayload($campaign, $item, $item->screen, [
            'estimated_cost'        => (int) round((float) $item->estimated_cost),
            'estimated_impressions' => (int) $item->estimated_impressions,
            'booked_cpms'           => $item->booked_cpms,
            'screen_count'          => $item->screen_count ?? 1,
        ]));

        // Suất giữ tạm trong giỏ thành suất của dòng đặt chỗ. Nếu giữ chỗ đã hết
        // hạn và người khác đã lấy suất thì ném 422 ở đây, cả đơn bị hủy.
        app(InventoryHoldService::class)->consumeForBookingLine($line, $item);

        return $line;
    }

    /**
     * Một dòng giỏ thuộc sản phẩm → N dòng đặt chỗ, mỗi màn hình một dòng.
     *
     * Trước đây gói thu về **một** dòng trỏ vào màn hình đầu tiên: SOV chỉ bị
     * trừ ở một chỗ nên các màn hình còn lại vẫn bán tiếp, và toàn bộ tiền ghi
     * cho owner của màn hình đầu. Xem `BundleExpander` để biết cách chia tiền.
     *
     * @return array<int, BookingLine>
     */
    private function createBundleLines(Campaign $campaign, CartItem $item): array
    {
        $expander = app(BundleExpander::class);

        // Đọc lại thành phần gói từ sản phẩm, không tin danh sách đã lưu trong
        // giỏ: owner có thể đã gỡ một màn hình khỏi gói từ lúc khách thêm giỏ.
        // Màn hình không còn bán được thì cổng bán hàng ném 422 ở đây.
        $product = app(PurchaseEligibilityService::class)->findPurchasableProduct($item->product_id);
        $screens = $expander->resolveScreens($item, $product);
        $buyMode = $expander->buyModeOf($item);

        $totalVnd  = (int) round((float) $item->estimated_cost);
        $unitPrice = (int) round((float) ($product->individual_price ?: $product->floor_price));

        $split = $expander->splitCost($totalVnd, $screens, $buyMode, $unitPrice);

        $snapshotScreens = [];
        $lines = [];

        foreach ($screens->values() as $i => $screen) {
            $amount      = (int) ($split['amounts'][$i] ?? 0);
            $impressions = (int) ($screen->inventory?->weekly_impressions ?? 0);

            $snapshotScreens[] = [
                'screen_id'   => $screen->id,
                'screen_name' => $screen->name,
                'owner_id'    => $screen->owner_id,
                'weight'      => (int) ($split['weights'][$i] ?? 0),
                'amount_vnd'  => $amount,
            ];

            $lines[] = [
                'screen'      => $screen,
                'amount'      => $amount,
                'impressions' => $impressions,
            ];
        }

        $bundle = BookingLineBundle::create([
            'campaign_id' => $campaign->id,
            'product_id'  => $product->id,
            'buy_mode'    => $buyMode,
            'price_total' => $totalVnd,
            'snapshot'    => [
                'product_id'      => $product->id,
                'product_name'    => $product->name,
                'listing_mode'    => $product->listing_mode,
                'buy_mode'        => $buyMode,
                'price_total_vnd' => $totalVnd,
                'split_method'    => $split['method'],
                'screens'         => $snapshotScreens,
                'captured_at'     => now()->toIso8601String(),
            ],
        ]);

        $holds = app(InventoryHoldService::class);

        $created = [];
        foreach ($lines as $line) {
            $bookingLine = BookingLine::create($this->linePayload($campaign, $item, $line['screen'], [
                'product_id'            => $product->id,
                'bundle_id'             => $bundle->id,
                'estimated_cost'        => $line['amount'],
                'estimated_impressions' => $line['impressions'],
                // Gói tính theo giá gói, không theo số CPM của từng màn hình.
                'booked_cpms'           => null,
                'screen_count'          => 1,
            ]));

            // Mỗi màn hình trong gói có giữ chỗ riêng từ lúc thêm giỏ; chuyển
            // từng cái thành suất của dòng tương ứng.
            $holds->consumeForBookingLine($bookingLine, $item);

            $created[] = $bookingLine;
        }

        return $created;
    }

    /**
     * Phần chung của một dòng đặt chỗ: ngày, SOV, và ảnh chụp giá lúc đặt.
     *
     * Giá được đóng băng ở đây theo kho của **chính màn hình đó**, không phải
     * màn hình đầu tiên của gói.
     */
    private function linePayload(Campaign $campaign, CartItem $item, Screen $screen, array $overrides): array
    {
        $inv = $screen->inventory;
        $pricingModel = $item->pricing_model ?? $inv?->pricing_model ?? 'io';

        if ($pricingModel === 'both') {
            $pricingModel = 'io';
        }

        return array_merge([
            'campaign_id'           => $campaign->id,
            'screen_id'             => $screen->id,
            'owner_id'              => $screen->owner_id,
            'start_date'            => $item->start_date,
            'end_date'              => $item->end_date,
            'spot_length'           => $item->spot_length,
            'share_of_voice_pct'    => $item->share_of_voice_pct,
            'floor_cpm_at_booking'  => $inv?->floor_cpm ?? 0,
            'status'                => 'pending',
            'pricing_model'         => $pricingModel,
            'io_rate_at_booking'    => $pricingModel === 'io' ? ($inv?->io_rate ?? 0) : null,
            'io_rate_unit'          => $pricingModel === 'io' ? ($inv?->io_rate_unit ?? 'month') : null,
            'kpi_spots_per_day'     => $pricingModel === 'io' ? $inv?->io_kpi_spots_per_day : null,
        ], $overrides);
    }

    /**
     * Submit campaign for owner approval.
     */
    public function submit(Campaign $campaign, User $user): Campaign
    {
        abort_unless($campaign->isDraft(), 422, 'Campaign không ở trạng thái nháp');

        // Kiểm lại lần cuối trước khi gửi cho media owner: giữa lúc tạo nháp và
        // lúc gửi, owner có thể đã bị tạm ngưng hoặc màn hình đã bị gỡ bán.
        $this->assertLinesStillPurchasable($campaign);

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
     * Mọi màn hình trong chiến dịch còn bán được không.
     *
     * Cổng bán hàng trước đây chỉ chặn ở bước thêm giỏ. Giữa thêm giỏ và gửi
     * booking có thể cách nhau nhiều ngày — đủ để owner bị tạm ngưng hoặc màn
     * hình bị tắt (audit F10, Codex R05: eligibility phải kiểm ở mọi chuyển
     * trạng thái, không chỉ lúc thêm).
     */
    private function assertLinesStillPurchasable(Campaign $campaign): void
    {
        $eligibility = app(PurchaseEligibilityService::class);

        $lines = $campaign->bookingLines()->with('screen')->get();

        foreach ($lines as $line) {
            if ($line->screen) {
                $eligibility->assertScreenPurchasable($line->screen);
            }
        }
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
