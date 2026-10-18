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

// ── Nguồn tile: hai bản sao phải khớp, và không chỗ nào hardcode ───────────

/**
 * Giá trị của một biến/hằng, đã gỡ mọi cách viết để còn lại chuỗi cuối cùng.
 *
 * Hai tệp viết cùng một URL theo hai cú pháp khác hẳn nhau:
 *
 *   PHP  $maptiler = 'https://…/maps/' . env('MAPTILER_STYLE', 'streets-v2')
 *                    . '/{z}/{x}/{y}.png?key={key}';
 *   TS   const MAPTILER_URL =
 *            `https://…/maps/${process.env.NEXT_PUBLIC_MAPTILER_STYLE
 *             ?? 'streets-v2'}/{z}/{x}/{y}.png?key={key}`;
 *
 * Nên so chuỗi thô là so hai thứ không bao giờ bằng nhau. Hàm này quy cả hai
 * về cùng một dạng: thay `env()` và `process.env … ??` bằng giá trị mặc định,
 * mở `${…}`, rồi nối các mảnh chuỗi lại.
 */
function giaTri(bt) {
    return bt
        .replace(/env\(\s*'[^']*'\s*,\s*'([^']*)'\s*\)/g, '$1')
        .replace(/process\.env\.[A-Z_0-9]+\s*\?\?\s*'([^']*)'/g, '$1')
        .replace(/\$\{\s*([^}]*?)\s*\}/g, '$1')
        .replace(/'\s*\.\s*/g, '')
        .replace(/\s*\.\s*'/g, '')
        .replace(/['`]/g, '')
        .trim();
}

/**
 * Phần nằm giữa `<moc>` và dấu `;` cuối dòng, đã quy về chuỗi cuối cùng.
 *
 * Cắt chuỗi chứ không dựng `new RegExp(...)` từ mảnh: một regex viết trong
 * chuỗi cần `\\s` chứ không phải `\s`, và khi viết nhầm thì nó **vẫn chạy**,
 * chỉ khớp sai — một chốt hỏng mà xanh. Phép cắt này không có chỗ cho kiểu
 * nhầm đó.
 */
function catSauMoc(s, moc, ten, tep) {
    const i = s.indexOf(moc);
    assert.ok(i >= 0, 'Không đọc được `' + ten + '` trong ' + tep);

    // Giá trị kết thúc ở dấu `;` cuối dòng. Không URL hay chuỗi ghi công nào
    // của dự án chứa `;` (ghi công dùng ký tự `©`, không dùng `&copy;`), nên
    // dấu `;` đầu tiên theo sau một xuống dòng là dấu kết thúc thật.
    const j = s.indexOf(';\n', i + moc.length);
    assert.ok(j > 0, '`' + ten + '` trong ' + tep + ' không kết thúc bằng `;`');

    return giaTri(s.slice(i + moc.length, j));
}

/** Giá trị gán cho một biến PHP trong `config/map.php`. */
function bienPhp(ten) {
    const php = readFileSync('config/map.php', 'utf8').replace(/\r\n/g, '\n');

    return catSauMoc(php, '$' + ten + ' =', '$' + ten, 'config/map.php');
}

/** Giá trị gán cho một hằng trong `webapp/lib/ban-do.ts`. */
function hangNext(ten) {
    const ts = readFileSync('webapp/lib/ban-do.ts', 'utf8').replace(/\r\n/g, '\n');

    return catSauMoc(ts, 'const ' + ten + ' =', ten, 'webapp/lib/ban-do.ts');
}

test('nguồn tile và ghi công của Next và của Filament phải khớp', () => {
    // Hai bản sao là đánh đổi có ý thức — Next không đọc được config PHP, và
    // kéo URL tile qua một lời gọi API là thêm một vòng mạng cho một chuỗi
    // hằng. Chốt này là thứ bịt chỗ trôi: quên sửa một bên thì đỏ.
    for (const [tenPhp, tenTs] of [
        ['maptiler', 'MAPTILER_URL'],
        ['esri', 'ESRI_URL'],
        ['ghiCongMaptiler', 'GHI_CONG_MAPTILER'],
        ['ghiCongEsri', 'GHI_CONG_ESRI'],
    ]) {
        assert.equal(
            hangNext(tenTs),
            bienPhp(tenPhp),
            '`' + tenTs + '` (Next) khác `$' + tenPhp + '` (config/map.php) — ' +
                'đổi nhà cung cấp tile phải sửa cả hai nơi.',
        );
    }
});

test('hai bên chọn nguồn theo cùng một luật: có khóa thì MapTiler', () => {
    // Luật chọn cũng là một bản sao, và nó lặng lẽ hơn URL: nếu một bên đổi
    // thành "luôn dùng MapTiler" thì trang công khai và khu quản trị hiện hai
    // tấm bản đồ khác nhau mà không chốt nào khác thấy.
    assert.match(
        readFileSync('config/map.php', 'utf8'),
        /'tile_url'\s*=>\s*env\('MAP_TILE_URL'\)\s*\?:\s*\(\$maptilerKey !== ''\s*\?\s*\$maptiler\s*:\s*\$esri\)/,
        'config/map.php không còn chọn MapTiler-khi-có-khóa, Esri-khi-không.',
    );

    assert.match(
        readFileSync('webapp/lib/ban-do.ts', 'utf8'),
        /export const TILE_URL =\s*MAPTILER_KEY === ''\s*\?\s*ESRI_URL\s*:\s*MAPTILER_URL;/,
        'webapp/lib/ban-do.ts không còn chọn MapTiler-khi-có-khóa, Esri-khi-không.',
    );
});

test('khóa MapTiler không được nằm trong repo', () => {
    // Khóa hiện ra trong trình duyệt của khách dù muốn hay không, nhưng một
    // khóa trong git là một khóa không xoay được: nó ở lại trong lịch sử.
    for (const p of ['config/map.php', 'webapp/lib/ban-do.ts', '.env.docker.example']) {
        // Khóa MapTiler là 20 ký tự chữ-và-số. Bắt theo hình dạng VÀ ngữ cảnh
        // (`key=…` hoặc `MAPTILER_KEY = …`), nên nó không báo oan vì một chuỗi
        // hai mươi ký tự ngẫu nhiên nào đó trong chú thích.
        assert.doesNotMatch(
            readFileSync(p, 'utf8'),
            /key=['"]?[A-Za-z0-9]{20}\b|MAPTILER_KEY['"]?\s*[,=:]\s*['"][A-Za-z0-9]{20}/,
            p + ' có thứ trông như một khóa MapTiler thật. Khóa sống ở `.env`.',
        );
    }
});

test('không view nào hardcode một nguồn tile', () => {
    const thuMuc = ['resources/views', 'webapp/components', 'webapp/app'];
    const sai = [];

    function quet(duong) {
        for (const muc of readdirSync(duong, { withFileTypes: true })) {
            const p = join(duong, muc.name);

            if (muc.isDirectory()) {
                quet(p);
                continue;
            }

            if (!/\.(blade\.php|tsx|ts)$/.test(muc.name)) continue;

            const ma = boChuThich(readFileSync(p, 'utf8'));

            // Mọi URL tile đều có hình dạng `{z}/{x}/{y}` — bắt theo hình dạng
            // đó thay vì theo tên một nhà cung cấp, nên nó cũng đỏ nếu ai cắm
            // một nguồn thứ ba vào thẳng view.
            if (/https:\/\/[^'"]*\{z\}[^'"]*\{[xy]\}/.test(ma)) {
                sai.push(p.replace(/\\/g, '/'));
            }
        }
    }

    for (const d of thuMuc) quet(d);

    assert.deepEqual(
        sai,
        [],
        'Những tệp sau cắm thẳng một URL tile. Nguồn tile sống ở ' +
            '`config/map.php` (Blade) và `webapp/lib/ban-do.ts` (Next) — ' +
            'mười hai bản đồ, một chỗ đổi:\n  ' +
            sai.join('\n  '),
    );
});
