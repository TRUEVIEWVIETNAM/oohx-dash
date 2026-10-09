// ════════════════════════════════════════════════════════════════════════════
// Tên trường API mà trang ĐỌC phải có trong `docs/openapi/v2.yaml`.
//
// ══ Vì sao trục này cần một chốt riêng ══
//
// Các ca jsdom dựng phản hồi API bằng **dữ liệu mẫu viết tay**. Nếu DTO đổi
// tên một trường — `status_label` thành `statusLabel`, hay bỏ `method_label` —
// thì dữ liệu mẫu vẫn mang tên cũ, mọi ca vẫn xanh, và trang thật đọc
// `undefined`. Trên trang tiền của người mua, `undefined` không nổ: nó rơi về
// nhánh `|| p.status` và hiện một mã CSDL.
//
// Đó đúng hình dạng của lỗi `.b-gray` ở một tầng khác: hai bên đồng ý với nhau
// trong test, nhưng không ai đối chiếu với nguồn sự thật.
//
// `docs/openapi/v2.yaml` là nguồn sự thật đó (CLAUDE.md mục 2), và Next.js
// sinh TypeScript từ nó. Nên một trường trang đọc mà đặc tả không khai nghĩa
// là **một trong hai bên sai**, và cả hai hướng đều đáng đỏ:
//
//   - đặc tả cũ: TypeScript sinh ra thiếu trường, bên tiêu thụ sau dùng sai;
//   - trang sai: nó đọc một trường không ai trả.
//
// ══ snake_case là phép lọc ══
//
// API đặt tên bằng snake_case; biến cục bộ trong các khối script này là
// camelCase hoặc tiếng Việt không dấu. Nên lọc theo snake_case cho tín hiệu
// sạch, thay vì cố đoán biến nào đến từ JSON.
//
// ══ Bỏ chú thích là phần KHÔNG được bỏ qua ══
//
// Bản khảo sát đầu tiên báo `refund_tiers` thiếu trong đặc tả. Nó nằm trong
// một dòng chú thích — `config('pricing.refund_tiers')` — không phải mã. Một
// chốt báo oan thì sẽ bị tắt, nên bộ bỏ chú thích ở đây là một máy trạng thái
// thật, và có ca riêng canh nó.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { MOC_DOM, layScriptHanhVi } from './bo-khung.mjs';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const TEP_DAC_TA = 'docs/openapi/v2.yaml';

const TRANG = Object.keys(MOC_DOM).filter((k) => ! k.startsWith('//'));

/**
 * Bỏ chú thích JS, giữ nguyên nội dung chuỗi **và biểu thức chính quy**.
 *
 * Máy trạng thái thật chứ không một cặp `replace`: `'https://oohx.net'` có
 * `//` bên trong một chuỗi, và một phép thay thế thô sẽ cắt mất phần còn lại
 * của dòng.
 *
 * ══ Regex literal là chỗ bản đầu sai ══
 *
 * Bản đầu chỉ theo dõi ba kiểu nháy. Nhưng mọi trang này đều có hàm thoát HTML:
 *
 *     .replace(/'/g, '&#39;')
 *
 * `/'/g` là một **regex literal** chứa dấu nháy đơn. Máy không biết điều đó
 * nên nó mở trạng thái chuỗi tại dấu nháy ấy, rồi đóng/mở lệch nhịp ở các dấu
 * nháy sau — và từ đó nó coi phần còn lại của tệp là nội dung chuỗi, nên
 * **không bỏ chú thích nào nữa**.
 *
 * Hậu quả đo được: chốt báo `refund_tiers` thiếu trong đặc tả, trong khi tên
 * đó chỉ nằm trong một docblock (`config('pricing.refund_tiers')`). Tôi đã
 * tưởng đó là báo oan của phép lọc snake_case; nó là một lỗi phân tích.
 *
 * ══ Phân biệt chia với regex ══
 *
 * Không thể biết chắc mà không phân tích cú pháp đầy đủ, nên dùng phép đoán
 * quen dùng: `/` mở regex khi ký tự có nghĩa trước nó cho phép một toán hạng
 * đứng sau. Sau một tên, một số hay `)` thì `/` là phép chia.
 */
export function boChuThich(js) {
    const MO_REGEX = new Set(['(', ',', '=', ':', '[', '!', '&', '|', '?', '{', '}', ';', '+', '-', '*', '%', '~', '^', '<', '>', null]);

    let ra = '';
    let i = 0;
    let nhay = null; // ký tự nháy đang mở, hoặc null
    let trongChuThich = null; // 'dong' | 'khoi' | null
    let truoc = null; // ký tự có nghĩa gần nhất đã phát ra

    while (i < js.length) {
        const c = js[i];
        const d = js[i + 1];

        if (trongChuThich === 'dong') {
            if (c === '\n') { trongChuThich = null; ra += c; }
            i++;
            continue;
        }

        if (trongChuThich === 'khoi') {
            if (c === '*' && d === '/') { trongChuThich = null; i += 2; continue; }
            // Giữ dòng mới để số dòng không trôi khi cần đọc lỗi.
            if (c === '\n') ra += c;
            i++;
            continue;
        }

        if (nhay) {
            ra += c;
            if (c === '\\') { ra += js[i + 1] ?? ''; i += 2; continue; }
            if (c === nhay) { nhay = null; truoc = c; }
            i++;
            continue;
        }

        if (c === '"' || c === "'" || c === '`') { nhay = c; ra += c; truoc = c; i++; continue; }
        if (c === '/' && d === '/') { trongChuThich = 'dong'; i += 2; continue; }
        if (c === '/' && d === '*') { trongChuThich = 'khoi'; i += 2; continue; }

        // Regex literal: đi tới dấu `/` đóng, bỏ qua escape và lớp `[...]`.
        if (c === '/' && MO_REGEX.has(truoc)) {
            ra += c;
            i++;

            let trongLop = false;

            while (i < js.length) {
                const r = js[i];

                ra += r;

                if (r === '\\') { ra += js[i + 1] ?? ''; i += 2; continue; }
                if (r === '[') trongLop = true;
                else if (r === ']') trongLop = false;
                else if (r === '/' && ! trongLop) { i++; break; }
                else if (r === '\n') { i++; break; } // regex không qua dòng — mã vỡ, dừng cho an toàn

                i++;
            }

            truoc = '/';
            continue;
        }

        ra += c;
        if (! /\s/.test(c)) truoc = c;
        i++;
    }

    return ra;
}

/** Tên trường snake_case mà script đọc từ phản hồi. */
function truongDoc(trang) {
    const js = boChuThich(layScriptHanhVi(trang));
    const ra = new Set();

    for (const m of js.matchAll(/\.([a-z][a-z0-9]*(?:_[a-z0-9]+)+)\b/g)) ra.add(m[1]);
    for (const m of js.matchAll(/\[\s*'([a-z][a-z0-9]*(?:_[a-z0-9]+)+)'\s*\]/g)) ra.add(m[1]);

    return ra;
}

const DAC_TA = readFileSync(resolve(GOC, TEP_DAC_TA), 'utf8');

// ── Phép kiểm ───────────────────────────────────────────────────────────────

test('mọi trường snake_case trang đọc đều có trong đặc tả OpenAPI', () => {
    const thieu = [];

    for (const trang of TRANG) {
        for (const t of truongDoc(trang)) {
            if (! new RegExp('\\b' + t + '\\b').test(DAC_TA)) {
                thieu.push(`${trang}: ${t}`);
            }
        }
    }

    assert.deepEqual(
        thieu,
        [],
        'Trang đọc những trường sau mà `' + TEP_DAC_TA + '` không khai. Một trong\n'
        + 'hai bên sai: hoặc đặc tả cũ (và TypeScript sinh ra thiếu trường),\n'
        + 'hoặc trang đọc một trường không ai trả:\n  ' + thieu.join('\n  '),
    );
});

test('bộ trích không rỗng — một regex vỡ phải đỏ, không xanh vì không tìm thấy gì', () => {
    assert.ok(TRANG.length >= 6, `chỉ thấy ${TRANG.length} trang đọc API`);
    assert.ok(DAC_TA.length > 10_000, 'không đọc được đặc tả OpenAPI');

    let tong = 0;

    for (const trang of TRANG) {
        const n = truongDoc(trang).size;

        assert.ok(n > 0, `không trích được trường nào từ ${trang}`);
        tong += n;
    }

    assert.ok(tong >= 60, `chỉ trích được ${tong} trường trên cả sáu trang — bộ trích đã vỡ`);
});

/**
 * Bộ bỏ chú thích phải đúng, vì nó quyết định chốt này có báo oan hay không.
 *
 * Ba ca trong một: `//` bên trong chuỗi phải được giữ, chú thích dòng và khối
 * phải mất, và tên trường trong chú thích không được tính. Bản khảo sát đầu
 * tiên không có bước này và nó báo oan `refund_tiers` — một tên nằm trong câu
 * `config('pricing.refund_tiers')` của một docblock.
 */
test('bỏ chú thích giữ nguyên chuỗi và không ăn mã', () => {
    const vao = [
        "var u = 'https://oohx.net/api/v2'; // đọc cf.khong_co_that",
        '/* khối: p.cung_khong_co_that */',
        'var x = a.co_that;',
        'var y = "chuỗi có /* không phải chú thích */ bên trong";',
    ].join('\n');

    const ra = boChuThich(vao);

    assert.match(ra, /https:\/\/oohx\.net\/api\/v2/, '`//` trong chuỗi phải được giữ');
    assert.match(ra, /a\.co_that/, 'mã phải còn');
    assert.match(ra, /\/\* không phải chú thích \*\//, 'chuỗi chứa dấu chú thích phải nguyên');
    assert.doesNotMatch(ra, /khong_co_that/, 'chú thích dòng phải mất');
    assert.doesNotMatch(ra, /cung_khong_co_that/, 'chú thích khối phải mất');
});

/**
 * Regex literal chứa dấu nháy — hình dạng đã làm bản đầu sai.
 *
 * Mọi trang này đều có hàm thoát HTML với `.replace(/'/g, '&#39;')`. Bản đầu
 * của bộ bỏ chú thích mở trạng thái chuỗi tại dấu nháy **bên trong regex**,
 * rồi lệch nhịp ở các dấu nháy sau và thôi bỏ chú thích từ đó. Hậu quả đo
 * được: chốt báo một tên nằm trong docblock là "thiếu trong đặc tả".
 */
test('regex literal chứa dấu nháy không làm lệch trạng thái chuỗi', () => {
    const vao = [
        "function chu(s) { return s.replace(/'/g, '&#39;').replace(/\"/g, '&quot;'); }",
        '/** docblock: config(\'pricing.ten_trong_chu_thich\') */',
        'var x = p.ten_trong_ma;',
    ].join('\n');

    const ra = boChuThich(vao);

    assert.match(ra, /ten_trong_ma/, 'mã sau regex phải còn');
    assert.doesNotMatch(
        ra,
        /ten_trong_chu_thich/,
        'docblock sau một regex chứa dấu nháy vẫn phải bị bỏ',
    );

    // Phép chia vẫn là phép chia: `a / b` không được hiểu là mở regex.
    const chia = boChuThich('var t = a / b; // ten_bi_bo\nvar u = c.ten_con_lai;');

    assert.match(chia, /a \/ b/);
    assert.match(chia, /ten_con_lai/);
    assert.doesNotMatch(chia, /ten_bi_bo/);
});

/**
 * Chốt này phải THẤY được một trường bịa.
 *
 * Không có ca này thì một lỗi trong bộ trích biến phép kiểm trên thành một ca
 * luôn xanh, và nó sẽ xanh suốt — vì tập thiếu rỗng là kết quả mong đợi.
 */
test('một trường không có trong đặc tả thì bị bắt', () => {
    const js = "var x = p.truong_khong_bao_gio_co_trong_dac_ta;";
    const ds = [...boChuThich(js).matchAll(/\.([a-z][a-z0-9]*(?:_[a-z0-9]+)+)\b/g)].map((m) => m[1]);

    assert.deepEqual(ds, ['truong_khong_bao_gio_co_trong_dac_ta']);
    assert.ok(
        ! new RegExp('\\b' + ds[0] + '\\b').test(DAC_TA),
        'tên bịa này không được tình cờ có trong đặc tả',
    );
});
