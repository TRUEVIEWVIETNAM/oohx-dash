<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\CampaignReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BuyerReportController extends Controller
{
    public function __construct(private CampaignReportService $reportService) {}

    public function show(Request $request, Campaign $campaign): View
    {
        // Policy, KHÔNG so `current_organization_id`. Xem
        // `CampaignPolicy::viewReports()` để biết khe cũ là gì.
        //
        // `->can()` chứ không `$this->authorize()`: lớp `Controller` gốc của dự
        // án không dùng trait `AuthorizesRequests`, và các controller khu người
        // mua đều gọi theo cách này.
        abort_unless($request->user()?->can('viewReports', $campaign) ?? false, 403);

        abort_unless(
            in_array($campaign->status, ['active', 'completed', 'paused']),
            404,
            'Báo cáo chỉ khả dụng cho campaign đang chạy hoặc đã hoàn thành'
        );

        $overview = $this->reportService->getOverview($campaign);
        $dailyData = $this->reportService->getDailyImpressions($campaign);
        $breakdown = $this->reportService->getScreenBreakdown($campaign);

        return view('buyer.dashboard.report', [
            'campaign'  => $campaign,
            'overview'  => $overview,
            'dailyData' => $dailyData,
            'breakdown' => $breakdown,
        ]);
    }
}
