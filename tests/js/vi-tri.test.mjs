// ════════════════════════════════════════════════════════════════════════════
// Định vị: khi nào được phép hỏi, và "quanh tôi" rộng bao nhiêu.
//
// Chủ dự án chốt 22/10/2026: xin quyền vị trí **khi mở trang** `/explore`, và
// hiển thị màn hình quanh người dùng.
//
// ── Vì sao hai phép này bị tách khỏi DOM ────────────────────────────────────
//
// `nenLayViTri` quyết định một hộp thoại quyền có bật lên hay không, và cả hai
// chiều sai đều hỏng nặng:
//
//   • quá chặt → tính năng không bao giờ chạy, kể cả với người ĐÃ đồng ý;
//   • quá lỏng → hộp thoại bật ở mọi lượt tải trang. Trình duyệt **nhớ** cú
//     "Chặn", và Chrome còn tự chặn quyền của cả site khi thấy kiểu hỏi đó.
//     Một lần hỏi sai thời điểm là mất tính năng vĩnh viễn với người đó.
//
// `khungQuanh` thì quyết định "quanh tôi" nghĩa là bao xa. Quên chia `cos(lat)`
// là khung đông–tây rộng hơn mong muốn 7% ở Hà Nội và gấp đôi ở Bắc Âu; chia
// mà không có sàn thì ở sát cực ra `Infinity`, API trả 422, bản đồ trống trơn
// và không có gì nói vì sao.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const vt = await import('../../webapp/lib/vi-tri.ts');

// ── Khi nào được phép gọi getCurrentPosition ───────────────────────────────

test('đã đồng ý thì lấy LUÔN, kể cả khi đã hỏi trước đó', () => {
    // Ở nhánh này không có hộp thoại nào bật lên — trình duyệt trả toạ độ
    // thẳng. Chặn nó lại là bắt người đã đồng ý bấm thêm một nút ở mọi lượt
    // tải trang.
    assert.equal(vt.nenLayViTri(true, 'granted'), true);
    assert.equal(vt.nenLayViTri(false, 'granted'), true);
});

test('đã bị chặn thì KHÔNG gọi, dù chưa hỏi lần nào', () => {
    // Trình duyệt từ chối ngay mà không hỏi, nên gọi chỉ tốn một lần gọi
    // hỏng — và một số trình duyệt đếm số lần site gọi vào quyền đã chặn.
    assert.equal(vt.nenLayViTri(false, 'denied'), false);
    assert.equal(vt.nenLayViTri(true, 'denied'), false);
});

test('chưa quyết thì hỏi ĐÚNG MỘT lần cho mỗi trình duyệt', () => {
    // Nhánh quan trọng nhất. Người dùng **bỏ qua** hộp thoại (bấm ra ngoài,
    // không chọn gì) thì trạng thái vẫn là `prompt` mãi mãi. Không nhớ đã hỏi
    // thì hộp thoại bật lên ở MỌI lượt tải trang — đúng hành vi làm Chrome tự
    // chặn quyền của cả site.
    assert.equal(vt.nenLayViTri(false, 'prompt'), true);
    assert.equal(vt.nenLayViTri(true, 'prompt'), false);
});

test('không truy vấn được quyền thì xử như chưa quyết', () => {
    // `navigator.permissions` không có ở mọi trình duyệt, và có thể ném.
    assert.equal(vt.nenLayViTri(false, null), true);
    assert.equal(vt.nenLayViTri(true, null), false);
});

// ── "Quanh tôi" rộng bao nhiêu ─────────────────────────────────────────────

test('khung bao đúng bán kính theo chiều bắc–nam', () => {
    const k = vt.khungQuanh(10.8, 106.7, 15);

    // 15km / 111,32km mỗi độ = 0,1347°.
    assert.ok(Math.abs(k.north - 10.8 - 0.1347) < 0.001, `north lệch: ${k.north}`);
    assert.ok(Math.abs(10.8 - k.south - 0.1347) < 0.001, `south lệch: ${k.south}`);
});

test('độ kinh CO LẠI theo vĩ độ — 15km ở Hà Nội không bằng 15km ở xích đạo', () => {
    const xichDao = vt.khungQuanh(0, 100, 15);
    const haNoi = vt.khungQuanh(21, 100, 15);

    const rongXichDao = xichDao.east - xichDao.west;
    const rongHaNoi = haNoi.east - haNoi.west;

    // cos(21°) ≈ 0,9336 → khung ở Hà Nội phải RỘNG HƠN về số độ để cùng là
    // 15km thật. Quên phép chia này thì nó bằng nhau, và 15km ở Hà Nội thành
    // 14km.
    assert.ok(rongHaNoi > rongXichDao, 'Khung ở vĩ độ cao phải rộng hơn về số độ.');
    assert.ok(
        Math.abs(rongHaNoi / rongXichDao - 1 / Math.cos((21 * Math.PI) / 180)) < 0.001,
        'Tỉ lệ không khớp 1/cos(lat).',
    );
});

test('bán kính đổi thì khung đổi theo, tuyến tính', () => {
    const a = vt.khungQuanh(10.8, 106.7, 15);
    const b = vt.khungQuanh(10.8, 106.7, 30);

    assert.ok(Math.abs((b.north - 10.8) / (a.north - 10.8) - 2) < 0.001);
});

test('sát cực khung vẫn có độ rộng dùng được, không phình ra số vô nghĩa', () => {
    // Ca này từng chỉ đo `Number.isFinite`, và một đột biến **bỏ hẳn sàn
    // `cos`** vẫn làm nó xanh: ở cực `cos` không ra 0 mà ra 6,1e-17, nên phép
    // chia cho ra 2,2e15 — hữu hạn, và hoàn toàn vô nghĩa. Đo độ rộng mới
    // bắt được.
    for (const lat of [89.999, 90, -90]) {
        const k = vt.khungQuanh(lat, 0, 15);

        for (const [ten, gt] of Object.entries(k)) {
            assert.ok(Number.isFinite(gt), `lat ${lat}: ${ten} không hữu hạn (${gt})`);
        }

        const rong = k.east - k.west;

        assert.ok(
            rong > 0 && rong <= 30,
            `lat ${lat}: khung rộng ${rong}° — sàn \`cos\` đã mất. Một khung nhìn vô lý gửi `
                + 'lên API là một 422 im lặng và một bản đồ trống không ai lần ra nguyên nhân.',
        );
    }
});

test('không vượt quá hai cực', () => {
    assert.equal(vt.khungQuanh(89.95, 0, 15).north, 90);
    assert.equal(vt.khungQuanh(-89.95, 0, 15).south, -90);
});

test('bán kính mặc định là 15km', () => {
    assert.equal(vt.BAN_KINH_KM, 15);
    assert.deepEqual(vt.khungQuanh(10.8, 106.7), vt.khungQuanh(10.8, 106.7, 15));
});

// ── Việc nối dây ───────────────────────────────────────────────────────────

test('hook thực sự hỏi quyền trước, và nhớ là đã hỏi', () => {
    const hook = readFileSync(join(GOC, 'webapp/lib/dung-vi-tri.ts'), 'utf8');

    assert.match(hook, /nenLayViTri\(/, '`dung-vi-tri.ts` không gọi `nenLayViTri`.');

    assert.match(
        hook,
        /navigator\.permissions/,
        'Hook không truy vấn trạng thái quyền trước. Thiếu bước đó thì người ĐÃ chặn vẫn bị '
            + 'gọi vào, và người ĐÃ đồng ý không được lấy toạ độ thẳng.',
    );

    assert.match(
        hook,
        /localStorage/,
        'Hook không nhớ "đã hỏi rồi". Người dùng bỏ qua hộp thoại thì trạng thái vẫn là '
            + '`prompt` mãi mãi, và hộp thoại bật lên ở mọi lượt tải trang.',
    );

    assert.match(
        hook,
        /enableHighAccuracy:\s*false/,
        'Hook xin vị trí ĐỘ CHÍNH XÁC CAO. Tính năng này chỉ cần biết người dùng ở thành phố '
            + 'nào; bật GPS là lấy nhiều dữ liệu hơn mức cần, chậm hơn và tốn pin hơn.',
    );
});

test('cả hai kiểu xem đều có đường tới "quanh tôi"', () => {
    const khoi = readFileSync(join(GOC, 'webapp/components/KhoiExplore.tsx'), 'utf8');

    assert.match(khoi, /dungViTri\(/, '`KhoiExplore` không gọi hook định vị.');

    // Hook nằm ở component NGOÀI, không trong nhánh bản đồ: kiểu lưới là kiểu
    // mặc định, và chủ dự án chốt hỏi khi mở `/explore` — không phải khi mở
    // riêng kiểu bản đồ.
    //
    // Tìm CÂU LỆNH, không tìm chuỗi: bản đầu của ca này dùng
    // `indexOf("if (kieuXem === 'grid')")` và nó khớp phải một **chú thích**
    // nhắc tới nhánh đó, nằm ngay trên lời gọi hook. Ca đỏ trong khi code
    // đúng — một phép kiểm đo sai thứ nó định đo.
    const nhanh = khoi.indexOf("\n    if (kieuXem === 'grid') {");

    assert.ok(nhanh > 0, "Không tìm thấy câu lệnh rẽ nhánh kiểu xem.");

    assert.ok(
        khoi.indexOf('dungViTri(') < nhanh,
        'Hook định vị nằm sau nhánh kiểu xem, nên kiểu lưới không hỏi gì — trong khi lưới là '
            + 'kiểu mặc định của `/explore`.',
    );
});
