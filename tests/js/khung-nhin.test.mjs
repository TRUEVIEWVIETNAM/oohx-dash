// ════════════════════════════════════════════════════════════════════════════
// Khung nhìn bản đồ: khi nào dữ liệu cũ đi, và bốn con số của dải tổng quan.
//
// Lát 4 của `docs/PLAN-GOP-EXPLORE-BAN-DO.md` (22/10/2026).
//
// ── Vì sao hai phép này bị tách khỏi DOM ────────────────────────────────────
//
// `canNapLai` quyết định khi nào người dùng **thấy một cái nút**, và một
// ngưỡng sai ở đó hỏng theo hai chiều, cả hai đều im lặng:
//
//   • quá chặt → nút không bao giờ hiện, bản đồ đứng im với dữ liệu của vùng
//     người dùng đã rời khỏi từ lâu;
//   • quá lỏng → nút hiện sau mỗi cú nhích 2px, và nó thành một thứ nhấp nháy
//     người ta học cách không nhìn.
//
// `tomTatKhung` thì in một khoảng giá lên trang bán hàng. Trộn giá tháng với
// giá CPM tính bằng đô cho ra một con số đọc được và hoàn toàn sai.
//
// Không mở trình duyệt thì không đo được hai thứ đó. Gọi bằng số thì đo được.
// Việc nối dây — hook có thật sự gọi chúng không — ở cuối tệp.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, join } from 'node:path';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');

const kn = await import('../../webapp/lib/khung-nhin.ts');

/** Khung vuông quanh một tâm, cạnh `canh` độ. */
function khung(lat, lng, canh) {
    const nua = canh / 2;

    return { north: lat + nua, south: lat - nua, east: lng + nua, west: lng - nua };
}

const DA_NAP = khung(10.8, 106.7, 1); // ~1° quanh TP.HCM

// ── Khi nào nút "Tìm trong khu vực này" hiện ───────────────────────────────

test('chưa nạp lần nào thì KHÔNG hiện nút', () => {
    // Lượt nạp đầu tiên đang trên đường. Một cái nút "Tìm trong khu vực này"
    // trên một bản đồ còn trống là mời bấm vào việc đang tự chạy.
    assert.equal(kn.canNapLai(null, khung(10.8, 106.7, 1)), false);
});

test('khung mới nằm GỌN trong vùng đã nạp thì không hiện nút', () => {
    // Thu nhỏ lại: mọi pin cần thiết đã nằm trong tay. Bật nút lúc này là mời
    // người dùng trả một lời gọi API để nhận lại đúng thứ họ đang nhìn.
    assert.equal(kn.canNapLai(DA_NAP, khung(10.8, 106.7, 0.4)), false);

    // Nhích nhẹ nhưng vẫn trong vùng cũ.
    assert.equal(kn.canNapLai(DA_NAP, khung(10.85, 106.75, 0.3)), false);
});

test('chạm ra ngoài vùng đã nạp — bất kỳ hướng nào — thì hiện nút', () => {
    for (const [ten, k] of [
        ['bắc', { ...DA_NAP, north: DA_NAP.north + 0.2 }],
        ['nam', { ...DA_NAP, south: DA_NAP.south - 0.2 }],
        ['đông', { ...DA_NAP, east: DA_NAP.east + 0.2 }],
        ['tây', { ...DA_NAP, west: DA_NAP.west - 0.2 }],
    ]) {
        assert.equal(kn.canNapLai(DA_NAP, k), true, `Ra ngoài phía ${ten} mà không hiện nút.`);
    }
});

test('phóng to ra ngoài vùng cũ thì hiện nút', () => {
    assert.equal(kn.canNapLai(DA_NAP, khung(10.8, 106.7, 3)), true);
});

test('một cú nhích vài pixel KHÔNG bật nút', () => {
    // Dung sai 0,5% cạnh khung đã nạp. Với cạnh 1° đó là 0,005° — khoảng
    // 550m, tức dưới một pixel ở mức thu phóng cả thành phố.
    //
    // `invalidateSize()` lúc panel mở ra cũng sinh một `moveend` kiểu này, và
    // nút nhấp nháy ngay khi trang vừa dựng xong là thứ người ta học cách
    // không nhìn.
    assert.equal(kn.canNapLai(DA_NAP, { ...DA_NAP, north: DA_NAP.north + 0.004 }), false);

    // Quá dung sai thì bật.
    assert.equal(kn.canNapLai(DA_NAP, { ...DA_NAP, north: DA_NAP.north + 0.006 }), true);
});

test('dung sai theo CẠNH khung, không phải một số độ cố định', () => {
    // Ở mức cả nước một cạnh là ~15°, ở mức đường phố nó là ~0,005°. Một
    // ngưỡng tuyệt đối đúng ở đầu này thì sai ba bậc ở đầu kia.
    const canuoc = khung(16, 107, 15);
    const duongpho = khung(10.8, 106.7, 0.004);

    // 0,05° là nhiễu ở mức cả nước…
    assert.equal(kn.canNapLai(canuoc, { ...canuoc, north: canuoc.north + 0.05 }), false);

    // …và là một cú kéo hẳn sang vùng khác ở mức đường phố.
    assert.equal(kn.canNapLai(duongpho, { ...duongpho, north: duongpho.north + 0.05 }), true);
});

test('lần nạp trước bị CẮT ở 500 thì mọi cú di chuyển đều đáng nạp lại', () => {
    // Trần cứng của `GET /api/v2/screens/map`. Tập đang có không đầy đủ ngay
    // cả trong vùng cũ, nên thu nhỏ lại cũng có thể lộ ra pin đã bị cắt —
    // luật "nằm gọn bên trong" không còn đúng.
    assert.equal(kn.canNapLai(DA_NAP, khung(10.8, 106.7, 0.4), true), true);

    // Nhưng đứng yên hoàn toàn thì vẫn không hiện: hàm chỉ được gọi ở
    // `moveend`, và ca này canh rằng cờ `daCat` không tự bật nút một mình.
    assert.equal(kn.canNapLai(null, khung(10.8, 106.7, 0.4), true), false);
});

// ── Bốn con số của dải tổng quan ───────────────────────────────────────────

const pin = (amount, unit = 'màn hình/tháng', currency = 'VND') => ({
    slug: 's' + Math.random(),
    name: 'X',
    lat: 10,
    lng: 106,
    price: { amount, currency, unit },
});

test('không có pin nào thì mọi ô đều rỗng, không phải số 0 giả', () => {
    const t = kn.tomTatKhung([], null);

    assert.equal(t.tong, 0);
    assert.equal(t.daBaoGia, 0);
    assert.equal(t.giaMin, null);
    assert.equal(t.giaMax, null);
    assert.equal(t.donVi, null);
});

test('tổng lấy từ meta.total, KHÔNG từ số pin trả về', () => {
    // Hai con số khác nhau khi API cắt ở trần: `data` có 500 phần tử còn
    // `meta.total` nói 1.200. In số nhỏ là nói với người mua rằng vùng này
    // chỉ có 500 vị trí.
    const t = kn.tomTatKhung([pin(1_000_000)], { returned: 1, total: 1200, truncated: true });

    assert.equal(t.tong, 1200);
    assert.equal(t.daCat, true);
});

test('đếm "đã báo giá" bỏ qua giá rỗng và giá bằng không', () => {
    const t = kn.tomTatKhung(
        [pin(5_000_000), pin(null), pin(0), pin(8_000_000), { slug: 'x', name: 'Y', lat: 1, lng: 1 }],
        null,
    );

    assert.equal(t.tong, 5);
    assert.equal(t.daBaoGia, 2);
});

test('khoảng giá chỉ lấy MỘT nhóm đơn vị, và nói rõ nhóm nào', () => {
    // Lỗi mà ca này chặn: min–max của tất cả cho ra `2,5 – 40.000.000`, trộn
    // một giá CPM tính bằng đô với một giá thuê tháng tính bằng đồng.
    const t = kn.tomTatKhung(
        [
            pin(5_000_000),
            pin(40_000_000),
            pin(12_000_000),
            pin(2.5, 'CPM', 'USD'),
            pin(3.1, 'CPM', 'USD'),
        ],
        null,
    );

    assert.equal(t.daBaoGia, 5);
    assert.equal(t.giaMin, 5_000_000);
    assert.equal(t.giaMax, 40_000_000);
    assert.equal(t.donVi, 'màn hình/tháng');
    assert.equal(t.tienTe, 'VND');
});

test('nhóm ĐÔNG NHẤT thắng, kể cả khi nó không phải nhóm đầu tiên', () => {
    const t = kn.tomTatKhung(
        [pin(9_000_000), pin(2.5, 'CPM', 'USD'), pin(3.1, 'CPM', 'USD'), pin(4.4, 'CPM', 'USD')],
        null,
    );

    assert.equal(t.donVi, 'CPM');
    assert.equal(t.tienTe, 'USD');
    assert.equal(t.giaMin, 2.5);
    assert.equal(t.giaMax, 4.4);
});

test('cùng đơn vị đo nhưng khác đơn vị tiền là HAI nhóm', () => {
    // `floor_cpm` có hàng USD trong dữ liệu thật, và `io_rate` thì luôn VND.
    // Gom chung theo tên đơn vị đo là lại trộn hai thang tiền.
    const t = kn.tomTatKhung([pin(3, 'CPM', 'USD'), pin(70_000, 'CPM', 'VND')], null);

    assert.equal(t.giaMin, t.giaMax, 'Hai nhóm một phần tử: phải lấy đúng một nhóm.');
});

test('tuần và tháng không trộn vào một khoảng', () => {
    const t = kn.tomTatKhung(
        [pin(2_000_000, 'màn hình/tuần'), pin(30_000_000), pin(40_000_000)],
        null,
    );

    assert.equal(t.donVi, 'màn hình/tháng');
    assert.equal(t.giaMin, 30_000_000);
});

// ── Tiền viết gọn ──────────────────────────────────────────────────────────

test('tiền rút gọn cho một ô rộng 90px', () => {
    assert.equal(kn.gonTien(5_000_000, 'VND'), '5tr');
    assert.equal(kn.gonTien(1_200_000, 'VND'), '1,2tr');
    assert.equal(kn.gonTien(450_000, 'VND'), '450k');
    assert.equal(kn.gonTien(2_500_000_000, 'VND'), '2,5 tỷ');
    assert.equal(kn.gonTien(800, 'VND'), '800 ₫');
});

test('tiền KHÔNG phải VND thì không rút — rút một số một chữ số là thêm chỗ để sai', () => {
    assert.equal(kn.gonTien(2.5, 'USD'), '2,5 USD');
    assert.equal(kn.gonTien(3, 'USD'), '3 USD');
});

test('thiếu đơn vị tiền thì coi là VND', () => {
    // `MapPinResource::priceOf()` luôn trả `currency`, nhưng DTO khai nó
    // required còn dữ liệu cũ thì không chắc. Mặc định VND khớp với
    // `io_rate`, nhánh chiếm đa số kho.
    assert.equal(kn.gonTien(5_000_000, null), '5tr');
});

// ── Việc nối dây ───────────────────────────────────────────────────────────

test('hook thực sự dùng hai phép trên, và moveend KHÔNG còn gọi API', () => {
    const hook = readFileSync(join(GOC, 'webapp/lib/dung-ban-do.ts'), 'utf8');

    assert.match(hook, /canNapLai\(/, '`dung-ban-do.ts` không gọi `canNapLai`.');
    assert.match(hook, /tomTatKhung\(/, '`dung-ban-do.ts` không gọi `tomTatKhung`.');

    const moveend = hook.slice(hook.indexOf("map.on('moveend'"));
    const than = moveend.slice(0, moveend.indexOf('});'));

    assert.ok(than.length > 0, "Không tìm thấy trình xử lý `moveend`.");

    assert.doesNotMatch(
        than,
        /napPin|napRef/,
        'Trình xử lý `moveend` lại gọi nạp pin. Đó là hành vi trước lát 4: mỗi cú kéo một '
            + 'lời gọi API, và danh sách nhảy dưới tay người đang đọc nó.',
    );

    assert.match(
        than,
        /setCapNhat\(/,
        '`moveend` không còn bật cờ nào, nên nút "Tìm trong khu vực này" sẽ không bao giờ '
            + 'hiện và bản đồ đứng im với dữ liệu của vùng đã rời khỏi.',
    );
});

test('nút Tìm-trong-khu-vực-này dùng napPin MỚI NHẤT, không phải bản của lượt render đầu', () => {
    // Lỗi đo được trên production 22/10/2026, trước lát 4: effect dựng bản đồ
    // có deps `[]`, nên `moveend` đóng kín `napPin` của lượt render đầu tiên.
    // Mở `/explore?screen_type=lcd&view=map` rồi thu nhỏ bản đồ: lời gọi đi ra
    // KHÔNG có `screen_type=lcd` và bản đồ vẽ lại 95 pin toàn kho, trong khi
    // danh sách bên cạnh vẫn 20 thẻ LCD. Một URL, hai tập dữ liệu.
    const hook = readFileSync(join(GOC, 'webapp/lib/dung-ban-do.ts'), 'utf8');

    assert.match(
        hook,
        /napRef\.current = napPin/,
        'Hook không còn giữ `napPin` mới nhất trong ref.',
    );

    assert.match(
        hook,
        /const napLai = useCallback\([\s\S]{0,200}\[napPin\]\)/,
        '`napLai` không phụ thuộc `napPin`, nên nút sẽ nạp lại bằng bộ lọc của lượt render '
            + 'đầu tiên — đúng lỗi lát này sửa.',
    );
});

test('nhãn đơn vị rút cho vừa một ô 90px, và không mất chữ phân biệt kỳ', () => {
    // `màn hình/tháng` trong ô 90px bị cắt thành `màn hình/th…` — mất đúng
    // chữ phân biệt tháng với tuần, tức mất cả nghĩa của khoảng giá bên trên.
    assert.equal(kn.nhanDonVi('màn hình/tháng'), 'Giá/tháng');
    assert.equal(kn.nhanDonVi('màn hình/tuần'), 'Giá/tuần');

    // Không có tiền tố thì in nguyên, không đoán.
    assert.equal(kn.nhanDonVi('CPM'), 'Giá CPM');

    // Chuỗi lạ từ máy chủ ra một nhãn dài hơn mong muốn — chứ không ra nhãn
    // sai kỳ hay ô trống.
    assert.equal(kn.nhanDonVi('mỗi ngày'), 'Giá mỗi ngày');
    assert.equal(kn.nhanDonVi(null), 'Khoảng giá');
});
