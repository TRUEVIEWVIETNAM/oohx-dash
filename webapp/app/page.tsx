import type { Metadata } from 'next';
import Link from 'next/link';
import { getHomeData, DEFAULT_CITY, type HomeData } from '@/lib/home';
import { ANH_HERO_DASHBOARD } from '@/lib/anh-hero';
import { buildMetadata, jsonLd, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { ScreenCard } from '@/components/ScreenCard';
import { OwnerCard } from '@/components/OwnerCard';
import { MegaSearch } from '@/components/MegaSearch';
import { BanDoTrangChu } from '@/components/BanDoTrangChu';
import { ProductCard } from '@/components/ProductCard';
import { ThanhTrust, MucAI } from '@/components/KhoiLoiHua';

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
    const { stats, featuredScreens, featuredOwners, regions, filters, pins, products } =
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
                    <div className="w" style={{ textAlign: 'center' }}>
                        {/*
                          Thẻ đếm inventory đang mở đặt lịch. Con số là
                          `stats.total_screens` — cùng con số ba ô dưới dùng,
                          nên không có chỗ nào để hai số lệch nhau.
                        */}
                        {stats ? (
                            <div className="hero-badge" style={{ display: 'inline-flex' }}>
                                <div className="hero-badge-dot" />
                                {new Intl.NumberFormat('vi-VN').format(stats.total_screens)}{' '}
                                inventory đang mở đặt lịch
                            </div>
                        ) : null}

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
                        </div>

                        {/*
                          Ô tìm kiếm lớn — biến thể `hero`.

                          Chip gợi ý dựng đúng cách bản Blade dựng: ba nhóm loại
                          hình đầu ghép với tên tỉnh thành đông inventory nhất,
                          rồi nhóm thứ tư đứng một mình. Không có nguồn nào khác
                          cho chúng, và bịa ra một truy vấn gợi ý là bịa ra một
                          tính năng.
                        */}
                        <MegaSearch
                            variant="hero"
                            target="/explore"
                            regions={regions}
                            categories={filters?.categories_networks ?? []}
                            chips={chipGoiY(filters)}
                        />

                        <div className="hero-actions" style={{ marginTop: 28 }}>
                            <Link href="/explore" className="btn btn-p btn-lg">
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="#fff"
                                    style={{ width: 18, height: 18, flexShrink: 0 }}
                                    aria-hidden="true"
                                >
                                    <path d="M3 3v8h8V3H3zm6 6H5V5h4v4zm-6 4v8h8v-8H3zm6 6H5v-4h4v4zm4-16v8h8V3h-8zm6 6h-4V5h4v4zm-6 4v8h8v-8h-8zm6 6h-4v-4h4v4z" />
                                </svg>{' '}
                                Khám phá Inventory
                            </Link>
                            <Link href="/map" className="btn btn-s btn-lg">
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="var(--t2)"
                                    style={{ width: 18, height: 18, flexShrink: 0 }}
                                    aria-hidden="true"
                                >
                                    <path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z" />
                                </svg>{' '}
                                Xem bản đồ
                            </Link>
                        </div>

                        {/*
                          "Miễn phí đăng ký · Không phí môi giới" là chính sách
                          thật, nên nó ở lại. Câu "30M+ impressions" đứng cạnh
                          nó trong bản Blade thì không có nguồn nào và đã bị gỡ
                          từ đợt dọn F-15 — không mang lại.
                        */}
                        <div className="hero-hint">Miễn phí đăng ký · Không phí môi giới</div>

                        {/*
                          Ba ô số liệu dùng đúng bộ class `hstat`. Bản trước vẽ
                          `<div><strong>…</strong></div>` nên ba ô không có kiểu
                          nào dù `.hstat`, `.hstat-n`, `.hstat-l` đều có trong
                          CSS.

                          Một chỗ cố ý khác bản Blade: nó in
                          `total_screens / 1000` kèm "K+", nên 104 màn hình hiện
                          ra "0.1K+". Ở đây in số thật. Làm tròn một con số nhỏ
                          thành đơn vị nghìn là nói sai về quy mô, theo cả hai
                          chiều.
                        */}
                        {stats ? (
                            <div className="hero-stats">
                                <Hstat value={stats.total_screens} label="Inventory" />
                                <Hstat value={stats.total_cities} label="Tỉnh thành" />
                                <Hstat value={stats.total_owners} label="Media owners" />
                            </div>
                        ) : null}
                    </div>

                    {/*
                      Ảnh mô phỏng bảng điều khiển. Là SVG vẽ tay, không mang số
                      liệu nào — nên không thuộc nhóm F-15. Hai thẻ nổi từng đè
                      lên nó ("Live Impressions" đếm bằng `Math.random()`,
                      "AI Match 94%") đã bị gỡ ở đợt dọn đó và không được dựng
                      lại.
                    */}
                    <div className="w" style={{ paddingBottom: 0 }}>
                        <div className="hero-visual" style={{ position: 'relative', padding: '0 20px' }}>
                            <div className="hero-browser">
                                <div className="hb-bar">
                                    <div className="hb-dots">
                                        <div className="hb-dot" style={{ background: '#FF5F57' }} />
                                        <div className="hb-dot" style={{ background: '#FEBC2E' }} />
                                        <div className="hb-dot" style={{ background: '#28C840' }} />
                                    </div>
                                    <div className="hb-url">oohx.net</div>
                                </div>
                                <div className="hb-screen">
                                    {/* eslint-disable-next-line @next/next/no-img-element */}
                                    <img src={ANH_HERO_DASHBOARD} alt="Giao diện bảng điều khiển OOHX" />
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/*
                  Thanh năm mục, nguyên văn bản Blade. Chủ dự án chốt dựng lại
                  ngày 18/10/2026 sau khi đọc danh sách những câu không kiểm
                  được — xem docblock của `KhoiLoiHua.tsx`.
                */}
                <ThanhTrust />

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

                        <div className="inv-grid">
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

                {/* ── Live Map View ──────────────────────────────────────── */}
                <section className="sec sec-gray">
                    <div className="w">
                        <div className="sec-head">
                            <div className="sec-head-row">
                                <div>
                                    <span className="eyebrow">GEO INTELLIGENCE</span>
                                    <h2 className="section-hed">Live Map View</h2>
                                    <p
                                        className="section-sub"
                                        style={{ marginTop: 10, fontSize: 16 }}
                                    >
                                        OOH là business của vị trí — xem thực tế trên bản đồ.
                                    </p>
                                </div>
                                <Link
                                    href="/map"
                                    className="btn btn-p btn-sm"
                                    style={{ marginTop: 6, flexShrink: 0 }}
                                >
                                    <svg
                                        viewBox="0 0 24 24"
                                        fill="#fff"
                                        style={{ width: 14, height: 14, flexShrink: 0 }}
                                        aria-hidden="true"
                                    >
                                        <path d="M21 11V3h-8l3.29 3.29-10 10L3 13v8h8l-3.29-3.29 10-10z" />
                                    </svg>{' '}
                                    Mở bản đồ
                                </Link>
                            </div>
                        </div>

                        <BanDoTrangChu
                            pinBanDau={pins}
                            thanhPhoBanDau={DEFAULT_CITY}
                            tenThanhPhoBanDau={tenThanhPho(filters, DEFAULT_CITY)}
                            thanhPho={filters?.cities ?? []}
                        />
                    </div>
                </section>

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

                        {/*
                          `oc-grid` KHÔNG kèm `oc-grid--full`: biến thể `full`
                          là lưới 4 cột cho thẻ owner đầy đủ ở `/owners`, còn
                          trang chủ dùng thẻ `compact` trong lưới gốc — đúng
                          cặp mà bản Blade dùng.
                        */}
                        <div className="oc-grid">
                            {featuredOwners.map((owner, i) => (
                                <OwnerCard
                                    key={owner.slug}
                                    owner={owner}
                                    index={i}
                                    variant="compact"
                                />
                            ))}
                        </div>
                    </section>
                ) : null}


                {/* ── 4 bước đặt booking ─────────────────────────────────── */}
                <section className="sec sec-gray">
                    <div className="w">
                        <div style={{ textAlign: 'center', maxWidth: 560, margin: '0 auto 40px' }}>
                            <span className="eyebrow">WORKFLOW</span>
                            <h2 className="section-hed">4 bước đặt booking OOH</h2>
                            <p className="section-sub" style={{ margin: '12px auto 0', fontSize: 16 }}>
                                Không email qua lại. Tìm, kiểm tra, đặt và theo dõi trực tiếp.
                            </p>
                        </div>

                        {/*
                          Bốn thẻ, đúng bố cục bản Blade.

                          Chữ trong thẻ **đã viết lại**, và đó là một quyết định
                          cần nói ra. Bản Blade hứa bốn năng lực hôm nay không
                          kiểm được: "AI search", "traffic data", "so sánh tối
                          đa 5 vị trí" (không có chức năng so sánh nào) và
                          "proof-of-play report tự động" (đường ghi bằng chứng
                          phát sóng đã có, nhưng chưa thiết bị nào gửi dữ liệu).
                          Chữ ở đây mô tả đúng những gì bốn bước này làm được
                          hôm nay. Muốn đúng câu chữ cũ thì đó là một quyết định
                          về lời hứa sản phẩm, không phải một lần sửa giao diện.
                        */}
                        <div
                            className="steps-grid"
                            style={{
                                background: '#fff',
                                borderRadius: 'var(--r4)',
                                border: '1px solid var(--ln2)',
                                overflow: 'hidden',
                                boxShadow: 'var(--sh2)',
                            }}
                        >
                            {BON_BUOC.map((b) => (
                                <div className="step-card" key={b.so}>
                                    <div className="step-num" style={{ background: b.mau }}>
                                        {b.so}
                                    </div>
                                    <div className="step-title">{b.tieuDe}</div>
                                    <div className="step-desc">{b.moTa}</div>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>

                {/* ── Marketplace OOH đầu tiên có AI ─────────────────────── */}
                <MucAI />

                {/* ── Gói chiến dịch ─────────────────────────────────────── */}
                {products.length > 0 ? (
                    <section className="sec">
                        <div className="w">
                            <div style={{ textAlign: 'center', maxWidth: 560, margin: '0 auto 40px' }}>
                                <span className="eyebrow">GÓI DỊCH VỤ</span>
                                <h2 className="section-hed">Gói chiến dịch</h2>
                                <p className="section-sub" style={{ margin: '12px auto 0', fontSize: 16 }}>
                                    Chưa biết bắt đầu từ đâu? Xem các gói đang mở trên sàn.
                                </p>
                            </div>

                            {/*
                              Bản Blade vẽ ba thẻ gói **viết cứng trong
                              template** ("City Launch", "Retail Activation",
                              "Event Blitz"), và chú thích ở đó đã nêu nghi vấn:
                              không xác nhận được ba tên ấy ứng với bản ghi nào,
                              nên ba nút của chúng bị gỡ vì không có đích.

                              Mục này đọc **gói thật** từ `/api/v2/products` —
                              cùng dữ liệu `/products` đang phục vụ. Nên bấm vào
                              là tới đúng trang gói đó, và không còn một tên gói
                              nào trên trang mà hệ thống không biết.
                            */}
                            <div className="inv-grid">
                                {products.map((product) => (
                                    <ProductCard key={product.slug} product={product} />
                                ))}
                            </div>

                            <p style={{ paddingTop: 20, textAlign: 'center' }}>
                                <Link href="/products" className="btn btn-s btn-sm">
                                    Xem tất cả gói
                                </Link>
                            </p>
                        </div>
                    </section>
                ) : null}

                {/* ── Mời media owner lên sàn ────────────────────────────── */}
                <section className="sec">
                    <div className="w">
                        <div className="owner-cta">
                            <div className="oc-mesh" />
                            <div className="oc-in">
                                <div>
                                    <div className="oc-title">Đưa inventory của bạn lên OOHX</div>
                                    <p className="oc-desc">
                                        Tiếp cận agency &amp; brand đang tìm inventory mỗi ngày.
                                        Quản lý availability và nhận booking trực tiếp.
                                    </p>
                                    {/*
                                      Hai nút của bản Blade ("Đăng ký làm chủ sở
                                      hữu", "Tìm hiểu thêm") KHÔNG dựng lại: cả
                                      hai là `<button>` trần không hành vi, và
                                      `/register` hôm nay chỉ dành cho NGƯỜI MUA
                                      — không có đường đăng ký nào cho media
                                      owner. Thêm một route mới là thêm tính
                                      năng, không phải dựng lại giao diện.
                                    */}
                                </div>

                                <div className="oc-features">
                                    {BA_LOI_ICH.map((f) => (
                                        <div className="oc-feat" key={f.tieuDe}>
                                            <div className="oc-feat-icon">
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    fill="rgba(255,255,255,.9)"
                                                    style={{ width: 18, height: 18 }}
                                                    aria-hidden="true"
                                                >
                                                    <path d={f.icon} />
                                                </svg>
                                            </div>
                                            <div>
                                                <div className="oc-feat-title">{f.tieuDe}</div>
                                                <div className="oc-feat-sub">{f.moTa}</div>
                                            </div>
                                        </div>
                                    ))}
                                    {/*
                                      Thẻ thứ tư của bản Blade — "Tăng fill rate
                                      lên 40%" — không dựng lại. Fill rate cần
                                      dữ liệu phát sóng thật, và `impression_logs`
                                      hôm nay chưa có bản ghi nào, nên 40% là một
                                      lời hứa kinh doanh không có nguồn (F-15).
                                    */}
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </main>
        </>
    );
}

/**
 * Bốn bước của mục WORKFLOW.
 *
 * Màu lấy đúng bản Blade (xanh · lục · cam · tím), vì nó là thứ tự màu người
 * đọc thấy. Chữ thì viết lại — lý do ghi ngay tại khối render.
 */
const BON_BUOC = [
    {
        so: '01',
        mau: 'var(--bl)',
        tieuDe: 'Tìm kiếm & lọc',
        moTa: 'Lọc theo tỉnh thành, loại điểm đặt, network và khoảng giá. Xem ngay trên bản đồ.',
    },
    {
        so: '02',
        mau: '#34C759',
        tieuDe: 'So sánh',
        moTa: 'Ảnh thực địa, thông số kỹ thuật, suất còn trống 30 ngày tới và giá niêm yết.',
    },
    {
        so: '03',
        mau: '#FF9F0A',
        tieuDe: 'Gửi booking',
        moTa: 'Điền thông tin chiến dịch, tải nội dung lên. Media owner nhận yêu cầu ngay.',
    },
    {
        so: '04',
        mau: '#AF52DE',
        tieuDe: 'Theo dõi & báo cáo',
        moTa: 'Bảng điều khiển theo trạng thái từng dòng đặt chỗ, kèm báo cáo chiến dịch.',
    },
];

/**
 * Ba lợi ích trong khối mời media owner.
 *
 * Cả ba mô tả chức năng đang chạy trong panel `/publisher`: nhập kho, quản lý
 * suất, và thông báo khi có yêu cầu đặt chỗ (`BookingSubmittedNotification`).
 * Thẻ thứ tư của bản Blade hứa một con số kết quả kinh doanh và không được
 * dựng lại — xem chú thích ở khối render.
 */
const BA_LOI_ICH = [
    {
        tieuDe: 'Đăng inventory nhanh chóng',
        moTa: 'Tải ảnh, thông số và vị trí trong vài phút',
        icon: 'M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-8 14H7v-2h4v2zm6-4H7v-2h10v2zm0-4H7V7h10v2z',
    },
    {
        tieuDe: 'Quản lý suất còn trống',
        moTa: 'Cập nhật lịch và thời lượng đã bán theo từng màn hình',
        icon: 'M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11z',
    },
    {
        tieuDe: 'Nhận thông báo khi có yêu cầu',
        moTa: 'Không bỏ lỡ một yêu cầu đặt chỗ nào',
        icon: 'M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z',
    },
];

function Hstat({ value, label }: { value: number; label: string }) {
    return (
        <div className="hstat">
            <div className="hstat-n">{new Intl.NumberFormat('vi-VN').format(value)}</div>
            <div className="hstat-l">{label}</div>
        </div>
    );
}

/**
 * Chip gợi ý tìm kiếm cho ô tìm kiếm lớn.
 *
 * Dựng đúng cách bản Blade dựng (`index.blade.php`, khối `@php` trước
 * `@include` mega-search): ba nhóm loại hình đầu, mỗi cái ghép với một trong
 * hai tỉnh thành đông inventory nhất, rồi nhóm thứ tư đứng một mình.
 *
 * Chúng chỉ là **chữ điền vào ô tìm kiếm**, không phải một truy vấn gợi ý —
 * nên không có gì ở đây nói một điều có thể sai.
 */
/**
 * Tên tỉnh thành cho bộ đếm dưới bản đồ, tra từ danh sách `filters.cities`.
 *
 * Không có bảng slug → tên nào ở phía Next: bảng đó đã có ở
 * `FrontpageService::CITY_SLUG_MAP`, và chép nó sang đây là tạo bản thứ hai sẽ
 * lệch khi thêm tỉnh. Không tra được thì trả chính slug — một chữ đúng nhưng
 * chưa đẹp, hơn là một tên đoán sai.
 */
function tenThanhPho(filters: HomeData['filters'], slug: string): string {
    return (filters?.cities ?? []).find((c) => c.code === slug)?.name ?? slug;
}

function chipGoiY(filters: HomeData['filters']): string[] {
    const nhom = (filters?.venue_types ?? []).filter((v) => v.name);
    const tinh = (filters?.cities ?? []).filter((c) => c.name).slice(0, 2);

    const ra = nhom.slice(0, 3).map((v, i) => {
        const ten = tinh[i]?.name ?? tinh[0]?.name ?? '';

        return ten ? `${v.name} ${ten}` : String(v.name);
    });

    if (nhom.length > 3 && nhom[3].name) ra.push(String(nhom[3].name));

    return ra;
}
