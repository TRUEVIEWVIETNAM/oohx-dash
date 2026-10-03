import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import './globals.css';

/**
 * Layout gốc.
 *
 * ══ `lang="vi"` ══
 *
 * CLAUDE.md mục 3: "Tiếng Việt là mặc định trên toàn bộ trang công khai."
 * Thẻ này cũng là thứ công cụ tìm kiếm đọc để biết ngôn ngữ trang.
 *
 * ══ Không có header/footer ở đây ══
 *
 * Có chủ ý, và đây là chỗ dễ làm sai nhất của giai đoạn 6.
 *
 * Giai đoạn 6 chuyển TỪNG đường dẫn trên cùng một tên miền: `/explore` do Next
 * phục vụ, `/map` và `/products` vẫn do Laravel. Nếu dựng lại header ở đây mà
 * lệch một chữ so với `resources/views/frontpage/layouts/app.blade.php` thì
 * người dùng thấy thanh điều hướng nhảy khi bấm từ `/explore` sang `/map` —
 * đúng kiểu lỗi mà lộ trình gọi là "hai giao diện lệch hành vi".
 *
 * Nên bước đầu chỉ chuyển phần THÂN trang và giữ nguyên bộ khung bằng cách
 * dùng chung stylesheet. Header/footer dựng lại là việc của bước sau, làm một
 * lần cho tất cả các trang đã chuyển, và phải đối chiếu HTML với bản Blade.
 */
export const metadata: Metadata = {
    // `metadataBase` để Next dựng URL tuyệt đối cho og:image khi chỗ gọi chỉ
    // đưa đường dẫn tương đối.
    metadataBase: new URL(process.env.OOHX_PUBLIC_ORIGIN ?? 'https://oohx.net'),
    title: 'OOHX',
};

export default function RootLayout({ children }: { children: ReactNode }) {
    return (
        <html lang="vi">
            <body>{children}</body>
        </html>
    );
}
