import type { Metadata } from 'next';
import Link from 'next/link';
import {
    getHomeData,
    DEFAULT_CITY,
    type HomeData,
    type HeroStats,
    type ProductSummary,
} from '@/lib/home';
import { buildMetadata, jsonLd, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { OwnerCard } from '@/components/OwnerCard';
import { MegaSearch } from '@/components/MegaSearch';
import { BanDoTrangChu } from '@/components/BanDoTrangChu';
import { DuyetKho } from '@/components/DuyetKho';
// `formatProductPrice` dùng chung với `ProductCard`: trang chủ và trang gói
// không được in hai con số khác nhau cho cùng một gói.
import { formatProductPrice } from '@/components/ProductCard';
import { ThanhTrust } from '@/components/KhoiLoiHua';

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
 * ══ Sáu khối, theo thứ tự người mua hỏi (lát 3, 18/10/2026) ══
 *
 *   ① hero — kho có bao nhiêu, nằm ở đâu, tìm ở đâu
 *   ② duyệt kho — xem kho, theo loại hình hoặc theo tỉnh thành
 *   ③ hai cách mua — lẻ hay gói
 *   ④ bốn bước — đặt thế nào
 *   ⑤ media owner + ba bảo đảm — đặt với ai
 *   ⑥ mời lên sàn — khối duy nhất dành cho NGƯỜI BÁN, và nó đứng cuối
 *
 * Bố cục cũ có mười khối xếp theo thứ tự chúng được viết ra: ba lối vào kho
 * rời nhau, hai cách mua cách nhau bốn khối, mục mời media owner chen giữa
 * luồng của người mua. `thuTu` trong `webapp/test/khoi-giao-dien.mjs` canh
 * chính thứ tự trên — đảo chỗ hai khối không làm hỏng một khẳng định "có mặt"
 * nào.
 *
 * Ba khối bị gỡ ở lát này, mỗi cái một lý do khác nhau:
 *
 *  - **"Vị trí nổi bật"** — `getFeaturedScreens()` đòi màn hình có **ảnh
 *    riêng** (`screen_specs.photo_url` hoặc `photos`) VÀ **có giá**. Đo trên
 *    production 05/10/2026: cả 50 màn hình trong mẫu đều lùi về cover của
 *    owner, tức chỉ 1 trên 104 thoả cả hai. Một lưới một thẻ không nói được
 *    gì về kho. Lời gọi `/screens/featured` nuôi nó cũng đi — xem `lib/home.ts`.
 *  - **"Gói chiến dịch"** — cùng hình dạng: một lưới cho một gói. Tên gói và
 *    giá của nó chuyển vào ô "mua theo gói" của khối ③.
 *  - **"Marketplace OOH đầu tiên có AI"** — chủ dự án chốt bỏ, đảo quyết định
 *    dựng nguyên văn của chính hôm đó. Xem `KhoiLoiHua.tsx`.
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
    const { stats, featuredOwners, regions, filters, pins, products, browse } =
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
                {/*
                  Hai cột: chữ + ô tìm kiếm bên trái, bản đồ THẬT bên phải.

                  Trước lát này (18/10/2026) hero là một cột giữa trang, và
                  dưới nó là `hero-browser` — một ảnh SVG vẽ tay mô phỏng bảng
                  điều khiển. Bản đồ thật nằm cách đó bốn khối, ở mục "Live Map
                  View". Người mở trang lần đầu thấy một ảnh giả trước khi thấy
                  một màn hình thật của sàn.

                  Lát này đổi chỗ hai thứ đó. `hero-browser` bị gỡ, và
                  `lib/anh-hero.ts` đi theo nó.

                  Không tốn thêm byte nào: `BanDoTrangChu` đã ở trên trang chủ
                  từ trước và `useEffect` của nó chạy ngay khi gắn vào, không
                  chờ cuộn tới — nên mọi lượt vào trang chủ hôm nay đã tải
                  Leaflet và tile rồi. Đây là một lần chuyển chỗ.
                */}
                <section className="hero hero--split">
                    <div className="w hero-luoi">
                        <div className="hero-chu">
                            {/*
                              Thẻ đếm inventory đang mở đặt lịch. Con số là
                              `stats.total_screens` — cùng con số ba ô dưới
                              dùng, nên không có chỗ nào để hai số lệch nhau.
                            */}
                            {stats ? (
                                <div className="hero-badge" style={{ display: 'inline-flex' }}>
                                    <div className="hero-badge-dot" />
                                    {new Intl.NumberFormat('vi-VN').format(stats.total_screens)}{' '}
                                    inventory đang mở đặt lịch
                                </div>
                            ) : null}

                            <div className="hero-text">
                                <h1 className="hero-hed">
                                    Marketplace OOH/DOOH{' '}
                                    <span className="acc">thông minh nhất Việt Nam.</span>
                                </h1>

                                <p className="hero-desc">
                                    Tìm, so sánh và đặt booking billboard · LED · LCD trong vài
                                    phút.
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

                              Chip gợi ý dựng đúng cách bản Blade dựng: ba nhóm
                              loại hình đầu ghép với tên tỉnh thành đông
                              inventory nhất, rồi nhóm thứ tư đứng một mình.
                              Không có nguồn nào khác cho chúng, và bịa ra một
                              truy vấn gợi ý là bịa ra một tính năng.
                            */}
                            <MegaSearch
                                variant="hero"
                                target="/explore"
                                regions={regions}
                                categories={filters?.categories_networks ?? []}
                                chips={chipGoiY(filters)}
                            />

                            {/*
                              Ba ô số liệu dùng đúng bộ class `hstat`.

                              Một chỗ cố ý khác bản Blade: nó in
                              `total_screens / 1000` kèm "K+", nên 104 màn hình
                              hiện ra "0.1K+". Ở đây in số thật. Làm tròn một
                              con số nhỏ thành đơn vị nghìn là nói sai về quy
                              mô, theo cả hai chiều.
                            */}
                            {stats ? (
                                <div className="hero-stats">
                                    <Hstat value={stats.total_screens} label="Inventory" />
                                    <Hstat value={stats.total_cities} label="Tỉnh thành" />
                                    <Hstat value={stats.total_owners} label="Media owners" />
                                </div>
                            ) : null}

                            {/*
                              "Miễn phí đăng ký · Không phí môi giới" là chính
                              sách thật, nên nó ở lại. Câu "30M+ impressions"
                              đứng cạnh nó trong bản Blade thì không có nguồn
                              nào và đã bị gỡ từ đợt dọn F-15 — không mang lại.
                            */}
                            <div className="hero-hint">Miễn phí đăng ký · Không phí môi giới</div>
                        </div>

                        {/*
                          Hai nút "Khám phá Inventory" và "Xem bản đồ" của hero
                          cũ KHÔNG dựng lại ở đây. Cả hai đích đều còn đường
                          tới — `/explore` là chỗ nút "Tìm kiếm" của ô tìm kiếm
                          dẫn tới, `/map` là chỗ thanh dưới bản đồ dẫn tới —
                          nên giữ chúng là đặt bốn nút cạnh nhau cho hai đích.
                        */}
                        <BanDoTrangChu
                            bienThe="hero"
                            pinBanDau={pins}
                            thanhPhoBanDau={DEFAULT_CITY}
                            tenThanhPhoBanDau={tenThanhPho(filters, DEFAULT_CITY)}
                            thanhPho={filters?.cities ?? []}
                        />
                    </div>
                </section>

                {/*
                  Thanh năm mục, nguyên văn bản Blade. Chủ dự án chốt dựng lại
                  ngày 18/10/2026 sau khi đọc danh sách những câu không kiểm
                  được — xem docblock của `KhoiLoiHua.tsx`.
                */}
                <ThanhTrust />

                {/* ── ② Duyệt kho ────────────────────────────────────────── */}
                {/*
                  Một kho, hai cách nhìn.

                  Trước lát này đây là HAI mục rời: "Duyệt theo Địa điểm" và
                  "17 Tỉnh thành", cách nhau một dải nền xám, mỗi cái một tiêu
                  đề và một nút "Tất cả" riêng. Cả hai dẫn vào cùng `/explore`,
                  chỉ khác chiều lọc — xem docblock của `DuyetKho`.
                */}
                {browse && (browse.venue_types.length > 0 || browse.cities.length > 0) ? (
                    <section className="sec">
                        <div className="w">
                            <div className="sec-head">
                                <div className="sec-head-row">
                                    <div>
                                        <span className="eyebrow">DUYỆT KHO</span>
                                        <h2 className="section-hed">
                                            {stats
                                                ? `${new Intl.NumberFormat('vi-VN').format(stats.total_screens)} vị trí, chọn cách nhìn`
                                                : 'Chọn cách nhìn kho'}
                                        </h2>
                                        <p className="section-sub" style={{ marginTop: 10 }}>
                                            Cùng một kho. Lọc theo loại hình hoặc theo tỉnh
                                            thành, tuỳ việc bạn đang làm.
                                        </p>
                                    </div>
                                    <Link
                                        href="/explore"
                                        className="btn btn-s btn-sm"
                                        style={{ marginTop: 6, flexShrink: 0 }}
                                    >
                                        Xem tất cả
                                        <MuiTen />
                                    </Link>
                                </div>
                            </div>

                            <DuyetKho browse={browse} />
                        </div>
                    </section>
                ) : null}

                {/* ── ③ Hai cách mua ─────────────────────────────────────── */}
                {/*
                  Trước lát này hai cách đặt cách nhau BỐN khối: lưới "Vị trí
                  nổi bật" ở giữa trang, mục "Gói chiến dịch" gần cuối. Người
                  mua không so được hai cách khi phải cuộn qua bốn mục khác để
                  nhìn thấy cả hai.

                  Hai lưới thẻ cũ không dựng lại ở đây:

                   · "Vị trí nổi bật" cho ra ĐÚNG MỘT thẻ trên 104 màn hình —
                     `getFeaturedScreens()` đòi ảnh riêng + có giá, và đo trên
                     production 05/10/2026 thì cả 50 màn hình trong mẫu đều
                     lùi về cover của owner. Một lưới một thẻ không nói được
                     gì về kho.
                   · Lưới `ProductCard` cho ra một thẻ, vì kho có một gói.
                     Tên gói và giá của nó vào thẳng ô bên phải dưới đây.
                */}
                <section className="sec sec-gray">
                    <div className="w">
                        <div style={{ textAlign: 'center', maxWidth: 560, margin: '0 auto 32px' }}>
                            <span className="eyebrow">CHỌN CÁCH PHÙ HỢP</span>
                            <h2 className="section-hed">Mua lẻ hay mua theo gói</h2>
                            <p className="section-sub" style={{ margin: '12px auto 0' }}>
                                Hai cách đặt cùng một kho. Chọn theo quy mô chiến dịch.
                            </p>
                        </div>

                        <div className="mua">
                            <OMua
                                icon="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 110-5 2.5 2.5 0 010 5z"
                                ten="Mua lẻ từng vị trí"
                                moTa="Chọn đúng màn hình bạn cần"
                                y={yMuaLe(stats)}
                                nut={{ href: '/explore', chu: chuNutDuyet(stats), kieu: 'btn-s' }}
                            />

                            <OMua
                                noiBat
                                icon="M20 6h-4V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2H4a2 2 0 00-2 2v11a2 2 0 002 2h16a2 2 0 002-2V8a2 2 0 00-2-2zm-10-2h4v2h-4V4z"
                                ten="Mua theo gói"
                                moTa="Nhiều vị trí đã ghép sẵn"
                                y={yMuaGoi(products)}
                                nut={{ href: '/products', chu: 'Xem gói chiến dịch', kieu: 'btn-p' }}
                            />
                        </div>
                    </div>
                </section>

                {/* ── ④ Quy trình ────────────────────────────────────────── */}
                {/*
                  Bốn thẻ, đúng bố cục VÀ đúng câu chữ bản Blade.

                  Đợt trước lát 2 viết lại bốn câu mô tả; chủ dự án chốt trả
                  lại nguyên văn ngày 18/10/2026, và chốt lại lần nữa khi
                  duyệt bố cục mới. Bản mock của lát này có bốn câu viết lại —
                  KHÔNG dùng. Danh sách bốn chỗ không kiểm được nằm ở docblock
                  của `BON_BUOC` dưới cuối tệp.

                  Mục này tụt xuống sau "cách mua": nó trả lời "đặt thế nào",
                  một câu hỏi chỉ có nghĩa sau khi người đọc đã biết đặt cái
                  gì.
                */}
                <section className="sec">
                    <div className="w">
                        <div style={{ textAlign: 'center', maxWidth: 560, margin: '0 auto 40px' }}>
                            <span className="eyebrow">WORKFLOW</span>
                            <h2 className="section-hed">4 bước đặt booking OOH</h2>
                            <p className="section-sub" style={{ margin: '12px auto 0' }}>
                                Không email qua lại. Tìm, kiểm tra, đặt và theo dõi trực tiếp.
                            </p>
                        </div>

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

                {/* ── ⑤ Bạn đặt trực tiếp với chủ màn hình ───────────────── */}
                {/*
                  Dải media owner + ba bảo đảm, gộp làm một.

                  Trước lát này lưới owner đứng một mình dưới tiêu đề "Media
                  owner trên sàn" — một danh sách không nói vì sao người mua
                  cần biết. Ba dòng dưới nó trả lời đúng câu đó, và cả ba đều
                  là điều CLAUDE.md ghi là sự thật của sàn, không phải lời hứa
                  năng lực:

                   · sàn không thu hộ, người mua chuyển khoản trực tiếp cho
                     từng owner (hồ sơ Bộ Công Thương);
                   · giá do owner đặt, sàn không chèn phí vào giữa;
                   · booking đã xác nhận giữ nguyên giá và điều khoản
                     (CLAUDE.md mục 6).

                  Ba dòng này KHÔNG dựng lại "Vị trí đã xác minh" của bản mock.
                  Toạ độ và ảnh là do owner khai, không qua kiểm định nào —
                  đúng lý do audit F-15 gỡ "VERIFIED PARTNERS" khỏi chính mục
                  này.
                */}
                {featuredOwners.length > 0 ? (
                    <section className="sec sec-gray">
                        <div className="w">
                            <div className="sec-head">
                                <div className="sec-head-row">
                                    <div>
                                        {/*
                                          "ĐỐI TÁC", không "VERIFIED PARTNERS":
                                          truy vấn lọc `status = 'active'`,
                                          không đọc cột `verified` (audit
                                          F-15, đã sửa ở bản Blade).
                                        */}
                                        <span className="eyebrow">ĐỐI TÁC</span>
                                        <h2 className="section-hed">
                                            Bạn đặt trực tiếp với chủ màn hình
                                        </h2>
                                        <p className="section-sub" style={{ marginTop: 10 }}>
                                            Sàn không thu hộ và không môi giới. Tiền chuyển
                                            thẳng cho media owner.
                                        </p>
                                    </div>
                                    <Link
                                        href="/owners"
                                        className="btn btn-s btn-sm"
                                        style={{ marginTop: 6, flexShrink: 0 }}
                                    >
                                        Tất cả media owner
                                        <MuiTen />
                                    </Link>
                                </div>
                            </div>

                            {/*
                              `oc-grid` KHÔNG kèm `oc-grid--full`: biến thể
                              `full` là lưới 4 cột cho thẻ owner đầy đủ ở
                              `/owners`, còn trang chủ dùng thẻ `compact` trong
                              lưới gốc — đúng cặp mà bản Blade dùng.
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

                            <div className="bao-dam">
                                {BA_BAO_DAM.map((bd) => (
                                    <div className="bd-o" key={bd.tieuDe}>
                                        <svg viewBox="0 0 24 24" aria-hidden="true">
                                            <path d={bd.icon} />
                                        </svg>
                                        <div>
                                            <b>{bd.tieuDe}</b>
                                            <span>{bd.moTa}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </section>
                ) : null}

                {/* ── ⑥ Mời media owner lên sàn ──────────────────────────── */}
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
                                      owner. Nút "Bán cùng OOHX" của bản mock
                                      lát này cũng không dựng, cùng lý do:
                                      `/ban-cung-oohx` không tồn tại, và chốt
                                      "không liên kết nào trỏ vào trang chưa
                                      có" (PR #115) sẽ chặn nó.
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
 * Một trong hai ô "cách mua".
 *
 * Danh sách `y` do chỗ gọi dựng từ dữ liệu thật và có thể RỖNG — khi sàn chưa
 * có gói nào thì ô bên phải chỉ còn tiêu đề và nút. Thà ít dòng hơn là một
 * dòng bịa: cả trang chủ này không mục nào in một con số không có nguồn.
 */
function OMua({
    icon,
    ten,
    moTa,
    y,
    nut,
    noiBat = false,
}: {
    icon: string;
    ten: string;
    moTa: string;
    y: string[];
    nut: { href: string; chu: string; kieu: 'btn-s' | 'btn-p' };
    noiBat?: boolean;
}) {
    return (
        <div className={noiBat ? 'lc lc--nb' : 'lc'}>
            <div className="lc-dau">
                <span className="lc-ic">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d={icon} />
                    </svg>
                </span>
                <div>
                    <div className="lc-ten">{ten}</div>
                    <div className="lc-mo">{moTa}</div>
                </div>
            </div>

            {y.length > 0 ? (
                <ul className="lc-ds">
                    {y.map((dong) => (
                        <li key={dong}>
                            <svg viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M9 16.2L4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z" />
                            </svg>
                            {dong}
                        </li>
                    ))}
                </ul>
            ) : null}

            <div className="lc-chan">
                <Link href={nut.href} className={`btn ${nut.kieu}`}>
                    {nut.chu}
                </Link>
            </div>
        </div>
    );
}

/**
 * Ba dòng của ô "mua lẻ".
 *
 * Dòng đầu là số thật từ `/stats`; hai dòng sau mô tả hai chức năng ĐANG
 * CHẠY — `LichTrong` trên trang chi tiết màn hình, và luồng yêu cầu báo giá
 * gửi tới owner. Không dòng nào hứa một thứ chưa dựng.
 */
function yMuaLe(stats: HeroStats | null): string[] {
    const so = new Intl.NumberFormat('vi-VN');
    const ra: string[] = [];

    if (stats) {
        ra.push(
            `${so.format(stats.total_screens)} vị trí trên ${so.format(stats.total_cities)} tỉnh thành`,
        );
    }

    ra.push('Xem lịch trống trước khi gửi yêu cầu');
    ra.push('Media owner báo giá trực tiếp cho bạn');

    return ra;
}

/**
 * Ba dòng của ô "mua theo gói", dựng từ gói THẬT.
 *
 * Bản Blade vẽ ba thẻ gói **viết cứng trong template** ("City Launch",
 * "Retail Activation", "Event Blitz"), và chú thích ở đó đã nêu nghi vấn:
 * không xác nhận được ba tên ấy ứng với bản ghi nào. Ở đây tên gói, số vị trí
 * và giá đều đọc từ `/api/v2/products` — cùng dữ liệu `/products` phục vụ.
 *
 * Giá đi qua `formatProductPrice()` dùng chung với `ProductCard`, nên trang
 * chủ và trang gói không thể in hai con số khác nhau cho cùng một gói.
 */
function yMuaGoi(products: ProductSummary[]): string[] {
    const so = new Intl.NumberFormat('vi-VN');
    const ra: string[] = [];
    const dau = products[0];

    if (products.length > 0 && dau) {
        const viTri = dau.quantity?.total_units ?? dau.quantity?.screen_count ?? null;

        ra.push(
            products.length === 1
                ? `1 gói đang mở — ${dau.name}${viTri ? `, ${so.format(viTri)} vị trí` : ''}`
                : `${so.format(products.length)} gói đang mở trên sàn`,
        );
    }

    ra.push('Một yêu cầu cho cả chùm vị trí');

    const gia = dau ? formatProductPrice(dau) : null;

    if (gia) ra.push(`Giá trọn gói từ ${gia}`);

    return ra;
}

/** Chữ trên nút của ô "mua lẻ" — có số khi lấy được số, không thì không. */
function chuNutDuyet(stats: HeroStats | null): string {
    return stats
        ? `Duyệt ${new Intl.NumberFormat('vi-VN').format(stats.total_screens)} vị trí`
        : 'Duyệt kho màn hình';
}

/**
 * Ba bảo đảm dưới dải media owner.
 *
 * Mỗi dòng là một điều CLAUDE.md ghi là sự thật của sàn, không phải một lời
 * hứa năng lực. Đây là chỗ dễ trượt nhất của cả trang: ba ô ngắn cạnh nhau
 * mời người viết điền những câu nghe hay — "đã xác minh", "bảo đảm", "cam
 * kết" — mà không có gì đứng sau. Thêm dòng thứ tư thì phải chỉ ra được nó
 * đứng trên cái gì.
 */
const BA_BAO_DAM = [
    {
        tieuDe: 'Không qua trung gian',
        moTa: 'Sàn không thu hộ. Bạn chuyển khoản thẳng cho từng media owner.',
        icon: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z',
    },
    {
        tieuDe: 'Giá do chủ màn hình đặt',
        moTa: 'Sàn không chèn phí vào giữa và không thu phí môi giới.',
        icon: 'M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z',
    },
    {
        tieuDe: 'Booking đã xác nhận không đổi',
        moTa: 'Giá và điều khoản chốt lúc xác nhận được giữ nguyên trên sàn.',
        icon: 'M20 6h-4V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2H4a2 2 0 00-2 2v11a2 2 0 002 2h16a2 2 0 002-2V8a2 2 0 00-2-2zM10 4h4v2h-4V4zm9 15H5V8h14v11z',
    },
];

/**
 * Bốn bước của mục WORKFLOW — **nguyên văn bản Blade**.
 *
 * Lát 2 viết lại bốn câu mô tả vì chúng hứa những điều không kiểm được; chủ
 * dự án chốt trả lại nguyên văn (18/10/2026), cùng đợt với thanh `trust`, mục
 * AI và bốn thẻ "Điểm nổi bật".
 *
 * Bốn chỗ không kiểm được, ghi lại ở đúng chỗ người sửa sau đọc:
 *
 *  - **"AI search"** (bước 01) — `MegaSearch` lọc theo thuộc tính; không có AI.
 *  - **"traffic data"** (bước 02) — số liệu lưu lượng là cột do media owner
 *    khai, không phải đo độc lập; `impression_logs` chưa có bản ghi nào.
 *  - **"So sánh tối đa 5 vị trí"** (bước 02) — **không có chức năng so sánh
 *    nào** trên sàn, không phải "có nhưng giới hạn 5".
 *  - **"proof-of-play report tự động"** (bước 04) — đường ghi bằng chứng phát
 *    sóng đã dựng từ giai đoạn 2, nhưng chưa thiết bị nào gửi dữ liệu.
 *
 * Bốn **bước** thì có thật và đã dựng; bốn **câu** thì chưa.
 *
 * Màu lấy đúng bản Blade (xanh · lục · cam · tím) — nó là thứ tự màu người đọc
 * thấy.
 */
/** Mũi tên ">" của bản Blade, dùng ở nút "Tất cả" và dòng đếm vị trí. */
function MuiTen() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            style={{ width: 14, height: 14, flexShrink: 0 }}
            aria-hidden="true"
        >
            <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" />
        </svg>
    );
}

const BON_BUOC = [
    {
        so: '01',
        mau: 'var(--bl)',
        tieuDe: 'Tìm kiếm & lọc',
        moTa: 'AI search theo khu vực, loại biển, budget và availability. Map view tức thì.',
    },
    {
        so: '02',
        mau: '#34C759',
        tieuDe: 'So sánh',
        moTa: 'Ảnh thực địa, traffic data, availability calendar. So sánh tối đa 5 vị trí.',
    },
    {
        so: '03',
        mau: '#FF9F0A',
        tieuDe: 'Gửi booking',
        moTa: 'Điền thông tin campaign, upload creative. Media owner nhận ngay, xử lý nhanh chóng.',
    },
    {
        so: '04',
        mau: '#AF52DE',
        tieuDe: 'Theo dõi & báo cáo',
        moTa: 'Dashboard theo dõi booking status + proof-of-play report tự động.',
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
