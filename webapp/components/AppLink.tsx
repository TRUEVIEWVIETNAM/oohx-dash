import Link from 'next/link';
import type { ReactNode } from 'react';
import { trongApp } from '@/lib/duong-dan';

/**
 * Liên kết nội bộ: `next/link` nếu app này phục vụ đường đó, `<a>` nếu không.
 *
 * Mọi liên kết nội bộ của thanh điều hướng và chân trang đi qua đây, nên khi
 * mở thêm một context trong `nextjs.conf` thì chỉ phải sửa `TRONG_APP` ở
 * `lib/duong-dan.ts` — không phải đi tìm từng dòng `<a>` trong hai component.
 *
 * Trước đây phép kiểm này nằm trong `SiteHeader.tsx` và chân trang **không**
 * dùng nó: chân trang viết cứng `<a href>` cho mọi mục. Nghĩa là luật "khớp
 * với nextjs.conf" chỉ được áp cho thanh điều hướng, trong khi chân trang có
 * liên kết tới đúng những đường dẫn đó và xuất hiện trên **mọi** trang.
 */
export function AppLink({
    href,
    className,
    onNavigate,
    children,
}: {
    href: string;
    className?: string;
    /**
     * Chạy khi người dùng bấm vào liên kết.
     *
     * Có mặt vì menu trượt phải tự đóng sau khi điều hướng: ở bản Blade cả
     * trang tải lại nên lớp phủ biến mất theo, còn ở Next điều hướng là phía
     * client — lớp phủ sẽ ở lại che trang mới.
     */
    onNavigate?: () => void;
    children: ReactNode;
}) {
    return trongApp(href) ? (
        <Link href={href} className={className} onClick={onNavigate}>
            {children}
        </Link>
    ) : (
        <a href={href} className={className} onClick={onNavigate}>
            {children}
        </a>
    );
}
