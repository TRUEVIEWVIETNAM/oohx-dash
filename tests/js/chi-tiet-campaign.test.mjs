// Múi giờ ÂM (UTC−10), đặt trước mọi import. Hai lý do, như hai tệp kia:
// kỳ chạy chiến dịch là ngày theo lịch nên không được đi qua múi giờ nào, và
// `gio()` ghim `Asia/Ho_Chi_Minh` nên chỉ chạy ở một múi giờ khác hẳn mới
// chứng minh được cái ghim đó có tác dụng.
process.env.TZ = 'Pacific/Honolulu';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dungTrang, choVeXong, chuCua, mocScriptCanTrang, mocDuocKhai } from './bo-khung.mjs';

const TRANG = 'resources/views/buyer/dashboard/campaign-detail.blade.php';
const API = 'https://oohx.net/api/v2/campaigns/cpn-1';

const CAU_HINH = {
    api: API,
    duongHuy: 'https://oohx.net/my/campaigns/cpn-1/lines',
    duongDanhGia: 'https://oohx.net/my/campaigns/cpn-1/reviews',
    duongBaoCao: 'https://oohx.net/my/campaigns/cpn-1/report',
    duongThanhToan: 'https://oohx.net/booking/cpn-1/payment',
    duongTatCa: 'https://oohx.net/my/campaigns',
    dangNhap: 'https://oohx.net/login',
    csrf: 'csrf-gia-123',
};

// ── Hợp đồng móc DOM ────────────────────────────────────────────────────────

test('tập móc script cần trang cung cấp trùng khít tập móc được khai', () => {
    assert.deepEqual(
        mocScriptCanTrang(TRANG),
        mocDuocKhai(TRANG),
        'Script và tests/js/moc-dom.json lệch nhau. Móc script TỰ TẠO phải khai ở '
        + '`tuTao`, không ở `moc` — phía PHP đòi `moc` có mặt trong HTML trang thật.',
    );
});

// ── Dữ liệu mẫu ─────────────────────────────────────────────────────────────

function dong(ghiDe = {}) {
    return {
        id: 'line-a',
        screen: {
            slug: 'man-hinh-a',
            name: 'Màn hình A',
            photo_url: 'https://cdn.oohx.net/a.jpg',
            owner: { slug: 'kim-ngan', name: 'Kim Ngân ADV' },
            location: { city: 'Hà Nội' },
        },
        period: { start_date: '2026-10-08', end_date: '2026-11-01' },
        delivery: { pricing_model: 'io' },
        estimate: { currency: 'VND', cost: 10_000_000, impressions: 500_000 },
        status: 'approved',
        status_label: 'Đã duyệt',
        rejected_reason: null,
        ...ghiDe,
    };
}

function baoGia(ghiDe = {}) {
    return {
        booking_line_id: 'line-a',
        currency: 'VND',
        days_before: 20,
        refund_pct: 100,
        paid: 10_800_000,
        refundable: 10_800_000,
        tier: { min_days_before: 14, refund_pct: 100 },
        is_estimate: true,
        ...ghiDe,
    };
}

function duLieu(ghiDe = {}) {
    return {
        campaign: {
            id: 'cpn-1',
            code: 'CPN-ABC',
            name: 'Chiến dịch Tết',
            status: 'approved',
            status_label: 'Đã duyệt',
            period: { start_date: '2026-10-08', end_date: '2026-11-01' },
        },
        lines: [dong()],
        creatives: [],
        conflicts: [],
        summary: { currency: 'VND', line_count: 1, subtotal: 10_000_000, impressions: 500_000, can_submit: false },

        stats: {
            currency: 'VND',
            line_count: 1,
            estimated_cost: 10_000_000,
            actual_impressions: 123_456,
            delivery_rate_pct: 24.7,
        },
        cancel_quotes: [baoGia()],
        refund_policy: [
            { min_days_before: 14, refund_pct: 100 },
            { min_days_before: 7, refund_pct: 50 },
            { min_days_before: 0, refund_pct: 0 },
        ],
        reviewable_owners: [],
        my_reviews: [],
        activities: [],
        activity_count: 0,

        ...ghiDe,
    };
}

function traLoi(data, status = 200) {
    return () => ({ status, body: status >= 400
        ? { error: 'x', message: data, code: status, details: [] }
        : { data } });
}

// ── Gọi đúng đường ──────────────────────────────────────────────────────────

test('gọi đúng MỘT đường v2, không đường nào khác', async () => {
    const { daGoi } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi(duLieu()) });

    await choVeXong();

    assert.deepEqual(daGoi, [API]);
});

// ── Số liệu đầu trang ───────────────────────────────────────────────────────

/**
 * Bốn con số đọc thẳng từ `stats` của máy chủ.
 *
 * `estimated_cost` trong phản hồi dưới đây **không** bằng tổng `estimate.cost`
 * của các dòng (9.999.999 so với 10.000.000). Nếu trang tự cộng lại từ `lines`
 * thì nó hiện 10.000.000 và test đỏ. Máy chủ cộng từ accessor của model, và đó
 * là con số duy nhất được phép hiện.
 */
test('bốn con số đầu trang đọc thẳng từ stats, không cộng lại từ lines', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            stats: {
                currency: 'VND', line_count: 7,
                estimated_cost: 9_999_999, actual_impressions: 123_456, delivery_rate_pct: 24.7,
            },
        })),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-cd-than');

    assert.match(chu, /9\.999\.999/);
    assert.doesNotMatch(chu, /10\.000\.000Chi phí/, 'không được tự cộng lại từ lines');
    assert.match(chu, /123\.456/);
    assert.match(chu, /24\.7%/);
    assert.match(chu, /7Màn hình/);
});

// ── Đầu trang: trạng thái và nút ────────────────────────────────────────────

test('thẻ trạng thái và kỳ chạy hiện đúng, ngày không lùi', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi(duLieu()) });

    await choVeXong();

    const chu = chuCua(tai, 'data-cd-trangthai');

    assert.match(chu, /Đã duyệt/);
    assert.match(chu, /08\/10\/2026/);
    assert.match(chu, /01\/11\/2026/);
    assert.doesNotMatch(chu, /07\/10\/2026/, 'ngày theo lịch không được đi qua múi giờ');
});

/**
 * Nút nào hiện do TRẠNG THÁI máy chủ trả về quyết.
 *
 * Ẩn nút không phải phân quyền — việc chặn thật ở controller đích — nhưng một
 * nút "Thanh toán" hiện trên chiến dịch nháp là dẫn người dùng tới một trang
 * 403, và đó là lỗi giao diện có thật.
 */
test('nút thanh toán chỉ hiện khi campaign đã duyệt', async () => {
    for (const [trangThai, coNut] of [['approved', true], ['draft', false], ['completed', false]]) {
        const { tai } = dungTrang(TRANG, {
            cauHinh: CAU_HINH,
            traLoi: traLoi(duLieu({
                campaign: { id: 'cpn-1', code: 'C', name: 'N', status: trangThai, period: {} },
            })),
        });

        await choVeXong();

        const co = chuCua(tai, 'data-cd-nut').includes('Thanh toán');

        assert.equal(co, coNut, `trạng thái ${trangThai}: nút thanh toán ${coNut ? 'phải' : 'không được'} hiện`);
    }
});

test('nút báo cáo chỉ hiện khi campaign đã chạy', async () => {
    for (const [trangThai, coNut] of [['active', true], ['completed', true], ['paused', true], ['draft', false], ['approved', false]]) {
        const { tai } = dungTrang(TRANG, {
            cauHinh: CAU_HINH,
            traLoi: traLoi(duLieu({
                campaign: { id: 'cpn-1', code: 'C', name: 'N', status: trangThai, period: {} },
            })),
        });

        await choVeXong();

        const co = chuCua(tai, 'data-cd-nut').includes('Xem báo cáo');

        assert.equal(co, coNut, `trạng thái ${trangThai}: nút báo cáo ${coNut ? 'phải' : 'không được'} hiện`);
    }
});

// ── Dòng đặt chỗ ────────────────────────────────────────────────────────────

/**
 * Nhãn trạng thái của **cả hai** enum đến từ máy chủ.
 *
 * Trang này từng dùng MỘT bảng chữ ở client cho cả chiến dịch và dòng đặt chỗ.
 * Hai enum đó khác nhau ở đúng một mã — chiến dịch dùng `pending_approval`,
 * dòng dùng `pending` — nên một dòng `pending` rơi ra ngoài bảng và hiện ra
 * `pending` nguyên văn tiếng Anh ngay cạnh các dòng đã có chữ Việt. Lỗi này có
 * từ bản Blade cũ và đi theo sang bản JS.
 *
 * Nhãn dưới đây **cố ý khác** mọi nhãn thật, nên một bản tự dịch sẽ đỏ.
 */
test('nhãn trạng thái của chiến dịch và của dòng đều đến từ máy chủ', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            campaign: {
                id: 'cpn-1', code: 'C', name: 'N',
                status: 'approved', status_label: 'NHÃN CHIẾN DỊCH',
                period: { start_date: '2026-10-08', end_date: '2026-11-01' },
            },
            lines: [dong({ status: 'pending', status_label: 'NHÃN DÒNG' })],
            cancel_quotes: [],
        })),
    });

    await choVeXong();

    assert.match(chuCua(tai, 'data-cd-trangthai'), /NHÃN CHIẾN DỊCH/);
    assert.match(chuCua(tai, 'data-cd-than'), /NHÃN DÒNG/);

    // Và `pending` không được lọt ra dưới dạng mã.
    assert.doesNotMatch(chuCua(tai, 'data-cd-than'), /\bpending\b/);

    // Màu vẫn do client chọn — đó là việc trình bày.
    const theDong = tai.querySelector('[data-cd-than] .badge');
    assert.ok(theDong.classList.contains('b-org'), 'dòng chờ duyệt mang màu "đang chờ"');
});

test('dòng đặt chỗ hiện tên màn hình, owner, kỳ ngắn và chi phí', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi(duLieu()) });

    await choVeXong();

    const chu = chuCua(tai, 'data-cd-than');

    assert.match(chu, /Màn hình A/);
    assert.match(chu, /Kim Ngân ADV/);
    assert.match(chu, /08\/10 → 01\/11/, 'dòng dùng kỳ ngắn, không có năm');
    assert.match(chu, /10\.000\.000 ₫/);
});

// ── Hủy đặt chỗ ─────────────────────────────────────────────────────────────

/**
 * Không có báo giá nghĩa là **một trong hai**: dòng không hủy được, hoặc người
 * xem thiếu quyền `manage_payments`. Cả hai dẫn tới cùng một việc — không vẽ
 * nút — nên client không được tự suy ra điều kiện nào trong hai điều kiện đó.
 *
 * Test này canh nửa quan trọng: **mảng rỗng thì không có nút nào**. Vẽ nút cho
 * một người không có quyền là dẫn họ tới một hành động sẽ bị từ chối, trên một
 * đường có hệ quả tiền.
 */
test('không có báo giá thì không vẽ nút hủy nào', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({ cancel_quotes: [] })),
    });

    await choVeXong();

    assert.equal(tai.querySelectorAll('[data-cd-huy]').length, 0);
    assert.doesNotMatch(chuCua(tai, 'data-cd-than'), /Hủy đặt chỗ/);

    // Và chính sách hủy cũng không hiện: nó chỉ có nghĩa cạnh một nút hủy.
    assert.doesNotMatch(chuCua(tai, 'data-cd-than'), /Chính sách hủy/);
});

test('ghép báo giá với dòng theo booking_line_id, không theo vị trí', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            lines: [
                dong({ id: 'line-a', screen: { name: 'Màn A', owner: {}, location: {} } }),
                dong({ id: 'line-b', screen: { name: 'Màn B', owner: {}, location: {} } }),
            ],
            // Cố ý đảo thứ tự: ghép theo vị trí là cách để con số hoàn của
            // dòng A hiện ra dưới dòng B.
            cancel_quotes: [
                baoGia({ booking_line_id: 'line-b', refundable: 2_222_222, refund_pct: 50 }),
                baoGia({ booking_line_id: 'line-a', refundable: 1_111_111, refund_pct: 100 }),
            ],
        })),
    });

    await choVeXong();

    const hang = [...tai.querySelectorAll('[data-cd-than] .buyer-campaign-row')];

    assert.ok(hang.length >= 2);
    assert.match(hang[0].textContent, /Màn A/);
    assert.match(hang[0].textContent, /1\.111\.111/);
    assert.doesNotMatch(hang[0].textContent, /2\.222\.222/, 'con số của dòng B không được nằm ở dòng A');
    assert.match(hang[1].textContent, /2\.222\.222/);
});

test('form hủy POST về route Blade kèm token, đúng id dòng', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi(duLieu()) });

    await choVeXong();

    const form = tai.querySelector('[data-cd-huy]');

    assert.ok(form);
    assert.equal(form.getAttribute('method'), 'POST');
    assert.equal(
        form.getAttribute('action'),
        'https://oohx.net/my/campaigns/cpn-1/lines/line-a/cancel',
    );
    assert.equal(form.querySelector('[name="_token"]').value, 'csrf-gia-123');
});

test('báo giá 0 đồng nói thẳng là không được hoàn', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            cancel_quotes: [baoGia({ days_before: 3, refund_pct: 0, refundable: 0 })],
        })),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-cd-than');

    assert.match(chu, /Không được hoàn tiền/);
    assert.doesNotMatch(chu, /Hoàn dự kiến/);
});

/**
 * Câu hỏi lại nằm trong một thuộc tính DỮ LIỆU, không trong `onsubmit`.
 *
 * Câu đó mang số tiền và được dựng từ dữ liệu máy chủ. Nhúng nó vào một thuộc
 * tính thực thi được là mở một đường chạy mã từ dữ liệu; ở đây nó chỉ được đọc
 * ra và truyền cho `confirm()`.
 */
test('hỏi lại trước khi hủy, và câu hỏi không nằm trong thuộc tính thực thi được', async () => {
    const { tai, win } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi(duLieu()) });

    await choVeXong();

    const form = tai.querySelector('[data-cd-huy]');

    assert.equal(form.getAttribute('onsubmit'), null, 'không được dùng onsubmit');
    assert.match(form.getAttribute('data-cd-hoi'), /dự kiến 10\.800\.000 ₫/);

    let daHoi = null;
    win.confirm = (q) => { daHoi = q; return false; };

    const e = new win.Event('submit', { cancelable: true });
    form.dispatchEvent(e);

    assert.match(daHoi ?? '', /Hủy đặt chỗ trên màn hình này\?/);
    assert.equal(e.defaultPrevented, true, 'trả lời Không thì phải chặn gửi biểu mẫu');
});

// ── Chính sách hủy ──────────────────────────────────────────────────────────

/**
 * Phần trăm đọc từ phản hồi máy chủ, không chép cứng.
 *
 * Mốc dưới đây **cố ý khác** cấu hình thật (90/40/0 thay vì 100/50/0). Một bản
 * chép cứng sẽ hiện 100% và đỏ.
 */
test('chính sách hủy đọc từ máy chủ, không chép cứng phần trăm', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            refund_policy: [
                { min_days_before: 21, refund_pct: 90 },
                { min_days_before: 10, refund_pct: 40 },
                { min_days_before: 0, refund_pct: 0 },
            ],
        })),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-cd-than');

    assert.match(chu, /từ 21 ngày trở lên hoàn 90%/);
    assert.match(chu, /từ 10 ngày trở lên hoàn 40%/);
    assert.match(chu, /dưới 10 ngày hoàn 0%/);
    assert.match(chu, /media owner hoàn trực tiếp cho bạn/);
});

// ── Đánh giá ────────────────────────────────────────────────────────────────

test('không có gì để đánh giá thì không vẽ khối đánh giá', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi(duLieu()) });

    await choVeXong();

    assert.doesNotMatch(chuCua(tai, 'data-cd-than'), /Đánh giá media owner/);
    assert.equal(tai.querySelectorAll('.rv-form').length, 0);
});

test('owner còn đánh giá được thì có biểu mẫu POST về route Blade', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            reviewable_owners: [{ id: 'own-a', slug: 'kim-ngan', name: 'Kim Ngân ADV', logo_url: null }],
        })),
    });

    await choVeXong();

    const form = tai.querySelector('.rv-form');

    assert.ok(form);
    assert.equal(form.getAttribute('action'), 'https://oohx.net/my/campaigns/cpn-1/reviews');
    assert.equal(form.querySelector('[name="_token"]').value, 'csrf-gia-123');
    assert.equal(form.querySelector('[name="owner_id"]').value, 'own-a');

    // Năm mức sao, và `required` để người dùng không gửi một biểu mẫu trống
    // rồi mới nhận lỗi từ máy chủ.
    const sao = [...form.querySelectorAll('[name="rating"]')];
    assert.equal(sao.length, 5);
    assert.deepEqual(sao.map((s) => s.value), ['5', '4', '3', '2', '1']);
    assert.ok(sao.every((s) => s.required));

    // Nhãn phải trỏ đúng ô của owner NÀY: hai owner trên cùng trang mà trùng
    // `id` của thẻ input thì bấm sao của owner B lại chọn cho owner A.
    assert.equal(form.querySelector('label[for="r-own-a-5"]')?.getAttribute('for'), 'r-own-a-5');
});

test('hai owner cùng trang thì mỗi biểu mẫu có id ô sao riêng', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            reviewable_owners: [
                { id: 'own-a', name: 'A' },
                { id: 'own-b', name: 'B' },
            ],
        })),
    });

    await choVeXong();

    const id = [...tai.querySelectorAll('.rv-form [name="rating"]')].map((e) => e.id);

    assert.equal(id.length, 10);
    assert.equal(new Set(id).size, 10, 'trùng id là bấm sao của owner này lại chọn cho owner kia');
});

/**
 * Nhãn trạng thái lấy từ máy chủ (`status_label`), không dịch lại ở client.
 *
 * Nhãn dưới đây **cố ý khác** mọi nhãn trong `OwnerReview::STATUS_LABELS`. Một
 * bản tự dịch `status` sẽ hiện "Chờ duyệt" và đỏ.
 */
test('đánh giá đã viết hiện sao, nhận xét và nhãn trạng thái của máy chủ', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            my_reviews: [{
                id: 'rv-1',
                owner: { id: 'own-a', name: 'Kim Ngân ADV' },
                rating: 4,
                comment: 'Đúng hẹn, hỗ trợ tốt.',
                status: 'pending',
                status_label: 'NHÃN TỪ MÁY CHỦ',
                published_at: null,
                created_at: '2026-10-08T02:11:14.000000Z',
            }],
        })),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-cd-than');

    assert.match(chu, /Kim Ngân ADV/);
    assert.match(chu, /★★★★☆/, 'bốn sao đầy, một sao rỗng');
    assert.match(chu, /Đúng hẹn, hỗ trợ tốt\./);
    assert.match(chu, /NHÃN TỪ MÁY CHỦ/);
    assert.doesNotMatch(chu, /Chờ duyệt/, 'không được tự dịch status ở client');
});

test('rating ngoài khoảng 1-5 không làm vỡ dãy sao', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            my_reviews: [
                { id: 'a', owner: { name: 'A' }, rating: 9, status: 'published', status_label: 'Đã đăng' },
                { id: 'b', owner: { name: 'B' }, rating: -3, status: 'published', status_label: 'Đã đăng' },
            ],
        })),
    });

    await choVeXong();

    const sao = [...tai.querySelectorAll('[data-cd-than] .rv-stars')].map((e) => e.textContent.trim());

    assert.equal(sao.length, 2);
    for (const s of sao) {
        assert.equal([...s].length, 5, 'luôn đúng năm ký tự sao: ' + JSON.stringify(s));
    }
});

// ── Lịch sử hoạt động ───────────────────────────────────────────────────────

test('lịch sử hiện mô tả, giờ Việt Nam và người làm', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            activities: [{
                id: 'act-1',
                action: 'approved',
                description: 'Campaign được duyệt (1/1 màn hình)',
                actor: { name: 'Người Duyệt' },
                created_at: '2026-10-08T02:11:14.000000Z',
            }],
            activity_count: 1,
        })),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-cd-than');

    assert.match(chu, /Campaign được duyệt \(1\/1 màn hình\)/);
    assert.match(chu, /08\/10\/2026 09:11/, 'giờ Việt Nam, ngày trước giờ');
    assert.doesNotMatch(chu, /02:11/, 'không được hiện giờ UTC');
    assert.doesNotMatch(chu, /16:11/, 'không được theo múi giờ của máy người xem');
    assert.match(chu, /Người Duyệt/);
});

test('hoạt động không có người làm ghi là Hệ thống', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            activities: [{ id: 'a', action: 'cancelled', description: 'Tự động hủy', actor: null, created_at: '2026-10-08T02:11:14Z' }],
            activity_count: 1,
        })),
    });

    await choVeXong();

    assert.match(chuCua(tai, 'data-cd-than'), /Hệ thống/);
});

/**
 * Lịch sử bị cắt thì phải NÓI RA.
 *
 * Máy chủ cắt ở 50 dòng mới nhất và trả `activity_count`. Một lịch sử bị cắt
 * mà không ghi gì là một lịch sử người đọc tưởng là đầy đủ — và đây là trang
 * người ta mở ra để tìm hiểu "chuyện gì đã xảy ra".
 */
test('lịch sử bị cắt thì nói rõ đang hiện bao nhiêu trong tổng bao nhiêu', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            activities: [
                { id: 'a', action: 'x', description: 'Việc 1', actor: null, created_at: '2026-10-08T02:00:00Z' },
                { id: 'b', action: 'x', description: 'Việc 2', actor: null, created_at: '2026-10-08T01:00:00Z' },
            ],
            activity_count: 137,
        })),
    });

    await choVeXong();

    assert.match(chuCua(tai, 'data-cd-than'), /Đang hiện 2 hoạt động mới nhất trong tổng 137/);
});

test('lịch sử không bị cắt thì không ghi thêm gì', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            activities: [{ id: 'a', action: 'x', description: 'Việc 1', actor: null, created_at: '2026-10-08T02:00:00Z' }],
            activity_count: 1,
        })),
    });

    await choVeXong();

    assert.doesNotMatch(chuCua(tai, 'data-cd-than'), /Đang hiện/);
});

// ── Thoát ký tự ─────────────────────────────────────────────────────────────

/**
 * Bốn nguồn dữ liệu không tin được trên trang này: tên màn hình (vào CSDL qua
 * `ScreenImport` từ CSV của media owner), tên owner, nhận xét đánh giá do
 * người dùng tự gõ, và mô tả hoạt động do service ghép từ tên người.
 */
test('mã HTML trong mọi nguồn dữ liệu không thoát ra khỏi thuộc tính', async () => {
    const doc = '"><img src=x onerror=alert(1)><b>đậm</b>';

    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi(duLieu({
            lines: [dong({ screen: { name: doc, photo_url: 'https://cdn.oohx.net/a.jpg', owner: { name: doc }, location: { city: doc } } })],
            reviewable_owners: [{ id: 'own-a', name: doc }],
            my_reviews: [{ id: 'rv-1', owner: { name: doc }, rating: 3, comment: doc, status: 'pending', status_label: doc }],
            activities: [{ id: 'a', action: 'x', description: doc, actor: { name: doc }, created_at: '2026-10-08T02:00:00Z' }],
            activity_count: 1,
        })),
    });

    await choVeXong();

    const khung = tai.querySelector('[data-cd-than]');

    // Đúng một ảnh — ảnh thật của màn hình.
    assert.equal(khung.querySelectorAll('img').length, 1);
    assert.equal(khung.querySelectorAll('b').length, 0);

    // Và vẫn phải HIỆN RA dưới dạng chữ: media owner đặt tên có dấu `<` thì họ
    // phải thấy đúng tên mình, không phải một khoảng trống.
    assert.match(khung.textContent, /<img src=x onerror=alert\(1\)>/);

    assert.equal(khung.querySelector('img').getAttribute('alt'), doc);
});

// ── Lỗi ─────────────────────────────────────────────────────────────────────

test('401 hiện lỗi kèm lối đăng nhập lại, không nạp lại trang', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi('x', 401),
    });

    await choVeXong();

    assert.deepEqual(
        loiJsdom.filter((m) => /navigation|reload/i.test(m)),
        [],
        '401 không được nạp lại trang — phiên web còn mà phiên API thì không là một thế có thật',
    );

    assert.equal(tai.querySelector('[data-cd-loi]').hidden, false);
    assert.match(chuCua(tai, 'data-cd-loi-msg'), /hết/i);

    // Lối "Đăng nhập lại" là **markup của trang**, không do script vẽ, nên bộ
    // khung jsdom dựng từ `moc-dom.json` không có nó và phép kiểm ở đây sẽ
    // luôn thất bại dù trang thật đúng. Nó được canh ở phía PHP, nơi đọc HTML
    // trang thật: `MocDomTrangBladeTest::test_moi_trang_co_loi_dang_nhap_lai`.
    //
    // Ghi ra thay vì bỏ qua im lặng: ranh giới "script vẽ gì / trang có gì" là
    // thứ dễ quên nhất khi thêm test cho một trang mới.

    // Phần thân phải trống: để lại dòng "Đang tải…" bên cạnh một thông báo lỗi
    // là nói hai điều trái nhau cùng lúc.
    assert.equal(chuCua(tai, 'data-cd-than'), '');
});

test('429 nói rõ là vượt hạn mức', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi('x', 429) });

    await choVeXong();

    assert.match(chuCua(tai, 'data-cd-loi-msg'), /quá nhanh|một phút/i);
});

test('lỗi khác hiện thông điệp của máy chủ', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi('Campaign này đã bị xoá.', 404),
    });

    await choVeXong();

    assert.equal(chuCua(tai, 'data-cd-loi-msg'), 'Campaign này đã bị xoá.');
});

test('mạng hỏng không để trang treo ở "Đang tải…"', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => { throw new Error('mất mạng'); },
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-cd-loi]').hidden, false);
    assert.notEqual(chuCua(tai, 'data-cd-loi-msg'), '');
    assert.doesNotMatch(chuCua(tai, 'data-cd-than'), /Đang tải/);
});
