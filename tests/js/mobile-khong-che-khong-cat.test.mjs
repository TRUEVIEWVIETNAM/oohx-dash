// ════════════════════════════════════════════════════════════════════════════
// Bốn lỗi mobile đo được trên production ngày 20/10/2026, mỗi lỗi một chốt.
//
// Chủ dự án gửi hai ảnh chụp `/explore` và `/map` trên điện thoại. Đo lại ở
// khung 390×844 trong trình duyệt thì ra bốn thứ, và cả bốn đều **không có
// test nào** bắt được — jsdom không chạy layout, `khoi-giao-dien.mjs` đọc HTML
// đã render chứ không chạy CSS, và không chốt nào đọc tới `dung-ban-do.ts`.
//
// Tệp này không mở rộng tầm đó ra tới cách trang HIỆN RA — điều đó vẫn ngoài
// tầm. Nó đóng một khoảng hẹp hơn và kiểm được bằng văn bản: **bốn dòng đã
// sửa phải còn nguyên ở đó.** Mỗi chốt mang theo số đo của lỗi nó chặn, để
// lần sau ai gỡ cũng đọc được cái giá.
//
// ── Bốn lỗi ────────────────────────────────────────────────────────────────
//
//   • `--nav: 60px` chừa chỗ cho thanh điều hướng dưới của bản **Blade**.
//     Không trang Next nào render thanh đó, nhưng 15 quy tắc trong
//     `frontpage.css` vẫn trừ hao nó: nút "Bộ lọc" ở `/explore` và "Danh
//     sách" ở `/map` nổi cách đáy **72px** — lơ lửng giữa nội dung, đè lên
//     thẻ bên dưới và lên dòng ghi công của bản đồ.
//
//   • `.sc-title` không cho chuỗi dài xuống dòng. Tên kho thật có dạng mã,
//     không một khoảng trắng: `OOHX_LED_YBI_YEN_BAI_NGUYEN_VAN_CU_1`. Đo ở
//     390px: nội dung 307px trong ô 197px, `overflow:hidden` cắt phũ giữa
//     chữ và **không có dấu "…"**.
//
//   • `.inv-grid .sc-title` clamp **một** dòng ở mobile. Với một dòng ở
//     197px thì `..._NGUYEN_VAN_CU_1` và `..._CU_2` cắt ra giống hệt nhau —
//     người mua không phân biệt nổi hai vị trí khác nhau.
//
//   • `L.control.attribution({ prefix: true })`. `prefix` của Leaflet là
//     **chuỗi HTML** in trước phần ghi công, không phải cờ bật/tắt, nên dòng
//     dưới bản đồ production đọc là "true | © MapTiler · © OpenStreetMap".
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const doc = (duong) => readFileSync(join(GOC, duong), 'utf8');

/** Thân của quy tắc CSS đầu tiên có selector khớp đúng `selector`. */
function thanQuyTac(css, selector) {
    const re = new RegExp(
        '(?:^|[},])\\s*' + selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*\\{([^}]*)\\}',
        'm',
    );

    return (css.match(re) || [])[1] ?? null;
}

test('`--nav` về 0 trên các trang do Next phục vụ', () => {
    const css = doc('webapp/app/globals.css');

    assert.match(
        css,
        /--nav:\s*0px/,
        'Không còn `--nav: 0px` trong `globals.css`. 15 quy tắc của '
            + '`frontpage.css` trừ hao `var(--nav)` để né thanh điều hướng dưới '
            + 'của bản Blade — thanh mà không trang Next nào render. Bỏ dòng này '
            + 'là nút "Bộ lọc" và "Danh sách" lại nổi cách đáy 72px, đè lên nội '
            + 'dung.',
    );
});

test('bản Blade vẫn giữ `--nav` thật, vì `.bnav` ở đó có thật', () => {
    const css = doc('resources/css/frontpage.css');

    assert.match(
        css,
        /--nav:\s*60px/,
        '`frontpage.css` mất `--nav: 60px`. Bản Blade **có** thanh điều hướng '
            + 'dưới (`.bnav`, dựng ở `frontpage/partials/mobile-nav.blade.php`), '
            + 'nên ở đó việc trừ hao là đúng. Chỗ phải về 0 là `globals.css` của '
            + 'Next, không phải tệp này.',
    );

    assert.match(
        css,
        /\.bnav\{[^}]*position:fixed/,
        '`.bnav` không còn là thanh cố định. Nếu bản Blade đã bỏ nó thì '
            + '`--nav` ở tệp này cũng hết lý do tồn tại — và chốt trên đổi theo.',
    );
});

test('`.sc-title` cho tên dạng mã xuống dòng', () => {
    const than = thanQuyTac(doc('resources/css/frontpage.css'), '.sc-title');

    assert.ok(than, 'Không tìm thấy quy tắc `.sc-title`.');

    assert.match(
        than,
        /overflow-wrap:\s*anywhere/,
        '`.sc-title` mất `overflow-wrap:anywhere`. Tên kho thật không có khoảng '
            + 'trắng nào (`OOHX_LED_YBI_YEN_BAI_NGUYEN_VAN_CU_1`), nên thiếu nó '
            + 'thì chuỗi không xuống dòng được: `overflow:hidden` cắt phũ giữa '
            + 'chữ, không dấu "…", và người đọc không biết là còn chữ.',
    );
});

test('thẻ kho ở mobile cho tiêu đề hai dòng', () => {
    const css = doc('resources/css/frontpage.css');

    // Quy tắc nằm trong `@media(max-width:767px)`; lấy đúng dòng của nó.
    const dong = css
        .split('\n')
        .find((l) => /\.inv-grid \.sc-title\{/.test(l) && /line-clamp/.test(l));

    assert.ok(dong, 'Không tìm thấy `.inv-grid .sc-title` có `line-clamp`.');

    assert.match(
        dong,
        /-webkit-line-clamp:\s*2/,
        'Thẻ kho ở mobile lại clamp một dòng. Ở 197px, một dòng cắt '
            + '`..._NGUYEN_VAN_CU_1` và `..._CU_2` thành hai chuỗi **giống hệt '
            + 'nhau** — hai vị trí khác nhau trông như một.',
    );
});

test('ô tick nói được nó là ô chọn trên thiết bị cảm ứng', () => {
    const css = doc('resources/css/frontpage.css');

    assert.match(
        css,
        /@media\(hover:none\)\{\.o-tick\{color:[^}]+\}\}/,
        'Mất quy tắc `@media(hover:none)` cho `.o-tick`. Màu dấu tick khi chưa '
            + 'chọn là `transparent` và chỉ lộ ra khi trỏ chuột vào; trên điện '
            + 'thoại không có hover, nên ô hiện ra như một ô trắng 44×44 rỗng đè '
            + 'lên ảnh và không ai đoán được nó làm gì.',
    );
});

test('bản đồ không in chữ "true" dưới chân', () => {
    const ts = doc('webapp/lib/dung-ban-do.ts');

    assert.doesNotMatch(
        ts,
        /attribution\(\{[^}]*prefix:\s*true/,
        '`L.control.attribution({ prefix: true })` đã quay lại. `prefix` là '
            + '**chuỗi HTML** in trước phần ghi công, không phải cờ bật/tắt: '
            + 'Leaflet nối thẳng giá trị vào DOM, nên dòng dưới bản đồ đọc là '
            + '"true | © MapTiler · © OpenStreetMap". Cách tắt là `false`.',
    );

    assert.match(
        ts,
        /attribution\(\{[^}]*prefix:\s*false/,
        'Không còn lời gọi `attribution({ ... prefix: false })` nào.',
    );
});
