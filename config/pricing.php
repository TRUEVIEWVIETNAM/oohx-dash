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

    // Số ngày quy đổi cho một kỳ thuê theo tháng. [CẦN QUYẾT ĐỊNH: tháng lịch?]
    'month_days' => env('PRICING_MONTH_DAYS', 30),

    // Số ngày quy đổi cho một kỳ thuê theo tuần.
    'week_days' => 7,

    // Khoảng ngày tối đa cho một dòng đặt chỗ. [CẦN QUYẾT ĐỊNH]
    'max_range_days' => env('PRICING_MAX_RANGE_DAYS', 365),

    // Thuế suất VAT. Trước đây viết cứng 0.1 ở bốn nơi.
    // [CẦN QUYẾT ĐỊNH: mức áp dụng thực tế, kế toán xác nhận]
    'vat_rate' => env('PRICING_VAT_RATE', 0.1),

];
