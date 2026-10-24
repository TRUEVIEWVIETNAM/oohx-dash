/**
 * Chốt tĩnh cho lát 5 của `docs/PLAN-CHI-TIET-VI-TRI.md` — hai việc cùng một
 * chủ đề: **trang không được hứa điều hệ thống không chứng minh được**.
 *
 * ══ Vì sao đọc mã nguồn ══
 *
 * `khoi-giao-dien.mjs` canh HTML đã render, và nó canh cả hai việc này. Nhưng
 * lớp đó cần một lượt `next build` nên nó là lớp cuối cùng chạy; còn ba đột
 * biến đáng lo nhất của lát 5 nhìn thấy được ngay tại chỗ sửa:
 *
 *   1. Gỡ markup bốn thẻ mà để lại hằng `DIEM_NOI_BAT` — class biến mất khỏi
 *      DOM nhưng bốn câu vẫn ở trong bundle, và một hằng không ai dùng sẽ
 *      được dùng lại.
 *   2. Huy hiệu xác minh in cả chiều `true` — đúng thứ dự án gỡ 18/10/2026.
 *   3. Dải cảnh báo nguồn ảnh in vô điều kiện, tức vô dụng y như bốn thẻ vừa
 *      gỡ.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const TRANG = readFileSync(
    new URL('../../webapp/app/explore/[slug]/page.tsx', import.meta.url),
    'utf8',
);

const THU_VIEN = readFileSync(
    new URL('../../webapp/components/ThuVienAnh.tsx', import.meta.url),
    'utf8',
);

/**
 * Bỏ chú thích trước khi đếm.
 *
 * Cùng cái bẫy đã làm đỏ ca đếm `getScreenAvailability` ở lát 3: docblock của
 * lát 5 **nhắc tên** cả bốn thẻ để người sửa sau biết vì sao chúng đi, nên một
 * phép tìm chuỗi thô sẽ thấy chúng ở chỗ chúng không còn render.
 */
function khongChuThich(ma) {
    return ma
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/^\s*\/\/.*$/gm, '');
}

const TRANG_SACH = khongChuThich(TRANG);
const THU_VIEN_SACH = khongChuThich(THU_VIEN);

// ── Việc 1: bốn thẻ "Điểm nổi bật" ─────────────────────────────────────────

test('bốn câu không kiểm được KHÔNG còn trong mã trang', () => {
    for (const cau of ['AI Traffic Data', 'Proof of Play', 'Verified Location', 'Fast Booking']) {
        assert.ok(
            !TRANG_SACH.includes(cau),
            `"${cau}" vẫn còn trong mã trang — gỡ markup thôi là chưa đủ`,
        );
    }
});

test('hằng DIEM_NOI_BAT và bộ class hl-* đi cùng nhau', () => {
    assert.ok(!TRANG_SACH.includes('DIEM_NOI_BAT'), 'hằng bốn thẻ vẫn còn');

    for (const lop of ['hl-grid', 'hl-card', 'hl-icon', 'hl-title', 'hl-sub']) {
        assert.ok(!TRANG_SACH.includes(lop), `class ${lop} vẫn còn`);
    }
});

test('bộ luật CSS .hl-* cũng đi — không để lại luật chết', () => {
    const css = readFileSync(
        new URL('../../resources/css/frontpage.css', import.meta.url),
        'utf8',
    );

    // Chỉ xét định nghĩa luật (`.hl-x{`), không xét chú thích giải thích vì
    // sao chúng đi.
    assert.equal(
        (css.match(/\.hl-[a-z]+\s*\{/g) ?? []).length,
        0,
        'frontpage.css còn luật .hl-* mà không nơi nào đặt tên ấy vào DOM',
    );
});

// ── Việc 2: huy hiệu xác minh, một chiều ───────────────────────────────────

test('huy hiệu xác minh in CHIỀU TẮT, không in chiều bật', () => {
    assert.ok(TRANG_SACH.includes('Chưa xác minh'), 'thiếu huy hiệu chiều tắt');

    // `owners.verified` là một cờ quản trị bật tay — không quy trình kiểm
    // định, không hồ sơ, không bước đối chiếu. Dự án gỡ chữ "Đã xác minh"
    // ngày 18/10/2026 khỏi `OwnerCard` và khỏi thẻ owner thu nhỏ của chính
    // trang này. In nó cạnh số điện thoại người mua sắp gọi là nói rằng SÀN
    // đã kiểm, và sàn thì chưa.
    assert.ok(
        !TRANG_SACH.includes('Đã xác minh'),
        'trang in "Đã xác minh" — xem docblock KhoiLienHe',
    );
});

test('huy hiệu đọc owner.verified, không đọc thứ gì khác', () => {
    assert.match(
        TRANG_SACH,
        /chuaXacMinh\s*=\s*screen\.owner\?\.verified\s*!==\s*true/,
        'huy hiệu phải đọc đúng `owner.verified`',
    );

    // `!== true` chứ không `=== false`: `undefined` (payload cũ, hoặc owner
    // thiếu hẳn) là "chưa biết", và chưa biết thì chưa xác minh.
    assert.ok(
        !/screen\.owner\?\.verified\s*===\s*false/.test(TRANG_SACH),
        '`=== false` để `undefined` lọt thành "đã xác minh"',
    );
});

// ── Việc 3: nguồn ảnh ──────────────────────────────────────────────────────

test('trang truyền photo_source xuống thư viện ảnh', () => {
    assert.match(TRANG_SACH, /nguon=\{screen\.photo_source\}/);
});

test('cảnh báo nguồn ảnh có điều kiện, và `screen` không kích hoạt nó', () => {
    // Bảng nhãn không được có khoá `screen`: ảnh của chính màn hình là trường
    // hợp bình thường, và một dải cảnh báo in cho mọi màn hình thì cũng vô
    // dụng như bốn thẻ vừa gỡ.
    const bang = THU_VIEN_SACH.match(/NGUON_THAY_THE[^=]*=\s*\{([\s\S]*?)\};/);
    assert.ok(bang, 'thiếu bảng nhãn nguồn ảnh');
    assert.ok(!/\bscreen\s*:/.test(bang[1]), 'bảng nhãn có khoá `screen`');

    // Và bốn bậc thay thế phải có đủ: thiếu một bậc thì bậc đó im lặng đúng
    // như trước lát 5.
    for (const nguon of ['site', 'network', 'owner_cover', 'venue_category']) {
        assert.ok(new RegExp(`${nguon}:`).test(bang[1]), `thiếu bậc ${nguon}`);
    }

    // Mỗi câu phải nói ra điều người mua cần biết, không chỉ dán nhãn.
    const soCau = (bang[1].match(/chưa có ảnh chụp vị trí/g) ?? []).length;
    assert.equal(soCau, 4, 'mỗi bậc phải nói "chưa có ảnh chụp vị trí"');

    // Dải chỉ dựng khi có nhãn — `canhBao` phải là một điều kiện thật.
    assert.match(THU_VIEN_SACH, /\{canhBao\s*\?/);
});

test('ảnh vẫn hiện khi là ảnh thay thế — không ẩn, chỉ nói rõ', () => {
    // Một ảnh thay thế vẫn cho biết khu vực đó trông thế nào, nên bỏ nó đi là
    // mất thông tin. Nếu có người đổi `canhBao` thành điều kiện ẩn `gal-main`
    // thì ca này đỏ.
    const viTriAnh = THU_VIEN_SACH.indexOf('gal-main');
    const viTriCanhBao = THU_VIEN_SACH.indexOf('gal-nguon');

    assert.ok(viTriAnh > -1 && viTriCanhBao > -1);
    assert.ok(viTriAnh < viTriCanhBao, 'ảnh phải đứng trước dải cảnh báo');
    assert.ok(
        !/canhBao\s*\?\s*null/.test(THU_VIEN_SACH),
        'cảnh báo không được dùng để ẩn ảnh',
    );
});
