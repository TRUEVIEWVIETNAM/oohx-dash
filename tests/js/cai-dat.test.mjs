import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dungTrang, choVeXong, mocScriptCanTrang, mocDuocKhai } from './bo-khung.mjs';

const TRANG = 'resources/views/buyer/dashboard/settings.blade.php';

const CAU_HINH = {
    apiCaiDat: 'https://oohx.net/api/v2/me/settings',
    apiHoSo: 'https://oohx.net/api/v2/me/profile',
    apiMatKhau: 'https://oohx.net/api/v2/me/password',
    apiToChuc: 'https://oohx.net/api/v2/me/organization',
    urlDangNhap: 'https://oohx.net/login',
};

function caiDat(ghiDe = {}) {
    return {
        data: {
            user: { name: 'Nguyễn Anh Tuấn', email: 'tuan@example.com' },
            organization: {
                name: 'Công ty ABC',
                billing_email: 'ketoan@abc.vn',
                billing_phone: '0240000000',
                tax_id: '0100000001',
                website: 'https://abc.vn',
            },
            permissions: { can_update_organization: true },
            ...ghiDe,
        },
    };
}

// ── Hợp đồng móc DOM ────────────────────────────────────────────────────────

test('tập móc script cần trang cung cấp trùng khít tập móc được khai', () => {
    assert.deepEqual(mocScriptCanTrang(TRANG), mocDuocKhai(TRANG));
});

// ── Ba lý do tôi từng từ chối chuyển trang này, canh từng lý do ─────────────

/**
 * Lý do 1 và 2: "ô nhập trống trong một nhịp, và người dùng gõ được trong nhịp
 * đó".
 *
 * Canh bằng cách KHÔNG cho phản hồi về: giữ `fetch` treo, rồi đọc DOM. Đây là
 * trạng thái người dùng thấy trong nhịp đầu tiên, và nó phải là khung chờ với
 * mọi ô bị `disabled` — không phải một biểu mẫu trống trông như đã tải xong.
 */
test('trước khi dữ liệu về: khung chờ hiện, thân ẩn, mọi ô disabled', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => new Promise(() => {}), // treo mãi
    });

    assert.equal(tai.querySelector('[data-td-cho]').hidden, false, 'khung chờ phải hiện');
    assert.equal(tai.querySelector('[data-td-than]').hidden, true, 'thân phải ẩn');

    for (const m of ['data-td-hoso-name', 'data-td-hoso-email', 'data-td-tc-tax', 'data-td-mk-moi']) {
        assert.equal(tai.querySelector(`[${m}]`).disabled, true, `${m} phải disabled`);
    }

    for (const m of ['data-td-luu-hoso', 'data-td-luu-matkhau', 'data-td-luu-tochuc']) {
        assert.equal(tai.querySelector(`[${m}]`).disabled, true, `${m} phải disabled`);
    }
});

test('sau khi dữ liệu về: điền đúng giá trị, bỏ disabled, ẩn khung chờ', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 200, body: caiDat() }),
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-td-cho]').hidden, true);
    assert.equal(tai.querySelector('[data-td-than]').hidden, false);

    assert.equal(tai.querySelector('[data-td-hoso-name]').value, 'Nguyễn Anh Tuấn');
    assert.equal(tai.querySelector('[data-td-hoso-email]').value, 'tuan@example.com');
    assert.equal(tai.querySelector('[data-td-tc-name]').value, 'Công ty ABC');
    assert.equal(tai.querySelector('[data-td-tc-tax]').value, '0100000001');
    assert.equal(tai.querySelector('[data-td-tc-web]').value, 'https://abc.vn');

    assert.equal(tai.querySelector('[data-td-hoso-name]').disabled, false);
    assert.equal(tai.querySelector('[data-td-tc-tax]').disabled, false);
    assert.equal(tai.querySelector('[data-td-luu-tochuc]').disabled, false);
});

/**
 * Trường `null` từ API không được thành chuỗi "null" trong ô nhập.
 *
 * `billing_phone` và `website` là `nullable` trong đặc tả. `el.value = null`
 * trong jsdom cho ra chuỗi rỗng, nhưng `el.value = String(null)` cho ra
 * "null" — và một ô nhập chứa chữ "null" là thứ người dùng sẽ lưu lại.
 */
test('trường null thành ô trống, không thành chữ "null"', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({
            status: 200,
            body: caiDat({
                organization: {
                    name: 'Công ty ABC',
                    billing_email: null,
                    billing_phone: null,
                    tax_id: null,
                    website: null,
                },
            }),
        }),
    });

    await choVeXong();

    for (const m of ['data-td-tc-email', 'data-td-tc-phone', 'data-td-tc-tax', 'data-td-tc-web']) {
        assert.equal(tai.querySelector(`[${m}]`).value, '', `${m} phải trống`);
    }
});

/**
 * Vai trò chỉ-xem: biểu mẫu tổ chức vẫn `disabled`, và có câu giải thích.
 *
 * Bản Blade cũ **luôn** render biểu mẫu sửa được. Người có vai trò "chỉ xem"
 * điền xong, bấm Lưu, và nhận 403 — policy chặn đúng, nhưng giao diện đã mời
 * họ làm một việc không được phép.
 */
test('can_update_organization=false: ô tổ chức vẫn disabled, hiện câu chỉ-xem', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({
            status: 200,
            body: caiDat({ permissions: { can_update_organization: false } }),
        }),
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-td-chixem]').hidden, false, 'phải hiện câu chỉ-xem');
    assert.equal(tai.querySelector('[data-td-tc-tax]').disabled, true, 'ô mã số thuế phải disabled');
    assert.equal(tai.querySelector('[data-td-luu-tochuc]').disabled, true, 'nút Lưu phải disabled');

    // Hồ sơ cá nhân thì VẪN sửa được: tên và email của chính mình không phải
    // dữ liệu của tổ chức. Lưới chống việc siết quá tay.
    assert.equal(tai.querySelector('[data-td-hoso-name]').disabled, false);
    assert.equal(tai.querySelector('[data-td-luu-hoso]').disabled, false);
});

/**
 * Lý do 3: "`old()` giữ lại thứ vừa nhập khi validate thất bại".
 *
 * Đây là ca thay cho `old()`, và nó đòi nhiều hơn `old()` làm được: lỗi hiện
 * **cạnh đúng ô**, và giá trị người dùng vừa gõ **còn nguyên** trong ô đó.
 * `old()` dựng lại cả trang; cách này không làm mất gì.
 */
test('lỗi validate hiện cạnh đúng ô, và giữ nguyên thứ người dùng đã gõ', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: (url, tuyChon) => {
            if (url === CAU_HINH.apiCaiDat) {
                return { status: 200, body: caiDat() };
            }

            assert.equal(tuyChon.method, 'PUT');

            return {
                status: 422,
                body: {
                    error: 'validation_failed',
                    message: 'Dữ liệu gửi lên không hợp lệ.',
                    code: 422,
                    details: [{ field: 'email', message: 'Email này đã có người dùng.' }],
                },
            };
        },
    });

    await choVeXong();

    const oEmail = tai.querySelector('[data-td-hoso-email]');
    oEmail.value = 'trung@example.com';

    tai.querySelector('[data-td-form-hoso]').dispatchEvent(
        new tai.defaultView.Event('submit', { bubbles: true, cancelable: true }),
    );

    await choVeXong();

    const oLoiEmail = tai.querySelector('[data-td-loi-email]');
    assert.equal(oLoiEmail.hidden, false, 'ô lỗi của email phải hiện');
    assert.match(oLoiEmail.textContent, /đã có người dùng/);

    assert.equal(oEmail.value, 'trung@example.com', 'thứ người dùng vừa gõ phải còn nguyên');

    // Lỗi của ô `email` KHÔNG được hiện dưới ô `name` — hai ô lỗi riêng tồn tại
    // vì `name` có ở cả hai biểu mẫu.
    assert.equal(tai.querySelector('[data-td-loi-name]').hidden, true);

    // Và ô nhập phải bật lại, không bị kẹt `disabled` sau một lần lỗi.
    assert.equal(oEmail.disabled, false, 'ô phải bật lại sau khi lỗi');
    assert.equal(tai.querySelector('[data-td-luu-hoso]').disabled, false);
});

test('lưu thành công thì hiện thông báo và xoá ô mật khẩu', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: (url) =>
            url === CAU_HINH.apiCaiDat
                ? { status: 200, body: caiDat() }
                : { status: 200, body: { data: { message: 'Mật khẩu đã được đổi' } } },
    });

    await choVeXong();

    tai.querySelector('[data-td-mk-hientai]').value = 'cumatkhau';
    tai.querySelector('[data-td-mk-moi]').value = 'matkhaumoi123';
    tai.querySelector('[data-td-mk-xacnhan]').value = 'matkhaumoi123';

    tai.querySelector('[data-td-form-matkhau]').dispatchEvent(
        new tai.defaultView.Event('submit', { bubbles: true, cancelable: true }),
    );

    await choVeXong();

    const oTC = tai.querySelector('[data-td-thanhcong]');
    assert.equal(oTC.hidden, false);
    assert.match(oTC.textContent, /Mật khẩu đã được đổi/);

    // Mật khẩu mới không được nằm lại trong DOM của một trang người dùng có thể
    // bỏ đó mà đi.
    for (const m of ['data-td-mk-hientai', 'data-td-mk-moi', 'data-td-mk-xacnhan']) {
        assert.equal(tai.querySelector(`[${m}]`).value, '', `${m} phải được xoá`);
    }
});

/**
 * 401 cho một lối bấm được, KHÔNG `location.reload()`.
 *
 * Tải lại trang khi phiên web còn mà phiên API thì không là một vòng lặp không
 * có lối ra — một trong ba lỗi thật đã ghi ở `tests/js/README.md`.
 */
test('401 vẽ lối Đăng nhập lại, không nạp lại trang', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 401, body: { error: 'unauthenticated', code: 401 } }),
    });

    await choVeXong();

    const a = tai.querySelector('[data-td-loi] a');
    assert.ok(a, 'phải có thẻ a để bấm');
    assert.equal(a.getAttribute('href'), CAU_HINH.urlDangNhap);
    assert.equal(tai.querySelector('[data-td-cho]').hidden, true, 'khung chờ phải tắt');

    assert.deepEqual(
        loiJsdom.filter((m) => /navigation/i.test(m)),
        [],
        'không được cố nạp lại trang',
    );
});
