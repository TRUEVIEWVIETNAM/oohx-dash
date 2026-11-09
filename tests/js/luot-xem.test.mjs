// ════════════════════════════════════════════════════════════════════════════
// Máy đếm lượt xem — lát 2 của `docs/PLAN-LUOT-XEM.md`.
//
// Import thẳng module TypeScript mà Next dựng lên production
// (`webapp/lib/luot-xem.ts`), nên không có bản sao nào để trôi khỏi bản thật.
//
// ── Vì sao những ca này tồn tại ────────────────────────────────────────────
//
// Mọi lỗi của một đường đo đạc đều **im lặng**. `fetch` nuốt lỗi, máy chủ trả
// 204 hoặc 404 mà không ai đọc, và hậu quả duy nhất là một con số sai trên một
// trang mà không ai biết con số đúng là bao nhiêu. Bốn loại lỗi dưới đây đều
// có hình dạng đó:
//
//   • **Đường dẫn client lệch route Laravel.** Mọi lượt gửi nhận 404, bảng
//     vĩnh viễn rỗng, và trang chi tiết vẫn chạy bình thường.
//   • **Mất phép gộp theo phiên.** Con số đo độ sốt ruột của người đang xem
//     chứ không đo có bao nhiêu người xem; một người bấm F5 ba mươi lần đẩy
//     một vị trí lên đầu bảng "Phổ biến nhất".
//   • **Mảng phụ thuộc của `useEffect` thiếu `slug`.** Next điều hướng phía
//     client không dựng lại component, nên mọi vị trí sau vị trí đầu tiên của
//     một phiên duyệt không được đếm.
//   • **Thân request mọc thêm trường.** Bảng lượt xem không phải dữ liệu cá
//     nhân *vì* thân request không mang gì định danh người gửi. Thêm một id
//     phiên vào đây là làm tiền đề đó sai, và máy chủ vẫn trả 204.
// ════════════════════════════════════════════════════════════════════════════

import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { registerHooks } from 'node:module';
import { existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

/**
 * Cho Node phân giải được `from './do-dac'` — một import KHÔNG có phần mở rộng.
 *
 * Đó là quy ước của cả `webapp/lib` (xem `dung-vi-tri.ts`, `dung-ban-do.ts`…)
 * vì Next tự thêm `.ts`. Node ESM thì không, nên không có móc này thì tệp test
 * chỉ còn cách **chép lại** module hoặc bỏ hẳn những ca gọi mã thật — và ca
 * quan trọng nhất ở đây (gộp theo phiên) là một ca chỉ chứng minh được bằng
 * cách chạy mã thật.
 *
 * Móc chỉ thêm `.ts` khi đường dẫn không có phần mở rộng và tệp `.ts` tồn tại;
 * mọi trường hợp khác trả về phép phân giải mặc định.
 */
registerHooks({
    resolve(chiDinh, ctx, tiep) {
        if (chiDinh.startsWith('.') && !/\.[a-z]+$/i.test(chiDinh) && ctx.parentURL) {
            const duongDan = fileURLToPath(new URL(chiDinh + '.ts', ctx.parentURL));

            if (existsSync(duongDan)) {
                return tiep(chiDinh + '.ts', ctx);
            }
        }

        return tiep(chiDinh, ctx);
    },
});

const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    url: 'https://oohx.net/explore/billboard-cho-ben-thanh-14x7m',
});
globalThis.window = dom.window;

const lx = await import('../../webapp/lib/luot-xem.ts');

const maLuotXem = readFileSync(join(GOC, 'webapp/lib/luot-xem.ts'), 'utf8');
const maComponent = readFileSync(join(GOC, 'webapp/components/GhiLuotXem.tsx'), 'utf8');
const maRoute = readFileSync(join(GOC, 'routes/api.php'), 'utf8');

/** Mọi lời gọi `fetch` của lượt chạy hiện tại. */
let daGoi = [];

beforeEach(() => {
    daGoi = [];
    globalThis.fetch = (url, opt) => {
        daGoi.push({ url, opt });
        return Promise.resolve({ ok: true, status: 204 });
    };
    window.sessionStorage.clear();
});

// ── Đường dẫn phải khớp route của Laravel ──────────────────────────────────

test('đường dẫn client khớp route Laravel', () => {
    // Lệch một ký tự ở đây là mọi lượt gửi nhận 404 và bảng vĩnh viễn rỗng,
    // trong khi trang chi tiết vẫn chạy bình thường. Không gì đỏ.
    const khop = maRoute.match(/Route::post\('(screens\/\{slug\}\/[a-z-]+)',\s*\[V2LuotXemController/);
    assert.ok(khop, 'Không tìm thấy route `POST screens/{slug}/luot-xem` trong `routes/api.php`.');

    const doan = khop[1].split('/').filter((d) => d && d !== '{slug}');

    lx.ghiLuotXem('bien-mot');

    assert.equal(daGoi.length, 1, 'Không gửi lượt nào.');
    assert.equal(
        daGoi[0].url,
        `/api/v2/${doan[0]}/bien-mot/${doan[1]}`,
        'Đường dẫn client không khớp route Laravel — mọi lượt gửi sẽ nhận 404.',
    );
});

test('slug được mã hoá vào đường dẫn', () => {
    // Slug trong CSDL là chuỗi do người nhập kho đặt. Một dấu gạch chéo hay
    // khoảng trắng không mã hoá làm đường dẫn bị cắt, và lượt đó rơi vào một
    // route khác.
    lx.ghiLuotXem('bien a/b');

    assert.equal(daGoi[0].url, '/api/v2/screens/bien%20a%2Fb/luot-xem');
});

// ── Thân request: rỗng, và không mang gì định danh ─────────────────────────

test('không gửi thân request nào', () => {
    lx.ghiLuotXem('bien-mot');

    assert.equal(
        daGoi[0].opt.body,
        undefined,
        'Có thân request. Màn hình nằm trong đường dẫn, và bảng lượt xem không phải dữ liệu cá '
            + 'nhân VÌ thân request không mang gì định danh người gửi — thêm một trường ở đây là '
            + 'làm tiền đề đó sai, và máy chủ vẫn trả 204.',
    );
});

test('không đọc gì định danh được người dùng', () => {
    assert.doesNotMatch(
        maLuotXem,
        /userAgent|navigator\.platform|document\.referrer|location\.search/,
        'Đọc một thứ định danh được. Một khi nó đi qua đường mạng thì nó nằm trong nhật ký máy '
            + 'chủ dù bảng không có cột nào nhận nó.',
    );
});

test('không gửi cookie phiên theo lượt xem', () => {
    lx.ghiLuotXem('bien-mot');

    assert.equal(
        daGoi[0].opt.credentials,
        'omit',
        'Thiếu `credentials: omit`. Mặc định của `fetch` cùng nguồn gốc là gửi cookie phiên, nên '
            + 'mỗi lượt xem mang theo danh tính người dùng tới máy chủ — không vào bảng, nhưng vào '
            + 'nhật ký truy cập.',
    );
});

// ── Gộp theo phiên ─────────────────────────────────────────────────────────

test('một màn hình chỉ đếm một lượt trong mỗi phiên', () => {
    lx.ghiLuotXem('bien-mot');
    lx.ghiLuotXem('bien-mot');
    lx.ghiLuotXem('bien-mot');

    assert.equal(
        daGoi.length,
        1,
        'Gửi lại cho cùng một màn hình trong cùng một phiên. Máy chủ không biết người gửi là ai '
            + 'nên nó KHÔNG gộp được — mất phép gộp ở đây là con số đo số lần bấm F5.',
    );
});

test('mỗi màn hình có ô đếm riêng', () => {
    lx.ghiLuotXem('bien-mot');
    lx.ghiLuotXem('bien-hai');

    assert.equal(daGoi.length, 2, 'Gộp lẫn hai màn hình khác nhau — chỉ vị trí đầu tiên được đếm.');
});

test('chặn được site data thì vẫn đếm', () => {
    // Cửa sổ ẩn danh ở vài trình duyệt làm `sessionStorage` NÉM khi đọc. Thà
    // đếm hơi nhiều còn hơn mất hẳn những người duyệt web ở chế độ ẩn danh —
    // và một ngoại lệ không bắt ở đây thì `useEffect` của trang chi tiết ném,
    // tức một trang trắng vì một phép đo.
    const that = window.sessionStorage;
    Object.defineProperty(window, 'sessionStorage', {
        configurable: true,
        get() { throw new Error('site data bị chặn'); },
    });

    try {
        assert.equal(lx.daDemTrongPhien('bien-mot'), false);
        lx.ghiLuotXem('bien-mot');
        assert.equal(daGoi.length, 1, 'Không đếm được khi site data bị chặn.');
    } finally {
        Object.defineProperty(window, 'sessionStorage', { configurable: true, value: that });
    }
});

// ── Không bao giờ chặn luồng ───────────────────────────────────────────────

test('phép đo không bao giờ chặn luồng', () => {
    assert.doesNotMatch(
        maLuotXem,
        /await\s+fetch/,
        '`await fetch` trong đường đo đạc. Một phép đo làm chậm tính năng nó đo là một phép đo tự huỷ.',
    );

    assert.match(
        maLuotXem,
        /\.catch\(/,
        'Không nuốt lỗi gửi — một lượt xem mất đi không phải lỗi người dùng cần biết.',
    );
});

test('fetch ném đồng bộ thì không vỡ trang', () => {
    globalThis.fetch = () => { throw new Error('CSP chặn'); };

    lx.ghiLuotXem('bien-mot'); // không được ném ra ngoài
});

// ── Lưới "không gửi ở máy phát triển", dùng chung một định nghĩa ───────────

test('phép kiểm tên miền dùng chung với `do-dac.ts`, không chép lại', () => {
    // Hai bản chép rời sẽ lệch. Thiếu dấu chấm thì `oohx.net.evil.com` gửi
    // được số vào bảng của sàn; thiếu cả phép kiểm thì mỗi lần `next start` ở
    // máy lại bơm số rác vào bảng production — rác đó không gỡ ra được, vì
    // bảng chỉ có số cộng dồn.
    assert.match(
        maLuotXem,
        /import\s*\{\s*nenGuiDoDac\s*\}\s*from\s*'\.\/do-dac'/,
        'Không dùng chung `nenGuiDoDac` của `do-dac.ts`.',
    );

    const khongChuThich = maLuotXem.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

    assert.doesNotMatch(
        khongChuThich,
        /oohx\.net/,
        'Tự viết lại phép kiểm tên miền. Nó đã có bốn ca chốt riêng trong `do-dac.test.mjs`, kể '
            + 'cả hai ca tên miền giả mạo — một bản thứ hai sẽ không được canh bởi ca nào.',
    );
});

test('không gửi từ máy phát triển', () => {
    const domDev = new JSDOM('<!doctype html>', { url: 'http://localhost:3000/explore/bien-mot' });
    const that = globalThis.window;
    globalThis.window = domDev.window;

    try {
        lx.ghiLuotXem('bien-mot');
        assert.equal(daGoi.length, 0, '`localhost` gửi được số vào bảng production.');
    } finally {
        globalThis.window = that;
    }
});

// ── Component nối dây ──────────────────────────────────────────────────────

test('`useEffect` phụ thuộc vào `slug`', () => {
    // Next điều hướng phía client giữa hai trang `/explore/{slug}` KHÔNG dựng
    // lại component — nó chỉ đổi props. Để `[]` thì effect chạy đúng một lần
    // cho cả chuỗi vị trí người dùng xem, và mọi vị trí sau vị trí đầu tiên
    // vĩnh viễn không được đếm.
    assert.match(
        maComponent,
        /\}\s*,\s*\[\s*slug\s*\]\s*\)/,
        'Mảng phụ thuộc của `useEffect` không có `slug`.',
    );
});

test('component không vẽ gì ra DOM', () => {
    assert.match(
        maComponent,
        /return null;/,
        'Component đếm vẽ ra DOM — nó chỉ tồn tại để có một chỗ chạy trong trình duyệt.',
    );
});
