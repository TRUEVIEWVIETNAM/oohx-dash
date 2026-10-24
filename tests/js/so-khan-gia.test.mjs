/**
 * Chốt cho `webapp/lib/so-khan-gia.ts` — lát 2 của
 * `docs/PLAN-CHI-TIET-VI-TRI.md`.
 *
 * Hình dạng dữ liệu thật, đo trên production ngày 24/10/2026
 * (`GET /api/v2/screens/oohx-led-cto-can-tho`): đối tượng `insights` **có**,
 * mọi trường bên trong **null**, `source` là `"owner"`.
 *
 * `KhoiInsights` chỉ chặn `!insights`, nên hình dạng đó đi qua và render một
 * `<div class="ins">` rỗng — tab mở ra không có gì. Không chốt nào đỏ, vì mọi
 * màn hình giả trước lát này hoặc đủ số liệu, hoặc có `insights: null`.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const { coSoKhanGia, coNoiDung } = await import('../../webapp/lib/so-khan-gia.ts');

/** Nguyên văn hình dạng production. */
const NHU_PRODUCTION = {
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
};

test('hình dạng production: đối tượng CÓ, mọi trường null → không có số liệu', () => {
    assert.equal(coSoKhanGia(NHU_PRODUCTION), false);
});

test('null, undefined và kiểu lạ đều là không có', () => {
    for (const v of [null, undefined, 0, '', 'owner', [], 123, true]) {
        assert.equal(coSoKhanGia(v), false, `giá trị: ${JSON.stringify(v)}`);
    }
});

test('ĐÚNG MỘT trường có số là đủ để coi như có', () => {
    // Từng trường một: nếu hàm quên một trường thì ca này chỉ ra đúng trường đó.
    const motTruong = [
        ['daily_footfall', 12000],
        ['monthly_reach', 250000],
        ['daily_impressions_est', 7200],
        ['placement_zone', 'escalator'],
    ];

    for (const [khoa, giaTri] of motTruong) {
        assert.equal(
            coSoKhanGia({ ...NHU_PRODUCTION, [khoa]: giaTri }),
            true,
            `trường bị bỏ sót: ${khoa}`,
        );
    }
});

test('ba đối tượng con có nội dung cũng tính là có', () => {
    const conCoNoiDung = [
        ['audience_profile', { male_pct: 52 }],
        ['time_performance', { best_day: 'sat' }],
        ['nearby_context', { highlights: 'Cạnh chợ' }],
    ];

    for (const [khoa, giaTri] of conCoNoiDung) {
        assert.equal(
            coSoKhanGia({ ...NHU_PRODUCTION, [khoa]: giaTri }),
            true,
            `nhánh bị bỏ sót: ${khoa}`,
        );
    }
});

test('đối tượng con TOÀN NULL không tính là có', () => {
    // Đây là cùng hình dạng lỗi, một cấp sâu hơn: trước lát 2, ba nhánh này
    // được kiểm bằng `object ? (`, nên một `audience_profile` toàn null vẫn
    // dựng tiêu đề khối "Chân dung khán giả" cùng những thanh phần trăm 0% —
    // tức vẽ ra một chân dung gồm 0% nam và 0% nữ.
    const rong = {
        male_pct: null,
        female_pct: null,
        age_18_24_pct: null,
        income_tier: null,
        lifestyle_tags: [],
        source_note: 'Đếm thủ công 3 ngày',
    };

    assert.equal(coNoiDung(rong), false);
    assert.equal(coSoKhanGia({ ...NHU_PRODUCTION, audience_profile: rong }), false);
});

test('source, methodology_note và source_note KHÔNG phải số liệu', () => {
    // `source` luôn có mặt kể cả khi không có gì được khai. Đếm nó là nói rằng
    // hồ sơ có dữ liệu, trong khi nó chỉ có một nhãn về nguồn của thứ không
    // tồn tại.
    assert.equal(coSoKhanGia({ ...NHU_PRODUCTION, source: 'owner' }), false);
    assert.equal(
        coSoKhanGia({ ...NHU_PRODUCTION, methodology_note: 'Đếm thủ công ba ngày' }),
        false,
    );
    assert.equal(coNoiDung({ source_note: 'Đếm thủ công' }), false);
});

test('số 0 là "chưa đo", không phải "đã đo và bằng không"', () => {
    // Một màn hình có `daily_footfall: 0` mà hiện ra khối số liệu là nói rằng
    // không ai đi qua điểm đó — một khẳng định sai về vị trí.
    assert.equal(coSoKhanGia({ ...NHU_PRODUCTION, daily_footfall: 0 }), false);
    assert.equal(coSoKhanGia({ ...NHU_PRODUCTION, monthly_reach: 0 }), false);
    assert.equal(coNoiDung({ male_pct: 0, female_pct: 0 }), false);
});

test('mảng rỗng và chuỗi trắng không dựng được gì', () => {
    assert.equal(coNoiDung({ lifestyle_tags: [] }), false);
    assert.equal(coNoiDung({ brands: [], landmarks: [] }), false);
    assert.equal(coNoiDung({ highlights: '   ' }), false);
    assert.equal(coNoiDung({ highlights: 'Cạnh chợ Tân An' }), true);
});

test('bảy điều kiện của coSoKhanGia khớp NĂM nhánh render của KhoiInsights', () => {
    // Nếu `KhoiInsights` thêm một nhánh mà hàm không biết, khối lại có thể
    // render rỗng. Nếu hàm kể một trường mà khối không render, tab hiện ra
    // trống. Hai tệp phải nói cùng một chuyện.
    const lib = readFileSync(new URL('../../webapp/lib/so-khan-gia.ts', import.meta.url), 'utf8');
    const kh = readFileSync(
        new URL('../../webapp/components/KhoiInsights.tsx', import.meta.url),
        'utf8',
    );

    for (const truong of [
        'daily_footfall',
        'monthly_reach',
        'daily_impressions_est',
        'placement_zone',
        'audience_profile',
        'time_performance',
        'nearby_context',
    ]) {
        assert.ok(lib.includes(truong), `hàm không kể tới: ${truong}`);
        assert.ok(kh.includes(truong), `component không dùng: ${truong}`);
    }

    // Và component phải đi qua hàm, không tự dựng lại phép kiểm.
    assert.ok(kh.includes('coSoKhanGia(insights)'), 'KhoiInsights phải gọi coSoKhanGia');
    assert.ok(kh.includes('coNoiDung(insights.audience_profile)'), 'nhánh khán giả phải qua coNoiDung');
    assert.ok(kh.includes('coNoiDung(insights.time_performance)'), 'nhánh thời gian phải qua coNoiDung');
    assert.ok(kh.includes('coNoiDung(insights.nearby_context)'), 'nhánh bối cảnh phải qua coNoiDung');

    // Phép kiểm cũ không được còn: `object ? (` là chính chỗ sai.
    assert.ok(
        !kh.includes('insights.audience_profile ?? null'),
        'còn phép kiểm cũ ở nhánh khán giả',
    );
});

test('không có số liệu thì NÓI, không trả null', () => {
    // Ca này thêm sau khi đột biến `return <KhongCoSoLieu />` → `return null`
    // chạy qua cả bộ chốt tĩnh mà không ca nào đỏ. `khoi-giao-dien.mjs` bắt
    // được (nó canh `class="ins-trong"`), nhưng lớp đó cần `next build` nên nó
    // là lớp cuối, không phải lớp đầu.
    //
    // Và đây là chính quyết định của lát 2: trả `null` là ẩn khối, mà ẩn khối
    // nói với người mua rằng SÀN không có phần số liệu khán giả — trong khi sự
    // thật là CHỦ VỊ TRÍ chưa khai.
    const kh = readFileSync(
        new URL('../../webapp/components/KhoiInsights.tsx', import.meta.url),
        'utf8',
    );

    assert.ok(
        kh.includes('return <KhongCoSoLieu />;'),
        'nhánh không-có-số-liệu phải render khối, không trả null',
    );
    assert.ok(kh.includes('function KhongCoSoLieu()'), 'thiếu khối KhongCoSoLieu');

    // Khối phải nói được GÌ, không chỉ nói "không có": hệ quả (không tính được
    // CPM) và một đường đi tiếp.
    const i = kh.indexOf('function KhongCoSoLieu()');
    const than = kh.slice(i, i + 1200);
    assert.ok(than.includes('không tính được CPM'), 'thiếu hệ quả');
    assert.ok(than.includes('/explore/yeu-cau-bao-gia'), 'thiếu đường đi tiếp');
    assert.ok(than.includes('Chủ vị trí chưa cung cấp'), 'thiếu câu nói ai còn nợ dữ liệu');
});

test('lịch trống: hai trạng thái phải nói hai câu khác nhau', () => {
    // `lich === null` (lời gọi lỗi, sàn KHÔNG BIẾT) và `days: []` (lấy được,
    // sàn BIẾT là không có ngày nào mở bán) từng dùng cùng một câu. Nói "thử
    // tải lại trang" cho trường hợp sau là dẫn người mua đi làm một việc vô
    // ích.
    const lt = readFileSync(
        new URL('../../webapp/components/LichTrong.tsx', import.meta.url),
        'utf8',
    );

    assert.ok(!lt.includes('!lich || !lich.days?.length'), 'hai trạng thái còn bị gộp');
    assert.ok(lt.includes('Chưa lấy được lịch trống'), 'thiếu câu cho trường hợp lời gọi lỗi');
    assert.ok(lt.includes('Không có ngày nào mở bán'), 'thiếu câu cho trường hợp lấy được mà rỗng');

    // Câu "thử tải lại trang" chỉ được xuất hiện ở nhánh lời gọi lỗi.
    const iNull = lt.indexOf('Chưa lấy được lịch trống');
    const iRong = lt.indexOf('Không có ngày nào mở bán');
    const iTaiLai = lt.indexOf('Thử tải lại trang');
    assert.ok(iTaiLai > iNull && iTaiLai < iRong, '"thử tải lại trang" nằm sai nhánh');
});
