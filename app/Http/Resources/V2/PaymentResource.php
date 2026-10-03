<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một khoản thanh toán — DTO danh sách trắng.
 *
 * ══ Bốn thứ KHÔNG ra ngoài ══
 *
 * - `metadata`: mảng tự do, đã từng và sẽ còn chứa thứ do tầng trong ghi vào.
 *   Trả một cột tự do ra ngoài là hứa một hợp đồng mà không ai kiểm được.
 * - `idempotency_key`: băm nội bộ chống trùng. Biết khóa là biết cách khiến
 *   lần tạo khoản tiếp theo trả về khoản cũ thay vì tạo khoản mới.
 * - `gateway_ref`: mã đối soát với cổng thanh toán, thuộc đường vận hành.
 * - `invoice_url`: nếu có thì phải là URL ký hạn, và hiện chưa có đường phát
 *   URL ký hạn nào. Trả ra một đường dẫn không ký là mở hóa đơn cho người lạ.
 *
 * `owner` chỉ ra `id` và `name`. Người mua cần biết mình chuyển tiền cho ai,
 * nhưng CLAUDE.md mục 2 cấm lộ `bank_*` và `billing_info` — nên **số tài khoản
 * không đi qua đây**. Thông tin chuyển khoản là việc của một đường riêng, có
 * phân quyền riêng, chưa dựng.
 */
class PaymentResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'owner' => $this->owner ? [
                'id'   => $this->owner->id,
                'name' => $this->owner->name,
            ] : null,

            'method'   => $this->method,
            'currency' => $this->currency ?? 'VND',
            'amount'   => (int) round((float) $this->amount),
            'status'   => $this->status,

            // Mã do người mua tự ghi khi chuyển khoản — của họ, nên trả lại.
            'transaction_ref' => $this->transaction_ref,

            'invoice_number' => $this->invoice_number,

            'due_date'   => $this->due_date?->toDateString(),
            'paid_at'    => $this->paid_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
        ];
    }
}
