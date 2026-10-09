// Múi giờ ÂM (UTC−10), đặt trước mọi import: kỳ chạy chiến dịch là ngày theo
// lịch nên không được đi qua múi giờ nào, và chạy ở UTC — như máy CI mặc định —
// thì lỗi "lùi một ngày" không bao giờ hiện ra.
process.env.TZ = 'Pacific/Honolulu';

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { dungTrang, choVeXong, chuCua, mocScriptCanTrang, mocDuocKhai } from './bo-khung.mjs';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const TRANG = 'resources/views/buyer/dashboard/index.blade.php';

const CAU_HINH = {
    apiTomTat: 'https://oohx.net/api/v2/campaigns/summary',
    apiDanhSach: 'https://oohx.net/api/v2/campaigns',
    explore: 'https://oohx.net/explore',
    xemBase: 'https://oohx.net/my/campaigns',
    soGanDay: 5,
    oThongKe: ['draft', 'pending_approval', 'active'],
};

/** Tám mã của `Campaign::STATUS_LABELS`, đúng thứ tự máy chủ trả. */
const TAM_MA = [
    ['draft', 'Nháp'],
    ['pending_approval', 'Chờ duyệt'],
    ['approved', 'Đã duyệt'],
    ['rejected', 'Từ chối'],
    ['active', 'Đang chạy'],
    ['paused', 'Tạm dừng'],
    ['completed', 'Hoàn thành'],
    ['cancelled', 'Đã hủy'],
];

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

function tomTat(dem = {}, total = null) {
    const by_status = TAM_MA.map(([status, label]) => ({
        status,
        label,
        count: dem[status] ?? 0,
    }));

    return {
        data: {
            total: total ?? by_status.reduce((t, m) => t + m.count, 0),
            by_status,
        },
    };
}

/**
 * Bộ trả lời phân nhánh theo ĐƯỜNG, vì trang này gọi hai endpoint.
 *
 * `loi` nhận `{ duong, status, thong }` để một trong hai đường hỏng riêng —
 * đó là tình huống của ca "một khối hỏng thì cả trang nói".
 */
function traLoi({ tomTat: tt, danhSach = [], loi = null } = {}) {
    return (url) => {
        const laTomTat = String(url).includes('/summary');

        if (loi && (loi.duong === 'ca' || (loi.duong === 'tomTat') === laTomTat)) {
            return {
                status: loi.status,
                body: { error: 'e', message: loi.thong ?? 'x', code: loi.status, details: [] },
            };
        }

        return laTomTat
            ? { status: 200, body: tt ?? tomTat() }
            : {
                status: 200,
                body: {
                    data: danhSach,
                    meta: { page: 1, per_page: 5, total: danhSach.length, last_page: 1, max_per_page: 100 },
                },
            };
    };
}

// ── Gọi đúng hai đường ──────────────────────────────────────────────────────

test('lần tải đầu gọi đúng hai đường, danh sách kèm per_page từ cấu hình', async () => {
    const { daGoi } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi() });

    await choVeXong();

    assert.equal(daGoi.length, 2, 'đúng hai lời gọi, không hơn');

    const tt = daGoi.find((u) => u.includes('/summary'));
    const ds = daGoi.find((u) => ! u.includes('/summary'));

    assert.ok(tt, 'phải gọi đường tóm tắt');
    assert.equal(new URL(ds).searchParams.get('per_page'), '5');
});

// ── Ô thống kê ──────────────────────────────────────────────────────────────

test('ô thống kê lấy CẢ số lẫn chữ từ máy chủ, theo thứ tự cấu hình', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ tomTat: tomTat({ draft: 2, pending_approval: 7, active: 3 }) }),
    });

    await choVeXong();

    const o = [...tai.querySelectorAll('[data-td-thongke] .buyer-stat')];

    assert.equal(o.length, 4, 'ba ô cấu hình đòi, cộng ô tổng');

    const doc = o.map((e) => [
        e.querySelector('.buyer-stat-n').textContent.trim(),
        e.querySelector('.buyer-stat-l').textContent.trim(),
    ]);

    assert.deepEqual(doc, [
        ['2', 'Nháp'],
        ['7', 'Chờ duyệt'],
        ['3', 'Đang chạy'],
        ['12', 'Tổng cộng'],
    ]);
});

/**
 * Chữ của ô thống kê **không** được trang tự dịch.
 *
 * Cùng lý lẽ với `status_label` ở danh sách: chữ là việc nghiệp vụ và nó đã có
 * một bảng ở `Campaign::STATUS_LABELS`. Một bản chép trong JS là bản thứ hai,
 * và nó sẽ lệch — đúng cách chữ trạng thái đã trôi thành năm bản.
 */
test('chữ ô thống kê là chữ máy chủ gửi, không phải bản dịch trong trang', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({
            tomTat: {
                data: {
                    total: 1,
                    by_status: TAM_MA.map(([status]) => ({
                        status,
                        label: status === 'draft' ? 'CHỮ TỪ MÁY CHỦ' : status,
                        count: status === 'draft' ? 1 : 0,
                    })),
                },
            },
        }),
    });

    await choVeXong();

    assert.match(chuCua(tai, 'data-td-thongke'), /CHỮ TỪ MÁY CHỦ/);
    assert.doesNotMatch(chuCua(tai, 'data-td-thongke'), /Nháp/, 'trang không được tự dịch mã');
});

/**
 * Mã cấu hình đòi mà phản hồi không có thì phải **hiện ra**, không biến mất.
 *
 * Phản hồi luôn trả đủ tám mã, nên thiếu một mã nghĩa là máy chủ đã đổi. Bỏ
 * qua im lặng thì một ô thống kê biến mất khỏi trang và không ai để ý; hiện
 * `—` thì người nhìn thấy ngay là có gì đó sai.
 */
test('mã cấu hình đòi mà máy chủ không trả thì hiện dấu gạch, không bỏ qua', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({
            tomTat: {
                data: {
                    total: 4,
                    by_status: [{ status: 'draft', label: 'Nháp', count: 4 }],
                },
            },
        }),
    });

    await choVeXong();

    const o = [...tai.querySelectorAll('[data-td-thongke] .buyer-stat')];

    assert.equal(o.length, 4, 'vẫn đủ ô — mã thiếu không làm ô biến mất');
    assert.equal(o[1].querySelector('.buyer-stat-n').textContent.trim(), '—');
    assert.equal(o[1].querySelector('.buyer-stat-l').textContent.trim(), 'pending_approval');
});

/**
 * `total` đọc thẳng từ máy chủ, KHÔNG cộng lại ở client.
 *
 * Máy chủ trả tổng của **mọi** mã trong CSDL, kể cả mã không có trong
 * `by_status` vì chưa có chữ. Cộng lại ở đây là che mất chiến dịch đó đi — và
 * làm hai trang hiện hai tổng khác nhau cho cùng một tổ chức.
 */
test('tổng đọc thẳng từ máy chủ, không cộng lại từ các phần', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        // Cố ý KHÔNG thoả: 2 + 0 + 0 + … = 2, nhưng tổng là 9.
        traLoi: traLoi({ tomTat: tomTat({ draft: 2 }, 9) }),
    });

    await choVeXong();

    const o = [...tai.querySelectorAll('[data-td-thongke] .buyer-stat')];

    assert.equal(o[3].querySelector('.buyer-stat-n').textContent.trim(), '9');
});

// ── Danh sách gần đây ───────────────────────────────────────────────────────

test('mỗi hàng là liên kết tới trang chi tiết đúng id', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ danhSach: [cd({ id: 'cpn-xyz' })] }),
    });

    await choVeXong();

    const a = tai.querySelector('[data-td-than] a.buyer-campaign-row');

    assert.equal(a.getAttribute('href'), 'https://oohx.net/my/campaigns/cpn-xyz');
});

test('hàng hiện tên, mã và kỳ chạy theo ngày lịch, không lùi ở múi giờ âm', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ danhSach: [cd()] }),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-td-than');

    assert.match(chu, /Chiến dịch Tết/);
    assert.match(chu, /CPN-ABC/);
    assert.match(chu, /08\/10\/2026/, 'ngày bắt đầu không được lùi một ngày');
    assert.match(chu, /01\/11\/2026/);
});

test('nhãn trạng thái lấy từ máy chủ, màu lấy từ client', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ danhSach: [cd({ status: 'approved', status_label: 'NHÃN TỪ MÁY CHỦ' })] }),
    });

    await choVeXong();

    const the = tai.querySelector('[data-td-than] .badge');

    assert.equal(the.textContent.trim(), 'NHÃN TỪ MÁY CHỦ');
    assert.ok(the.classList.contains('b-bl'), 'màu của trạng thái approved do client chọn');
    assert.doesNotMatch(chuCua(tai, 'data-td-than'), /Đã duyệt/, 'không được tự dịch status');
});

/**
 * Bảng màu phải phủ **cả tám** mã.
 *
 * Bản Blade trước đây là một ternary lồng tô đúng hai mã; sáu mã còn lại rơi
 * vào nhánh xám, nên "Đã hủy" và "Hoàn thành" cùng màu với "Nháp". Ca này đọc
 * từng mã một qua đúng đường vẽ thật, nên nó canh bảng màu chứ không canh một
 * biểu thức chính quy trên mã nguồn.
 */
test('mỗi mã trạng thái có màu riêng, không mã nào rơi vào nhánh xám mặc định', async () => {
    const mongDoi = {
        draft: 'b-gray', pending_approval: 'b-org', approved: 'b-bl', rejected: 'b-red',
        active: 'b-grn', paused: 'b-org', completed: 'b-gray', cancelled: 'b-red',
    };

    for (const [ma] of TAM_MA) {
        const { tai } = dungTrang(TRANG, {
            cauHinh: CAU_HINH,
            traLoi: traLoi({ danhSach: [cd({ status: ma, status_label: 'x' })] }),
        });

        await choVeXong();

        const the = tai.querySelector('[data-td-than] .badge');

        assert.ok(
            the.classList.contains(mongDoi[ma]),
            `trạng thái ${ma} phải có class ${mongDoi[ma]}, đang có "${the.className}"`,
        );
    }
});

test('tên chiến dịch chứa mã HTML không thoát ra khỏi thuộc tính', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ danhSach: [cd({ name: '"><img src=x onerror=alert(1)>' })] }),
    });

    await choVeXong();

    assert.equal(
        tai.querySelectorAll('[data-td-than] img').length,
        0,
        'tên do người dùng gõ không được dựng thành thẻ',
    );
});

test('chưa có chiến dịch nào thì mời đi khám phá kho', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ danhSach: [] }),
    });

    await choVeXong();

    assert.match(chuCua(tai, 'data-td-than'), /Chưa có campaign nào/);
    assert.equal(
        tai.querySelector('[data-td-than] a').getAttribute('href'),
        'https://oohx.net/explore',
    );
});

// ── Lỗi ─────────────────────────────────────────────────────────────────────

/**
 * Thông báo lỗi phải **dọn cả hai khối**, không để số cũ nằm cạnh lời xin lỗi.
 *
 * Số đếm còn trên trang bên cạnh dòng "không tải được" là một trang nói hai
 * điều trái nhau: ba con số trông như dữ liệu thật, trong khi câu bên trên nói
 * là không có dữ liệu.
 *
 * Ghi rõ điều ca này **không** canh, vì tôi đã thử: tách `Promise.all` thành
 * hai `then` rời thì ca này vẫn xanh — `veLoi()` dọn cả hai khối trong cả hai
 * cách viết. Thứ pin `Promise.all` lại là ba ca lỗi bên dưới (401/429/500):
 * tách rời thì một trong hai chuỗi không có `catch`, và lỗi của nó không tới
 * được `veLoi()`.
 */
test('thông báo lỗi dọn cả ô thống kê lẫn danh sách', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ loi: { duong: 'danhSach', status: 500, thong: 'Máy chủ hỏng' } }),
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-td-loi]').hidden, false);
    assert.equal(chuCua(tai, 'data-td-thongke'), '', 'không được vẽ ô thống kê khi nửa kia hỏng');
    assert.equal(chuCua(tai, 'data-td-than'), '');
});

test('401 hiện lỗi kèm lối đăng nhập lại, không nạp lại trang', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ loi: { duong: 'ca', status: 401, thong: 'x' } }),
    });

    await choVeXong();

    assert.match(chuCua(tai, 'data-td-loi-msg'), /Phiên đăng nhập đã hết/);
    assert.deepEqual(loiJsdom, [], 'không được điều hướng/nạp lại');
});

test('429 nói rõ là vượt hạn mức, không nói chung chung', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ loi: { duong: 'tomTat', status: 429 } }),
    });

    await choVeXong();

    assert.match(chuCua(tai, 'data-td-loi-msg'), /quá nhanh/);
});

test('lỗi khác hiện thông điệp của máy chủ', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ loi: { duong: 'tomTat', status: 500, thong: 'Lỗi do máy chủ mô tả' } }),
    });

    await choVeXong();

    assert.equal(chuCua(tai, 'data-td-loi-msg'), 'Lỗi do máy chủ mô tả');
});

// ── Cấu hình trang thật ─────────────────────────────────────────────────────

/**
 * Mã trong `oThongKe` của trang THẬT phải là mã enum, không phải chữ tuỳ ý.
 *
 * Bộ khung chạy script trên cấu hình do test dựng, nên không ca nào ở trên
 * chạm tới cấu hình thật. Một lỗi gõ ở đó — `pending` thay vì
 * `pending_approval` — cho ra một ô `—` trên production mà mọi test vẫn xanh.
 */
test('cấu hình thật của trang chỉ dùng hằng trạng thái, không chuỗi rời', () => {
    const nguon = readFileSync(resolve(GOC, TRANG), 'utf8');
    const khop = nguon.match(/'oThongKe'\s*=>\s*\[([\s\S]*?)\]/);

    assert.ok(khop, 'không còn khoá `oThongKe` trong trang — đổi cách viết thì sửa test cùng lượt');

    const so = [...khop[1].matchAll(/Campaign::(STATUS_\w+)/g)].length;

    assert.ok(so >= 3, `chờ ít nhất ba hằng Campaign::STATUS_*, thấy ${so}`);
    assert.doesNotMatch(
        khop[1],
        /'[a-z_]+'/,
        'mã trạng thái phải là hằng của model, không phải chuỗi gõ tay',
    );
});
