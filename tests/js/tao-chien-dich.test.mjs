// Múi giờ ÂM (UTC−10), đặt TRƯỚC mọi import: kỳ chạy là ngày theo lịch, nên
// `new Date('2026-10-08')` lùi một ngày ở múi giờ này. Chạy ở UTC — như máy CI
// mặc định — thì lỗi đó không bao giờ hiện ra.
process.env.TZ = 'Pacific/Honolulu';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dungTrang, choVeXong, mocScriptCanTrang, mocDuocKhai } from './bo-khung.mjs';

const TRANG = 'resources/views/buyer/booking/create.blade.php';

const CAU_HINH = {
    apiGio: 'https://oohx.net/api/v2/cart',
    apiTao: 'https://oohx.net/api/v2/campaigns',
    urlGio: 'https://oohx.net/cart',
    urlBuocSau: 'https://oohx.net/booking',
    urlDangNhap: 'https://oohx.net/login',
};

/**
 * Giỏ mẫu, và `subtotal` CỐ Ý không bằng tổng các món.
 *
 * 5.000.000 + 3.000.000 = 8.000.000, nhưng `subtotal` trả 7.900.000. Một bản
 * tự cộng lại ở client sẽ hiện 8.000.000 và ca test đỏ — đó là cách canh "tiền
 * do máy chủ quyết" mà không phải tin vào lời hứa.
 */
function gio(ghiDe = {}) {
    return {
        data: {
            items: [
                {
                    id: 'it-1',
                    screen: { name: 'Màn hình "Vincom" Q1', photo_url: 'https://cdn/x.jpg' },
                    period: { start_date: '2026-10-08', end_date: '2026-11-08' },
                    estimate: { cost: 5000000 },
                },
                {
                    id: 'it-2',
                    screen: { name: 'Aeon Tân Phú', photo_url: '' },
                    period: { start_date: '2026-10-10', end_date: '2026-10-20' },
                    estimate: { cost: 3000000 },
                },
            ],
            summary: { currency: 'VND', item_count: 2, subtotal: 7900000, vat: 632000, total: 8532000 },
            ...ghiDe,
        },
    };
}

test('tập móc script cần trang cung cấp trùng khít tập móc được khai', () => {
    assert.deepEqual(mocScriptCanTrang(TRANG), mocDuocKhai(TRANG));
});

test('trước khi giỏ về: khung chờ hiện, danh sách và tổng ẩn', () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => new Promise(() => {}) });

    assert.equal(tai.querySelector('[data-tc-cho]').hidden, false);
    assert.equal(tai.querySelector('[data-tc-ds]').hidden, true);
    assert.equal(tai.querySelector('[data-tc-tong]').hidden, true);

    // Script phải tự đặt trạng thái này — bộ khung dựng móc thành thẻ TRỐNG,
    // nên `hidden` của markup không tới được đây.
    assert.equal(tai.querySelector('[data-tc-loi]').hidden, true);
});

test('tổng lấy từ summary.subtotal của máy chủ, KHÔNG cộng lại ở client', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ({ status: 200, body: gio() }) });

    await choVeXong();

    const chu = tai.querySelector('[data-tc-tong-tien]').textContent;

    assert.match(chu, /7\.900\.000/, 'phải hiện subtotal của máy chủ');
    assert.doesNotMatch(chu, /8\.000\.000/, 'không được tự cộng lại các món');
});

test('ngày theo lịch không lùi một ngày ở múi giờ âm', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ({ status: 200, body: gio() }) });

    await choVeXong();

    const hang = tai.querySelectorAll('[data-tc-ds] > div');

    assert.equal(hang.length, 2);
    assert.match(hang[0].textContent, /08\/10 → 08\/11/, 'ngày 08 không được thành 07');
});

test('tên màn hình chứa dấu nháy không thoát ra khỏi thuộc tính', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ({ status: 200, body: gio() }) });

    await choVeXong();

    // Cấu trúc một hàng: [img, khối-giữa, giá]; khối giữa là [tên, kỳ chạy].
    const hang = tai.querySelectorAll('[data-tc-ds] > div')[0];
    const ten  = hang.children[1].children[0];

    assert.equal(ten.textContent, 'Màn hình "Vincom" Q1');
    assert.equal(tai.querySelectorAll('[data-tc-ds] img').length, 2, 'đúng hai ảnh, không có thẻ lạ sinh thêm');
});

test('gửi biểu mẫu gọi POST với đúng thân, và không gửi ô trống', async () => {
    let than = null;

    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: (url, tuyChon) => {
            if (url === CAU_HINH.apiGio) { return { status: 200, body: gio() }; }

            assert.equal(tuyChon.method, 'POST');
            than = JSON.parse(tuyChon.body);

            return { status: 201, body: { data: { campaign: { id: 'cpn-1' } } } };
        },
    });

    await choVeXong();

    tai.querySelector('[data-tc-name]').value = 'Honda Civic Q2';
    tai.querySelector('[data-tc-budget]').value = '50000000';

    tai.querySelector('[data-tc-form]').dispatchEvent(
        new tai.defaultView.Event('submit', { bubbles: true, cancelable: true }),
    );

    await choVeXong();

    assert.deepEqual(than, { name: 'Honda Civic Q2', total_budget: '50000000' });
    assert.ok(! ('notes' in than), 'ô trống không được gửi lên');
});

test('lỗi validate hiện cạnh đúng ô, giữ nguyên thứ đã gõ, và bật lại nút', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: (url) =>
            url === CAU_HINH.apiGio
                ? { status: 200, body: gio() }
                : {
                    status: 422,
                    body: {
                        error: 'validation_failed',
                        code: 422,
                        details: [{ field: 'name', message: 'Tên chiến dịch là bắt buộc.' }],
                    },
                },
    });

    await choVeXong();

    const oTen = tai.querySelector('[data-tc-name]');
    oTen.value = 'x';

    tai.querySelector('[data-tc-form]').dispatchEvent(
        new tai.defaultView.Event('submit', { bubbles: true, cancelable: true }),
    );

    await choVeXong();

    const loi = tai.querySelector('[data-tc-loi-name]');

    assert.equal(loi.hidden, false);
    assert.match(loi.textContent, /bắt buộc/);
    assert.equal(oTen.value, 'x', 'thứ người dùng vừa gõ phải còn nguyên');
    assert.equal(tai.querySelector('[data-tc-tiep]').disabled, false, 'nút phải bật lại sau khi lỗi');
});

test('401 vẽ lối Đăng nhập lại, không nạp lại trang', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 401, body: { error: 'unauthorized', code: 401 } }),
    });

    await choVeXong();

    const a = tai.querySelector('[data-tc-loi] a');

    assert.ok(a, 'phải có thẻ a để bấm');
    assert.equal(a.getAttribute('href'), CAU_HINH.urlDangNhap);
    assert.deepEqual(loiJsdom.filter((m) => /navigation/i.test(m)), []);
});
