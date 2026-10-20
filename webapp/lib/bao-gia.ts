import type { MucDaChon } from './danh-sach-rut-gon';

/**
 * Phần **thuần** của màn hình gửi yêu cầu báo giá (lát 6).
 *
 * Tách khỏi component để chạy được trong `node --test` mà không cần React:
 * ba hàm dưới đây là chỗ dễ sai nhất của màn hình đó — gom theo owner, dựng
 * thân yêu cầu, và đọc lỗi trả về — còn JSX thì chỉ là hình.
 */

export type NhomOwner = {
    owner: string;
    muc: MucDaChon[];
};

/** Nhãn dùng khi một mục không biết media owner. */
export const OWNER_KHONG_RO = 'Chưa rõ media owner';

/**
 * Gom danh sách theo media owner, nhiều vị trí nhất trước.
 *
 * Người mua cần thấy **yêu cầu này đi tới mấy nơi**: mười hai vị trí của một
 * owner là một cuộc thương lượng, mười hai vị trí của tám owner là tám.
 *
 * Thứ tự trong mỗi nhóm giữ nguyên thứ tự người dùng đã tick — đó là thứ tự
 * họ nhớ, không phải thứ tự chữ cái.
 */
export function gomTheoOwner(ds: MucDaChon[]): NhomOwner[] {
    const theoTen = new Map<string, MucDaChon[]>();

    for (const m of ds) {
        const ten = m.owner?.trim() || OWNER_KHONG_RO;
        const nhom = theoTen.get(ten);

        if (nhom) nhom.push(m);
        else theoTen.set(ten, [m]);
    }

    return [...theoTen.entries()]
        .map(([owner, muc]) => ({ owner, muc }))
        .sort((a, b) => b.muc.length - a.muc.length);
}

export type ThongTinGui = {
    contact_name: string;
    contact_email: string;
    contact_phone?: string;
    contact_company?: string;
    campaign_start?: string;
    campaign_end?: string;
    budget_vnd?: string;
    notes?: string;
    dong_y_chinh_sach: boolean;
};

/**
 * Dựng thân gửi lên `POST /api/v2/quote-requests`.
 *
 * ══ Ô trống bị **bỏ hẳn**, không gửi chuỗi rỗng ══
 *
 * `campaign_start` rỗng gửi lên thành `""`, và luật `date` của Laravel từ
 * chối chuỗi rỗng — người dùng không điền một ô **tuỳ chọn** lại nhận 422.
 * Cùng lý do cho `budget_vnd`: `''` không phải `integer`.
 *
 * ══ Ngân sách về số nguyên ở đây ══
 *
 * Người ta gõ "500.000.000" hay "500,000,000" tuỳ bàn phím. Máy chủ nhận số
 * nguyên VND (CLAUDE.md mục 6), nên chỗ bóc dấu phân cách là ở đây — một
 * chỗ, có phép kiểm, thay vì trong một `onChange` không ai đọc.
 */
export function dungThan(ds: MucDaChon[], tt: ThongTinGui): Record<string, unknown> {
    const than: Record<string, unknown> = {
        slugs: ds.map((m) => m.slug),
        contact_name: tt.contact_name.trim(),
        contact_email: tt.contact_email.trim(),
        dong_y_chinh_sach: tt.dong_y_chinh_sach,
    };

    for (const khoa of ['contact_phone', 'contact_company', 'campaign_start', 'campaign_end', 'notes'] as const) {
        const v = tt[khoa]?.trim();

        if (v) than[khoa] = v;
    }

    const so = soNguyenVnd(tt.budget_vnd);

    if (so !== null) than.budget_vnd = so;

    return than;
}

/** `"500.000.000 ₫"` → `500000000`. Trả `null` khi không có con số nào. */
export function soNguyenVnd(tho?: string): number | null {
    if (!tho) return null;

    const chiSo = tho.replace(/[^\d]/g, '');

    if (chiSo === '') return null;

    const n = Number.parseInt(chiSo, 10);

    return Number.isSafeInteger(n) ? n : null;
}

/**
 * Lấy câu lỗi đọc được từ envelope `{error, message, code, details[]}`.
 *
 * Ưu tiên `details[0].message` vì nó nói đúng ô nào sai; `message` ở ngoài
 * là câu chung ("Dữ liệu gửi lên không hợp lệ"), đọc một mình thì người dùng
 * không biết sửa gì.
 */
export function cauLoi(than: unknown, mac: string): string {
    if (typeof than !== 'object' || than === null) return mac;

    const b = than as { message?: unknown; details?: unknown };

    if (Array.isArray(b.details) && b.details.length > 0) {
        const dau = b.details[0] as { message?: unknown };

        if (typeof dau?.message === 'string' && dau.message !== '') return dau.message;
    }

    return typeof b.message === 'string' && b.message !== '' ? b.message : mac;
}
