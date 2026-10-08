// Đặt TRƯỚC mọi thứ khác: Node đọc lại múi giờ khi biến này đổi, nhưng chỉ
// các lần dùng `Date` SAU đó mới thấy.
//
// Chọn một múi giờ ÂM có chủ ý (UTC−10). Ngày bắt đầu/kết thúc của một plan là
// ngày theo lịch; `new Date('2026-10-08')` đọc nó là nửa đêm UTC, nên ở múi âm
// nó lùi sang 07. Chạy test ở UTC — như máy CI — thì lỗi đó không bao giờ hiện
// ra, và đúng loại lỗi này đã xảy ra một lần.
process.env.TZ = 'Pacific/Honolulu';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dungTrang, choVeXong, chuCua, mocScriptDiTim, mocDuocKhai } from './bo-khung.mjs';

const TRANG = 'resources/views/buyer/cart.blade.php';

// ── Hợp đồng móc DOM ────────────────────────────────────────────────────────

test('tập móc script đi tìm trùng khít tập móc được khai', () => {
    assert.deepEqual(
        mocScriptDiTim(TRANG),
        mocDuocKhai(TRANG),
        'Script và tests/js/moc-dom.json lệch nhau. Một móc thiếu KHÔNG làm trang '
        + 'vỡ ồn ào — script hứng `null` bên trong một `.then()` nên trang hiện '
        + '"không tải được" với nguyên nhân sai. Vì vậy phép kiểm này phải chặt.',
    );
});

const CAU_HINH = {
    api: 'https://oohx.net/api/v2/cart',
    explore: 'https://oohx.net/explore',
    xoaBase: 'https://oohx.net/cart',
    csrf: 'csrf-gia-123',
    anhThay: 'https://placehold.co/200x200',
};

/** Một dòng giỏ đủ trường, để từng test chỉ sửa phần nó quan tâm. */
function dongGio(ghiDe = {}) {
    return {
        id: 'item-1',
        screen: {
            slug: 'man-hinh-a',
            name: 'Màn hình A',
            photo_url: 'https://cdn.oohx.net/a.jpg',
            owner: { name: 'Kim Ngân ADV' },
            location: { city: 'Hà Nội' },
        },
        buy_mode: 'io',
        period: { start_date: '2026-10-08', end_date: '2026-11-08' },
        delivery: {
            pricing_model: 'io',
            screen_count: 3,
            duration_units: 2,
            duration_unit: 'month',
            booked_cpms: null,
        },
        estimate: { currency: 'VND', unit_price: 1_500_000, cost: 9_000_000 },
        ...ghiDe,
    };
}

function traLoi200(data) {
    return () => ({ status: 200, body: { data } });
}

// ── Gọi đúng đường ──────────────────────────────────────────────────────────

test('gọi đúng endpoint v2 trong cấu hình, không đường nào khác', async () => {
    const { daGoi } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi200({ items: [], summary: {} }),
    });

    await choVeXong();

    assert.deepEqual(daGoi, ['https://oohx.net/api/v2/cart']);
});

// ── Tiền ────────────────────────────────────────────────────────────────────

/**
 * Ba con số tiền lấy THẲNG từ API, không tính lại.
 *
 * Số trong phản hồi dưới đây **cố ý không** thoả `subtotal × 1,08 = total`.
 * Nếu trang tự nhân thì nó hiện 9.720.000; nếu nó đọc từ API thì hiện
 * 9.700.001. Đó là cách duy nhất phân biệt "đọc" với "tính lại" từ bên ngoài,
 * và chính phép tính lại đó từng làm lệch 1₫ ở đúng con số người mua đọc.
 */
test('ba con số tiền đọc thẳng từ API, không tính lại ở client', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi200({
            items: [dongGio()],
            summary: {
                currency: 'VND',
                item_count: 3,
                subtotal: 9_000_000,
                vat: 700_001,
                total: 9_700_001,
            },
        }),
    });

    await choVeXong();

    assert.equal(chuCua(tai, 'data-cart-subtotal'), '9.000.000 ₫');
    assert.equal(chuCua(tai, 'data-cart-vat'), '700.001 ₫');
    assert.equal(chuCua(tai, 'data-cart-total'), '9.700.001 ₫');
    assert.equal(chuCua(tai, 'data-cart-count'), '3');
});

test('số màn hình lấy từ summary của máy chủ, không đếm mảng items', async () => {
    // `item_count` của máy chủ là 7 trong khi mảng chỉ có 1 dòng. Máy chủ biết
    // tổng thật (dòng giỏ mang `screen_count`), client đếm mảng thì sai.
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi200({
            items: [dongGio()],
            summary: { item_count: 7, subtotal: 1, vat: 0, total: 1 },
        }),
    });

    await choVeXong();

    assert.equal(chuCua(tai, 'data-cart-count'), '7');
});

// ── Ngày theo lịch không được đi qua múi giờ ─────────────────────────────────

test('ngày bắt đầu không lùi một ngày ở múi giờ âm', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi200({
            items: [dongGio({ period: { start_date: '2026-10-08', end_date: '2026-11-01' } })],
            summary: { subtotal: 1, vat: 0, total: 1 },
        }),
    });

    await choVeXong();

    const html = tai.querySelector('[data-cart-items]').textContent;

    assert.match(html, /08\/10\/2026/, 'ngày bắt đầu phải là 08, không phải 07');
    assert.doesNotMatch(html, /07\/10\/2026/);
    assert.match(html, /01\/11\/2026/, 'ngày kết thúc phải là 01/11, không phải 31/10');
});

// ── Thoát ký tự ─────────────────────────────────────────────────────────────

/**
 * Tên màn hình vào CSDL qua `ScreenImport` từ tệp CSV của media owner, nên nó
 * là dữ liệu không tin được. Cách quen tay — gán `textContent` rồi đọc
 * `innerHTML` — KHÔNG thoát dấu nháy kép, và tên này đi vào `alt="…"` và
 * `src="…"`.
 */
test('tên màn hình chứa mã HTML không thoát ra khỏi thuộc tính', async () => {
    const doc = '"><img src=x onerror=alert(1)><b>đậm</b>';

    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi200({
            items: [dongGio({
                screen: {
                    slug: 'a', name: doc, photo_url: 'https://cdn.oohx.net/a.jpg',
                    owner: { name: doc }, location: { city: doc },
                },
            })],
            summary: { subtotal: 1, vat: 0, total: 1 },
        }),
    });

    await choVeXong();

    const khung = tai.querySelector('[data-cart-items]');

    // Đúng một ảnh — ảnh thật của màn hình. Thẻ `img` thứ hai nghĩa là chuỗi
    // kia đã thoát ra khỏi thuộc tính và trở thành phần tử.
    assert.equal(khung.querySelectorAll('img').length, 1);
    assert.equal(khung.querySelectorAll('b').length, 0);

    // Và nó vẫn phải HIỆN RA, dưới dạng chữ. Lọc bỏ im lặng cũng là một lỗi:
    // media owner đặt tên có dấu `<` thì họ phải thấy đúng tên mình.
    assert.match(khung.textContent, /<img src=x onerror=alert\(1\)>/);

    const img = khung.querySelector('img');
    assert.equal(img.getAttribute('alt'), doc, 'alt phải mang nguyên chuỗi, đã thoát');
    assert.equal(img.getAttribute('src'), 'https://cdn.oohx.net/a.jpg');
});

// ── Đường ghi vẫn ở Blade ───────────────────────────────────────────────────

test('form xóa vẫn POST về route Blade kèm token và _method DELETE', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi200({
            items: [dongGio({ id: 'dong-abc' })],
            summary: { subtotal: 1, vat: 0, total: 1 },
        }),
    });

    await choVeXong();

    const form = tai.querySelector('[data-cart-items] form');

    assert.ok(form, 'phải có form xóa');
    assert.equal(form.getAttribute('method'), 'POST');
    assert.equal(form.getAttribute('action'), 'https://oohx.net/cart/dong-abc');
    assert.equal(form.querySelector('[name="_token"]').value, 'csrf-gia-123');
    assert.equal(form.querySelector('[name="_method"]').value, 'DELETE');
});

// ── Các trạng thái ──────────────────────────────────────────────────────────

test('giỏ trống hiện khối trống, không hiện khối tiền', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi200({ items: [], summary: {} }),
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-cart-empty]').hidden, false);
    assert.equal(tai.querySelector('[data-cart-layout]').hidden, true);
    assert.equal(tai.querySelector('[data-cart-error]').hidden, true);
});

/**
 * 401 nói thẳng ra, KHÔNG nạp lại trang.
 *
 * Trang đi qua guard `web`; API đi qua `auth:sanctum` phía sau
 * `EnsureFrontendRequestsAreStateful`, và lớp sau chỉ bật khi `Referer`/`Origin`
 * khớp `sanctum.stateful`. Nên có thế: phiên web còn hợp lệ (trang dựng được)
 * mà API vẫn 401 vì referer bị tước. Nạp lại cho ra đúng kết quả cũ, và người
 * dùng mắc trong vòng lặp không lối ra.
 */
test('401 hiện lỗi và không nạp lại trang', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 401, body: { error: 'unauthenticated', message: 'x' } }),
    });

    await choVeXong();

    // `location.reload` chỉ đọc trong jsdom nên không stub được; thay vào đó
    // một lần gọi nó để lại `jsdomError` kiểu "Not implemented: navigation".
    assert.deepEqual(
        loiJsdom.filter((m) => /navigation|reload/i.test(m)),
        [],
        '401 không được nạp lại trang — đó là vòng lặp nạp trang không lối ra',
    );
    assert.deepEqual(loiJsdom, [], 'không được có lỗi script nào bị bỏ rơi');
    assert.equal(tai.querySelector('[data-cart-error]').hidden, false);
    assert.match(chuCua(tai, 'data-cart-error-msg'), /đăng nhập lại/i);
    assert.equal(tai.querySelector('[data-cart-layout]').hidden, true);
});

test('lỗi máy chủ hiện đúng thông điệp máy chủ gửi về', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 500, body: { message: 'Kho tạm thời không truy cập được.' } }),
    });

    await choVeXong();

    assert.equal(chuCua(tai, 'data-cart-error-msg'), 'Kho tạm thời không truy cập được.');
});

test('phản hồi không phải JSON hiện lỗi riêng, không vỡ im lặng', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 200, khongPhaiJson: true }),
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-cart-error]').hidden, false);
    assert.match(chuCua(tai, 'data-cart-error-msg'), /không đọc được/i);
});

test('mạng hỏng hiện lỗi, không để trang treo ở "Đang tải…"', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => { throw new Error('mất mạng'); },
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-cart-error]').hidden, false);
    assert.notEqual(chuCua(tai, 'data-cart-error-msg'), '');
    assert.equal(chuCua(tai, 'data-cart-sub'), '');
});

test('nút thử lại gọi lại API', async () => {
    let lan = 0;

    const { tai, daGoi } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => {
            lan++;
            return lan === 1
                ? { status: 500, body: { message: 'hỏng' } }
                : { status: 200, body: { data: { items: [], summary: {} } } };
        },
    });

    await choVeXong();
    assert.equal(tai.querySelector('[data-cart-error]').hidden, false);

    tai.querySelector('[data-cart-retry]').dispatchEvent(
        new tai.defaultView.Event('click'),
    );
    await choVeXong();

    assert.equal(daGoi.length, 2, 'bấm thử lại phải gọi lại');
    assert.equal(tai.querySelector('[data-cart-error]').hidden, true);
    assert.equal(tai.querySelector('[data-cart-empty]').hidden, false);
});

// ── Hai kiểu bán ────────────────────────────────────────────────────────────

test('kiểu CPM hiện đơn giá và số CPM, không hiện số màn hình', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi200({
            items: [dongGio({
                delivery: { pricing_model: 'cpm', booked_cpms: 4_000, screen_count: 1 },
                estimate: { unit_price: 50_000, cost: 200_000_000 },
            })],
            summary: { subtotal: 200_000_000, vat: 16_000_000, total: 216_000_000 },
        }),
    });

    await choVeXong();

    const chu = tai.querySelector('[data-cart-items]').textContent;

    assert.match(chu, /CPM/);
    assert.match(chu, /50\.000 ₫\/CPM/);
    assert.match(chu, /4\.000 CPM/);
});

test('kiểu I/O hiện đơn giá × số màn hình × số kỳ', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi200({
            items: [dongGio()],
            summary: { subtotal: 9_000_000, vat: 720_000, total: 9_720_000 },
        }),
    });

    await choVeXong();

    const chu = tai.querySelector('[data-cart-items]').textContent;

    assert.match(chu, /I\/O Booking/);
    assert.match(chu, /1\.500\.000 ₫ × 3 mh × 2 tháng/);
});
