<?php

namespace App\Http\Resources\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thông tin nhận tiền của MỘT media owner — DTO danh sách trắng.
 *
 * ══ Đây là ngoại lệ duy nhất của điều cấm `bank_*` + `tax_code` ══
 *
 * CLAUDE.md mục 2 cấm phơi `bank_*` và `tax_code`. DTO này cố ý phá điều đó,
 * nên lý do phải nằm ngay cạnh code chứ không nằm trong một quyết định đã quên:
 *
 * Mô hình kinh doanh là **người mua chuyển khoản trực tiếp cho từng media
 * owner**. OOHX không thu hộ — đúng như hồ sơ đăng ký với Bộ Công Thương:
 * *"thanh toán trực tiếp giữa khách hàng và nhà cung cấp dịch vụ quảng cáo;
 * OOHX.NET hỗ trợ ghi nhận giao dịch và đối soát"*. Người mua **không trả
 * được** nếu không thấy nơi nhận tiền. Nên điều cấm kia, áp vào đúng chỗ này,
 * sẽ chặn chính nghiệp vụ mà sàn tồn tại để làm.
 *
 * Điều cấm vẫn giữ nguyên ở mọi chỗ khác. `PaymentResource` và `by_owner` của
 * `PaymentOverview` vẫn chỉ có `owner.id` + `owner.name`, và phải giữ như vậy:
 * một endpoint hẹp thì canh được, còn thông tin nhận tiền lẫn vào mọi phản hồi
 * thanh toán thì không.
 *
 * ══ Vì sao `tax_code` ra ngoài ══
 *
 * Người mua cần mã số thuế của bên nhận tiền để ghi vào sổ sách của chính họ,
 * và MST doanh nghiệp Việt Nam là thông tin tra cứu công khai được
 * (tracuunnt.gdt.gov.vn). Nó không phải bí mật; nó nằm trong điều cấm vì không
 * có nghiệp vụ nào cần nó ra ngoài — tới hôm nay thì có đúng một.
 *
 * ══ Những thứ VẪN không ra ngoài ══
 *
 * `revenue_share_pct` (phần ăn chia của sàn với owner — không phải việc của
 * người mua), `business_license_path` (tệp trên disk riêng, phải qua URL ký
 * hạn), `billing_*` và `credit_limit` của tổ chức. Danh sách dưới đây là danh
 * sách TRẮNG: thêm trường vào đây là một quyết định, không phải một lần sửa.
 */
class OwnerRemittanceResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $daKhaiDu = (bool) $this->hasBankDetails();

        return [
            'id'   => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,

            // Trang hiện tại hiển thị `legal_name ?: name`. Trả cả hai thay vì
            // trả sẵn một chuỗi đã chọn: tên pháp lý là tên ghi trên chứng từ,
            // tên thương hiệu là tên người mua nhận ra. Client cần phân biệt
            // được hai thứ đó.
            'legal_name' => $this->legal_name,

            'tax_code' => $this->tax_code,

            // Máy chủ trả lời "đã khai đủ hay chưa", không để client tự suy từ
            // việc trường nào null. `hasBankDetails()` đòi đủ ba trường; thiếu
            // một trường là không chuyển được, và một client tự đoán sẽ vẽ ra
            // nút trả tiền cho một bên không nhận được tiền.
            'has_bank_details' => $daKhaiDu,

            // Chưa khai đủ thì trả `null` sạch chứ không trả một phần. Nửa bộ
            // thông tin tệ hơn không có gì: người mua sẽ thử chuyển và tiền đi
            // sai chỗ.
            'bank_name'           => $daKhaiDu ? $this->bank_name : null,
            'bank_account_number' => $daKhaiDu ? $this->bank_account_number : null,
            'bank_account_name'   => $daKhaiDu ? $this->bank_account_name : null,
            'bank_branch'         => $daKhaiDu ? $this->bank_branch : null,
        ];
    }
}
