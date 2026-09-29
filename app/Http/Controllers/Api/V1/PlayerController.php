<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BookingLine;
use App\Models\ImpressionLog;
use App\Models\Screen;
use App\Services\Player\DeviceAuthenticator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Endpoint cho thiết bị phát.
 *
 * Đây là đường ghi **bằng chứng phát sóng** — thứ quyết định media owner được
 * trả bao nhiêu tiền và người mua nhận được gì. Bốn thứ nó phải làm đúng, và
 * trước giai đoạn 2 thì không làm đúng cái nào:
 *
 *  1. Ghi được. Bảng cũ chèn là hỏng (SQLSTATE 1364) — xem migration dựng lại.
 *  2. Biết ai gửi. Trước đây chỉ cần biết `screen_uuid` là bơm được số liệu.
 *  3. Không cộng trùng khi thiết bị gửi lại sau khi mất mạng.
 *  4. Nối được lượt phát với **dòng đặt chỗ** đã bán.
 */
class PlayerController extends Controller
{
    /** Thiết bị được phép báo muộn tối đa bao nhiêu ngày (gửi bù sau khi mất mạng). */
    private const MAX_LATE_DAYS = 7;

    /** Sai lệch đồng hồ về phía tương lai được bỏ qua. */
    private const MAX_CLOCK_SKEW_MINUTES = 5;

    public function __construct(private readonly DeviceAuthenticator $devices) {}

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'screen_uuid'    => 'required|uuid',
            'player_version' => 'nullable|string|max:50',
        ]);

        $screen = $this->devices->authenticate($request, $data['screen_uuid']);

        $screen->update([
            'last_heartbeat_at' => now(),
            'status'            => 'online',
            'player_version'    => $data['player_version'] ?? $screen->player_version,
        ]);

        return response()->json([
            'status'           => 'ok',
            'server_time'      => now()->toIso8601String(),
            'operating'        => $screen->isOperating(),
            'playlist_version' => $screen->inventory?->updated_at?->timestamp,
        ]);
    }

    public function impression(Request $request): JsonResponse
    {
        $data = $request->validate([
            'screen_uuid'  => 'required|uuid',

            // Mã sự kiện do thiết bị sinh và giữ nguyên qua các lần gửi lại.
            'event_id'     => 'required|string|max:64',

            // ULID, KHÔNG phải số nguyên. Luật cũ ghi 'integer' trong khi
            // chiến dịch và nội dung đều dùng ULID, nên mọi lượt phát gửi kèm
            // id thật đều bị từ chối — và báo cáo lọc theo campaign_id không
            // bao giờ khớp.
            'campaign_id'     => 'nullable|string|size:26',
            'creative_id'     => 'nullable|string|size:26',
            'booking_line_id' => 'nullable|string|size:26',

            'duration_sec' => 'required|integer|min:1|max:600',

            // BẮT BUỘC, không còn nullable. Chống trùng dựa trên
            // (screen_id, event_id, played_at) — khóa unique buộc phải chứa cột
            // phân vùng. Nếu để thiết bị bỏ trống rồi máy chủ điền `now()` thì
            // lần gửi lại có mốc khác, khóa không chặn được, và lượt phát bị
            // cộng hai lần. Với bằng chứng phát sóng thì thời điểm phát vốn là
            // nội dung chính, không phải thứ tùy chọn.
            'played_at'    => 'required|date',
            'deal_type'    => 'nullable|in:direct,rtb,pmp',
            'proof_url'    => 'nullable|url|max:500',
        ]);

        $screen = $this->devices->authenticate($request, $data['screen_uuid']);

        $playedAt = $this->resolvePlayedAt($data['played_at']);

        // Hỏi trước khi ghi: thiết bị gửi lại thì nhận lại đúng bản ghi cũ.
        // Khóa unique ở CSDL vẫn là chốt chặn cuối cho trường hợp hai yêu cầu
        // chạy song song, nhưng hỏi trước thì tránh được cả việc mốc thời gian
        // bị kẹp biên khác nhau giữa hai lần gửi.
        $existing = ImpressionLog::where('screen_id', $screen->id)
            ->where('event_id', $data['event_id'])
            ->first();

        if ($existing) {
            return $this->duplicateResponse($existing);
        }

        $line = $this->resolveBookingLine($screen, $data, $playedAt);

        $multiplier = $screen->getCurrentMultiplier();

        $payload = [
            'screen_id'          => $screen->id,
            'owner_id'           => $screen->owner_id,
            'campaign_id'        => $line?->campaign_id ?? ($data['campaign_id'] ?? null),
            'booking_line_id'    => $line?->id,
            'creative_id'        => $data['creative_id'] ?? null,
            'event_id'           => $data['event_id'],
            'played_at'          => $playedAt,
            'duration_sec'       => $data['duration_sec'],
            'multiplier_applied' => $multiplier,
            'imp_count'          => max(1, $screen->inventory?->effective_screen_count ?? 1) * $multiplier,
            'deal_type'          => $data['deal_type'] ?? 'direct',
            'proof_url'          => $data['proof_url'] ?? null,
            'source'             => 'adtrue_player',
        ];

        try {
            $log = ImpressionLog::create($payload);
        } catch (UniqueConstraintViolationException) {
            // Hai yêu cầu chạy song song cùng vượt qua phép hỏi ở trên. Trả về
            // bản ghi đã có thay vì cộng thêm một lượt: cộng thêm ở đây là cộng
            // thêm tiền.
            return $this->duplicateResponse(
                ImpressionLog::where('screen_id', $screen->id)
                    ->where('event_id', $data['event_id'])
                    ->firstOrFail()
            );
        }

        return response()->json([
            'id'         => $log->id,
            'imp_count'  => $log->imp_count,
            'multiplier' => $log->multiplier_applied,
            'duplicate'  => false,
        ], 201);
    }

    private function duplicateResponse(ImpressionLog $log): JsonResponse
    {
        return response()->json([
            'id'         => $log->id,
            'imp_count'  => $log->imp_count,
            'multiplier' => $log->multiplier_applied,
            'duplicate'  => true,
        ], 200);
    }

    /**
     * Thời điểm phát, đã chặn biên.
     *
     * Đồng hồ thiết bị ngoài hiện trường sai là chuyện thường, và `played_at`
     * quyết định lượt phát rơi vào phân vùng nào, kỳ báo cáo nào, hóa đơn nào.
     * Không chặn biên thì một thiết bị lệch giờ — hoặc một người biết token —
     * ghi được vào bất kỳ mốc thời gian nào, kể cả tương lai.
     *
     * Cho phép báo muộn tới 7 ngày vì thiết bị mất mạng rồi gửi bù là bình
     * thường; quá mốc đó thì kẹp về biên và vẫn ghi, không vứt dữ liệu đi.
     */
    private function resolvePlayedAt(string $raw): Carbon
    {
        $now    = now();
        $played = Carbon::parse($raw);

        $earliest = $now->copy()->subDays(self::MAX_LATE_DAYS);
        $latest   = $now->copy()->addMinutes(self::MAX_CLOCK_SKEW_MINUTES);

        if ($played->lessThan($earliest)) {
            return $earliest;
        }

        if ($played->greaterThan($latest)) {
            return $now;
        }

        return $played;
    }

    /**
     * Dòng đặt chỗ mà lượt phát này thuộc về.
     *
     * Tin thiết bị ở mức vừa phải: `booking_line_id` nó gửi phải thật sự là
     * dòng **của chính màn hình đó**, đang chạy, và ngày phát nằm trong khoảng
     * đã bán. Không có thì thử suy từ `campaign_id`. Vẫn không ra thì ghi lượt
     * phát mà không gắn dòng nào — mất dữ liệu còn tệ hơn là gắn sai.
     */
    private function resolveBookingLine(Screen $screen, array $data, Carbon $playedAt): ?BookingLine
    {
        $query = BookingLine::query()
            ->where('screen_id', $screen->id)
            ->whereIn('status', ['active', 'completed'])
            ->whereDate('start_date', '<=', $playedAt->toDateString())
            ->whereDate('end_date', '>=', $playedAt->toDateString());

        if (! empty($data['booking_line_id'])) {
            $line = (clone $query)->whereKey($data['booking_line_id'])->first();

            if ($line) {
                return $line;
            }
        }

        if (! empty($data['campaign_id'])) {
            return (clone $query)->where('campaign_id', $data['campaign_id'])->first();
        }

        return null;
    }
}
