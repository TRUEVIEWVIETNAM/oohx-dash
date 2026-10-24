/**
 * Chặn một hình dạng lỗi đã lặp lại BỐN lần: `{a}/{b}` trong JSX.
 *
 * ══ Chuyện gì xảy ra ══
 *
 * React chèn `<!-- -->` giữa hai text node liền kề, để trình duyệt biết đâu là
 * biên giữa chúng khi hydrate. Nên `{m.trong}/{m.tong}` ra HTML là
 * `8<!-- -->/<!-- -->8`.
 *
 * Trên màn hình nó đọc đúng: "8/8". Nên đây **không** phải lỗi hiển thị, và đó
 * chính là lý do nó sống lâu — không ai thấy gì sai.
 *
 * Chỗ nó thành vấn đề: con số không còn là **một chuỗi liền** để phép kiểm
 * tìm. Một khối in `Th` rỗng, hoặc một dấu gạch không có số nào ở hai bên, vẫn
 * làm mọi chốt xanh. Với những con số nói "còn bao nhiêu ngày đặt được" hay
 * "đã chọn bao nhiêu trên tối đa", đó là mất khả năng canh đúng chỗ quan
 * trọng nhất.
 *
 * ══ Bốn chỗ đã gặp ══
 *
 *   1. `/explore/yeu-cau-bao-gia` — "103 trong 104 vị trí" (đã sửa trước)
 *   2. `LichGon` — "Th10" và "8/8" (soát production 24/10/2026)
 *   3. `LichTrong` — "Tháng 10/2026" (cùng lượt soát)
 *   4. `ThanhDanhSach` — "Đã chọn 3/50" (cùng lượt soát)
 *
 * Ba chỗ sau cùng được tìm ra bởi **một khẳng định `khong`** trong
 * `khoi-giao-dien.mjs`, chứ không bởi mắt người đọc. Lớp này đẩy phép kiểm đó
 * lên sớm hơn: nó không cần `next build`, và nó quét cả những tệp chưa có
 * trang nào trong bộ chốt render.
 *
 * ══ Giới hạn, nói rõ ══
 *
 * Chỉ bắt dạng **phân số** `}/{` — nơi hai con số kẹp một dấu gạch, và cũng là
 * nơi con số mang nghĩa. Các dạng text node liền kề khác (`{a} {b}`,
 * `{a}, {b}`) cũng sinh `<!-- -->` nhưng hiếm khi mang một con số cần canh, và
 * bắt hết sẽ ra quá nhiều báo giả để ai còn đọc nổi.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const GOC = new URL('../../webapp/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');

/** Mọi `.tsx` dưới `webapp/`, trừ thư mục sinh ra và phụ thuộc. */
function moiTsx(dir) {
    const bo = new Set(['node_modules', '.next', 'out', '.turbo']);
    const ra = [];

    for (const ten of readdirSync(dir)) {
        if (bo.has(ten)) continue;

        const duong = join(dir, ten);
        if (statSync(duong).isDirectory()) {
            ra.push(...moiTsx(duong));
        } else if (ten.endsWith('.tsx')) {
            ra.push(duong);
        }
    }

    return ra;
}

test('không tệp .tsx nào còn dạng {a}/{b} trong JSX', () => {
    const tep = moiTsx(GOC);
    assert.ok(tep.length > 10, `chỉ quét được ${tep.length} tệp — đường dẫn gốc sai?`);

    const viPham = [];

    for (const t of tep) {
        // Bỏ chú thích trước khi quét: ba tệp đã sửa đều giải thích chỗ sai
        // bằng cách viết lại nguyên văn dạng sai đó.
        const ma = readFileSync(t, 'utf8')
            .replace(/\{\/\*[\s\S]*?\*\/\}/g, '')
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/\/\/.*$/gm, '');

        const dong = ma.split('\n');
        for (let i = 0; i < dong.length; i++) {
            if (/\}\/\{/.test(dong[i])) {
                viPham.push(`${t.slice(GOC.length)}:${i + 1}  ${dong[i].trim()}`);
            }
        }
    }

    assert.deepEqual(
        viPham,
        [],
        'React sẽ chèn <!-- --> vào giữa — ghép bằng template string:\n  ' +
            viPham.join('\n  '),
    );
});

test('ba chỗ đã sửa dùng template string', () => {
    // Canh đúng ba chỗ, để một lần "dọn dẹp" sau này không lặng lẽ trả chúng
    // về dạng cũ. Lớp quét ở trên bắt được, nhưng ca này nói ra TÊN chỗ sai.
    const can = [
        ['components/LichGon.tsx', '{`${m.trong}/${m.tong}`}'],
        ['components/LichTrong.tsx', '{`Tháng ${m.thang}/${m.nam}`}'],
        ['components/ThanhDanhSach.tsx', '{`Đã chọn ${dem.viTri}/${TOI_DA}`}'],
    ];

    for (const [tep, mong] of can) {
        const ma = readFileSync(join(GOC, tep), 'utf8');
        assert.ok(ma.includes(mong), `${tep} thiếu: ${mong}`);
    }
});
