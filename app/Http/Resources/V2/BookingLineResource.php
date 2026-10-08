<?php

namespace App\Http\Resources\V2;

use App\Models\BookingLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một dòng đặt chỗ — DTO danh sách trắng.
 *
 * ══ Ba cột KHÔNG ra ngoài, và vì sao ══
 *
 * `floor_cpm_at_booking`, `io_rate_at_booking` và `negotiated_cpm` là **ảnh
 * chụp cấu hình giá lúc đặt**, dùng để đối soát về sau.
 *
 * Không che vì bí mật — giá niêm yết của màn hình vẫn ra ngoài bình thường ở
 * `screen.pricing` (xem `ScreenSummaryResource`). Che vì **ba con số đó không
 * trả lời câu hỏi người mua đang hỏi**: thứ họ cần biết là mình trả bao nhiêu
 * cho dòng này, và đó là `estimate.cost` — đã tính cả thời lượng, phần trăm
 * SOV và chiết khấu theo kỳ. Đặt một đơn giá chưa nhân bên cạnh một tổng đã
 * nhân là mời người đọc so hai số không so được với nhau.
 *
 * `negotiated_cpm` còn là mức riêng cho từng đơn, tức một điều khoản thương
 * lượng — không phải thứ nên để một DTO danh sách phát ra mà không ai bàn.
 *
 * `approved_by` cũng không ra: đó là id người duyệt bên trong sàn.
 */
class BookingLineResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'screen' => $this->screen ? (new ScreenSummaryResource($this->screen))->resolve() : null,

            'period' => [
                'start_date' => $this->start_date?->toDateString(),
                'end_date'   => $this->end_date?->toDateString(),
            ],

            'delivery' => [
                'pricing_model'      => $this->pricing_model,
                'share_of_voice_pct' => $this->share_of_voice_pct !== null ? (int) $this->share_of_voice_pct : null,
                'spot_length'        => $this->spot_length !== null ? (int) $this->spot_length : null,
                'booked_cpms'        => $this->booked_cpms !== null ? (int) $this->booked_cpms : null,
                'kpi_spots_per_day'  => $this->kpi_spots_per_day !== null ? (int) $this->kpi_spots_per_day : null,
                'io_rate_unit'       => $this->io_rate_unit,
            ],

            'estimate' => [
                'currency'              => 'VND',
                'cost'                  => $this->estimated_cost !== null ? (int) round((float) $this->estimated_cost) : null,
                'impressions'           => $this->estimated_impressions !== null ? (int) $this->estimated_impressions : null,
                'duration_discount_pct' => $this->duration_discount_pct !== null ? (int) $this->duration_discount_pct : null,
            ],

            'status' => $this->status,

            // Chữ do MÁY CHỦ sở hữu, màu do client sở hữu.
            //
            // Bảng chữ ở `BookingLine::STATUS_LABELS`, **khác**
            // `Campaign::STATUS_LABELS` ở đúng một mã: dòng dùng `pending`,
            // chiến dịch dùng `pending_approval`. Trang chi tiết từng dùng một
            // bảng cho cả hai, nên một dòng `pending` hiện ra `pending` nguyên
            // văn tiếng Anh ngay cạnh các dòng đã có chữ Việt.
            'status_label' => BookingLine::STATUS_LABELS[$this->status] ?? $this->status,

            // Lý do bị từ chối thì người mua phải thấy — đó là thông tin về
            // chính đơn của họ, không phải ghi chú nội bộ.
            'rejected_reason' => $this->rejected_reason,
        ];
    }
}
