<?php

namespace App\Services;

use App\Models\BookingLine;
use App\Models\Screen;

class AvailabilityService
{
    /**
     * Get total booked SOV % for a screen in a date range.
     * Excludes cancelled/rejected lines.
     */
    public function getBookedSOV(string $screenId, string $startDate, string $endDate): int
    {
        return (int) BookingLine::where('screen_id', $screenId)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where(function ($q) use ($startDate, $endDate) {
                $q->where('start_date', '<=', $endDate)
                  ->where('end_date', '>=', $startDate);
            })
            ->sum('share_of_voice_pct');
    }

    /**
     * SOV còn lại của một màn hình, **đã trừ cả suất đang có người giữ**.
     *
     * Không trừ giữ chỗ thì trang công khai báo còn trống một suất mà thực tế đã
     * có người đang thanh toán, và người thứ hai chỉ biết mình mất suất ở bước
     * cuối. `InventoryHoldService` là nơi duy nhất biết công thức sức chứa —
     * hàm này đi qua nó chứ không tự tính lại, để hai chỗ không trôi khỏi nhau
     * như `InventoryController` với `FrontpageService` đã từng.
     */
    public function getRemainingSOV(string $screenId, string $startDate, string $endDate, ?string $ignoreCartItemId = null): int
    {
        $screen = Screen::withoutGlobalScopes()->with('inventory')->find($screenId);

        if (! $screen) {
            return 0;
        }

        return app(InventoryHoldService::class)
            ->remainingSov($screen, $startDate, $endDate, $ignoreCartItemId);
    }

    /**
     * Check if a screen is available for booking with given SOV.
     */
    public function isAvailable(string $screenId, string $startDate, string $endDate, int $sovPct = 100): bool
    {
        return $this->getRemainingSOV($screenId, $startDate, $endDate) >= $sovPct;
    }

    /**
     * Validate all booking lines in a campaign for SOV conflicts.
     * Returns array of conflicts: ['screen_id' => ..., 'available' => ..., 'requested' => ...]
     */
    public function validateCampaign(string $campaignId): array
    {
        $lines = BookingLine::where("campaign_id", $campaignId)
            ->whereNotIn("status", ["cancelled", "rejected"])
            ->with("screen.inventory")
            ->get();

        $holds = app(InventoryHoldService::class);

        $conflicts = [];
        foreach ($lines as $line) {
            if (! $line->screen) {
                continue;
            }

            // Không tự viết lại phép tính sức chứa ở đây: đi qua cùng một hàm
            // với đường đặt chỗ, chỉ bỏ qua chính dòng này. Hai công thức song
            // song là cách chắc chắn nhất để hai con số trôi khỏi nhau.
            $available = $holds->remainingSov(
                $line->screen,
                $line->start_date->toDateString(),
                $line->end_date->toDateString(),
                ignoreBookingLineId: $line->id,
            );

            if ($line->share_of_voice_pct > $available) {
                $conflicts[] = [
                    'booking_line_id' => $line->id,
                    'screen_id'       => $line->screen_id,
                    'screen_name'     => $line->screen?->name,
                    'requested'       => $line->share_of_voice_pct,
                    'available'       => $available,
                    'dates'           => $line->start_date->format('d/m') . ' → ' . $line->end_date->format('d/m'),
                ];
            }
        }

        return $conflicts;
    }
}
