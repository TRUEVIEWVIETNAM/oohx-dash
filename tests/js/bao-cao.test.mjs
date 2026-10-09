import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dungTrang, choVeXong, chuCua, mocScriptCanTrang, mocDuocKhai } from './bo-khung.mjs';

const TRANG = 'resources/views/buyer/dashboard/report.blade.php';

const CAU_HINH = {
    api: 'https://oohx.net/api/v2/campaigns/cpn-1/report',
    duongChiTiet: 'https://oohx.net/my/campaigns/cpn-1',
    dangNhap: 'https://oohx.net/login',
};

// ── Hợp đồng móc DOM ────────────────────────────────────────────────────────

test('tập móc script cần trang cung cấp trùng khít tập móc được khai', () => {
    assert.deepEqual(mocScriptCanTrang(TRANG), mocDuocKhai(TRANG));
});

// ── Dữ liệu mẫu ─────────────────────────────────────────────────────────────

function tongQuan(ghiDe = {}) {
    return {
        total_screens: 12,
        total_estimated_impressions: 500_000,
        total_actual_impressions: 420_000,
        delivery_rate: 84,
        total_estimated_cost: 10_000_000,
        total_actual_cost: 8_400_000,
        days_total: 10,
        days_elapsed: 7,
        days_remaining: 3,
        progress_pct: 70,
        ...ghiDe,
    };
}

function hang(ghiDe = {}) {
    return {
        screen_name: 'Màn hình Nguyễn Trãi',
        owner_name: 'Kim Ngân ADV',
        city: 'Hà Nội',
        dates: '01/10 → 10/10',
        estimated_impressions: 50_000,
        actual_impressions: 42_000,
        delivery_rate: 84,
        actual_cost: 840_000,
        ...ghiDe,
    };
}

function traLoi({ overview, breakdown = [hang()], daily, loi = null } = {}) {
    return () => {
        if (loi) {
            return {
                status: loi.ma,
                body: { error: 'e', message: loi.thong ?? 'x', code: loi.ma, details: [] },
            };
        }

        return {
            status: 200,
            body: {
                data: {
                    overview: overview ?? tongQuan(),
                    breakdown,
                    daily: daily ?? { labels: ['01/10', '02/10'], impressions: [1000, 2000] },
                },
            },
        };
    };
}

// ── Gọi đúng một đường ──────────────────────────────────────────────────────

test('chỉ gọi đúng một đường, đúng endpoint báo cáo', async () => {
    const { daGoi } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi() });

    await choVeXong();

    assert.deepEqual(daGoi, [CAU_HINH.api]);
});

// ── Ô thống kê ──────────────────────────────────────────────────────────────

test('bốn ô thống kê đọc thẳng từ API, không tính lại ở client', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi() });

    await choVeXong();

    const o = [...tai.querySelectorAll('[data-rpt-thongke] .rpt-stat')];

    assert.equal(o.length, 4);

    const doc = o.map((e) => e.querySelector('.rpt-stat-n').textContent.trim());

    assert.deepEqual(doc, ['420.000', '84%', '8.400.000', '12']);
});

/**
 * Tỉ lệ phát sóng **không** được tính lại ở trang.
 *
 * Phản hồi dưới đây cố ý **không** thoả `thực tế / ước tính`: 420.000 trên
 * 500.000 là 84%, nhưng máy chủ nói 61,5%. Một bản tự chia sẽ hiện 84 và ca
 * này đỏ — đúng cách ca VAT ở trang giỏ hàng bắt được phép nhân thứ hai.
 */
test('tỉ lệ phát sóng đọc từ máy chủ, không chia lại', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ overview: tongQuan({ delivery_rate: 61.5 }) }),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-rpt-thongke');

    assert.match(chu, /61\.5%|61,5%/);
    assert.doesNotMatch(chu, /\b84%/, 'trang không được tự chia thực tế / ước tính');
});

test('tiến độ lấy progress_pct của máy chủ, không tính từ số ngày', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        // 7/10 ngày là 70%, nhưng máy chủ nói 42%.
        traLoi: traLoi({ overview: tongQuan({ progress_pct: 42 }) }),
    });

    await choVeXong();

    const chu = chuCua(tai, 'data-rpt-tiendo');

    assert.match(chu, /42%/);
    assert.doesNotMatch(chu, /70%/, 'trang không được tự tính tiến độ từ days_elapsed');
});

// ── Bảng chi tiết ───────────────────────────────────────────────────────────

test('bảng hiện tên màn hình, owner, thành phố, kỳ chạy và số liệu', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi() });

    await choVeXong();

    const chu = chuCua(tai, 'data-rpt-bang');

    assert.match(chu, /Màn hình Nguyễn Trãi/);
    assert.match(chu, /Kim Ngân ADV/);
    assert.match(chu, /Hà Nội/);
    assert.match(chu, /01\/10 → 10\/10/);
    assert.match(chu, /50\.000/);
    assert.match(chu, /42\.000/);
    assert.match(chu, /840\.000/);
});

test('tên màn hình chứa mã HTML không thoát ra khỏi thuộc tính', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ breakdown: [hang({ screen_name: '"><img src=x onerror=alert(1)>' })] }),
    });

    await choVeXong();

    assert.equal(
        tai.querySelectorAll('[data-rpt-bang] img').length,
        0,
        'tên màn hình đến từ dữ liệu người dùng, không được dựng thành thẻ',
    );
});

test('báo cáo không có màn hình nào thì nói ra, không để bảng trống', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi({ breakdown: [] }) });

    await choVeXong();

    assert.match(chuCua(tai, 'data-rpt-bang'), /Chưa có màn hình nào/);
});

// ── Biểu đồ: Chart.js từ CDN ────────────────────────────────────────────────

/**
 * Thiếu Chart.js thì trang **vẫn** hiện ô thống kê và bảng.
 *
 * Bản cũ có hai khối script, nên `new Chart` nổ chỉ cắt khối biểu đồ — phần
 * còn lại đã được máy chủ render sẵn. Nay cả trang nằm trong MỘT khối, nên một
 * CDN bị chặn sẽ cắt luôn hai phần kia nếu không có chốt.
 *
 * jsdom không nạp script ngoài, nên `window.Chart` không tồn tại — tức mọi ca
 * trong tệp này chạy đúng ở nhánh "không có thư viện". Đó là lý do ca này phải
 * khẳng định hai phần kia vẫn đầy đủ.
 */
test('thiếu Chart.js thì ô thống kê và bảng vẫn đầy đủ, và trang nói ra', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi() });

    await choVeXong();

    assert.equal(tai.querySelectorAll('[data-rpt-thongke] .rpt-stat').length, 4);
    assert.match(chuCua(tai, 'data-rpt-bang'), /Màn hình Nguyễn Trãi/);
    assert.match(tai.body.textContent, /Không tải được thư viện biểu đồ/);
});

test('có Chart.js thì biểu đồ nhận đúng nhãn và số impressions của máy chủ', async () => {
    const { win, tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi() });

    const daDung = [];
    win.Chart = function (el, cauHinh) { daDung.push({ el, cauHinh }); };

    await choVeXong();

    assert.equal(daDung.length, 1, 'phải dựng đúng một biểu đồ');

    const d = daDung[0].cauHinh.data;

    assert.deepEqual(d.labels, ['01/10', '02/10']);
    assert.deepEqual(d.datasets[0].data, [1000, 2000]);
    assert.equal(daDung[0].el, tai.querySelector('[data-rpt-bieudo]'));
    assert.doesNotMatch(tai.body.textContent, /Không tải được thư viện biểu đồ/);
});

// ── Lỗi ─────────────────────────────────────────────────────────────────────

test('401 hiện lỗi kèm lối đăng nhập lại, không nạp lại trang', async () => {
    const { tai, loiJsdom } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ loi: { ma: 401 } }),
    });

    await choVeXong();

    assert.equal(tai.querySelector('[data-rpt-loi]').hidden, false);
    assert.match(chuCua(tai, 'data-rpt-loi-msg'), /Phiên đăng nhập đã hết/);
    assert.deepEqual(loiJsdom, [], 'không được điều hướng/nạp lại');
});

test('429 nói rõ là vượt hạn mức', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi({ loi: { ma: 429 } }) });

    await choVeXong();

    assert.match(chuCua(tai, 'data-rpt-loi-msg'), /quá nhanh/);
});

/**
 * 404 của endpoint này là một câu trả lời nghiệp vụ, không phải "không có trang".
 *
 * Chiến dịch chưa chạy thì chưa có báo cáo, và máy chủ nói đúng câu đó. Trang
 * phải hiện câu của máy chủ chứ không nói chung chung.
 */
test('404 hiện đúng thông điệp của máy chủ', async () => {
    const { tai } = dungTrang(TRANG, {
        cauHinh: CAU_HINH,
        traLoi: traLoi({ loi: { ma: 404, thong: 'Báo cáo chỉ khả dụng cho campaign đang chạy…' } }),
    });

    await choVeXong();

    assert.match(chuCua(tai, 'data-rpt-loi-msg'), /chỉ khả dụng cho campaign đang chạy/);
});

test('lỗi thì dọn cả ô thống kê, tiến độ lẫn bảng', async () => {
    const { tai } = dungTrang(TRANG, { cauHinh: CAU_HINH, traLoi: traLoi({ loi: { ma: 500 } }) });

    await choVeXong();

    assert.equal(chuCua(tai, 'data-rpt-thongke'), '');
    assert.equal(chuCua(tai, 'data-rpt-tiendo'), '');
    assert.equal(chuCua(tai, 'data-rpt-bang'), '');
});

// ── Cấu hình trang thật ─────────────────────────────────────────────────────

/**
 * Trang thật phải giữ chỗ cho khối thống kê.
 *
 * `.rpt-stats` là grid không có `min-height`; rỗng hẳn thì mọi thứ bên dưới
 * nhảy khi API trả về. Bộ khung jsdom dựng khối này rỗng từ `moc-dom.json`,
 * nên chỉ đọc mã nguồn mới canh được phần giữ chỗ — xem cùng lỗi ở PR #47.
 */
test('trang thật giữ chỗ cho khối thống kê', async () => {
    const { readFileSync } = await import('node:fs');
    const { fileURLToPath } = await import('node:url');
    const { dirname, resolve } = await import('node:path');

    const goc = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
    const nguon = readFileSync(resolve(goc, TRANG), 'utf8');
    const o = nguon.indexOf('data-rpt-thongke');

    assert.notEqual(o, -1);
    assert.match(
        nguon.slice(o, o + 600),
        /@for[\s\S]*rpt-stat-n[\s\S]*rpt-stat-l[\s\S]*@endfor/,
        'khối thống kê phải dựng sẵn thẻ rỗng',
    );
});
