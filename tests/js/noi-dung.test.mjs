import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dungTrang, choVeXong, mocScriptCanTrang, mocDuocKhai } from './bo-khung.mjs';

const TRANG = 'resources/views/buyer/booking/creative.blade.php';

const CAU_HINH = {
    api: 'https://oohx.net/api/v2/campaigns/cpn-1',
    apiTai: 'https://oohx.net/api/v2/campaigns/cpn-1/creatives',
    urlReview: 'https://oohx.net/booking/cpn-1/review',
    urlDangNhap: 'https://oohx.net/login',
};

function cd(ghiDe = {}) {
    return {
        data: {
            campaign: { id: 'cpn-1', code: 'CPN-ABC', name: 'Honda Civic Q2' },
            lines: [
                {
                    id: 'ln-1',
                    screen: { name: 'Vincom Q1' },
                    creative_spec: { width_px: 1920, height_px: 1080 },
                },
                {
                    // Màn hình CHƯA khai độ phân giải: `creative_spec` về với hai
                    // `null`. Phải ra gạch ngang, không ra "null x null px".
                    id: 'ln-2',
                    screen: { name: 'Aeon Tân Phú' },
                    creative_spec: { width_px: null, height_px: null },
                },
            ],
            creatives: [],
            ...ghiDe,
        },
    };
}

/** Một nội dung cho mỗi trạng thái — ba trạng thái, ba chữ, ba màu. */
const BA_TRANG_THAI = [
    { id: 'cr-1', name: 'A', type: 'vast_tag', type_label: 'VAST tag', file_size: 0, status: 'pending_review', status_label: 'Chờ duyệt' },
    { id: 'cr-2', name: 'B', type: 'image', type_label: 'Hình ảnh', file_size: 204800, status: 'approved', status_label: 'Đã duyệt' },
    { id: 'cr-3', name: 'C', type: 'video', type_label: 'Video', file_size: 1024, status: 'rejected', status_label: 'Bị từ chối' },
];

/** `<input>` của bộ khung là ô chữ, nên `files` phải gắn tay. */
function ganTep(win, oTep, ten = 'banner.png') {
    const tep = new win.File(['x'], ten, { type: 'image/png' });

    Object.defineProperty(oTep, 'files', { value: [tep], configurable: true });

    return tep;
}

test('tập móc script cần trang cung cấp trùng khít tập móc được khai', () => {
    assert.deepEqual(mocScriptCanTrang(TRANG), mocDuocKhai(TRANG));
});

test('trước khi dữ liệu về: khối danh sách, ô lỗi và ô thành công đều ẩn', () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => new Promise(() => {}) });

    // Script phải tự đặt trạng thái này — bộ khung dựng móc thành thẻ TRỐNG,
    // nên `hidden` của markup không tới được đây.
    assert.equal(tai.querySelector('[data-nd-khoi-ds]').hidden, true);
    assert.equal(tai.querySelector('[data-nd-loi]').hidden, true);
    assert.equal(tai.querySelector('[data-nd-thanhcong]').hidden, true, 'chưa tải gì mà đã báo thành công');
});

test('loại nội dung dùng type_label, không hoa hoá mã CSDL', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 200, body: cd({ creatives: BA_TRANG_THAI }) }),
    });

    await choVeXong();

    const chu = tai.querySelector('[data-nd-ds]').textContent;

    assert.match(chu, /VAST tag/);
    assert.doesNotMatch(chu, /VAST_TAG/, '`toUpperCase()` đã quay lại?');
    assert.doesNotMatch(chu, /vast_tag/, 'mã CSDL không được lọt ra trang người mua');
});

test('chữ trạng thái lấy từ status_label, không còn tiếng Anh', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 200, body: cd({ creatives: BA_TRANG_THAI }) }),
    });

    await choVeXong();

    const the = [...tai.querySelectorAll('[data-nd-ds] .badge')];

    assert.deepEqual(the.map((e) => e.textContent), ['Chờ duyệt', 'Đã duyệt', 'Bị từ chối']);
});

/**
 * `rejected` phải KHÁC màu `pending_review`.
 *
 * Bản Blade cũ dùng một biểu thức ba ngôi chỉ biết `approved`, nên hai trạng
 * thái còn lại rơi vào cùng một nhánh: "chờ duyệt" và "bị từ chối" — hai nghĩa
 * trái nhau — hiện cùng một màu xám.
 */
test('ba trạng thái ra ba màu khác nhau, và không màu nào là b-gray', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 200, body: cd({ creatives: BA_TRANG_THAI }) }),
    });

    await choVeXong();

    const lop = [...tai.querySelectorAll('[data-nd-ds] .badge')].map((e) => e.className);

    assert.equal(new Set(lop).size, 3, 'ba trạng thái phải ra ba màu: ' + lop.join(' | '));

    // `b-gray` là nhánh mặc định — một mã rơi vào đó nghĩa là bảng màu thiếu nó.
    assert.deepEqual(lop.filter((c) => /b-gray/.test(c)), []);
});

test('bảng kích thước đọc creative_spec theo pixel, thiếu thì ra gạch ngang', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ({ status: 200, body: cd() }) });

    await choVeXong();

    const hang = [...tai.querySelectorAll('[data-nd-kichthuoc] > div')];

    assert.equal(hang.length, 2);
    assert.match(hang[0].textContent, /Vincom Q1/);
    assert.match(hang[0].textContent, /1920 x 1080 px/);
    assert.match(hang[1].textContent, /— x — px/, 'thiếu độ phân giải phải ra gạch ngang');
    assert.doesNotMatch(hang[1].textContent, /null/);
});

test('khung chờ của bảng kích thước bị dọn sau khi dữ liệu về', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ({ status: 200, body: cd() }) });

    await choVeXong();

    assert.equal(tai.querySelector('[data-nd-kt-cho]'), null);
});

test('chưa có nội dung nào: ẩn cả khối danh sách, không hiện thẻ rỗng', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: () => ({ status: 200, body: cd() }) });

    await choVeXong();

    assert.equal(tai.querySelector('[data-nd-khoi-ds]').hidden, true);
});

/**
 * Tải lên đi bằng `FormData` và **không** tự đặt `Content-Type`.
 *
 * Đặt tay `multipart/form-data` là lỗi kinh điển: trình duyệt mới sinh được
 * `boundary`, nên một header viết tay làm máy chủ không tách được phần nào ra
 * phần nào và tệp về rỗng.
 */
test('tải lên gửi FormData, không JSON, và không tự đặt Content-Type', async () => {
    let tc = null;

    const { tai, win } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: (url, tuyChon) => {
            if (url === CAU_HINH.api) { return { status: 200, body: cd() }; }

            tc = tuyChon;

            return { status: 201, body: { data: { creative: { id: 'cr-9' } } } };
        },
    });

    await choVeXong();

    const oTep = tai.querySelector('[data-nd-tep]');
    ganTep(win, oTep);
    tai.querySelector('[data-nd-ten]').value = 'Banner 16:9';

    tai.querySelector('[data-nd-form]').dispatchEvent(
        new win.Event('submit', { bubbles: true, cancelable: true }),
    );

    await choVeXong();

    assert.ok(tc, 'phải gọi đường tải lên');
    assert.equal(tc.method, 'POST');
    assert.ok(tc.body instanceof win.FormData, 'thân phải là FormData');
    assert.equal(tc.headers['Content-Type'], undefined, 'không được đặt tay Content-Type');
    assert.equal(tc.body.get('name'), 'Banner 16:9');
    assert.equal(tc.body.get('file').name, 'banner.png');
});

test('tên trống thì không gửi khoá name lên', async () => {
    let tc = null;

    const { tai, win } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: (url, tuyChon) => {
            if (url === CAU_HINH.api) { return { status: 200, body: cd() }; }
            tc = tuyChon;
            return { status: 201, body: { data: {} } };
        },
    });

    await choVeXong();

    ganTep(win, tai.querySelector('[data-nd-tep]'));

    tai.querySelector('[data-nd-form]').dispatchEvent(
        new win.Event('submit', { bubbles: true, cancelable: true }),
    );

    await choVeXong();

    assert.equal(tc.body.has('name'), false, 'ô trống không được gửi lên');
});

test('chưa chọn tệp: hiện lỗi cạnh ô tệp và KHÔNG gọi máy chủ', async () => {
    const { tai, win, daGoi } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 200, body: cd() }),
    });

    await choVeXong();

    const truoc = daGoi.length;

    tai.querySelector('[data-nd-form]').dispatchEvent(
        new win.Event('submit', { bubbles: true, cancelable: true }),
    );

    await choVeXong();

    const loi = tai.querySelector('[data-nd-loi-file]');

    assert.equal(loi.hidden, false);
    assert.match(loi.textContent, /Chọn một tệp/);
    assert.equal(daGoi.length, truoc, 'không được gọi máy chủ khi chưa có tệp');
});

test('lỗi validate 422 về đúng ô, và bật lại nút tải', async () => {
    const { tai, win } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: (url) =>
            url === CAU_HINH.api
                ? { status: 200, body: cd() }
                : {
                    status: 422,
                    body: {
                        error: 'validation_failed',
                        code: 422,
                        details: [
                            { field: 'file', message: 'Tệp vượt quá 50MB.' },
                            { field: 'khong_co_o_nao', message: 'Chiến dịch đã gửi đi.' },
                        ],
                    },
                },
    });

    await choVeXong();

    ganTep(win, tai.querySelector('[data-nd-tep]'));

    tai.querySelector('[data-nd-form]').dispatchEvent(
        new win.Event('submit', { bubbles: true, cancelable: true }),
    );

    await choVeXong();

    assert.match(tai.querySelector('[data-nd-loi-file]').textContent, /50MB/);
    assert.equal(tai.querySelector('[data-nd-loi-name]').hidden, true, 'ô không lỗi phải im');

    // Lỗi không thuộc ô nào không được rơi mất: nó lên khối lỗi chung.
    const chung = tai.querySelector('[data-nd-loi]');
    assert.equal(chung.hidden, false);
    assert.match(chung.textContent, /đã gửi đi/);

    assert.equal(tai.querySelector('[data-nd-tai]').disabled, false, 'nút phải bật lại sau lỗi');
});

test('tải xong thì nạp lại danh sách và xoá ô đã gõ', async () => {
    let lanNap = 0;

    const { tai, win } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: (url) => {
            if (url === CAU_HINH.api) {
                lanNap += 1;

                return {
                    status: 200,
                    body: cd({ creatives: lanNap === 1 ? [] : [BA_TRANG_THAI[0]] }),
                };
            }

            return { status: 201, body: { data: {} } };
        },
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-nd-khoi-ds]').hidden, true);

    ganTep(win, tai.querySelector('[data-nd-tep]'));
    const oTen = tai.querySelector('[data-nd-ten]');
    oTen.value = 'Banner 16:9';

    tai.querySelector('[data-nd-form]').dispatchEvent(
        new win.Event('submit', { bubbles: true, cancelable: true }),
    );

    await choVeXong();

    assert.equal(lanNap, 2, 'phải nạp lại danh sách sau khi tải lên');
    assert.equal(tai.querySelector('[data-nd-khoi-ds]').hidden, false);
    assert.match(tai.querySelector('[data-nd-ds-tieude]').textContent, /\(1\)/);
    assert.equal(oTen.value, '', 'ô tên phải được xoá sau khi tải xong');
    assert.equal(tai.querySelector('[data-nd-thanhcong]').hidden, false);
});

test('401 vẽ lối Đăng nhập lại, không nạp lại trang', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 401, body: { error: 'unauthorized', code: 401 } }),
    });

    await choVeXong();

    const a = tai.querySelector('[data-nd-loi] a');

    assert.ok(a, 'phải có thẻ a để bấm');
    assert.equal(a.getAttribute('href'), CAU_HINH.urlDangNhap);
    assert.deepEqual(loiJsdom.filter((m) => /navigation/i.test(m)), []);
});
