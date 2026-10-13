<?php

namespace App\Notifications;

use App\Models\Refund;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Media owner có một nghĩa vụ hoàn tiền mới — hoặc một nghĩa vụ vừa tăng.
 *
 * ══ Vì sao thư này cần tồn tại ══
 *
 * Giai đoạn 1 dựng ba lối vào cho hủy/hoàn tiền, nhưng **không có thông báo
 * nào**: owner phải tự mở `/publisher` mới biết mình đang nợ người mua một
 * khoản. Sàn không thu hộ — người mua chuyển khoản trực tiếp cho từng owner —
 * nên khoản hoàn cũng do owner tự chuyển lại. Một nghĩa vụ mà người phải trả
 * không biết là một nghĩa vụ không ai thực hiện, và với sàn đang nộp hồ sơ
 * TMĐT thì hoàn tiền là nghĩa vụ chứ không phải tiện ích.
 *
 * ══ Chỉ gửi khi có tiền thật phải chuyển ══
 *
 * `Refund::STATUS_WAIVED` nghĩa là không đồng nào phải hoàn (hủy sát ngày
 * chạy, hoặc người mua chưa chuyển gì). Gửi thư cho một khoản bằng 0 là dạy
 * owner bỏ qua loại thư này — nên người gọi chỉ gửi khi khoản còn `pending`.
 *
 * ══ Số tiền lấy từ bản ghi, không tính lại ở đây ══
 *
 * `amount` và `refund_pct` do `CancellationService` tính và đã ghi vào bảng
 * cùng ảnh chụp chính sách. Thư chỉ đọc lại. Một phép tính thứ hai trong lớp
 * thông báo là chỗ để hai con số lệch nhau giữa thư và trang.
 */
class RefundObligationNotification extends Notification
{
    use Queueable;

    /**
     * @param  int|null  $tangThem  Số tiền nghĩa vụ vừa tăng thêm, nếu đây là
     *                              một lần đối soát bổ sung. `null` nghĩa là
     *                              nghĩa vụ mới sinh ra từ một lệnh hủy.
     */
    public function __construct(
        private readonly Refund $refund,
        private readonly ?int $tangThem = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Hai thông báo cũ của dự án cũng dựng URL từ config theo đúng cách
        // này. Không dùng `RefundResource::getUrl()`: đường thư đi phải chạy
        // được cả khi không có ngữ cảnh panel nào đang mở.
        $dash = config('domains.dash', 'dash.oohx.net');

        $campaign  = $this->refund->campaign;
        $manHinh   = $this->refund->bookingLine?->screen?->name ?? 'màn hình đã gỡ';
        $soTien    = number_format((int) round((float) $this->refund->amount), 0, ',', '.');
        $doiSoat   = $this->tangThem !== null;

        $mail = (new MailMessage)
            ->subject(
                ($doiSoat ? 'Nghĩa vụ hoàn tiền tăng thêm' : 'Nghĩa vụ hoàn tiền mới')
                . ': ' . ($campaign->code ?? $this->refund->campaign_id)
            )
            ->greeting("Xin chào {$notifiable->name},");

        if ($doiSoat) {
            $mail->line(
                'Một khoản thanh toán của chiến dịch **' . $campaign->name . '** vừa được xác nhận '
                . 'sau khi dòng đặt chỗ đã bị hủy, nên nghĩa vụ hoàn tiền của bạn tăng thêm **'
                . number_format($this->tangThem, 0, ',', '.') . ' ₫**.'
            );
        } else {
            $mail->line(
                'Người mua đã hủy một dòng đặt chỗ trong chiến dịch **' . $campaign->name . '**.'
            );
        }

        return $mail
            ->line("**Màn hình:** {$manHinh}")
            ->line("**Số tiền phải hoàn:** {$soTien} ₫ ({$this->refund->refund_pct}% theo chính sách tại thời điểm hủy)")
            ->line('Sàn không thu hộ, nên khoản này do bạn chuyển lại trực tiếp cho người mua. Sau khi chuyển, hãy đánh dấu "đã hoàn" trong trang Hoàn tiền.')
            ->action('Xem nghĩa vụ hoàn tiền', "https://{$dash}/publisher/refunds");
    }
}
