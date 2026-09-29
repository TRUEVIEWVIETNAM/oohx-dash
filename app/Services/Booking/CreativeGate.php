<?php

namespace App\Services\Booking;

use App\Models\BookingLine;
use App\Models\Campaign;
use App\Models\CampaignActivity;
use App\Models\Creative;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Nội dung quảng cáo chưa duyệt thì chiến dịch không được lên sóng.
 *
 * Trạng thái duyệt đã có từ trước (`creatives.status`) nhưng **không nối vào
 * đâu cả**: duyệt hay không duyệt cũng không đổi gì trong luồng chạy, và bảng
 * `booking_line_creatives` chưa bao giờ có dòng nào. Với một sàn quảng cáo
 * ngoài trời thì đây là rủi ro pháp lý, không chỉ rủi ro kỹ thuật.
 *
 * **Nói rõ phạm vi:** hiện **chưa có đường phát sóng** — không có endpoint nào
 * trả lịch phát cho thiết bị, `playlist_version` trong heartbeat chỉ là dấu thời
 * gian của bảng kho. Nên cổng này đặt ở chỗ duy nhất có thật: **chuyển chiến
 * dịch sang trạng thái đang chạy**. Khi nào có đường phát sóng thì nó phải gọi
 * lại đúng hàm `assertReadyToAir()` này, chứ không tự viết luật thứ hai.
 */
class CreativeGate
{
    /** Dòng đặt chỗ ở các trạng thái này mới cần có nội dung. */
    private const LINES_NEEDING_CREATIVE = ['approved', 'active'];

    /**
     * Duyệt một nội dung và gắn nó vào các dòng đặt chỗ của chiến dịch.
     *
     * Gắn vào **mọi dòng chưa có nội dung nào**: mặc định hợp lý cho một chiến
     * dịch một mẫu quảng cáo, và là điều kiện để cổng lên sóng mở ra. Khi cần
     * mỗi màn hình một mẫu khác nhau thì gắn tay qua `attach()`.
     */
    public function approve(Creative $creative, ?int $reviewerId = null): Creative
    {
        return DB::transaction(function () use ($creative, $reviewerId) {
            $creative->update([
                'status'      => 'approved',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            $lines = $creative->campaign
                ? $creative->campaign->bookingLines()->whereNotIn('status', ['cancelled', 'rejected'])->get()
                : collect();

            foreach ($lines as $line) {
                if ($line->creatives()->count() === 0) {
                    $line->creatives()->attach($creative->id, ['weight' => 100]);
                }
            }

            if ($creative->campaign) {
                CampaignActivity::log(
                    $creative->campaign,
                    'creative_approved',
                    "Nội dung \"{$creative->name}\" đã được duyệt",
                    $reviewerId,
                );
            }

            return $creative->fresh();
        });
    }

    /**
     * Từ chối một nội dung và **gỡ nó khỏi mọi dòng đặt chỗ**.
     *
     * Gỡ chứ không chỉ đổi trạng thái: một nội dung bị từ chối mà vẫn nằm trong
     * danh sách gắn với dòng đặt chỗ là đúng thứ sẽ bị đọc nhầm khi sau này có
     * đường phát sóng.
     */
    public function reject(Creative $creative, ?int $reviewerId = null, ?string $reason = null): Creative
    {
        return DB::transaction(function () use ($creative, $reviewerId, $reason) {
            $creative->update([
                'status'      => 'rejected',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            $creative->bookingLines()->detach();

            if ($creative->campaign) {
                CampaignActivity::log(
                    $creative->campaign,
                    'creative_rejected',
                    "Nội dung \"{$creative->name}\" bị từ chối" . ($reason ? ": {$reason}" : ''),
                    $reviewerId,
                );
            }

            return $creative->fresh();
        });
    }

    /** Gắn tay một nội dung ĐÃ DUYỆT vào một dòng đặt chỗ. */
    public function attach(BookingLine $line, Creative $creative, int $weight = 100): void
    {
        if ($creative->status !== 'approved') {
            throw new HttpException(422, "Nội dung \"{$creative->name}\" chưa được duyệt, không gắn vào dòng đặt chỗ được.");
        }

        if ($creative->campaign_id !== $line->campaign_id) {
            throw new HttpException(422, 'Nội dung không thuộc chiến dịch của dòng đặt chỗ này.');
        }

        $line->creatives()->syncWithoutDetaching([$creative->id => ['weight' => $weight]]);
    }

    /**
     * Các dòng đặt chỗ chưa có nội dung đã duyệt.
     *
     * @return Collection<int, BookingLine>
     */
    public function linesMissingApprovedCreative(Campaign $campaign): Collection
    {
        return $campaign->bookingLines()
            ->whereIn('status', self::LINES_NEEDING_CREATIVE)
            ->whereDoesntHave('creatives', fn ($q) => $q->where('creatives.status', 'approved'))
            ->with('screen')
            ->get();
    }

    public function isReadyToAir(Campaign $campaign): bool
    {
        return $this->linesMissingApprovedCreative($campaign)->isEmpty();
    }

    /**
     * Chặn lên sóng khi còn dòng chưa có nội dung đã duyệt.
     *
     * Nêu đích danh màn hình nào thiếu — báo "chưa đủ điều kiện" mà không nói
     * thiếu ở đâu thì người vận hành phải đi dò từng dòng.
     */
    public function assertReadyToAir(Campaign $campaign): void
    {
        $missing = $this->linesMissingApprovedCreative($campaign);

        if ($missing->isEmpty()) {
            return;
        }

        $names = $missing->map(fn (BookingLine $l) => $l->screen?->name ?? $l->screen_id)->take(5)->implode(', ');
        $more  = $missing->count() > 5 ? sprintf(' và %d màn hình khác', $missing->count() - 5) : '';

        throw new HttpException(422, sprintf(
            'Chưa thể lên sóng: %d dòng đặt chỗ chưa có nội dung đã duyệt (%s%s).',
            $missing->count(),
            $names,
            $more,
        ));
    }
}
