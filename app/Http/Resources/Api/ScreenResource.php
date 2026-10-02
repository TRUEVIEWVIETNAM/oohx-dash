<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScreenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $spec      = $this->spec;
        $inventory = $this->inventory;
        $site      = $this->site;
        $owner     = $this->owner;

        $data = [
            // ── Marketplace fields (always returned) ─────────────────────
            'screen_id'          => $this->external_id ?? $this->uuid,
            'name'               => $this->name,
            'owner_id'           => $owner?->slug ?? ('OWN-' . $this->owner_id),
            'owner_name'         => $owner?->name,
            'screen_type'        => $this->resolveScreenType($spec, $inventory),
            'venue_type'         => $inventory?->vnCategory?->slug ?? $inventory?->venue_type,
            'venue_type_label'   => $inventory?->vnCategory?->name_vi ?? $inventory?->vnCategory?->name,
            'location'           => [
                'address'  => $site?->address,
                'city'     => $site?->city,
                'district' => $site?->region,
                'lat'      => $site ? (float) $site->lat : null,
                'lng'      => $site ? (float) $site->lon : null,
            ],
            'specs'              => [
                'width_px'  => $spec?->width_px,
                'height_px' => $spec?->height_px,
                'width_m'   => $spec?->width_cm ? round($spec->width_cm / 100, 2) : null,
                'height_m'  => $spec?->height_cm ? round($spec->height_cm / 100, 2) : null,
            ],
            // F09 — CỐ Ý KHÔNG SỬA Ở ĐÂY.
            //
            // Tên trường nói "giá một slot" nhưng giá trị là `floor_cpm`, tức
            // giá cho 1.000 lượt hiển thị. Đối tác đang đọc trường này, và
            // /api/v1 là hợp đồng: đổi giá trị hoặc bỏ trường đi là làm sai số
            // tiền trong hệ thống của họ mà họ không được báo trước.
            //
            // Cách đúng là đặt tên đúng ở /api/v2 (`floor_cpm_vnd` cho phần
            // được phép thấy) rồi thông báo cho đối tác chuyển sang, và giữ
            // trường này cho tới khi không còn ai đọc. Đang chờ trả lời câu hỏi
            // "đối tác nào đang dùng API" — xem STATUS.md.
            'price_per_slot_vnd' => $inventory?->floor_cpm ? (int) $inventory->floor_cpm : 0,
            'operating_hours'    => $this->resolveOperatingHours($inventory),
            'min_booking_days'   => 7,
            'photos'             => $spec?->photo_urls ?? [],
            'status'             => $this->active ? 'active' : 'inactive',
            'updated_at'         => $this->updated_at?->toIso8601String(),
        ];

        // ── Intelligence fields (only with ?include=intelligence) ────
        // Phase 1 — Inventory Intelligence (placement, traffic, audience, time, nearby).
        if ($this->shouldInclude($request, 'intelligence')) {
            $data['intelligence'] = [
                'placement_zone'        => $this->placement_zone,
                'orientation'           => $this->orientation, // accessor: derives from spec if null
                'daily_footfall'        => $this->daily_footfall ? (int) $this->daily_footfall : null,
                'monthly_reach'         => $this->monthly_reach ? (int) $this->monthly_reach : null,
                'daily_impressions'     => $this->daily_impressions, // accessor: footfall × 0.6 or weekly/7
                'audience_profile'      => $this->audience_profile,
                'time_performance'      => $this->time_performance,
                'nearby_context'        => $this->nearby_context,
                'methodology_note'      => $this->traffic_methodology_note,
                'has_data'              => $this->has_insights,
            ];
        }

        // ── AdOps fields (only with ?include=adops) ──────────────────
        if ($this->shouldIncludeAdops($request)) {
            $data['adops'] = [
                'slot_duration_sec'      => $inventory?->spot_length ?? 15,
                'min_slot_duration_sec'  => $inventory?->min_spot_length ?? 3,
                'max_slot_duration_sec'  => $inventory?->max_spot_length ?? 180,
                'slots_per_loop'         => $this->resolveSlotsPerLoop($inventory),
                'loop_length_sec'        => $inventory?->loop_length,
                'weekly_impressions'     => $inventory?->weekly_impressions ? (int) $inventory->weekly_impressions : null,
                'programmatic_enabled'   => (bool) ($inventory?->programmatic_enabled ?? false),
                'pmp_only'               => (bool) ($inventory?->pmp_only ?? false),
                'deals_enabled'          => (bool) ($inventory?->deals_enabled ?? true),
                'ad_server_enabled'      => (bool) ($inventory?->ad_server_enabled ?? true),
                'share_of_voice_max_pct' => $inventory?->share_of_voice_max_pct ?? 100,
                'frequency_cap_sec'      => $inventory?->frequency_cap ?? 0,
                'orientation'            => $spec?->orientation,
                'resolution_preset'      => $spec?->resolution_preset,
            ];
        }

        return $data;
    }

    // ── Helpers ─────────────────────────────────────────────

    private function shouldInclude(Request $request, string $key): bool
    {
        $include = (string) $request->input('include', '');
        if ($include === '') return false;
        $tokens = array_map('trim', explode(',', $include));
        return in_array($key, $tokens, true) || $include === 'all';
    }

    private function shouldIncludeAdops(Request $request): bool
    {
        return $this->shouldInclude($request, 'adops');
    }

    private function resolveScreenType($spec, $inventory): string
    {
        $widthCm  = $spec?->width_cm ?? 0;
        $heightCm = $spec?->height_cm ?? 0;

        if ($widthCm >= 300 || $heightCm >= 300) {
            return 'billboard';
        }

        if ($inventory?->venue_type === 'outdoor') {
            return 'led';
        }

        return 'lcd';
    }

    private function resolveSlotsPerLoop($inventory): int
    {
        if (! $inventory) {
            return 8;
        }

        $loop = $inventory->loop_length;
        $spot = $inventory->spot_length;

        if ($loop && $spot > 0) {
            return (int) round($loop / $spot);
        }

        return 8;
    }

    private function resolveOperatingHours($inventory): array
    {
        $hours = $inventory?->operating_hours;

        if (empty($hours)) {
            return ['open' => '08:00', 'close' => '22:00', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']];
        }

        $openTime   = '08:00';
        $closeTime  = '22:00';
        $activeDays = [];

        foreach ($hours as $day => $value) {
            if ($value === 'closed' || empty($value)) {
                continue;
            }

            $activeDays[] = $day;

            if (is_array($value) && isset($value['open'], $value['close'])) {
                $openTime  = $value['open'];
                $closeTime = $value['close'];
            }
        }

        return [
            'open'  => $openTime,
            'close' => $closeTime,
            'days'  => $activeDays,
        ];
    }
}
