import type { Metadata } from 'next';
import Image from 'next/image';
import Link from 'next/link';
import {
    getHomeData,
    DEFAULT_CITY,
    type HomeData,
    type HeroStats,
    type ProductSummary,
    type ScreenSummary,
} from '@/lib/home';
import { buildMetadata, jsonLd, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { MegaSearch } from '@/components/MegaSearch';
import { BanDoTrangChu } from '@/components/BanDoTrangChu';
// `formatProductPrice` dùng chung với `ProductCard`: trang chủ và trang gói
// không được in hai con số khác nhau cho cùng một gói.
import { formatProductPrice } from '@/components/ProductCard';
import { ThanhTrust } from '@/components/KhoiLoiHua';

/**
 * Trang chủ — **lát 1 của `docs/PLAN-GHEP-MOCKUP.md`**, hệ thiết kế mới.
 *
 * ══ Mọi con số ở đây đến từ API ══
 *
 * Mục nào không có dữ liệu thì **không render** — không placeholder, không
 * "đang cập nhật", không số tròn cho đẹp. Thiếu một khối thì người đọc thấy ít
 * hơn; một con số sai thì họ tin vào thứ không có.
 *
 * ══ Bố cục, theo board `P-TrangChu` đã chốt 08/11/2026 ══
 *
 *   ① dải pháp lý — trên cả logo, như alibaba.com
 *   ② hero = ô tìm kiếm + bản đồ thật
 *   ③ menu ngang — **một khối** chạy hết bề ngang
 *   ④ dải cam kết
 *   ⑤ cặp panel: kho chưa có giá / media owner trên sàn
 *   ⑥ lưới vị trí đã công bố giá
 *   ⑦ gói chiến dịch
 *   ⑧ mức độ hoàn thiện dữ liệu kho
 *   ⑨ tỉnh thành — lưới ảnh, giữ lại vì SEO
 *
 * ══ Bốn khối của bố cục cũ KHÔNG dựng lại, và vì sao ══
 *
 *  - **Bốn bước đặt booking** và **hai ô cách mua**: chủ dự án chốt bỏ ngày
 *    08/11/2026 khi duyệt mockup (*"khối 6 bước 2 phía tôi thấy hơi vô duyên ở
 *    trang chủ"*). Cả hai đích vẫn còn đường tới từ ô tìm kiếm và từ mục gói.
 *  - **Ba bảo đảm** và **khối mời media owner**: gộp vào panel bên phải ở ⑤ và
 *    ô cuối của ③ — cùng nội dung, hết một khối rời.
 *  - **Ba câu "AI search theo khu vực / So sánh tối đa 5 vị trí /
 *    proof-of-play report tự động"**: `khoi-giao-dien.mjs` từng canh chúng
 *    **nguyên văn** bản Blade, kèm một luật nói rõ *"bản mock có bốn câu viết
 *    lại; luật này canh để không ai lấy nhầm từ đó"*. Quyết định 08/11/2026
 *    (*"viết lại nội dung formal hơn"*, rồi *"mockup chốt rồi"*) **đảo** quyết
 *    định ấy, nên luật kia được sửa cùng lượt này, không phải xoá lặng lẽ.
 *
 * ══ Hai thứ của bố cục cũ giữ lại DÙ board không vẽ ══
 *
 *  1. **Bản đồ thật ở hero.** Board vẽ mọi khung bản đồ là khung nét đứt rỗng
 *     vì BizmapLive do chủ dự án tích hợp. Nhưng trang chủ **đã có** bản đồ
 *     chạy thật từ 18/10/2026, nên thay nó bằng một khung rỗng là bước lùi.
 *  2. **Lưới tỉnh thành.** Board bỏ nó. Nhưng `khoi-giao-dien.mjs` ghi lý do
 *     nó tồn tại: hai lưới "duyệt" phải nằm trong HTML máy chủ trả về, *"nếu
 *     không, công cụ tìm kiếm mất một nửa số liên kết vào kho"*. Lưới loại hình
 *     đã được ③ thay thế (cùng liên kết, cùng số đếm); lưới tỉnh thành thì
 *     không có gì thay, nên nó ở lại.
 *
 * ══ `MegaSearch` và `ThanhTrust` chưa đổi theo hệ mới ══
 *
 * Hai component này mang class của `frontpage.css`, nên chúng vẫn trông theo
 * hệ cũ bên trong một trang theo hệ mới. Đó là một đường may **nhìn thấy
 * được**, và nó cố ý: cả hai mang hành vi thật (`MegaSearch` có hai dropdown
 * và gợi ý; `ThanhTrust` có bảng "mã nào đứng sau từng câu" ở
 * `KhoiLoiHua.tsx`). Dựng lại chúng trong cùng lượt này là đổi hành vi cùng
 * lúc với đổi ngoại hình, và khi có gì sai thì không biết do đâu. Lát 2.
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

const so = (n: number) => new Intl.NumberFormat('vi-VN').format(n);

/**
 * `sizes` phải khớp bề rộng THẬT của ô trong lưới, không phải một con số tròn.
 *
 * Sai một bậc ở đây không làm trang vỡ — nó chỉ lặng lẽ tải ảnh to gấp đôi,
 * đúng kiểu hỏng mà lát ảnh 18/10/2026 sinh ra để chữa.
 *
 *   .vt-luoi    auto-fill minmax(186px) ⇒ 6 cột ở 1240px, 2 cột ở 390px
 *   .tinh-luoi  auto-fill minmax(168px) ⇒ 7 cột ở 1240px, 2 cột ở 390px
 */
const SIZES_VT = '(min-width:1240px) 196px, (min-width:768px) 25vw, 50vw';
const SIZES_TINH = '(min-width:1240px) 170px, (min-width:768px) 25vw, 50vw';

export default async function HomePage() {
    const {
        stats,
        featuredOwners,
        regions,
        filters,
        pins,
        products,
        browse,
        quotedScreens,
        quotedTotal,
    } = await getHomeData(DEFAULT_CITY);

    const tongKho = stats?.total_screens ?? 0;
    const coGia = filters?.price_status?.quoted ?? quotedTotal;
    const chuaGia = filters?.price_status?.unquoted ?? 0;

    return (
        <>
            {/*
              ① Dải pháp lý — nay nằm TRONG `SiteHeader` (lát 2, 09/11/2026).
              ────────────────────────────────────────────────────────────────
              Lát 1 dựng nó ở đây, nên nó chỉ có trên trang chủ. Nhưng pháp
              nhân và mã số thuế là thông tin bắt buộc của một sàn TMĐT trên
              **mọi** trang công khai, không riêng trang đầu — và nó thuộc
              chrome dùng chung đúng như thanh đầu và chân trang.

              Lát 1 còn viết cứng bốn chuỗi ấy ở đây thay vì đọc
              `lib/company.ts`. Chuyển vào `SiteHeader` trả cả hai về đúng chỗ:
              một nguồn dữ liệu, và 14 trang thay vì một.
            */}
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

            {/*
              `v2` là phạm vi của hệ thiết kế mới — xem docblock `oohx.css`.
              Bỏ lớp này ra thì cả trang rơi về `frontpage.css`.
            */}
            <main className="v2">
                {/* ② HERO = ô tìm kiếm. Không tiêu đề quảng cáo. */}
                <section
                    style={{
                        background: 'linear-gradient(180deg,#F6F8FE 0%,#fff 100%)',
                        borderBottom: '1px solid var(--stroke)',
                    }}
                >
                    <div className="wrap" style={{ paddingTop: 26, paddingBottom: 26 }}>
                        <div
                            style={{
                                display: 'flex',
                                flexWrap: 'wrap',
                                gap: 26,
                                alignItems: 'flex-start',
                            }}
                        >
                            <div style={{ flex: '1 1 440px', minWidth: 0 }}>
                                <h1 style={{ fontSize: 28, fontWeight: 700, lineHeight: 1.25 }}>
                                    Kho vị trí OOH &amp; DOOH Việt Nam
                                </h1>

                                {stats ? (
                                    <p
                                        className="num"
                                        style={{
                                            margin: '8px 0 0',
                                            fontSize: 14,
                                            color: 'var(--body-ink)',
                                        }}
                                    >
                                        {so(stats.total_screens)} vị trí · {so(stats.total_cities)}{' '}
                                        tỉnh thành · {so(stats.total_owners)} media owner
                                    </p>
                                ) : null}

                                <div style={{ marginTop: 16 }}>
                                    <MegaSearch
                                        variant="hero"
                                        target="/explore"
                                        regions={regions}
                                        categories={filters?.categories_networks ?? []}
                                        chips={chipGoiY(filters)}
                                    />
                                </div>

                                <p
                                    style={{
                                        margin: '12px 0 0',
                                        fontSize: 12.5,
                                        color: 'var(--body-ink)',
                                    }}
                                >
                                    Miễn phí đăng ký · Không phí môi giới
                                </p>
                            </div>

                            {/*
                              Bản đồ THẬT, không phải khung nét đứt của board.
                              Xem docblock đầu tệp, mục "hai thứ giữ lại".
                            */}
                            <div style={{ flex: '1 1 380px', minWidth: 0 }}>
                                <BanDoTrangChu
                                    bienThe="hero"
                                    pinBanDau={pins}
                                    thanhPhoBanDau={DEFAULT_CITY}
                                    tenThanhPhoBanDau={tenThanhPho(filters, DEFAULT_CITY)}
                                    thanhPho={filters?.cities ?? []}
                                />
                            </div>
                        </div>
                    </div>
                </section>

                {/* ③ MENU NGANG — một khối duy nhất */}
                <div className="wrap" style={{ paddingTop: 18 }}>
                    <MenuNgang filters={filters} tongKho={tongKho} chuaGia={chuaGia} />
                </div>

                {/* ④ DẢI CAM KẾT */}
                <div className="wrap" style={{ paddingTop: 18 }}>
                    <ThanhTrust />
                </div>

                {/* ⑤ CẶP PANEL */}
                <div className="wrap" style={{ paddingTop: 22 }}>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 14 }}>
                        <PanelChuaGia chuaGia={chuaGia} tongKho={tongKho} />
                        <PanelOwner owners={featuredOwners} />
                    </div>
                </div>

                {/* ⑥ LƯỚI VỊ TRÍ ĐÃ CÔNG BỐ GIÁ */}
                {quotedScreens.length > 0 ? (
                    <div className="wrap" style={{ paddingTop: 26 }}>
                        <div className="shead">
                            <h2>Vị trí đã công bố giá</h2>
                            <p className="sub">
                                Giá niêm yết do media owner tự khai. Thứ tự dưới đây là thứ tự
                                API trả về, không phải xếp hạng — sàn chưa có dữ liệu lượt xem
                                hay lượt đặt để xếp.
                            </p>
                            <Link href="/explore?price_status=quoted" className="btn btn-s btn-o">
                                Xem tất cả
                            </Link>
                        </div>

                        <div className="vt-luoi">
                            {quotedScreens.map((s) => (
                                <TheViTri key={s.slug} screen={s} />
                            ))}

                            {chuaGia > 0 ? <OChuaGia chuaGia={chuaGia} /> : null}
                        </div>
                    </div>
                ) : null}

                {/* ⑦ GÓI CHIẾN DỊCH */}
                {products.length > 0 ? (
                    <div className="wrap" style={{ paddingTop: 26 }}>
                        <div
                            className="shead"
                            style={{ paddingTop: 20, borderTop: '1px solid var(--stroke)' }}
                        >
                            <h2>Gói chiến dịch</h2>
                            <p className="sub">
                                Gói do media owner dựng sẵn: nhiều vị trí, một mức giá.
                            </p>
                            <Link href="/products" className="btn btn-s btn-o">
                                Xem tất cả
                            </Link>
                        </div>

                        <div
                            style={{
                                display: 'grid',
                                gridTemplateColumns: 'repeat(auto-fill,minmax(260px,1fr))',
                                gap: 12,
                            }}
                        >
                            {products.map((p) => (
                                <TheGoi key={p.slug} goi={p} />
                            ))}
                        </div>
                    </div>
                ) : null}

                {/* ⑧ MỨC ĐỘ HOÀN THIỆN DỮ LIỆU KHO */}
                {tongKho > 0 ? (
                    <div className="wrap" style={{ paddingTop: 26 }}>
                        <HoanThienDuLieu
                            tongKho={tongKho}
                            coGia={coGia}
                            soOwner={stats?.total_owners ?? 0}
                        />
                    </div>
                ) : null}

                {/* ⑨ TỈNH THÀNH — giữ lại vì SEO, xem docblock đầu tệp */}
                {(browse?.cities ?? []).length > 0 ? (
                    <div className="wrap" style={{ paddingTop: 26, paddingBottom: 34 }}>
                        <div
                            className="shead"
                            style={{ paddingTop: 20, borderTop: '1px solid var(--stroke)' }}
                        >
                            <h2>Duyệt theo tỉnh thành</h2>
                            <p className="sub">
                                {stats ? `${so(stats.total_cities)} tỉnh thành có vị trí trên sàn.` : null}
                            </p>
                        </div>

                        <div className="tinh-luoi">
                            {(browse?.cities ?? []).map((c) => (
                                <Link
                                    key={c.code ?? ''}
                                    href={`/explore?city=${encodeURIComponent(c.code ?? '')}`}
                                    className="tinh"
                                >
                                    {/*
                                      `next/image`, giống `DuyetKho`. Ảnh tỉnh
                                      thành là PNG 800×600 (~1,15 MB) do quản
                                      trị tải lên và hiển thị ở ~170px. Đo
                                      18/10/2026 trước khi chuyển: 10,68 MB
                                      cho hai lưới; sau: 0,23 MB. Một `<img>`
                                      thô ở đây không làm trang vỡ — nó chỉ
                                      lặng lẽ kéo lại vài MB.
                                    */}
                                    {c.photo ? (
                                        <Image src={c.photo} alt="" fill sizes={SIZES_TINH} />
                                    ) : null}
                                    <span className="nhan">
                                        <span className="ten">{c.name}</span>
                                        <span className="so">{so(c.count ?? 0)} vị trí</span>
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </div>
                ) : null}
            </main>
        </>
    );
}

/* ── ③ Menu ngang ───────────────────────────────────────────────────────── */

/**
 * Một khối duy nhất, chạy hết bề ngang — khuôn lấy từ alibaba.com.
 *
 * ══ Năm hàng loại hình, không sáu ══
 *
 * Board vẽ sáu hàng. Dữ liệu thật chỉ có **năm** nhóm lọc được: ba loại hình
 * (`screen_types`) và hai loại điểm đặt (`venue_types`). Vẽ hàng thứ sáu nghĩa
 * là bịa một nhóm, hoặc tách một nhóm có thật thành hai lối dẫn tới cùng một
 * danh sách.
 *
 * Mọi số đếm ở đây là số của `/api/v2/filters`, tức đúng con số mà `/explore`
 * sẽ trả khi bấm vào. Hai nguồn khác nhau cho cùng một con số là *"bộ lọc nói
 * 30, bấm vào thấy 28"*.
 *
 * ══ Hai ô giữa KHÔNG phải "đã xem gần đây" ══
 *
 * Board vẽ hai ô cá nhân: *"Vị trí đã xem gần đây"* và *"Tiếp tục tìm"*. Cả
 * hai cần biết **một người** đã làm gì, và sàn cố ý không lưu thứ đó — xem
 * `docs/PLAN-LUOT-XEM.md`, lát 4: nó thuộc `localStorage` của từng trình
 * duyệt, chưa dựng. Nên hai ô ở đây là hai lối đi **thật** thay vào, không
 * phải hai ô rỗng chờ dữ liệu.
 */
function MenuNgang({
    filters,
    tongKho,
    chuaGia,
}: {
    filters: HomeData['filters'];
    tongKho: number;
    chuaGia: number;
}) {
    const hang = [
        ...(filters?.screen_types ?? []).map((t) => ({
            ten: t.name ?? t.code ?? '',
            so: t.count ?? 0,
            href: `/explore?screen_type=${encodeURIComponent(t.code ?? '')}`,
            icon: t.code === 'billboard' ? 'signpost' : t.code === 'led' ? 'tv' : 'desktop_windows',
        })),
        ...(filters?.venue_types ?? []).map((t) => ({
            ten: t.name ?? t.code ?? '',
            so: t.count ?? 0,
            href: `/explore?venue_type=${encodeURIComponent(t.code ?? '')}`,
            icon: t.code === 'roadside' ? 'add_road' : 'storefront',
        })),
    ];

    return (
        <div className="card" style={{ overflow: 'hidden' }}>
            {/* hàng chào — đầu khối, không phải một dải riêng */}
            <div
                style={{
                    display: 'flex',
                    flexWrap: 'wrap',
                    alignItems: 'center',
                    gap: '8px 0',
                    padding: '12px 16px',
                    borderBottom: '1px solid var(--stroke)',
                }}
            >
                <p style={{ flex: 1, minWidth: 170, fontSize: 14, fontWeight: 600 }}>
                    Chào mừng đến với OOHX
                </p>

                {[
                    { ten: 'Yêu cầu báo giá', href: '/explore/yeu-cau-bao-gia' },
                    { ten: 'Bản đồ vị trí', href: '/explore?view=map' },
                    { ten: 'Media owner', href: '/owners' },
                ].map((m, i) => (
                    <Link
                        key={m.href}
                        href={m.href}
                        style={{
                            padding: '0 12px',
                            fontSize: 12.5,
                            fontWeight: 500,
                            color: 'var(--black)',
                            borderLeft: i === 0 ? 'none' : '1px solid var(--stroke)',
                        }}
                    >
                        {m.ten}
                    </Link>
                ))}
            </div>

            {/* bốn cột trong CÙNG một khối, nối nhau bằng gạch 1px */}
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 1, background: 'var(--stroke)' }}>
                <nav
                    style={{
                        flex: '1 1 238px',
                        minWidth: 0,
                        background: '#fff',
                        padding: '5px 0',
                    }}
                >
                    {hang.map((h) => (
                        <Link
                            key={h.href}
                            href={h.href}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                gap: 9,
                                padding: '7px 14px',
                                fontSize: 13,
                                color: 'var(--black)',
                            }}
                        >
                            <span className="ms" style={{ fontSize: 19, color: 'var(--body-ink)' }}>
                                {h.icon}
                            </span>
                            <span style={{ flex: 1, minWidth: 0 }}>{h.ten}</span>
                            <span className="num" style={{ fontSize: 12, color: 'var(--body-ink)' }}>
                                {so(h.so)}
                            </span>
                            <span className="ms" style={{ fontSize: 17, color: 'var(--bodydark)' }}>
                                chevron_right
                            </span>
                        </Link>
                    ))}
                </nav>

                {[
                    {
                        ten: 'Yêu cầu báo giá',
                        vi: `${so(chuaGia)} vị trí chưa niêm yết giá — gửi yêu cầu để nhận giá`,
                        href: '/explore/yeu-cau-bao-gia',
                        icon: 'request_quote',
                    },
                    {
                        ten: 'Toàn bộ kho',
                        vi: `${so(tongKho)} vị trí, lọc theo tỉnh thành, loại hình và trạng thái giá`,
                        href: '/explore',
                        icon: 'grid_view',
                    },
                ].map((o) => (
                    <Link
                        key={o.href}
                        href={o.href}
                        style={{
                            flex: '1 1 190px',
                            minWidth: 0,
                            background: '#fff',
                            padding: '13px 15px',
                            color: 'var(--black)',
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 5,
                        }}
                    >
                        <span className="ms" style={{ fontSize: 21, color: 'var(--primary)' }}>
                            {o.icon}
                        </span>
                        <span style={{ fontSize: 13, fontWeight: 600 }}>{o.ten}</span>
                        <span style={{ fontSize: 11.5, color: 'var(--body-ink)', lineHeight: 1.45 }}>
                            {o.vi}
                        </span>
                    </Link>
                ))}

                <div
                    style={{
                        flex: '1.5 1 290px',
                        minWidth: 0,
                        background: 'var(--primary-soft)',
                        padding: '15px 17px',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: 7,
                    }}
                >
                    <p style={{ fontSize: 15, fontWeight: 700 }}>Đưa kho của bạn lên sàn</p>
                    <p style={{ fontSize: 12.5, color: 'var(--body-ink)', lineHeight: 1.5 }}>
                        Miễn phí đăng ký, không phí môi giới. Người mua chuyển khoản trực tiếp cho
                        bạn — sàn không thu hộ.
                    </p>
                    <Link
                        href="/register"
                        className="btn btn-s btn-p"
                        style={{ alignSelf: 'flex-start', marginTop: 2 }}
                    >
                        Đăng ký media owner
                    </Link>
                </div>
            </div>
        </div>
    );
}

/* ── ⑤ Cặp panel ────────────────────────────────────────────────────────── */

/**
 * Panel trái — **kho chưa công bố giá**, không phải "Billboard hot".
 *
 * Board đặt tên khối này là *"Billboard hot"* kèm chip `cần xác nhận`, vì
 * không ai biết luật xếp hạng. Trên site thật thì chip đó không đủ: in chữ
 * "hot" cạnh sáu vị trí lấy theo thứ tự API trả về là một xếp hạng bịa.
 *
 * Nên panel này nói đúng con số lớn nhất và thật nhất của kho: **85/104 vị trí
 * chưa niêm yết giá**. Đó cũng là lý do yêu cầu báo giá là lối mua chính của
 * sàn, không phải một nút phụ.
 */
function PanelChuaGia({ chuaGia, tongKho }: { chuaGia: number; tongKho: number }) {
    if (chuaGia <= 0) {
        return null;
    }

    return (
        <section
            style={{
                flex: '1 1 330px',
                minWidth: 0,
                background: 'var(--danger)',
                borderRadius: 6,
                padding: '14px 15px 15px',
                display: 'flex',
                flexDirection: 'column',
                gap: 10,
            }}
        >
            <h2 style={{ fontSize: 19, fontWeight: 700, color: '#fff' }}>Chưa niêm yết giá</h2>

            <p className="num" style={{ fontSize: 30, fontWeight: 700, color: '#fff', lineHeight: 1 }}>
                {so(chuaGia)}
                <span style={{ fontSize: 15, fontWeight: 600, opacity: 0.85 }}>
                    {' '}/ {so(tongKho)} vị trí
                </span>
            </p>

            <p style={{ fontSize: 12.5, color: '#fff', opacity: 0.92, lineHeight: 1.5 }}>
                Phần lớn kho chưa công bố giá, nên yêu cầu báo giá là lối mua chính — không phải
                một nút phụ. Gửi một lần, các media owner liên quan trả giá cho đúng danh sách
                vị trí bạn chọn.
            </p>

            <Link
                href="/explore/yeu-cau-bao-gia"
                className="btn btn-s"
                style={{ alignSelf: 'flex-start', background: '#fff', color: 'var(--danger)' }}
            >
                Gửi yêu cầu báo giá
            </Link>
        </section>
    );
}

/**
 * Panel phải — media owner trên sàn, kèm **tình trạng xác minh thật**.
 *
 * Board gọi khối này là *"Media owner uy tín"*. Đo trên production 08/11/2026:
 * cả hai media owner đều có `verified: false`. Nên chữ "uy tín" không mang
 * sang, và mỗi thẻ in đúng trạng thái của nó — một dấu tích xanh vẽ vô điều
 * kiện là chỗ mockup trước đã sai một lần.
 */
function PanelOwner({ owners }: { owners: HomeData['featuredOwners'] }) {
    if (owners.length === 0) {
        return null;
    }

    return (
        <section
            style={{
                flex: '1 1 330px',
                minWidth: 0,
                background: 'var(--black)',
                borderRadius: 6,
                padding: '14px 15px 15px',
                display: 'flex',
                flexDirection: 'column',
                gap: 10,
            }}
        >
            <div className="shead" style={{ marginBottom: 0 }}>
                <h2 style={{ fontSize: 19, fontWeight: 700, color: '#fff' }}>
                    Media owner trên sàn
                </h2>
                <Link
                    href="/owners"
                    className="btn btn-s"
                    style={{ background: 'var(--sidebar-on)', color: '#fff' }}
                >
                    Xem tất cả
                </Link>
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                {owners.slice(0, 3).map((o) => (
                    <Link
                        key={o.slug ?? ''}
                        href={`/owners/${o.slug ?? ''}`}
                        style={{
                            display: 'flex',
                            alignItems: 'center',
                            gap: 10,
                            padding: '9px 11px',
                            background: 'var(--boxdark)',
                            borderRadius: 6,
                            color: '#fff',
                        }}
                    >
                        <span style={{ flex: 1, minWidth: 0, fontSize: 13, fontWeight: 600 }}>
                            {o.name}
                        </span>

                        {/*
                          KHÔNG có dấu xác minh ở đây.

                          Board vẽ mỗi thẻ owner kèm một dấu, và bản đầu của
                          tệp này in `o.verified ? 'Đã xác minh' : 'Chưa xác
                          minh'`. `khoi-giao-dien.mjs` bắt đúng chỗ đó, và lý
                          do nó ghi từ 18/10/2026 vẫn đúng: `owners.verified`
                          là một **cờ thủ công**, không phải một quy trình kiểm
                          định, và trang chủ là chỗ nó gây hại nhất — nó đứng
                          cạnh ba dòng bảo đảm mà từng dòng chỉ ra được một
                          điều có thật, nên *"một câu không có nguồn đứng giữa
                          ba câu có nguồn thì nó mượn được uy tín của cả ba"*.

                          Thứ thay nó là một con số có nguồn: số vị trí. Và
                          tình trạng xác minh thật của sàn được nói ở khối "Mức
                          độ hoàn thiện dữ liệu kho" phía dưới, nơi nó là một
                          chỗ còn thiếu chứ không phải một dấu khen.
                        */}
                        <span className="num" style={{ fontSize: 12, color: 'var(--bodydark)' }}>
                            {so(o.screen_count ?? 0)} vị trí
                        </span>
                    </Link>
                ))}
            </div>

            {/*
              Ba câu dưới đây được `khoi-giao-dien.mjs` canh **nguyên văn**,
              kèm lý do: chúng nói những điều CLAUDE.md ghi là sự thật của sàn,
              không phải lời hứa năng lực, và *"canh nguyên văn để một lần làm
              mạnh câu chữ phải đi qua một quyết định"*.

              Bố cục mới gỡ khối `bao-dam` rời của bố cục cũ, nên ba câu chuyển
              **vào đây** — không bị xoá. Đổi chữ ở đây là đỏ một ca, đúng như
              nó phải.
            */}
            <ul
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 5,
                    fontSize: 11.5,
                    color: 'var(--bodydark)',
                    lineHeight: 1.5,
                }}
            >
                {['Sàn không thu hộ', 'Giá do chủ màn hình đặt', 'Booking đã xác nhận không đổi'].map(
                    (c) => (
                        <li key={c} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                            <span className="ms" style={{ fontSize: 15, color: 'var(--secondary)' }}>
                                check
                            </span>
                            {c}
                        </li>
                    ),
                )}
            </ul>
        </section>
    );
}

/* ── ⑥ Thẻ vị trí ───────────────────────────────────────────────────────── */

/**
 * Giải phẫu thẻ lấy từ thẻ sản phẩm alibaba.com — xem `.vt` trong `oohx.css`.
 *
 * Giá in **đúng đơn vị kỳ** mà API trả (`io_rate.unit`), không quy đổi: một
 * con số tháng in cạnh một con số tuần mà không nói kỳ là hai con số không so
 * được với nhau.
 */
function TheViTri({ screen }: { screen: ScreenSummary }) {
    const io = screen.pricing?.io_rate;
    const gia = io?.amount ?? null;
    const ky = io?.unit === 'week' ? 'tuần' : io?.unit === 'month' ? 'tháng' : null;

    const rong = screen.size?.width_m ?? null;
    const cao = screen.size?.height_m ?? null;

    return (
        <Link href={`/explore/${screen.slug}`} className="vt">
            <span className="anh">
                {screen.photo_url ? (
                    /*
                      `<img srcSet>`, **không** `next/image` — giống
                      `ScreenCard`. Ảnh vị trí đã có bản dẫn xuất sinh ở máy
                      chủ (`photo_srcset`, lát 4 ngày 25/10/2026), nên cho nó
                      qua trình tối ưu của Next là tối ưu một lần nữa thứ đã
                      tối ưu.

                      `?? undefined`, không `?? ''`: React in `srcset=""` ra
                      HTML cho chuỗi rỗng.
                    */
                    /* eslint-disable-next-line @next/next/no-img-element */
                    <img
                        src={screen.photo_url}
                        srcSet={screen.photo_srcset ?? undefined}
                        sizes={screen.photo_srcset ? SIZES_VT : undefined}
                        alt=""
                        loading="lazy"
                    />
                ) : null}
            </span>

            <span className="than">
                <span className="badges">
                    <span
                        className="pill"
                        style={{ background: 'var(--primary-soft)', color: 'var(--primary-dark)' }}
                    >
                        {screen.screen_type_label}
                    </span>
                </span>

                <span className="ten">{screen.name}</span>

                {gia !== null ? (
                    <>
                        <span className="gia">{so(gia)} ₫</span>
                        <span className="moq">{ky ? `mỗi ${ky}` : 'theo kỳ do owner khai'}</span>
                    </>
                ) : null}

                {rong && cao ? (
                    <span className="tin num">
                        <span className="ms" style={{ fontSize: 14, color: 'var(--body-ink)' }}>
                            straighten
                        </span>
                        {rong} × {cao} m
                    </span>
                ) : null}

                <span className="ban">
                    <span className="ms" style={{ fontSize: 14 }}>
                        location_on
                    </span>
                    {screen.location?.city ?? '—'}
                </span>
            </span>
        </Link>
    );
}

/** Ô cuối lưới: số vị trí chưa có giá, chiếm một ô như một thẻ. */
function OChuaGia({ chuaGia }: { chuaGia: number }) {
    return (
        <Link
            href="/explore/yeu-cau-bao-gia"
            style={{
                border: '1px dashed var(--stroke)',
                borderRadius: 6,
                padding: '14px 13px',
                display: 'flex',
                flexDirection: 'column',
                justifyContent: 'center',
                gap: 6,
                color: 'var(--black)',
                background: 'var(--whiten)',
            }}
        >
            <span className="num" style={{ fontSize: 24, fontWeight: 700 }}>
                {so(chuaGia)}
            </span>
            <span style={{ fontSize: 12.5, fontWeight: 600 }}>vị trí chưa có giá</span>
            <span style={{ fontSize: 11.5, color: 'var(--body-ink)', lineHeight: 1.45 }}>
                Chúng không hiện trong lưới này. Gửi yêu cầu báo giá để nhận giá.
            </span>
        </Link>
    );
}

/* ── ⑦ Thẻ gói ──────────────────────────────────────────────────────────── */

function TheGoi({ goi }: { goi: ProductSummary }) {
    return (
        <Link href={`/products/${goi.slug}`} className="card" style={{ padding: 13, color: 'var(--black)' }}>
            <p style={{ fontSize: 14, fontWeight: 600, lineHeight: 1.4 }}>{goi.name}</p>

            <p
                className="num"
                style={{ margin: '8px 0 0', fontSize: 17, fontWeight: 700, letterSpacing: '-.03em' }}
            >
                {formatProductPrice(goi)}
            </p>

            {/*
              `quantity.screen_count`, không `screen_count` ở gốc DTO: số vị
              trí của một gói sống trong nhóm `quantity` cùng `total_units`,
              `min` và `max` — xem `ProductSummary` trong đặc tả.
            */}
            {goi.quantity?.screen_count ? (
                <p className="num" style={{ margin: '4px 0 0', fontSize: 11.5, color: 'var(--body-ink)' }}>
                    {so(goi.quantity.screen_count)} vị trí trong gói
                </p>
            ) : null}
        </Link>
    );
}

/* ── ⑧ Mức độ hoàn thiện dữ liệu kho ────────────────────────────────────── */

/**
 * Khối này in **chỗ kho còn thiếu**, không in thành tích.
 *
 * Nó tồn tại vì người mua OOH quyết định bằng dữ liệu, và một sàn in "104 vị
 * trí" mà không nói 85 trong số đó chưa có giá là để họ phát hiện điều đó sau
 * khi đã mất thời gian.
 *
 * Mỗi dòng là một con số đo được từ `/api/v2/filters` và `/api/v2/stats`.
 * Không dòng nào ước lượng.
 */
function HoanThienDuLieu({
    tongKho,
    coGia,
    soOwner,
}: {
    tongKho: number;
    coGia: number;
    soOwner: number;
}) {
    const dong = [
        {
            ten: 'Đã niêm yết giá',
            co: coGia,
            mau: coGia / Math.max(tongKho, 1) >= 0.5 ? 'var(--success)' : 'var(--warning)',
            vi: 'Số còn lại cần gửi yêu cầu báo giá.',
        },
    ];

    return (
        <div style={{ paddingTop: 20, borderTop: '1px solid var(--stroke)' }}>
            <div className="shead">
                <h2>Mức độ hoàn thiện dữ liệu kho</h2>
                <p className="sub">
                    Đo trực tiếp từ kho, không ước lượng. Khối này in chỗ còn thiếu để bạn biết
                    phải hỏi gì.
                </p>
            </div>

            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fit,minmax(230px,1fr))',
                    gap: 12,
                }}
            >
                {dong.map((d) => (
                    <div key={d.ten} className="card" style={{ padding: 13 }}>
                        <p className="lbl">{d.ten}</p>

                        <p
                            className="num"
                            style={{ margin: '6px 0 8px', fontSize: 22, fontWeight: 700 }}
                        >
                            {so(d.co)}
                            <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--body-ink)' }}>
                                {' '}/ {so(tongKho)}
                            </span>
                        </p>

                        <div className="bar">
                            <i
                                style={{
                                    width: `${Math.round((d.co / Math.max(tongKho, 1)) * 100)}%`,
                                    background: d.mau,
                                }}
                            />
                        </div>

                        <p style={{ margin: '7px 0 0', fontSize: 11.5, color: 'var(--body-ink)', lineHeight: 1.45 }}>
                            {d.vi}
                        </p>
                    </div>
                ))}

                <div className="card" style={{ padding: 13 }}>
                    <p className="lbl">Media owner</p>
                    <p className="num" style={{ margin: '6px 0 8px', fontSize: 22, fontWeight: 700 }}>
                        {so(soOwner)}
                    </p>
                    <p style={{ fontSize: 11.5, color: 'var(--body-ink)', lineHeight: 1.45 }}>
                        Quy trình xác minh media owner chưa chạy, nên chưa owner nào mang dấu đã
                        xác minh.
                    </p>
                </div>

                <div className="card" style={{ padding: 13 }}>
                    <p className="lbl">Dữ liệu khán giả</p>
                    <p style={{ margin: '6px 0 8px', fontSize: 15, fontWeight: 700 }}>Chưa có</p>
                    <p style={{ fontSize: 11.5, color: 'var(--body-ink)', lineHeight: 1.45 }}>
                        Lưu lượng, dwell time và chân dung khán giả chưa đo được trên sàn. Chỗ nào
                        có số, sàn nói rõ nguồn.
                    </p>
                </div>
            </div>
        </div>
    );
}

/* ── Phụ ────────────────────────────────────────────────────────────────── */

function tenThanhPho(filters: HomeData['filters'], slug: string): string {
    return (filters?.cities ?? []).find((c) => c.code === slug)?.name ?? slug;
}

/**
 * Chip gợi ý của ô tìm kiếm: ba thành phố có nhiều vị trí nhất.
 *
 * Giữ nguyên hành vi trước lát này — không có nguồn nào khác cho chúng, và bịa
 * ra một truy vấn gợi ý là bịa ra một tính năng.
 */
function chipGoiY(filters: HomeData['filters']): string[] {
    return (filters?.cities ?? [])
        .slice(0, 3)
        .map((c) => c.name ?? '')
        .filter((n) => n !== '');
}
