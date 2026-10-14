import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import {
    getOwner,
    getFilters,
    getLocations,
    type Filters,
    type OwnerDetail,
    type OwnerPublicReview,
    type Region,
} from '@/lib/api';
import { buildMetadata, jsonLd, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { ScreenCard } from '@/components/ScreenCard';
import { Pagination } from '@/components/Pagination';
import { MegaSearch } from '@/components/MegaSearch';
import { HoSoOwner, BangTruot } from '@/components/HoSoOwner';

/**
 * `/owners/{slug}` — trang hồ sơ một media owner, kèm kho màn hình của họ.
 *
 * ══ Lát 5: từ một tiêu đề + lưới thành bố cục hồ sơ ══
 *
 * Bản Blade 438 dòng, bố cục kiểu trang doanh nghiệp: ảnh bìa, avatar chồng
 * lên bìa, hàng tên + tagline + meta + nút liên hệ, hai tab, cột trái bốn thẻ
 * (giới thiệu, thống kê, loại địa điểm, đánh giá người mua), cột phải băng
 * trượt màn hình nổi bật, và tab thứ hai là kho đầy đủ có ô tìm kiếm lớn.
 *
 * Bản Next trước lát này: `pg-hero` + một `<h2>` + lưới thẻ. Không khối nào ở
 * trên.
 *
 * ══ `contact` NAY hiện lên trang — và đây là một đổi ý có lý do ══
 *
 * Bản Next trước ghi: *"tôi không in `contact`, vì một trang công khai in
 * email và số điện thoại là một trang cho máy thu thập địa chỉ"*, và nói thêm
 * *"bản Blade cũng không in"*. **Câu thứ hai sai**:
 * `frontpage/owner-detail.blade.php` in cả email, điện thoại, website và địa
 * chỉ trong thẻ "Giới thiệu", cộng hai nút "Liên hệ" / "Gửi email" ở hàng
 * tiêu đề. Chúng đã công khai từ trước.
 *
 * Và lý do thứ nhất không đứng được một mình: sàn **không thu hộ** (người mua
 * chuyển khoản trực tiếp cho owner, hồ sơ Bộ Công Thương), nên không thấy
 * đường liên hệ owner thì không ai đặt được gì. Lát 4 đã mở đúng hai trường
 * này ở `/explore/{slug}` cho cùng lý do.
 *
 * ══ JSON-LD là `Organization`, không phải `Product` ══
 *
 * Một media owner là tổ chức, không phải hàng bán. Dùng `Product` cho nó là
 * nói sai với công cụ tìm kiếm, và sai theo kiểu nó sẽ đi tìm giá và tình
 * trạng còn hàng — hai thứ không tồn tại ở mức này.
 */

const PER_PAGE = 24;

/** Số thẻ trong băng trượt "Màn hình nổi bật" — đúng `take(8)` của bản Blade. */
const SO_THE_NOI_BAT = 8;

type Params = { slug: string };
type SearchParams = {
    page?: string;
    tab?: string;
    q?: string;
    city?: string;
    venue_type?: string;
    network?: string;
    sort?: string;
};

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

    /*
     * Ba lời gọi song song, `allSettled`: hồ sơ là nội dung của trang, hai cái
     * sau chỉ nuôi ô tìm kiếm trong tab "Màn hình". `/locations` hỏng thì
     * dropdown rỗng, không phải trang trắng — cùng cách `/explore` làm từ lát 3.
     */
    const [ketQua, vungMien, boLoc] = await Promise.allSettled([
        getOwner(slug, {
            page,
            per_page: PER_PAGE,
            q: query.q,
            city: query.city,
            venue_type: query.venue_type,
            network: query.network,
            sort: query.sort,
        }),
        getLocations(),
        getFilters(),
    ]);

    if (ketQua.status === 'rejected') throw ketQua.reason;

    const result = ketQua.value;

    if (!result) {
        notFound();
    }

    const regions: Region[] = vungMien.status === 'fulfilled' ? vungMien.value : [];
    const filters: Filters | null = boLoc.status === 'fulfilled' ? boLoc.value : null;

    const owner = result.data;
    const screens = result.screens;
    const reviews: OwnerPublicReview[] = result.reviews ?? [];
    const rating = result.rating ?? { average: null, count: 0 };

    /*
     * Tab mở sẵn do MÁY CHỦ quyết, không do một `useEffect` nhảy sau khi tải.
     *
     * Bản Blade mở "Tổng quan" rồi nhảy sang "Màn hình" trong một IIFE nếu URL
     * có bộ lọc — người dùng thấy một nhịp nội dung sai trước khi nó biến mất.
     */
    const coBoLoc = Boolean(query.q || query.city || query.venue_type || query.network);
    const tabBanDau = query.tab === 'inventory' || coBoLoc || page > 1 ? 'inventory' : 'overview';

    const soManHinh = screens.meta.total ?? 0;
    const chuDau = chuCaiDau(owner.name ?? '');
    const diaChi = [owner.contact?.address, owner.location?.commune, owner.location?.province]
        .filter(Boolean)
        .join(', ');

    return (
        <>
            <SiteHeader active="owners" />
            {/*
              `profile`, không phải `website`: bản Blade phát `og:type=profile`
              cho trang này. Đổi nó là đổi cách mạng xã hội dựng thẻ xem trước
              cho đúng URL đó.
            */}
            <OgType type="profile" />

            <script
                type="application/ld+json"
                dangerouslySetInnerHTML={jsonLd({
                    '@context': 'https://schema.org',
                    '@type': 'Organization',
                    name: owner.name,
                    description: describe(owner),
                    ...(owner.logo_url ? { logo: owner.logo_url } : {}),
                    ...(owner.contact?.website ? { url: owner.contact.website } : {}),
                    ...(owner.location?.province
                        ? {
                              address: {
                                  '@type': 'PostalAddress',
                                  addressRegion: owner.location.province,
                                  ...(owner.contact?.address
                                      ? { streetAddress: owner.contact.address }
                                      : {}),
                              },
                          }
                        : {}),
                    ...(owner.founded ? { foundingDate: String(owner.founded) } : {}),

                    // `aggregateRating` chỉ khi CÓ đánh giá thật.
                    //
                    // Khai nó với `ratingValue: 0` là nói với công cụ tìm kiếm
                    // rằng owner này bị đánh giá 0/5, trong khi sự thật là
                    // chưa ai đánh giá. Cùng loại lỗi `price: 0` ở JSON-LD
                    // trang màn hình (audit F-15).
                    ...(rating.count > 0 && rating.average !== null
                        ? {
                              aggregateRating: {
                                  '@type': 'AggregateRating',
                                  ratingValue: rating.average,
                                  reviewCount: rating.count,
                              },
                          }
                        : {}),
                })}
            />

            <main>
                <div className="fp-cover">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img
                        className="fp-cover-img"
                        src={owner.cover_url ?? owner.logo_url ?? ''}
                        alt={owner.name ?? ''}
                    />

                    <div className="fp-cover-inner">
                        <div className="fp-avatar-wrap">
                            <div className="fp-avatar">
                                {owner.logo_url ? (
                                    // eslint-disable-next-line @next/next/no-img-element
                                    <img src={owner.logo_url} alt={owner.name ?? ''} />
                                ) : (
                                    chuDau
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="fp-header">
                    <div className="fp-header-spacer" />

                    <div className="fp-header-info">
                        <div className="fp-name">{owner.name}</div>
                        {owner.tagline ? <div className="fp-tagline">{owner.tagline}</div> : null}

                        <div className="fp-meta">
                            <span className="fp-meta-item">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M3 3v8h8V3H3zm6 6H5V5h4v4zm-6 4v8h8v-8H3zm6 6H5v-4h4v4zm4-16v8h8V3h-8zm6 6h-4V5h4v4zm-6 4v8h8v-8h-8zm6 6h-4v-4h4v4z" />
                                </svg>
                                {owner.stats?.screen_count ?? 0} màn hình
                            </span>

                            <span className="fp-meta-item">
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 110-5 2.5 2.5 0 010 5z" />
                                </svg>
                                {owner.stats?.city_count ?? 0} tỉnh/thành
                            </span>

                            {owner.type ? (
                                <span className="fp-meta-item">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z" />
                                    </svg>
                                    {nhanLoai(owner.type)}
                                </span>
                            ) : null}

                            {/*
                              Điểm trung bình chỉ hiện khi CÓ đánh giá. Bản
                              Blade cũng vậy (`$ratingSummary['count'] > 0`) —
                              giữ nguyên, vì in "0,0 ★" cho một owner chưa ai
                              đánh giá là nói sai.
                            */}
                            {rating.count > 0 && rating.average !== null ? (
                                <span className="fp-meta-item own-rating">
                                    <span className="rv-stars" aria-hidden="true">
                                        ★
                                    </span>
                                    <span className="own-rating-avg">
                                        {rating.average.toFixed(1)}
                                    </span>
                                    <span className="own-rating-cnt">
                                        ({rating.count} đánh giá)
                                    </span>
                                </span>
                            ) : null}
                        </div>
                    </div>

                    <div className="fp-header-actions">
                        {owner.contact?.phone ? (
                            <a href={`tel:${owner.contact.phone}`} className="btn btn-s btn-sm">
                                Liên hệ
                            </a>
                        ) : null}

                        {owner.contact?.email ? (
                            <a href={`mailto:${owner.contact.email}`} className="btn btn-p btn-sm">
                                Gửi email
                            </a>
                        ) : null}
                    </div>
                </div>

                <HoSoOwner
                    tabBanDau={tabBanDau}
                    soManHinh={soManHinh}
                    tongQuan={
                        <div className="fp-overview">
                            <div className="fp-sidebar">
                                <div className="fp-card">
                                    <div className="fp-card-title">Giới thiệu</div>

                                    {owner.about ? (
                                        <div
                                            style={{
                                                fontSize: 14,
                                                color: 'var(--t2)',
                                                lineHeight: 1.6,
                                                marginBottom: 12,
                                                whiteSpace: 'pre-line',
                                            }}
                                        >
                                            {owner.about}
                                        </div>
                                    ) : null}

                                    {owner.contact?.website ? (
                                        <DongTin nhan="Website">
                                            <a
                                                href={owner.contact.website}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                {bỏGiaoThuc(owner.contact.website)}
                                            </a>
                                        </DongTin>
                                    ) : null}

                                    {owner.contact?.email ? (
                                        <DongTin nhan="Email">
                                            <a href={`mailto:${owner.contact.email}`}>
                                                {owner.contact.email}
                                            </a>
                                        </DongTin>
                                    ) : null}

                                    {owner.contact?.phone ? (
                                        <DongTin nhan="Điện thoại">{owner.contact.phone}</DongTin>
                                    ) : null}

                                    {owner.founded ? (
                                        <DongTin nhan="Năm thành lập">Từ {owner.founded}</DongTin>
                                    ) : null}

                                    {diaChi ? <DongTin nhan="Trụ sở">{diaChi}</DongTin> : null}
                                </div>

                                <div className="fp-card">
                                    <div className="fp-card-title">Thống kê</div>
                                    <div className="fp-stats-grid">
                                        <OThongKe
                                            so={owner.stats?.screen_count ?? 0}
                                            nhan="Màn hình"
                                        />
                                        <OThongKe
                                            so={owner.stats?.city_count ?? 0}
                                            nhan="Tỉnh/Thành"
                                        />
                                        <OThongKe
                                            so={owner.stats?.venue_types?.length ?? 0}
                                            nhan="Loại địa điểm"
                                        />

                                        {/*
                                          "Kinh nghiệm" tính từ `founded`, và
                                          chỉ khi có `founded`. Bản Blade dùng
                                          `now()->year - $owner->founded`; giữ
                                          đúng phép đó, nhưng kẹp ở 0 — một
                                          `founded` tương lai (lỗi nhập liệu)
                                          sẽ cho số âm, và "−3 năm kinh nghiệm"
                                          là một con số không ai đọc ra là lỗi
                                          dữ liệu.
                                        */}
                                        {owner.founded ? (
                                            <OThongKe
                                                so={Math.max(
                                                    0,
                                                    new Date().getFullYear() - Number(owner.founded),
                                                )}
                                                nhan="Kinh nghiệm"
                                                donVi=" năm"
                                            />
                                        ) : null}
                                    </div>
                                </div>

                                {owner.stats?.venue_types?.length ? (
                                    <div className="fp-card">
                                        <div className="fp-card-title">Loại địa điểm</div>
                                        <div className="fp-venue-tags">
                                            {owner.stats.venue_types.map((vt) => (
                                                <span className="fp-venue-tag" key={vt}>
                                                    {vt}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                ) : null}

                                {reviews.length ? (
                                    <div className="fp-card">
                                        <div className="fp-card-title">
                                            Đánh giá từ người mua ({rating.count})
                                        </div>

                                        {reviews.map((rv, i) => (
                                            <div className="own-rv" key={`${rv.organization}-${i}`}>
                                                <div className="own-rv-hd">
                                                    <span className="own-rv-org">
                                                        {rv.organization}
                                                    </span>
                                                    <span
                                                        className="rv-stars"
                                                        aria-label={`${rv.rating} trên 5 sao`}
                                                    >
                                                        {'★'.repeat(rv.rating)}
                                                        {'☆'.repeat(Math.max(0, 5 - rv.rating))}
                                                    </span>
                                                </div>

                                                {rv.comment ? (
                                                    <p className="own-rv-cmt">{rv.comment}</p>
                                                ) : null}

                                                {rv.published_at ? (
                                                    <div className="own-rv-date">
                                                        {ngayVN(rv.published_at)}
                                                    </div>
                                                ) : null}
                                            </div>
                                        ))}
                                    </div>
                                ) : null}
                            </div>

                            <div style={{ minWidth: 0 }}>
                                {screens.data.length ? (
                                    <BangTruot>
                                        {screens.data.slice(0, SO_THE_NOI_BAT).map((screen) => (
                                            <ScreenCard key={screen.slug} screen={screen} compact />
                                        ))}
                                    </BangTruot>
                                ) : (
                                    <div className="fp-card">
                                        <div className="fp-card-title">Màn hình nổi bật</div>
                                        <div style={{ fontSize: 14, color: 'var(--t4)' }}>
                                            Media owner này chưa công khai màn hình nào.
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    }
                    kho={
                        <>
                            <div className="fp-inv-filter">
                                <MegaSearch
                                    variant="compact"
                                    target={`/owners/${slug}`}
                                    regions={regions}
                                    categories={filters?.categories_networks ?? []}
                                    params={query}
                                />
                            </div>

                            <div className="fp-inv-header">
                                <div className="avail-badge">
                                    <div className="avail-dot" />
                                    {new Intl.NumberFormat('vi-VN').format(soManHinh)} vị trí
                                </div>
                            </div>

                            {screens.data.length === 0 ? (
                                <div
                                    style={{
                                        padding: '48px 20px',
                                        textAlign: 'center',
                                        color: 'var(--t4)',
                                    }}
                                >
                                    Không có vị trí nào khớp bộ lọc.
                                </div>
                            ) : (
                                <div className="inv-grid">
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
                        </>
                    }
                />
            </main>
        </>
    );
}

/** Một dòng thông tin trong thẻ "Giới thiệu" — nhãn nằm DƯỚI giá trị, như bản Blade. */
function DongTin({ nhan, children }: { nhan: string; children: React.ReactNode }) {
    return (
        <div className="fp-info-row">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z" />
            </svg>
            <div>
                <div className="fp-info-val">{children}</div>
                <div className="fp-info-label">{nhan}</div>
            </div>
        </div>
    );
}

function OThongKe({ so, nhan, donVi }: { so: number; nhan: string; donVi?: string }) {
    return (
        <div className="fp-stat">
            <div className="fp-stat-n">
                {new Intl.NumberFormat('vi-VN').format(so)}
                {donVi ? <span className="u">{donVi}</span> : null}
            </div>
            <div className="fp-stat-l">{nhan}</div>
        </div>
    );
}

/** Hai chữ cái đầu của tên, dùng khi owner không có logo. */
function chuCaiDau(ten: string): string {
    return ten
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((t) => t[0] ?? '')
        .join('')
        .toUpperCase();
}

function bỏGiaoThuc(url: string): string {
    return url.replace(/^https?:\/\/(www\.)?/, '');
}

/** `2026-10-14` → `14/10/2026`, không qua `new Date()` để không lệch múi giờ. */
function ngayVN(iso: string): string {
    const [nam, thang, ngay] = iso.slice(0, 10).split('-');

    return ngay && thang && nam ? `${ngay}/${thang}/${nam}` : iso;
}

/** `media_owner` → `Media owner`. Cùng phép của bản Blade (`ucfirst(str_replace)`). */
function nhanLoai(type: string): string {
    const s = type.replace(/_/g, ' ');

    return s.charAt(0).toUpperCase() + s.slice(1);
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
