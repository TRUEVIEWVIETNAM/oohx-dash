<?php

namespace App\Services;

use App\Models\BookingLine;
use App\Models\CartItem;
use App\Models\InventoryHold;
use App\Models\Screen;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Giành và nhả suất phát trên một màn hình.
 *
 * Vấn đề trước đây: `AvailabilityService` chỉ **đọc rồi so sánh**. Hai người mua
 * cùng chạy qua nó trong cùng một khoảnh khắc thì cả hai đều thấy còn trống và
 * cả hai đều ghi được — không có gì chặn ai. Đây là lỗi kinh điển "kiểm rồi mới
 * làm" (check-then-act), và nó không lộ ra trong test một luồng.
 *
 * Cách chặn: **khóa hàng màn hình** trước khi tính chỗ trống. Hàng `screens`
 * đóng vai mốc khóa cho mọi quyết định sức chứa của màn hình đó, nên hai giao
 * dịch bắt buộc phải xếp hàng. Người đến sau nhìn thấy giữ chỗ của người đến
 * trước và nhận 422, chứ không thấy một bức ảnh cũ của kho.
 *
 * Sức chứa của một màn hình trong một khoảng ngày:
 *
 *     SOV đã dùng = tổng SOV của các dòng đặt chỗ còn hiệu lực
 *                 + tổng SOV của các giữ chỗ còn hiệu lực
 *     còn lại     = share_of_voice_max_pct − SOV đã dùng
 */
class InventoryHoldService
{
    /** Dòng đặt chỗ ở các trạng thái này vẫn đang chiếm suất. */
    private const LIVE_LINE_STATUSES = ['pending', 'approved', 'active', 'paused', 'completed'];

    /**
     * Giành một suất. Ném 422 nếu không còn đủ.
     *
     * Phải được gọi **trong** một transaction: khóa chỉ có tác dụng tới khi
     * transaction kết thúc, và giữ chỗ không được tồn tại mà không có thứ trỏ
     * tới nó. Hàm tự mở transaction nếu chưa có.
     */
    public function acquireForCartItem(CartItem $item, Screen $screen, int $sovPct = 0): InventoryHold
    {
        $sovPct = $sovPct > 0 ? $sovPct : (int) ($item->share_of_voice_pct ?: 100);

        return DB::transaction(function () use ($item, $screen, $sovPct) {
            // Khóa TRƯỚC khi đọc bất cứ con số sức chứa nào. Thứ tự ở đây là
            // toàn bộ giá trị của hàm: khóa sau khi tính thì không chặn gì.
            $locked = $this->lockScreen($screen->id);

            // Giữ chỗ cũ của chính dòng giỏ này phải nhả trước, nếu không người
            // mua tự chặn chính mình khi sửa ngày hoặc sửa SOV.
            $this->releaseForCartItem($item, $locked->id);

            $this->assertCapacity(
                $locked,
                $item->start_date->toDateString(),
                $item->end_date->toDateString(),
                $sovPct,
            );

            return InventoryHold::create([
                'screen_id'    => $screen->id,
                'cart_item_id' => $item->id,
                'start_date'   => $item->start_date,
                'end_date'     => $item->end_date,
                'sov_pct'      => $sovPct,
                'expires_at'   => now()->addMinutes($this->ttlMinutes()),
                'status'       => InventoryHold::STATUS_ACTIVE,
            ]);
        });
    }

    /**
     * Chuyển giữ chỗ của giỏ thành giữ chỗ của dòng đặt chỗ.
     *
     * Gọi trong transaction của `createFromCart`. Kiểm lại sức chứa một lần nữa
     * ở đây: giữ chỗ có thể đã hết hạn trong lúc khách còn đang nhập thông tin
     * chiến dịch, và trong khoảng đó người khác đã kịp lấy suất.
     */
    public function consumeForBookingLine(BookingLine $line, ?CartItem $item = null): InventoryHold
    {
        $screen = $this->lockScreen($line->screen_id);

        $start = $line->start_date->toDateString();
        $end   = $line->end_date->toDateString();
        $sov   = (int) ($line->share_of_voice_pct ?: 100);

        // Giữ chỗ chỉ được nâng cấp khi nó **bao phủ đúng** khoảng ngày và đủ
        // SOV mà dòng đặt chỗ cần.
        //
        // Điều kiện cũ chỉ đòi hai khoảng GIAO NHAU. Khách giữ tháng 1 rồi sửa
        // sang tháng 2 mà hold cũ chưa cập nhật thì hold tháng 1 vẫn khớp, và
        // suất tháng 2 được cấp mà không qua phép kiểm sức chứa nào — kể cả
        // khi người khác đã lấy (Codex R03).
        $existing = $item
            ? InventoryHold::effective()
                ->where('cart_item_id', $item->id)
                ->where('screen_id', $screen->id)
                ->where('start_date', '<=', $start)
                ->where('end_date', '>=', $end)
                ->where('sov_pct', '>=', $sov)
                ->first()
            : null;

        if ($existing) {
            // Đã có giữ chỗ còn hiệu lực từ giỏ: nâng cấp thành vĩnh viễn.
            // Không kiểm lại sức chứa vì suất này đã thuộc về khách từ trước.
            $existing->update([
                'booking_line_id' => $line->id,
                'sov_pct'         => $sov,
                'expires_at'      => null,
                'status'          => InventoryHold::STATUS_CONSUMED,
            ]);

            return $existing->fresh();
        }

        // Không còn giữ chỗ (hết hạn, hoặc đặt chỗ không đi qua giỏ): phải giành lại.
        //
        // `ignoreBookingLineId` là bắt buộc, không phải tinh chỉnh: `createFromCart`
        // ghi dòng đặt chỗ TRƯỚC rồi mới giành suất, mà dòng ở trạng thái
        // `pending` đã bị tính vào sức chứa đã dùng. Thiếu tham số này thì một
        // màn hình hoàn toàn trống cũng trả 422 mỗi khi giữ chỗ hết hạn —
        // người mua bị chặn bởi chính đơn của mình (Codex R02).
        $this->assertCapacity(
            $screen,
            $start,
            $end,
            $sov,
            ignoreCartItemId: $item?->id,
            ignoreBookingLineId: $line->id,
        );

        return InventoryHold::create([
            'screen_id'       => $screen->id,
            'booking_line_id' => $line->id,
            'start_date'      => $line->start_date,
            'end_date'        => $line->end_date,
            'sov_pct'         => $sov,
            'expires_at'      => null,
            'status'          => InventoryHold::STATUS_CONSUMED,
        ]);
    }

    /** Nhả giữ chỗ của một dòng giỏ (bỏ khỏi giỏ, sửa ngày, giỏ bị xóa). */
    public function releaseForCartItem(CartItem $item, ?string $screenId = null): int
    {
        return InventoryHold::where('cart_item_id', $item->id)
            ->when($screenId, fn ($q) => $q->where('screen_id', $screenId))
            ->where('status', InventoryHold::STATUS_ACTIVE)
            ->update(['status' => InventoryHold::STATUS_RELEASED]);
    }

    /** Nhả giữ chỗ của một dòng đặt chỗ đã hủy. */
    public function releaseForBookingLine(BookingLine $line): int
    {
        return InventoryHold::where('booking_line_id', $line->id)
            ->whereIn('status', [InventoryHold::STATUS_ACTIVE, InventoryHold::STATUS_CONSUMED])
            ->update(['status' => InventoryHold::STATUS_RELEASED]);
    }

    /**
     * Dọn giữ chỗ đã hết hạn.
     *
     * Chỉ là việc dọn nhà: truy vấn sức chứa đã tự loại giữ chỗ hết hạn, nên job
     * này chậm hay hỏng cũng không giam kho.
     */
    public function purgeExpired(): int
    {
        return InventoryHold::where('status', InventoryHold::STATUS_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => InventoryHold::STATUS_RELEASED]);
    }

    /**
     * SOV còn lại của một màn hình trong khoảng ngày.
     *
     * `ignoreCartItemId` để người mua không tự chặn chính mình: khi xem lại giỏ
     * của mình thì giữ chỗ của chính mình không tính là chướng ngại.
     */
    public function remainingSov(
        Screen $screen,
        string $startDate,
        string $endDate,
        ?string $ignoreCartItemId = null,
        ?string $ignoreBookingLineId = null,
        bool $locking = false,
    ): int {
        $max = (int) ($screen->inventory?->share_of_voice_max_pct ?? 100);

        $bookedByLines = (int) BookingLine::withoutGlobalScopes()
            ->where('screen_id', $screen->id)
            ->whereIn('status', self::LIVE_LINE_STATUSES)
            ->when($ignoreBookingLineId, fn ($q) => $q->where('id', '!=', $ignoreBookingLineId))
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->when($locking, fn ($q) => $q->lockForUpdate())
            ->sum('share_of_voice_pct');

        // Giữ chỗ đã gắn dòng đặt chỗ thì không cộng hai lần — dòng đặt chỗ ở
        // trên đã tính rồi.
        $heldBySelf = (int) InventoryHold::effective()
            ->where('screen_id', $screen->id)
            ->whereNull('booking_line_id')
            ->when($ignoreCartItemId, fn ($q) => $q->where('cart_item_id', '!=', $ignoreCartItemId))
            ->overlapping($startDate, $endDate)
            ->when($locking, fn ($q) => $q->lockForUpdate())
            ->sum('sov_pct');

        return max(0, $max - $bookedByLines - $heldBySelf);
    }

    /**
     * Khóa hàng màn hình làm mốc cho mọi quyết định sức chứa của nó.
     *
     * Đây là dòng code khiến hai người mua phải xếp hàng. Bỏ `lockForUpdate()`
     * thì mọi thứ còn lại vẫn "chạy đúng" trong test một luồng và sai trong thực tế.
     */
    private function lockScreen(string $screenId): Screen
    {
        $screen = Screen::withoutGlobalScopes()
            ->whereKey($screenId)
            ->lockForUpdate()
            ->first();

        if (! $screen) {
            throw new HttpException(422, 'Màn hình không tồn tại hoặc đã bị gỡ.');
        }

        // Nạp kho sau khi đã khóa, để đọc đúng giới hạn SOV hiện hành.
        $screen->load('inventory');

        return $screen;
    }

    /**
     * `$lockedScreen` **phải** là màn hình vừa lấy qua `lockScreen()` trong cùng
     * transaction. Gọi hàm này với một đối tượng màn hình lấy theo cách khác thì
     * phép kiểm vẫn chạy nhưng không còn chặn được ai.
     */
    private function assertCapacity(
        Screen $lockedScreen,
        string $startDate,
        string $endDate,
        int $sovPct,
        ?string $ignoreCartItemId = null,
        ?string $ignoreBookingLineId = null,
    ): void {
        $locked = $lockedScreen;

        // `locking: true` — phép đếm này PHẢI là phép đọc có khóa.
        //
        // MySQL mặc định chạy REPEATABLE READ: một truy vấn đọc thường dùng ảnh
        // chụp lập từ lần đọc đầu tiên của transaction. Giao dịch B đã đọc giỏ
        // hàng trước khi xin khóa màn hình, nên ảnh chụp của nó có từ trước;
        // khóa hàng màn hình bắt B xếp hàng nhưng KHÔNG làm mới ảnh chụp đó.
        // B chờ xong, đếm trên ảnh cũ, không thấy giữ chỗ A vừa commit, và bán
        // vượt suất. Phép đọc có khóa luôn đọc bản mới nhất đã commit (Codex R04).
        $remaining = $this->remainingSov(
            $locked,
            $startDate,
            $endDate,
            $ignoreCartItemId,
            $ignoreBookingLineId,
            locking: true,
        );

        if ($sovPct > $remaining) {
            throw new HttpException(422, sprintf(
                'Màn hình "%s" chỉ còn %d%% SOV trong khoảng %s – %s, không đủ cho %d%% bạn yêu cầu.',
                $locked->name,
                $remaining,
                $startDate,
                $endDate,
                $sovPct,
            ));
        }
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('pricing.hold_ttl_minutes', 30));
    }
}
