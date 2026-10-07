<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Một agency / brand trên danh sách công khai `/agency`.
 *
 * ══ Danh sách TRẮNG, và ở đây nó quan trọng hơn chỗ khác ══
 *
 * Nguồn là `Organization` — bảng của bên MUA. Nó mang `billing_info`,
 * `tax_code`, điều khoản thanh toán, và quan hệ tới chiến dịch cùng giỏ hàng.
 * CLAUDE.md mục 2 cấm lộ nhóm đó, và cách chắc chắn là liệt kê thứ ĐƯỢC trả
 * chứ không liệt kê thứ phải giấu.
 *
 * ══ Ba trường của bản Blade KHÔNG mang sang ══
 *
 * - `payment_terms_days` ("Terms"). Đó là điều khoản thương mại của một bên
 *   thứ ba, đặt trên một trang ai cũng mở được. Bản Blade có in nó; tôi không
 *   mang sang và ghi ra đây để người quyết thấy — nếu cần thì thêm lại một
 *   dòng, còn gỡ một dữ liệu đã công khai thì không thu lại được.
 *
 *   Cạnh nó, bảng còn có `credit_limit`, `tax_id`, `billing_address`,
 *   `billing_email`, `billing_phone`. Hạn mức tín dụng của một khách hàng lọt
 *   ra trang công khai thì nặng hơn hẳn mọi trường còn lại — nên danh sách
 *   trắng ở đây là một phép CHẶN, không phải một phép sắp xếp.
 * - "Rating", luôn hiển thị `—`. Một ô số không có dữ liệu phía sau là đúng
 *   thứ audit F-15 gọi tên.
 * - Ảnh bìa dựng từ `placehold.co`. Nó là ảnh bịa của một dịch vụ ngoài, và
 *   nó gửi tên agency sang bên thứ ba ở mỗi lượt tải trang.
 *
 * `campaign_count` thì giữ: nó là số thật, đếm từ CSDL.
 */
class AgencySummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,

            // Chỉ tên miền, không phải URL đầy đủ. Bản Blade cũng hiện đúng
            // vậy (`parse_url(..., PHP_URL_HOST)`), và một URL đầy đủ có thể
            // mang tham số theo dõi mà agency không định công khai.
            'website_host' => $this->website ? parse_url($this->website, PHP_URL_HOST) : null,

            'logo_url' => $this->logo_url ? asset('storage/' . $this->logo_url) : null,

            // Đếm thật từ CSDL qua `withCount`. Thiếu `withCount` thì nó là
            // null, và null KHÁC 0 — null nghĩa là "không đếm", 0 nghĩa là
            // "đếm rồi, không có cái nào". Trả 0 cho trường hợp đầu là bịa.
            'campaign_count' => $this->campaign_count,
        ];
    }
}
