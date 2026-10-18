/**
 * Hai khối của trang chủ bản Blade mà lát 2 **cố ý không dựng**, nay dựng lại
 * **nguyên văn** theo quyết định của chủ dự án (18/10/2026).
 *
 * ══ Vì sao có tệp riêng, không nhét vào `app/page.tsx` ══
 *
 * Để một ngày nào đó gỡ chúng ra là xoá hai lời gọi, không phải đi tìm giữa
 * 500 dòng. Nội dung ở đây là **lời hứa sản phẩm**, không phải dữ liệu — nên
 * nó nên sống ở một chỗ đọc được trong một lần nhìn.
 *
 * ══ Chữ ở đây nói những điều hệ thống chưa làm được ══
 *
 * Ghi lại một lần, ở đúng chỗ người sửa sau sẽ đọc:
 *
 *  - **"Traffic real-time"** — `impression_logs` chưa có bản ghi nào.
 *  - **"AI-powered search"**, **"AI Campaign Match / GPT-powered"**,
 *    **"AI Audience Estimation / Data-driven ±8%"** — không có tính năng AI
 *    nào trong mã; `MegaSearch` là lọc theo thuộc tính.
 *  - **"Secure payment"** — sàn **không thu hộ**; người mua chuyển khoản
 *    trực tiếp cho từng media owner (hồ sơ Bộ Công Thương).
 *  - **"Real-time Availability / Live sync <5min"** — không có đồng bộ nào
 *    với owner; suất trống tính từ `BookingLine` + `InventoryHold` trong CSDL
 *    của chính sàn.
 *  - **"3.2× nhanh hơn cách đặt truyền thống"** — không có nguồn.
 *  - **"100% verified"** — cột `owners.verified` có thật nhưng là cờ thủ
 *    công, không phải một quy trình kiểm định.
 *
 * Audit F-15 đã gỡ khỏi trang này "30M+ impressions", bộ đếm chạy bằng
 * `Math.random()`, "AI Match 94%" và "tăng fill rate 40%". Hai khối dưới sống
 * sót đợt đó vì nó dọn theo *con số*; đây là *lời khẳng định năng lực*. Chủ dự
 * án đã đọc danh sách trên và chốt dựng lại nguyên văn.
 *
 * ══ Ảnh: đúng tấm của bản Blade, nhưng phục vụ từ domain của mình ══
 *
 * Chủ dự án chốt dùng đúng ảnh cũ (18/10/2026). Tệp đã tải về
 * `public/images/frontpage/ai-platform.jpg` (JPEG 800×1200, 155 KB) thay vì
 * hotlink `images.unsplash.com`.
 *
 * Cùng một tấm ảnh, nhưng bỏ được hai thứ đi kèm hotlink: trang công khai
 * không còn phụ thuộc một CDN bên thứ ba lúc chạy (Unsplash chậm, đổi URL hay
 * chặn hotlink thì ô ảnh trống), và không còn gửi referrer của người đọc sang
 * máy chủ khác mỗi lần mở trang chủ.
 *
 * Đường `/images/*` do Laravel phục vụ trên production — OpenLiteSpeed định
 * tuyến các đường tĩnh về đó, và `webapp/` không có thư mục `public` riêng
 * (xem ghi chú ở `lib/logo.ts` và `lib/anh-hero.ts`). Nên ở môi trường test
 * chỉ chạy `next start`, ảnh này 404 — chốt khối giao diện kiểm chuỗi HTML
 * nên điều đó không ảnh hưởng, nhưng đừng ngạc nhiên khi xem thử cục bộ.
 */

/** Ảnh nền ô "3.2×" — đúng tấm của bản Blade, phục vụ từ domain của mình. */
const ANH_AI = '/images/frontpage/ai-platform.jpg';

const TRUST: { nhan: string; path: string }[] = [
    {
        nhan: 'Verified inventory',
        path: 'M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.2L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12zm-12.91 4.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48-7.33 7.35z',
    },
    {
        nhan: 'Traffic real-time',
        path: 'M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z',
    },
    {
        nhan: 'AI-powered search',
        path: 'M19 9l1.25-2.75L23 5l-2.75-1.25L19 1l-1.25 2.75L15 5l2.75 1.25zm-7.5.5L9 4 6.5 9.5 1 12l5.5 2.5L9 20l2.5-5.5L17 12zM19 15l-1.25 2.75L15 19l2.75 1.25L19 23l1.25-2.75L23 19l-2.75-1.25z',
    },
    {
        nhan: 'Secure payment',
        path: 'M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z',
    },
    {
        nhan: 'Agency support 24/7',
        path: 'M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z',
    },
];

/** Thanh năm mục ngay dưới hero. */
export function ThanhTrust() {
    return (
        <div className="trust">
            <div className="trust-in">
                {TRUST.map((muc, i) => (
                    <span key={muc.nhan} style={{ display: 'contents' }}>
                        {i > 0 ? <div className="trust-sep" /> : null}
                        <div className="trust-item">
                            <svg
                                viewBox="0 0 24 24"
                                fill="var(--bl)"
                                aria-hidden="true"
                                style={{ width: 18, height: 18, flexShrink: 0 }}
                            >
                                <path d={muc.path} />
                            </svg>
                            {muc.nhan}
                        </div>
                    </span>
                ))}
            </div>
        </div>
    );
}

const AI_CARDS: { tieuDe: string; moTa: string; the: string; path: string }[] = [
    {
        tieuDe: 'AI Campaign Match',
        moTa: 'Mô tả campaign bằng ngôn ngữ tự nhiên — AI đề xuất inventory phù hợp nhất trong giây.',
        the: 'GPT-powered',
        path: 'M19 9l1.25-2.75L23 5l-2.75-1.25L19 1l-1.25 2.75L15 5l2.75 1.25zm-7.5.5L9 4 6.5 9.5 1 12l5.5 2.5L9 20l2.5-5.5L17 12zM19 15l-1.25 2.75L15 19l2.75 1.25L19 23l1.25-2.75L23 19l-2.75-1.25z',
    },
    {
        tieuDe: 'Real-time Availability',
        moTa: 'Kiểm tra slot trống trước khi brief AdOps. Sync với owner trong 5 phút.',
        the: 'Live sync <5min',
        path: 'M20 3h-1V1h-2v2H7V1H5v2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 18H4V8h16v13z',
    },
    {
        tieuDe: 'AI Audience Estimation',
        moTa: 'Ước tính reach từ traffic data thực tế, thời điểm trong ngày và demographic khu vực.',
        the: 'Data-driven ±8%',
        path: 'M20.38 8.57l-1.23 1.85a8 8 0 0 1-.22 7.58H5.07A8 8 0 0 1 15.58 6.85l1.85-1.23A10 10 0 0 0 3.35 19a2 2 0 0 0 1.72 1h13.85a2 2 0 0 0 1.74-1 10 10 0 0 0-.27-10.44zm-9.79 6.84a2 2 0 0 0 2.83 0l5.66-8.49-8.49 5.66a2 2 0 0 0 0 2.83z',
    },
    {
        tieuDe: 'Verified Data Network',
        moTa: 'Ảnh thực địa, location verified, traffic data nguồn đáng tin. Không có inventory ảo.',
        the: '100% verified',
        path: 'M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.2L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12z',
    },
];

/** Mục "Marketplace OOH đầu tiên có AI" — hai cột, bốn thẻ và ô 3.2×. */
export function MucAI() {
    return (
        <section className="sec">
            <div className="w">
                <div className="value-grid">
                    <div>
                        <span className="eyebrow">AI-POWERED PLATFORM</span>
                        <h2
                            className="section-hed"
                            style={{ marginTop: 8, marginBottom: 12 }}
                        >
                            Marketplace OOH đầu tiên có AI
                        </h2>
                        <p
                            className="section-sub"
                            style={{ marginBottom: 32, fontSize: 16 }}
                        >
                            Kết nối agency, brand và media owner — nhanh hơn, minh bạch hơn, đo
                            được kết quả.
                        </p>

                        <div className="ai-cards">
                            {AI_CARDS.map((the) => (
                                <div className="ai-card" key={the.tieuDe}>
                                    <div className="ai-icon">
                                        <svg
                                            viewBox="0 0 24 24"
                                            fill="var(--bl)"
                                            aria-hidden="true"
                                            style={{ width: 20, height: 20, flexShrink: 0 }}
                                        >
                                            <path d={the.path} />
                                        </svg>
                                    </div>
                                    <div>
                                        <div className="ai-card-title">{the.tieuDe}</div>
                                        <div className="ai-card-desc">{the.moTa}</div>
                                        <div className="ai-card-tag">
                                            <span className="badge badge-bl">{the.the}</span>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div>
                        <div className="value-visual">
                            {/* eslint-disable-next-line @next/next/no-img-element */}
                            <img src={ANH_AI} alt="" loading="lazy" />
                            <div className="value-visual-ov">
                                <div className="vv-number">3.2×</div>
                                <div className="vv-label">Nhanh hơn cách đặt truyền thống</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}
