// ════════════════════════════════════════════════════════════════════════════
// Nút đặt chỗ theo phiên — `nút booking theo phiên`, 06/11/2026.
//
// ══ Lỗi được báo ══
//
// Người dùng đã đăng nhập (avatar hiện rõ ở thanh đầu) mà nút trên trang gói
// vẫn nói "Đăng nhập để booking". Không phải lỗi phiên: `usePhien()` đọc
// `/api/v2/me` đúng, và chính nó vẽ cái avatar kia. Ba nút ở hai trang viết
// **cứng** nhãn và một liên kết `/login`, không hỏi phiên bao giờ.
//
// ══ Bốn cái sai chỉ thấy được ở phía giao diện ══
//
//   • **Thiếu header CSRF.** `/api/v2` mang `EnsureFrontendRequestsAreStateful`,
//     nên với cookie phiên thì `VerifyCsrfToken` có hiệu lực. Thiếu token trả
//     **419** — mã mà không ai nghĩ tới khi đọc "thêm vào giỏ không ăn", và
//     thông điệp của nó không nói gì về nguyên nhân.
//
//   • **Quên giải mã URL của token.** Cookie Laravel mã hoá kiểu URL. Gửi lại
//     nguyên chuỗi thì cũng 419, và lần này trông y hệt "phiên hết hạn" — đủ
//     để người sửa đi lục phần đăng nhập thay vì phần CSRF.
//
//   • **Nuốt câu lỗi của máy chủ.** Hai câu 422 đáng giá nhất của giỏ hàng là
//     "Khoảng ngày … tương ứng N kỳ, không thể đặt M kỳ" và "… tối thiểu N
//     CPM". Chúng mang con số thật của kho và là thứ duy nhất giúp người mua
//     sửa được thao tác. Thay bằng một câu chung là bỏ đi chỗ đó.
//
//   • **Gộp 401 với 403.** Nhóm giỏ cần cả `auth:sanctum` lẫn middleware
//     `buyer`. Một tài khoản không phải người mua nhận 403; mời họ "đăng nhập
//     lại" là đẩy họ quay vòng bằng đúng tài khoản vừa bị từ chối.
//
// Phần máy chủ (`CartService`, `AddToCartRequest`) đã có chốt PHP riêng. Tệp
// này chỉ canh phía trình duyệt.
// ════════════════════════════════════════════════════════════════════════════

import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const doc = (duong) => readFileSync(join(GOC, duong), 'utf8');

const LIB = 'webapp/lib/gui-len-laravel.ts';
const NUT = 'webapp/components/NutThemVaoKeHoach.tsx';
const PANEL_MAN_HINH = 'webapp/components/PanelGia.tsx';
const PANEL_GOI = 'webapp/components/PanelGiaGoi.tsx';

const { themVaoGio } = await import('../../webapp/lib/gui-len-laravel.ts');
const { ngayKetThuc, congNgay } = await import('../../webapp/lib/ky-han.ts');

// ── Khung giả cho trình duyệt ───────────────────────────────────────────────

/** Những lời gọi `fetch` của lượt test đang chạy. */
let daGoi = [];

/**
 * Dựng `document.cookie` và `fetch` giả.
 *
 * `tra` là phản hồi cho `/api/v2/cart/items`; `/sanctum/csrf-cookie` luôn trả
 * rỗng và, nếu `cookieSauKhiXin` có giá trị, đặt cookie — mô phỏng đúng việc
 * Laravel gắn `XSRF-TOKEN` ở lượt đó.
 */
function dungKhung({ cookie = '', tra = {}, cookieSauKhiXin = null } = {}) {
    daGoi = [];
    globalThis.document = { cookie };

    globalThis.fetch = async (url, tuyChon = {}) => {
        daGoi.push({ url, tuyChon });

        if (url === '/sanctum/csrf-cookie') {
            if (cookieSauKhiXin !== null) globalThis.document.cookie = cookieSauKhiXin;

            return { ok: true, status: 204, json: async () => ({}) };
        }

        if (tra.nem) throw new Error('mạng hỏng');

        return {
            ok: (tra.status ?? 201) < 400,
            status: tra.status ?? 201,
            json: async () => {
                if (tra.khongPhaiJson) throw new Error('không phải JSON');

                return tra.than ?? { data: { summary: { item_count: 1, subtotal: 0 } } };
            },
        };
    };
}

/** Lời gọi tới endpoint giỏ, bỏ qua lượt xin cookie. */
const goiGio = () => daGoi.find((g) => g.url === '/api/v2/cart/items');

beforeEach(() => dungKhung());

// ── Hình dạng lời gọi ───────────────────────────────────────────────────────

test('gửi POST kèm cookie phiên và header CSRF đã giải mã', async () => {
    // Cookie Laravel mã hoá kiểu URL; `abc=` thành `abc%3D`.
    dungKhung({ cookie: 'laravel_session=xyz; XSRF-TOKEN=abc%3D' });

    await themVaoGio({ product_slug: 'goi-mot', buy_mode: 'package' });

    const g = goiGio();

    assert.ok(g, 'Không gọi /api/v2/cart/items');
    assert.equal(g.tuyChon.method, 'POST');
    assert.equal(
        g.tuyChon.credentials,
        'include',
        'Thiếu `credentials` thì cookie phiên không đi kèm và mọi lượt thêm đều 401.',
    );
    assert.equal(
        g.tuyChon.headers['X-XSRF-TOKEN'],
        'abc=',
        'Token phải được giải mã URL trước khi gửi — gửi nguyên `abc%3D` thì Laravel trả 419, '
        + 'một lỗi trông y hệt "phiên hết hạn".',
    );
    assert.equal(g.tuyChon.headers['Content-Type'], 'application/json');
    assert.deepEqual(JSON.parse(g.tuyChon.body), { product_slug: 'goi-mot', buy_mode: 'package' });
});

test('không xin cookie CSRF khi đã có sẵn', async () => {
    dungKhung({ cookie: 'XSRF-TOKEN=co-roi' });

    await themVaoGio({ product_slug: 'goi-mot' });

    assert.equal(
        daGoi.filter((g) => g.url === '/sanctum/csrf-cookie').length,
        0,
        'Một lượt đi mạng thừa trước mỗi lần bấm nút là một nhịp trễ người dùng cảm thấy được.',
    );
});

test('xin cookie CSRF khi chưa có, rồi dùng token vừa nhận', async () => {
    dungKhung({ cookie: '', cookieSauKhiXin: 'XSRF-TOKEN=moi-xin' });

    await themVaoGio({ product_slug: 'goi-mot' });

    assert.equal(daGoi[0].url, '/sanctum/csrf-cookie', 'Phải xin cookie TRƯỚC khi gửi.');
    assert.equal(goiGio().tuyChon.headers['X-XSRF-TOKEN'], 'moi-xin');
});

test('thân request không mang trường số tiền nào', async () => {
    dungKhung({ cookie: 'XSRF-TOKEN=t' });

    await themVaoGio({
        screen_slug: 'man-hinh-mot',
        start_date: '2026-11-10',
        end_date: '2026-12-09',
        pricing_model: 'io',
    });

    const than = JSON.parse(goiGio().tuyChon.body);

    // CLAUDE.md §5: số tiền do máy chủ tính. `CartService` tính lại
    // `estimated_cost` từ cấu hình kho, nên một trường tiền gửi lên vừa bị bỏ
    // qua vừa gợi ý sai cho người đọc mã rằng client có tiếng nói ở đó.
    for (const khoa of Object.keys(than)) {
        assert.ok(
            !/cost|price|amount|total|tien|gia/i.test(khoa),
            `Thân request có trường tiền \`${khoa}\` — máy chủ mới là nơi tính tiền.`,
        );
    }
});

// ── Đọc phản hồi ────────────────────────────────────────────────────────────

test('201 trả số món lấy từ summary của máy chủ', async () => {
    dungKhung({
        cookie: 'XSRF-TOKEN=t',
        tra: { status: 201, than: { data: { summary: { item_count: 4, subtotal: 250_000_000 } } } },
    });

    const kq = await themVaoGio({ product_slug: 'goi-mot' });

    assert.deepEqual(kq, { ok: true, soMon: 4, tongTam: 250_000_000 });
});

test('201 mà thân không đọc được vẫn là đã thêm', async () => {
    dungKhung({ cookie: 'XSRF-TOKEN=t', tra: { status: 201, khongPhaiJson: true } });

    const kq = await themVaoGio({ product_slug: 'goi-mot' });

    assert.equal(kq.ok, true, 'Một 201 không đọc được thân vẫn nghĩa là máy chủ đã ghi.');
});

test('mỗi mã HTTP ra một mã lỗi riêng', async () => {
    const bang = [
        [401, 'khach'],
        [419, 'khach'],
        [403, 'khong-phai-nguoi-mua'],
        [429, 'qua-nhieu'],
        [422, 'du-lieu'],
        [500, 'du-lieu'],
    ];

    for (const [status, ma] of bang) {
        dungKhung({ cookie: 'XSRF-TOKEN=t', tra: { status, than: {} } });

        const kq = await themVaoGio({ product_slug: 'goi-mot' });

        assert.equal(kq.ok, false, `${status} phải là lỗi`);
        assert.equal(kq.ma, ma, `${status} phải ra mã \`${ma}\``);
        assert.ok(kq.loi.length > 0, `${status} phải có câu nói được`);
    }
});

test('401 và 403 không dùng chung một câu', async () => {
    dungKhung({ cookie: 'XSRF-TOKEN=t', tra: { status: 401, than: {} } });
    const khach = await themVaoGio({ product_slug: 'goi-mot' });

    dungKhung({ cookie: 'XSRF-TOKEN=t', tra: { status: 403, than: {} } });
    const khongMua = await themVaoGio({ product_slug: 'goi-mot' });

    assert.notEqual(
        khach.loi,
        khongMua.loi,
        'Mời một tài khoản không-phải-người-mua "đăng nhập lại" là đẩy họ quay vòng '
        + 'bằng đúng tài khoản vừa bị từ chối.',
    );
});

test('câu 422 của máy chủ được giữ nguyên, không thay bằng câu chung', async () => {
    const cau = 'Khoảng ngày 10/11/2026 – 09/01/2027 tương ứng 2 kỳ, không thể đặt 1 kỳ.';

    dungKhung({
        cookie: 'XSRF-TOKEN=t',
        tra: { status: 422, than: { error: 'validation_failed', message: cau, code: 422, details: [] } },
    });

    const kq = await themVaoGio({ screen_slug: 'man-hinh-mot' });

    assert.equal(
        kq.loi,
        cau,
        'Câu này mang con số thật của kho và là thứ duy nhất giúp người mua sửa được thao tác.',
    );
});

test('không có message thì lấy câu đầu trong details', async () => {
    dungKhung({
        cookie: 'XSRF-TOKEN=t',
        tra: {
            status: 422,
            than: { error: 'validation_failed', details: [{ field: 'end_date', message: 'Ngày kết thúc phải sau ngày bắt đầu.' }] },
        },
    });

    const kq = await themVaoGio({ screen_slug: 'man-hinh-mot' });

    assert.equal(kq.loi, 'Ngày kết thúc phải sau ngày bắt đầu.');
});

test('mạng hỏng ra mã riêng chứ không giả vờ là lỗi dữ liệu', async () => {
    dungKhung({ cookie: 'XSRF-TOKEN=t', tra: { nem: true } });

    const kq = await themVaoGio({ product_slug: 'goi-mot' });

    assert.equal(kq.ok, false);
    assert.equal(kq.ma, 'mang');
});

// ── Ngày kết thúc: một ngày lệch là tiền gấp đôi ────────────────────────────

test('ngày kết thúc theo tháng khớp đúng ví dụ của máy chủ', () => {
    // Nguyên văn docblock `BillablePeriodCalculator::calendarMonths()`:
    // "15/01 – 14/02 = 1 kỳ. 15/01 – 15/02 = 2 kỳ."
    //
    // Nên một kỳ kể từ 15/01 phải kết thúc 14/02. Dôi một ngày là máy chủ đếm
    // hai kỳ và người mua trả tiền hai tháng cho một đơn một tháng — không
    // ngoại lệ nào, không cảnh báo nào.
    assert.equal(ngayKetThuc('2026-01-15', 1, false), '2026-02-14');
    assert.equal(ngayKetThuc('2026-01-01', 6, false), '2026-06-30');
    assert.equal(ngayKetThuc('2026-01-01', 12, false), '2026-12-31');
});

test('tháng đích ngắn hơn thì kẹp về cuối tháng, không tràn', () => {
    // Máy chủ dùng `addMonthsNoOverflow`: 31/01 + 1 tháng = 28/02.
    // `setUTCMonth` của JS thì tràn sang 02/03 hoặc 03/03.
    assert.equal(ngayKetThuc('2026-01-31', 1, false), '2026-02-27');
    assert.equal(ngayKetThuc('2028-01-31', 1, false), '2028-02-28', 'Năm nhuận có 29/02.');
    assert.equal(ngayKetThuc('2026-03-31', 1, false), '2026-04-29');
});

test('ngày kết thúc theo tuần đếm cả hai đầu mút', () => {
    // `ioUnits` nhánh tuần là `ceil(days / 7)` với `days` tính cả hai đầu, nên
    // một tuần từ 10/11 phải hết ngày 16/11 — 17/11 đã là tuần thứ hai.
    assert.equal(ngayKetThuc('2026-11-10', 1, true), '2026-11-16');
    assert.equal(ngayKetThuc('2026-11-10', 4, true), '2026-12-07');
});

test('phép tính ngày không đổi theo múi giờ của máy chạy', () => {
    // Ngày bắt đầu/kết thúc là ngày theo **lịch**. CI chạy ở UTC nên một phép
    // tính bằng giờ địa phương vẫn xanh ở đó và sai trên máy người dùng.
    const truoc = process.env.TZ;

    try {
        process.env.TZ = 'Pacific/Honolulu'; // UTC−10
        assert.equal(ngayKetThuc('2026-01-15', 1, false), '2026-02-14');
        assert.equal(congNgay('2026-01-15', 29), '2026-02-13');

        process.env.TZ = 'Pacific/Kiritimati'; // UTC+14
        assert.equal(ngayKetThuc('2026-01-15', 1, false), '2026-02-14');
        assert.equal(congNgay('2026-01-15', 29), '2026-02-13');
    } finally {
        if (truoc === undefined) delete process.env.TZ;
        else process.env.TZ = truoc;
    }
});

test('ngày không hợp lệ trả về nguyên chuỗi vào, không trả Invalid Date', () => {
    // Ô `<input type="date">` để trống cho chuỗi rỗng. Một `end_date` bằng
    // "Invalid Date" hay "NaN-NaN-NaN" đi lên máy chủ sẽ thành 422 có tên
    // trường — nhưng tên trường đó trỏ vào `end_date`, trong khi chỗ người
    // dùng cần sửa là ô ngày bắt đầu.
    assert.equal(ngayKetThuc('', 1, false), '');
    assert.equal(congNgay('khong-phai-ngay', 5), 'khong-phai-ngay');
});

// ── Bất biến trên mã giao diện ──────────────────────────────────────────────

test('chỉ một tệp gọi thẳng endpoint giỏ', () => {
    // Quét theo **lời gọi** `fetch('…')` chứ không theo chuỗi trần: docblock
    // của cả ba tệp đều nhắc tên endpoint để giải thích, và một phép tìm chuỗi
    // sẽ đếm chính lời giải thích là vi phạm — cùng cái bẫy đã làm đỏ bộ quét
    // của lát 2 plan ảnh.
    for (const tep of [NUT, PANEL_MAN_HINH, PANEL_GOI]) {
        assert.ok(
            !/fetch\(\s*['"`]\/api\/v2\/cart/.test(doc(tep)),
            `${tep} tự gọi endpoint giỏ. Ba bản \`fetch\` là ba chỗ có thể quên `
            + '`credentials` hoặc header CSRF, và cả hai cái quên ấy hỏng im lặng.',
        );
    }

    assert.ok(/fetch\(\s*['"`]\/api\/v2\/cart\/items['"`]/.test(doc(LIB)), 'Lời gọi phải nằm ở ' + LIB);
});

test('mọi fetch trong lib gửi lên Laravel đều kèm credentials', () => {
    const ma = doc(LIB);
    const soFetch = (ma.match(/fetch\(/g) ?? []).length;
    const soCredentials = (ma.match(/credentials:\s*'include'/g) ?? []).length;

    assert.ok(soFetch >= 2, `Chỉ thấy ${soFetch} lời gọi fetch — mẫu quét đã trôi khỏi mã thật.`);
    assert.equal(
        soCredentials,
        soFetch,
        'Một lời gọi thiếu `credentials` thì cookie phiên không đi kèm, và lỗi hiện ra là 401 '
        + 'chứ không phải lỗi của chỗ thật sự sai.',
    );
});

test('hai panel không còn tự dựng nút đăng nhập', () => {
    for (const tep of [PANEL_MAN_HINH, PANEL_GOI]) {
        assert.ok(
            !/href=\{duongDangNhap\}/.test(doc(tep)),
            `${tep} vẫn tự dựng liên kết đăng nhập. Nhánh khách phải nằm trong `
            + `${NUT} để nhánh người mua không thể bị quên ở một trong ba chỗ.`,
        );
    }

    assert.ok(/href=\{duongDangNhap\}/.test(doc(NUT)), 'Nhánh khách phải còn trong ' + NUT);
});

test('nút chạy ở trình duyệt, và nhãn khách giữ nguyên từng chữ', () => {
    const ma = doc(NUT);

    assert.ok(ma.startsWith("'use client'"), 'Nút đọc phiên nên phải là client component.');
    assert.ok(
        ma.includes("nhanKhach = 'Đăng nhập để booking'"),
        'Nhãn cho khách giữ nguyên: nó là thứ máy chủ render vào HTML đã cache, và đổi nó '
        + 'là đổi cả chốt khối giao diện.',
    );
});

test('nút chặn bấm đôi khi đang gửi', () => {
    const ma = doc(NUT);

    assert.ok(/disabled=\{dangGui\}/.test(ma), 'Nút phải vô hiệu trong lúc chờ phản hồi.');
    assert.ok(
        /if\s*\(dangGui\)\s*return/.test(ma),
        'Và chặn cả ở hàm xử lý: hai phản hồi về lệch nhau sẽ ghi đè trạng thái của nhau.',
    );
});

test('badge giỏ lấy số của máy chủ, không tự cộng một', () => {
    const ma = doc(NUT) + doc('webapp/lib/phien.ts');

    assert.ok(
        !/cart_count\s*\+\s*1|soMon\s*\+\s*1|\+\+\s*cart_count/.test(ma),
        'Hai tab cùng mở, hay một món đã có sẵn được `updateOrCreate` gộp vào, đều làm phép '
        + 'cộng ở client ra số sai. Cùng lý do tiền không được client tính.',
    );
    assert.ok(/datSoGio\(kq\.soMon\)/.test(doc(NUT)), 'Phải ghi đúng con số `summary.item_count`.');
});
