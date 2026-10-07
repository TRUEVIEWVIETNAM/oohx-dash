import type { Metadata } from 'next';
import { listAgencies, type AgencySummary } from '@/lib/api';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { Pagination } from '@/components/Pagination';

/**
 * `/agency` — agency và brand đang dùng sàn.
 *
 * Đây là trang công khai CUỐI CÙNG rời Blade. Sau nó, Laravel chỉ còn phục vụ
 * API, `sitemap.xml`, `robots.txt` và Filament.
 *
 * ══ Bốn thứ của bản Blade KHÔNG chép sang ══
 *
 * Bản Blade mang bốn khiếm khuyết cùng loại audit F-15 ("phản hồi như đã làm
 * việc trong khi không làm gì"), và chép chúng sang là mang theo lỗi đã biết:
 *
 * 1. Thẻ agency trỏ `href="#"` — `$agencyLink = '#'; // Future: /agencies/{slug}`.
 *    Chưa có trang chi tiết agency, nên ở đây thẻ KHÔNG phải liên kết.
 * 2. Hai nút "Xem chi tiết" và "Liên hệ" là `<span class="btn">` — trông như
 *    nút, bấm không ra gì.
 * 3. Ô "Rating" luôn hiện `—`. Một ô số không có dữ liệu phía sau.
 * 4. Ô tìm kiếm có `name="q"` nhưng KHÔNG nằm trong `<form>`, nên gõ xong bấm
 *    Enter thì không có gì xảy ra.
 *
 * Cái thứ tư thì **sửa**, không gỡ: `getAgenciesPaginated()` đã nhận `q` từ
 * lâu, nên nó chỉ thiếu dây nối. Đúng việc đã làm cho `/owners` ở giai đoạn 4.
 *
 * Cái thứ nhất, hai và ba thì gỡ: chúng cần thứ chưa tồn tại (trang chi tiết,
 * luồng liên hệ, dữ liệu đánh giá), và làm chúng chạy là thêm tính năng mới.
 *
 * ══ Ảnh bìa `placehold.co` cũng không mang sang ══
 *
 * Bản Blade dựng ảnh bìa từ một dịch vụ ngoài, truyền tên agency vào URL. Nó
 * là ảnh bịa, và nó gửi tên khách hàng của sàn sang bên thứ ba ở mỗi lượt tải
 * trang. Thay bằng chữ viết tắt dựng tại chỗ.
 */

const PER_PAGE = 12;

const TIEU_DE = 'Agencies & Brands';

type SearchParams = { page?: string; q?: string };

export const metadata: Metadata = buildMetadata({
    title: `${TIEU_DE} | OOHX`,
    description:
        'Các agency và brand đang dùng OOHX để tìm, so sánh và đặt chỗ quảng cáo ngoài trời tại Việt Nam.',
    path: '/agency',
});

export default async function AgencyPage({
    searchParams,
}: {
    searchParams: Promise<SearchParams>;
}) {
    const query = await searchParams;
    const page = Math.max(1, Number.parseInt(query.page ?? '1', 10) || 1);
    const q = (query.q ?? '').trim();

    const result = await listAgencies({ page, per_page: PER_PAGE, q: q || undefined });

    return (
        <>
            <SiteHeader active="agency" />
            <OgType type="website" />

            <main>
                <div className="pg-hero">
                    <div className="w">
                        <div
                            style={{
                                fontSize: 13,
                                fontWeight: 700,
                                color: 'var(--bl)',
                                letterSpacing: '.3px',
                                marginBottom: 8,
                            }}
                        >
                            TRUSTED PARTNERS
                        </div>
                        <h1>{TIEU_DE}</h1>
                        <p>
                            {new Intl.NumberFormat('vi-VN').format(result.meta.total ?? 0)} agency và
                            brand đang sử dụng OOHX để đặt chỗ quảng cáo.
                        </p>

                        {/*
                          `<form method="GET">` — bản Blade có ô nhập với
                          `name="q"` nhưng KHÔNG có form bọc ngoài, nên gõ xong
                          bấm Enter thì không có gì xảy ra. API đã nhận `q` từ
                          lâu; đây chỉ là nối dây.
                        */}
                        <form className="pg-hero-search" method="GET" action="/agency">
                            <input
                                name="q"
                                defaultValue={q}
                                placeholder="Tìm agency/brand theo tên…"
                                maxLength={100}
                            />
                        </form>
                    </div>
                </div>

                <div className="w">
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            margin: '20px 0',
                        }}
                    >
                        <div style={{ fontSize: 14, fontWeight: 600, color: 'var(--t2)' }}>
                            Hiển thị{' '}
                            <strong style={{ color: 'var(--bl)' }}>
                                {new Intl.NumberFormat('vi-VN').format(result.meta.total ?? 0)}
                            </strong>{' '}
                            agency
                        </div>
                    </div>

                    {result.data.length === 0 ? (
                        <div style={{ textAlign: 'center', padding: '64px 20px', color: 'var(--t4)' }}>
                            <div style={{ fontSize: 16, fontWeight: 700, color: 'var(--t3)', marginBottom: 6 }}>
                                {q ? `Không có agency nào khớp “${q}”` : 'Chưa có agency nào'}
                            </div>
                            <div style={{ fontSize: 13 }}>
                                {q
                                    ? 'Thử một từ khoá ngắn hơn.'
                                    : 'Hãy là người đầu tiên tham gia cộng đồng OOHX.'}
                            </div>
                        </div>
                    ) : (
                        <div className="oc-grid oc-grid--full">
                            {result.data.map((a, i) => (
                                <TheAgency key={`${a.name}-${i}`} a={a} i={i} />
                            ))}
                        </div>
                    )}

                    <Pagination basePath="/agency" params={query} meta={result.meta} />
                </div>
            </main>
        </>
    );
}

/** Cặp màu cho chữ viết tắt. Chép đúng bảng màu bản Blade dùng. */
const MAU: [string, string][] = [
    ['#E8EAFF', '#2A4FF6'],
    ['#E3F8E8', '#34C759'],
    ['#F3E8FF', '#AF52DE'],
    ['#FFE8E8', '#FF3B30'],
    ['#E8F4FF', '#0071E3'],
    ['#FFF3E8', '#FF9F0A'],
];

/**
 * KHÔNG phải `<a>`.
 *
 * Chưa có trang chi tiết agency. Bản Blade dùng `<a href="#">`, tức một thẻ
 * trông bấm được mà bấm không đi đâu. `<article>` nói đúng nó là gì.
 */
function TheAgency({ a, i }: { a: AgencySummary; i: number }) {
    const [nen, chu] = MAU[i % MAU.length];

    const vietTat = (a.name ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');

    return (
        <article className="oc-card">
            <div className="oc-card-head">
                {a.logo_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={a.logo_url} className="oc-card-logo" loading="lazy" alt={a.name ?? ''} />
                ) : (
                    <div
                        className="oc-card-logo oc-card-logo--txt"
                        style={{ background: nen, color: chu }}
                    >
                        {vietTat}
                    </div>
                )}

                <div className="oc-card-name-wrap">
                    <div className="oc-card-name">{a.name}</div>
                    {a.website_host ? (
                        <div className="oc-card-ver" style={{ color: 'var(--t4)' }}>
                            {a.website_host}
                        </div>
                    ) : null}
                </div>
            </div>

            {/*
              MỘT ô số, không phải ba. Bản Blade có "Chiến dịch / Terms /
              Rating": Rating luôn rỗng, và Terms là điều khoản thương mại của
              một bên thứ ba trên một trang ai cũng mở được.
            */}
            <div className="oc-card-stats">
                <div className="oc-card-stat">
                    <div className="oc-card-stat-n">
                        {a.campaign_count ?? '—'}
                    </div>
                    <div className="oc-card-stat-l">Chiến dịch</div>
                </div>
            </div>
        </article>
    );
}
