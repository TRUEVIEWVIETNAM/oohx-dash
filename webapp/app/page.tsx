import type { Metadata } from 'next';
import Link from 'next/link';
import { getHomeData, DEFAULT_CITY } from '@/lib/home';
import { buildMetadata, jsonLd, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { ScreenCard } from '@/components/ScreenCard';

/**
 * Trang chủ — đường dẫn cuối của giai đoạn 6 trước nhóm trang chính sách.
 *
 * ══ Không mục nào bịa số ══
 *
 * Audit F-15 dọn khỏi trang chủ Blade: "30M+ impressions", bộ đếm chạy bằng
 * `Math.random()`, "AI Match 94%", "Tăng fill rate lên 40%", badge "Còn trống"
 * in vô điều kiện, và 5 nút CTA không có hành vi. Trang này **không dựng lại**
 * thứ nào trong số đó.
 *
 * Mọi con số ở đây đến từ API. Mục nào không có dữ liệu thì **không render** —
 * không có placeholder, không có "đang cập nhật", không có số tròn cho đẹp.
 * Thiếu một khối thì người đọc thấy ít hơn; một con số sai thì họ tin vào thứ
 * không có.
 *
 * ══ Mục "vị trí nổi bật" hôm nay chỉ có 1 thẻ ══
 *
 * Không phải lỗi render. `getFeaturedScreens()` đòi màn hình có **ảnh riêng**
 * (`screen_specs.photo_url` hoặc `photos`) và **có giá**. Đo trên production
 * ngày 05/10/2026: cả 50 màn hình trong mẫu đều lùi về cover của owner, tức
 * không ai có ảnh riêng — chỉ 1 trên 104 màn hình thoả cả hai điều kiện.
 *
 * Đó là chuyện dữ liệu, không phải chuyện code, nên trang hiện đúng những gì
 * có.
 */

export async function generateMetadata(): Promise<Metadata> {
    const { stats } = await getHomeData();

    const desc = stats
        ? `Tìm, so sánh và đặt booking billboard, LED, LCD trong vài phút. ` +
          `${new Intl.NumberFormat('vi-VN').format(stats.total_screens)} vị trí từ ` +
          `${new Intl.NumberFormat('vi-VN').format(stats.total_owners)} media owner tại ` +
          `${new Intl.NumberFormat('vi-VN').format(stats.total_cities)} tỉnh thành.`
        : null;

    return buildMetadata({
        title: 'OOHX – Marketplace OOH/DOOH',
        // `null` thì `buildMetadata` dùng mô tả mặc định. Không tự dựng một câu
        // có số khi không lấy được số.
        description: desc,
        path: '/',
    });
}

export default async function HomePage() {
    const { stats, featuredScreens, featuredOwners, regions, filters } =
        await getHomeData(DEFAULT_CITY);

    return (
        <>
            <SiteHeader active="home" />
            <OgType type="website" />

            <script
                type="application/ld+json"
                dangerouslySetInnerHTML={jsonLd({
                    '@context': 'https://schema.org',
                    '@type': 'WebSite',
                    name: 'OOHX',
                    url: 'https://oohx.net',
                    inLanguage: 'vi-VN',

                    // `SearchAction` trỏ vào `/explore?q=` — một đường thật,
                    // đang chạy. Khai một `SearchAction` cho đường không có
                    // là nói sai với công cụ tìm kiếm.
                    potentialAction: {
                        '@type': 'SearchAction',
                        target: {
                            '@type': 'EntryPoint',
                            urlTemplate: 'https://oohx.net/explore?q={search_term_string}',
                        },
                        'query-input': 'required name=search_term_string',
                    },
                })}
            />

            <main>
                {/* ── Hero ───────────────────────────────────────────────── */}
                <section className="hero">
                    <div className="w">
                        <div className="hero-text">
                            <h1 className="hero-hed" style={{ marginBottom: 18 }}>
                                Marketplace OOH/DOOH
                                <br />
                                <span className="acc">thông minh nhất Việt Nam.</span>
                            </h1>

                            <p className="hero-desc">
                                Tìm, so sánh và đặt booking billboard · LED · LCD trong vài phút.
                                {stats ? (
                                    <>
                                        {' '}
                                        {new Intl.NumberFormat('vi-VN').format(
                                            stats.total_screens,
                                        )}{' '}
                                        vị trí từ{' '}
                                        {new Intl.NumberFormat('vi-VN').format(
                                            stats.total_owners,
                                        )}{' '}
                                        media owner.
                                    </>
                                ) : null}
                            </p>

                            <div className="hero-actions">
                                <Link href="/explore" className="btn btn-p btn-lg">
                                    Khám phá inventory
                                </Link>
                                <Link href="/map" className="btn btn-s btn-lg">
                                    Xem bản đồ
                                </Link>
                            </div>

                            {stats ? (
                                <div className="hero-stats">
                                    <Stat
                                        value={stats.total_screens}
                                        label="vị trí"
                                    />
                                    <Stat value={stats.total_owners} label="media owner" />
                                    <Stat value={stats.total_cities} label="tỉnh thành" />
                                </div>
                            ) : null}
                        </div>
                    </div>
                </section>

                {/* ── Loại điểm đặt ──────────────────────────────────────── */}
                {filters?.venue_types && filters.venue_types.length > 0 ? (
                    <section className="w" style={{ padding: '48px 0' }}>
                        <div className="sec-head">
                            <span className="eyebrow">LOẠI ĐỊA ĐIỂM</span>
                            <h2 className="section-hed">Duyệt theo địa điểm</h2>
                        </div>

                        <div className="cat-chips">
                            {filters.venue_types.map((facet) => (
                                <Link
                                    key={facet.code ?? facet.name}
                                    className="cat-chip"
                                    href={`/explore?venue_type=${encodeURIComponent(facet.code ?? '')}`}
                                >
                                    {facet.name}
                                    {facet.count ? (
                                        <> ({new Intl.NumberFormat('vi-VN').format(facet.count)})</>
                                    ) : null}
                                </Link>
                            ))}
                        </div>
                    </section>
                ) : null}

                {/* ── Vị trí nổi bật ─────────────────────────────────────── */}
                {featuredScreens.length > 0 ? (
                    <section className="w" style={{ padding: '48px 0' }}>
                        <div className="sec-head">
                            <span className="eyebrow">PREMIUM POSITIONS</span>
                            {/*
                              "Vị trí nổi bật", KHÔNG phải "Đang còn trống".
                              Tiêu đề cũ hứa điều truy vấn không bảo đảm —
                              `getFeaturedScreens()` lọc có ảnh + có giá, không
                              lọc còn suất (audit F-15, đã sửa ở bản Blade).
                            */}
                            <h2 className="section-hed">Vị trí nổi bật</h2>
                        </div>

                        <div className="oc-grid oc-grid--full">
                            {featuredScreens.map((screen) => (
                                <ScreenCard
                                    key={screen.slug}
                                    screen={screen}
                                    availability={screen.availability}
                                />
                            ))}
                        </div>

                        <p style={{ paddingTop: 20 }}>
                            <Link href="/explore" className="btn btn-s btn-sm">
                                Xem tất cả
                            </Link>
                        </p>
                    </section>
                ) : null}

                {/* ── Media owner ────────────────────────────────────────── */}
                {featuredOwners.length > 0 ? (
                    <section className="w" style={{ padding: '48px 0' }}>
                        <div className="sec-head">
                            {/*
                              "ĐỐI TÁC", không "VERIFIED PARTNERS": truy vấn lọc
                              `status = 'active'`, không đọc cột `verified`
                              (audit F-15, đã sửa ở bản Blade).
                            */}
                            <span className="eyebrow">ĐỐI TÁC</span>
                            <h2 className="section-hed">Media owner trên sàn</h2>
                        </div>

                        <div className="oc-grid oc-grid--full">
                            {featuredOwners.map((owner) => (
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
                    </section>
                ) : null}

                {/* ── Tỉnh thành theo vùng ───────────────────────────────── */}
                {regions.length > 0 ? (
                    <section className="w" style={{ padding: '48px 0 64px' }}>
                        <div className="sec-head">
                            <span className="eyebrow">MARKET COVERAGE</span>
                            <h2 className="section-hed">
                                {stats
                                    ? `${new Intl.NumberFormat('vi-VN').format(stats.total_cities)} tỉnh thành`
                                    : 'Tỉnh thành có inventory'}
                            </h2>
                        </div>

                        <div className="ft-g">
                            {regions.map((region) => (
                                <div className="ft-col" key={region.code ?? region.name}>
                                    <h4>{region.name}</h4>
                                    <ul className="ft-ls">
                                        {region.provinces.map((province) => (
                                            <li key={province.code}>
                                                <Link
                                                    href={`/explore?city=${encodeURIComponent(province.code)}`}
                                                >
                                                    {province.name} (
                                                    {new Intl.NumberFormat('vi-VN').format(
                                                        province.count,
                                                    )}
                                                    )
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    </section>
                ) : null}
            </main>
        </>
    );
}

function Stat({ value, label }: { value: number; label: string }) {
    return (
        <div>
            <strong>{new Intl.NumberFormat('vi-VN').format(value)}</strong> {label}
        </div>
    );
}
