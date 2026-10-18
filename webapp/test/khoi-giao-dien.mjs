// ════════════════════════════════════════════════════════════════════════════
// Khối giao diện phải CÓ MẶT trong HTML đã render.
//
// Vì sao tệp này tồn tại — ba lỗi thật, cả ba đã ở trên production:
//
//   • Thẻ màn hình mở bằng `class="sc"`. `.sc` là một ô trong bảng thông số
//     (`frontpage.css:1330`); thẻ màn hình là `.sc-card` (dòng 1939). Cả hai
//     tên ĐỀU có định nghĩa, nên `tests/js/class-css.test.mjs` không thấy gì
//     sai — một phép kiểm "tên class có người định nghĩa" không trả lời được
//     câu "đúng tên class chưa".
//
//   • Trang chủ mất năm trong mười khối của bản Blade nó thay: ô tìm kiếm lớn,
//     bản đồ nhúng, bốn bước đặt booking, gói chiến dịch, khối mời media owner.
//     Giai đoạn 6 nhận "xong" theo bốn điều kiện — SEO, sitemap, thời gian phản
//     hồi, ranh giới với Laravel — và **không điều nào** trong bốn điều đó là
//     "giao diện giống bản cũ".
//
//   • `/explore` và `/products` không còn bộ lọc nào hiện ra cho người dùng,
//     trong khi vẫn in "không khớp bộ lọc" khi danh sách rỗng.
//
// Phạm vi của phép kiểm này **hẹp và nói thẳng**: nó khẳng định một khối có
// trong HTML, không khẳng định khối đó trông đúng. jsdom không chạy layout và
// test này cũng không mở trình duyệt thật. Nó đóng đúng một khoảng: **một khối
// biến mất khỏi trang thì phải đỏ**, thay vì chỉ có người tình cờ nhận ra.
//
// Chạy: `npm run test:khoi` trong `webapp/` (cần `next build` trước, với API
// giả — xem `test/stub-api.mjs`).
// ════════════════════════════════════════════════════════════════════════════

import { spawn } from 'node:child_process';
import { startStubApi } from './stub-api.mjs';

const PORT = Number(process.env.KHOI_TEST_PORT ?? 4997);
const ORIGIN = 'https://oohx.net';

/**
 * Khối bắt buộc theo từng đường dẫn.
 *
 * `co` — chuỗi phải CÓ trong HTML. Mỗi chuỗi là một tên class đi kèm dấu nháy
 * mở của thuộc tính, nên `class="sc-card"` không khớp nhầm với `class="sc"`.
 *
 * `khong` — chuỗi KHÔNG được có. Dùng cho đúng những lỗi đã xảy ra: một thẻ
 * mở bằng `class="sc"` là lỗi cũ quay lại.
 *
 * `thuTu` — chuỗi phải xuất hiện theo ĐÚNG thứ tự liệt kê. Tuỳ chọn; xem
 * `kiemThuTu()` về việc vì sao sự có mặt là chưa đủ.
 */
const TRANG = [
    {
        path: '/',
        ten: 'trang chủ',
        co: [
            'class="hero-badge"',
            'class="hstat"',
            'class="hero-hint"',

            // Hero hai cột (18/10/2026): chữ + ô tìm kiếm trái, bản đồ thật
            // phải. Ba luật dưới canh cái KHUNG — nếu lưới mất, hai cột rơi
            // về một chồng dọc mà không có lỗi nào bắn ra.
            'class="hero hero--split"',
            'hero-luoi',
            'class="hero-chu"',
            'map-wrap map-wrap--hero',

            'class="ms-box"', // ô tìm kiếm lớn
            'class="mega-drop"', // hai dropdown của nó
            'class="map-mock"', // bản đồ nhúng
            'class="hp-map-city-bar"',
            'class="map-count"',

            // ── Thứ tự sáu khối của thân trang (lát 3, 18/10/2026) ────────
            //
            // Bố cục cũ xếp mười khối theo thứ tự chúng được viết ra, không
            // theo thứ tự người đọc cần: ba lối vào kho nằm rời nhau, hai
            // cách mua cách nhau BỐN khối, và mục mời media owner chen vào
            // giữa luồng của người mua.
            //
            // Thứ tự mới trả lời bốn câu theo đúng trình tự người mua hỏi:
            // kho có gì (② duyệt) → mua kiểu nào (③) → đặt ra sao (④) →
            // đặt với ai (⑤) — rồi mới tới lời mời dành cho người bán (⑥).
            //
            // `thuTu` canh chính THỨ TỰ đó, không chỉ canh sự có mặt: đổi
            // chỗ hai khối không làm hỏng một khẳng định `co` nào, và đó
            // đúng là kiểu hỏng mà lát này sinh ra để chữa.
            'class="dk-tabs"', // ② hai tab của khối duyệt kho
            'role="tablist"',
            'class="dk-tab"',
            'class="mua"', // ③ hai ô cách mua
            'class="lc"',
            'class="lc lc--nb"', // ô "mua theo gói", viền nổi
            'class="lc-ds"',
            'class="steps-grid"', // ④ bốn bước đặt booking
            'class="step-card"',
            'class="bao-dam"', // ⑤ ba bảo đảm dưới dải owner
            'class="bd-o"',
            'class="owner-cta"', // ⑥ khối mời media owner
            'class="oc-feat"',
            'class="oc-card', // thẻ owner (biến thể compact thêm hậu tố)

            // Bốn câu mô tả, nguyên văn bản Blade (chốt 18/10/2026, xác nhận
            // lại khi duyệt bố cục mới). Bản mock của lát 3 có bốn câu VIẾT
            // LẠI; luật này canh để không ai lấy nhầm từ đó.
            'AI search theo khu vực',
            'So sánh tối đa 5 vị trí',
            'proof-of-play report tự động',

            // Hai lưới thẻ 'duyệt' (18/10/2026). Bản Blade có ảnh + icon +
            // tên + số vị trí; bản Next đầu tiên rút xuống một hàng chip chữ
            // và một danh sách liên kết kiểu chân trang, vì '/filters' và
            // '/locations' là hai endpoint duy nhất có sẵn và cả hai đều
            // không mang ảnh.
            //
            // Từ lát 3 chúng là hai TAB của một khối, nhưng cả hai vẫn phải
            // nằm trong HTML máy chủ trả về: tab không hoạt động ẩn bằng
            // `hidden`, không bằng việc không render — nếu không, công cụ
            // tìm kiếm mất một nửa số liên kết vào kho.
            'class="cat-grid"',
            'class="cat-card"',
            'class="cat-icon"',
            'class="city-grid"',
            'class="city-card"',
            'class="city-bg"',

            // Ảnh hai lưới phải đi qua trình tối ưu của Next (18/10/2026).
            // Đo trên production trước khi sửa: 10,68 MB ảnh cho hai lưới,
            // mỗi ảnh tỉnh là PNG 800x600 nặng ~1,15 MB hiển thị ở 159px.
            // Sau khi chuyển: 0,23 MB, giảm 97,9%. Một thẻ <img> thô lọt
            // lại vào đây sẽ không làm trang vỡ — nó chỉ lặng lẽ kéo thêm
            // vài MB, đúng kiểu hỏng mà không ai nhìn thấy.
            '/_next/image',

            'class="ft-g"', // lưới liên kết tỉnh thành ở chân trang

            // Thanh "trust", chủ dự án chốt dựng nguyên văn 18/10/2026. Nó
            // từng nằm trong danh sách `khong` của chính tệp này — xem
            // `KhoiLoiHua.tsx` về những câu không kiểm được.
            'class="trust"',
            'class="trust-in"',
            'class="trust-item"',
            'class="trust-sep"',
            'Traffic real-time',
            'AI-powered search',
            'Secure payment',

            // Ba bảo đảm của khối ⑤ nói những điều CLAUDE.md ghi là sự thật
            // của sàn, không phải lời hứa năng lực. Canh nguyên văn để một
            // lần "làm mạnh câu chữ" phải đi qua một quyết định.
            'Sàn không thu hộ',
            'Giá do chủ màn hình đặt',
            'Booking đã xác nhận không đổi',
        ],
        // Thứ tự xuất hiện trong HTML, không chỉ sự có mặt. Xem chú thích ở
        // khối ② trên.
        thuTu: [
            'class="hero hero--split"',
            'class="dk-tabs"',
            'class="mua"',
            'class="steps-grid"',
            'class="bao-dam"',
            'class="owner-cta"',
        ],
        khong: [
            'class="sc"',
            'class="oc"',
            'class="oc-name"',

            // Ảnh SVG mô phỏng bảng điều khiển, gỡ 18/10/2026 khi bản đồ
            // thật lên hero. Nó không mang một con số nào nên không thuộc
            // nhóm F-15 — lý do gỡ là chỗ: nó chiếm vị trí đầu trang để vẽ
            // một màn hình không có thật, trước khi người xem thấy một màn
            // hình có thật.
            'class="hero-browser"',
            'class="hb-screen"',

            // ── Mục "Marketplace OOH đầu tiên có AI", gỡ ở lát 3 ──────────
            //
            // Bốn thẻ "GPT-powered", "Live sync <5min", "Data-driven ±8%",
            // "100% verified" và ô ảnh "3.2×" — không thẻ nào có một dòng mã
            // đứng sau, và "3.2×" không có nguồn.
            //
            // Sáu luật dưới đây TỪNG nằm ở `co`: chủ dự án chốt dựng nguyên
            // văn sáng 18/10 (#94), rồi chốt bỏ chiều 18/10 cùng bố cục mới.
            // Chúng đảo chiều theo quyết định, không theo ý người viết test —
            // nên lần đảo tiếp theo cũng phải là một quyết định, không phải
            // một lần sửa test cho xanh.
            'class="value-grid"',
            'class="ai-cards"',
            'class="ai-card"',
            'class="vv-number"',
            '3.2×',
            'Marketplace OOH đầu tiên có AI',

            // Ảnh hotlink Unsplash nằm TRONG mục AI, nên nó đi theo. Chủ dự
            // án chốt ba lần về tấm ảnh này trong một ngày (tự host #95 →
            // hotlink #96); gỡ cả mục thì không còn gì để chốt. Luật này
            // canh việc nó không lặng lẽ quay lại ở một mục khác — trang chủ
            // không còn phụ thuộc CDN bên thứ ba lúc chạy.
            'images.unsplash.com',

            // Lưới "Vị trí nổi bật", gỡ ở lát 3. `getFeaturedScreens()` đòi
            // ảnh RIÊNG + có giá; đo trên production 05/10/2026 thì 1 trên
            // 104 màn hình thoả cả hai, nên mục này luôn ra đúng một thẻ.
            // `inv-grid` là lưới thẻ màn hình — nó không còn chỗ nào trên
            // trang chủ.
            'class="inv-grid"',

            // Mục "Gói chiến dịch" cũ: một lưới `pd-card` cho một gói. Tên
            // gói và giá của nó nay nằm trong ô "mua theo gói" của khối ③.
            'class="pd-card"',

            // Nút "Bán cùng OOHX" của bản mock lát 3 trỏ vào
            // `/ban-cung-oohx` — một đường KHÔNG tồn tại. Chốt "không liên
            // kết nào trỏ vào trang chưa có" ở `tests/js/class-css.test.mjs`
            // đã chặn nó một lần ở PR #115; luật này chặn nó ở trang chủ.
            '/ban-cung-oohx',

            // Ảnh SVG mô phỏng bảng điều khiển, gỡ 18/10/2026 khi bản đồ
            // thật lên hero. Nó không mang một con số nào nên không thuộc
            // nhóm F-15 — lý do gỡ là chỗ: nó chiếm vị trí đầu trang để vẽ
            // một màn hình không có thật, trước khi người xem thấy một màn
            // hình có thật.
            'class="hero-browser"',
            'class="hb-screen"',

        ],
    },
    {
        path: '/explore',
        ten: 'danh sách kho',
        co: [
            'class="ex-hero"',
            'class="ex-top"',
            'class="ex-title"',
            'class="ph-map-btn"', // nút sang bản đồ
            'class="results-header"',
            'class="rh-count"',
            'class="sort-sel"', // ô sắp xếp
            'class="view-btn', // hai nút đổi kiểu xem
            'class="inv-grid"',
            'class="sc-card"', // thẻ màn hình, đúng tên
            'class="sc-foot"',

            // Lát 3 — ô tìm kiếm lớn, biến thể compact. Trước lát 3 trang này
            // đọc city/venue_type/sort từ URL mà không hiện ra bộ lọc nào.
            'class="ms-wrap"',
            'class="ms-box"',
            'class="ms-pill"',
            'class="mega-drop"',

            // Nhãn loại hình, không phải mã (lát 3 responsive, 18/10/2026).
            // Fixture có một màn hình `screen_type: 'lcd'`; trang phải in
            // "LCD". Bảng chữ sống ở `ScreenSummaryResource`.
            '>LCD<',
        ],
        khong: [
            'class="sc"',
            'class="oc-grid oc-grid--full"',

            // `ms-hero` chỉ thuộc biến thể của trang chủ (max-width 760px);
            // có nó ở đây nghĩa là `variant` bị truyền sai.
            'ms-wrap ms-hero',

            // Ba tên class của bản Blade không có định nghĩa CSS nào — chúng
            // là móc `querySelectorAll` và một biến thể rỗng, và ở React thì
            // sự kiện gắn trực tiếp.
            'ms-compact',

            // Hàng thông số rỗng (lát 3 responsive, 18/10/2026). Fixture có
            // một màn hình không kích thước; thẻ phải BỎ hàng đó chứ không in
            // "Kích thước —". Trên kho thật 0/50 màn hình có kích thước, nên
            // dấu gạch này từng xuất hiện ở gần như mọi thẻ.
            'sc-spec-v">—<',

            // Mã thô ra mặt người dùng — chuyện đã xảy ra với `lcd`.
            'sc-spec-v">lcd<',
        ],
    },
    {
        path: '/explore/man-hinh-co-gia',
        ten: 'chi tiết màn hình',
        co: [
            // Lát 4 — thư viện ảnh
            'class="bc"', // breadcrumb
            'class="bc-sep"',
            'class="gallery"', // nhiều ảnh nên KHÔNG có gallery--single
            'class="gal-main"',
            'class="gal-thumbs"',
            'class="gal-thumb gal-thumb-on"',
            'class="gal-count"',
            'class="gal-badge"',

            // Lát 4 — bố cục hai cột và hàng số liệu
            'class="detail-layout"',
            'class="detail-sidebar"',
            'class="dtitle"',
            'class="dmeta"',
            'class="stats-row"',
            'class="stat-box"',
            'class="oc-mini"', // thẻ owner một dòng

            // Lát 4 — bốn tab, và nội dung CỦA CẢ BỐN phải có trong HTML máy
            // chủ render (bản Blade cũng in hết rồi ẩn bằng CSS)
            //
            // `tabs`, KHÔNG `dk-tabs`: đây là thanh tab gạch chân của trang
            // chi tiết (`TabChiTiet`), một bộ class khác với thanh tab dạng
            // viên của khối "Duyệt kho" trên trang chủ. Hai bộ từng cùng tên
            // `.tabs`/`.tab` trong `frontpage.css`, và luật sau thắng luật
            // trước — khối duyệt kho đổi sang tiền tố `dk-` ở lát 3.
            'class="tabs"',
            'id="tp-info"',
            'id="tp-insights"',
            'id="tp-avail"',
            'id="tp-review"',
            'class="specs-grid"',

            // Lát 4 — insights
            'class="ins"',
            'class="ins-stats"',
            'class="ins-stat-src"', // badge "Đo"
            'class="ins-block"',
            'class="ins-bar-fill ins-bar-fill--bl"',
            'class="ins-day is-best"',
            'class="ins-pill ins-pill--mid"',
            'class="ins-tag ins-tag--bl"',
            'class="ins-source ins-source-foot"',

            // Lát 4 — lịch trống, cả ba trạng thái (fixture dựng đủ ba)
            'class="avail-grid"',
            'class="month-card"',
            'class="cal-grid"',
            'class="cal-dh"',
            'class="cal-day cd-ok"',
            'class="cal-day cd-pt"',
            'class="cal-day cd-bk"',
            'class="cal-legend"',

            // Lát 4 — panel giá
            'class="bp"',
            'class="bp-head"',
            'class="bp-price"',
            'class="bp-body"',
            'class="bp-field"',
            'class="bp-inp"',
            'class="bp-sum"',
            'class="bp-ctas"',
            'class="bp-foot"',

            // Lát 4 — khối vị trí + POI
            'class="loc-section"',
            'class="loc-browser"',
            'class="loc-list"',
            'class="loc-filter"',
            'class="loc-filter-pill is-active"',
            'class="loc-grp-h"',
            'class="loc-item"',
            'class="loc-dot"',
            'class="loc-map-wrap"',
            'class="loc-map"',

            // Lát 4 — màn hình tương tự
            'class="similar-section"',
            'class="inv-grid"',
            'class="sc-card sc-compact"',

            // Bốn thẻ "Điểm nổi bật", chủ dự án chốt dựng nguyên văn
            // 18/10/2026. Chúng in vô điều kiện cho mọi màn hình.
            'class="hl-grid"',
            'class="hl-card"',
            'AI Traffic Data',
            'Proof of Play',
            'Verified Location',
            'Fast Booking',
        ],
        khong: [
            // CHÚ Ý: `class="sc"` KHÔNG nằm trong danh sách này, khác với
            // `/explore` và `/products`. `.sc` là một ô bảng thông số
            // (frontpage.css:1330) và trang chi tiết có đúng bảng đó bên trong
            // `specs-grid`. Lỗi của lát 0 là thẻ **màn hình** mở bằng tên ấy —
            // và chỗ canh điều đó ở trang này là `class="sc-card sc-compact"`
            // trong danh sách `co` phía trên.

            // Bốn thẻ đánh giá 4.9 sao: bản Blade đã bọc trong `@if(false)`,
            // tức chính tác giả đã tắt vì chúng là dữ liệu bịa.
            'class="rv-item"',
            'class="rv-big"',

            // Hàng VAT và "Tổng cộng" tính bằng JS: CLAUDE.md mục 5 nói số
            // tiền do máy chủ tính, và thuế suất không có trong hợp đồng API.
            'class="bp-row bp-total"',

            // Nút "lưu" (trái tim) gọi POST /save/{id} — khu người mua chưa
            // chuyển sang Next, nên một trái tim bấm vào không làm gì.
            'id="savebtn"',

            // Câu "Thanh toán bảo mật bởi OOHX": sàn KHÔNG thu hộ.
            'Thanh toán bảo mật',
        ],
    },
    {
        path: '/explore/man-hinh-khong-gia',
        ten: 'chi tiết màn hình chưa có giá',
        co: [
            // Không giá → panel báo giá, không panel tính tiền.
            'class="bp bp-quote"',
            'class="bp-quote-title"',

            // Fixture của màn hình này KHÔNG có insights, nên tab Insights
            // phải vắng mặt hẳn — đúng cách bản Blade ẩn tab khi
            // `has_insights` là false.
            'id="tp-info"',
            'id="tp-avail"',

            // Fixture này có toạ độ nhưng KHÔNG có POI: khối bản đồ vẫn dựng,
            // danh sách bên trái hiện trạng thái rỗng. Đó là một nhánh render
            // riêng, và nó phải được đi qua ở đâu đó.
            'class="loc-browser"',
            'class="loc-empty"',
            'class="loc-empty-sub"',
        ],
        khong: [
            'id="tp-insights"',

            // `offers` trong JSON-LD chỉ xuất hiện khi CÓ giá: `price: 0` với
            // schema.org nghĩa là MIỄN PHÍ.
            '"offers"',

            // Có toạ độ nên khối bản đồ vẫn dựng, nhưng KHÔNG có POI nào —
            // nên thanh chip lọc nhóm không được xuất hiện.
            'class="loc-filter"',
        ],
    },
    {
        path: '/owners',
        ten: 'danh sách media owner',
        co: [
            'class="pg-hero"',
            'class="pg-hero-search"', // ô tìm kiếm
            'class="cat-chips"', // chip lọc theo loại hình
            'class="cat-chip',
            'class="oc-grid oc-grid--full"', // đây mới là lưới thẻ owner
            'class="oc-card"',
            'class="oc-card-cover"',
            'class="oc-card-stats"',
        ],
        khong: ['class="oc"', 'class="oc-body"', 'class="oc-stats"'],
    },
    {
        path: '/owners/owner-mot',
        ten: 'hồ sơ media owner',
        co: [
            // Lát 5 — ảnh bìa + avatar
            'class="fp-cover"',
            'class="fp-cover-img"',
            'class="fp-avatar-wrap"',
            'class="fp-avatar"',

            // Lát 5 — hàng tên + meta + nút liên hệ
            'class="fp-header"',
            'class="fp-name"',
            'class="fp-tagline"',
            'class="fp-meta"',
            'class="fp-meta-item"',
            'class="fp-header-actions"',
            'class="fp-meta-item own-rating"', // điểm trung bình, chỉ khi có đánh giá
            'class="own-rating-avg"',

            // Lát 5 — hai tab, và nội dung CẢ HAI render ở máy chủ
            'class="fp-nav-wrap"',
            'class="fp-tabs"',
            'id="fp-overview"',
            'id="fp-inventory"',

            // Lát 5 — cột trái: bốn thẻ
            'class="fp-overview"',
            'class="fp-sidebar"',
            'class="fp-card"',
            'class="fp-card-title"',
            'class="fp-info-row"',
            'class="fp-info-val"',
            'class="fp-info-label"',
            'class="fp-stats-grid"',
            'class="fp-stat"',
            'class="fp-stat-n"',
            'class="fp-venue-tags"',
            'class="fp-venue-tag"',

            // Lát 5 — đánh giá người mua
            'class="own-rv"',
            'class="own-rv-hd"',
            'class="own-rv-org"',
            'class="own-rv-cmt"',
            'class="own-rv-date"',

            // Lát 5 — cột phải: băng trượt
            'class="fp-card fp-featured-card"',
            'class="fp-featured-head"',
            'class="fp-slider-nav"',
            'class="fp-slider-btn"',
            'class="fp-slider"',
            'class="sc-card sc-compact"',

            // Lát 5 — tab kho: ô tìm kiếm lớn + lưới + bộ đếm
            'class="fp-inv-filter"',
            'class="ms-wrap"',
            'class="fp-inv-header"',
            'class="avail-badge"',
            'class="inv-grid"',

            // JSON-LD có `aggregateRating` vì fixture CÓ đánh giá
            'aggregateRating',

            // `og:type=profile` như bản Blade
            'content="profile"',
        ],
        khong: [
            // Bố cục cũ của bản Next: `pg-hero` + một `<h2>` là tất cả những
            // gì trang này từng có.
            'class="pg-hero"',

            // Lưới thẻ owner KHÔNG thuộc trang hồ sơ của một owner.
            'class="oc-grid oc-grid--full"',

            // `stats.screens` / `stats.cities` là hai khóa KHÔNG có trong hợp
            // đồng `OwnerDetail` (stub từng viết sai thế). Nếu trang đọc chúng
            // thì hai số đếm ra 0 — nên canh chuỗi "0 màn hình".
            '>0 màn hình',
        ],
    },
    {
        path: '/owners/owner-khong-danh-gia',
        ten: 'hồ sơ owner chưa có đánh giá',
        co: [
            'class="fp-avatar"',
            'id="fp-overview"',

            // Owner này KHÔNG có màn hình nào: thẻ "Màn hình nổi bật" vẫn phải
            // có mặt với câu nói rõ, không phải một băng trượt rỗng.
            'Media owner này chưa công khai màn hình nào',
        ],
        khong: [
            // Chưa ai đánh giá → không thẻ đánh giá, không điểm trung bình,
            // và JSON-LD KHÔNG được khai `aggregateRating`: `ratingValue: 0`
            // nói với công cụ tìm kiếm rằng owner bị đánh giá 0/5.
            'class="own-rv"',
            'class="fp-meta-item own-rating"',
            'aggregateRating',

            // Không `founded` → không ô "Kinh nghiệm".
            'Kinh nghiệm',

            // Băng trượt không dựng khi không có thẻ nào.
            'class="fp-slider"',
        ],
    },
    {
        path: '/products',
        ten: 'danh sách gói',
        co: [
            'class="inv-grid"',
            'class="pd-card"',
            'class="pd-photo"',
            'class="pd-foot"',

            // Lát 3 — hero và chip danh mục
            'class="ph"',
            'class="ph-top"',
            'class="ph-title"',
            'class="ph-map-btn"',
            'class="fchips"',
            'class="fchip-count"',

            // Lát 3 — bố cục hai cột và sidebar lọc
            'class="listing-layout"',
            'class="sidebar"',
            'class="sidebar-scroll"',
            'class="sb-card"',
            'class="sb-title"',
            'class="sb-group"',
            'class="sb-item',
            'class="sb-checkbox"',
            'class="sb-count"',
            'class="sb-input"',

            // Lát 3 — drawer của mobile
            'class="drawer-drag"',
            'class="drawer-drag-bar"',
            'class="filter-float"',

            // Lát 3 — thanh kết quả và ô sắp xếp
            'class="results-header"',
            'class="rh-count"',
            'class="sort-sel"',

            // Nhãn loại gói phải là chữ tiếng Việt tra từ bảng, không phải mã
            // thô. `ProductCard` có `kieu?.nhan ?? product.type`, nên một giá
            // trị ngoài enum đi thẳng ra trang — và đúng thế đã xảy ra: stub
            // trả `type: 'bundle'` và thẻ in "bundle".
            '>Gói</span>',
        ],
        khong: [
            'class="sc"',

            // `sb-search-inp` KHÔNG có định nghĩa trong frontpage.css; bản
            // Blade bù bằng một đống style nội tuyến, trong khi `.sb-input`
            // (dòng 1179) đã có đúng bộ thuộc tính đó kèm `:focus`.
            'sb-search-inp',

            // `.filter-backdrop{display:none}` + `!important` ở desktop, kèm
            // chú thích "removed, panel is opaque white". Ở bản Blade nó là một
            // div không bao giờ hiện mà JS vẫn gắn click để đóng drawer.
            'filter-backdrop',

            // Hai nút đổi kiểu xem KHÔNG thuộc trang này: `.inv-grid.list-view`
            // nhắm vào `.ic-*` của `.inv-card`, không phải `.pd-card`.
            'class="view-btn',

            // Mã loại gói lọt ra trang thay vì nhãn.
            '>package</span>',
            '>bundle</span>',
        ],
    },
    {
        path: '/map',
        ten: 'bản đồ kho',
        co: [
            // Lát 6 — bố cục hai cột
            'class="map-layout"',
            'class="map-panel"',
            'class="map-area"',
            'class="map-canvas"',

            // Lát 6 — bộ lọc thông minh trong panel trái
            'class="sft"',
            'class="sft-search"',
            'class="sft-search-input"',
            'class="sft-chips"',
            'class="sft-chip"',
            'class="sft-adv-toggle"',

            // Lát 6 — danh sách và bộ đếm
            'class="mp-drag"',
            'class="mp-drag-bar"',
            'class="mp-count"',
            'class="mp-list"',

            // Lát 6 — thanh thành phố, bộ đếm nổi, nút thu phóng
            'class="map-toolbar"',
            'class="mt-btn on"', // nút "Tất cả" sáng lúc mở trang
            'class="mt-top-chips"',
            'class="map-count-badge"',
            'class="map-controls"',
            'class="mc-btn"',
            'class="panel-toggle"',

            // `h1` vẫn có mặt cho công cụ tìm kiếm, chỉ ẩn khỏi mắt
            'class="visually-hidden"',
        ],
        khong: [
            // Bố cục cũ của bản Next: một `pg-hero` cao rồi bản đồ bên dưới.
            'class="pg-hero"',

            // `sft-chips-wrap` là móc `querySelectorAll` của bản Blade, không
            // có định nghĩa CSS nào; `.sft-chips` đã `flex-wrap: wrap`.
            'sft-chips-wrap',

            // `#leaflet-map` là id, và CSS theo id buộc mọi nơi dùng lại khối
            // này phải đặt đúng id đó. Bản Next dùng `.map-canvas`.
            'id="leaflet-map"',

            // JSON-LD KHÔNG thuộc trang này: `/explore` đã khai cùng dữ liệu,
            // và hai trang cùng được index là tự tạo nội dung trùng.
            'application/ld+json',
        ],
    },
    {
        path: '/products/goi-mot',
        ten: 'chi tiết gói',
        co: [
            // Lát 7 — breadcrumb và bố cục hai cột
            'class="bc"',
            'class="bc-sep"',
            'class="pd-detail-layout"',
            'class="pd-detail-main"',
            'class="pd-detail-sidebar"',

            // Lát 7 — thư viện ảnh bộ `pd-detail-*` (KHÔNG phải bộ `gal-*`
            // của trang màn hình: trang gói ở bản Blade không có lightbox)
            'class="pd-detail-gallery"',
            'class="pd-detail-hero-img"',

            // Lát 7 — ba badge
            'class="badge b-bl"',
            'class="badge b-gray"',

            // Lát 7 — bảng thông số dùng bộ ô `sc` như trang màn hình
            'class="specs-grid"',
            'class="sc-l"',
            'class="sc-v"',

            // Lát 7 — panel giá có TAB (fixture là `listing_mode: both`)
            'class="bp"',
            'class="bp-tabs"',
            'class="bp-tab active"',
            'class="bp-tab-content active"',
            'class="bp-price"',
            'class="bp-lbl"', // nhãn "Chọn gói" của package_options
            'class="bp-foot"',

            // Lát 7 — danh sách màn hình CHỌN ĐƯỢC
            'class="pd-screen-list"',
            'class="pd-screen-item"',
            'class="pd-screen-check"',

            // Lát 7 — gói tương tự
            'Sản phẩm tương tự',
            'class="pd-card"',
        ],
        khong: [
            // Bố cục cũ của bản Next: `pg-hero` + một ảnh + một dòng giá.
            'class="pg-hero"',

            // Thẻ màn hình KHÔNG dùng ở trang này nữa — danh sách là
            // `pd-screen-item` có checkbox, không phải lưới thẻ.
            'class="sc-card"',

            // "Verified Owner" của bản Blade in vô điều kiện cho mọi owner.
            'Verified Owner',

            // "Thanh toán bảo mật bởi OOHX": sàn KHÔNG thu hộ.
            'Thanh toán bảo mật',

            // Badge tình trạng in theo `status === active`, mà endpoint chỉ
            // trả gói công khai — tức in vô điều kiện (audit F-15).
            'class="bp-avail"',

            // Mã loại gói lọt ra thay vì nhãn.
            '>package</span>',
        ],
    },
];

const loi = [];

async function kiemTrang(base, trang) {
    const res = await fetch(base + trang.path);

    if (!res.ok) {
        loi.push(`${trang.path} trả ${res.status}`);

        return;
    }

    const html = await res.text();

    for (const can of trang.co) {
        if (!html.includes(can)) {
            loi.push(
                `${trang.path} (${trang.ten}) thiếu \`${can}\` — khối tương ứng không có trong HTML`,
            );
        }
    }

    for (const cam of trang.khong) {
        if (html.includes(cam)) {
            loi.push(
                `${trang.path} (${trang.ten}) có \`${cam}\` — đó là tên class của một khối khác`,
            );
        }
    }

    kiemThuTu(trang, html);
}

/**
 * `thuTu` — các khối phải xuất hiện theo ĐÚNG thứ tự này trong HTML.
 *
 * Vì sao cần, bên cạnh `co`: `co` chỉ hỏi "khối này có mặt không". Đảo chỗ
 * hai mục của trang chủ thì mọi khẳng định `co` vẫn xanh, trong khi thứ tự
 * mới là toàn bộ nội dung của lát 3 — người mua đọc trang theo chiều từ trên
 * xuống, nên thứ tự LÀ bố cục.
 *
 * So bằng `indexOf` trên HTML thô: mỗi chuỗi trong `thuTu` phải là một chuỗi
 * xuất hiện đúng một lần và đã nằm trong `co`, nên không cần phân tích DOM.
 */
function kiemThuTu(trang, html) {
    if (!trang.thuTu) return;

    let truoc = -1;
    let tenTruoc = null;

    for (const moc of trang.thuTu) {
        const i = html.indexOf(moc);

        if (i < 0) {
            loi.push(`${trang.path} (${trang.ten}) thiếu mốc thứ tự \`${moc}\``);

            return;
        }

        if (i < truoc) {
            loi.push(
                `${trang.path} (${trang.ten}) sai thứ tự khối: ` +
                    `\`${moc}\` đứng TRƯỚC \`${tenTruoc}\`, phải đứng sau`,
            );

            return;
        }

        truoc = i;
        tenTruoc = moc;
    }
}

/**
 * Chống rỗng: bộ kiểm phải thật sự đang đọc HTML có nội dung.
 *
 * Không có phép kiểm này thì một `next start` trả trang lỗi 200 với thân rỗng
 * sẽ làm mọi khẳng định `includes` ở trên đỏ — hoặc tệ hơn, một lần sửa làm
 * danh sách `TRANG` rỗng sẽ cho ra "xanh vì không kiểm gì".
 */
function kiemKhongRong() {
    const tong = TRANG.reduce(
        (n, t) => n + t.co.length + t.khong.length + (t.thuTu?.length ?? 0),
        0,
    );

    if (TRANG.length < 4 || tong < 40) {
        loi.push(`Danh sách khối đã co lại: ${TRANG.length} trang, ${tong} khẳng định`);
    }
}

async function chay() {
    const { server, port: apiPort } = await startStubApi();
    const apiBase = `http://127.0.0.1:${apiPort}/api/v2`;

    const next = spawn(
        process.execPath,
        [
            'node_modules/next/dist/bin/next',
            'start',
            '--port',
            String(PORT),
            '--hostname',
            '127.0.0.1',
        ],
        {
            env: { ...process.env, OOHX_API_BASE: apiBase, OOHX_PUBLIC_ORIGIN: ORIGIN },
            stdio: ['ignore', 'pipe', 'pipe'],
        },
    );

    let log = '';
    next.stdout.on('data', (d) => (log += d));
    next.stderr.on('data', (d) => (log += d));

    const base = `http://127.0.0.1:${PORT}`;
    let san_sang = false;

    for (let i = 0; i < 60; i++) {
        try {
            await fetch(base + '/explore');
            san_sang = true;
            break;
        } catch {
            await new Promise((r) => setTimeout(r, 500));
        }
    }

    if (!san_sang) {
        console.error('Next không khởi động được trong 30 giây. Log:\n' + log);
        next.kill();
        server.close();
        process.exit(1);
    }

    try {
        kiemKhongRong();

        for (const trang of TRANG) await kiemTrang(base, trang);
    } finally {
        next.kill();
        server.close();
    }

    if (loi.length === 0) {
        const tong = TRANG.reduce(
        (n, t) => n + t.co.length + t.khong.length + (t.thuTu?.length ?? 0),
        0,
    );

        console.log(`Khối giao diện: ${TRANG.length} trang, ${tong} khẳng định, tất cả khớp.`);
        process.exit(0);
    }

    console.error(`Khối giao diện: ${loi.length} lỗi\n`);
    for (const l of loi) console.error('  ' + l);
    process.exit(1);
}

chay().catch((e) => {
    console.error(e);
    process.exit(1);
});
