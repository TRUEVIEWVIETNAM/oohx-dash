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
    children,
}: {
    href: string;
    className?: string;
    children: ReactNode;
}) {
    return trongApp(href) ? (
        <Link href={href} className={className}>
            {children}
        </Link>
    ) : (
        <a href={href} className={className}>
            {children}
        </a>
    );
}
