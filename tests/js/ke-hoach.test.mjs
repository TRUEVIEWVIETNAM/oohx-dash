// ════════════════════════════════════════════════════════════════════════════
// Bảng tổng kế hoạch — phép cộng tiền duy nhất trên trang công khai.
//
// Lát 6 của `docs/PLAN-GOP-EXPLORE-BAN-DO.md` (22/10/2026).
//
// ── Vì sao phép này bị tách khỏi DOM ────────────────────────────────────────
//
// Mọi chỗ khác trên trang công khai chỉ **hiển thị** một con số máy chủ đã
// tính. Bảng tổng là chỗ đầu tiên trang tự nhân và tự cộng, và một phép nhân
// sai ở đây ra một con số trông hợp lý mà không ai đối chiếu được — người mua
// mang nó đi lập ngân sách, và chỗ sai lộ ra ở bàn đàm phán.
//
// Ba cái bẫy có thật, cả ba đều ở dưới:
//
//   • **Làm tròn kỳ.** OOH bán theo kỳ: 45 ngày trên bảng giá tháng là trả
//     HAI tháng. Chia thẳng ra 1,5 và báo thiếu tiền.
//   • **Mục chưa báo giá.** 85/104 vị trí trên production không có `io_rate`.
//     Cộng chúng như 0 ra một kế hoạch rẻ hơn sự thật; trả tổng 0 cho một kế
//     hoạch 12 vị trí thì nói rằng nó miễn phí.
//   • **Tỉnh lẫn trong chuỗi địa điểm.** 21/104 bản ghi có `city` dạng
//     `"Thái Nguyên > Phường Bắc Kạn"`, phần sau là PHƯỜNG chứ không phải
//     quận.
//
// Và một câu chữ được canh như một con số: cảnh báo về reach.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const kh = await import('../../webapp/lib/ke-hoach.ts');

const muc = (slug, dat = {}) => ({ slug, ten: 'Màn hình ' + slug, ...dat });

// ── Tỉnh thành từ chuỗi địa điểm ───────────────────────────────────────────

test('tỉnh lấy phần TRƯỚC dấu mũi tên, và phần sau bị bỏ', () => {
    // 21/104 bản ghi trên production có dạng này, và phần sau là **phường**.
    // Gọi nó là quận trong một bảng độ phủ là nói sai với người đang lập kế
    // hoạch truyền thông.
    assert.equal(kh.tinhTu('Thái Nguyên > Phường Bắc Kạn'), 'Thái Nguyên');
    assert.equal(kh.tinhTu('Lào Cai > Phường Yên Bái'), 'Lào Cai');

    // 83/104 là tên tỉnh trần.
    assert.equal(kh.tinhTu('Hồ Chí Minh'), 'Hồ Chí Minh');

    assert.equal(kh.tinhTu(''), null);
    assert.equal(kh.tinhTu(undefined), null);
    assert.equal(kh.tinhTu(' > Phường X'), null);
});

// ── Làm tròn kỳ ────────────────────────────────────────────────────────────

test('số kỳ LÀM TRÒN LÊN — 45 ngày trên giá tháng là hai tháng', () => {
    assert.equal(kh.soKy(30, 'month'), 1);
    assert.equal(kh.soKy(31, 'month'), 2);
    assert.equal(kh.soKy(45, 'month'), 2);
    assert.equal(kh.soKy(90, 'month'), 3);

    assert.equal(kh.soKy(7, 'week'), 1);
    assert.equal(kh.soKy(8, 'week'), 2);
    assert.equal(kh.soKy(30, 'week'), 5);
});

test('số kỳ không bao giờ xuống dưới một', () => {
    // Không ai thuê nửa tháng với giá nửa tháng. Và một ô nhập trống hay số
    // rác không được ra chi phí 0.
    assert.equal(kh.soKy(1, 'month'), 1);
    assert.equal(kh.soKy(0, 'month'), 1);
    assert.equal(kh.soKy(-5, 'month'), 1);
    assert.equal(kh.soKy(NaN, 'month'), 1);
});

test('mặc định là kỳ tháng', () => {
    assert.equal(kh.soKy(45), 2);
});

// ── Tổng kế hoạch ──────────────────────────────────────────────────────────

test('kế hoạch rỗng không ra con số nào giả', () => {
    const t = kh.tomTatKeHoach([], 30);

    assert.equal(t.soViTri, 0);
    assert.equal(t.soTinh, 0);
    assert.equal(t.chiPhi, null, 'Chi phí của kế hoạch rỗng phải là null, không phải 0.');
    assert.deepEqual(t.theoLoai, []);
});

test('chỉ cộng mục CÓ giá, và nói ra bao nhiêu mục không có', () => {
    // 85/104 vị trí trên production không có `io_rate`. Cộng chúng như 0 ra
    // một kế hoạch rẻ hơn sự thật.
    const t = kh.tomTatKeHoach(
        [
            muc('a', { gia: 10_000_000, ky: 'month' }),
            muc('b'),
            muc('c', { gia: 0, ky: 'month' }),
            muc('d', { gia: 5_000_000, ky: 'month' }),
        ],
        30,
    );

    assert.equal(t.soViTri, 4);
    assert.equal(t.soCoGia, 2);
    assert.equal(t.soChuaGia, 2);
    assert.equal(t.chiPhi, 15_000_000);
});

test('không mục nào có giá thì chi phí là null, KHÔNG phải 0', () => {
    // Một tổng bằng 0 trên một kế hoạch 12 vị trí nói rằng nó miễn phí.
    const t = kh.tomTatKeHoach([muc('a'), muc('b')], 30);

    assert.equal(t.soViTri, 2);
    assert.equal(t.chiPhi, null);
    assert.equal(t.soChuaGia, 2);
});

test('chi phí nhân theo số kỳ, và mỗi mục theo kỳ CỦA NÓ', () => {
    // Một kế hoạch trộn bảng giá tuần với bảng giá tháng là chuyện bình
    // thường. Nhân cả hai theo một kỳ chung là sai cho một nửa.
    const ds = [
        muc('thang', { gia: 10_000_000, ky: 'month' }),
        muc('tuan', { gia: 2_000_000, ky: 'week' }),
    ];

    // 30 ngày: 1 tháng + 5 tuần (30/7 = 4,28 → 5).
    assert.equal(kh.tomTatKeHoach(ds, 30).chiPhi, 10_000_000 + 2_000_000 * 5);

    // 45 ngày: 2 tháng + 7 tuần.
    assert.equal(kh.tomTatKeHoach(ds, 45).chiPhi, 20_000_000 + 2_000_000 * 7);
});

test('thiếu `ky` thì coi là tháng, không bỏ mục đi', () => {
    // Mục người dùng khoanh TRƯỚC lát 6 không có trường `ky`. Bỏ chúng ra
    // khỏi phép cộng là một kế hoạch tự nhiên rẻ đi sau khi deploy.
    assert.equal(kh.tomTatKeHoach([muc('a', { gia: 9_000_000 })], 60).chiPhi, 18_000_000);
});

test('đếm tỉnh theo tỉnh thật, và không đếm mục thiếu địa điểm', () => {
    const t = kh.tomTatKeHoach(
        [
            muc('a', { thanhPho: 'Hồ Chí Minh' }),
            muc('b', { thanhPho: 'Hồ Chí Minh' }),
            muc('c', { thanhPho: 'Thái Nguyên > Phường Bắc Kạn' }),
            muc('d'),
        ],
        30,
    );

    assert.equal(t.soTinh, 2);
    assert.deepEqual(t.theoTinh, [
        { ten: 'Hồ Chí Minh', so: 2 },
        { ten: 'Thái Nguyên', so: 1 },
    ]);
});

test('phân bổ xếp nhiều nhất trước, và hoà thì theo bảng chữ cái', () => {
    // Hai lần mở bảng với cùng một kế hoạch phải cho cùng một thứ tự; một
    // thanh phân bổ nhảy chỗ giữa hai lần nhìn là thứ không ai tin.
    const t = kh.tomTatKeHoach(
        [
            muc('a', { loaiHinh: 'LCD' }),
            muc('b', { loaiHinh: 'Billboard' }),
            muc('c', { loaiHinh: 'Billboard' }),
            muc('d', { loaiHinh: 'LED' }),
        ],
        30,
    );

    assert.deepEqual(t.theoLoai, [
        { ten: 'Billboard', so: 2 },
        { ten: 'LCD', so: 1 },
        { ten: 'LED', so: 1 },
    ]);
});

// ── Câu cảnh báo được canh như một con số ──────────────────────────────────

test('câu cảnh báo về reach còn nguyên — xoá nó là đổi nghĩa con số', () => {
    assert.match(
        kh.CANH_BAO_REACH,
        /reach thô/,
        'Mất chữ "reach thô". Người đi làm qua biển Chợ Bến Thành rồi qua biển Lý Tự Trọng–'
            + 'Pasteur cách 700m bị đếm HAI lần; gọi tổng đó là "reach" mà không nói "thô" là '
            + 'đưa cho agency một con số họ sẽ mang đi báo cáo cho nhãn hàng.',
    );

    assert.match(
        kh.CANH_BAO_REACH,
        /khử trùng lặp/,
        'Mất lời giải thích vì sao reach không cộng được.',
    );

    assert.match(
        kh.CANH_BAO_REACH,
        /sàn chưa làm được/,
        'Mất lời thú nhận rằng sàn chưa khử trùng lặp được. Bỏ nó đi là ngầm hứa đã làm.',
    );
});

// ── Việc nối dây ───────────────────────────────────────────────────────────

test('bảng tổng thực sự dùng phép trên, và in ra câu cảnh báo', () => {
    const bang = readFileSync(join(GOC, 'webapp/components/BangKeHoach.tsx'), 'utf8');

    assert.match(bang, /tomTatKeHoach\(/, '`BangKeHoach` không gọi `tomTatKeHoach`.');

    assert.match(
        bang,
        /CANH_BAO_REACH/,
        'Bảng không in câu cảnh báo về reach. Một bảng cộng tiền mà im lặng về con số KHÔNG '
            + 'cộng được là bảng mời người ta cộng nốt nó.',
    );
});

test('mọi chỗ thêm vào kế hoạch đều gửi kèm giá, loại hình và địa điểm', () => {
    // Thiếu một chỗ thì cùng một màn hình khoanh từ thẻ thì có giá, khoanh từ
    // popup bản đồ thì không — và bảng tổng nói hai con số khác nhau cho cùng
    // một kế hoạch, tuỳ người dùng bấm ở đâu.
    for (const tep of [
        'webapp/components/ScreenCard.tsx',
        'webapp/components/PopupBanDo.tsx',
        'webapp/components/TheChiTiet.tsx',
    ]) {
        const ma = readFileSync(join(GOC, tep), 'utf8');

        assert.match(ma, /loaiHinh:/, `${tep} không gửi \`loaiHinh\` khi thêm vào kế hoạch.`);
        assert.match(ma, /gia:/, `${tep} không gửi \`gia\`.`);
        assert.match(ma, /ky:/, `${tep} không gửi \`ky\`.`);
    }
});

test('kỳ suy từ nhãn giá, và CPM thì KHÔNG phải một kỳ', () => {
    // `MapPin.price.unit` là nhãn người đọc (`màn hình/tháng`), còn
    // `ScreenSummary.pricing.io_rate.unit` là mã (`month`). Popup bản đồ chỉ
    // có cái đầu.
    assert.equal(kh.kyTuNhan('màn hình/tháng'), 'month');
    assert.equal(kh.kyTuNhan('màn hình/tuần'), 'week');
    assert.equal(kh.kyTuNhan('month'), 'month');
    assert.equal(kh.kyTuNhan('week'), 'week');

    // Điểm chính: giá CPM là tiền cho 1.000 lượt hiển thị, không phải tiền
    // thuê một kỳ. Nhân nó với số tháng ra một con số vô nghĩa, và nó sẽ nằm
    // giữa một bảng ngân sách trông đứng đắn.
    assert.equal(kh.kyTuNhan('CPM'), null);
    assert.equal(kh.kyTuNhan(null), null);
    assert.equal(kh.kyTuNhan(undefined), null);
    assert.equal(kh.kyTuNhan('mỗi ngày'), null);
});
