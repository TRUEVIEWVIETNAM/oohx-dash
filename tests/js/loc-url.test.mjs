// ════════════════════════════════════════════════════════════════════════════
// Bộ lọc kho dựng URL đúng — kể cả những tham số KHÔNG thuộc bộ lọc.
//
// Lát 3 của việc gộp bản đồ (22/10/2026) đổi cột lọc dọc thành dải chip. Hình
// dạng đổi hết, nhưng thứ dễ vỡ nhất lại không nằm trong hình dạng: nó nằm ở
// hàm dựng URL, vì hàm đó phải mang theo ba tham số của người khác.
//
// ── Lỗi mà tệp này đóng lại ──────────────────────────────────────────────────
//
// Bản cột lọc dọc giữ `q` và `sort` rồi bỏ mọi thứ còn lại. Đúng cho tới
// 22/10/2026, vì cho tới hôm đó `/explore` chỉ có một kiểu xem. Sau lát 2 thì
// `view=map` là "mọi thứ còn lại" — nên tick một ô lọc trong kiểu xem bản đồ
// sẽ ném người dùng về kiểu lưới, mất cả khung nhìn họ vừa kéo tới.
//
// Không có gì đỏ lên khi điều đó xảy ra: `khoi-giao-dien.mjs` đọc HTML máy chủ
// trả về, và đường này chỉ chạy trong trình duyệt sau một cú bấm. Nên luật
// được tách ra khỏi DOM (`webapp/lib/loc-url.ts`) và gọi thẳng bằng số ở đây —
// cùng cách `keo-sheet.ts` bị tách ra khỏi `dung-keo-sheet.ts`.
//
// Lượt gửi KHÔNG-JS đi đường khác (form GET với input ẩn), và đường đó được
// canh bằng `'name="view"'` trong `webapp/test/khoi-giao-dien.mjs`.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';

// Node 24 bóc chú thích kiểu ngay khi nạp, nên module TypeScript mà Next dựng
// lên production chạy được ở đây mà không cần bước biên dịch nào.
const lu = await import('../../webapp/lib/loc-url.ts');

/** `chon` rỗng cho cả năm chiều, rồi ghi đè những chiều cần đặt. */
function chon(dat = {}) {
    const ra = lu.docChon({});

    for (const [k, v] of Object.entries(dat)) ra[k] = new Set(v);

    return ra;
}

// ── Đọc tham số ra ──────────────────────────────────────────────────────────

test('đọc được cả dấu phẩy và dấu gạch dọc, và bỏ khoảng trắng', () => {
    // `FrontpageListingRequest` ở Laravel nhận cả hai dấu, nên bộ lọc cũng
    // phải đọc được cả hai — URL do người dùng dán vào không nhất thiết do
    // trang này sinh ra.
    assert.deepEqual([...lu.tachDanhSach('hanoi,hcm')], ['hanoi', 'hcm']);
    assert.deepEqual([...lu.tachDanhSach('hanoi|hcm')], ['hanoi', 'hcm']);
    assert.deepEqual([...lu.tachDanhSach(' hanoi , hcm ')], ['hanoi', 'hcm']);
    assert.deepEqual([...lu.tachDanhSach('hanoi,,hcm')], ['hanoi', 'hcm']);
    assert.deepEqual([...lu.tachDanhSach(undefined)], []);
    assert.deepEqual([...lu.tachDanhSach('')], []);
});

test('đọc đúng năm chiều, không nhận thêm chiều nào', () => {
    const ra = lu.docChon({ city: 'hcm', page: '7', q: 'bien' });

    assert.deepEqual(Object.keys(ra).sort(), [...lu.KHOA_LOC].sort());
    assert.deepEqual([...ra.city], ['hcm']);
    assert.equal(ra.owner.size, 0);
});

test('đếm gộp cả năm chiều', () => {
    assert.equal(lu.demChon(chon()), 0);
    assert.equal(lu.demChon(chon({ city: ['hcm', 'hanoi'], owner: ['abc'] })), 3);
});

// ── Dựng URL ────────────────────────────────────────────────────────────────

test('không lọc gì thì về đường dẫn trần, không phải "?"', () => {
    assert.equal(lu.duongDanLoc({}, chon()), '/explore');
});

test('nhiều giá trị trong một chiều đi thành MỘT khoá, phân tách bằng dấu phẩy', () => {
    // Hợp đồng đã khai ở `docs/openapi/v2.yaml` (`style: form, explode: false`)
    // và PHP không ghép khoá lặp thành mảng — `city=hanoi&city=hcm` chỉ còn
    // giá trị cuối. Dấu phẩy ra khỏi `URLSearchParams` dưới dạng `%2C`.
    assert.equal(
        lu.duongDanLoc({}, chon({ city: ['hanoi', 'hcm'] })),
        '/explore?city=hanoi%2Chcm',
    );
});

test('KIỂU XEM đi qua nguyên vẹn — lỗi chính mà tệp này canh', () => {
    assert.equal(
        lu.duongDanLoc({ view: 'map' }, chon({ price_status: ['quoted'] })),
        '/explore?price_status=quoted&view=map',
    );

    // Giá trị nào khác `map` thì là kiểu lưới, và kiểu lưới không cần tham số
    // — đúng như `KIEU_XEM_MAC_DINH` ở `page.tsx`.
    assert.equal(lu.duongDanLoc({ view: 'grid' }, chon()), '/explore');
    assert.equal(lu.duongDanLoc({ view: 'xyz' }, chon()), '/explore');
});

test('từ khoá và thứ tự sắp xếp cũng đi qua nguyên vẹn', () => {
    // `q` thuộc ô tìm kiếm lớn, `sort` thuộc thanh kết quả. Bỏ `q` là lặng lẽ
    // xoá từ khoá người dùng vừa gõ, và họ sẽ thấy một tập kết quả rộng hơn
    // mà không hiểu vì sao.
    assert.equal(
        lu.duongDanLoc({ q: 'bien hiflex', sort: 'price_asc', view: 'map' }, chon({ owner: ['abc'] })),
        '/explore?q=bien+hiflex&owner=abc&sort=price_asc&view=map',
    );
});

test('SỐ TRANG thì bị bỏ, và đó là chủ ý', () => {
    // Trang 7 của bộ lọc cũ không còn nghĩa gì sau khi tập kết quả đổi. Giữ
    // nó lại là cách chắc chắn để người dùng thấy "không tìm thấy inventory
    // phù hợp" ngay sau khi họ vừa NỚI bộ lọc ra.
    assert.equal(
        lu.duongDanLoc({ page: '7' }, chon({ screen_type: ['billboard'] })),
        '/explore?screen_type=billboard',
    );
});

test('gỡ một chiều thì khoá đó rời URL, không ở lại dưới dạng rỗng', () => {
    const truoc = chon({ city: ['hcm'], screen_type: ['billboard'] });

    assert.equal(
        lu.duongDanLoc({}, truoc),
        '/explore?screen_type=billboard&city=hcm',
    );

    // Đúng việc nút gỡ trên chip làm: thay tập của một chiều bằng tập rỗng.
    assert.equal(
        lu.duongDanLoc({}, { ...truoc, city: new Set() }),
        '/explore?screen_type=billboard',
    );
});

test('tham số lạ trong URL không được mang sang', () => {
    // Chỉ ba tham số được đi qua, và chúng được liệt kê bằng tên. Một tham số
    // bám theo mọi lần áp dụng bộ lọc là cách URL dài dần ra cho tới lúc
    // không ai đọc được, và là cách một tham số theo dõi sống mãi.
    assert.equal(
        lu.duongDanLoc({ utm_source: 'fb', ref: 'x', page: '3' }, chon()),
        '/explore',
    );
});

test('thứ tự khoá cố định, nên cùng một bộ lọc cho cùng một URL', () => {
    // Cùng một bộ lọc mà ra hai chuỗi khác nhau là hai bản cache, hai URL
    // được index, và hai dòng khác nhau trong báo cáo lưu lượng.
    const a = lu.duongDanLoc({}, chon({ city: ['hcm'], owner: ['abc'], screen_type: ['led'] }));
    const b = lu.duongDanLoc({}, chon({ owner: ['abc'], screen_type: ['led'], city: ['hcm'] }));

    assert.equal(a, b);
    assert.equal(a, '/explore?screen_type=led&city=hcm&owner=abc');
});
