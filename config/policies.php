<?php

/**
 * Legal pages and the company identity shown to the public.
 *
 * `version` is not decoration: it is stamped onto every consent we record, so a
 * dispute can be answered with the exact text the user agreed to. Bump it in the
 * same commit that changes the corresponding Blade file, never on its own and
 * never afterwards — a consent row pointing at a version whose wording has since
 * moved proves nothing.
 */
return [

    // Đơn vị đăng ký sàn với Bộ Công Thương. Hiển thị dưới chân trang.
    'company' => [
        'legal_name'        => 'CÔNG TY TNHH TRUEVIEW',
        'business_code'     => '0109944503',
        'business_code_by'  => 'Sở Tài Chính thành phố Hà Nội',
        'business_code_on'  => '24/3/2022',
        'address'           => 'Số 110 đường Lạc Long Quân, Phường Tây Hồ, Thành phố Hà Nội, Việt Nam.',
        'legal_rep'         => 'NGUYỄN ANH TUẤN',
        'authorized_contact' => 'NGUYỄN ANH TUẤN',
        'hotline'           => '0943668996',
        'email'             => 'tuan.nguyen@attvietnam.vn',
    ],

    /**
     * Website chưa hoàn tất đăng ký với Bộ Công Thương. Đặt false ngay khi có
     * xác nhận đăng ký — banner thử nghiệm sẽ tự tắt trên toàn site.
     */
    'trial_mode' => env('OOHX_TRIAL_MODE', true),

    'pages' => [
        'quy-che-hoat-dong' => [
            'key'     => 'terms',
            'title'   => 'Quy chế hoạt động',
            'view'    => 'frontpage.policies.terms',
            'version' => '0.1-draft',
            'effective_from' => null, // chưa ban hành — nội dung còn là bản nháp
        ],
        'chinh-sach-bao-mat' => [
            'key'     => 'privacy',
            'title'   => 'Chính sách bảo mật',
            'view'    => 'frontpage.policies.privacy',
            // 0.2: thêm việc chia sẻ liên hệ người mua cho media owner (mục 4).
            'version' => '0.2-draft',
            'effective_from' => null,
        ],
        'giai-quyet-tranh-chap' => [
            'key'     => 'disputes',
            'title'   => 'Cơ chế giải quyết tranh chấp, khiếu nại, phản ánh',
            'view'    => 'frontpage.policies.disputes',
            'version' => '0.1-draft',
            'effective_from' => null,
        ],
        'bang-phi' => [
            'key'     => 'fees',
            'title'   => 'Bảng phí dịch vụ',
            'view'    => 'frontpage.policies.fees',
            'version' => '1.0',
            'effective_from' => '22/09/2026',
        ],
    ],

    /**
     * Biểu phí sàn thu của media owner (hồ sơ TMĐT mục 16). Người mua không trả
     * phí. Sàn không thu theo giao dịch và không chia doanh thu — đổi mô hình thì
     * phải sửa cả hồ sơ đã nộp, không chỉ con số ở đây.
     */
    'fees' => [
        // Giai đoạn 2, tính theo doanh nghiệp media owner, chưa gồm VAT.
        'owner_annual_fee'     => 6_668_000,
        // Giai đoạn 1 (0 đồng) kéo dài tới khi có ngần này owner được duyệt hoạt động.
        'free_until_active_owners' => 30,
        // Báo trước tối thiểu bao nhiêu ngày trước khi bắt đầu thu.
        'notice_days'          => 30,
    ],
];
