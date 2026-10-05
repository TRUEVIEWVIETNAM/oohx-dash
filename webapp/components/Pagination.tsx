import Link from 'next/link';
import type { PageMeta } from '@/lib/api';

/**
 * Phân trang bằng thẻ `<a>`, không bằng nút gọi JS.
 *
 * Hai lý do: công cụ tìm kiếm đi theo được, và nó hoạt động khi JS chưa tải
 * xong. Một trang danh mục mà phải chờ JS mới xem được trang 2 thì không giải
 * quyết được việc giai đoạn 6 đặt ra.
 *
 * `rel="prev"` và `rel="next"` để công cụ tìm kiếm hiểu quan hệ chuỗi trang.
 *
 * ══ Giữ nguyên mọi tham số khác ══
 *
 * Bấm sang trang 2 khi đang lọc theo thành phố thì vẫn phải còn lọc đó. Vòng
 * lặp dưới sao lại tất cả tham số trừ `page` — không khai danh sách cố định,
 * vì thêm một bộ lọc mới mà quên thêm vào danh sách là lỗi im lặng: trang 2 trả
 * kết quả khác trang 1 và không ai biết vì sao.
 */
export function Pagination({
    basePath,
    params,
    meta,
}: {
    basePath: string;
    params: Record<string, string | undefined>;
    meta: PageMeta;
}) {
    const page = meta.page ?? 1;
    const lastPage = meta.last_page ?? 1;

    if (lastPage <= 1) return null;

    const href = (target: number) => {
        const search = new URLSearchParams();

        for (const [key, value] of Object.entries(params)) {
            if (key === 'page' || value === undefined || value === '') continue;
            search.set(key, value);
        }

        if (target > 1) search.set('page', String(target));

        const query = search.toString();

        return query ? `${basePath}?${query}` : basePath;
    };

    return (
        <nav
            aria-label="Phân trang"
            style={{ display: 'flex', justifyContent: 'center', gap: 12, padding: '32px 0 64px' }}
        >
            {page > 1 ? (
                <Link className="btn btn-s btn-sm" href={href(page - 1)} rel="prev">
                    Trang trước
                </Link>
            ) : null}

            <span style={{ alignSelf: 'center', fontSize: 14, color: 'var(--t2)' }}>
                Trang {page} / {lastPage}
            </span>

            {page < lastPage ? (
                <Link className="btn btn-s btn-sm" href={href(page + 1)} rel="next">
                    Trang sau
                </Link>
            ) : null}
        </nav>
    );
}
