import type { MucDaChon } from './danh-sach-rut-gon';

/**
 * Xuất danh sách rút gọn ra CSV (lát 7).
 *
 * ══ Vì sao sinh ở trình duyệt, không qua endpoint ══
 *
 * Plan viết `GET /api/v2/quote-requests/{ma}/tep`, tức tải theo **mã yêu cầu
 * đã gửi**. Nhưng nút tải nằm ở danh sách rút gọn — chỗ người mua đứng
 * **trước** khi gửi, khi chưa có mã nào. Hai thứ đó không khớp.
 *
 * Danh sách sống trong `localStorage`; máy chủ không biết nó. Gửi nó lên chỉ
 * để nhận lại dưới dạng tệp là một vòng mạng cho dữ liệu đã nằm sẵn trong
 * tay, cộng một endpoint mới phải phân quyền. Chủ dự án chốt kiểu trình
 * duyệt ngày 20/10/2026.
 *
 * ══ BOM UTF-8, và vì sao nó bắt buộc ở đây ══
 *
 * Excel trên Windows đọc CSV không BOM theo bảng mã ANSI của hệ thống, nên
 * "Hà Nội" ra "HÃ  Ná»™i". Ba byte đầu `EF BB BF` là thứ duy nhất nói với nó
 * rằng tệp là UTF-8. Người nhận tệp này là agency Việt Nam mở bằng Excel —
 * bỏ BOM là hỏng đúng nhóm người dùng duy nhất của tính năng.
 */

/** Tiêu đề cột, theo đúng thứ tự xuất. */
export const COT = ['STT', 'Tên vị trí', 'Thành phố', 'Media owner', 'Mã vị trí', 'Đường dẫn'] as const;

export const BOM = '﻿';

/**
 * Bọc một ô theo RFC 4180.
 *
 * Bọc khi ô chứa dấu phẩy, nháy kép, xuống dòng — hoặc **chấm phẩy**: Excel
 * ở một số máy Việt Nam lấy `;` làm dấu phân cách cột theo cài đặt vùng, và
 * một tên màn hình có dấu chấm phẩy sẽ vỡ thành hai cột ở đúng những máy đó.
 *
 * Nháy kép bên trong nhân đôi. Tên màn hình do media owner nhập, nên nó chứa
 * được mọi thứ — kho thật đã có một tên mang dấu `<`.
 */
export function oCsv(gia: unknown): string {
    const s = gia === null || gia === undefined ? '' : String(gia);

    return /[",;\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

/**
 * Dựng nội dung tệp.
 *
 * `goc` là origin để ghép đường dẫn đầy đủ (`https://oohx.net/explore/...`):
 * agency dán cột này thẳng vào bảng kế hoạch của họ và bấm được. Truyền vào
 * thay vì đọc `window` ở đây để hàm chạy được trong `node --test`.
 *
 * Xuống dòng là CRLF, cũng theo RFC 4180 — Excel cũ trên Windows nối cả tệp
 * thành một dòng khi chỉ có LF.
 */
export function dungCsv(ds: MucDaChon[], goc = ''): string {
    const dong = [COT.join(',')];

    ds.forEach((m, i) => {
        dong.push([
            i + 1,
            oCsv(m.ten),
            oCsv(m.thanhPho ?? ''),
            oCsv(m.owner ?? ''),
            oCsv(m.slug),
            oCsv(goc ? `${goc}/explore/${m.slug}` : ''),
        ].join(','));
    });

    return BOM + dong.join('\r\n') + '\r\n';
}

/** `oohx-danh-sach-2026-10-20.csv` — ngày để người nhận phân biệt hai lần tải. */
export function tenTep(luc = new Date()): string {
    const hai = (n: number) => String(n).padStart(2, '0');

    return `oohx-danh-sach-${luc.getFullYear()}-${hai(luc.getMonth() + 1)}-${hai(luc.getDate())}.csv`;
}
