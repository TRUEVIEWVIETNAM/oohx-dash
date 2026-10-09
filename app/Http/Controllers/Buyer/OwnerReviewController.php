<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Owner;
use App\Services\OwnerReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Người mua đánh giá media owner sau khi booking (review mục 6).
 */
class OwnerReviewController extends Controller
{
    public function __construct(private readonly OwnerReviewService $reviews) {}

    public function store(Request $request, Campaign $campaign): RedirectResponse
    {
        // Policy, KHÔNG so `current_organization_id` — xem
        // `CampaignPolicy::review()`.
        abort_unless(
            $request->user()?->can('review', $campaign) ?? false,
            403,
            'Bạn không có quyền đánh giá campaign này'
        );

        $data = $request->validate([
            'owner_id' => ['required', 'string', 'exists:owners,id'],
            'rating'   => ['required', 'integer', 'min:1', 'max:5'],
            'comment'  => ['nullable', 'string', 'max:2000'],
        ], [
            'rating.required' => 'Vui lòng chọn số sao.',
        ]);

        try {
            $this->reviews->submit(
                $campaign,
                Owner::findOrFail($data['owner_id']),
                $request->user(),
                $data['rating'],
                $data['comment'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['rating' => $e->getMessage()]);
        }

        return back()->with('success', 'Cảm ơn bạn. Đánh giá sẽ hiển thị sau khi được kiểm duyệt.');
    }
}
