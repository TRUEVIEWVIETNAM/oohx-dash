// ════════════════════════════════════════════════════════════════════════════
// Phần thuần của màn hình gửi yêu cầu báo giá (lát 6).
//
// Import thẳng `webapp/lib/bao-gia.ts` — mã thật sẽ lên production, không
// phải một bản chép. Ba thứ canh ở đây là ba chỗ đã hoặc sẽ sai:
//
//   • Ô tuỳ chọn để trống gửi lên thành `""`, và luật `date`/`integer` của
//     Laravel từ chối chuỗi rỗng. Người dùng không điền một ô KHÔNG bắt buộc
//     mà nhận 422 là lỗi khó hiểu nhất trong một biểu mẫu.
//   • Ngân sách người ta gõ có dấu chấm, dấu phẩy, chữ "₫". Máy chủ nhận số
//     nguyên VND.
//   • Envelope lỗi của v2 là `{error, message, code, details[]}`; đọc sai
//     hình dạng thì người dùng chỉ thấy "Không gửi được, thử lại".
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';

const bg = await import('../../webapp/lib/bao-gia.ts');

function muc(slug, owner, ten = 'Màn hình ' + slug) {
    return { slug, ten, thanhPho: 'Hà Nội', owner };
}

const DU = {
    contact_name: ' Nguyễn Văn A ',
    contact_email: ' a@brand.vn ',
    dong_y_chinh_sach: true,
};

// ── Gom theo media owner ────────────────────────────────────────────────────

test('gom theo owner, nhóm nhiều vị trí nhất đứng trước', () => {
    const nhom = bg.gomTheoOwner([
        muc('a', 'Owner Một'),
        muc('b', 'Owner Hai'),
        muc('c', 'Owner Hai'),
    ]);

    assert.deepEqual(
        nhom.map((n) => [n.owner, n.muc.length]),
        [['Owner Hai', 2], ['Owner Một', 1]],
        'Người mua cần thấy yêu cầu đi tới mấy nơi, và nơi nào nặng nhất.',
    );
});

test('giữ nguyên thứ tự người dùng đã tick trong mỗi nhóm', () => {
    const nhom = bg.gomTheoOwner([muc('b', 'O'), muc('a', 'O'), muc('c', 'O')]);

    assert.deepEqual(nhom[0].muc.map((m) => m.slug), ['b', 'a', 'c']);
});

test('mục thiếu owner gom vào một nhóm có tên, không rơi ra ngoài', () => {
    const nhom = bg.gomTheoOwner([muc('a', undefined), muc('b', '  '), muc('c', 'Owner Một')]);

    const khongRo = nhom.find((n) => n.owner === bg.OWNER_KHONG_RO);

    assert.equal(khongRo?.muc.length, 2, 'Chuỗi rỗng và `undefined` là cùng một nhóm.');
    assert.equal(nhom.length, 2);
});

// ── Dựng thân gửi ───────────────────────────────────────────────────────────

test('ô tuỳ chọn để trống bị bỏ hẳn, không gửi chuỗi rỗng', () => {
    const than = bg.dungThan([muc('a', 'O')], {
        ...DU,
        contact_phone: '',
        campaign_start: '',
        campaign_end: '   ',
        budget_vnd: '',
        notes: '',
    });

    assert.deepEqual(
        Object.keys(than).sort(),
        ['contact_email', 'contact_name', 'dong_y_chinh_sach', 'slugs'],
        'Gửi `campaign_start: ""` thì luật `date` của Laravel trả 422 cho một '
            + 'ô người dùng cố tình bỏ trống.',
    );
});

test('cắt khoảng trắng hai đầu của tên và email', () => {
    const than = bg.dungThan([muc('a', 'O')], DU);

    assert.equal(than.contact_name, 'Nguyễn Văn A');
    assert.equal(than.contact_email, 'a@brand.vn');
});

test('slugs theo đúng thứ tự danh sách', () => {
    const than = bg.dungThan([muc('x', 'O'), muc('y', 'O')], DU);

    assert.deepEqual(than.slugs, ['x', 'y']);
});

test('ô có nội dung thì được gửi', () => {
    const than = bg.dungThan([muc('a', 'O')], {
        ...DU,
        campaign_start: '2026-11-01',
        campaign_end: '2026-11-30',
        notes: 'Ưu tiên quận trung tâm',
    });

    assert.equal(than.campaign_start, '2026-11-01');
    assert.equal(than.campaign_end, '2026-11-30');
    assert.equal(than.notes, 'Ưu tiên quận trung tâm');
});

// ── Ngân sách ───────────────────────────────────────────────────────────────

test('ngân sách bóc mọi dấu phân cách về số nguyên VND', () => {
    for (const [go, mong] of [
        ['500.000.000', 500000000],
        ['500,000,000', 500000000],
        ['500 000 000 ₫', 500000000],
        ['1tỷ2', 12],
        ['0', 0],
    ]) {
        assert.equal(bg.soNguyenVnd(go), mong, `"${go}" phải ra ${mong}`);
    }
});

test('ngân sách không có chữ số nào thì không gửi', () => {
    assert.equal(bg.soNguyenVnd(''), null);
    assert.equal(bg.soNguyenVnd('thương lượng'), null);
    assert.equal(bg.soNguyenVnd(undefined), null);

    const than = bg.dungThan([muc('a', 'O')], { ...DU, budget_vnd: 'thương lượng' });

    assert.equal('budget_vnd' in than, false);
});

// ── Đọc lỗi ─────────────────────────────────────────────────────────────────

test('lỗi validate đọc đúng ô nào sai, không đọc câu chung', () => {
    const cau = bg.cauLoi(
        {
            error: 'validation_failed',
            message: 'Dữ liệu gửi lên không hợp lệ.',
            code: 422,
            details: [{ field: 'contact_email', message: 'Email không đúng định dạng.' }],
        },
        'mặc định',
    );

    assert.equal(
        cau,
        'Email không đúng định dạng.',
        'Câu chung "dữ liệu không hợp lệ" không nói người dùng phải sửa gì.',
    );
});

test('không có details thì lấy câu chung', () => {
    assert.equal(
        bg.cauLoi({ error: 'khong_con_vi_tri', message: 'Không vị trí nào còn mở.', details: [] }, 'mặc định'),
        'Không vị trí nào còn mở.',
    );
});

test('thân lạ hoặc rỗng thì rơi về câu mặc định', () => {
    for (const than of [null, undefined, 'chuoi', 42, {}, { details: [] }, { message: '' }]) {
        assert.equal(bg.cauLoi(than, 'mặc định'), 'mặc định');
    }
});
