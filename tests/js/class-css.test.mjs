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
    // Rộng hơn các trang đọc API ở trên, vì `.b-gray` còn ở những trang CHƯA
    // chuyển (`buyer/dashboard/index` và các trang Filament) và ở đó nó nằm
    // trong biểu thức Blade. Thẻ trạng thái là chỗ các bản chép tụ lại, nên
    // tiền tố `b-` là chỗ đáng canh toàn bộ.
    //
    // Phép quét này KHÔNG bỏ chú thích, và đó là cố ý: bỏ chú thích nghĩa là
    // phải hiểu bốn kiểu chú thích (`{{-- --}}`, `<!-- -->`, `//`, `/* */`)
    // lồng nhau trong cùng một tệp, tức một bộ phân tích cú pháp nữa để sai.
    // Giá phải trả là không được nhắc tên một class chết trong chú thích —
    // `buyer/booking/payment-success` ghi lại đúng rào đó ở chỗ nó gặp.
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

// ════════════════════════════════════════════════════════════════════════════
// Trang Next — cùng stylesheet, trước đây không ai canh
//
// `webapp/app/globals.css` chỉ `@import` chính `frontpage.css`, nên mọi tên
// class trong `webapp/` chịu đúng một ràng buộc như Blade: **đặt vào DOM thì
// phải có người định nghĩa.** Nhưng mọi phép kiểm phía trên lấy phạm vi từ
// `resources/views`, nên trang Next nằm ngoài — và hai lỗi thật đã sống sót
// qua mọi lượt CI vì thế:
//
//   • Thẻ owner ở `/owners` và trang chủ dùng SÁU tên class không ai định
//     nghĩa: `oc`, `oc-cover`, `oc-body`, `oc-name`, `oc-stats`, `oc-type`.
//     Thẻ ra DOM mà không có một kiểu nào, kể cả khung. Bộ đúng là `oc-card-*`
//     — và `frontpage.css` dòng 1836 còn ghi đúng điều đó trong một chú thích:
//     "Old .owners-layout / .oc removed — replaced by .oc-card system above".
//
//   • Thẻ màn hình và thẻ gói mở bằng `className="sc"`, trong khi `.sc` là một
//     ô bảng thông số và thẻ màn hình là `.sc-card`.
//
// Lỗi thứ hai **không** bị phép kiểm này bắt: cả hai tên đều có định nghĩa.
// Nó được canh ở `webapp/test/khoi-giao-dien.mjs`, trên HTML đã render. Ghi ở
// đây để người đọc không tin rằng tệp này phủ cả hai.
// ════════════════════════════════════════════════════════════════════════════

const THU_MUC_NEXT = ['webapp/app', 'webapp/components'];

function moiTepTsx() {
    const ra = [];

    for (const goc of THU_MUC_NEXT) {
        (function quet(thuMuc) {
            for (const m of readdirSync(thuMuc, { withFileTypes: true })) {
                const d = join(thuMuc, m.name);

                // `.next/` là đầu ra của build, không phải mã nguồn.
                if (m.isDirectory() && m.name !== '.next' && m.name !== 'node_modules') quet(d);
                else if (m.isFile() && /\.tsx$/.test(m.name)) ra.push(d);
            }
        })(resolve(GOC, goc));
    }

    return ra.sort();
}

/**
 * Phần trong `{…}` ngay sau một vị trí, cắt theo ngoặc **khớp cặp**.
 *
 * Không dùng biểu thức chính quy cho việc này. `className={`a ${x ? 'b' : ''}`}`
 * có ngoặc lồng, và một regex không tham sẽ dừng ở `}` của `${…}` — bản đầu của
 * bộ trích này làm đúng vậy và bỏ sót mọi className dạng template. Lỗi đó không
 * làm test đỏ: nó làm test **xanh vì không thấy gì**, nên phải đếm ngoặc thật.
 *
 * Trả `null` khi không khớp cặp — tệp vỡ cú pháp thì để `tsc` nói, không đoán.
 */
function trongNgoac(raw, batDau) {
    let sau = 0;

    for (let i = batDau; i < raw.length; i++) {
        const c = raw[i];

        if (c === '{') sau++;
        else if (c === '}') {
            sau--;

            if (sau === 0) return raw.slice(batDau + 1, i);
        }
    }

    return null;
}

/**
 * Mọi tên class một tệp TSX có thể đặt vào DOM.
 *
 * Ba dạng, vì cả ba đều có trong `webapp/`:
 *
 *   className="sc-card"                     — chuỗi thuần
 *   className={`sc-card ${x ? 'a' : 'b'}`}  — template, có phần tĩnh
 *   className={dk ? 'a b' : 'c'}            — chuỗi trong biểu thức
 *
 * Dạng template có tên class **ngoài** dấu nháy (`sc-card` ở trên), nên phải
 * lấy riêng: cắt `${…}` đi rồi tách phần văn bản còn lại. Phần bên trong `${…}`
 * thì lấy như một biểu thức — mọi chuỗi nháy, bỏ toán hạng so sánh, cùng lý do
 * như bên Blade: `x === 'package'` không phải một tên class.
 */
function classCuaTepTsx(raw) {
    const ra = new Map();

    for (const m of raw.matchAll(/className=(["'])([^"'{}]*)\1/g)) tach(m[2], 'className="…"', ra);

    for (const m of raw.matchAll(/className=(?=\{)/g)) {
        const bieuThuc = trongNgoac(raw, m.index + m[0].length);

        if (bieuThuc === null) continue;

        // Phần tĩnh của mọi template literal trong biểu thức.
        for (const t of bieuThuc.matchAll(/`([\s\S]*?)`/g)) {
            tach(t[1].replace(/\$\{[\s\S]*?\}/g, ' '), 'className={`…`}', ra);
        }

        for (const s of chuoiTrongBieuThuc(bieuThuc.replace(/`[\s\S]*?`/g, ' '))) {
            tach(s, 'className={…}', ra);
        }

        // Chuỗi nháy nằm TRONG `${…}` của template — `${full ? '' : 'x'}`.
        for (const t of bieuThuc.matchAll(/\$\{([\s\S]*?)\}/g)) {
            for (const s of chuoiTrongBieuThuc(t[1])) tach(s, 'className={`${…}`}', ra);
        }
    }

    for (const m of raw.matchAll(/classList\.(?:add|remove|toggle)\(([^)]*)\)/g)) {
        for (const s of m[1].matchAll(/(['"])([^'"]+)\1/g)) tach(s[2], 'classList', ra);
    }

    return ra;
}

const TEP_TSX = moiTepTsx();

/**
 * Một tên class có người định nghĩa.
 *
 * Token kết thúc bằng `-` là **tiền tố**, không phải tên đầy đủ: nó là phần
 * còn lại sau khi cắt `${…}` khỏi một template, ví dụ
 * `` `rfl-badge rfl-badge--${r.status}` `` để lại `rfl-badge--`. Với dạng đó,
 * điều kiểm được là **có ít nhất một** class trong CSS mang tiền tố ấy — đủ để
 * bắt một tiền tố viết sai (`rfl-bdge--`), và không báo oan cho một hậu tố mà
 * chỉ thời gian chạy mới biết.
 *
 * Giữ nguyên mức này thay vì liệt kê các hậu tố có thể: danh sách hậu tố nằm
 * ở phía API (`reflections.status`), và chép nó vào đây là tạo bản thứ hai của
 * một enum — đúng thứ `NhanEnumMotNoiTest` tồn tại để chống.
 */
function coNguoiDinhNghia(t) {
    if (CO_CSS.has(t)) return true;

    if (t.endsWith('-')) {
        for (const c of CO_CSS) if (c.startsWith(t) && c.length > t.length) return true;
    }

    return false;
}

test('mọi class của mọi trang Next đều có người định nghĩa trong CSS', () => {
    const thieu = [];

    for (const d of TEP_TSX) {
        for (const [t, nguon] of classCuaTepTsx(readFileSync(d, 'utf8'))) {
            if (! coNguoiDinhNghia(t)) {
                thieu.push(`${d.slice(resolve(GOC).length + 1)}: .${t} (từ ${nguon})`);
            }
        }
    }

    assert.deepEqual(
        thieu,
        [],
        `Các class sau ra DOM từ trang Next mà không có định nghĩa nào trong ${TEP_CSS}:\n  `
        + thieu.join('\n  '),
    );
});

test('phạm vi Next không rỗng — đổi thư mục hay đổi cú pháp phải đỏ', () => {
    // Không có phép kiểm này thì đổi `webapp/app` sang một tên khác, hay đổi
    // cách viết className, sẽ làm phạm vi co về rỗng — và phép kiểm trên xanh
    // vì không quét gì cả. Đó đúng là tình trạng của tệp này trước 14/10/2026,
    // chỉ khác là phạm vi rỗng vì chưa ai viết nó.
    assert.ok(TEP_TSX.length >= 15, `Chỉ thấy ${TEP_TSX.length} tệp .tsx — bộ quét thư mục đã vỡ`);

    let tong = 0;

    for (const d of TEP_TSX) tong += classCuaTepTsx(readFileSync(d, 'utf8')).size;

    assert.ok(tong >= 80, `Chỉ trích được ${tong} class trên cả nhóm Next — bộ trích đã vỡ`);
});

test('bộ trích TSX đọc được cả ba dạng className', () => {
    // Canh chính bộ trích. Dạng template và dạng biểu thức là nơi tên class dễ
    // lọt: một bộ trích chỉ hiểu `className="…"` sẽ bỏ qua chúng và vẫn xanh.
    const ra = classCuaTepTsx(`
        <div className="sc-card" />
        <div className={\`oc-card \${full ? '' : 'oc-card--compact'}\`} />
        <div className={loai === 'package' ? 'pd-badge' : 'pd-badge-cat'} />
    `);

    assert.deepEqual(
        [...ra.keys()].sort(),
        ['oc-card', 'oc-card--compact', 'pd-badge', 'pd-badge-cat', 'sc-card'],
        'Bộ trích phải lấy cả ba dạng và bỏ toán hạng so sánh',
    );
});

test('tiền tố động phải có ít nhất một class mang nó', () => {
    // Canh chính luật tiền tố. Nếu nó nới thành "cứ kết thúc bằng - là bỏ qua"
    // thì một tiền tố viết sai sẽ lọt, và đó là cả giá trị còn lại của luật này.
    assert.ok(coNguoiDinhNghia('rfl-badge--'), '.rfl-badge--* có trong CSS, tiền tố phải được nhận');
    assert.ok(! coNguoiDinhNghia('rfl-bdge--'), 'Tiền tố viết sai phải bị báo');
    assert.ok(! coNguoiDinhNghia('khong-co-that'), 'Tên không có trong CSS phải bị báo');
});

// ── Inline style không được xoá padding ngang của class layout ─────────────
//
// Lỗi thật, tìm thấy 18/10/2026 khi soát giao diện production ở 390px.
//
//     <section className="w" style={{ padding: '48px 0' }}>
//
// `.w` là lớp bọc layout của cả trang: `padding: 0 22px`, lên 32px từ 768 và
// 52px từ 1024. Nhưng `padding` trong `style` là **shorthand** — nó đặt lại
// cả bốn chiều, nên padding ngang thành 0 và chữ dính sát mép màn hình ở MỌI
// bề rộng. Bốn mục trang chủ và trang 404 đã ở production như vậy.
//
// Không chốt nào đỏ, và lý do đáng nhớ: chốt trên tệp này hỏi *"tên class có
// người định nghĩa không"* — `.w` **có**. Không ai hỏi *"class đó còn tác
// dụng không"*. Một class bị vô hiệu bởi chính phần tử mang nó thì vẫn là một
// class hợp lệ với mọi phép kiểm văn bản.
//
// Phép kiểm này đóng đúng khoảng đó: nếu một phần tử mang class có padding
// ngang trong CSS, thì `style` của nó không được chứa `padding` shorthand.
// Dùng longhand (`paddingTop`, `paddingBottom`) thì không ảnh hưởng chiều
// ngang, nên vẫn được phép — và đó cũng là cách năm chỗ kia đã sửa.

/** Class nào trong CSS đặt padding NGANG khác 0. */
function classCoPaddingNgang() {
    const css = readFileSync(resolve(GOC, TEP_CSS), 'utf8');
    const ra = new Set();

    for (const m of css.matchAll(/\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}/g)) {
        const than = m[2];

        for (const p of than.matchAll(/(?:^|;)\s*padding\s*:\s*([^;]+)/g)) {
            const phan = p[1].trim().split(/\s+/);

            // `padding: A` → mọi chiều; `A B` → B là ngang; `A B C` → B;
            // `A B C D` → B và D.
            const ngang = phan.length === 1 ? [phan[0]]
                : phan.length === 4 ? [phan[1], phan[3]]
                : [phan[1]];

            if (ngang.some((v) => v && v !== '0' && !/^0[a-z%]*$/.test(v))) {
                ra.add(m[1]);
            }
        }
    }

    return ra;
}

/**
 * Các thẻ mở JSX có CẢ `className="…"` lẫn `style={{…}}`.
 *
 * Cắt tới `>` đầu tiên. Một prop chứa arrow function (`onClick={() => …}`) sẽ
 * làm thẻ bị cắt sớm, nên phép quét này có thể BỎ SÓT — nó là chốt chống thêm
 * mới, không phải bằng chứng sạch tuyệt đối. Năm chỗ của ngày 18/10 đều nằm
 * trong tầm, và ca mutation dưới canh đúng điều đó.
 */
function theCoClassVaStyle(ma) {
    const ra = [];

    for (const m of ma.matchAll(/<[A-Za-z][A-Za-z0-9.]*\s[^>]*?>/g)) {
        const the = m[0];
        const cls = the.match(/className="([^"]+)"/);
        const sty = the.match(/style=\{\{([\s\S]*?)\}\}/);

        if (cls && sty) ra.push({ the, cls: cls[1].split(/\s+/), style: sty[1] });
    }

    return ra;
}

/**
 * Phần NGANG của một giá trị `padding` viết trong `style`, hoặc `null`
 * nếu không phải shorthand.
 *
 * Phân biệt hai chuyện khác hẳn nhau, và đây là chỗ bản đầu của phép kiểm
 * này báo oan: `padding: '8px 12px'` **đổi** padding ngang (12px) — hợp lệ,
 * `.mega-body` cố ý làm vậy cho gọn. `padding: '48px 0'` **xoá** nó. Chỉ
 * cái thứ hai mới là lỗi.
 */
function ngangCuaPadding(style) {
    const m = style.match(/(?:^|[,{\s])padding\s*:\s*(?:'([^']*)'|"([^"]*)"|([0-9.]+))/);

    if (! m) return null;

    // `padding: 48` trong JSX là 48px mọi chiều — ngang khác 0.
    if (m[3] !== undefined) return m[3];

    const phan = (m[1] ?? m[2]).trim().split(/\s+/);

    return phan.length === 1 ? phan[0] : phan[1];
}

const LA_KHONG = (v) => v === '0' || /^0[a-z%]*$/.test(v);

test('inline style không được xoá padding ngang của class layout', () => {
    const coNgang = classCoPaddingNgang();
    const sai = [];

    for (const d of TEP_TSX) {
        for (const { cls, style } of theCoClassVaStyle(readFileSync(d, 'utf8'))) {
            const ngang = ngangCuaPadding(style);

            if (ngang === null || ! LA_KHONG(ngang)) continue;

            for (const c of cls) {
                if (coNgang.has(c)) {
                    sai.push(`${d.slice(resolve(GOC).length + 1)}: .${c} + style padding ngang 0`);
                }
            }
        }
    }

    assert.deepEqual(
        sai,
        [],
        'Những chỗ sau dùng `padding` shorthand trong `style` trên một phần tử '
        + 'mang class có padding ngang — shorthand đặt lại cả bốn chiều, nên '
        + 'padding ngang của class bị xoá và chữ dính sát mép màn hình. Dùng '
        + '`paddingTop`/`paddingBottom`:\n  ' + sai.join('\n  '),
    );
});

test('bộ dò padding ngang không rỗng và đọc đúng bốn dạng shorthand', () => {
    // Không có ca này thì một regex vỡ làm `classCoPaddingNgang()` trả tập
    // rỗng, và phép kiểm trên xanh vì không có gì để so.
    const coNgang = classCoPaddingNgang();

    assert.ok(coNgang.has('w'), '`.w` có `padding: 0 22px` — phải nhận ra');
    assert.ok(coNgang.size >= 20, `Chỉ thấy ${coNgang.size} class có padding ngang — bộ dò đã vỡ`);

    // `.sec` là `padding: 80px 0` — dọc có, ngang KHÔNG. Nhận nhầm nó là
    // nhận nhầm cả nhóm, và chốt sẽ báo oan cho mọi mục dùng `.sec`.
    assert.ok(! coNgang.has('sec'), '`.sec` là `padding: 80px 0`, ngang bằng 0 — không được nhận');

    // Bộ trích thẻ phải thấy được dạng đã gây lỗi.
    const thu = theCoClassVaStyle(
        `<section className="w" style={{ padding: '48px 0' }}>x</section>`,
    );

    assert.equal(thu.length, 1, 'Bộ trích thẻ không đọc được dạng đã gây lỗi ngày 18/10');
    assert.ok(thu[0].cls.includes('w'));

    // Ranh giới giữa "xoá" và "đổi" — chỗ bản đầu của phép kiểm báo oan.
    assert.equal(ngangCuaPadding("padding: '48px 0'"), '0', 'phải thấy ngang = 0');
    assert.equal(ngangCuaPadding("padding: '8px 12px'"), '12px', 'đổi giá trị thì không phải lỗi');
    assert.equal(ngangCuaPadding('padding: 48'), '48', 'số trần là px mọi chiều');
    assert.equal(ngangCuaPadding('paddingTop: 48'), null, 'longhand không phải shorthand');
    assert.equal(ngangCuaPadding("padding: '96px 20px'"), '20px');
});

// ── Vùng chạm trên điện thoại ─────────────────────────────────────────────
//
// Đo trên production 18/10/2026 ở viewport 390px: **37 phần tử bấm được cao
// dưới 44px**, trong đó nhóm tệ nhất là thanh thành phố của bản đồ trang chủ
// (25px) và nút đóng dải thông báo (22px).
//
// 44px là ngưỡng của Apple HIG và WCAG 2.5.5. Nó không phải con số thẩm mỹ:
// ngón tay người lớn chạm một vùng khoảng 9mm, và dưới ngưỡng đó tỉ lệ bấm
// nhầm sang phần tử bên cạnh tăng rõ.
//
// ══ Vì sao chốt đọc CSS chứ không đo thật ══
//
// Đo thật cần một trình duyệt chạy layout, mà CI không có. `test:khoi` dựng
// `next start` và đọc HTML, nhưng HTML không nói phần tử cao bao nhiêu.
//
// Nên phép kiểm này hẹp hơn phép đo: nó chỉ canh rằng **luật vẫn còn trong
// stylesheet**. Nó không chứng minh vùng chạm đủ — nó chống việc ai đó xoá
// luật trong một lần dọn dẹp và không ai biết cho tới lần có người cầm điện
// thoại lên.

test('luật vùng chạm 44px vẫn còn trong stylesheet', () => {
    const css = readFileSync(resolve(GOC, TEP_CSS), 'utf8');

    // Lấy mọi khối `@media(max-width:767px){…}` — luật chỉ áp cho màn hình
    // cảm ứng, vì trên desktop con trỏ chuột chính xác hơn ngón tay.
    const khoiMobile = [...css.matchAll(/@media\s*\(\s*max-width\s*:\s*767px\s*\)\s*\{/g)]
        .map((m) => {
            let sau = 1;
            let i = m.index + m[0].length;

            while (i < css.length && sau > 0) {
                if (css[i] === '{') sau++;
                if (css[i] === '}') sau--;
                i++;
            }

            return css.slice(m.index, i);
        })
        .join('\n');

    assert.ok(
        khoiMobile.length > 200,
        'Không tìm thấy khối @media(max-width:767px) nào — bộ trích đã vỡ.',
    );

    // Mỗi dòng: selector, và cái cớ nó từng nhỏ hơn 44px.
    for (const [sel, lyDo] of [
        ['.btn-sm', 'nút phụ cỡ nhỏ — đo được 36px'],
        ['.btn-xs', 'nút trong thanh bản đồ — đo được 30px'],
        ['.hmc-btn', 'thanh thành phố trên bản đồ trang chủ — đo được 25px'],
        ['.hm-zoom-btn', 'nút phóng to/thu nhỏ bản đồ — đo được 32px'],
        ['.ms-pill', 'pill của ô tìm kiếm lớn — đo được 24px'],
        ['.trial-notice-x', 'nút đóng dải thông báo — đo được 22px'],
        ['.ft-ls a', 'liên kết danh sách chân trang — đo được 18px'],
    ]) {
        assert.ok(
            khoiMobile.includes(sel),
            `Luật vùng chạm cho \`${sel}\` đã biến mất (${lyDo}).`,
        );
    }

    // Và chúng phải thật sự đặt 44px, không chỉ có tên trong một luật khác.
    const so44 = (khoiMobile.match(/44px/g) ?? []).length;

    assert.ok(
        so44 >= 6,
        `Chỉ thấy ${so44} lần "44px" trong khối mobile — luật vùng chạm đã bị rút ruột.`,
    );
});
// ── Thang chữ ─────────────────────────────────────────────────────────────
//
// Đo trên production 18/10/2026, trước khi có thang: `<h1>` của mỗi trang
// một cỡ, và không cỡ nào suy ra được từ cỡ nào.
//
//     /  80px   ·  /owners 42px  ·  /explore/{slug} 36px  ·  /explore 28px
//     /owners/{slug}: KHÔNG CÓ <h1> NÀO
//
// Trong stylesheet: 579 khai báo `font-size`, 24 cỡ cố định (gồm 9px,
// 11.5px, 12.5px, 13.5px) và **12 công thức `clamp()` khác nhau** — mỗi khối
// tự đặt thang riêng, nên ở 900px thang trông khác hẳn ở 1440px.
//
// Chủ dự án chốt dựng thang mới (18/10/2026), chấp nhận trang đổi diện mạo.

const NAC_THANG = ['--fs-hero', '--fs-1', '--fs-2', '--fs-3', '--fs-4', '--fs-5', '--fs-6', '--fs-7'];

/** Giá trị của một token thang, như khai trong `:root`. */
function nac(ten) {
    const css = readFileSync(resolve(GOC, TEP_CSS), 'utf8');
    const m = css.match(new RegExp('\\' + ten + '\\s*:\\s*([^;]+);'));

    return m ? m[1].trim() : null;
}

test('thang chữ có đủ tám nấc và khai một chỗ', () => {
    for (const t of NAC_THANG) {
        assert.ok(nac(t), `Thiếu nấc \`${t}\` trong \`:root\`.`);
    }

    // Khai hai lần là có hai thang, và chỗ thứ hai sẽ là chỗ không ai sửa.
    const css = readFileSync(resolve(GOC, TEP_CSS), 'utf8');

    for (const t of NAC_THANG) {
        const so = (css.match(new RegExp('\\' + t + '\\s*:', 'g')) ?? []).length;

        assert.equal(so, 1, `\`${t}\` được khai ${so} lần — thang phải có đúng một nguồn.`);
    }
});

test('các nấc giảm dần, không nấc nào vượt nấc trên', () => {
    // Một thang mà nấc dưới to hơn nấc trên thì không phải thang. Đây là
    // kiểu sai dễ xảy ra nhất khi ai đó chỉnh một nấc cho vừa một khối.
    const so = (v) => {
        const m = v.match(/clamp\(\s*([0-9.]+)px\s*,[^,]+,\s*([0-9.]+)px\s*\)/);

        return m ? { min: +m[1], max: +m[2] } : { min: parseFloat(v), max: parseFloat(v) };
    };

    for (let i = 1; i < NAC_THANG.length; i++) {
        const tren = so(nac(NAC_THANG[i - 1]));
        const duoi = so(nac(NAC_THANG[i]));

        assert.ok(
            duoi.min < tren.min && duoi.max < tren.max,
            `\`${NAC_THANG[i]}\` (${duoi.min}–${duoi.max}) không nhỏ hơn `
            + `\`${NAC_THANG[i - 1]}\` (${tren.min}–${tren.max}).`,
        );
    }
});

test('class tiêu đề dùng token, không tự đặt clamp riêng', () => {
    const css = readFileSync(resolve(GOC, TEP_CSS), 'utf8');

    // Mười ba class từng là mười ba thang. Mỗi cái phải xuất hiện trong một
    // luật `font-size: var(--fs-…)`.
    const PHAI_THEO_THANG = [
        '.hero-hed', '.hero-sub-hed', '.oc-title', '.ag-hero h1', '.pg-hero h1',
        '.pol-h1', '.ex-title', '.ph-title', '.fp-name', '.dtitle',
        '.section-hed', '.sec-h', '.cta-title', '.pb-name', '.ag-sn',
        '.section-sub', '.sec-sub', '.hero-desc',
    ];

    // Mọi khối luật có `font-size:var(--fs-…)`, gom selector của chúng lại.
    const selectorTheoThang = [...css.matchAll(/([^{}]+)\{[^{}]*font-size:\s*var\(--fs-[^)]+\)[^{}]*\}/g)]
        .map((m) => m[1])
        .join(',');

    const thieu = PHAI_THEO_THANG.filter((c) => ! selectorTheoThang.includes(c));

    assert.deepEqual(
        thieu,
        [],
        'Những class tiêu đề sau không dùng nấc nào của thang — chúng sẽ lại '
        + 'trôi mỗi cái một cỡ:\n  ' + thieu.join('\n  '),
    );
});

test('thẻ tiêu đề trần có mức nền', () => {
    // Trang 404 từng ra `<h1>` 30px — không phải nấc nào, mà là `2em` mặc
    // định của trình duyệt tính trên `font-size: 15px` của body. Chỗ quên gắn
    // class là chỗ không ai nhớ tới, nên luật phải đón sẵn.
    const css = readFileSync(resolve(GOC, TEP_CSS), 'utf8');

    for (const t of ['h1', 'h2', 'h3']) {
        assert.match(
            css,
            new RegExp('(^|[;}\\s])' + t + '\\{font-size:var\\(--fs-', 'm'),
            `Thiếu mức nền cho \`<${t}>\` không mang class.`,
        );
    }
});
