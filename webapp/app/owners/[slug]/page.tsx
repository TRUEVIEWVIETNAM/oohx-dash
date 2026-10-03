import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { getOwner, type OwnerDetail } from '@/lib/api';
import { buildMetadata, jsonLd, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { ScreenCard } from '@/components/ScreenCard';
import { Pagination } from '@/components/Pagination';

/**
 * `/owners/{slug}` — trang một media owner, kèm danh sách màn hình của họ.
 *
 * ══ `contact` KHÔNG hiện lên trang ══
 *
 * `OwnerDetail` có khối `contact`. Tôi **không** in nó ra, và đây là quyết định
 * có chủ ý: một trang công khai in email và số điện thoại dưới dạng chữ là một
 * trang cho máy thu thập địa chỉ. Bản Blade cũng không in — nếu sau này cần
 * hiện liên hệ thì nó phải đi qua một biểu mẫu có giới hạn tần suất, không
 * phải một dòng chữ.
 *
 * ══ JSON-LD là `Organization`, không phải `Product` ══
 *
 * Một media owner là tổ chức, không phải hàng bán. Dùng `Product` cho nó là
 * nói sai với công cụ tìm kiếm, và sai theo kiểu nó sẽ đi tìm giá và tình
 * trạng còn hàng — hai thứ không tồn tại ở mức này.
 */

const PER_PAGE = 24;

type Params = { slug: string };
type SearchParams = { page?: string };

export async function generateMetadata({
    params,
}: {
    params: Promise<Params>;
}): Promise<Metadata> {
    const { slug } = await params;
    const result = await getOwner(slug);

    if (!result) {
        return buildMetadata({ title: 'Không tìm thấy đối tác | OOHX', path: `/owners/${slug}` });
    }

    const owner = result.data;

    return buildMetadata({
        title: `${owner.name ?? 'Media owner'} | OOHX`,
        description: describe(owner),
        path: `/owners/${slug}`,
        image: owner.cover_url ?? owner.logo_url,
    });
}

export default async function OwnerDetailPage({
    params,
    searchParams,
}: {
    params: Promise<Params>;
    searchParams: Promise<SearchParams>;
}) {
    const { slug } = await params;
    const query = await searchParams;
    const page = Math.max(1, Number.parseInt(query.page ?? '1', 10) || 1);

    const result = await getOwner(slug, { page, per_page: PER_PAGE });

    if (!result) {
        notFound();
    }

    const owner = result.data;
    const screens = result.screens;

    return (
        <>
            <SiteHeader active="owners" />
            <OgType type="website" />

            <script
                type="application/ld+json"
                dangerouslySetInnerHTML={jsonLd({
                    '@context': 'https://schema.org',
                    '@type': 'Organization',
                    name: owner.name,
                    description: describe(owner),
                    ...(owner.logo_url ? { logo: owner.logo_url } : {}),
                    ...(owner.location?.province ? { address: { "@type": "PostalAddress", addressRegion: owner.location.province } } : {}),
                    ...(owner.founded ? { foundingDate: String(owner.founded) } : {}),
                })}
            />

            <main>
                <div className="pg-hero">
                    <div className="w">
                        <h1>{owner.name}</h1>
                        {owner.tagline ? <p>{owner.tagline}</p> : null}
                    </div>
                </div>

                <div className="w">
                    {owner.about ? (
                        <section style={{ maxWidth: 760, padding: '24px 0' }}>
                            <p>{owner.about}</p>
                        </section>
                    ) : null}

                    <h2 className="section-hed">
                        Màn hình của {owner.name}
                        {screens.meta.total !== undefined ? (
                            <span style={{ color: 'var(--t3)', fontWeight: 400 }}>
                                {' '}
                                ({new Intl.NumberFormat('vi-VN').format(screens.meta.total)})
                            </span>
                        ) : null}
                    </h2>

                    {screens.data.length === 0 ? (
                        <div
                            style={{ padding: '48px 20px', textAlign: 'center', color: 'var(--t4)' }}
                        >
                            Đối tác này chưa công khai màn hình nào.
                        </div>
                    ) : (
                        <div className="oc-grid oc-grid--full">
                            {screens.data.map((screen) => (
                                <ScreenCard key={screen.slug} screen={screen} />
                            ))}
                        </div>
                    )}

                    <Pagination
                        basePath={`/owners/${slug}`}
                        params={query}
                        meta={screens.meta}
                    />
                </div>
            </main>
        </>
    );
}

/**
 * Mô tả cho `<meta name="description">` và JSON-LD — một hàm, hai chỗ dùng.
 *
 * `about` có thể dài và chứa thẻ; `buildMetadata` tự cắt ở 160 ký tự. Ưu tiên
 * `tagline` vì nó được viết để ngắn.
 */
function describe(owner: OwnerDetail): string {
    if (owner.tagline) return owner.tagline;
    if (owner.about) return owner.about;

    const parts = [owner.name, owner.location?.province].filter(Boolean);

    return parts.length ? `${parts.join(', ')} — media owner trên sàn OOHX.` : '';
}
