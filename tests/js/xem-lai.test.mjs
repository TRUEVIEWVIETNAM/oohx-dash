import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dungTrang, choVeXong, mocScriptCanTrang, mocDuocKhai } from './bo-khung.mjs';

const TRANG = 'resources/views/buyer/booking/review.blade.php';

const CAU_HINH = {
    api: 'https://oohx.net/api/v2/campaigns/cpn-1',
    apiGui: 'https://oohx.net/api/v2/campaigns/cpn-1/submit',
    urlXem: 'https://oohx.net/my/campaigns/cpn-1',
    urlDangNhap: 'https://oohx.net/login',
    urlQuyChe: 'https://oohx.net/quy-che-hoat-dong',
    urlBaoMat: 'https://oohx.net/chinh-sach-bao-mat',
};

/**
 * Ba con số tiền CỐ Ý không thoả `subtotal × 1,08 = total`.
 *
 * `subtotal` 10.000.000, `vat` 900.000, `total` 10.900.000 — tức 9%, không phải
 * thuế suất nào trong `config/pricing.php`. Một bản tự nhân `vat_rate` ở client
 * sẽ ra 800.000 / 10.800.000 và ca test đỏ.
 *
 * Đó là cách canh "VAT tính một chỗ" mà không phải tin vào lời hứa — đúng lỗi
 * trang giỏ từng mắc.
 */
function cd(ghiDe = {}) {
    return {
        data: {
            campaign: {
                id: 'cpn-1',
                code: 'CPN-ABC',
                name: 'Honda Civic Q2',
                brand_name: 'Honda Vietnam',
                category: 'automotive',
                period: { start_date: '2026-10-08', end_date: '2026-11-08' },
                budget: 50000000,
            },
            lines: [
                {
                    id: 'ln-1',
                    screen: {
                        name: 'Vincom Q1',
                        photo_url: 'https://cdn/a.jpg',
                        owner: { name: 'Kim Ngân ADV' },
                        location: { city: 'Hà Nội' },
                    },
                    period: { start_date: '2026-10-08', end_date: '2026-11-08' },
                    delivery: { share_of_voice_pct: 25, spot_length: 15 },
                    estimate: { cost: 10000000 },
                },
            ],
            creatives: [{ id: 'cr-1', name: 'Banner 16:9', type: 'vast_tag', type_label: 'VAST tag' }],
            conflicts: [],
            summary: {
                currency: 'VND',
                line_count: 1,
                subtotal: 10000000,
                impressions: 120000,
                vat: 900000,
                total: 10900000,
                can_submit: true,
            },
            ...ghiDe,
        },
    };
}

test('tập móc script cần trang cung cấp trùng khít tập móc được khai', () => {
    assert.deepEqual(mocScriptCanTrang(TRANG), mocDuocKhai(TRANG));
});

test('trước khi dữ liệu về: khung chờ hiện, thân và ô lỗi ẩn', () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => new Promise(() => {}) });

    assert.equal(tai.querySelector('[data-xn-cho]').hidden, false);
    assert.equal(tai.querySelector('[data-xn-than]').hidden, true);

    // Script phải tự đặt trạng thái này — bộ khung dựng móc thành thẻ TRỐNG,
    // nên `hidden` của markup không tới được đây.
    assert.equal(tai.querySelector('[data-xn-loi]').hidden, true);
    assert.equal(tai.querySelector('[data-xn-xungdot]').hidden, true);

    // Nút gửi không được hiện TRƯỚC khi biết `can_submit`.
    assert.equal(tai.querySelector('[data-xn-form]').hidden, true);
    assert.equal(tai.querySelector('[data-xn-chan]').hidden, true);
});

test('ba con số tiền đọc THẲNG từ máy chủ, không nhân lại vat_rate', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ({ status: 200, body: cd() }) });

    await choVeXong();

    assert.match(tai.querySelector('[data-xn-truocvat]').textContent, /10\.000\.000/);
    assert.match(tai.querySelector('[data-xn-vat]').textContent, /900\.000/);
    assert.match(tai.querySelector('[data-xn-tong]').textContent, /10\.900\.000/);

    // 8% của 10.000.000 là 800.000 — con số một bản tự nhân sẽ ra.
    assert.doesNotMatch(tai.querySelector('[data-xn-vat]').textContent, /800\.000/);
    assert.doesNotMatch(tai.querySelector('[data-xn-tong]').textContent, /10\.800\.000/);
});

test('không còn dòng CPM dưới mỗi màn hình', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ({ status: 200, body: cd() }) });

    await choVeXong();

    // `floor_cpm_at_booking` bị `BookingLineResource` cố ý loại: "đặt một đơn
    // giá chưa nhân bên cạnh một tổng đã nhân là mời người đọc so hai số không
    // so được với nhau". Ca này canh việc trang không đòi nó lại.
    assert.doesNotMatch(tai.querySelector('[data-xn-dong]').textContent, /CPM/);
});

test('can_submit=true: hiện biểu mẫu gửi, ẩn khối chặn', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ({ status: 200, body: cd() }) });

    await choVeXong();

    assert.equal(tai.querySelector('[data-xn-form]').hidden, false);
    assert.equal(tai.querySelector('[data-xn-chan]').hidden, true);
});

/**
 * `can_submit` do MÁY CHỦ quyết, không suy từ `conflicts` ở client.
 *
 * Phản hồi này cố ý **mâu thuẫn**: `conflicts` rỗng nhưng `can_submit` false
 * (đúng trường hợp chiến dịch không còn ở trạng thái `draft`). Một bản tự suy
 * từ `conflicts` sẽ hiện nút gửi, và ca này đỏ.
 */
test('can_submit=false với conflicts rỗng: vẫn ẩn biểu mẫu gửi', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({
            status: 200,
            body: cd({ summary: { ...cd().data.summary, can_submit: false } }),
        }),
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-xn-form]').hidden, true, 'không được hiện nút gửi');
    assert.equal(tai.querySelector('[data-xn-chan]').hidden, false, 'phải hiện khối chặn');
});

test('xung đột hiện theo requested_pct và available_pct của DTO', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({
            status: 200,
            body: cd({
                conflicts: [{
                    booking_line_id: 'ln-1',
                    screen_name: 'Vincom Q1',
                    requested_pct: 25,
                    available_pct: 10,
                    dates: '08/10 → 08/11',
                }],
                summary: { ...cd().data.summary, can_submit: false },
            }),
        }),
    });

    await choVeXong();

    // Bộ khung dựng mọi móc THÀNH EM RUỘT của nhau, không lồng nhau: nên chữ
    // nằm ở `-ds`, còn `-xungdot` chỉ giữ cờ ẩn/hiện.
    const chu = tai.querySelector('[data-xn-xungdot-ds]').textContent;

    assert.equal(tai.querySelector('[data-xn-xungdot]').hidden, false);
    assert.match(chu, /Vincom Q1/);
    assert.match(chu, /yêu cầu 25%/);
    assert.match(chu, /còn trống 10%/);
    assert.doesNotMatch(chu, /undefined/, 'đọc sai tên trường sẽ ra "undefined"');
});

test('loại nội dung dùng type_label, không hoa hoá mã', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ({ status: 200, body: cd() }) });

    await choVeXong();

    const nd = tai.querySelector('[data-xn-nd]').textContent;

    assert.match(nd, /VAST tag/);
    assert.doesNotMatch(nd, /VAST_TAG/, 'mã CSDL không được lọt ra');
    assert.doesNotMatch(nd, /vast_tag/);
});

test('gửi đặt chỗ gọi POST với hai ô đồng ý', async () => {
    let than = null;

    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: (url, tuyChon) => {
            if (url === CAU_HINH.api) { return { status: 200, body: cd() }; }

            assert.equal(tuyChon.method, 'POST');
            than = JSON.parse(tuyChon.body);

            return { status: 200, body: { data: { campaign: { id: 'cpn-1' } } } };
        },
    });

    await choVeXong();

    tai.querySelector('[data-xn-xacnhan]').checked = true;
    tai.querySelector('[data-xn-dongy]').checked = true;

    tai.querySelector('[data-xn-form]').dispatchEvent(
        new tai.defaultView.Event('submit', { bubbles: true, cancelable: true }),
    );

    await choVeXong();

    assert.deepEqual(than, { confirm_accuracy: 1, accept_terms: 1 });
});

test('401 vẽ lối Đăng nhập lại, không nạp lại trang', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 401, body: { error: 'unauthorized', code: 401 } }),
    });

    await choVeXong();

    const a = tai.querySelector('[data-xn-loi] a');

    assert.ok(a, 'phải có thẻ a để bấm');
    assert.equal(a.getAttribute('href'), CAU_HINH.urlDangNhap);
    assert.deepEqual(loiJsdom.filter((m) => /navigation/i.test(m)), []);
});
