<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\BookingLine;
use App\Models\Campaign;
use App\Services\Booking\CancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Người mua tự hủy một dòng đặt chỗ.
 *
 * Trước file này, `CancellationService` không được gọi từ đâu cả — chính sách
 * hoàn tiền bậc thang chỉ tồn tại trong test. Có logic mà người dùng không bấm
 * được thì tính năng chưa tồn tại (CLAUDE.md mục 8), và với một sàn TMĐT thì
 * hoàn tiền là nghĩa vụ chứ không phải tiện ích.
 */
class CancellationController extends Controller
{
    public function __construct(private readonly CancellationService $cancellations) {}

    public function store(Request $request, Campaign $campaign, BookingLine $line): RedirectResponse
    {
        // Policy trước, không dựa vào việc giao diện ẩn nút (CLAUDE.md mục 2).
        // `cancel` xếp cùng `manage_payments` vì hủy kéo theo nghĩa vụ tiền, nên
        // vai trò `viewer` của tổ chức không hủy được.
        abort_unless(
            $request->user()?->can('cancel', $campaign) ?? false,
            403,
            'Bạn không có quyền hủy đặt chỗ của chiến dịch này.'
        );

        // Dòng phải thuộc chính chiến dịch trên URL. Thiếu phép kiểm này thì
        // người có quyền trên chiến dịch của mình hủy được dòng của chiến dịch
        // người khác — policy đã đúng mà vẫn rò, vì nó kiểm sai đối tượng.
        abort_unless($line->campaign_id === $campaign->id, 404);

        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $refund = $this->cancellations->cancelLine($line, $request->user(), $data['reason'] ?? null);
        } catch (HttpException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Số tiền lấy từ bản ghi máy chủ vừa tạo, không từ thứ gì client gửi
        // lên (CLAUDE.md mục 5: số tiền do máy chủ tính).
        $amount = (int) round((float) $refund->amount);

        return back()->with('success', $amount > 0
            ? sprintf(
                'Đã hủy đặt chỗ. Media owner sẽ hoàn %s ₫ (%d%% theo chính sách hủy).',
                number_format($amount, 0, ',', '.'),
                $refund->refund_pct,
            )
            : 'Đã hủy đặt chỗ. Theo chính sách hủy, lần hủy này không được hoàn tiền.');
    }
}
