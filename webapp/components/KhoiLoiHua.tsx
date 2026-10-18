/**
 * Thanh năm mục "trust" của trang chủ bản Blade — một khối chữ mà lát 2 **cố
 * ý không dựng**, nay dựng lại **nguyên văn** theo quyết định của chủ dự án
 * (18/10/2026).
 *
 * ══ Vì sao có tệp riêng, không nhét vào `app/page.tsx` ══
 *
 * Để một ngày nào đó gỡ nó ra là xoá một lời gọi, không phải đi tìm giữa 500
 * dòng. Nội dung ở đây là **lời hứa sản phẩm**, không phải dữ liệu — nên nó
 * nên sống ở một chỗ đọc được trong một lần nhìn.
 *
 * ══ Chữ ở đây nói những điều hệ thống chưa làm được ══
 *
 * Ghi lại một lần, ở đúng chỗ người sửa sau sẽ đọc:
 *
 *  - **"Traffic real-time"** — `impression_logs` chưa có bản ghi nào.
 *  - **"AI-powered search"** — không có tính năng AI nào trong mã;
 *    `MegaSearch` là lọc theo thuộc tính.
 *  - **"Secure payment"** — sàn **không thu hộ**; người mua chuyển khoản
 *    trực tiếp cho từng media owner (hồ sơ Bộ Công Thương).
 *  - **"Verified inventory"** — cột `owners.verified` có thật nhưng là cờ
 *    thủ công, không phải một quy trình kiểm định.
 *
 * Audit F-15 đã gỡ khỏi trang này "30M+ impressions", bộ đếm chạy bằng
 * `Math.random()`, "AI Match 94%" và "tăng fill rate 40%". Thanh này sống sót
 * đợt đó vì nó dọn theo *con số*; đây là *lời khẳng định năng lực*. Chủ dự án
 * đã đọc danh sách trên và chốt dựng lại nguyên văn.
 *
 * ══ Mục "Marketplace OOH đầu tiên có AI" đã gỡ (lát 3, 18/10/2026) ══
 *
 * Tệp này từng có khối thứ hai, `MucAI` — hai cột, bốn thẻ "AI Campaign Match
 * / GPT-powered", "Real-time Availability / Live sync <5min", "AI Audience
 * Estimation / Data-driven ±8%", "Verified Data Network / 100% verified", và
 * một ô ảnh ghi "3.2× nhanh hơn cách đặt truyền thống". Không thẻ nào trong
 * số đó có một dòng mã đứng sau, và "3.2×" không có nguồn.
 *
 * Nó cũng mang tấm ảnh hotlink `images.unsplash.com/photo-1551836022-…` —
 * thứ chủ dự án chốt **ba lần** trong một ngày (tự host ở PR #95, rồi quay về
 * hotlink nguyên văn ở PR #96). Gỡ `MucAI` là gỡ luôn phụ thuộc CDN bên thứ
 * ba lúc chạy, và cái referrer `oohx.net` gửi sang Unsplash mỗi lượt mở trang.
 *
 * Quyết định cùng đợt với bố cục mới: chủ dự án duyệt bản mock không có khối
 * AI, rồi xác nhận lại bằng chữ ("bỏ khối AI"). Nó **đảo** quyết định dựng
 * nguyên văn của chính hôm đó — ghi lại ở đây vì ba PR liên tiếp (#94, #95,
 * #96) đã đổ vào một khối mà PR này xoá.
 *
 * Muốn quay lại thì cả khối lẫn tệp ảnh tự host còn trong lịch sử git.
 */

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
