import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { Montserrat } from 'next/font/google';

/**
 * Font tự host qua `next/font`, thay cho `<link>` tới fonts.googleapis.com.
 *
 * Ba lý do dưới đây viết cho Open Sans (lát 6 của giai đoạn chuyển đổi) và
 * **vẫn đúng nguyên văn cho Montserrat** — chúng là lý do chọn `next/font`,
 * không phải lý do chọn một họ font. Giữ lại ở đây vì đó là chỗ duy nhất ghi
 * vì sao bản Next không dùng `<link>` cho font chữ, trong khi vẫn dùng `<link>`
 * cho Material Symbols.
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
 * `vietnamese` không phải tuỳ chọn: thiếu nó thì dấu tiếng Việt rơi về font
 * dự phòng và chữ có dấu trông khác chữ không dấu trong cùng một dòng.
 */
/*
 * ══ Open Sans ĐÃ GỠ — lát 4, 09/11/2026 ══
 *
 * Lát 1 nạp Montserrat **cạnh** Open Sans vì chỉ trang nào bọc nội dung trong
 * `.v2` mới dùng font mới; 13 trang còn lại vẫn là Open Sans. Hai họ font cùng
 * lúc là cái giá của việc ghép từng trang, và lát 1 ghi rõ là trả lại ở lát 4.
 *
 * Hết lát 4, **cả 15 trang** Next đều có `.v2` — `webapp/test/seo.mjs` canh
 * đúng điều đó trong vòng lặp qua mọi trang. Nên không còn chữ nào trên bản
 * Next hiển thị bằng Open Sans, và tệp font vẫn tải về ở mọi lượt.
 *
 * `--font` của `globals.css` nay trỏ sang Montserrat, nên 43 luật trong
 * `frontpage.css` đọc `var(--font)` cũng ra Montserrat — kể cả nếu có phần tử
 * nào nằm ngoài `.v2`.
 *
 * **Bản Blade không bị ảnh hưởng:** nó nạp `frontpage.css` qua `@vite` và
 * không thấy `globals.css`. Trang Blade vẫn Open Sans qua dãy font của chính
 * `frontpage.css`. Đổi font cho khu quản trị và Blade là một việc riêng, và nó
 * còn trong bảng "chủ dự án cần chốt" của plan.
 */

/**
 * Montserrat tự host — lát 1 của `docs/PLAN-GHEP-MOCKUP.md`.
 *
 * Chủ dự án chốt Montserrat cho toàn bộ website ngày 08/11/2026. Lát 1 nạp nó
 * **cạnh** Open Sans vì khi đó chỉ trang chủ bọc nội dung trong `.v2`.
 *
 * Từ lát 4 (09/11/2026) nó là font **duy nhất** của bản Next: cả 15 trang đều
 * có `.v2`, và `Open_Sans` đã gỡ — xem chú thích ở trên.
 *
 * Bốn độ đậm 400/500/600/700 — đúng bộ mà chuẩn mockup khai, không hơn. Nạp
 * rộng hơn là tải tệp font không ai dùng; nạp hẹp hơn thì trình duyệt tự tổng
 * hợp nét đậm thiếu và chữ trông khác board ở đúng những chỗ ấy.
 *
 * `vietnamese` không phải tuỳ chọn, cùng lý do như Open Sans ở trên: thiếu nó
 * thì dấu tiếng Việt rơi về font dự phòng và chữ có dấu trông khác chữ không
 * dấu trong cùng một dòng.
 */
const montserrat = Montserrat({
    subsets: ['latin', 'latin-ext', 'vietnamese'],
    weight: ['400', '500', '600', '700'],
    display: 'swap',
    variable: '--font-montserrat',
});

import { SiteFooter } from '@/components/SiteFooter';
import { TrialNotice } from '@/components/TrialNotice';
import './globals.css';
import './oohx.css';

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
        <html lang="vi" className={montserrat.variable}>
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
                {/*
                  Bản **Rounded** cho hệ thiết kế mới — lát 1 của
                  `docs/PLAN-GHEP-MOCKUP.md`. Chuẩn mockup dùng Rounded; hệ cũ
                  dùng Outlined ở ba icon trên 13 trang chưa ghép.

                  Hai font icon cùng lúc là một vòng mạng thừa, và đó là cái
                  giá của việc ghép từng trang. Gộp ngay bây giờ nghĩa là đổi
                  hình ba icon trên 13 trang, và không phép kiểm nào của dự án
                  nhìn thấy hình icon. Bỏ dòng Outlined ở trên khi lát 4 xong.
                */}
                <link
                    rel="stylesheet"
                    href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0..1,0&display=swap"
                />
            </head>
            <body>
                {/*
                  `.v2` bọc cả ô thông báo thử nghiệm — lát 4, 09/11/2026.

                  Nó là chrome toàn site như dải pháp lý, nhưng nó render ở
                  `<body>` chứ không trong `<main>` của trang, nên nó là thứ
                  DUY NHẤT từng nằm ngoài phạm vi hệ mới. Hệ quả: luật
                  `.v2 .trial-notice-in` viết ở lát 4 là một luật chết, và ô
                  thông báo giữ bo góc với bóng của hệ cũ ngay trên một trang
                  đã phẳng.
                */}
                {TRIAL_MODE ? (
                    <div className="v2">
                        <TrialNotice />
                    </div>
                ) : null}
                {children}
                <SiteFooter />
            </body>
        </html>
    );
}
