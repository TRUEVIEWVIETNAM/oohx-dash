import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { SiteFooter } from '@/components/SiteFooter';
import { TrialNotice } from '@/components/TrialNotice';
import './globals.css';

/**
 * Layout gốc.
 *
 * Thứ tự giữ đúng `resources/views/frontpage/layouts/app.blade.php`:
 * thông báo thử nghiệm → header → nội dung → chân trang.
 *
 * ══ Header nằm trong từng trang, không nằm ở đây ══
 *
 * Vì nó cần biết mục nào đang hoạt động để tô sáng, và Blade truyền
 * `activeNav` theo từng trang. Đặt ở layout thì mất phần tô sáng, và thanh điều
 * hướng không cho biết người dùng đang ở đâu.
 *
 * Nên mỗi trang mở đầu bằng `<SiteHeader active="..." />`. Chân trang và thông
 * báo thử nghiệm thì không phụ thuộc trang nào, nên ở đây.
 *
 * ══ `lang="vi"` ══
 *
 * CLAUDE.md mục 3: "Tiếng Việt là mặc định trên toàn bộ trang công khai."
 */
export const metadata: Metadata = {
    metadataBase: new URL(process.env.OOHX_PUBLIC_ORIGIN ?? 'https://oohx.net'),
    title: 'OOHX',
};

/**
 * Cờ thử nghiệm đọc ở máy chủ.
 *
 * Nguồn sự thật là `config/policies.php` của Laravel (`OOHX_TRIAL_MODE`), và
 * unit systemd truyền biến đó sang tiến trình Next. Mặc định **bật**, giống
 * `env('OOHX_TRIAL_MODE', true)` bên Laravel: với một thông báo pháp lý thì
 * mặc định an toàn là hiện, không phải ẩn.
 */
const TRIAL_MODE = (process.env.OOHX_TRIAL_MODE ?? 'true') !== 'false';

export default function RootLayout({ children }: { children: ReactNode }) {
    return (
        <html lang="vi">
            <head>
                {/*
                  Hai font của Google, chép ĐÚNG URL bản Blade dùng
                  (`layouts/app.blade.php` dòng 15–18), kể cả khoảng biến thiên
                  trong tham số.

                  Không rút gọn tham số cho "gọn": `frontpage.css` đặt
                  `font-family: 'Open Sans'` và khai các độ đậm trong khoảng
                  300–800. Nạp một khoảng hẹp hơn thì trình duyệt tự tổng hợp
                  nét đậm thiếu, và chữ trên bản Next trông khác bản Blade ở
                  đúng những chỗ dùng độ đậm không có.
                */}
                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link
                    rel="stylesheet"
                    href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300..800;1,400&display=swap"
                />
                <link
                    rel="stylesheet"
                    href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap"
                />
            </head>
            <body>
                {TRIAL_MODE ? <TrialNotice /> : null}
                {children}
                <SiteFooter />
            </body>
        </html>
    );
}
