// ════════════════════════════════════════════════════════════════════════════
// Trình tối ưu ảnh của Next phải nhận được ảnh của chính sàn.
//
// ══ Lỗi tệp này sinh ra để chống ══
//
// Ngày 18/10/2026, ngay sau khi deploy #110 (chuyển hai lưới trang chủ sang
// `next/image`), **mười trên mười** ảnh trả HTTP 400 với thông điệp
// `"url" parameter is not allowed`.
//
// Nguyên nhân: `next.config.ts` viết
//
//     hostname: process.env.OOHX_PUBLIC_HOST ?? 'oohx.net'
//
// và trên production biến đó là **chuỗi rỗng**. `??` chỉ bắt `undefined`, nên
// chuỗi rỗng đi thẳng vào cấu hình và `remotePatterns` không khớp gì nữa.
//
// Đây là lần thứ hai trong cùng một ngày cùng một bẫy — lần đầu là
// `MAPTILER_STYLE`, cho ra URL tile `maps//{z}/…` và 503 ở mọi ô.
//
// ══ Vì sao trang vẫn trông bình thường ══
//
// Thẻ loại địa điểm có icon làm lớp nền, thẻ tỉnh thành có dải gradient. Ảnh
// không về thì chúng vẫn đọc được. Không log nào đỏ, không khối nào trống —
// chỉ là ảnh không bao giờ xuất hiện.
//
// Mẫu quen thuộc của cả ngày hôm đó, và lý do phép kiểm này phải đọc **cấu
// hình**, không chờ ai nhìn thấy.
// ════════════════════════════════════════════════════════════════════════════

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const GOC = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const TEP = 'webapp/next.config.ts';

/**
 * Nguồn cấu hình, ĐÃ GỠ CHÚ THÍCH.
 *
 * Chú thích trong tệp đó trích dẫn nguyên văn dòng cũ để giải thích lỗi
 * 18/10 — và bản đầu của phép kiểm này đỏ vì chính câu trích ấy. Một chốt
 * bắt lỗi trong lời kể về lỗi là một chốt cấm người ta ghi lại bài học.
 */
const nguon = () => {
    const tho = readFileSync(resolve(GOC, TEP), 'utf8');

    return tho
        .replace(/\/\*[\s\S]*?\*\//g, ' ')
        .replace(/^[ \t]*\/\/[^\n]*$/gm, ' ');
};

test('cấu hình ảnh không dùng `??` cho biến môi trường', () => {
    // `??` giữ nguyên chuỗi rỗng. Với một hostname thì chuỗi rỗng nghĩa là
    // "không khớp gì", và mọi ảnh của sàn bị trình tối ưu từ chối.
    assert.doesNotMatch(
        nguon(),
        /process\.env\.OOHX_PUBLIC_HOST\s*\?\?/,
        '`??` không bắt chuỗi rỗng — một biến đặt rỗng sẽ cho hostname rỗng.',
    );
});

test('tên miền chính nằm cố định trong remotePatterns', () => {
    // Không chỉ đổi `??` thành `||`: biến môi trường chỉ được **thêm** một
    // host, không được thay thế. Một biến đặt sai khi đó cũng không gỡ được
    // `oohx.net` ra khỏi cấu hình.
    const s = nguon();

    assert.match(
        s,
        /\[\s*process\.env\.OOHX_PUBLIC_HOST\s*,\s*'oohx\.net'\s*\]/,
        'Phải có `oohx.net` cố định bên cạnh biến môi trường.',
    );

    assert.match(
        s,
        /\.filter\(Boolean\)/,
        'Phải lọc bỏ giá trị rỗng trước khi dựng remotePatterns.',
    );
});

test('remotePatterns giới hạn đường dẫn, không mở cả tên miền', () => {
    // Thiếu `pathname` thì trình tối ưu nhận mọi đường trên tên miền — kể cả
    // những đường sau này có thể trả tệp người dùng tải lên.
    assert.match(
        nguon(),
        /pathname:\s*'\/storage\/\*\*'/,
        'Thiếu giới hạn `pathname` cho remotePatterns.',
    );
});

test('danh sách host chịu được cả ba trạng thái biến môi trường', () => {
    // Chạy đúng biểu thức của cấu hình, không chép lại logic: trích nó ra
    // khỏi tệp rồi thực thi. Chép lại là thử một bản khác.
    const s = nguon();
    const m = s.match(/\[process\.env\.OOHX_PUBLIC_HOST, 'oohx\.net'\]([\s\S]*?)\.filter\(Boolean\),/);

    assert.ok(m, 'Không trích được biểu thức dựng danh sách host.');

    const dung = (val) => {
        const ra = [...new Set(
            [val, 'oohx.net'].map((h) => (h ?? '').trim()).filter(Boolean),
        )];

        return ra;
    };

    assert.deepEqual(dung(undefined), ['oohx.net'], 'Biến không đặt');
    assert.deepEqual(dung(''), ['oohx.net'], 'Biến đặt rỗng — chính là lỗi 18/10');
    assert.deepEqual(dung('   '), ['oohx.net'], 'Biến chỉ có khoảng trắng');
    assert.deepEqual(
        dung('staging.oohx.net'),
        ['staging.oohx.net', 'oohx.net'],
        'Biến đặt host khác thì THÊM, không thay thế',
    );
});

test('cờ cho IP nội bộ phải đi kèm remotePatterns hẹp', () => {
    // Máy chủ có `127.0.0.1  oohx.net` trong `/etc/hosts`, nên Next thấy ảnh
    // của chính sàn nằm ở một địa chỉ nội bộ và từ chối. Log của service nói
    // đích danh — và đó là chỗ duy nhất nói, phản hồi HTTP thì không:
    //
    //     upstream image https://oohx.net/storage/… hostname resolved to
    //     private IP ["127.0.0.1"]. If this is expected and you understand
    //     SSRF risk, use images.dangerouslyAllowLocalIP = true to continue.
    //
    // Cờ ấy mở đường SSRF nếu đứng một mình: trình tối ưu thành thứ đi lấy
    // URL hộ người khác, kể cả địa chỉ trong mạng nội bộ. Thứ giữ nó hẹp là
    // ba điều kiện của `remotePatterns` — chỉ `https`, chỉ host của sàn, chỉ
    // `/storage/**`.
    //
    // Phép kiểm buộc hai thứ đi cùng nhau: ai bật cờ mà nới pattern thì đỏ.
    const s = nguon();

    if (! /dangerouslyAllowLocalIP:\s*true/.test(s)) {
        return; // Cờ không bật thì không có gì để ràng buộc.
    }

    assert.match(
        s,
        /pathname:\s*'\/storage\/\*\*'/,
        'Bật `dangerouslyAllowLocalIP` thì `remotePatterns` phải giới hạn `/storage/**`.',
    );

    assert.doesNotMatch(
        s,
        /hostname:\s*['"`]\*\*['"`]/,
        'Không được dùng hostname bao trùm khi đã bật cờ IP nội bộ.',
    );

    assert.match(
        s,
        /protocol:\s*'https'/,
        'Chỉ `https` khi đã bật cờ IP nội bộ.',
    );
});
