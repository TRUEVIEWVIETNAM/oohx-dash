<?php

return [

    /*
     * Disk lưu nội dung quảng cáo.
     *
     * CLAUDE.md mục 5 nêu đúng "nội dung quảng cáo" trong nhóm tệp nhạy cảm
     * phải để disk riêng và truy cập qua URL ký hạn. Trước 03/10/2026 nó nằm
     * trên disk `public`, nên tải được qua `/storage/...` không cần đăng nhập.
     *
     * Để ở config thay vì viết cứng: lệnh `oohx:creatives-to-private` đọc
     * chính giá trị này, nên hai bên không thể lệch nhau.
     */
    'disk' => env('CREATIVES_DISK', 'private'),

    /*
     * URL ký hạn sống bao lâu (phút).
     *
     * 60 phút là đánh đổi có chủ ý giữa hai thứ:
     *
     * - Ngắn hơn thì an toàn hơn khi URL bị lộ (lịch sử trình duyệt, ảnh chụp
     *   màn hình chia sẻ lại).
     * - Nhưng URL ký hạn nhúng trong thẻ `<img>` của bảng duyệt nội dung ở
     *   Filament sẽ hết hạn ngay trên một trang đang mở. Một người duyệt mở
     *   danh sách rồi đi họp về sẽ thấy toàn ảnh vỡ, và không có cách nào đoán
     *   ra nguyên nhân từ giao diện.
     *
     * Rủi ro của 60 phút được hạ xuống bằng cách khác: route còn kiểm
     * `CreativePolicy` chứ không chỉ kiểm ký. URL lộ ra ngoài vẫn vô dụng với
     * người không có quyền.
     */
    'url_ttl_minutes' => (int) env('CREATIVES_URL_TTL_MINUTES', 60),

];
