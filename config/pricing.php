<?php

/**
 * Tham số tính giá.
 *
 * Những con số ở đây là **quy ước nghiệp vụ**, không phải chi tiết kỹ thuật.
 * Trước đây chúng nằm rải rác dưới dạng số viết cứng trong CartService và
 * PaymentService, nên không ai biết chúng đã được ai duyệt hay chưa.
 *
 * ⚠ `month_days = 30` là hành vi ĐANG CHẠY của hệ thống, chưa phải chính sách
 * được duyệt. Codex đã chỉ ra (review R03) rằng "một tháng" là tháng lịch hay 30
 * ngày là quyết định kinh doanh, ảnh hưởng trực tiếp tới số tiền. Ở đây chỉ đưa
 * nó ra khỏi code để nhìn thấy được, không tự đặt chính sách mới.
 */
return [

    /**
     * Cách tính một kỳ thuê theo tháng. CHỐT 27/09/2026: dùng **tháng lịch**.
     *
     *  'calendar'   — tính theo mốc cùng ngày tháng sau. 01/01–30/06 = 6 kỳ,
     *                 01/01–01/07 = 7 kỳ. Đây là cách người mua hiểu chữ "tháng".
     *  'fixed_days' — chia cho `month_days`. Cách cũ; giữ lại để đối chiếu dữ liệu
     *                 lịch sử, không dùng cho đơn mới.
     */
    'month_mode' => env('PRICING_MONTH_MODE', 'calendar'),

    // Chỉ dùng khi month_mode = 'fixed_days'.
    'month_days' => env('PRICING_MONTH_DAYS', 30),

    // Số ngày quy đổi cho một kỳ thuê theo tuần.
    'week_days' => 7,

    /**
     * Khoảng ngày tối đa cho MỘT dòng đặt chỗ.
     *
     * Đây là chặn đầu vào, không phải chính sách bán hàng: nó bắt các trường hợp
     * gõ nhầm năm (2027 thành 2072) hoặc script gửi khoảng ngày vô lý. Người mua
     * muốn thuê dài hơn thì tách thành nhiều dòng hoặc liên hệ.
     */
    'max_range_days' => env('PRICING_MAX_RANGE_DAYS', 365),

    // Thuế suất VAT. CHỐT 27/09/2026: 8%. Trước đây viết cứng 0.1 ở 13 chỗ.
    'vat_rate' => env('PRICING_VAT_RATE', 0.08),

    /**
     * Giữ chỗ trong giỏ hàng hết hạn sau bao nhiêu phút. CHỐT 29/09/2026: 30 phút.
     *
     * Đủ để hoàn tất đặt chỗ và xác nhận chuyển khoản, đủ ngắn để một giỏ bỏ dở
     * không giam suất của người khác. Giữ chỗ đã chuyển thành dòng đặt chỗ thì
     * không hết hạn nữa.
     */
    'hold_ttl_minutes' => env('PRICING_HOLD_TTL_MINUTES', 30),

    /**
     * Thiết bị phát được báo muộn tối đa bao nhiêu ngày.
     *
     * Một con số, hai nơi dùng: PlayerController kẹp mốc thời gian theo nó, và
     * lệnh tổng hợp phải phủ trọn đúng cửa sổ đó. Hai nơi lệch nhau thì lượt
     * phát gửi muộn vào được CSDL nhưng không bao giờ vào báo cáo.
     */
    'impression_late_days' => env('PRICING_IMPRESSION_LATE_DAYS', 7),

    /**
     * Hoàn tiền khi khách hủy đơn đã xác nhận. CHỐT 29/09/2026: bậc thang theo
     * số ngày còn lại tính tới ngày chạy đầu tiên của dòng bị hủy.
     *
     * Đọc từ trên xuống, mốc đầu tiên khớp thì áp tỉ lệ đó. `min_days_before`
     * là "còn ít nhất bao nhiêu ngày nữa mới tới ngày chạy".
     */
    'refund_tiers' => [
        ['min_days_before' => 14, 'refund_pct' => 100],
        ['min_days_before' => 7,  'refund_pct' => 50],
        ['min_days_before' => 0,  'refund_pct' => 0],
    ],

];
