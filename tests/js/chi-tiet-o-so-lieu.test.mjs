/**
 * Chốt cho bốn ô số liệu trên `/explore/{slug}` — lát 1 của
 * `docs/PLAN-CHI-TIET-VI-TRI.md`.
 *
 * ══ Vì sao đọc mã nguồn thay vì gọi hàm ══
 *
 * `moTaGia()`, `moTaImpressions()` và biểu thức dựng `h1` nằm trong
 * `page.tsx` — một Server Component — và không được export. Đưa chúng sang
 * `webapp/lib/` chỉ để test được là một refactor nằm ngoài lát này.
 *
 * Nên lớp này đọc mã nguồn, giống cách `tests/js/do-dac.test.mjs` đọc
 * `DoDacService::CHO_PHEP` thẳng từ PHP. Nó canh được đúng những đột biến mà
 * `khoi-giao-dien.mjs` không thấy, vì fixture không có trường hợp tương ứng:
 * không màn hình giả nào có `daily_impressions_est: 0`, và không cái nào
 * thiếu `sov_max_pct`.
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const NGUON = new URL('../../webapp/app/explore/[slug]/page.tsx', import.meta.url);
const ma = readFileSync(NGUON, 'utf8');

test('bốn nhãn ô nằm đúng thứ tự trong mã nguồn', () => {
    // Thứ tự này là thứ tự một người mua sàng vị trí: giá trước, rồi lượng
    // người thấy. `khoi-giao-dien.mjs` canh thứ tự trên HTML đã render; ca này
    // canh ngay tại chỗ sửa, nên nó đỏ trước cả khi phải dựng Next.
    const nhan = ['Giá thuê', 'Impressions/ngày', 'Kích thước', 'SOV tối đa'];
    const viTri = nhan.map((n) => ma.indexOf(`nhan="${n}"`));

    for (let i = 0; i < nhan.length; i++) {
        assert.ok(viTri[i] > -1, `thiếu ô: ${nhan[i]}`);
    }
    for (let i = 1; i < nhan.length; i++) {
        assert.ok(
            viTri[i] > viTri[i - 1],
            `"${nhan[i]}" phải đứng sau "${nhan[i - 1]}"`,
        );
    }
});

test('bốn ô CŨ không còn là ô số liệu', () => {
    // "Site" và "Network" vẫn phải còn trong bảng thông số (`SpecO`) — chúng
    // chỉ thôi làm ô số liệu. Nên ca này canh đúng `nhan="..."` của `StatBox`,
    // không canh sự vắng mặt của chữ.
    for (const cu of ['Site', 'Network', 'Floor CPM']) {
        const d = ma.indexOf(`<StatBox nhan="${cu}"`);
        assert.equal(d, -1, `"${cu}" vẫn còn là ô số liệu`);
    }

    // Và chúng vẫn còn ở bảng thông số — nếu lát này xoá luôn thì mất thông
    // tin, không phải dọn.
    for (const con of ['Network', 'Site']) {
        assert.ok(
            ma.includes(`nhan="${con}"`),
            `"${con}" phải còn trong bảng thông số`,
        );
    }
});

test('h1 KHÔNG render screen.name', () => {
    // `screen.name` là mã kho — vị trí thật mang tên `OOHX_LED_CTO_CAN_THO`,
    // và nó từng là `h1` của trang.
    assert.ok(
        !ma.includes('className="dtitle">{screen.name}'),
        'h1 lại in mã kho',
    );
    assert.ok(
        ma.includes('className="dtitle">{tenHienThi}'),
        'h1 phải in tenHienThi',
    );
    // Mã kho vẫn hiện ra, nhưng ở nhãn phụ.
    assert.ok(ma.includes('className="dkho"'), 'mất nhãn mã kho');
});

test('ô SOV đọc trường thật, không đọc mặc định 100', () => {
    // `sovMax` mang `?? 100` cho `LichTrong`. Nếu ô số liệu dùng nó thì một
    // màn hình không khai `sov_max_pct` sẽ hiện "100%" — nói với người mua
    // rằng họ mua được toàn bộ thời lượng, trong khi hệ thống không biết.
    assert.ok(
        ma.includes('nhan="SOV tối đa"'),
        'thiếu ô SOV',
    );
    const khuc = ma.slice(ma.indexOf('nhan="SOV tối đa"'), ma.indexOf('nhan="SOV tối đa"') + 200);
    assert.ok(khuc.includes('sovThat'), 'ô SOV phải dùng sovThat');
    assert.ok(!khuc.includes('{sovMax}') && !khuc.includes('${sovMax}'), 'ô SOV dùng mặc định 100');
    assert.ok(ma.includes('const sovThat = screen.specs?.sov_max_pct;'), 'sovThat phải là trường thật, không có ??');
});

test('impressions bằng 0 KHÔNG được in ra', () => {
    // 0 ở đây nghĩa là "chưa đo", không phải "không ai thấy màn hình này".
    // Fixture không có màn hình nào `daily_impressions_est: 0`, nên chốt render
    // không bắt được đột biến này.
    const i = ma.indexOf('function moTaImpressions');
    assert.ok(i > -1, 'thiếu moTaImpressions');
    const than = ma.slice(i, ma.indexOf('\n}', i));

    assert.ok(than.includes('n <= 0'), 'moTaImpressions phải loại cả 0');
    assert.ok(than.includes("typeof n !== 'number'"), 'phải chặn kiểu không phải số');
    assert.ok(
        than.includes('daily_impressions_est'),
        'phải đọc daily_impressions_est, không suy ra từ trường khác',
    );
});

test('giá ghi rõ đang là giá GÌ', () => {
    // `io_rate` (thuê trọn theo tháng) và `floor_cpm` (giá sàn trên nghìn lượt)
    // khác nhau khoảng ba bậc độ lớn. In một trong hai mà không ghi đơn vị thì
    // người mua đọc sai mà không có cách nào biết.
    const i = ma.indexOf('function moTaGia');
    assert.ok(i > -1, 'thiếu moTaGia');
    const than = ma.slice(i, ma.indexOf('\n}\n', i));

    assert.ok(than.includes('/tháng'), 'thiếu đơn vị cho io_rate');
    assert.ok(than.includes("' CPM'"), 'thiếu đơn vị cho floor_cpm');
    assert.ok(
        than.indexOf('io_rate') < than.indexOf('floor_cpm'),
        'io_rate phải được ưu tiên trước floor_cpm',
    );
});

test('chuỗi địa giới không còn chỗ nào in thô', () => {
    // Lát 1 bỏ sót ba chỗ ở lượt đầu: `KhoiViTri`, ô "Tỉnh/Thành" và
    // `describe()`. Chốt render chỉ bắt được vì fixture có city hai cấp — ca
    // này bắt ngay tại mã, kể cả khi fixture đổi.
    const thoVaoGiaoDien = [
        'giaTri={screen.site?.city ?? screen.location?.city}',
        '[screen.site.address, screen.site.city]',
    ];
    for (const s of thoVaoGiaoDien) {
        assert.ok(!ma.includes(s), `còn in thô chuỗi địa giới: ${s}`);
    }

    assert.ok(ma.includes("from '@/lib/dia-gioi'"), 'phải dùng lib/dia-gioi');
});
