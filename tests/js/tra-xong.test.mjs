import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dungTrang, choVeXong, mocScriptCanTrang, mocDuocKhai } from './bo-khung.mjs';

const TRANG = 'resources/views/buyer/booking/payment-success.blade.php';

const CAU_HINH = {
    api: 'https://oohx.net/api/v2/campaigns/cpn-1/payments',
    urlDangNhap: 'https://oohx.net/login',
};

function tra(ghiDe = {}) {
    return {
        transaction_ref: 'TRX-2026-0012',
        invoice_number: 'HD-000045',
        amount: 10900000,
        status: 'completed',
        status_label: 'Thành công',
        ...ghiDe,
    };
}

function ds(...lanTra) {
    return { status: 200, body: { data: { payments: lanTra } } };
}

test('tập móc script cần trang cung cấp trùng khít tập móc được khai', () => {
    assert.deepEqual(mocScriptCanTrang(TRANG), mocDuocKhai(TRANG));
});

test('trước khi dữ liệu về: khung chờ hiện, thẻ thật và ô lỗi ẩn', () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => new Promise(() => {}) });

    assert.equal(tai.querySelector('[data-ok-cho]').hidden, false);
    assert.equal(tai.querySelector('[data-ok-the]').hidden, true);

    // Script phải tự đặt trạng thái này. Bộ khung dựng móc thành thẻ TRỐNG nên
    // `hidden` của markup không tới được đây — một trang chỉ dựa vào markup sẽ
    // mở ra với một khối lỗi rỗng đang hiện.
    assert.equal(tai.querySelector('[data-ok-loi]').hidden, true);
});

/**
 * ĐÂY là lỗi thật mà lượt chuyển trang này sửa.
 *
 * Bản Blade cũ viết cứng `<span class="badge b-org">Chờ xác nhận</span>`, bất
 * kể `$payment->status`. Một lần trả đã được xác nhận vẫn hiện "Chờ xác nhận"
 * trên đúng trang người mua mở ra để kiểm tra.
 *
 * Nên ca này cho một lần trả `completed` và đòi đọc được "Thành công" — và nói
 * thẳng tên chuỗi cũ ra, để một lần hồi quy đọc được ngay nguyên nhân.
 */
test('trạng thái lấy từ status_label, không còn viết cứng "Chờ xác nhận"', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ds(tra()) });

    await choVeXong();

    const the = tai.querySelector('[data-ok-trangthai]');

    assert.equal(the.textContent, 'Thành công');
    assert.notEqual(the.textContent, 'Chờ xác nhận', 'thẻ viết cứng đã quay lại?');
    assert.doesNotMatch(the.textContent, /completed/, 'mã CSDL không được lọt ra');
});

test('màu thẻ theo mã trạng thái, và completed khác failed', async () => {
    async function lop(ma) {
        const { tai } = dungTrang(TRANG, {
            cauHinh: CAU_HINH,
            traLoi: () => ds(tra({ status: ma, status_label: 'Chữ của ' + ma })),
        });

        await choVeXong();

        return tai.querySelector('[data-ok-trangthai]').className;
    }

    const xong = await lop('completed');
    const hong = await lop('failed');
    const cho  = await lop('pending');

    assert.equal(xong, 'badge b-grn');
    assert.equal(hong, 'badge b-red');
    assert.equal(cho, 'badge b-org');

    assert.notEqual(xong, hong, '"thành công" và "thất bại" không được cùng màu');
});

/**
 * Mã lạ rơi vào `b-gray` — một class CÓ THẬT trong `frontpage.css`.
 *
 * Bản đầu của hàm màu viết `b-blu` cho `processing`, một class chưa ai định
 * nghĩa: thẻ ra DOM không nền, không màu chữ. `class-css.test.mjs` canh việc
 * class tồn tại; ca này canh nhánh mặc định không bỏ trống.
 */
test('mã trạng thái lạ vẫn ra một class, không ra chuỗi rỗng', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ds(tra({ status: 'mot_ma_chua_co', status_label: 'Lạ' })),
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-ok-trangthai]').className, 'badge b-gray');
});

test('tiền hiện VND nguyên, phân nhóm bằng dấu chấm', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ds(tra()) });

    await choVeXong();

    assert.equal(tai.querySelector('[data-ok-tien]').textContent, '10.900.000 ₫');
});

test('lấy lần trả MỚI NHẤT, tức phần tử đầu của danh sách', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ds(
            tra({ transaction_ref: 'TRX-MOI' }),
            tra({ transaction_ref: 'TRX-CU', amount: 1 }),
        ),
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-ok-ma]').textContent, 'TRX-MOI');
});

test('thiếu mã giao dịch hoặc số hoá đơn thì ra gạch ngang', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ds(tra({ transaction_ref: null, invoice_number: null })),
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-ok-ma]').textContent, '—');
    assert.equal(tai.querySelector('[data-ok-hoadon]').textContent, '—');
});

/**
 * Chưa có lần trả nào: ẩn cả thẻ.
 *
 * Bản cũ ẩn bằng một directive `if` của Blade — khi `$payment` là `null` thì
 * không render khối nào. Giữ đúng hành vi đó thay vì hiện một thẻ rỗng với bốn
 * dòng gạch ngang.
 */
test('không có lần trả nào: ẩn cả thẻ và khung chờ, không báo lỗi', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ds() });

    await choVeXong();

    assert.equal(tai.querySelector('[data-ok-the]').hidden, true);
    assert.equal(tai.querySelector('[data-ok-cho]').hidden, true);
    assert.equal(tai.querySelector('[data-ok-loi]').hidden, true, 'giỏ rỗng không phải lỗi');
});

test('gọi đúng một đường, và là đường payments của chiến dịch', async () => {
    const { daGoi } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ds(tra()) });

    await choVeXong();

    assert.deepEqual(daGoi, [CAU_HINH.api]);
});

test('máy chủ lỗi: hiện thông báo của máy chủ, ẩn khung chờ', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 403, body: { error: 'forbidden', message: 'Không có quyền xem chiến dịch này.', code: 403 } }),
    });

    await choVeXong();

    const loi = tai.querySelector('[data-ok-loi]');

    assert.equal(loi.hidden, false);
    assert.match(loi.textContent, /Không có quyền/);
    assert.equal(tai.querySelector('[data-ok-cho]').hidden, true);
    assert.equal(tai.querySelector('[data-ok-the]').hidden, true);
});

test('401 vẽ lối Đăng nhập lại, không nạp lại trang', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 401, body: { error: 'unauthorized', code: 401 } }),
    });

    await choVeXong();

    const a = tai.querySelector('[data-ok-loi] a');

    assert.ok(a, 'phải có thẻ a để bấm');
    assert.equal(a.getAttribute('href'), CAU_HINH.urlDangNhap);
    assert.equal(tai.querySelector('[data-ok-cho]').hidden, true);
    assert.deepEqual(loiJsdom.filter((m) => /navigation/i.test(m)), []);
});
