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
import { existsSync, readFileSync, readdirSync } from 'node:fs';
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

// ════════════════════════════════════════════════════════════════════════════
// Phạm vi rộng: MỌI class của MỌI tệp Blade hiện ra dưới `frontpage.css`
//
// Bốn phép kiểm trên để lại một khoảng trống: chúng canh đủ mọi class cho BỐN
// trang đọc API, còn mọi tệp Blade khác thì chỉ canh tiền tố `b-*`. Bảy trang
// người mua còn lại, layout, năm partial và bốn thân trang chính sách chưa có
// ai canh phần class không phải `b-*`.
//
// Khoảng đó không phải giả thiết: lần quét tay đầu tiên trên nhóm này tìm ra
// `buyer/dashboard/index.blade.php` in thẳng `{{ $c->status }}` (tám mã trạng
// thái ra nguyên văn tiếng Anh) — không phải lỗi class, nhưng tìm ra vì bộ quét
// trả về một token không có định nghĩa.
//
// ── Vì sao một stylesheet, không phải hai ──
//
// `webapp/app/globals.css` chỉ có một dòng thật: `@import
// '../../resources/css/frontpage.css'`. Trang Next và trang Blade dùng **cùng**
// stylesheet, nên HTML mà Laravel sinh ra rồi Next nhét vào
// `dangerouslySetInnerHTML` (thân bốn trang chính sách, qua `body_html` của
// `PublicContentController::policy`) cũng thuộc phạm vi này.
//
// ── Phạm vi tìm bằng THAM CHIẾU, không bằng thư mục ──
//
// Một danh sách đường dẫn viết cứng sẽ mục: thêm trang mới thì nó không tự
// vào. Nên phạm vi được lần ra: layout nào `@vite` frontpage.css → trang nào
// `@extends` layout đó → view nào được `config/policies.php` dựng thành
// `body_html` → rồi đệ quy theo `@include`.
//
// ── Những gì CỐ Ý nằm ngoài ──
//
//   • `resources/views/filament/**` — khu quản trị dùng CSS riêng của Filament.
//   • `resources/views/vendor/pagination/**` — bản mặc định của Laravel
//     (Bootstrap/Tailwind), không phải mã của dự án.
//   • `welcome.blade.php` — dùng `app.css`, tức Tailwind. Ở đó "có định nghĩa"
//     nghĩa là *sinh ra theo yêu cầu*, nên một phép kiểm tĩnh không nói được gì.
//   • `invitations/**` — mỗi trang có khối `<style>` riêng trong chính nó.
// ════════════════════════════════════════════════════════════════════════════

const THU_MUC_VIEW = 'resources/views';

function moiTepBlade() {
    const ra = [];

    (function quet(thuMuc) {
        for (const m of readdirSync(thuMuc, { withFileTypes: true })) {
            const d = join(thuMuc, m.name);

            if (m.isDirectory()) quet(d);
            else if (m.name.endsWith('.blade.php')) ra.push(d);
        }
    })(resolve(GOC, THU_MUC_VIEW));

    return ra;
}

/** `resources/views/a/b/c.blade.php` → `a.b.c` */
function tenView(duongDanTuyetDoi) {
    return duongDanTuyetDoi
        .slice(resolve(GOC, THU_MUC_VIEW).length + 1)
        .replace(/\.blade\.php$/, '')
        .split(/[\\/]/)
        .join('.');
}

/** `a.b.c` → đường dẫn tuyệt đối */
function duongDanView(ten) {
    return resolve(GOC, THU_MUC_VIEW, ...ten.split('.')) + '.blade.php';
}

/**
 * Mọi tệp Blade mà đầu ra của nó hiện ra dưới `frontpage.css`.
 *
 * Trả về `{ tep, layout }` — `layout` để phép kiểm chống-rỗng khẳng định được
 * là đã thật sự tìm thấy điểm vào, chứ không phải tìm thấy số không.
 */
function tepTrongPhamViFrontpageCss() {
    const tatCa = moiTepBlade();
    const noiDung = new Map(tatCa.map((d) => [d, readFileSync(d, 'utf8')]));

    // 1) Layout nào nạp chính stylesheet này.
    // Nhận cả hai cách gọi — `@vite(['…css', '…js'])` và `@vite('…css')` — vì
    // đổi giữa hai dạng đó là một lần sửa hợp lệ, và một phạm vi co về rỗng vì
    // dấu ngoặc vuông thì tệ hơn là không có phép kiểm.
    const layout = tatCa.filter((d) => /@vite\([^)]*resources\/css\/frontpage\.css/.test(noiDung.get(d)));

    const canQuet = new Set(layout);

    // 2) Trang nào `@extends` một trong các layout đó.
    for (const l of layout) {
        const ten = tenView(l);

        for (const d of tatCa) {
            if (noiDung.get(d).includes(`@extends('${ten}'`)) canQuet.add(d);
        }
    }

    // 3) View nào được dựng thành `body_html` cho trang Next.
    //
    //    Đọc `config/policies.php` bằng biểu thức chính quy chứ không chạy PHP.
    //    Một tên ở đây mà không có tệp là LỖI, không phải trường hợp bỏ qua:
    //    `view()` của Laravel sẽ nổ khi ai đó mở trang chính sách đó.
    const config = readFileSync(resolve(GOC, 'config/policies.php'), 'utf8');
    const tenThan = [...config.matchAll(/'body'\s*=>\s*'([^']+)'/g)].map((m) => m[1]);

    for (const ten of tenThan) {
        const d = duongDanView(ten);

        if (! existsSync(d)) {
            throw new Error(
                `config/policies.php trỏ 'body' => '${ten}' nhưng không có tệp ${d}. `
                + 'Trang chính sách đó sẽ nổ khi có người mở.',
            );
        }

        canQuet.add(d);
    }

    // 4) Đệ quy theo `@include`.
    for (const d of [...canQuet]) {
        (function theoInclude(tep) {
            for (const m of readFileSync(tep, 'utf8').matchAll(/@include\('([^']+)'/g)) {
                const con = duongDanView(m[1]);

                if (! existsSync(con)) {
                    throw new Error(`${tep} @include('${m[1]}') nhưng không có tệp ${con}.`);
                }

                if (! canQuet.has(con)) {
                    canQuet.add(con);
                    theoInclude(con);
                }
            }
        })(d);
    }

    return { tep: [...canQuet].sort(), layout };
}

/**
 * Chuỗi nháy trong một biểu thức Blade, BỎ các toán hạng so sánh.
 *
 * Lấy chuỗi bên trong `{{ … }}` là cách duy nhất thấy được tên class nằm trong
 * biểu thức — `{{ $x ? 'b-grn' : 'b-gray' }}` không có tên class nào ở ngoài.
 * Nhưng cùng chỗ đó cũng có chuỗi KHÔNG phải tên class: toán hạng của phép so
 * sánh. `{{ $c->status === 'pending_approval' ? … }}` làm bộ quét tay báo oan
 * đúng như vậy.
 *
 * Nên bỏ toán hạng so sánh trước khi lấy. Hướng này an toàn: một tên class
 * không bao giờ là toán hạng của `===`, nên không mất thứ cần canh; còn giữ
 * chúng lại thì test đỏ vì lý do sai, và một test báo oan thì sẽ bị tắt.
 */
function chuoiTrongBieuThuc(bieuThuc) {
    const sach = bieuThuc
        .replace(/in_array\([^)]*\)/g, ' ')
        .replace(/(?:===|!==|==|!=)\s*(['"])[^'"]*\1/g, ' ')
        .replace(/(['"])[^'"]*\1\s*(?:===|!==|==|!=)/g, ' ');

    return [...sach.matchAll(/(['"])([^'"]*)\1/g)].map((m) => m[2]);
}

/**
 * Mọi tên class một tệp Blade có thể đặt vào DOM.
 *
 * Chạy trên toàn văn tệp, không tách markup với `<script>`: `class="…"` trong
 * chuỗi JS cũng là một thuộc tính class, và cùng một biểu thức bắt được cả hai.
 */
function classCuaTepBlade(raw) {
    const ra = new Map();

    // class="…" — kể cả khi bên trong có biểu thức Blade hay nối chuỗi JS.
    for (const m of raw.matchAll(/class="([^"]*)"/g)) {
        for (const b of m[1].matchAll(/\{\{[\s\S]*?\}\}|\{!![\s\S]*?!!\}/g)) {
            for (const s of chuoiTrongBieuThuc(b[0])) tach(s, 'biểu thức Blade', ra);
        }

        tach(
            m[1].replace(/\{\{[\s\S]*?\}\}/g, ' ').replace(/\{!![\s\S]*?!!\}/g, ' '),
            'markup',
            ra,
        );
    }

    // class='…' — dạng JS hay dùng khi dựng HTML bằng chuỗi nháy kép bọc ngoài.
    for (const m of raw.matchAll(/class='([^']*)'/g)) {
        tach(m[1].replace(/\$\{[\s\S]*?\}/g, ' '), "class='…'", ra);
    }

    for (const m of raw.matchAll(/classList\.(?:add|remove|toggle)\(([^)]*)\)/g)) {
        for (const s of m[1].matchAll(/(['"])([^'"]+)\1/g)) tach(s[2], 'classList', ra);
    }
    for (const m of raw.matchAll(/className\s*=\s*(['"`])([^'"`]*)\1/g)) tach(m[2], 'className', ra);

    // Bảng tra cứu dùng NGAY ở vị trí class — bản JS và bản PHP.
    for (const ten of bangOViTriClass(raw)) {
        const kh = khaiBaoBang(raw, ten);

        if (kh) {
            for (const v of kh[1].matchAll(/(['"])([^'"]+)\1/g)) tach(v[2], 'bảng JS ' + ten, ra);
        }
    }

    for (const m of raw.matchAll(/class="[^"]*?\{\{\s*\$([A-Za-z_]\w*)\s*\[/g)) {
        const kh = raw.match(new RegExp('\\$' + m[1] + '\\s*=\\s*\\[([\\s\\S]*?)\\]\\s*;'));

        if (kh) {
            for (const v of kh[1].matchAll(/(['"])([^'"]+)\1/g)) tach(v[2], 'bảng PHP $' + m[1], ra);
        }
    }

    return ra;
}

const PHAM_VI = tepTrongPhamViFrontpageCss();

test('mọi class của mọi tệp Blade dùng frontpage.css đều có người định nghĩa', () => {
    const thieu = [];

    for (const d of PHAM_VI.tep) {
        for (const [t, nguon] of classCuaTepBlade(readFileSync(d, 'utf8'))) {
            if (! CO_CSS.has(t)) thieu.push(`${tenView(d)}: .${t} (từ ${nguon})`);
        }
    }

    assert.deepEqual(
        thieu,
        [],
        `Các class sau ra DOM mà không có định nghĩa nào trong ${TEP_CSS}:\n  `
        + thieu.join('\n  '),
    );
});

test('phạm vi quét không rỗng — lần theo tham chiếu phải ra đúng nhóm tệp', () => {
    // Không có phép kiểm này thì một `@vite` đổi cách viết, một `@extends` đổi
    // tên layout, hay một lần đổi thư mục view sẽ làm phạm vi co về rỗng — và
    // phép kiểm trên xanh vì không quét gì cả.
    assert.ok(
        PHAM_VI.layout.length >= 1,
        'Không tìm thấy layout nào @vite resources/css/frontpage.css — '
        + 'bộ lần theo tham chiếu đã vỡ',
    );

    assert.ok(
        PHAM_VI.tep.length >= 15,
        `Phạm vi chỉ có ${PHAM_VI.tep.length} tệp — chờ ít nhất 15 `
        + '(11 trang người mua + layout + partial + thân chính sách)',
    );

    let tong = 0;

    for (const d of PHAM_VI.tep) tong += classCuaTepBlade(readFileSync(d, 'utf8')).size;

    assert.ok(tong >= 250, `Chỉ trích được ${tong} class trên cả nhóm — bộ trích đã vỡ`);
});

test('toán hạng so sánh không bị nhận là tên class', () => {
    // Phép kiểm này canh chính bộ trích, không canh trang nào. Lấy chuỗi bên
    // trong biểu thức Blade là cách duy nhất thấy được `b-gray` nằm trong một
    // ternary, nhưng nếu lấy cả toán hạng so sánh thì mọi trang có
    // `{{ $x === 'abc' ? … }}` ở vị trí class sẽ đỏ vì lý do sai.
    const ra = classCuaTepBlade(
        `<span class="badge {{ $c->status === 'pending_approval' ? 'b-org' : 'b-gray' }}">x</span>`,
    );

    assert.deepEqual(
        [...ra.keys()].sort(),
        ['b-gray', 'b-org', 'badge'],
        'Bộ trích phải lấy hai nhánh màu và bỏ toán hạng so sánh',
    );
});
