/**
 * Thanh năm mục ngay dưới hero. **Chữ đổi ngày 20/10/2026** — xem mục cuối.
 *
 * ══ Vì sao có tệp riêng, không nhét vào `app/page.tsx` ══
 *
 * Để một ngày nào đó gỡ nó ra là xoá một lời gọi, không phải đi tìm giữa 500
 * dòng. Nội dung ở đây là **lời hứa sản phẩm**, không phải dữ liệu — nên nó
 * nên sống ở một chỗ đọc được trong một lần nhìn.
 *
 * ══ Năm câu cũ, và cái gì đứng sau mỗi câu ══
 *
 * Bản Blade in "Verified inventory · Traffic real-time · AI-powered search ·
 * Secure payment · Agency support 24/7". Lát 2 **cố ý không dựng** khối này;
 * chủ dự án chốt dựng lại nguyên văn ngày 18/10/2026 sau khi đọc đúng danh
 * sách dưới đây, rồi chốt sửa ngày 20/10.
 *
 *  - **"Traffic real-time"** — `impression_logs` chưa có bản ghi nào.
 *  - **"AI-powered search"** — không có tính năng AI nào trong mã;
 *    `MegaSearch` là lọc theo thuộc tính.
 *  - **"Secure payment"** — sàn **không thu hộ**; người mua chuyển khoản
 *    trực tiếp cho từng media owner (hồ sơ Bộ Công Thương).
 *  - **"Verified inventory"** — cột `owners.verified` có thật nhưng là cờ
 *    thủ công, không phải một quy trình kiểm định.
 *  - **"Agency support 24/7"** — một cam kết vận hành, không phải một thuộc
 *    tính của hệ thống. Không có gì trong mã để đối chiếu.
 *
 * Audit F-15 đã gỡ khỏi trang này "30M+ impressions", bộ đếm chạy bằng
 * `Math.random()`, "AI Match 94%" và "tăng fill rate 40%". Thanh này sống sót
 * đợt đó vì F-15 dọn theo *con số*; đây là *lời khẳng định năng lực*, và nó
 * là chỗ **cuối cùng** còn những câu đó sau khi khối AI bị gỡ ở lát 3.
 *
 * ══ Năm câu mới, và mã nào đứng sau từng câu ══
 *
 * | Câu | Đứng sau nó |
 * |---|---|
 * | Vị trí trên bản đồ | `GET /api/v2/screens/pins` + `BanDoTrangChu`; 104/104 bản ghi công khai có toạ độ (báo cáo soát 20/10) |
 * | Dữ liệu từ chủ màn hình | Ba lối nhập (Excel, biểu mẫu, `/api/v1`) đều ghi dữ liệu của owner; sàn không tự đo lại |
 * | Lọc theo tỉnh, loại hình | `GET /api/v2/filters` trả 17 tỉnh, 2 loại địa điểm, 3 loại màn hình trên kho thật |
 * | Gửi yêu cầu không cần đăng ký | `POST /api/v2/quote-requests` nằm ngoài nhóm Sanctum (chốt 19/10) |
 * | Xuất danh sách CSV | `/explore` xuất giỏ ra CSV (chốt lát 5: "CSV") |
 *
 * Mỗi câu nói một **việc làm được**, không nói một **năng lực**. Đó là khác
 * biệt làm chúng kiểm được: "Vị trí trên bản đồ" sai khi bản đồ không có pin,
 * còn "Traffic real-time" thì không có phép thử nào.
 *
 * Ba thứ **cố ý không nói ở đây** vì trang đã nói chỗ khác, và nhắc lại là
 * lấp chỗ: "Miễn phí đăng ký · Không phí môi giới" (ở hero), "Sàn không thu
 * hộ" và "Giá do chủ màn hình đặt" (khối ⑤ Bảo đảm).
 *
 * Thứ **không** nói vì chưa đo được: chất lượng toạ độ. Báo cáo soát ngày
 * 20/10 tìm ra sáu màn hình Yên Bái dùng chung một toạ độ và một bản ghi
 * thiếu tỉnh — nên câu ở đây là "vị trí trên bản đồ" (có pin), không phải
 * "toạ độ chính xác" (một khẳng định về chất lượng).
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
        // place
        nhan: 'Vị trí trên bản đồ',
        path: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z',
    },
    {
        // storefront
        nhan: 'Dữ liệu từ chủ màn hình',
        path: 'M21.9 8.89l-1.05-4.37c-.22-.9-1-1.52-1.91-1.52H5.05c-.9 0-1.69.63-1.9 1.52L2.1 8.89c-.24 1.02-.02 2.06.62 2.88.08.11.19.19.28.29V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-6.94c.09-.09.2-.18.28-.28.64-.82.86-1.87.62-2.89zM13 5l.67 4.47c.1.68-.42 1.53-1.3 1.53-.72 0-1.31-.59-1.37-1.34V5h2zM5.96 5H8l-.63 4.59c-.11.73-.69 1.32-1.39 1.4-.73.09-1.4-.42-1.5-1.13L5.96 5zm12.08 14H6c-.55 0-1-.45-1-1v-5.02c.09.01.17.02.26.02.8 0 1.52-.33 2.05-.86.54.54 1.26.86 2.08.86.79 0 1.5-.33 2.03-.84.54.51 1.26.84 2.08.84.76 0 1.48-.33 2.01-.84.54.51 1.25.84 2.01.84.09 0 .17-.01.26-.02V18c0 .55-.45 1-1 1z',
    },
    {
        // filter_list
        nhan: 'Lọc theo tỉnh, loại hình',
        path: 'M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z',
    },
    {
        // send
        nhan: 'Gửi yêu cầu không cần đăng ký',
        path: 'M2.01 21L23 12 2.01 3 2 10l15 2-15 2z',
    },
    {
        // file_download
        nhan: 'Xuất danh sách CSV',
        path: 'M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z',
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
