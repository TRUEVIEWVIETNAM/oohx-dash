/**
 * Chốt cho `webapp/lib/dia-gioi.ts` — lát 1 của `docs/PLAN-CHI-TIET-VI-TRI.md`.
 *
 * Dữ liệu thật đo được ngày 24/10/2026 trên
 * `GET /api/v2/screens/oohx-led-cto-can-tho`:
 *
 *     location.city = "Cần Thơ > Phường Tân An"
 *
 * Hai cấp hành chính trong một chuỗi, nối bằng `>`. Trang in nguyên chuỗi đó
 * ra giao diện trước lát này.
 */

import test from 'node:test';
import assert from 'node:assert/strict';

const { tachDiaGioi, moTaDiaGioi } = await import('../../webapp/lib/dia-gioi.ts');

test('tách đúng chuỗi hai cấp của dữ liệu thật', () => {
    assert.deepEqual(tachDiaGioi('Cần Thơ > Phường Tân An'), {
        tinh: 'Cần Thơ',
        duoiTinh: 'Phường Tân An',
    });
});

test('chuỗi một cấp thì không có cấp dưới', () => {
    assert.deepEqual(tachDiaGioi('Hà Nội'), { tinh: 'Hà Nội', duoiTinh: null });
});

test('ba cấp thì GIỮ cấp giữa, không bỏ', () => {
    // Lấy cấp cuối và bỏ cấp giữa là làm mất thông tin người mua cần: với một
    // người lên kế hoạch truyền thông, "Quận 1" nói nhiều hơn "Phường Bến Nghé".
    assert.deepEqual(tachDiaGioi('Hồ Chí Minh > Quận 1 > Phường Bến Nghé'), {
        tinh: 'Hồ Chí Minh',
        duoiTinh: 'Quận 1, Phường Bến Nghé',
    });
});

test('khoảng trắng quanh dấu bị cắt', () => {
    assert.deepEqual(tachDiaGioi('  Cần Thơ   >   Phường Tân An  '), {
        tinh: 'Cần Thơ',
        duoiTinh: 'Phường Tân An',
    });
});

test('cấp rỗng do nhập lỗi bị bỏ, không để lại dấu phẩy treo', () => {
    // "Cần Thơ >" và "Cần Thơ > > Tân An" đều gặp được trong dữ liệu nhập tay.
    assert.deepEqual(tachDiaGioi('Cần Thơ >'), { tinh: 'Cần Thơ', duoiTinh: null });
    assert.deepEqual(tachDiaGioi('Cần Thơ > > Phường Tân An'), {
        tinh: 'Cần Thơ',
        duoiTinh: 'Phường Tân An',
    });
});

test('chuỗi rỗng, null, undefined và chuỗi chỉ có dấu đều không làm vỡ', () => {
    for (const v of ['', '   ', '>', ' > > ', null, undefined]) {
        assert.deepEqual(tachDiaGioi(v), { tinh: '', duoiTinh: null }, `giá trị: ${JSON.stringify(v)}`);
    }
});

test('kiểu không phải chuỗi không làm vỡ', () => {
    // `location.city` là `string | null` theo đặc tả, nhưng hàm này đứng ở biên
    // giữa API và giao diện — một con số hay một đối tượng rơi vào đây thì
    // trang phải vẫn render, không phải đổ.
    for (const v of [0, 123, {}, [], true]) {
        assert.deepEqual(tachDiaGioi(v), { tinh: '', duoiTinh: null }, `giá trị: ${JSON.stringify(v)}`);
    }
});

test('kết quả KHÔNG bao giờ còn dấu lớn hơn', () => {
    // Đây là cái chốt của cả lát: dấu `>` là ký tự kỹ thuật của dữ liệu, và nó
    // đã đi ra tới giao diện production. Nếu một ngày có người nối chuỗi lại
    // bằng `>` thì ca này đỏ.
    const vao = ['Cần Thơ > Phường Tân An', 'Hồ Chí Minh > Quận 1 > Phường Bến Nghé', 'Hà Nội'];
    for (const s of vao) {
        const { tinh, duoiTinh } = tachDiaGioi(s);
        assert.ok(!tinh.includes('>'), `tinh còn dấu: ${tinh}`);
        assert.ok(duoiTinh === null || !duoiTinh.includes('>'), `duoiTinh còn dấu: ${duoiTinh}`);
        assert.ok(!moTaDiaGioi(s).includes('>'), `moTaDiaGioi còn dấu: ${moTaDiaGioi(s)}`);
    }
});

test('moTaDiaGioi nối bằng dấu giữa, không phải dấu lớn hơn', () => {
    assert.equal(moTaDiaGioi('Cần Thơ > Phường Tân An'), 'Cần Thơ · Phường Tân An');
    assert.equal(moTaDiaGioi('Hà Nội'), 'Hà Nội');
    assert.equal(moTaDiaGioi(null), '');
    assert.equal(moTaDiaGioi('  '), '');
});
