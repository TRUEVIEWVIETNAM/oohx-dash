import type { Metadata } from 'next';
import Link from 'next/link';
import { listReflections, type Reflection } from '@/lib/api';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { Pagination } from '@/components/Pagination';

/**
 * `/phan-anh-to-chuc-xa-hoi/danh-sach` — phản ánh đã công bố và kết quả xử lý.
 *
 * ══ Lọc "đã công bố" KHÔNG nằm ở đây ══
 *
 * `GET /api/v2/reflections` đã chỉ trả bản đã công bố, và nó dùng chung
 * `PublicReflectionService::published()` với trang Blade — một định nghĩa, hai
 * nơi hiển thị. Trang này không thêm điều kiện lọc nào, có chủ ý: thêm một
 * phép lọc ở tầng hiển thị là tạo định nghĩa thứ hai, và một phản ánh đang
 * trong quá trình xử lý lọt ra ngoài không phải lỗi giao diện.
 *
 * ══ Nhãn trạng thái lấy từ API ══
 *
 * `status_label` do API trả, không tra ở đây. Bốn nhãn đó là từ vựng nghiệp vụ
 * và chúng sống ở `PublicReflection::STATUS_LABELS`. Chép bảng tra sang đây là
 * tạo bản thứ hai, và khi thêm một trạng thái thì bên quên sửa sẽ hiện mã thô
 * (`in_review`) ra cho người đọc.
 *
 * ══ Dữ liệu người gửi ══
 *
 * `Reflection` không có `contact_*`, `internal_notes` hay `submitted_ip` —
 * endpoint không trả chúng, và `PublicContentApiTest` canh điều đó. Nên trang
 * này không thể lộ chúng kể cả khi ai đó vô ý in cả đối tượng ra.
 */

const PER_PAGE = 20;

const TIEU_DE = 'Danh sách phản ánh của tổ chức xã hội';

type SearchParams = { page?: string };

export const metadata: Metadata = buildMetadata({
    title: `${TIEU_DE} – OOHX`,
    description:
        'Các phản ánh của tổ chức xã hội mà sàn OOHX đã tiếp nhận, cùng kết quả xử lý từng trường hợp.',
    path: '/phan-anh-to-chuc-xa-hoi/danh-sach',
});

export default async function ReflectionListPage({
    searchParams,
}: {
    searchParams: Promise<SearchParams>;
}) {
    const query = await searchParams;
    const page = Math.max(1, Number.parseInt(query.page ?? '1', 10) || 1);

    const result = await listReflections({ page, per_page: PER_PAGE });

    return (
        <>
            <SiteHeader />
            <OgType type="website" />

            <main>
                <section className="pol">
                    <div className="w pol-w">
                        <nav className="pol-crumb" aria-label="Breadcrumb">
                            <Link href="/">Trang chủ</Link>
                            <span aria-hidden="true">/</span>
                            <span>{TIEU_DE}</span>
                        </nav>

                        <h1 className="pol-h1">{TIEU_DE}</h1>
                        <p className="pol-lead">
                            Các phản ánh đã tiếp nhận và kết quả xử lý. Gửi phản ánh mới tại{' '}
                            <Link href="/phan-anh-to-chuc-xa-hoi">đây</Link>.
                        </p>

                        {result.data.length === 0 ? (
                            <div className="rfl-none">
                                <p>Chưa có phản ánh nào được công bố.</p>
                            </div>
                        ) : (
                            result.data.map((r) => <MotPhanAnh key={r.code} r={r} />)
                        )}

                        <Pagination
                            basePath="/phan-anh-to-chuc-xa-hoi/danh-sach"
                            params={query}
                            meta={result.meta}
                        />
                    </div>
                </section>
            </main>
        </>
    );
}

function MotPhanAnh({ r }: { r: Reflection }) {
    return (
        <article className="rfl">
            <div className="rfl-top">
                <h2 className="rfl-subject">{r.subject}</h2>
                {r.status ? (
                    <span className={`rfl-badge rfl-badge--${r.status}`}>
                        {r.status_label ?? r.status}
                    </span>
                ) : null}
            </div>

            <div className="rfl-meta">
                <span>{r.organization_name}</span>
                <span aria-hidden="true">·</span>
                <span>Tiếp nhận {ngay(r.received_at)}</span>
                <span aria-hidden="true">·</span>
                <code>{r.code}</code>
            </div>

            <p className="rfl-content">{r.content}</p>

            {r.resolution ? (
                <div className="rfl-res">
                    <h3>Kết quả xử lý</h3>
                    <p>{r.resolution}</p>
                    {r.resolved_at ? <small>Ngày xử lý: {ngay(r.resolved_at)}</small> : null}
                </div>
            ) : null}
        </article>
    );
}

/**
 * ISO 8601 → `dd/mm/yyyy`, khớp `->format('d/m/Y')` của bản Blade.
 *
 * Định dạng ngày là trình bày, không phải từ vựng nghiệp vụ, nên làm ở đây là
 * đúng chỗ — khác với `status_label`. Nhưng vẫn phải khớp bản Blade: cùng một
 * phản ánh hiện hai kiểu ngày ở hai trang là thứ người đọc thấy ngay.
 *
 * Ghim múi giờ, không để máy chủ quyết. Tiến trình Node chạy giờ nào thì
 * `toLocaleDateString` theo giờ đó, và một mốc lúc 23:30 giờ Việt Nam sẽ lùi
 * một ngày nếu máy chủ chạy UTC.
 */
function ngay(iso: string | null | undefined): string {
    if (!iso) return '—';

    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';

    return new Intl.DateTimeFormat('vi-VN', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        timeZone: 'Asia/Ho_Chi_Minh',
    }).format(d);
}
