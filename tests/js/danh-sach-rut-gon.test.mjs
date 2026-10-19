// ════════════════════════════════════════════════════════════════════════════
// Danh sách rút gọn — logic lưu trong `localStorage` (lát 4).
//
// Khác mọi tệp còn lại trong thư mục này: chúng trích script ra khỏi
// `.blade.php`, còn tệp này **import thẳng module TypeScript** mà Next dựng
// lên production (`webapp/lib/danh-sach-rut-gon.ts`). Node 24 bóc chú thích
// kiểu ngay khi nạp, nên không có bước biên dịch và không có bản sao nào để
// trôi khỏi bản thật.
//
// Vì sao cần canh ở đây chứ không chỉ đo tay trong Chrome: ba trường hợp dưới
// đây hoặc không bao giờ xảy ra trên máy tôi, hoặc tốn vài phút mỗi lần dựng
// lại — `localStorage` bị chặn, dữ liệu cũ sai hình dạng, và chạm trần 50.
// ════════════════════════════════════════════════════════════════════════════

import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body></body></html>', {
    url: 'https://oohx.net/explore',
});

globalThis.window = dom.window;
globalThis.Event = dom.window.Event;

const ds = await import('../../webapp/lib/danh-sach-rut-gon.ts');

function muc(slug, ten = 'Màn hình ' + slug, thanhPho = 'Hà Nội', owner = 'Owner A') {
    return { slug, ten, thanhPho, owner };
}

beforeEach(() => {
    dom.window.localStorage.clear();
    ds.quenBanChup();
});

test('thêm rồi đọc lại thấy đúng mục đó', () => {
    assert.deepEqual(ds.doc(), []);

    assert.deepEqual(ds.them(muc('a')), { ok: true });

    assert.equal(ds.doc().length, 1);
    assert.equal(ds.doc()[0].slug, 'a');
    assert.ok(ds.daChon('a'));
});

test('danh sách sống qua một lượt tải lại trang', () => {
    ds.them(muc('a'));
    ds.them(muc('b'));

    // Mô phỏng tải lại: quên bản chụp trong bộ nhớ, giữ nguyên localStorage.
    ds.quenBanChup();

    assert.deepEqual(
        ds.doc().map((m) => m.slug),
        ['a', 'b'],
        'Danh sách phải nằm trong localStorage, không chỉ trong bộ nhớ của trang.',
    );
});

test('thêm cùng một slug hai lần không tạo bản thứ hai', () => {
    ds.them(muc('a'));

    assert.deepEqual(ds.them(muc('a')), { ok: false, lyDo: 'da_co' });
    assert.equal(ds.doc().length, 1);
});

test('chạm trần 50 thì từ chối, và nói rõ vì sao', () => {
    for (let i = 0; i < ds.TOI_DA; i++) ds.them(muc('s' + i));

    assert.equal(ds.doc().length, 50);

    assert.deepEqual(
        ds.them(muc('mot-nua')),
        { ok: false, lyDo: 'day' },
        'Trần phải là 50, đúng bằng MAX_PER_PAGE của /api/v2/screens: hai đầu '
            + 'cùng giới hạn thì không có trạng thái "khoanh được 80 nhưng gửi '
            + 'chỉ nhận 50".',
    );

    assert.equal(ds.doc().length, 50, 'Mục thứ 51 vẫn lọt vào danh sách.');
});

test('tick hai lần là bỏ chọn', () => {
    ds.dao(muc('a'));
    assert.ok(ds.daChon('a'));

    ds.dao(muc('a'));
    assert.equal(ds.daChon('a'), false);
});

test('đếm theo nhóm: vị trí, tỉnh thành, media owner', () => {
    ds.them(muc('a', 'A', 'Hà Nội', 'Owner A'));
    ds.them(muc('b', 'B', 'Hà Nội', 'Owner B'));
    ds.them(muc('c', 'C', 'Đà Nẵng', 'Owner A'));

    assert.deepEqual(ds.demTheoNhom(ds.doc()), { viTri: 3, tinh: 2, owner: 2 });
});

test('đếm bỏ qua mục thiếu tỉnh thành thay vì tính nó thành một tỉnh', () => {
    ds.them({ slug: 'a', ten: 'A' });
    ds.them(muc('b', 'B', 'Hà Nội', 'Owner B'));

    assert.deepEqual(
        ds.demTheoNhom(ds.doc()),
        { viTri: 2, tinh: 1, owner: 1 },
        '`undefined` lọt vào Set sẽ thành "1 tỉnh thành" cho một mục không '
            + 'biết nằm ở đâu.',
    );
});

test('dữ liệu hỏng trong localStorage không làm vỡ trang', () => {
    dom.window.localStorage.setItem(ds.KHOA, 'khong-phai-json');
    ds.quenBanChup();
    assert.deepEqual(ds.doc(), []);

    dom.window.localStorage.setItem(ds.KHOA, '{"khong":"phai mang"}');
    ds.quenBanChup();
    assert.deepEqual(ds.doc(), []);

    // Một phần tử hỏng không được kéo theo cả danh sách.
    dom.window.localStorage.setItem(
        ds.KHOA,
        JSON.stringify([{ slug: 'a', ten: 'A' }, { khong: 'co slug' }, null, 'chuoi']),
    );
    ds.quenBanChup();

    assert.deepEqual(
        ds.doc().map((m) => m.slug),
        ['a'],
        'Phần tử hợp lệ phải sống sót qua một bản ghi hỏng — dữ liệu này nằm '
            + 'trong tay người dùng và có thể do một phiên bản cũ của trang ghi.',
    );
});

test('localStorage bị chặn thì đọc ra rỗng, không ném', () => {
    const that = dom.window.localStorage.getItem.bind(dom.window.localStorage);

    dom.window.localStorage.getItem = () => {
        throw new Error('SecurityError: site data bị chặn');
    };

    ds.quenBanChup();

    assert.deepEqual(
        ds.doc(),
        [],
        'Chế độ riêng tư hoặc site data bị chặn đều ném ở đây. Trang phải '
            + 'chạy tiếp, chỉ mất tính nhớ.',
    );

    dom.window.localStorage.getItem = that;
});

test('ghi không được cũng không ném ra ngoài', () => {
    const that = dom.window.localStorage.setItem.bind(dom.window.localStorage);

    dom.window.localStorage.setItem = () => {
        throw new Error('QuotaExceededError');
    };

    assert.doesNotThrow(() => ds.them(muc('a')));

    dom.window.localStorage.setItem = that;
});

test('mỗi thay đổi phát một sự kiện để các ô tick khác thấy nhau', () => {
    let lan = 0;
    const thoi = ds.dangKy(() => (lan += 1));

    ds.them(muc('a'));
    ds.bo('a');
    ds.xoaHet();

    thoi();
    ds.them(muc('b'));

    assert.equal(
        lan,
        3,
        'Thiếu sự kiện thì tick trên bản đồ không làm ô tick của thẻ đổi theo '
            + '— `storage` chỉ bắn sang TAB KHÁC, không bắn trong cùng tab.',
    );
});

test('đọc hai lần không đổi gì thì trả CÙNG một mảng', () => {
    ds.them(muc('a'));

    assert.equal(
        ds.doc(),
        ds.doc(),
        '`useSyncExternalStore` so bản chụp bằng `Object.is`. Trả mảng mới mỗi '
            + 'lần là vòng lặp render vô tận.',
    );
});

test('bản chụp phía máy chủ luôn là cùng một mảng rỗng', () => {
    assert.deepEqual(ds.docTrenMayChu(), []);
    assert.equal(ds.docTrenMayChu(), ds.docTrenMayChu());
});
