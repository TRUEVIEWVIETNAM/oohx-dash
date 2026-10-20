// ════════════════════════════════════════════════════════════════════════════
// Xuất danh sách rút gọn ra CSV (lát 7).
//
// Import thẳng `webapp/lib/tep-csv.ts`. Người nhận tệp này là agency Việt
// Nam mở bằng Excel trên Windows, nên hai thứ quyết định tính năng chạy hay
// hỏng đều vô hình khi nhìn tệp trong trình soạn thảo:
//
//   • **BOM UTF-8.** Thiếu ba byte `EF BB BF`, Excel đọc theo bảng mã ANSI và
//     "Hà Nội" ra "HÃ  Ná»™i".
//   • **Bọc ô.** Tên màn hình do media owner nhập — kho thật đã có một tên
//     mang dấu `<`. Một dấu phẩy trong tên làm lệch mọi cột sau nó.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';

const csv = await import('../../webapp/lib/tep-csv.ts');

function muc(slug, ten, thanhPho = 'Hà Nội', owner = 'Owner Một') {
    return { slug, ten, thanhPho, owner };
}

test('tệp mở đầu bằng BOM UTF-8', () => {
    const noi = csv.dungCsv([muc('a', 'Màn hình A')]);

    assert.equal(
        noi.charCodeAt(0),
        0xfeff,
        'Thiếu BOM thì Excel trên Windows đọc tiếng Việt thành ký tự rác.',
    );

    assert.deepEqual(
        [...Buffer.from(noi, 'utf8').subarray(0, 3)],
        [0xef, 0xbb, 0xbf],
        'Ba byte đầu phải là EF BB BF khi ghi ra UTF-8.',
    );
});

test('hàng tiêu đề đúng thứ tự cột', () => {
    const dong = csv.dungCsv([]).replace(csv.BOM, '').trim().split('\r\n');

    assert.deepEqual(dong[0].split(','), [...csv.COT]);
});

test('mỗi vị trí một dòng, đánh số từ 1 theo thứ tự danh sách', () => {
    const noi = csv.dungCsv([muc('b', 'B'), muc('a', 'A'), muc('c', 'C')]);
    const dong = noi.replace(csv.BOM, '').trim().split('\r\n');

    assert.equal(dong.length, 4, 'Một dòng tiêu đề và ba dòng dữ liệu.');
    assert.ok(dong[1].startsWith('1,B,'), 'Thứ tự phải là thứ tự người dùng tick.');
    assert.ok(dong[2].startsWith('2,A,'));
    assert.ok(dong[3].startsWith('3,C,'));
});

test('xuống dòng là CRLF', () => {
    const noi = csv.dungCsv([muc('a', 'A')]);

    assert.ok(noi.includes('\r\n'), 'Excel cũ nối cả tệp thành một dòng khi chỉ có LF.');
    assert.equal(/[^\r]\n/.test(noi), false, 'Không được có LF đứng một mình.');
});

test('đường dẫn đầy đủ ghép từ origin truyền vào', () => {
    const noi = csv.dungCsv([muc('man-hinh-a', 'A')], 'https://oohx.net');

    assert.ok(noi.includes('https://oohx.net/explore/man-hinh-a'));
});

test('không có origin thì cột đường dẫn để trống, không in "undefined"', () => {
    const noi = csv.dungCsv([muc('a', 'A')]);

    assert.equal(noi.includes('undefined'), false);
    assert.ok(noi.trim().endsWith(','), 'Ô cuối rỗng, không phải chuỗi "null".');
});

// ── Bọc ô ───────────────────────────────────────────────────────────────────

test('dấu phẩy trong tên được bọc, không làm lệch cột', () => {
    const noi = csv.dungCsv([muc('a', 'Billboard Láng Hạ, Đống Đa')]);

    assert.ok(noi.includes('"Billboard Láng Hạ, Đống Đa"'));
});

test('nháy kép nhân đôi theo RFC 4180', () => {
    assert.equal(csv.oCsv('Màn hình "lớn"'), '"Màn hình ""lớn"""');
});

test('chấm phẩy cũng được bọc', () => {
    // Excel ở một số máy Việt Nam lấy `;` làm dấu phân cách theo cài đặt
    // vùng, nên một tên mang dấu đó vỡ thành hai cột ở đúng những máy ấy.
    assert.equal(csv.oCsv('A; B'), '"A; B"');
});

test('xuống dòng trong ghi chú không phá cấu trúc tệp', () => {
    const noi = csv.dungCsv([muc('a', 'Dòng một\nDòng hai')]);
    const sauBom = noi.replace(csv.BOM, '');

    assert.ok(sauBom.includes('"Dòng một\nDòng hai"'));

    // Ba dòng vật lý (tiêu đề + hai mảnh của ô), nhưng chỉ một bản ghi — đó
    // là lý do bọc ô tồn tại.
    assert.equal(sauBom.trim().split('\r\n').length, 2);
});

test('ô không có ký tự đặc biệt thì KHÔNG bọc', () => {
    assert.equal(csv.oCsv('Hà Nội'), 'Hà Nội');
    assert.equal(csv.oCsv(123), '123');
});

test('ô rỗng, null và undefined đều ra chuỗi rỗng', () => {
    for (const v of ['', null, undefined]) {
        assert.equal(csv.oCsv(v), '');
    }
});

test('thiếu thành phố hoặc owner thì để trống, không in "undefined"', () => {
    const noi = csv.dungCsv([{ slug: 'a', ten: 'A' }]);

    assert.equal(noi.includes('undefined'), false);
    assert.ok(noi.includes('1,A,,,a,'));
});

// ── Tên tệp ─────────────────────────────────────────────────────────────────

test('tên tệp mang ngày, hai chữ số cho tháng và ngày', () => {
    assert.equal(csv.tenTep(new Date(2026, 0, 5)), 'oohx-danh-sach-2026-01-05.csv');
    assert.equal(csv.tenTep(new Date(2026, 9, 20)), 'oohx-danh-sach-2026-10-20.csv');
});
