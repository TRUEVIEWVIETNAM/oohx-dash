// ════════════════════════════════════════════════════════════════════════════
// Thẻ chi tiết mở CẠNH danh sách, và nó mở mà không rời trang bản đồ.
//
// Lát 5 của `docs/PLAN-GOP-EXPLORE-BAN-DO.md` (22/10/2026).
//
// ── Vì sao tệp này tồn tại ──────────────────────────────────────────────────
//
// Cả lát này nằm ở **ba dòng cơ chế**, và cả ba đều hỏng im lặng:
//
//   • `onClickCapture` thay vì `onClick`. Thẻ màn hình là một `<Link>` của
//     Next, và `Link` gắn `onClick` lên chính thẻ `<a>`; React gọi handler của
//     con trước của cha, nên một handler bubble trên khung cuộn chạy SAU khi
//     Next đã bắt đầu điều hướng. Đo thấy ở lần chạy đầu: bấm một thẻ là cả
//     trang bản đồ biến mất — khung nhìn, tập pin và chỗ đang đứng trong danh
//     sách đi theo. Đột biến đổi lại thành `onClick` **không làm đỏ test nào**
//     trước khi có tệp này.
//
//   • `history.pushState` thay vì `router.push`. Cái sau là một lượt điều
//     hướng thật và cũng tháo cả trang.
//
//   • `popstate` đọc lại slug từ đường dẫn, không chỉ đóng. Đóng thẳng thì
//     nút Forward đổi địa chỉ mà không mở gì.
//
// Không canh được ở đây: thẻ có **hiện ra đúng chỗ** không. Việc đó cần
// layout thật, và đã đo bằng Chrome — số đo nằm trong mô tả PR.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const doc = (duong) => readFileSync(join(GOC, duong), 'utf8');

const KHUNG = doc('webapp/components/KhoiExplore.tsx');
const THE = doc('webapp/components/TheChiTiet.tsx');

test('cú bấm thẻ bị chặn ở pha CAPTURE, không phải bubble', () => {
    assert.match(
        KHUNG,
        /onClickCapture=\{bamThe\}/,
        'Khung cuộn của panel không còn chặn ở pha capture. Thẻ là một `<Link>` của Next và '
            + '`Link` gắn `onClick` lên chính thẻ `<a>`, nên một handler bubble trên cha chạy '
            + 'sau khi Next đã bắt đầu điều hướng — bấm một thẻ là mất cả trang bản đồ.',
    );

    assert.doesNotMatch(
        KHUNG,
        /onClick=\{bamThe\}/,
        'Vẫn còn một `onClick={bamThe}` ở đâu đó: hai handler cho cùng một cú bấm, và cái '
            + 'bubble chạy sau khi điều hướng đã bắt đầu.',
    );

    // `stopPropagation` là thứ cắt đường tới `onClick` của `Link`;
    // `preventDefault` là thứ cắt đường điều hướng gốc của trình duyệt. Thiếu
    // một trong hai thì thẻ mở ra VÀ trang cũng đi.
    const than = KHUNG.slice(KHUNG.indexOf('const bamThe'));
    const thanBam = than.slice(0, than.indexOf('[moChiTiet],'));

    assert.match(thanBam, /e\.preventDefault\(\)/, '`bamThe` không gọi `preventDefault`.');
    assert.match(thanBam, /e\.stopPropagation\(\)/, '`bamThe` không gọi `stopPropagation`.');
});

test('ba hành vi trình duyệt KHÔNG bị chặn', () => {
    const than = KHUNG.slice(KHUNG.indexOf('const bamThe'));
    const thanBam = than.slice(0, than.indexOf('[moChiTiet],'));

    assert.match(
        thanBam,
        /e\.button !== 0/,
        'Bấm giữa (mở tab mới) bị nuốt. Đó là một hành vi người dùng cố ý gọi.',
    );

    assert.match(
        thanBam,
        /metaKey \|\| e\.ctrlKey \|\| e\.shiftKey \|\| e\.altKey/,
        'Ctrl/Cmd/Shift/Alt + bấm bị nuốt, nên không mở được thẻ ra tab mới.',
    );

    assert.match(
        thanBam,
        /closest\('\.o-tick'\)/,
        'Cú bấm vào ô tick bị hiểu thành bấm thẻ. Ô tick nằm LỒNG trong thẻ vì cả thẻ là một '
            + '`<a>`, nên không loại nó ra thì thêm vào danh sách cũng mở chi tiết.',
    );
});

test('địa chỉ đổi bằng pushState, KHÔNG bằng router.push', () => {
    assert.match(
        KHUNG,
        /window\.history\.pushState\(null, '', `\/explore\/\$\{encodeURIComponent\(slug\)\}`\)/,
        'Không còn đổi địa chỉ bằng `history.pushState`.',
    );

    assert.doesNotMatch(
        KHUNG,
        /router\.push\(`?\/explore\//,
        '`router.push` sang `/explore/{slug}` là một lượt điều hướng thật: nó tháo cả trang '
            + 'bản đồ và dựng lại trang chi tiết, tức mất khung nhìn, mất tập pin và mất chỗ '
            + 'đang đứng trong danh sách — đúng thứ lát 5 sinh ra để giữ.',
    );
});

test('Back và Forward đều đọc lại slug từ đường dẫn', () => {
    assert.match(
        KHUNG,
        /addEventListener\('popstate'/,
        'Không còn lắng nghe `popstate`, nên nút Back đổi địa chỉ mà thẻ vẫn mở.',
    );

    assert.match(
        KHUNG,
        /window\.location\.pathname\.match\(/,
        'Trình xử lý `popstate` không đọc lại đường dẫn. Đóng thẳng thì nút Forward — quay '
            + 'lại thẻ vừa xem — chỉ đổi địa chỉ mà không mở gì.',
    );
});

test('thẻ lấy nội dung từ API, và bỏ lượt gọi cũ khi đổi slug', () => {
    assert.match(
        THE,
        /fetch\(`\/api\/v2\/screens\/\$\{encodeURIComponent\(slug\)\}`/,
        '`TheChiTiet` không còn gọi `/api/v2/screens/{slug}`.',
    );

    assert.match(
        THE,
        /new AbortController\(\)/,
        'Không còn huỷ lượt gọi cũ. Bấm nhanh hai pin thì hai lời gọi chồng nhau, và cái cũ '
            + 'về sau sẽ vẽ đè lên cái mới — cùng cái bẫy `seqRef` chặn ở `useBanDoPin`.',
    );
});

test('khối giá dùng lại PanelGia của trang đầy đủ, không viết bản thứ hai', () => {
    assert.match(
        THE,
        /import \{ PanelGia \}/,
        'Thẻ chi tiết không còn dùng `PanelGia`. Hai bản tính tiền cho cùng một màn hình là '
            + 'hai con số sẽ trôi khỏi nhau, và người mua thấy cả hai.',
    );
});

test('popup pin mở thẻ tại chỗ, nhưng liên kết vẫn là liên kết thật', () => {
    const popup = doc('webapp/components/PopupBanDo.tsx');

    assert.match(popup, /khiMoChiTiet\?:/, '`PopupBanDo` không nhận `khiMoChiTiet`.');

    // `href` phải còn: chặn cú bấm là một lớp PHỦ lên hành vi gốc, không phải
    // thay nó. Bỏ `href` thì bấm giữa không mở tab mới, và ở `/map` — nơi
    // không truyền callback — nút "Xem chi tiết" thành một nút chết.
    assert.match(
        popup,
        /href=\{`\/explore\/\$\{dau\?\.slug \?\? ''\}`\}/,
        'Nút "Xem chi tiết" trong popup mất `href`. Ở `/map` không có thẻ nào để mở, nên ở '
            + 'đó nó phải vẫn là một liên kết thật.',
    );
});
