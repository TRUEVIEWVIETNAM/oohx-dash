// ════════════════════════════════════════════════════════════════════════════
// Bản dẫn xuất ảnh ở phía giao diện — lát 4 của `docs/PLAN-ANH-VI-TRI.md`.
//
// Phần máy chủ (sinh tệp, dựng chuỗi `srcset`) có chốt riêng ở
// `tests/Feature/AnhDanXuatTest.php`. Tệp này canh ba cái sai mà **chỉ** thấy
// được ở phía giao diện, và cả ba đều im lặng:
//
//   • **`srcset` không có `sizes`.** `srcset` dùng đơn vị `w` thì trình duyệt
//     phải biết ô ảnh rộng bao nhiêu mới chọn được bản. Thiếu `sizes` nó mặc
//     định `100vw` và luôn chọn bản lớn nhất. Trang vẫn đúng, ảnh vẫn hiện,
//     và cả lát 4 không tiết kiệm được một byte nào.
//
//   • **Giao diện tự dựng đường dẫn bản dẫn xuất.** Thay `screens/a.jpg`
//     bằng `anh-dan-xuat/w480/screens/a.jpg` ở phía TypeScript là một phép
//     dựng đường dẫn tệp thứ hai — đúng thứ lát 1 vừa gộp lại thành một. Hậu
//     quả không phải một URL lạ mắt: mỗi ảnh chưa có bản dẫn xuất thành một
//     `<img>` trỏ vào 404, và `onerror` không có ai nghe.
//
//   • **Lightbox mất bản gốc.** Người dùng bấm vào ảnh để xem to. Đưa cho họ
//     `w1280` ở đó là làm đúng việc mà nút ấy sinh ra để tránh — và không
//     test nào về "trang có hiện ảnh không" phân biệt được hai thứ.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const doc = (p) => readFileSync(join(GOC, p), 'utf8');

const maThe = doc('webapp/components/ScreenCard.tsx');
const maThuVien = doc('webapp/components/ThuVienAnh.tsx');
const maTrangChiTiet = doc('webapp/app/explore/[slug]/page.tsx');

/** Thư mục bản dẫn xuất — phải khớp `AnhDanXuatService::THU_MUC`. */
const THU_MUC = 'anh-dan-xuat';

// ── `srcset` luôn đi cùng `sizes` ──────────────────────────────────────────

test('mọi <img> có srcSet đều có sizes ngay cạnh', () => {
    for (const [ten, ma] of [
        ['ScreenCard.tsx', maThe],
        ['ThuVienAnh.tsx', maThuVien],
    ]) {
        const soSrcSet = (ma.match(/srcSet=/g) ?? []).length;
        const soSizes = (ma.match(/sizes=/g) ?? []).length;

        assert.ok(soSrcSet > 0, `${ten} phải có ít nhất một \`srcSet\`.`);
        assert.equal(
            soSizes,
            soSrcSet,
            `${ten} có ${soSrcSet} \`srcSet\` nhưng ${soSizes} \`sizes\`. `
                + 'Một `srcSet` với đơn vị `w` mà thiếu `sizes` làm trình duyệt '
                + 'mặc định ô ảnh rộng 100vw và luôn chọn bản lớn nhất.',
        );
    }
});

test('sizes chỉ bật khi thật có srcset, không bật vô điều kiện', () => {
    // `sizes` trên một `<img>` không có `srcset` là vô nghĩa nhưng vô hại; cái
    // ca này canh là chiều ngược lại — `srcSet={x ?? undefined}` đi cùng
    // `sizes={CONST}` cố định thì ảnh không có bản dẫn xuất mang một `sizes`
    // lạc, và không ai đọc ra là lạc.
    for (const [ten, ma] of [
        ['ScreenCard.tsx', maThe],
        ['ThuVienAnh.tsx', maThuVien],
    ]) {
        for (const dong of ma.split('\n')) {
            if (! dong.includes('sizes=')) continue;

            assert.match(
                dong,
                /sizes=\{[^}]*\?/,
                `${ten}: \`sizes\` phải là biểu thức có điều kiện, thấy: ${dong.trim()}`,
            );
        }
    }
});

test('không dùng `?? \'\'` cho srcSet — React in ra srcset rỗng', () => {
    for (const [ten, ma] of [
        ['ScreenCard.tsx', maThe],
        ['ThuVienAnh.tsx', maThuVien],
    ]) {
        assert.ok(
            ! /srcSet=\{[^}]*\?\?\s*''/.test(ma),
            `${ten}: \`srcSet={x ?? ''}\` in \`srcset=""\` ra HTML. Dùng \`?? undefined\`.`,
        );
    }
});

// ── Đường dẫn bản dẫn xuất do MÁY CHỦ dựng ────────────────────────────────

test('không có tệp giao diện nào viết ra chuỗi `anh-dan-xuat`', () => {
    for (const [ten, ma] of [
        ['ScreenCard.tsx', maThe],
        ['ThuVienAnh.tsx', maThuVien],
        ['explore/[slug]/page.tsx', maTrangChiTiet],
    ]) {
        assert.ok(
            ! ma.includes(THU_MUC),
            `${ten} nhắc tới \`${THU_MUC}\`. Giao diện chỉ được dùng chuỗi `
                + '`photo_srcset`/`photo_srcsets` máy chủ trả về; tự dựng đường dẫn '
                + 'là một `<img>` trỏ vào 404 với mọi ảnh chưa sinh bản dẫn xuất.',
        );
    }
});

test('không có phép ghép bề rộng vào đường dẫn ở giao diện', () => {
    // Hình dạng cần chặn: `` `w${be}/` `` hay `'w480/'` nối vào một URL.
    for (const [ten, ma] of [
        ['ScreenCard.tsx', maThe],
        ['ThuVienAnh.tsx', maThuVien],
        ['explore/[slug]/page.tsx', maTrangChiTiet],
    ]) {
        assert.ok(
            ! /['"`]w(480|1280)\//.test(ma),
            `${ten} ghép bề rộng vào một đường dẫn. Bề rộng chỉ xuất hiện trong `
                + 'chuỗi `srcset` máy chủ dựng.',
        );
    }
});

// ── Lightbox giữ bản gốc ──────────────────────────────────────────────────

test('ảnh trong lightbox KHÔNG có srcSet', () => {
    const i = maThuVien.indexOf('className="lb-img"');

    assert.ok(i > -1, 'Không thấy `className="lb-img"` — lightbox đã đổi tên?');

    // Cắt từ dấu `<` mở thẻ tới `/>` đóng nó, rồi soi đúng thẻ đó. Đọc cả tệp
    // thì ca này luôn xanh vì ảnh chính bên trên có `srcSet`.
    const mo = maThuVien.lastIndexOf('<img', i);
    const dong = maThuVien.slice(mo, maThuVien.indexOf('/>', i) + 2);

    assert.ok(
        ! dong.includes('srcSet'),
        'Lightbox phải dùng bản gốc: người dùng mở nó để xem to. Thẻ hiện tại: '
            + dong.replace(/\s+/g, ' '),
    );
});

// ── Dãy `srcset` ghép theo CHỈ SỐ với dãy ảnh ─────────────────────────────

test('thư viện đọc srcset theo cùng chỉ số với ảnh', () => {
    // `anh[chon]` phải đi cùng `srcset?.[chon]`, và `thumb` của vòng `map`
    // phải đi cùng `srcset?.[i]`. Lệch chỉ số ở đây là mỗi ảnh mang `srcset`
    // của ảnh khác — trình duyệt tải một ảnh, hiện một ảnh khác.
    assert.match(maThuVien, /srcSet=\{srcset\?\.\[chon\]/);
    assert.match(maThuVien, /srcSet=\{srcset\?\.\[i\]/);
});

test('trang chi tiết chọn dãy srcset theo cùng nhánh với dãy ảnh', () => {
    // `anh` và `anhSrcset` phải rẽ theo **cùng** điều kiện
    // (`photo_urls?.length`). Rẽ theo hai điều kiện khác nhau là hai dãy khác
    // độ dài, và từ ô lệch trở đi mỗi ảnh mang `srcset` của ảnh khác.
    const soLanRe = (maTrangChiTiet.match(/screen\.photo_urls\?\.length/g) ?? []).length;

    assert.ok(
        soLanRe >= 2,
        'Trang chi tiết phải rẽ `anh` và `anhSrcset` theo cùng điều kiện '
            + '`photo_urls?.length`; thấy ' + soLanRe + ' lần.',
    );

    assert.match(maTrangChiTiet, /srcset=\{anhSrcset\}/);
});
