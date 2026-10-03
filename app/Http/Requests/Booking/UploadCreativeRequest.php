<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tải nội dung quảng cáo lên — bước 2 của đặt chỗ.
 *
 * **Dùng chung** giữa trang Blade (`Buyer\BookingController::uploadCreative`)
 * và `/api/v2` (`Api\V2\BookingController::storeCreative`).
 *
 * ══ Ba lớp kiểm, và vì sao cần cả ba ══
 *
 * CLAUDE.md mục 5: "Upload: kiểm mime, phần mở rộng và dung lượng."
 *
 * - `mimes:` kiểm **phần mở rộng** và mime đoán từ nội dung. Bản cũ chỉ có nó.
 * - `mimetypes:` kiểm **mime thật** đọc từ nội dung tệp. Thiếu lớp này thì một
 *   tệp có phần mở rộng hợp lệ nhưng nội dung khác vẫn qua được, và sau đó nó
 *   được phát lên màn hình ngoài trời.
 * - `max:51200` (50MB) chặn dung lượng. Giới hạn này cũng phải nhỏ hơn
 *   `upload_max_filesize` của PHP, nếu không thì tệp quá lớn bị PHP cắt trước
 *   khi Laravel kịp kiểm, và người gửi nhận một lỗi không nói gì.
 *
 * Hai lớp mime không trùng nhau: một tệp mp4 đổi tên thành `.png` thì
 * `mimes:png` có thể cho qua (nó đoán từ nội dung ở một số cấu hình), nhưng
 * `mimetypes:` so với danh sách thật thì không.
 */
class UploadCreativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Quyền `uploadCreative` do controller gọi trên chính bản ghi campaign.
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,mp4,webm',
                'mimetypes:image/jpeg,image/png,video/mp4,video/webm',
                'max:51200',
            ],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required'   => 'Chưa chọn tệp nào.',
            'file.mimes'      => 'Chỉ nhận ảnh JPG/PNG hoặc video MP4/WebM.',
            'file.mimetypes'  => 'Nội dung tệp không khớp với định dạng cho phép.',
            'file.max'        => 'Tệp vượt quá 50MB.',
        ];
    }
}
