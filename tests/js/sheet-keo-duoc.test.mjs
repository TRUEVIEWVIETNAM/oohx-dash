// ════════════════════════════════════════════════════════════════════════════
// Ngăn kéo lên phải kéo được thật (21/10/2026).
//
// Lỗi được sửa: tay nắm hình viên thuốc ở đỉnh `.map-panel.open` (`/map`, gỡ
// 22/10/2026) và `.sidebar.open` (`/products`) là ký hiệu quy ước của một bottom
// sheet kéo được, nhưng từ lát 6 tới 20/10/2026 nó **chỉ đóng ngăn khi bấm**.
// Kéo xuống: không gì xảy ra. Kéo lên: không gì xảy ra.
//
// ── Tệp này canh được gì, và KHÔNG canh được gì ──────────────────────────────
//
// Không canh được: **một cử chỉ thật**. jsdom không chạy layout và không có
// `setPointerCapture`; `khoi-giao-dien.mjs` đọc HTML đã render chứ không chạy
// sự kiện. Việc ngón tay trên kính có kéo ngăn lên hay không thì vẫn phải đo
// bằng Chrome, và đã đo — số đo nằm trong mô tả PR.
//
// Canh được, và đó là lý do luật của cử chỉ bị tách ra khỏi DOM:
//
//   • **Phép quyết định** ở `webapp/lib/keo-sheet.ts` — gọi thẳng bằng số.
//     Ngưỡng vẩy, điểm nghỉ gần nhất, và cái bẫy "màn hình thấp quá nên bậc
//     thu còn cao hơn bậc rộng" đều là số học, và số học thì chốt được.
//   • **Hai hằng số dùng ở hai nơi**: `CAO_TOI_THIEU` và `KHE_TREN` nằm trong
//     JS để kẹp cú kéo, và nằm lại trong CSS để giữ ngăn sau khi thả tay. Lệch
//     nhau thì ngăn nảy một đoạn đúng lúc nhả ngón tay — một lỗi không ai nhìn
//     ra khi đọc riêng từng tệp. Ở đây hai bên được đối chiếu bằng số.
//   • **Việc nối dây**: ba chỗ gọi có thật dùng hook, chứ không phải chỉ có
//     hook nằm đó. Lỗi cũ chính là một tay nắm trông như kéo được mà không có
//     ai gắn cử chỉ vào.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const doc = (duong) => readFileSync(join(GOC, duong), 'utf8');

// Node 24 bóc chú thích kiểu ngay khi nạp, nên module TypeScript mà Next dựng
// lên production chạy được ở đây mà không cần bước biên dịch nào.
const ks = await import('../../webapp/lib/keo-sheet.ts');

/** Thân của quy tắc CSS đầu tiên có selector khớp đúng `selector`. */
function thanQuyTac(css, selector) {
    const re = new RegExp(
        '(?:^|[},])\\s*' + selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{([^}]*)\\}',
        'm',
    );

    return (css.match(re) || [])[1] ?? null;
}

/**
 * Điều kiện `@media` gần nhất đứng trước `selector`.
 *
 * Phép xấp xỉ: nó không phân tích cây lồng nhau, chỉ lấy `@media` cuối cùng
 * trước vị trí selector. Đủ cho việc cần biết ở đây — quy tắc mới có nằm trong
 * khối `max-width:1023px` hay bị để trần — và sai thì sai về phía báo động,
 * không về phía bỏ qua.
 */
function mediaTruocDo(css, selector) {
    const i = css.indexOf(selector);

    if (i < 0) return null;

    const truoc = css.slice(0, i);
    const j = truoc.lastIndexOf('@media');

    return j < 0 ? null : truoc.slice(j, truoc.indexOf('{', j));
}

// ── Phép quyết định: chiều cao tối đa ───────────────────────────────────────

test('bậc rộng dừng lại dưới header, chừa đúng KHE_TREN', () => {
    // Đáy ngăn ở 784px (vùng nhìn 844, trừ 60px thanh điều hướng), header 52px.
    assert.equal(ks.caoToiDa(784, 52), 784 - 52 - ks.KHE_TREN);
});

test('màn hình thấp quá thì bậc rộng vẫn còn CAO_TOI_THIEU để kéo lại', () => {
    // Bàn phím ảo đang chiếm chỗ: đáy ngăn chỉ còn 60px dưới header.
    assert.equal(ks.caoToiDa(100, 52), ks.CAO_TOI_THIEU);

    // Nếu không có sàn thì ra số âm, và ngăn cao 0px thì không ai kéo lên lại.
    assert.ok(ks.caoToiDa(100, 52) > 0);
});

// ── Phép quyết định: chiều cao trong lúc kéo ────────────────────────────────

test('kéo xuống thì ngăn thấp theo ngón tay, và xuống được tới 0', () => {
    assert.equal(ks.caoKhiKeo(400, 120, 700), 280);

    // Chặn dưới ở 0, KHÔNG ở bậc thu: đoạn dưới bậc thu là phần xem trước của
    // việc đóng — ngăn trôi khỏi màn hình theo ngón tay trước khi thả tay.
    assert.equal(ks.caoKhiKeo(400, 900, 700), 0);
});

test('kéo lên thì dừng ở bậc rộng, không trèo lên header', () => {
    assert.equal(ks.caoKhiKeo(400, -200, 700), 600);
    assert.equal(ks.caoKhiKeo(400, -5000, 700), 700);
});

// ── Phép quyết định: thả tay thì về đâu ─────────────────────────────────────

const NGAN = { caoThu: 400, caoRong: 700 };

test('vẩy xuống đi MỘT bậc, không đóng thẳng từ bậc rộng', () => {
    assert.equal(ks.mocKhiTha({ ...NGAN, cao: 690, vy: 1.4 }), 'thu');
    assert.equal(ks.mocKhiTha({ ...NGAN, cao: 390, vy: 1.4 }), 'dong');
});

test('vẩy lên mở rộng, và từ dưới bậc thu thì về bậc thu', () => {
    assert.equal(ks.mocKhiTha({ ...NGAN, cao: 410, vy: -1.4 }), 'rong');
    assert.equal(ks.mocKhiTha({ ...NGAN, cao: 150, vy: -1.4 }), 'thu');
});

test('vẩy thắng vị trí: đi 20px mà nhanh thì vẫn đóng', () => {
    // Không có luật vẩy thì ca này về 'thu' vì 380 còn rất gần 400, và người
    // dùng phải kéo chậm hết nửa màn hình mới đóng được ngăn.
    assert.equal(ks.mocKhiTha({ ...NGAN, cao: 380, vy: ks.NGUONG_VAY }), 'dong');

    // Chậm hơn ngưỡng một chút thì quay về luật gần nhất.
    assert.equal(ks.mocKhiTha({ ...NGAN, cao: 380, vy: ks.NGUONG_VAY - 0.01 }), 'thu');
});

test('kéo chậm thì về điểm nghỉ gần nhất', () => {
    assert.equal(ks.mocKhiTha({ ...NGAN, cao: 430, vy: 0.1 }), 'thu');
    assert.equal(ks.mocKhiTha({ ...NGAN, cao: 600, vy: -0.1 }), 'rong');
    assert.equal(ks.mocKhiTha({ ...NGAN, cao: 90, vy: 0.1 }), 'dong');
});

test('ngang nhau thì giữ ngăn lại, không đóng', () => {
    // Đúng giữa 0 và bậc thu. Thả tay là một hành động mơ hồ, và đóng là hành
    // động mất nhiều nhất — mất cả danh sách lẫn chỗ đang đọc.
    assert.equal(ks.mocKhiTha({ ...NGAN, cao: 200, vy: 0 }), 'thu');
});

test('màn hình thấp: hai bậc trùng nhau, ngăn chỉ còn mở và đóng', () => {
    // CSS có thể tính ra bậc thu (ví dụ 40dvh) CAO HƠN cả bậc rộng khi vùng
    // nhìn bị bàn phím ảo ăn mất. Không kẹp thì "rộng" thấp hơn "thu" — kéo
    // lên lại làm ngăn thấp đi.
    const thap = { caoThu: 400, caoRong: 160 };

    assert.equal(ks.mocKhiTha({ ...thap, cao: 160, vy: 0 }), 'thu');
    assert.equal(ks.mocKhiTha({ ...thap, cao: 150, vy: 1.4 }), 'dong');

    // Vẩy lên ở đây về 'thu', KHÔNG về 'rong': bậc rộng không cao hơn bậc thu
    // một px nào, nên gọi nó là "đã mở rộng" là nói sai với cả người dùng
    // trình đọc màn hình (`aria-expanded`) và với lần thả tay sau.
    assert.equal(ks.mocKhiTha({ ...thap, cao: 150, vy: -1.4 }), 'thu');
    assert.equal(ks.mocKhiTha({ ...thap, cao: 160, vy: -1.4 }), 'thu');
});

// ── Hai hằng số nằm ở hai nơi phải khớp ────────────────────────────────────

test('bậc rộng trong CSS dùng đúng CAO_TOI_THIEU và KHE_TREN của JS', () => {
    const than = thanQuyTac(
        doc('resources/css/frontpage.css'),
        '.sidebar.open.sheet-rong,.ex-panel.open.sheet-rong,.ex-ct.sheet-rong',
    );

    assert.ok(than, 'Không tìm thấy quy tắc `.sheet-rong` cho hai ngăn kéo lên.');

    const san = [...than.matchAll(/max\((\d+)px/g)].map((m) => Number(m[1]));
    const khe = [...than.matchAll(/- (\d+)px\)/g)].map((m) => Number(m[1]));

    assert.ok(san.length > 0 && khe.length > 0, 'Quy tắc `.sheet-rong` không còn đọc ra số nào.');

    for (const s of san) {
        assert.equal(
            s,
            ks.CAO_TOI_THIEU,
            'Sàn chiều cao trong CSS lệch khỏi `CAO_TOI_THIEU` của `keo-sheet.ts`. JS kẹp cú '
                + 'kéo theo số của nó, CSS giữ ngăn lại theo số của mình — lệch thì ngăn nảy '
                + 'một đoạn đúng lúc nhả ngón tay.',
        );
    }

    for (const k of khe) {
        assert.equal(
            k,
            ks.KHE_TREN,
            'Khe chừa trên đỉnh ngăn trong CSS lệch khỏi `KHE_TREN` của `keo-sheet.ts`.',
        );
    }
});

test('bậc rộng chỉ áp dụng dưới 1024px', () => {
    const css = doc('resources/css/frontpage.css');

    assert.match(
        mediaTruocDo(css, '.sidebar.open.sheet-rong') ?? '',
        /max-width:\s*1023px/,
        'Quy tắc `.sheet-rong` nằm ngoài khối `@media(max-width:1023px)`. Ba class thắng hai '
            + 'class, nên để trần thì nó đè `height:auto` của cột tĩnh ở desktop — cột lọc và '
            + 'panel danh sách bị ghim chiều cao gần hết màn hình.',
    );
});

test('ngăn đo theo vùng nhìn thật, và có dự phòng cho trình duyệt không biết dvh', () => {
    const than = thanQuyTac(
        doc('resources/css/frontpage.css'),
        '.sidebar.open.sheet-rong,.ex-panel.open.sheet-rong,.ex-ct.sheet-rong',
    );

    assert.match(
        than,
        /height:max\(120px,calc\(100vh[^;]*;height:max\(120px,calc\(100dvh/,
        'Mất cặp `height` của bậc rộng. Dòng `vh` đứng trước là dự phòng cho trình duyệt '
            + 'không biết `dvh`; bỏ nó thì ở đó bậc rộng không có chiều cao nào. Bỏ dòng '
            + '`dvh` thì quay về lỗi của #144: `vh` tính theo viewport khi thanh địa chỉ ẩn.',
    );
});

// ── Tay nắm và transition ──────────────────────────────────────────────────

test('hai tay nắm nhận được cử chỉ kéo thay vì cuộn trang', () => {
    const css = doc('resources/css/frontpage.css');

    for (const sel of ['.drawer-drag', '.ex-panel-tay', '.ex-ct-tay']) {
        const than = thanQuyTac(css, sel);

        assert.ok(than, `Không tìm thấy quy tắc \`${sel}\`.`);

        assert.match(
            than,
            /touch-action:\s*none/,
            `\`${sel}\` mất \`touch-action:none\`. Thiếu nó thì trình duyệt coi cú kéo dọc là `
                + 'cuộn trang và hủy chuỗi pointer event ngay giữa cử chỉ — ngăn đứng im, '
                + 'đúng như trước khi sửa.',
        );
    }
});

test('ngăn trượt tới điểm nghỉ, và đứng im trong lúc ngón tay còn trên kính', () => {
    const css = doc('resources/css/frontpage.css');

    for (const sel of ['.sidebar.open', '.ex-panel.open', '.ex-ct']) {
        assert.match(
            thanQuyTac(css, sel) ?? '',
            /transition:height/,
            `\`${sel}\` không còn transition cho \`height\`: thả tay là ngăn nhảy tới điểm `
                + 'nghỉ thay vì trượt tới đó.',
        );
    }

    assert.match(
        thanQuyTac(css, '.sidebar.open.sheet-keo,.ex-panel.open.sheet-keo,.ex-ct.sheet-keo') ?? '',
        /transition:\s*none/,
        'Mất `.sheet-keo{transition:none}`. Để nguyên transition trong lúc kéo thì ngăn đuổi '
            + 'theo ngón tay chậm một nhịp 260ms, và cử chỉ thành ra nhão.',
    );
});

// ── Việc nối dây ──────────────────────────────────────────────────────────

test('mọi ngăn kéo lên đều gắn hook, không chỉ có hook nằm đó', () => {
    for (const [tep, lop] of [
        // `ScreenMap.tsx` rời bảng này ngày 22/10/2026 (lát 7): `/map` khai
        // tử, và component 398 dòng của nó gỡ cùng trang. Cử chỉ thì ở lại —
        // nó nay có BỐN chỗ dùng dưới đây thay vì ba.
        //
        // Đây là lần nới duy nhất của tệp này, và nó nới vì phần tử biến mất,
        // không vì phép kiểm phiền.
        // `BoLocKho.tsx` rời bảng này ngày 22/10/2026 (lát 3 của việc gộp bản
        // đồ): bộ lọc `/explore` không còn là ngăn kéo lên nào cả — nó là một
        // dải chip nằm trong mạch nội dung, mở ra bảng rớt. Không có tay nắm
        // thì không có cử chỉ nào để canh.
        //
        // `/products` thì vẫn là cột lọc dọc + ngăn kéo, nên `BoLocGoi` ở
        // lại. Đó cũng là lý do `.sidebar.open.sheet-rong` còn tên trong CSS.
        ['webapp/components/BoLocGoi.tsx', 'drawer-drag'],
        // Lát 2 của việc gộp bản đồ (22/10/2026): panel danh sách của
        // `/explore?view=map` là ngăn kéo thứ tư, và nó dùng lại hook chứ
        // không phải bản sao thứ hai của cử chỉ.
        ['webapp/components/KhoiExplore.tsx', 'ex-panel-tay'],
        // Lát 5 (22/10/2026): thẻ chi tiết là ngăn kéo thứ năm. Dưới 1024px
        // nó chồng lên panel danh sách, cùng hình dạng và cùng cử chỉ hai
        // bậc — không phải một bản kéo viết lại.
        ['webapp/components/TheChiTiet.tsx', 'ex-ct-tay'],
    ]) {
        const ma = doc(tep);

        assert.match(ma, /dungKeoSheet</, `${tep} không gọi \`dungKeoSheet\`.`);
        assert.match(ma, /ref=\{refNgan\}/, `${tep} không gắn \`refNgan\` vào chính ngăn.`);
        assert.match(ma, /sheet-rong/, `${tep} không có lớp bậc rộng.`);

        assert.match(
            ma,
            new RegExp(`className="${lop}"\\s*\\{\\.\\.\\.tay\\}`),
            `Tay nắm \`.${lop}\` trong ${tep} không nhận bộ sự kiện của hook. Đúng đây là lỗi `
                + 'cũ: tay nắm có đủ hình dạng của một thứ kéo được, và không có ai gắn cử '
                + 'chỉ vào.',
        );
    }
});

test('ba chỗ đo ra lỗi trong Chrome đều còn lời sửa của nó', () => {
    const hook = doc('webapp/lib/dung-keo-sheet.ts');

    assert.match(
        hook,
        /classList\.toggle\('sheet-rong'/,
        'Hook quay về chỉ gọi `setRong()` để đổi bậc. Đo trên `/map` ở khung 390×844: từ lúc '
            + 'gõ ↑ tới lúc lớp có mặt trong DOM mất **330ms**, vì React render lại cả panel '
            + 'trước. Với cú kéo thì ngăn tụt về bậc cũ rồi mới bò lên bậc mới.',
    );

    assert.match(
        hook,
        /vuaKeo\.current = false;[\s\S]{0,400}keo\.current = \{/,
        'Cờ `vuaKeo` không còn được xoá ở đầu mỗi cử chỉ. `click` không phải lúc nào cũng tới '
            + 'sau một cú kéo, và cờ sót lại ăn mất cú **nhấn** kế tiếp — tay nắm im lặng '
            + 'đúng một lần. Đã gặp thật khi đo.',
    );

    assert.match(
        hook,
        /addEventListener\('transitionend'/,
        'Hook không còn đo lại bậc thu khi animation kết thúc. Lần đo duy nhất có thể rơi vào '
            + 'giữa animation và ghi nhớ 700px cho một bậc thật ra cao 492px; cú kéo sau đó '
            + 'tính mọi ngưỡng theo số sai đó.',
    );
});

test('nhấn tay nắm vẫn đóng ngăn — hành vi cũ không bị cử chỉ mới ăn mất', () => {
    const hook = doc('webapp/lib/dung-keo-sheet.ts');

    assert.match(
        hook,
        /vuaKeo/,
        'Hook không còn nhớ "cử chỉ vừa rồi là một cú kéo". Trình duyệt vẫn bắn `click` sau '
            + 'chuỗi pointer event, nên thiếu chỗ nhớ đó thì MỌI cú kéo kết thúc bằng việc '
            + 'đóng ngăn — cử chỉ mới hỏng đúng theo kiểu nó định sửa.',
    );

    assert.match(
        hook,
        /NGUONG_KEO/,
        'Hook không còn dùng ngưỡng kéo, nên một cú nhấn trôi 3px cũng thành cú kéo.',
    );
});
