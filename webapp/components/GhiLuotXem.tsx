'use client';

import { useEffect } from 'react';
import { ghiLuotXem } from '@/lib/luot-xem';

/**
 * Cộng một lượt xem cho trang chi tiết đang mở — lát 2 của
 * `docs/PLAN-LUOT-XEM.md`.
 *
 * Không vẽ gì ra DOM. Nó tồn tại chỉ để có **một chỗ chạy trong trình duyệt**
 * trên một trang vốn render hoàn toàn ở máy chủ.
 *
 * ══ Vì sao phải là một component client, không phải một dòng trong page ══
 *
 * `page.tsx` của trang này là Server Component: nó chạy ở máy chủ, một lần cho
 * mỗi lượt render — và một lượt render **không phải** một lượt người xem. Cùng
 * lý do như docblock `LuotXemController`: bot đọc sitemap, Next render lại khi
 * cache hết hạn, và `next build` prerender đều là những lượt render không có
 * ai đọc. Đếm ở máy chủ là đo lưu lượng crawler.
 *
 * `useEffect` chỉ chạy trong trình duyệt, sau khi trang đã hiện ra cho một
 * người thật. Đó là định nghĩa gần nhất với "một lượt xem" mà sàn có.
 *
 * ══ Mảng phụ thuộc có `slug`, và nó quan trọng ══
 *
 * Next điều hướng phía client giữa hai trang `/explore/{slug}` **không** dựng
 * lại component — nó chỉ đổi props. Để `[]` thì effect chạy đúng một lần cho
 * cả chuỗi vị trí người dùng xem trong một phiên duyệt, và mọi vị trí sau vị
 * trí đầu tiên **vĩnh viễn không được đếm** mà không gì đỏ.
 *
 * Phép gộp "một lượt cho mỗi màn hình trong mỗi phiên" nằm trong `ghiLuotXem`,
 * không ở đây — xem `lib/luot-xem.ts`.
 */
export function GhiLuotXem({ slug }: { slug: string }) {
    useEffect(() => {
        ghiLuotXem(slug);
    }, [slug]);

    return null;
}
