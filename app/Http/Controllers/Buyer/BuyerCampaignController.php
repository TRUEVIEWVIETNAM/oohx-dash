<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BuyerCampaignController extends Controller
{
    public function __construct(private readonly \App\Services\OwnerReviewService $reviews) {}

    public function index(Request $request): View
    {
        $org = $request->user()->currentOrganization;

        $campaigns = $org->campaigns()
            ->latest()
            ->paginate(20);

        return view('buyer.dashboard.campaigns', [
            'campaigns' => $campaigns,
        ]);
    }

    public function show(Request $request, Campaign $campaign): View
    {
        abort_unless(
            $campaign->organization_id === $request->user()->current_organization_id,
            403
        );

        $campaign->load([
            'bookingLines.screen.spec',
            'bookingLines.screen.owner',
            'bookingLines.screen.site',
            'creatives',
            'payments',
            'activities',
        ]);

        return view('buyer.dashboard.campaign-detail', [
            'campaign'         => $campaign,
            'cancelQuotes'     => $this->cancelQuotes($campaign, $request),
            'reviewableOwners' => $this->reviews->reviewableOwners($campaign),
            'myReviews'        => \App\Models\OwnerReview::where('campaign_id', $campaign->id)
                ->with('owner:id,name')
                ->get(),
        ]);
    }

    /**
     * Số tiền hoàn cho từng dòng còn hủy được, **tính ở máy chủ**.
     *
     * Người mua phải thấy con số trước khi bấm, không phải sau. Và con số đó
     * không được sinh ở trình duyệt: số tiền do máy chủ tính (CLAUDE.md mục 5),
     * còn Blade chỉ hiển thị lại.
     *
     * `quote()` không thay đổi gì nên gọi ở trang xem là an toàn. Trả mảng rỗng
     * khi người xem không có quyền hủy, để giao diện khớp với quyền — việc chặn
     * thật vẫn nằm ở controller hủy.
     *
     * Đây là **ảnh chụp tại thời điểm xem**: tiền đã trả được phân bổ theo các
     * dòng còn mở, nên hủy dòng A xong thì báo giá của dòng B đổi. Con số quyết
     * định là con số `cancelLine()` tính lại trong transaction, không phải con
     * số đang hiển thị — nên giao diện nói rõ "dự kiến".
     *
     * @return array<string, array{days_before: int, refund_pct: int, paid: int, refundable: int, tier: array}>
     */
    private function cancelQuotes(Campaign $campaign, Request $request): array
    {
        if (! ($request->user()?->can('cancel', $campaign) ?? false)) {
            return [];
        }

        $cancellations = app(\App\Services\Booking\CancellationService::class);

        return $campaign->bookingLines
            ->reject(fn ($line) => in_array($line->status, ['cancelled', 'completed', 'rejected'], true))
            ->mapWithKeys(function ($line) use ($campaign, $cancellations) {
                // Gắn sẵn quan hệ: `quote()` đọc `$line->campaign`, không gắn
                // thì mỗi dòng nạp lại chiến dịch một lần.
                $line->setRelation('campaign', $campaign);

                return [$line->id => $cancellations->quote($line)];
            })
            ->all();
    }
}
