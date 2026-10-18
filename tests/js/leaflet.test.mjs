// ════════════════════════════════════════════════════════════════════════════
// Hai luật về cách dùng Leaflet trong `webapp/`.
//
// Vì sao tệp này tồn tại — hai lỗi thật, cả hai đã ở trên production:
//
//   • `/map` ném `markerClusterGroup is not a function` và không hiện pin nào.
//     `leaflet.markercluster` là plugin UMD: nó gắn `markerClusterGroup` lên
//     **module.exports của leaflet**, tức `L.default` trong ESM. Namespace
//     object mà `await import('leaflet')` trả về không nhận thuộc tính thêm
//     sau, nên gọi trên nó là gọi `undefined`.
//
//   • Bản đồ trang chủ hiện **ảnh vỡ** kèm chữ "Mark" thay cho pin.
//     `L.marker()` không có `icon` dùng `marker-icon.png` của Leaflet, và
//     bundler không giải đường ảnh đó.
//
// Cả hai lỗi **không** bị bắt bởi ba chốt đang có, và lý do đáng ghi lại:
// `typecheck` thấy kiểu hợp lệ (ép kiểu qua `as unknown as`), `test:seo` và
// `khoi-giao-dien.mjs` đọc HTML **máy chủ render** — mà Leaflet chỉ chạy ở
// trình duyệt. Một chốt đọc HTML không bao giờ thấy một hàm bị gọi sai.
//
// Nên đây là chốt **tĩnh**: nó đọc mã nguồn và kiểm hai hình dạng. Hẹp, nhưng
// hẹp đúng chỗ đã mất hai lần.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const GOC = 'webapp/components';

/**
 * Bỏ chú thích trước khi quét, giữ nguyên số dòng.
 *
 * Cần thiết, không phải cho gọn: chính các docblock giải thích hai lỗi này đều
 * **trích dẫn** đoạn mã sai, nên một chốt quét thô sẽ đỏ trên lời giải thích
 * của chính nó. Thay mỗi ký tự trong chú thích bằng dấu cách thay vì xoá, để
 * số dòng trong thông báo lỗi vẫn trỏ đúng chỗ.
 */
function boChuThich(ma) {
    return ma
        .replace(/\/\*[\s\S]*?\*\//g, (khoi) => khoi.replace(/[^\n]/g, ' '))
        .replace(/\/\/[^\n]*/g, (khoi) => ' '.repeat(khoi.length));
}

/** Mọi tệp `.tsx` trong `webapp/components` có nhắc tới leaflet. */
function tepDungLeaflet() {
    return readdirSync(GOC)
        .filter((t) => t.endsWith('.tsx'))
        .map((t) => ({ ten: t, noiDung: readFileSync(join(GOC, t), 'utf8') }))
        .map((t) => ({ ...t, noiDung: boChuThich(t.noiDung) }))
        .filter((t) => t.noiDung.includes("import('leaflet')"));
}

test('mọi `await import("leaflet")` đều lấy `.default`', () => {
    const tep = tepDungLeaflet();

    // Chống rỗng: nếu không tệp nào dùng leaflet thì luật này đang canh một
    // tập rỗng, và nó sẽ xanh mãi mãi mà không ai biết.
    assert.ok(tep.length >= 2, `Chỉ thấy ${tep.length} tệp dùng Leaflet — phạm vi quét có vẻ hỏng.`);

    const sai = [];

    for (const { ten, noiDung } of tep) {
        // `await import('leaflet')` KHÔNG đi kèm `.default` ngay sau đó.
        const re = /await import\('leaflet'\)(?!\s*\)?\s*\.default)/g;
        let m;

        while ((m = re.exec(noiDung)) !== null) {
            const dong = noiDung.slice(0, m.index).split('\n').length;
            sai.push(`${ten}:${dong}`);
        }
    }

    assert.deepEqual(
        sai,
        [],
        'Những chỗ sau lấy namespace thay vì `.default` của leaflet. ' +
            '`leaflet.markercluster` gắn `markerClusterGroup` lên `module.exports`, ' +
            'nên gọi trên namespace ném "is not a function" — chỉ ở trình duyệt:\n  ' +
            sai.join('\n  '),
    );
});

test('mọi `L.marker(...)` đều truyền `icon`', () => {
    const tep = tepDungLeaflet();
    const sai = [];

    for (const { ten, noiDung } of tep) {
        // `L.marker([...]` rồi tới `)` hoặc `.` mà không có `icon` ở giữa.
        const re = /L\.marker\(\s*\[[^\]]*\]\s*(?:,\s*\{(?<tuychon>[^}]*)\})?\s*\)/g;
        let m;

        while ((m = re.exec(noiDung)) !== null) {
            const tuychon = m.groups?.tuychon ?? '';

            if (/\bicon\b/.test(tuychon)) continue;

            const dong = noiDung.slice(0, m.index).split('\n').length;
            sai.push(`${ten}:${dong}`);
        }
    }

    assert.deepEqual(
        sai,
        [],
        'Những chỗ sau dựng marker không có `icon`, nên Leaflet dùng ' +
            '`marker-icon.png` — một đường ảnh bundler không giải, và trên ' +
            'production nó ra ảnh vỡ:\n  ' +
            sai.join('\n  '),
    );
});

test('bộ trích không rỗng — một regex vỡ phải đỏ, không xanh vì không tìm thấy gì', () => {
    const tep = tepDungLeaflet();

    const soImport = tep.reduce(
        (n, t) => n + (t.noiDung.match(/await import\('leaflet'\)/g) || []).length,
        0,
    );

    const soMarker = tep.reduce((n, t) => n + (t.noiDung.match(/L\.marker\(/g) || []).length, 0);

    assert.ok(soImport >= 5, `Chỉ thấy ${soImport} lời gọi \`import('leaflet')\` — bộ trích có vẻ hỏng.`);
    assert.ok(soMarker >= 2, `Chỉ thấy ${soMarker} lời gọi \`L.marker(\` — bộ trích có vẻ hỏng.`);
});
