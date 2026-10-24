/**
 * Chốt cho `webapp/lib/danh-gia-ho-so.ts` — lát 4 của
 * `docs/PLAN-CHI-TIET-VI-TRI.md`.
 *
 * Cái chốt quan trọng nhất của lát: hồ sơ THẬT phải cho ra **đúng 8/18**. Con
 * số ấy là thứ người mua đọc đầu tiên, nên nó không được trôi khi có người
 * thêm hoặc đổi một trường — và một con số sai ở đây sai theo hướng tệ nhất:
 * nói hồ sơ đủ hơn thực tế.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const { danhGiaHoSo, lechTiLe } = await import('../../webapp/lib/danh-gia-ho-so.ts');

/**
 * Nguyên văn phần đáng kể của `GET /api/v2/screens/oohx-led-cto-can-tho`,
 * đo ngày 24/10/2026.
 */
const THAT = {
    slug: 'oohx-led-cto-can-tho',
    name: 'OOHX_LED_CTO_CAN_THO',
    owner: { slug: 'adtrue', name: 'AdTRUE Platform ', verified: false },
    location: { site: 'LED Cần Thơ', city: 'Cần Thơ > Phường Tân An', district: null },
    size: { width_m: 23.04, height_m: 28.8 },
    pricing: {
        model: 'io',
        floor_cpm: { amount: null, currency: 'VND' },
        io_rate: { amount: null, currency: 'VND', unit: 'month' },
    },
    photo_url: 'https://oohx.net/storage/owners/covers/01KP5944Y5JW12MC2JJKYH2767.webp',
    photo_urls: ['https://oohx.net/storage/owners/covers/01KP5944Y5JW12MC2JJKYH2767.webp'],
    description: null,
    specs: {
        width_px: 1080,
        height_px: 1920,
        allow_image: true,
        allow_video: true,
        spot_length_s: 15,
        orientation: 'portrait',
        venue_label: 'Ngoài trời',
        venue_type: null,
        sov_max_pct: 100,
        io_kpi_spots_per_day: null,
    },
    site: {
        name: 'LED Cần Thơ',
        address: '59–59A Phan Đình Phùng, P. Tân An, Ninh Kiều, Cần Thơ',
        city: 'Cần Thơ > Phường Tân An',
        lat: 10.0321587,
        lng: 105.7868684,
    },
    insights: {
        placement_zone: null,
        placement_label: null,
        daily_footfall: null,
        monthly_reach: null,
        daily_impressions_est: null,
        audience_profile: null,
        time_performance: null,
        nearby_context: null,
        methodology_note: null,
        source: 'owner',
    },
    nearby_pois: [],
};

/** Hồ sơ đủ mọi trường — cực đối diện. */
const DU = {
    ...THAT,
    owner: { ...THAT.owner, verified: true },
    description: 'Biển LED mặt tiền đường lớn, hướng về vòng xoay.',
    photo_urls: [
        'https://oohx.net/storage/screens/a.jpg',
        'https://oohx.net/storage/screens/b.jpg',
    ],
    // 16,2 × 28,8 m khớp đúng tỉ lệ 1080×1920 (0,5625).
    size: { width_m: 16.2, height_m: 28.8 },
    pricing: {
        model: 'io',
        floor_cpm: { amount: null, currency: 'VND' },
        io_rate: { amount: 162500000, currency: 'VND', unit: 'month' },
    },
    specs: { ...THAT.specs, io_kpi_spots_per_day: 720 },
    nearby_pois: [{ name: 'Chợ Tân An' }],
    insights: {
        ...THAT.insights,
        daily_footfall: 31500,
        monthly_reach: 612000,
        daily_impressions_est: 48200,
        audience_profile: { male_pct: 52, female_pct: 48 },
        time_performance: { best_day: 'sat' },
    },
};

const het = (dg) => dg.nhom.flatMap((n) => n.truong);
const tim = (dg, ma) => het(dg).find((t) => t.ma === ma);

test('hồ sơ THẬT cho ra đúng 8/18', () => {
    const dg = danhGiaHoSo(THAT);

    assert.equal(dg.tong, 18);
    assert.equal(dg.diem, 8, `điểm là ${dg.diem}, phải 8`);
    assert.equal(dg.co, 6);
    assert.equal(dg.dangNgo, 2);
    assert.equal(dg.thieu, 10);
});

test('tổng ba trạng thái LUÔN bằng tổng số trường', () => {
    // Thêm một trường mà quên phân loại nó là một ca đỏ ở đây.
    for (const hs of [THAT, DU, {}, null, undefined, { specs: {} }]) {
        const dg = danhGiaHoSo(hs);
        assert.equal(
            dg.co + dg.dangNgo + dg.thieu,
            dg.tong,
            `lệch với: ${JSON.stringify(hs)?.slice(0, 40)}`,
        );
        assert.equal(dg.tong, 18);
        assert.equal(het(dg).length, 18);
    }
});

test('hồ sơ đủ mọi trường cho ra 18/18 và không trường nào đáng ngờ', () => {
    const dg = danhGiaHoSo(DU);

    assert.equal(dg.diem, 18);
    assert.equal(dg.co, 18);
    assert.equal(dg.dangNgo, 0);
    assert.equal(dg.thieu, 0);
    assert.equal(dg.ketLuan, 'Đủ để so sánh');
});

test('hồ sơ rỗng cho ra 0/18, không đổ', () => {
    for (const hs of [{}, null, undefined, 0, 'x', []]) {
        const dg = danhGiaHoSo(hs);
        assert.equal(dg.diem, 0, `giá trị: ${JSON.stringify(hs)}`);
        assert.equal(dg.thieu, 18);
    }
});

test('insights toàn null: CẢ NĂM trường khán giả là thiếu', () => {
    const dg = danhGiaHoSo(THAT);
    const khanGia = dg.nhom.find((n) => n.ma === 'khan_gia');

    assert.equal(khanGia.truong.length, 5);
    for (const t of khanGia.truong) {
        assert.equal(t.trangThai, 'thieu', `${t.ma} phải là thiếu`);
    }
});

test('dang_ngo CHỈ xuất hiện khi có lý do kiểm được', () => {
    const dg = danhGiaHoSo(THAT);
    const ngo = het(dg).filter((t) => t.trangThai === 'dang_ngo');

    assert.deepEqual(
        ngo.map((t) => t.ma).sort(),
        ['anh', 'kich_thuoc'],
        'chỉ ảnh-bìa-owner và tỉ lệ-lệch được coi là đáng ngờ',
    );

    // Và mỗi cái phải nói ra lý do — một dấu `!` không có câu giải thích thì
    // người mua không biết phải xác minh cái gì.
    for (const t of ngo) {
        assert.ok(t.ghiChu.length > 10, `${t.ma} thiếu lý do`);
    }
});

test('ảnh thật thì KHÔNG đáng ngờ, không ảnh nào thì thiếu', () => {
    assert.equal(tim(danhGiaHoSo(DU), 'anh').trangThai, 'co');
    assert.equal(tim(danhGiaHoSo({ ...THAT, photo_urls: [], photo_url: null }), 'anh').trangThai, 'thieu');

    // Ảnh bìa owner nằm ở vị trí thứ hai thì ảnh đầu vẫn là ảnh thật.
    const hai = danhGiaHoSo({
        ...THAT,
        photo_urls: ['https://oohx.net/storage/screens/a.jpg', THAT.photo_url],
    });
    assert.equal(tim(hai, 'anh').trangThai, 'co');
});

test('kích thước khớp tỉ lệ thì là "có", lệch thì "đáng ngờ"', () => {
    // 16,2/28,8 = 0,5625 = 1080/1920 → khớp.
    assert.equal(tim(danhGiaHoSo(DU), 'kich_thuoc').trangThai, 'co');

    // 23,04/28,8 = 0,8 ≠ 0,5625 → lệch 42%.
    assert.equal(tim(danhGiaHoSo(THAT), 'kich_thuoc').trangThai, 'dang_ngo');

    // Thiếu độ phân giải thì KHÔNG kết luận được tỉ lệ — kích thước vẫn "có".
    const khongPx = danhGiaHoSo({
        ...THAT,
        specs: { ...THAT.specs, width_px: null, height_px: null },
    });
    assert.equal(tim(khongPx, 'kich_thuoc').trangThai, 'co');
    assert.equal(tim(khongPx, 'phan_giai').trangThai, 'thieu');
});

test('lechTiLe trả null khi thiếu số, không trả 0', () => {
    // Trả 0 nghĩa là "khớp hoàn hảo" — một kết luận từ dữ liệu không có.
    assert.equal(lechTiLe(null, 28.8, 1080, 1920), null);
    assert.equal(lechTiLe(23.04, null, 1080, 1920), null);
    assert.equal(lechTiLe(23.04, 28.8, 0, 1920), null);
    assert.equal(lechTiLe('23', 28.8, 1080, 1920), null);

    assert.equal(lechTiLe(16.2, 28.8, 1080, 1920), 0);
    assert.ok(Math.abs(lechTiLe(23.04, 28.8, 1080, 1920) - 0.4222) < 0.001);
});

test('kết luận đọc giá và số khán giả, KHÔNG đọc điểm tổng', () => {
    // Một hồ sơ nhiều trường mà thiếu hai thứ đó vẫn không ra quyết định được.
    assert.equal(danhGiaHoSo(THAT).ketLuan, 'Chưa đủ để báo giá');

    const chiThieuGia = {
        ...DU,
        pricing: { ...THAT.pricing },
    };
    assert.equal(danhGiaHoSo(chiThieuGia).ketLuan, 'Thiếu giá');

    const chiThieuKhanGia = { ...DU, insights: { ...THAT.insights } };
    assert.equal(danhGiaHoSo(chiThieuKhanGia).ketLuan, 'Thiếu số khán giả');

    assert.equal(danhGiaHoSo(DU).ketLuan, 'Đủ để so sánh');
});

test('giá đọc được từ floor_cpm, không chỉ io_rate', () => {
    const cpm = {
        ...THAT,
        pricing: {
            model: 'cpm',
            floor_cpm: { amount: 41500, currency: 'VND' },
            io_rate: { amount: null, currency: 'VND', unit: null },
        },
    };

    assert.equal(tim(danhGiaHoSo(cpm), 'gia').trangThai, 'co');
});

test('mã trường là duy nhất — khoá React và khoá chốt', () => {
    const ma = het(danhGiaHoSo(THAT)).map((t) => t.ma);
    assert.equal(new Set(ma).size, ma.length, 'có mã trùng');
});

test('hàm là hàm thuần — /publisher dùng lại được', () => {
    // Không `window`, không `fetch`, không `document`: khối này phải dựng được
    // trong panel chủ vị trí, nơi không có gì của trang công khai.
    const lib = readFileSync(
        new URL('../../webapp/lib/danh-gia-ho-so.ts', import.meta.url),
        'utf8',
    )
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/\/\/.*$/gm, '');

    for (const xau of ['window', 'document', 'fetch(', 'localStorage', 'process.env']) {
        assert.ok(!lib.includes(xau), `hàm không được phụ thuộc: ${xau}`);
    }

    // Và gọi hai lần trên cùng dữ liệu phải ra cùng kết quả.
    assert.deepEqual(danhGiaHoSo(THAT), danhGiaHoSo(THAT));
});

test('khối hiện RIÊNG số trường đáng ngờ, không chỉ điểm tổng', () => {
    // Nếu khối chỉ in "8/18" thì hồ sơ này trông giống một hồ sơ có tám trường
    // sạch — trong khi hai trong tám trường của nó tự mâu thuẫn.
    const kh = readFileSync(
        new URL('../../webapp/components/KhoiHoSo.tsx', import.meta.url),
        'utf8',
    );

    assert.ok(kh.includes('dg.dangNgo > 0'), 'khối phải hiện riêng số đáng ngờ');
    assert.ok(kh.includes('cần xác minh'), 'thiếu câu nói việc cần làm');

    // Màu thanh đọc kết luận, không đọc phần trăm.
    assert.ok(kh.includes("dg.ketLuan === 'Đủ để so sánh'"), 'màu thanh phải đọc kết luận');
    assert.ok(!/du = pct/.test(kh), 'màu thanh không được đọc phần trăm');
});
