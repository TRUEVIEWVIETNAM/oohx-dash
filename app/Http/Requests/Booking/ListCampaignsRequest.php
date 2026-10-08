<?php

namespace App\Http\Requests\Booking;

use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bộ lọc của `GET /api/v2/campaigns`.
 *
 * **Dùng chung** giữa trang Blade `/my/campaigns` và `/api/v2`, như mọi
 * `Booking\*Request` khác: giữ hai bộ luật cho cùng một thao tác là cách để
 * một ngày nào đó API nhận thứ trang từ chối.
 *
 * ══ `status` phải nằm trong enum, và vì sao điều đó quan trọng ══
 *
 * Nhận một chuỗi tuỳ ý rồi đưa vào `where('status', …)` thì không rò dữ liệu —
 * phạm vi đã hẹp về một tổ chức ở `CampaignService::listForUser()`. Nhưng nó
 * trả về một danh sách **rỗng** cho mọi giá trị sai, và client không phân biệt
 * được "không có chiến dịch nào ở trạng thái này" với "tôi gõ sai tên trạng
 * thái". Chặn ngay cửa thì câu trả lời nói rõ chỗ sai.
 *
 * Danh sách lấy từ `Campaign::STATUS_LABELS` — cùng nguồn với nhãn chữ, nên
 * thêm một trạng thái mới là tự động lọc được, không phải sửa hai chỗ.
 */
class ListCampaignsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Phạm vi và tư cách thành viên do `CampaignService::listForUser()`
        // quyết — nó kiểm đúng ba điều kiện mà `CampaignPolicy::membership()`
        // dùng. Không có policy theo bản ghi ở đây vì chưa có bản ghi nào.
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(array_keys(Campaign::STATUS_LABELS))],

            // Tìm theo tên hoặc mã. Giới hạn độ dài để một chuỗi `LIKE` khổng
            // lồ không thành một cách làm chậm truy vấn.
            'q' => ['nullable', 'string', 'max:100'],

            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'Trạng thái không hợp lệ. Hợp lệ: '
                . implode(', ', array_keys(Campaign::STATUS_LABELS)) . '.',
        ];
    }

    /** @return array{status: string|null, q: string|null} */
    public function boLoc(): array
    {
        return [
            'status' => $this->input('status'),

            // Cắt khoảng trắng hai đầu: một ô tìm kiếm gửi lên " CPN-" thì
            // người dùng đang tìm "CPN-", và `%` ở hai đầu đã lo phần còn lại.
            'q' => trim((string) $this->input('q')) ?: null,
        ];
    }
}
