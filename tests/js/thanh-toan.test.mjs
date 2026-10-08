// Múi giờ ÂM (UTC−10), đặt trước mọi thứ khác. Hai lý do:
//
//  - kỳ chạy chiến dịch là ngày theo lịch, không được đi qua múi giờ nào;
//  - `gio()` ghim `Asia/Ho_Chi_Minh`, nên chạy test ở một múi giờ khác hẳn là
//    cách duy nhất chứng minh cái ghim đó có tác dụng. Chạy ở UTC thì một bản
//    bỏ ghim vẫn xanh.
process.env.TZ = 'Pacific/Honolulu';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dungTrang, choVeXong, chuCua, mocScriptDiTim, mocDuocKhai } from './bo-khung.mjs';

const TRANG = 'resources/views/buyer/booking/payment.blade.php';

// ── Hợp đồng móc DOM ────────────────────────────────────────────────────────

test('tập móc script đi tìm trùng khít tập móc được khai', () => {
    assert.deepEqual(
        mocScriptDiTim(TRANG),
        mocDuocKhai(TRANG),
        'Script và tests/js/moc-dom.json lệch nhau — xem chú thích của '
        + '`mocScriptDiTim` về lý do phép kiểm này không thay được bằng '
        + 'việc chạy script và xem nó có vỡ hay không.',
    );
});

const API_TIEN = 'https://oohx.net/api/v2/campaigns/cpn-1/payments';
const API_NHAN = 'https://oohx.net/api/v2/campaigns/cpn-1/payment-recipients';

const CAU_HINH = {
    apiTien: API_TIEN,
    apiNhanTien: API_NHAN,
    duongGhi: 'https://oohx.net/booking/cpn-1/payment',
    duongCampaign: 'https://oohx.net/my/campaigns/cpn-1',
    quyChe: 'https://oohx.net/quy-che-hoat-dong',
    dangNhap: 'https://oohx.net/login',
    csrf: 'csrf-gia-123',
    hotline: '0943668996',
    vatNhan: '8',
};

function congNo(ghiDe = {}) {
    return {
        owner: { id: 'own-a', name: 'Kim Ngân ADV' },
        cost: 10_000_000,
        vat: 800_000,
        total: 10_800_000,
        paid: 0,
        refunded: 0,
        pending: 0,
        remaining: 10_800_000,
        is_paid: false,
        ...ghiDe,
    };
}

function nguoiNhan(ghiDe = {}) {
    return {
        id: 'own-a',
        slug: 'kim-ngan-adv',
        name: 'Kim Ngân ADV',
        legal_name: 'CÔNG TY TNHH KIM NGÂN',
        tax_code: '0101234567',
        has_bank_details: true,
        bank_name: 'Vietcombank (VCB)',
        bank_account_number: '0011001234567',
        bank_account_name: 'CONG TY TNHH KIM NGAN',
        bank_branch: 'Chi nhánh Hà Nội',
        ...ghiDe,
    };
}

function tongKet(ghiDe = {}) {
    return {
        currency: 'VND',
        total_cost: 10_000_000,
        vat: 800_000,
        total_cost_vat: 10_800_000,
        total_paid: 0,
        refunded: 0,
        pending: 0,
        remaining: 10_800_000,
        is_fully_paid: false,
        ...ghiDe,
    };
}

/**
 * Hai đường, hai phản hồi.
 *
 * `nhan` nhận `{ status }` để mô phỏng 403 — trường hợp vai trò `viewer`, thứ
 * phải vẽ đúng chứ không phải một trang trắng.
 */
function traLoiHaiDuong({ tien, nhan }) {
    return (url) => {
        if (url === API_TIEN) {
            return { status: tien.status ?? 200, body: { data: tien.data } };
        }
        if (url === API_NHAN) {
            return { status: nhan.status ?? 200, body: nhan.status >= 400
                ? { error: 'forbidden', message: nhan.message ?? 'không có quyền', code: nhan.status, details: [] }
                : { data: nhan.data } };
        }
        throw new Error('gọi sai đường: ' + url);
    };
}

function duLieuDay(ghiDe = {}) {
    return traLoiHaiDuong({
        tien: {
            data: {
                campaign: {
                    id: 'cpn-1', code: 'CPN-ABC', status: 'approved',
                    name: 'Chiến dịch Tết', start_date: '2026-10-08', end_date: '2026-11-01',
                    line_count: 12,
                },
                summary: tongKet(ghiDe.tongKet),
                by_owner: ghiDe.by_owner ?? [congNo()],
                payments: ghiDe.payments ?? [],
                can_pay: true,
            },
        },
        nhan: ghiDe.nhan ?? { data: { campaign: { id: 'cpn-1', code: 'CPN-ABC' }, transfer_note: 'CPN-ABC', recipients: [nguoiNhan()] } },
    });
}

// ── Gọi đúng hai đường ──────────────────────────────────────────────────────

test('gọi đúng hai đường v2, không đường nào khác', async () => {
    const { daGoi } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: duLieuDay() });

    await choVeXong();

    assert.deepEqual([...daGoi].sort(), [API_TIEN, API_NHAN].sort());
    assert.equal(daGoi.length, 2, 'mỗi đường gọi đúng một lần');
});

// ── Tiền ────────────────────────────────────────────────────────────────────

/**
 * Số tiền đọc thẳng từ API.
 *
 * `vat` dưới đây **cố ý không** bằng `total_cost × 8%` (800.000). Nếu trang tự
 * nhân thì nó hiện 800.000; nếu nó đọc từ API thì hiện 800.001.
 */
test('tổng kết đọc thẳng từ API, không nhân lại theo nhãn VAT', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: duLieuDay({
            tongKet: { vat: 800_001, total_cost_vat: 10_800_001, remaining: 10_800_001 },
        }),
    });

    await choVeXong();

    // Đọc RIÊNG khối tổng kết, không cả trang: khối owner bên dưới có con số
    // VAT riêng của nó (`by_owner[].vat`), và một phép kiểm trên cả trang sẽ
    // bắt nhầm con số đó rồi báo lỗi ở chỗ không sai.
    const tong = tai.querySelector('[data-pay-main] .pay-rows');

    assert.ok(tong, 'phải có khối tổng kết');

    const chu = tong.textContent;

    assert.match(chu, /800\.001 ₫/);
    assert.match(chu, /10\.800\.001 ₫/);
    assert.doesNotMatch(chu, /800\.000 ₫/, 'trang không được tự nhân lại VAT từ nhãn 8%');
    assert.doesNotMatch(chu, /10\.800\.000 ₫/);
});

test('công nợ từng owner đọc thẳng từ API', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: duLieuDay({
            by_owner: [congNo({ cost: 7_000_000, vat: 560_003, total: 7_560_003, remaining: 7_560_003 })],
        }),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-pay-main');

    assert.match(chu, /7\.000\.000 ₫/);
    assert.match(chu, /560\.003 ₫/);
    assert.match(chu, /7\.560\.003 ₫/);
});

// ── Ghép hai phản hồi ───────────────────────────────────────────────────────

test('ghép công nợ với nơi nhận tiền theo id của owner', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: duLieuDay({
            by_owner: [
                congNo({ owner: { id: 'own-a', name: 'A' }, cost: 1_000_000, total: 1_080_000, remaining: 1_080_000 }),
                congNo({ owner: { id: 'own-b', name: 'B' }, cost: 2_000_000, total: 2_160_000, remaining: 2_160_000 }),
            ],
            nhan: {
                data: {
                    campaign: { id: 'cpn-1', code: 'CPN-ABC' },
                    transfer_note: 'CPN-ABC',
                    // Cố ý đảo thứ tự so với `by_owner`: ghép phải theo `id`,
                    // không theo vị trí trong mảng. Ghép theo vị trí là cách
                    // để tiền của owner A hiện ra cạnh tài khoản của owner B.
                    recipients: [
                        nguoiNhan({ id: 'own-b', name: 'B', legal_name: 'CT B', bank_account_number: '2222' }),
                        nguoiNhan({ id: 'own-a', name: 'A', legal_name: 'CT A', bank_account_number: '1111' }),
                    ],
                },
            },
        }),
    });

    await choVeXong();

    const khoi = [...tai.querySelectorAll('[data-pay-main] .pay-owner')];

    assert.equal(khoi.length, 2);

    assert.match(khoi[0].textContent, /CT A/);
    assert.match(khoi[0].textContent, /1111/);
    assert.doesNotMatch(khoi[0].textContent, /2222/, 'tài khoản của B không được nằm trong khối của A');

    assert.match(khoi[1].textContent, /CT B/);
    assert.match(khoi[1].textContent, /2222/);
});

/**
 * Nợ mà không tra ra nơi nhận tiền thì phải NÓI RA.
 *
 * Bỏ im lặng khối đó đi là cách để một khoản nợ không bao giờ được trả: người
 * mua không thấy nó, nên không biết mình còn thiếu ai.
 */
test('owner có nợ mà thiếu nơi nhận tiền vẫn hiện khối, không bị bỏ im lặng', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: duLieuDay({
            by_owner: [congNo({ owner: { id: 'own-x', name: 'Owner Thiếu Hồ Sơ' } })],
            nhan: {
                data: {
                    campaign: { id: 'cpn-1', code: 'CPN-ABC' },
                    transfer_note: 'CPN-ABC',
                    recipients: [],
                },
            },
        }),
    });

    await choVeXong();

    const khoi = [...tai.querySelectorAll('[data-pay-main] .pay-owner')];

    assert.equal(khoi.length, 1, 'khối owner phải còn đó');
    assert.match(khoi[0].textContent, /Owner Thiếu Hồ Sơ/);
    assert.match(khoi[0].textContent, /10\.800\.000 ₫/, 'số tiền vẫn phải hiện');
    assert.match(khoi[0].textContent, /chưa cung cấp thông tin tài khoản/i);
    assert.equal(khoi[0].querySelectorAll('form').length, 0, 'không có chỗ chuyển thì không vẽ nút trả');
});

test('owner chưa khai đủ tài khoản thì nói thẳng, không vẽ nút trả', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: duLieuDay({
            nhan: {
                data: {
                    campaign: { id: 'cpn-1', code: 'CPN-ABC' },
                    transfer_note: 'CPN-ABC',
                    recipients: [nguoiNhan({
                        has_bank_details: false,
                        bank_name: null, bank_account_number: null,
                        bank_account_name: null, bank_branch: null,
                    })],
                },
            },
        }),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-pay-main');

    assert.match(chu, /chưa cung cấp thông tin tài khoản/i);
    assert.match(chu, /0943668996/, 'phải có hotline để người mua liên hệ');
    assert.equal(tai.querySelectorAll('[data-pay-main] form').length, 0);
});

// ── Vai trò viewer: 403 ở đường nhận tiền ───────────────────────────────────

/**
 * Đây là trường hợp trang CŨ không có: bản render phía máy chủ chỉ cần quyền
 * `view` nên `viewer` xem được cả số tài khoản. Endpoint mới đòi
 * `manage_payments`, nên trang phải vẽ đúng "thấy nợ, không thấy nơi nhận
 * tiền" — chứ không phải chết trắng.
 */
test('403 ở đường nhận tiền vẫn vẽ công nợ, không lộ số tài khoản, không có form', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: duLieuDay({ nhan: { status: 403 } }),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-pay-main');

    // Vẫn thấy tiền.
    assert.match(chu, /10\.800\.000 ₫/);
    assert.match(chu, /Kim Ngân ADV/);

    // Không thấy nơi nhận tiền.
    assert.doesNotMatch(chu, /0011001234567/);
    assert.doesNotMatch(chu, /Vietcombank/);
    assert.doesNotMatch(chu, /0101234567/, 'MST cũng đi qua đường bị 403');

    // Không có đường bấm để trả.
    assert.equal(tai.querySelectorAll('[data-pay-main] form').length, 0);

    // Và nói rõ thiếu quyền gì, chứ không để người dùng đoán.
    assert.match(chu, /không có quyền/i);
    assert.match(chu, /quản lý thanh toán/i);

    assert.deepEqual(loiJsdom, [], '403 là câu trả lời đúng, không phải một lỗi');
});

// ── Đã trả đủ ───────────────────────────────────────────────────────────────

test('đã trả đủ thì hiện thẻ xác nhận và không còn form nào', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: duLieuDay({
            tongKet: { is_fully_paid: true, total_paid: 10_800_000, remaining: 0 },
        }),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-pay-main');

    assert.match(chu, /Đã thanh toán đủ/);
    assert.equal(tai.querySelectorAll('[data-pay-main] form').length, 0);
    assert.equal(tai.querySelectorAll('[data-pay-main] .pay-owner').length, 0);
});

// ── Form xác nhận ───────────────────────────────────────────────────────────

test('form POST về route Blade kèm token, owner_id và số tiền của máy chủ', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: duLieuDay() });

    await choVeXong();

    const form = tai.querySelector('[data-pay-main] form');

    assert.ok(form, 'owner đã khai đủ tài khoản thì phải có form');
    assert.equal(form.getAttribute('method'), 'POST');
    assert.equal(form.getAttribute('action'), 'https://oohx.net/booking/cpn-1/payment');
    assert.equal(form.querySelector('[name="_token"]').value, 'csrf-gia-123');
    assert.equal(form.querySelector('[name="method"]').value, 'bank_transfer');
    assert.equal(form.querySelector('[name="owner_id"]').value, 'own-a');
    assert.equal(form.querySelector('[name="amount"]').value, '10800000');

    // Ô đồng ý phải `required`: kiểm phía máy chủ đã có (`accept_terms`
    // `accepted`), nhưng để người dùng gửi rồi mới báo lỗi là bắt họ làm lại.
    const o = form.querySelector('[name="accept_terms"]');
    assert.equal(o.type, 'checkbox');
    assert.equal(o.required, true);
});

/**
 * Mỗi lần vẽ một mã chống trùng RIÊNG, và hai owner không dùng chung mã.
 *
 * Mã này là của chính lần gửi đó. Dùng token phiên là lỗi đã xảy ra: token
 * không đổi giữa các lần trả, nên trả một phần rồi quay lại trả nốt sẽ nhận
 * lại đúng khoản đã hoàn tất và không tạo được khoản mới (Codex R07).
 */
test('mỗi form một mã chống trùng khác nhau', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: duLieuDay({
            by_owner: [
                congNo({ owner: { id: 'own-a', name: 'A' } }),
                congNo({ owner: { id: 'own-b', name: 'B' } }),
            ],
            nhan: {
                data: {
                    campaign: { id: 'cpn-1', code: 'CPN-ABC' },
                    transfer_note: 'CPN-ABC',
                    recipients: [nguoiNhan({ id: 'own-a' }), nguoiNhan({ id: 'own-b' })],
                },
            },
        }),
    });

    await choVeXong();

    const ma = [...tai.querySelectorAll('[name="payment_nonce"]')].map((e) => e.value);

    assert.equal(ma.length, 2);
    assert.notEqual(ma[0], ma[1], 'hai owner không được dùng chung mã chống trùng');
    assert.ok(ma[0].length >= 8 && ma[1].length >= 8, 'mã phải đủ dài để không đoán được');
    assert.equal(ma.filter((m) => m === CAU_HINH.csrf).length, 0, 'mã KHÔNG được là token phiên');
});

// ── Nội dung chuyển khoản do máy chủ đưa ra ─────────────────────────────────

test('nội dung chuyển khoản lấy từ máy chủ, không tự ghép ở client', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: duLieuDay({
            nhan: {
                data: {
                    campaign: { id: 'cpn-1', code: 'CPN-ABC' },
                    // Cố ý khác mã campaign: đối soát dựa vào đúng chuỗi máy
                    // chủ đưa ra, nên client không được tự dựng lại từ `code`.
                    transfer_note: 'OOHX-DOI-SOAT-9',
                    recipients: [nguoiNhan()],
                },
            },
        }),
    });

    await choVeXong();

    assert.match(chuCua(tai, 'data-pay-main'), /OOHX-DOI-SOAT-9/);
});

// ── Lịch sử thanh toán: giờ Việt Nam, cố định ───────────────────────────────

/**
 * Máy chạy test đang ở UTC−10 (khai ở đầu tệp). `app.timezone` là `UTC`, nên
 * bản render cũ hiện 02:11 cho mốc dưới đây — lệch 7 tiếng so với lúc người
 * mua thật sự bấm nút. Giờ Việt Nam của mốc đó là 09:11 ngày 08/10.
 *
 * Test này đỏ theo BA cách khác nhau: bỏ ghim múi giờ (ra 16:11 ngày 07 theo
 * máy), để nguyên UTC (02:11), hoặc để `vi-VN` tự chọn thứ tự (giờ trước ngày).
 */
test('thời điểm tạo khoản hiện theo giờ Việt Nam, ngày trước giờ', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: duLieuDay({
            payments: [{
                id: 'pay-1',
                owner: { id: 'own-a', name: 'Kim Ngân ADV' },
                method: 'bank_transfer',
                currency: 'VND',
                amount: 10_800_000,
                status: 'pending',
                transaction_ref: 'TXN-1',
                invoice_number: 'HD-001',
                created_at: '2026-10-08T02:11:14.000000Z',
            }],
        }),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-pay-main');

    assert.match(chu, /08\/10\/2026 09:11/, 'giờ Việt Nam, ngày trước giờ');
    assert.doesNotMatch(chu, /02:11/, 'không được hiện giờ UTC');
    assert.doesNotMatch(chu, /07\/10\/2026/, 'không được theo múi giờ của máy người xem');

    assert.match(chu, /HD-001/);
    assert.match(chu, /Chờ xác nhận/);
});

// ── Thanh bên ───────────────────────────────────────────────────────────────

test('thanh bên hiện số màn hình và kỳ chạy, ngày không lùi', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: duLieuDay() });

    await choVeXong();

    const chu = chuCua(tai, 'data-pay-meta');

    assert.match(chu, /12 màn hình/);
    assert.match(chu, /08\/10\/2026/);
    assert.match(chu, /01\/11\/2026/);
    assert.doesNotMatch(chu, /07\/10\/2026/);
});

// ── Thoát ký tự ─────────────────────────────────────────────────────────────

/**
 * Tên pháp lý và tên chủ tài khoản do media owner tự khai ở khu publisher, nên
 * là dữ liệu không tin được. Chúng đi vào cả thân HTML lẫn nhãn của nút.
 */
test('tên owner chứa mã HTML không thoát ra khỏi thuộc tính', async () => {
    const doc = '"><img src=x onerror=alert(1)><b>đậm</b>';

    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: duLieuDay({
            nhan: {
                data: {
                    campaign: { id: 'cpn-1', code: 'CPN-ABC' },
                    transfer_note: 'CPN-ABC',
                    recipients: [nguoiNhan({
                        legal_name: doc, name: doc,
                        bank_account_name: doc, tax_code: doc,
                    })],
                },
            },
        }),
    });

    await choVeXong();

    const khung = tai.querySelector('[data-pay-main]');

    assert.equal(khung.querySelectorAll('img').length, 0);
    assert.equal(khung.querySelectorAll('b').length, 0);
    assert.match(khung.textContent, /<img src=x onerror=alert\(1\)>/);
});

// ── Lỗi ─────────────────────────────────────────────────────────────────────

test('401 ở đường tiền hiện lỗi kèm lối đăng nhập lại, không nạp lại trang', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 401, body: { error: 'unauthenticated', message: 'x', code: 401, details: [] } }),
    });

    await choVeXong();

    assert.deepEqual(
        loiJsdom.filter((m) => /navigation|reload/i.test(m)),
        [],
        '401 không được nạp lại trang — phiên web còn mà phiên API thì không là một thế có thật',
    );

    const a = tai.querySelector('[data-pay-main] a[href="https://oohx.net/login"]');

    assert.ok(a, 'phải có lối đăng nhập lại');
    assert.match(chuCua(tai, 'data-pay-main'), /hết/i);
});

test('429 nói rõ là vượt hạn mức, không nói chung chung', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => ({ status: 429, body: { error: 'too_many_requests', message: 'x', code: 429, details: [] } }),
    });

    await choVeXong();

    assert.match(chuCua(tai, 'data-pay-main'), /quá nhanh|một phút/i);
});

test('lỗi ở đường tiền hiện thông điệp của máy chủ', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: (url) => (url === API_TIEN
            ? { status: 422, body: { error: 'unprocessable', message: 'Campaign chưa được duyệt.', code: 422, details: [] } }
            : { status: 200, body: { data: { campaign: {}, transfer_note: '', recipients: [] } } }),
    });

    await choVeXong();

    assert.match(chuCua(tai, 'data-pay-main'), /Campaign chưa được duyệt\./);
});
