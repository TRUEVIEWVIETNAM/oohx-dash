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
 */
const TRANG = [
    {
        path: '/',
        ten: 'trang chủ',
        co: [
            'class="hero"',
            'class="hero-badge"',
            'class="hstat"',
            'class="hero-hint"',
            'class="hero-browser"',
            'class="ms-box"', // ô tìm kiếm lớn
            'class="mega-drop"', // hai dropdown của nó
            'class="map-mock"', // bản đồ nhúng
            'class="hp-map-city-bar"',
            'class="map-count"',
            'class="steps-grid"', // bốn bước đặt booking
            'class="step-card"',
            'class="owner-cta"', // khối mời media owner
            'class="oc-feat"',
            'class="inv-grid"', // lưới màn hình, không phải lưới thẻ owner
            'class="oc-card', // thẻ owner (biến thể compact thêm hậu tố)
            'class="ft-g"', // lưới liên kết tỉnh thành
        ],
        khong: ['class="sc"', 'class="oc"', 'class="oc-name"'],
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
        ],
        khong: ['class="sc"', 'class="oc-grid oc-grid--full"'],
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
        path: '/products',
        ten: 'danh sách gói',
        co: ['class="inv-grid"', 'class="pd-card"', 'class="pd-photo"', 'class="pd-foot"'],
        khong: ['class="sc"'],
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
}

/**
 * Chống rỗng: bộ kiểm phải thật sự đang đọc HTML có nội dung.
 *
 * Không có phép kiểm này thì một `next start` trả trang lỗi 200 với thân rỗng
 * sẽ làm mọi khẳng định `includes` ở trên đỏ — hoặc tệ hơn, một lần sửa làm
 * danh sách `TRANG` rỗng sẽ cho ra "xanh vì không kiểm gì".
 */
function kiemKhongRong() {
    const tong = TRANG.reduce((n, t) => n + t.co.length + t.khong.length, 0);

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
        const tong = TRANG.reduce((n, t) => n + t.co.length + t.khong.length, 0);

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
