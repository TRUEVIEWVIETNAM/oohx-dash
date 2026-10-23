// ════════════════════════════════════════════════════════════════════════════
// Đo đạc quyền vị trí — lát 2 của `docs/PLAN-DO-DAC-TOI-THIEU.md`.
//
// Chủ dự án chốt 23/10/2026: **bật đo đạc**, và **không** thêm mục vào chính
// sách bảo mật.
//
// ── Vì sao quyết định thứ hai cần những ca ở đây ────────────────────────────
//
// "Không cần khai" đứng được vì bảng đích là bảng đếm gộp, không chứa dữ liệu
// cá nhân. Đó là một phát biểu về **hình dạng của thứ được gửi đi**, nên nó
// đúng chỉ chừng nào hình dạng ấy còn nguyên. Một trường thứ tư thêm vào thân
// request — một id phiên, một đường dẫn, một dòng user agent — làm lời hứa đó
// sai mà không gì đỏ, vì máy chủ vẫn trả 204 và bảng vẫn không có cột nào
// nhận nó: dữ liệu đi vào nhật ký truy cập của máy chủ và sống ngoài mọi lời
// hứa của plan.
//
// Nên ca `thân request có ĐÚNG ba trường` ở dưới không phải một phép kiểm
// hình thức. Nó là thứ duy nhất canh một quyết định đã được chốt.
//
// ── Hai lỗi im lặng khác mà tệp này canh ───────────────────────────────────
//
//   • **Giá trị gửi đi không khớp danh sách trắng máy chủ.** Máy chủ trả 422,
//     `fetch` nuốt lỗi, và ô đó **vĩnh viễn bằng 0** trên `/admin`. Rủi ro
//     rất thực: `dung-vi-tri.ts` dùng hai quy ước đặt tên cạnh nhau —
//     `pha: 'tu-choi'` (gạch ngang) cho trạng thái giao diện và
//     `'tu_choi'` (gạch dưới) cho đo đạc.
//   • **`CHO_TRA_LOI_MS` ngắn hơn `timeout` của `getCurrentPosition`.** Khi
//     đó mọi lượt định vị chậm bị đếm thành "người dùng do dự", và hai ô dẫn
//     tới hai kết luận trái nhau.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const dd = await import('../../webapp/lib/do-dac.ts');

const maDoDac = readFileSync(join(GOC, 'webapp/lib/do-dac.ts'), 'utf8');
const maHook = readFileSync(join(GOC, 'webapp/lib/dung-vi-tri.ts'), 'utf8');

// ── Chỉ gửi từ tên miền thật ───────────────────────────────────────────────

test('gửi từ sàn thật và từ tên miền con của nó', () => {
    assert.equal(dd.nenGuiDoDac('oohx.net'), true);
    assert.equal(dd.nenGuiDoDac('www.oohx.net'), true);
});

test('KHÔNG gửi ở máy phát triển', () => {
    // Thiếu lưới này thì mỗi lần `next start` ở máy lại bơm số rác vào bảng
    // production — và rác đó không gỡ ra được, vì bảng chỉ có số cộng dồn và
    // không cột nào nói lượt nào từ đâu tới.
    for (const h of ['localhost', '127.0.0.1', '0.0.0.0', 'oohx.test', '[::1]', '']) {
        assert.equal(dd.nenGuiDoDac(h), false, `\`${h}\` không phải sàn thật.`);
    }
});

test('tên miền chỉ CHỨA "oohx.net" thì không được gửi', () => {
    // `includes('oohx.net')` nhận `oohx.net.evil.com` — một trang ai cũng
    // dựng được lại gửi được số vào bảng của sàn. `endsWith('oohx.net')`
    // nhận `notoohx.net`, cùng kiểu lỗi và khó thấy hơn. Dấu chấm chặn cả hai.
    assert.equal(dd.nenGuiDoDac('oohx.net.evil.com'), false);
    assert.equal(dd.nenGuiDoDac('notoohx.net'), false);
    assert.equal(dd.nenGuiDoDac('oohx.network'), false);
    assert.equal(dd.nenGuiDoDac('xoohx.net'), false);
});

// ── Thân request ───────────────────────────────────────────────────────────

test('thân request có ĐÚNG ba trường', () => {
    const than = maDoDac.slice(maDoDac.indexOf('body: JSON.stringify('));
    const dong = than.slice(0, than.indexOf('}),'));

    const truong = [...dong.matchAll(/^\s{16}([a-z_]+):/gm)].map((m) => m[1]);

    assert.deepEqual(
        truong,
        ['su_kien', 'gia_tri', 'thiet_bi'],
        'Thân request đổi khác ba trường. Chủ dự án chốt KHÔNG ghi mục đo đạc vào chính sách '
            + 'bảo mật, và lý do đứng được là thân request không mang gì định danh người gửi. '
            + 'Thêm một trường ở đây là làm quyết định đó sai — máy chủ vẫn trả 204 và bảng vẫn '
            + 'không có cột nào nhận nó, nhưng dữ liệu vào nhật ký truy cập của máy chủ.',
    );
});

test('không gửi cookie phiên theo lượt đếm', () => {
    assert.match(
        maDoDac,
        /credentials:\s*'omit'/,
        'Thiếu `credentials: omit`. Mặc định của `fetch` cùng nguồn gốc là gửi cookie phiên, '
            + 'nên mỗi lượt đếm mang theo danh tính người dùng tới máy chủ — không vào bảng, '
            + 'nhưng vào nhật ký truy cập.',
    );
});

test('lượt đếm sống qua lúc rời trang', () => {
    assert.match(
        maDoDac,
        /keepalive:\s*true/,
        'Thiếu `keepalive`. `chua_tra_loi` gửi sau 12 giây, và người bỏ qua hộp thoại quyền '
            + 'thường là người đang rời trang — nên ô quan trọng nhất của bản tổng hợp thành ô '
            + 'hụt nhiều nhất, hụt theo chiều làm tính năng trông đỡ tệ hơn thực tế.',
    );
});

test('phép đo không bao giờ chặn luồng', () => {
    assert.doesNotMatch(
        maDoDac,
        /await\s+fetch/,
        '`await fetch` trong đường đo đạc. Một phép đo làm chậm tính năng nó đo là một phép đo '
            + 'tự huỷ.',
    );

    assert.match(maDoDac, /\.catch\(/, 'Không nuốt lỗi gửi — một lượt đếm mất đi không phải lỗi người dùng cần biết.');
});

test('loại thiết bị theo con trỏ, không theo user agent', () => {
    assert.match(
        maDoDac,
        /\(pointer:\s*coarse\)/,
        'Không dùng `(pointer: coarse)`. Nó trả lời đúng câu đang cần — ngón tay hay con chuột '
            + '— kể cả ở những ca user agent trả lời sai.',
    );

    assert.doesNotMatch(
        maDoDac,
        /userAgent|navigator\.platform/,
        'Đọc user agent để đoán loại thiết bị. Chuỗi đó định danh thiết bị tới mức đáng ngạc '
            + 'nhiên, và một khi nó đi qua đường mạng thì nó nằm trong nhật ký máy chủ dù không '
            + 'vào bảng.',
    );
});

// ── Giá trị gửi đi phải khớp danh sách trắng của MÁY CHỦ ───────────────────

/** Danh sách trắng đọc thẳng từ `DoDacService::CHO_PHEP`. */
function danhSachTrangMayChu() {
    const php = readFileSync(join(GOC, 'app/Services/DoDacService.php'), 'utf8');
    const dau = php.indexOf('public const CHO_PHEP');
    const cuoi = php.indexOf('public const THIET_BI');

    assert.ok(dau > 0 && cuoi > dau, 'Không tìm thấy `CHO_PHEP` trong `DoDacService`.');

    return [...php.slice(dau, cuoi).matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
}

/** Mọi giá trị client thật sự gửi, lấy từ các lời gọi `ghiDoDac(...)`. */
function giaTriClientGui() {
    const goi = [...maHook.matchAll(/ghiDoDac\(([^)]*)\)/g)].map((m) => m[1]);
    const ra = new Set();

    for (const tham of goi) {
        for (const m of tham.matchAll(/'([^']+)'/g)) ra.add(m[1]);
    }

    return [...ra];
}

test('mọi giá trị client gửi đều nằm trong danh sách trắng máy chủ', () => {
    const choPhep = danhSachTrangMayChu();
    const gui = giaTriClientGui();

    assert.ok(gui.length > 0, 'Không lời gọi `ghiDoDac` nào — lát 2 chưa nối dây.');

    for (const g of gui) {
        assert.ok(
            choPhep.includes(g),
            `Client gửi \`${g}\`, không có trong \`DoDacService::CHO_PHEP\` (${choPhep.join(', ')}). `
                + 'Máy chủ trả 422, `fetch` nuốt lỗi, và ô đó VĨNH VIỄN bằng 0 trên `/admin` mà '
                + 'không gì đỏ.',
        );
    }
});

test('không lẫn quy ước gạch ngang của tên pha giao diện', () => {
    // `dung-vi-tri.ts` có `pha: 'tu-choi'` ngay cạnh `ghiDoDac('tu_choi')`.
    // Chép sai một dấu là một ô chết im lặng.
    for (const g of giaTriClientGui()) {
        assert.doesNotMatch(
            g,
            /-/,
            `\`${g}\` dùng gạch ngang. Tên pha giao diện dùng gạch ngang, giá trị đo đạc dùng `
                + 'gạch dưới, và hai quy ước đó nằm cạnh nhau trong cùng một tệp.',
        );
    }
});

test('cả sáu ô của máy chủ đều có đường để nhận số', () => {
    // Một giá trị trong danh sách trắng mà không chỗ nào gửi là một ô vĩnh
    // viễn trống trên `/admin` — và nó trông giống hệt "chưa ai gặp ca đó".
    const gui = giaTriClientGui();

    for (const c of danhSachTrangMayChu()) {
        assert.ok(
            gui.includes(c),
            `Máy chủ nhận \`${c}\` nhưng không chỗ nào gửi nó. Ô đó sẽ trống mãi, và trông `
                + 'giống "chưa ai gặp ca này".',
        );
    }
});

// ── Hẹn giờ `chua_tra_loi` ─────────────────────────────────────────────────

test('chờ lâu hơn timeout của getCurrentPosition', () => {
    const m = maHook.match(/timeout:\s*([\d_]+)/);

    assert.ok(m, 'Không tìm thấy `timeout` của `getCurrentPosition`.');

    const timeout = Number(m[1].replace(/_/g, ''));

    assert.ok(
        dd.CHO_TRA_LOI_MS > timeout,
        `\`CHO_TRA_LOI_MS\` (${dd.CHO_TRA_LOI_MS}ms) không dài hơn \`timeout\` (${timeout}ms). `
            + 'Khi đó mọi lượt định vị chậm bị đếm thành "người dùng do dự" — và hai ô đó dẫn tới '
            + 'hai kết luận trái nhau: `loi` là bộ định vị chậm, `chua_tra_loi` là ô còn cứu được '
            + 'bằng cách hỏi lúc khác.',
    );
});

test('hẹn giờ bị huỷ ở CẢ HAI callback', () => {
    const dau = maHook.indexOf('navigator.geolocation.getCurrentPosition(');
    const than = maHook.slice(dau, maHook.indexOf('enableHighAccuracy', dau));

    const huy = [...than.matchAll(/huyHetGio\(\)/g)].length;

    assert.ok(
        huy >= 2,
        `Chỉ ${huy} lời gọi \`huyHetGio()\` trong hai callback. Một câu trả lời về chậm sẽ được `
            + 'đếm HAI lần — `chua_tra_loi` cộng một rồi `cho_phep` cộng một — và khi đó từng tỉ '
            + 'lệ vẫn trông hợp lý, chỉ tổng của chúng là vô lý.',
    );
});

test('hẹn giờ KHÔNG bị huỷ khi rời trang', () => {
    const don = maHook.slice(maHook.lastIndexOf('return () => {'));

    assert.doesNotMatch(
        don,
        /huyHetGio|clearTimeout/,
        'Hàm dọn của `useEffect` huỷ hẹn giờ. Hộp thoại quyền thuộc về TAB, không thuộc về '
            + 'component: nó vẫn đứng đó sau khi người dùng chuyển trang trong cùng ứng dụng. '
            + 'Huỷ ở đây là bỏ mất đúng những lượt do dự lâu nhất.',
    );
});

// ── Lượt nào được đếm ──────────────────────────────────────────────────────

test('người ĐÃ đồng ý không được tính vào mẫu số', () => {
    assert.match(
        maHook,
        /lay\(quyen !== 'granted'\)/,
        'Nhánh `granted` được đếm. Ở đó KHÔNG hộp thoại nào bật — nên mỗi lượt người đã đồng ý '
            + 'quay lại sẽ cộng thêm một `hoi` và một `cho_phep`, và tỉ lệ cho phép tiến dần tới '
            + '100% mà không có ai mới đồng ý. Đó là kiểu sai tệ nhất: nó xác nhận đúng cái quyết '
            + 'định đang cần kiểm chứng.',
    );
});

test('người đã bị chặn từ trước được đếm riêng, một lượt', () => {
    assert.match(maHook, /ghiDoDac\('da_chan_tu_truoc'\)/, 'Không đếm nhánh đã chặn sẵn.');

    assert.match(
        maHook,
        /daDemDaChan/,
        'Nhánh đã-chặn-sẵn không có gì chặn đếm hai lần. React dựng hai lần ở chế độ nghiêm '
            + 'ngặt, và effect này chạy lại theo.',
    );
});

test('nút "Thử lại" không được tính vào mẫu số', () => {
    const dau = maHook.indexOf('const hoiLai');

    assert.ok(dau > 0, 'Không tìm thấy `hoiLai`.');

    assert.match(
        maHook.slice(dau),
        /lay\(false\)/,
        'Nút "Thử lại" được đếm. Câu hỏi cần trả lời là "hỏi KHI MỞ TRANG có đáng không", và '
            + 'một lượt hỏi sau cú bấm của người dùng có tỉ lệ cho phép cao hơn hẳn — trộn vào '
            + 'thì mẫu số lẫn hai loại lượt khác bản chất.',
    );
});

test('mẫu số được ghi trước khi hộp thoại bật', () => {
    const dau = maHook.indexOf("ghiDoDac('hoi')");
    const goi = maHook.indexOf('navigator.geolocation.getCurrentPosition(');

    assert.ok(dau > 0, 'Không ghi mẫu số `hoi`.');
    assert.ok(
        dau < goi,
        'Mẫu số ghi SAU lời gọi `getCurrentPosition`. Người dùng đóng tab trong lúc hộp thoại '
            + 'đang mở thì lượt đó mất khỏi mẫu số nhưng ở lại trong tử số của lần sau.',
    );
});

// ── Toạ độ không đi theo lượt đếm ──────────────────────────────────────────

test('không lượt đếm nào mang theo toạ độ', () => {
    // Cùng luật với `vi-tri.test.mjs`: vị trí của một người chỉ được đi trong
    // lời gọi API cùng nguồn gốc phục vụ chính tính năng đó. Một bảng đếm gộp
    // không có cột nào cho toạ độ, nên gửi lên là gửi vào hư không — và vào
    // nhật ký máy chủ.
    assert.doesNotMatch(maDoDac, /lat|lng|coords|latitude|longitude/, 'Đường đo đạc chạm tới toạ độ.');

    for (const tham of [...maHook.matchAll(/ghiDoDac\(([^)]*)\)/g)].map((m) => m[1])) {
        assert.doesNotMatch(
            tham,
            /lat|lng|coords|p\./,
            `Lời gọi \`ghiDoDac(${tham})\` mang theo dữ liệu vị trí.`,
        );
    }
});
