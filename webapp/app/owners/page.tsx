import type { Metadata } from 'next';
import Link from 'next/link';
import { listOwners } from '@/lib/api';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { Pagination } from '@/components/Pagination';

/**
 * `/owners` — danh sách media owner.
 *
 * ══ Không in chữ "verified" ══
 *
 * Bản Blade từng in "VERIFIED PARTNERS" và "N+ đối tác verified", trong khi
 * `getOwnersPaginated()` chỉ lọc `status = 'active'` — cột `verified` có trong
 * bảng mà không được dùng. Đã sửa ở bản Blade ngày 03/10 (audit F-15), và bản
 * này không mang lỗi đó sang: `/api/v2/owners` cũng không trả cờ verified nào,
 * nên ở đây không có gì để in.
 *
 * Cũng không có dấu "+" sau con số: `meta.total` là số đúng, không phải "hơn".
 */

const PER_PAGE = 24;

export const metadata: Metadata = buildMetadata({
    title: 'Media Owners | OOHX',
    description:
        'Danh sách chủ sở hữu màn hình OOH/DOOH đang hoạt động trên OOHX. ' +
        'Đặt booking trực tiếp, không qua trung gian.',
    path: '/owners',
});

type SearchParams = { page?: string; q?: string; type?: string };

export default async function OwnersPage({
    searchParams,
}: {
    searchParams: Promise<SearchParams>;
}) {
    const params = await searchParams;
    const page = Math.max(1, Number.parseInt(params.page ?? '1', 10) || 1);

    const { data: owners, meta } = await listOwners({
        page,
        per_page: PER_PAGE,
        q: params.q,
        type: params.type,
    });

    return (
        <>
            <SiteHeader active="owners" />
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
                            ĐỐI TÁC
                        </div>
                        <h1>Media Owners</h1>
                        <p>
                            {new Intl.NumberFormat('vi-VN').format(meta.total ?? 0)} đối tác đang
                            hoạt động. Đặt booking trực tiếp không qua trung gian.
                        </p>
                    </div>
                </div>

                <div className="w">
                    {owners.length === 0 ? (
                        <div
                            style={{ padding: '64px 20px', textAlign: 'center', color: 'var(--t4)' }}
                        >
                            Không có đối tác nào khớp bộ lọc.
                        </div>
                    ) : (
                        <div className="oc-grid oc-grid--full">
                            {owners.map((owner) => (
                                <article className="oc" key={owner.slug}>
                                    <Link href={`/owners/${owner.slug}`} className="oc-cover">
                                        {owner.cover_url ? (
                                            // eslint-disable-next-line @next/next/no-img-element
                                            <img
                                                src={owner.cover_url}
                                                alt={owner.name ?? ''}
                                                loading="lazy"
                                            />
                                        ) : null}
                                    </Link>

                                    <div className="oc-body">
                                        <h3 className="oc-name">
                                            <Link href={`/owners/${owner.slug}`}>{owner.name}</Link>
                                        </h3>

                                        {owner.type ? <div className="oc-type">{owner.type}</div> : null}

                                        <div className="oc-stats">
                                            <span>
                                                {new Intl.NumberFormat('vi-VN').format(
                                                    owner.screen_count ?? 0,
                                                )}{' '}
                                                màn hình
                                            </span>
                                        </div>
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}

                    <Pagination basePath="/owners" params={params} meta={meta} />
                </div>
            </main>
        </>
    );
}
