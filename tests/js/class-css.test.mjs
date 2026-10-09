// ════════════════════════════════════════════════════════════════════════════
// Class CSS mà trang có thể sinh ra phải THỰC SỰ tồn tại trong stylesheet.
//
// Vì sao tệp này tồn tại — hai lỗi thật, cả hai đã ở trên production:
//
//   • `.b-gray` KHÔNG BAO GIỜ có trong `frontpage.css`, trong khi mười chỗ
//     trong `resources/views` tham chiếu nó. Thẻ trạng thái "Nháp", "Hoàn
//     thành", "Đã hoàn tiền" — và cả nhánh mặc định của mọi bảng màu — ra
//     `.badge` không nền, không màu chữ: hình viên thuốc mất nền, nằm cạnh các
//     thẻ có màu. `git log -S` cho thấy class đó chưa từng có trong CSS.
//
//   • `b-green` ở trang thanh toán, trong khi CSS chỉ có `.b-grn`. Đó là dấu
//     "Đã ghi nhận" cho từng media owner — đúng thứ người mua cần thấy để biết
//     mình đã chuyển tiền cho ai.
//
// Không một test nào đỏ vì hai lỗi đó. Các test jsdom có chọn `.badge`, nhưng
// không khẳng định class MÀU, và `tests/js/README.md` đã nói thẳng là CSS nằm
// ngoài tầm: "Một khối vẽ ra đúng mà bị display:none thì test vẫn xanh."
//
// Tệp này không mở rộng tầm đó ra tới cách trang HIỆN RA — jsdom không chạy
// layout, nên điều đó vẫn ngoài tầm. Nó đóng một khoảng hẹp hơn và kiểm được
// bằng văn bản: **tên class mà trang đặt vào DOM phải có người định nghĩa.**
// Một tên không có định nghĩa thì không bao giờ là cố ý.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';
import { MOC_DOM, layScriptHanhVi } from './bo-khung.mjs';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const TEP_CSS = 'resources/css/frontpage.css';

const TRANG = Object.keys(MOC_DOM).filter((k) => ! k.startsWith('//'));

// ── Tập class CSS định nghĩa ────────────────────────────────────────────────

/**
 * Mọi tên class có định nghĩa trong stylesheet.
 *
 * Bỏ comment và nội dung `url(...)` trước khi quét, vì `url(a.png)` sẽ cho một
 * "class" tên `png`. Dù vậy tập này vẫn có thể RỘNG hơn thực tế — nó không
 * phân biệt phần selector với phần thân. Đó là chiều sai an toàn: phép kiểm
 * dưới đây chỉ báo khi một class **không có ai định nghĩa**, nên tập rộng hơn
 * làm nó bỏ sót, không bao giờ làm nó báo oan.
 */
function classTrongCss() {
    const raw = readFileSync(resolve(GOC, TEP_CSS), 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/url\([^)]*\)/g, ' ');

    return new Set([...raw.matchAll(/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/g)].map((m) => m[1]));
}

const CO_CSS = classTrongCss();

// ── Trang có thể đặt những class nào vào DOM ───────────────────────────────

/** Tên class hợp lệ. Lọc bỏ mảnh Blade, dấu câu, chuỗi rỗng. */
function hopLe(t) {
    return /^[a-zA-Z][\w-]*$/.test(t);
}

function tach(chuoi, nguon, ra) {
    for (const t of String(chuoi).trim().split(/\s+/)) {
        if (hopLe(t) && ! ra.has(t)) ra.set(t, nguon);
    }
}

/**
 * Bảng tra cứu được dùng NGAY ở vị trí class.
 *
 * Mã thật có dạng `'<span class="badge ' + (MAU[ma] || 'b-gray') + '"'`. Tên
 * class không nằm trong một chuỗi `class="..."` hoàn chỉnh mà là GIÁ TRỊ của
 * một bảng tra — nên phải lần ra bảng đó rồi lấy mọi giá trị trong nó.
 *
 * Tìm theo cách dùng chứ không theo quy ước đặt tên: tên biến có thể là `MAU`,
 * `MAU_TRANG_THAI` hay `mau`, và một quy ước đặt tên là thứ người viết sau
 * không biết mà tuân.
 */
function bangOViTriClass(js) {
    return new Set(
        [...js.matchAll(/class="[^"]*?["']\s*\+\s*\(?\s*([A-Za-z_]\w*)\s*\[/g)].map((m) => m[1]),
    );
}

function khaiBaoBang(js, ten) {
    return js.match(new RegExp('var\\s+' + ten + '\\s*=\\s*\\{([\\s\\S]*?)\\}\\s*;'));
}

/** Class khối script hành vi có thể sinh ra. */
function classTuScript(js) {
    const ra = new Map();

    for (const m of js.matchAll(/class=(["'])([^"'`]*)/g)) tach(m[2], 'class="…"', ra);
    for (const m of js.matchAll(/classList\.(?:add|remove|toggle)\(\s*(['"])([^'"]+)\1/g)) tach(m[2], 'classList', ra);
    for (const m of js.matchAll(/className\s*=\s*(['"])([^'"]*)\1/g)) tach(m[2], 'className', ra);

    for (const ten of bangOViTriClass(js)) {
        const kh = khaiBaoBang(js, ten);

        if (! kh) continue; // Có test riêng bắt việc này — xem bên dưới.

        for (const v of kh[1].matchAll(/(['"])([^'"]+)\1/g)) tach(v[2], 'bảng ' + ten, ra);
    }

    return ra;
}

/**
 * Class trong markup Blade (phần ngoài khối script).
 *
 * Cắt bỏ biểu thức Blade trước khi tách: `class="badge {{ $x ? 'a' : 'b' }}"`
 * thì `{{ … }}` không phải tên class. Hệ quả là tên class NẰM TRONG biểu thức
 * Blade không được kiểm ở đây — bốn trang này không có chỗ nào như vậy (chúng
 * vẽ bằng JS), và phép kiểm `b-*` toàn repo ở cuối tệp phủ các trang còn lại.
 */
function classTuMarkup(raw) {
    const markup = raw
        .replace(/<script>\n[\s\S]*?\n<\/script>/g, ' ')
        .replace(/\{\{[\s\S]*?\}\}/g, ' ')
        .replace(/\{!![\s\S]*?!!\}/g, ' ');

    const ra = new Map();
    for (const m of markup.matchAll(/class="([^"]*)"/g)) tach(m[1], 'markup', ra);

    return ra;
}

function classCuaTrang(trang) {
    const ra = classTuScript(layScriptHanhVi(trang));

    for (const [t, nguon] of classTuMarkup(readFileSync(resolve(GOC, trang), 'utf8'))) {
        if (! ra.has(t)) ra.set(t, nguon);
    }

    return ra;
}

// ── Phép kiểm ───────────────────────────────────────────────────────────────

test('mọi class bốn trang có thể sinh ra đều có người định nghĩa trong CSS', () => {
    const thieu = [];

    for (const trang of TRANG) {
        for (const [t, nguon] of classCuaTrang(trang)) {
            if (! CO_CSS.has(t)) thieu.push(`${trang}: .${t} (từ ${nguon})`);
        }
    }

    assert.deepEqual(
        thieu,
        [],
        'Các class sau được đặt vào DOM nhưng không có định nghĩa nào trong '
        + `${TEP_CSS} — chúng ra DOM mà không có kiểu gì:\n  ` + thieu.join('\n  '),
    );
});

test('bảng màu dùng ở vị trí class phải tra được khai báo', () => {
    // Không có phép kiểm này thì một bảng đổi cách khai báo (ví dụ `const` thay
    // `var`, hay tách sang tệp khác) sẽ làm bộ trích lặng lẽ bỏ qua nó, và phép
    // kiểm trên vẫn xanh trong khi không còn canh thứ nó sinh ra để canh.
    const khongTra = [];

    for (const trang of TRANG) {
        const js = layScriptHanhVi(trang);

        for (const ten of bangOViTriClass(js)) {
            if (! khaiBaoBang(js, ten)) khongTra.push(`${trang}: ${ten}`);
        }
    }

    assert.deepEqual(khongTra, [], 'Không tìm được khai báo `var <tên> = { … };` cho: ' + khongTra.join(', '));
});

test('bộ trích không rỗng — một regex vỡ phải đỏ, không được xanh vì không tìm thấy gì', () => {
    assert.ok(CO_CSS.size > 500, `Chỉ đọc được ${CO_CSS.size} class từ ${TEP_CSS} — bộ đọc CSS đã vỡ`);

    let tong = 0;

    for (const trang of TRANG) {
        const n = classCuaTrang(trang).size;

        assert.ok(n > 0, `Không trích được class nào từ ${trang} — bộ trích đã vỡ`);
        tong += n;
    }

    assert.ok(tong >= 40, `Chỉ trích được ${tong} class trên cả bốn trang — bộ trích đã vỡ`);
});

test('mọi class b-* trong mọi tệp Blade đều có trong CSS', () => {
    // Rộng hơn bốn trang trên, vì `.b-gray` còn ở hai trang CHƯA chuyển sang
    // đọc API (`buyer/booking/creative`, `buyer/dashboard/index`) và ở đó nó
    // nằm trong biểu thức Blade. Thẻ trạng thái là chỗ các bản chép tụ lại, nên
    // tiền tố `b-` là chỗ đáng canh toàn bộ.
    const tep = [];

    (function quet(thuMuc) {
        for (const m of readdirSync(thuMuc, { withFileTypes: true })) {
            const d = join(thuMuc, m.name);

            if (m.isDirectory()) quet(d);
            else if (m.name.endsWith('.blade.php')) tep.push(d);
        }
    })(resolve(GOC, 'resources/views'));

    assert.ok(tep.length > 20, `Chỉ thấy ${tep.length} tệp blade — bộ quét thư mục đã vỡ`);

    const thieu = new Map();

    for (const d of tep) {
        for (const m of readFileSync(d, 'utf8').matchAll(/\bb-[a-z][a-z0-9-]*\b/g)) {
            if (! CO_CSS.has(m[0])) {
                if (! thieu.has(m[0])) thieu.set(m[0], []);
                if (! thieu.get(m[0]).includes(d)) thieu.get(m[0]).push(d);
            }
        }
    }

    assert.deepEqual(
        [...thieu.keys()],
        [],
        'Class thẻ trạng thái không có định nghĩa trong CSS:\n  '
        + [...thieu].map(([t, ds]) => `.${t} — ${ds.join(', ')}`).join('\n  '),
    );
});
