<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\Creative;
use Illuminate\Http\UploadedFile;

/**
 * Lưu nội dung quảng cáo của người mua.
 *
 * Nằm ở service chứ không ở controller vì **hai** controller gọi tới nó
 * (`Buyer\BookingController` và `Api\V2\BookingController`), và nó quyết ba
 * thứ không được lệch nhau giữa hai đường: lưu vào disk nào, kiểu tệp là gì,
 * và trạng thái ban đầu.
 *
 * Bản cũ để cả ba thứ đó trong controller Blade. Khi thêm đường API, nhân đôi
 * chúng là cách chắc chắn để một ngày nào đó một đường lưu vào `public` và
 * đường kia lưu vào `private` — đúng kiểu `InventoryController` và
 * `FrontpageService` đã trôi khỏi nhau (CLAUDE.md mục 1).
 */
class CreativeService
{
    public function store(Campaign $campaign, UploadedFile $file, ?string $name = null): Creative
    {
        // Disk riêng, đọc từ config. Tới 03/10/2026 chỗ này là `'public'`, nên
        // tệp tải được qua `/storage/...` không cần đăng nhập — xem
        // `config/creatives.php` và `CreativeFileController`.
        $path = $file->store('creatives/' . $campaign->id, config('creatives.disk'));

        return Creative::create([
            'campaign_id'     => $campaign->id,
            'organization_id' => $campaign->organization_id,
            'name'            => $name ?: $file->getClientOriginalName(),
            'type'            => $this->type($file),
            'file_path'       => $path,
            'file_size'       => $file->getSize(),

            // Chưa duyệt. Nội dung tự lên sóng là chỗ nặng nhất có thể sai ở
            // một sàn quảng cáo ngoài trời, nên mặc định phải là đóng.
            'status'          => Creative::STATUS_PENDING_REVIEW,
        ]);
    }

    /**
     * Ảnh hay video, quyết theo **mime thật**.
     *
     * Bản cũ dùng `getClientOriginalExtension()` — một chuỗi do client gửi,
     * nên một tệp mp4 đổi tên thành `.png` sẽ được ghi là `image` và đi vào
     * nhánh xử lý ảnh. `UploadCreativeRequest` đã chặn mime lạ ở cửa, nhưng
     * phép phân loại ở đây cũng không nên dựa vào tên tệp.
     */
    private function type(UploadedFile $file): string
    {
        return str_starts_with((string) $file->getMimeType(), 'video/')
            ? Creative::TYPE_VIDEO
            : Creative::TYPE_IMAGE;
    }
}
