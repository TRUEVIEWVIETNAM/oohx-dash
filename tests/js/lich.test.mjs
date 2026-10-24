/**
 * Chốt cho `webapp/lib/lich.ts` — lát 3 của `docs/PLAN-CHI-TIET-VI-TRI.md`.
 *
 * Lát 3 đưa lịch trống ra khỏi tab thứ ba: "đặt được từ khi nào" là câu người
 * mua hỏi TRƯỚC cả giá, và nó đang nằm sau một cú bấm. Khối gọn trên màn hình
 * đầu và lưới ngày trong tab nay đọc **cùng một** phép nhóm, nên hai bên không
 * thể nói hai con số khác nhau về cùng một lịch.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const { theoThang, tomTatLich, ngayGon } = await import('../../webapp/lib/lich.ts');

const ngay = (date, remaining_sov_pct) => ({ date, remaining_sov_pct });

test('nhóm theo tháng, giữ thứ tự API trả về', () => {
    const t = theoThang([
        ngay('2026-10-30', 100),
        ngay('2026-10-31', 60),
        ngay('2026-11-01', 0),
    ]);

    assert.equal(t.length, 2);
    assert.deepEqual(
        t.map((m) => [m.khoa, m.nam, m.thang, m.ngay.length]),
        [
            ['2026-10', 2026, 10, 2],
            ['2026-11', 2026, 11, 1],
        ],
    );
});

test('ngaySom KHÔNG phải days[0] khi ngày đầu đã hết chỗ', () => {
    // API trả theo thứ tự ngày, nhưng ngày đầu có thể đã đặt hết. Lấy `days[0]`
    // là chỉ cho người mua một ngày họ không đặt được.
    const tom = tomTatLich([
        ngay('2026-10-24', 0),
        ngay('2026-10-25', 0),
        ngay('2026-10-26', 40),
        ngay('2026-10-27', 100),
    ]);

    assert.equal(tom.ngaySom, '2026-10-26');
    assert.equal(tom.tongTrong, 2);
});

test('tong là số ngày API TRẢ VỀ, không phải số ngày của tháng', () => {
    // API bắt đầu từ hôm nay, nên tháng đầu của cửa sổ chỉ có những ngày còn
    // lại. In "8/31" cho tháng hiện tại là nói sai: 23 ngày kia không phải đã
    // đặt, chúng đã qua.
    const tom = tomTatLich([
        ngay('2026-10-24', 100),
        ngay('2026-10-25', 100),
        ngay('2026-10-26', 0),
    ]);

    assert.equal(tom.thang.length, 1);
    assert.deepEqual(tom.thang[0], { khoa: '2026-10', nam: 2026, thang: 10, trong: 2, tong: 3 });
});

test('remaining_sov_pct thiếu hoặc sai kiểu thì coi là HẾT chỗ', () => {
    // Với một trang để ra quyết định mua, đoán rộng về phía "còn chỗ" dẫn người
    // mua tới một ngày không đặt được.
    const tom = tomTatLich([
        { date: '2026-10-24' },
        { date: '2026-10-25', remaining_sov_pct: null },
        { date: '2026-10-26', remaining_sov_pct: '100' },
        { date: '2026-10-27', remaining_sov_pct: undefined },
    ]);

    assert.equal(tom.ngaySom, null);
    assert.equal(tom.tongTrong, 0);
    assert.equal(tom.thang[0].trong, 0);
    assert.equal(tom.thang[0].tong, 4);
});

test('mọi ngày hết chỗ: có tháng nhưng không có ngaySom', () => {
    // Hai trạng thái rỗng KHÁC nhau, và khối gọn nói hai câu khác nhau:
    // "chưa mở ngày nào để đặt" (không có tháng) và "đã đặt hết trong cửa sổ
    // đang xem" (có tháng, không ngày nào còn chỗ).
    const tom = tomTatLich([ngay('2026-10-24', 0), ngay('2026-11-01', 0)]);

    assert.equal(tom.ngaySom, null);
    assert.equal(tom.thang.length, 2);
    assert.equal(tom.tongTrong, 0);
});

test('mảng rỗng, null, undefined và kiểu lạ không làm vỡ', () => {
    for (const v of [[], null, undefined, 0, 'x', {}]) {
        assert.deepEqual(
            tomTatLich(v),
            { ngaySom: null, thang: [], tongTrong: 0 },
            `giá trị: ${JSON.stringify(v)}`,
        );
    }
});

test('ngayGon đọc được ngày, và chịu được rác', () => {
    assert.equal(ngayGon('2026-11-05'), '5/11');
    assert.equal(ngayGon('2026-10-24'), '24/10');

    for (const v of [null, undefined, '', '2026-11', 'hôm nay', 20261105, {}]) {
        assert.equal(ngayGon(v), '', `giá trị: ${JSON.stringify(v)}`);
    }
});

test('trang gọi getScreenAvailability ĐÚNG MỘT lần, hai khối dùng chung', () => {
    // Để mỗi khối tự gọi là hai lượt vào một endpoint có giới hạn tần suất,
    // mỗi lần render trang, để hiện hai cách nhìn của CÙNG một dữ liệu.
    const page = readFileSync(
        new URL('../../webapp/app/explore/[slug]/page.tsx', import.meta.url),
        'utf8',
    );

    // Bỏ chú thích trước khi đếm: tên hàm còn xuất hiện hai lần trong docblock
    // giải thích chính quyết định này, và đếm chúng là đếm chữ chứ không phải
    // đếm lời gọi.
    const maThat = page.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');

    const soLan = (maThat.match(/getScreenAvailability\(/g) ?? []).length;
    assert.equal(soLan, 1, `gọi ${soLan} lần, phải đúng 1`);

    // Và cả hai khối nhận dữ liệu qua prop.
    assert.ok(page.includes('<LichGon lich={lich} />'), 'khối gọn phải nhận lich qua prop');
    assert.ok(page.includes('<LichTrong lich={lich}'), 'tab phải nhận cùng lich đó');

    for (const tep of ['LichGon.tsx', 'LichTrong.tsx']) {
        const ma = readFileSync(new URL(`../../webapp/components/${tep}`, import.meta.url), 'utf8')
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/\/\/.*$/gm, '');

        assert.ok(!ma.includes('getScreenAvailability'), `${tep} không được tự gọi API`);
        assert.ok(!ma.includes('fetch('), `${tep} không được tự fetch`);
    }
});

test('khối gọn nằm TRƯỚC thanh tab trong mã nguồn', () => {
    // Cả lát 3 nằm ở điều này: thấy được mà không cần bấm. Nếu có người đưa
    // khối xuống dưới thanh tab thì nó lại thành một thứ phải cuộn tới.
    const page = readFileSync(
        new URL('../../webapp/app/explore/[slug]/page.tsx', import.meta.url),
        'utf8',
    );

    const iStats = page.indexOf('className="stats-row"');
    const iGon = page.indexOf('<LichGon');
    const iTab = page.indexOf('<TabChiTiet');

    assert.ok(iStats > -1 && iGon > -1 && iTab > -1, 'thiếu một trong ba khối');
    assert.ok(iGon > iStats, 'khối gọn phải nằm sau hàng số liệu');
    assert.ok(iGon < iTab, 'khối gọn phải nằm TRƯỚC thanh tab');
});

test('khối gọn: BA trạng thái rỗng nói ba câu khác nhau', () => {
    // Ca này thêm sau khi đột biến "gộp hai câu rỗng" chạy qua cả bộ chốt tĩnh
    // mà không ca nào đỏ. `khoi-giao-dien.mjs` bắt được, nhưng lớp đó cần
    // `next build` nên nó là lớp cuối, không phải lớp đầu.
    //
    // Ba trạng thái dẫn tới ba hành động khác nhau của người mua:
    //   - `lich === null`   sàn KHÔNG BIẾT → thử lại, hoặc xem tab
    //   - không có tháng    sàn BIẾT chưa mở ngày nào → hỏi đợt kế tiếp
    //   - có tháng, hết chỗ sàn BIẾT đã đặt hết → hỏi đợt kế tiếp, hoặc chờ
    const gon = readFileSync(
        new URL('../../webapp/components/LichGon.tsx', import.meta.url),
        'utf8',
    );

    const cau = ['Chưa lấy được', 'Chưa mở ngày nào để đặt', 'Đã đặt hết trong cửa sổ đang xem'];
    for (const c of cau) {
        assert.ok(gon.includes(c), `thiếu câu: ${c}`);
    }

    // Và ba câu phải thật sự khác nhau — nếu có người sửa hai cái thành một
    // thì `includes` ở trên vẫn khớp cả ba chỉ khi chúng còn đủ.
    assert.equal(new Set(cau).size, 3);

    // Nhánh phân biệt "chưa mở ngày nào" với "đã đặt hết" đọc số tháng, không
    // đọc `ngaySom`: một lịch có tháng mà mọi ngày hết chỗ vẫn có tháng.
    assert.ok(gon.includes('tom.thang.length === 0'), 'thiếu nhánh phân biệt theo số tháng');
    assert.ok(gon.includes('tom.ngaySom === null'), 'thiếu nhánh không còn ngày nào trống');
});

test('LichTrong dùng theoThang của lib, không giữ bản riêng', () => {
    // Hai bản luật là cách hai khối nói hai con số khác nhau về cùng một lịch,
    // và chỗ lệch sẽ không ai thấy vì hai khối không bao giờ hiện cạnh nhau.
    const lt = readFileSync(
        new URL('../../webapp/components/LichTrong.tsx', import.meta.url),
        'utf8',
    );

    assert.ok(lt.includes("from '@/lib/lich'"), 'LichTrong phải nhập từ lib');
    assert.ok(!lt.includes('function theoThang'), 'LichTrong còn giữ bản theoThang riêng');
});
