import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { Open_Sans } from 'next/font/google';

/**
 * Open Sans tự host, thay cho `<link>` tới fonts.googleapis.com.
 *
 * ══ Ba thứ đổi, và vì sao đáng ══
 *
 *  1. **Không còn một vòng mạng ra Google** trước khi chữ hiện. Từ mạng Việt
 *     Nam đó là một chặng không đoán trước được — cùng loại phụ thuộc đã làm
 *     mười hai bản đồ nền xám hôm nay khi tile.openstreetmap.org trả 503.
 *  2. **Next tự chèn `<link rel="preload">`** cho đúng tệp font mà trang
 *     dùng. Bản `<link>` stylesheet thì trình duyệt phải tải CSS trước, đọc
 *     `@font-face`, rồi mới xin font — hai vòng nối tiếp, và chữ nhảy đúng
 *     quãng đó.
 *  3. Subset tự cắt theo `subsets`, nên tệp nhỏ hơn.
 *
 * Giữ nguyên khoảng độ đậm 300–800 và biến thể nghiêng: `frontpage.css` khai
 * các độ đậm trong khoảng đó, và nạp hẹp hơn thì trình duyệt tự tổng hợp nét
 * đậm thiếu — chữ trông khác bản Blade ở đúng những chỗ ấy.
 *
 * `vietnamese` không phải tuỳ chọn: thiếu nó thì dấu tiếng Việt rơi về font
 * dự phòng và chữ có dấu trông khác chữ không dấu trong cùng một dòng.
 */
const openSans = Open_Sans({
    subsets: ['latin', 'latin-ext', 'vietnamese'],
    style: ['normal', 'italic'],
    display: 'swap',
    variable: '--font-open-sans',
});

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
        <html lang="vi" className={openSans.variable}>
            <head>
                {/*
                  Material Symbols vẫn đi qua `<link>`, khác với Open Sans ở
                  trên.

                  Vì sao không chuyển nốt: trang dùng đúng BA icon, nên cái giá
                  của một vòng mạng ở đây nhỏ. Còn cái giá của việc chuyển thì
                  không nhỏ — font này có bốn trục biến thiên
                  (`opsz,wght,FILL,GRAD`), và `next/font` phải khai cố định
                  từng trục. Khai sai một trục là icon đổi hình, và không phép
                  kiểm nào của dự án nhìn thấy hình icon.
                */}
                <link rel="preconnect" href="https://fonts.googleapis.com" />
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
