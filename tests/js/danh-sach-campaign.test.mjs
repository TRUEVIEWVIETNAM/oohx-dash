// Múi giờ ÂM (UTC−10), đặt trước mọi import: kỳ chạy chiến dịch là ngày theo
// lịch nên không được đi qua múi giờ nào, và chạy ở UTC — như máy CI mặc định —
// thì lỗi "lùi một ngày" không bao giờ hiện ra.
process.env.TZ = 'Pacific/Honolulu';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dungTrang, choVeXong, chuCua, mocScriptCanTrang, mocDuocKhai } from './bo-khung.mjs';

const TRANG = 'resources/views/buyer/dashboard/campaigns.blade.php';

const CAU_HINH = {
    api: 'https://oohx.net/api/v2/campaigns',
    explore: 'https://oohx.net/explore',
    xemBase: 'https://oohx.net/my/campaigns',
    dangNhap: 'https://oohx.net/login',
    trangThai: {
        draft: 'Nháp',
        pending_approval: 'Chờ duyệt',
        approved: 'Đã duyệt',
        rejected: 'Từ chối',
        active: 'Đang chạy',
        paused: 'Tạm dừng',
        completed: 'Hoàn thành',
        cancelled: 'Đã hủy',
    },
};

// ── Hợp đồng móc DOM ────────────────────────────────────────────────────────

test('tập móc script cần trang cung cấp trùng khít tập móc được khai', () => {
    assert.deepEqual(mocScriptCanTrang(TRANG), mocDuocKhai(TRANG));
});

// ── Dữ liệu mẫu ─────────────────────────────────────────────────────────────

function cd(ghiDe = {}) {
    return {
        id: 'cpn-1',
        code: 'CPN-ABC',
        name: 'Chiến dịch Tết',
        status: 'approved',
        status_label: 'Đã duyệt',
        period: { start_date: '2026-10-08', end_date: '2026-11-01' },
        totals: { currency: 'VND', budget: null, screens: 12, impressions: 500000 },
        ...ghiDe,
    };
}

function meta(ghiDe = {}) {
    return { page: 1, per_page: 20, total: 1, last_page: 1, max_per_page: 100, ...ghiDe };
}

/** Trả về `{daGoi, ...}`; `tra` nhận URL đã gọi để test khẳng định query. */
function traLoi(ds, m = {}, status = 200, thong = 'x') {
    return () => (status >= 400
        ? { status, body: { error: 'e', message: thong, code: status, details: [] } }
        : { status, body: { data: ds, meta: meta(m) } });
}

function urlCua(daGoi, i = 0) {
    return new URL(daGoi[i]);
}

// ── Gọi đúng đường, đúng query ──────────────────────────────────────────────

test('lần tải đầu gọi đúng endpoint với page=1 và không bộ lọc nào', async () => {
    const { daGoi } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi([cd()]) });

    await choVeXong();

    assert.equal(daGoi.length, 1);

    const u = urlCua(daGoi);

    assert.equal(u.origin + u.pathname, 'https://oohx.net/api/v2/campaigns');
    assert.equal(u.searchParams.get('page'), '1');
    assert.equal(u.searchParams.get('status'), null, 'không gửi bộ lọc rỗng');
    assert.equal(u.searchParams.get('q'), null);
});

test('đọc page, status và q từ URL của trang', async () => {
    const { daGoi } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd()], { page: 3, last_page: 5, total: 93 }),
        url: 'https://oohx.net/my/campaigns?page=3&status=active&q=T%E1%BA%BFt',
    });

    await choVeXong();

    const u = urlCua(daGoi);

    // F5 và link chia sẻ phải giữ đúng chỗ đang đứng — đó là thứ bản phân
    // trang của Laravel vốn có, và chuyển sang đọc API không được làm mất.
    assert.equal(u.searchParams.get('page'), '3');
    assert.equal(u.searchParams.get('status'), 'active');
    assert.equal(u.searchParams.get('q'), 'Tết');
});

// ── Vẽ danh sách ────────────────────────────────────────────────────────────

test('mỗi hàng là một liên kết tới trang chi tiết đúng id', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd({ id: 'cpn-xyz' })]),
    });

    await choVeXong();

    const a = tai.querySelector('[data-ds-than] a.buyer-campaign-row');

    assert.ok(a);
    assert.equal(a.getAttribute('href'), 'https://oohx.net/my/campaigns/cpn-xyz');
});

test('hàng hiện tên, mã, kỳ chạy và số màn hình', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi([cd()]) });

    await choVeXong();

    const chu = chuCua(tai, 'data-ds-than');

    assert.match(chu, /Chiến dịch Tết/);
    assert.match(chu, /CPN-ABC/);
    assert.match(chu, /08\/10\/2026 → 01\/11\/2026/);
    assert.doesNotMatch(chu, /07\/10\/2026/, 'ngày theo lịch không được đi qua múi giờ');
    assert.match(chu, /12 screens/);
});

/**
 * Nhãn trạng thái từ MÁY CHỦ, không dịch lại ở client.
 *
 * Nhãn dưới đây **cố ý khác** mọi nhãn trong `Campaign::STATUS_LABELS`. Một
 * bản tự dịch `status` sẽ hiện "Đã duyệt" và đỏ. Màu thì vẫn do client chọn —
 * đó là việc trình bày.
 */
test('nhãn trạng thái lấy từ máy chủ, màu lấy từ client', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd({ status: 'approved', status_label: 'NHÃN TỪ MÁY CHỦ' })]),
    });

    await choVeXong();

    const the = tai.querySelector('[data-ds-than] .badge');

    assert.equal(the.textContent.trim(), 'NHÃN TỪ MÁY CHỦ');
    assert.ok(the.classList.contains('b-bl'), 'màu của trạng thái approved do client chọn');
    assert.doesNotMatch(chuCua(tai, 'data-ds-than'), /Đã duyệt/, 'không được tự dịch status');
});

// ── Hai loại rỗng ───────────────────────────────────────────────────────────

/**
 * "Chưa có campaign nào" và "không khớp bộ lọc" là HAI câu khác nhau.
 *
 * Nói "chưa có campaign nào" khi đang lọc là nói sai: họ có campaign, chỉ không
 * có cái nào khớp — và câu sai đó dẫn họ đi tạo cái mới thay vì xoá lọc.
 */
test('danh sách rỗng mà không lọc gì thì mời đi khám phá kho', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([], { total: 0, last_page: 1 }),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-ds-than');

    assert.match(chu, /Chưa có campaign nào/);
    assert.ok(tai.querySelector('[data-ds-than] a[href="https://oohx.net/explore"]'));
    assert.equal(tai.querySelector('[data-ds-xoaloc]'), null, 'không lọc gì thì không có gì để xoá');
});

test('danh sách rỗng VÌ bộ lọc thì nói đúng thế và cho nút xóa lọc', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([], { total: 0, last_page: 1 }),
        url: 'https://oohx.net/my/campaigns?status=cancelled',
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-ds-than');

    assert.match(chu, /khớp bộ lọc/i);
    assert.doesNotMatch(chu, /Chưa có campaign nào/);
    assert.ok(tai.querySelector('[data-ds-xoaloc]'));
});

test('bấm xóa lọc gọi lại không kèm bộ lọc và về trang 1', async () => {
    const { tai, win, daGoi } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([], { total: 0, last_page: 1 }),
        url: 'https://oohx.net/my/campaigns?page=4&status=cancelled&q=abc',
    });

    await choVeXong();

    tai.querySelector('[data-ds-xoaloc]').dispatchEvent(new win.Event('click'));
    await choVeXong();

    assert.equal(daGoi.length, 2);

    const u = urlCua(daGoi, 1);

    assert.equal(u.searchParams.get('page'), '1');
    assert.equal(u.searchParams.get('status'), null);
    assert.equal(u.searchParams.get('q'), null);

    // Và ô lọc phải đồng bộ lại: giao diện nói một đằng dữ liệu một nẻo là
    // cách làm người dùng mất tin vào bộ lọc.
    assert.equal(tai.querySelector('[data-ds-trangthai]').value, '');
    assert.equal(tai.querySelector('[data-ds-tim]').value, '');
});

// ── Bộ lọc ──────────────────────────────────────────────────────────────────

test('ô chọn trạng thái dựng từ bảng chữ của máy chủ, không chép cứng', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi([cd()]) });

    await choVeXong();

    const chon = tai.querySelector('[data-ds-trangthai]');
    const giaTri = [...chon.options].map((o) => o.value);

    // Một ô rỗng "Mọi trạng thái" cộng tám trạng thái.
    assert.equal(giaTri.length, 9);
    assert.equal(giaTri[0], '');
    assert.deepEqual(giaTri.slice(1), Object.keys(CAU_HINH.trangThai));
    assert.equal([...chon.options][1].textContent, 'Nháp');
});

test('đổi trạng thái gọi lại với status mới và về trang 1', async () => {
    const { tai, win, daGoi } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd()], { page: 3, last_page: 5 }),
        url: 'https://oohx.net/my/campaigns?page=3',
    });

    await choVeXong();

    const chon = tai.querySelector('[data-ds-trangthai]');
    chon.value = 'active';
    chon.dispatchEvent(new win.Event('change'));

    await choVeXong();

    const u = urlCua(daGoi, 1);

    assert.equal(u.searchParams.get('status'), 'active');
    // Giữ `page=3` khi kết quả chỉ còn 2 trang là hiện một trang rỗng và trông
    // như mất dữ liệu.
    assert.equal(u.searchParams.get('page'), '1');
});

/**
 * Ô tìm chờ gõ xong mới gọi.
 *
 * Gọi mỗi lần nhấn phím là một yêu cầu cho mỗi chữ cái, và hạn mức tần suất sẽ
 * chặn đúng người đang gõ nhanh nhất.
 */
test('ô tìm chờ 400ms sau lần gõ cuối mới gọi, gộp nhiều lần gõ thành một', async () => {
    const { tai, win, daGoi } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi([cd()]) });

    await choVeXong();
    assert.equal(daGoi.length, 1);

    const o = tai.querySelector('[data-ds-tim]');

    for (const s of ['T', 'Tế', 'Tết']) {
        o.value = s;
        o.dispatchEvent(new win.Event('input'));
    }

    // Chưa tới hạn chờ: vẫn chỉ một lời gọi.
    await choVeXong();
    assert.equal(daGoi.length, 1, 'ba lần gõ không được thành ba lời gọi');

    await new Promise((r) => setTimeout(r, 450));
    await choVeXong();

    assert.equal(daGoi.length, 2, 'sau hạn chờ thì gọi đúng một lần');
    assert.equal(urlCua(daGoi, 1).searchParams.get('q'), 'Tết');
});

// ── Phân trang ──────────────────────────────────────────────────────────────

test('một trang thì không vẽ thanh phân trang', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd()], { page: 1, last_page: 1, total: 1 }),
    });

    await choVeXong();

    assert.equal(chuCua(tai, 'data-ds-trang'), '');
});

test('nhiều trang thì vẽ thanh phân trang kèm tổng số', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd()], { page: 2, last_page: 5, total: 93 }),
        url: 'https://oohx.net/my/campaigns?page=2',
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-ds-trang');

    assert.match(chu, /Trang 2 \/ 5/);
    assert.match(chu, /93 campaign/);
    assert.equal(tai.querySelector('[data-ds-lui]').disabled, false);
    assert.equal(tai.querySelector('[data-ds-tien]').disabled, false);
});

test('trang đầu thì chặn nút Trước, trang cuối thì chặn nút Sau', async () => {
    const dau = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd()], { page: 1, last_page: 3, total: 50 }),
    });
    await choVeXong();
    assert.equal(dau.tai.querySelector('[data-ds-lui]').disabled, true);
    assert.equal(dau.tai.querySelector('[data-ds-tien]').disabled, false);

    const cuoi = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd()], { page: 3, last_page: 3, total: 50 }),
        url: 'https://oohx.net/my/campaigns?page=3',
    });
    await choVeXong();
    assert.equal(cuoi.tai.querySelector('[data-ds-lui]').disabled, false);
    assert.equal(cuoi.tai.querySelector('[data-ds-tien]').disabled, true);
});

test('bấm Sau gọi trang kế và giữ nguyên bộ lọc', async () => {
    const { tai, win, daGoi } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd()], { page: 2, last_page: 5, total: 93 }),
        url: 'https://oohx.net/my/campaigns?page=2&status=active&q=Tet',
    });

    await choVeXong();

    tai.querySelector('[data-ds-tien]').dispatchEvent(new win.Event('click'));
    await choVeXong();

    const u = urlCua(daGoi, 1);

    assert.equal(u.searchParams.get('page'), '3');
    assert.equal(u.searchParams.get('status'), 'active', 'đổi trang không được mất bộ lọc');
    assert.equal(u.searchParams.get('q'), 'Tet');
});

/**
 * Số trang phải vào URL, nếu không thì không chia sẻ được link tới trang 3 và
 * bấm Quay lại là mất chỗ đang đứng. Bản phân trang của Laravel vốn có cả hai
 * tính chất đó; chuyển sang đọc API không được làm mất.
 */
test('đổi trang ghi số trang vào URL của trình duyệt', async () => {
    const { tai, win } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd()], { page: 1, last_page: 4, total: 70 }),
    });

    await choVeXong();

    // Lần đầu chỉ chuẩn hoá URL: `page=1` không được ghi vào.
    assert.equal(new URL(win.location.href).searchParams.get('page'), null);

    tai.querySelector('[data-ds-tien]').dispatchEvent(new win.Event('click'));
    await choVeXong();

    assert.equal(new URL(win.location.href).searchParams.get('page'), '2');
});

test('popstate tải lại đúng trang trong URL mới', async () => {
    const { win, daGoi } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd()], { page: 1, last_page: 4, total: 70 }),
    });

    await choVeXong();

    win.history.replaceState({}, '', 'https://oohx.net/my/campaigns?page=4&status=paused');
    win.dispatchEvent(new win.Event('popstate'));

    await choVeXong();

    const u = urlCua(daGoi, daGoi.length - 1);

    assert.equal(u.searchParams.get('page'), '4');
    assert.equal(u.searchParams.get('status'), 'paused');
});

// ── Thoát ký tự ─────────────────────────────────────────────────────────────

test('tên chiến dịch chứa mã HTML không thoát ra khỏi thuộc tính', async () => {
    const doc = '"><img src=x onerror=alert(1)><b>đậm</b>';

    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([cd({ name: doc, code: doc, status_label: doc })]),
    });

    await choVeXong();

    const khung = tai.querySelector('[data-ds-than]');

    assert.equal(khung.querySelectorAll('img').length, 0);
    assert.equal(khung.querySelectorAll('b').length, 0);
    assert.match(khung.textContent, /<img src=x onerror=alert\(1\)>/);
});

// ── Lỗi ─────────────────────────────────────────────────────────────────────

test('401 hiện lỗi và không nạp lại trang', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([], {}, 401),
    });

    await choVeXong();

    assert.deepEqual(loiJsdom.filter((m) => /navigation|reload/i.test(m)), []);
    assert.equal(tai.querySelector('[data-ds-loi]').hidden, false);
    assert.match(chuCua(tai, 'data-ds-loi-msg'), /hết/i);

    // Thân và thanh phân trang phải trống: để lại "Đang tải…" bên cạnh một
    // thông báo lỗi là nói hai điều trái nhau cùng lúc.
    assert.equal(chuCua(tai, 'data-ds-than'), '');
    assert.equal(chuCua(tai, 'data-ds-trang'), '');
});

test('422 hiện thông điệp của máy chủ, không nói chung chung', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi([], {}, 422, 'Trạng thái không hợp lệ. Hợp lệ: draft, approved.'),
    });

    await choVeXong();

    assert.equal(
        chuCua(tai, 'data-ds-loi-msg'),
        'Trạng thái không hợp lệ. Hợp lệ: draft, approved.',
    );
});

test('429 nói rõ là vượt hạn mức', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi([], {}, 429) });

    await choVeXong();

    assert.match(chuCua(tai, 'data-ds-loi-msg'), /quá nhanh|một phút/i);
});

test('tải lại thành công sau một lần lỗi thì xoá thông báo lỗi', async () => {
    let lan = 0;

    const { tai, win } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: () => {
            lan++;
            return lan === 1
                ? { status: 500, body: { message: 'hỏng' } }
                : { status: 200, body: { data: [cd()], meta: meta() } };
        },
    });

    await choVeXong();
    assert.equal(tai.querySelector('[data-ds-loi]').hidden, false);

    const chon = tai.querySelector('[data-ds-trangthai]');
    chon.value = 'active';
    chon.dispatchEvent(new win.Event('change'));
    await choVeXong();

    assert.equal(tai.querySelector('[data-ds-loi]').hidden, true, 'lỗi cũ phải biến mất');
    assert.match(chuCua(tai, 'data-ds-than'), /Chiến dịch Tết/);
});
