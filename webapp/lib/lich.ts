/**
 * Đọc lịch trống — dùng chung cho khối gọn trên màn hình đầu và lưới ngày
 * trong tab.
 *
 * ══ Vì sao một lib riêng ══
 *
 * Lát 3 đưa lịch ra khỏi tab thứ ba: người mua sàng mười vị trí không mở ba
 * tab cho mỗi vị trí, nên "đặt được từ khi nào" phải thấy được mà không bấm.
 * Nhưng tab **vẫn giữ**, vì nó chứa mức SOV theo từng ngày mà khối gọn không
 * chứa được.
 *
 * Hai khối, một nguồn dữ liệu, nên phép nhóm theo tháng phải nằm ở một chỗ.
 * Viết hai bản là cách hai khối nói hai con số khác nhau về cùng một lịch —
 * và chỗ lệch sẽ không ai thấy, vì hai khối không bao giờ hiện cạnh nhau.
 *
 * ══ Múi giờ ══
 *
 * Mọi phép tính đi qua `Date.UTC` từ chuỗi `YYYY-MM-DD`, **không** qua
 * `new Date(chuỗi)` ở múi giờ địa phương: máy chủ render và trình duyệt có thể
 * khác múi giờ, và lệch một ngày làm cả lưới trượt một ô. Đây là cùng lý do đã
 * ghi trong `LichTrong`.
 */

export type NgayTrong = { date: string; remaining_sov_pct: number };

export type ThangLich = {
    /** `YYYY-MM`, dùng làm khoá React. */
    khoa: string;
    nam: number;
    thang: number;
    ngay: NgayTrong[];
};

/** Nhóm các ngày theo `YYYY-MM`, giữ thứ tự API trả về. */
export function theoThang(days: NgayTrong[]): ThangLich[] {
    const nhom = new Map<string, NgayTrong[]>();

    for (const d of days) {
        const khoa = d.date.slice(0, 7);
        const danh = nhom.get(khoa);
        danh ? danh.push(d) : nhom.set(khoa, [d]);
    }

    return [...nhom.entries()].map(([khoa, ngay]) => {
        const [nam, thang] = khoa.split('-').map(Number);

        return { khoa, nam, thang, ngay };
    });
}

export type TomTatThang = {
    khoa: string;
    nam: number;
    thang: number;
    /** Số ngày còn chỗ (`remaining_sov_pct > 0`). */
    trong: number;
    /** Số ngày API trả về cho tháng đó — KHÔNG phải số ngày của tháng. */
    tong: number;
};

export type TomTatLich = {
    /** Ngày sớm nhất còn chỗ, dạng `YYYY-MM-DD`. `null` khi không có ngày nào. */
    ngaySom: string | null;
    thang: TomTatThang[];
    /** Tổng số ngày còn chỗ trong cả cửa sổ. */
    tongTrong: number;
};

/**
 * Tóm tắt lịch cho khối gọn.
 *
 * `tong` là **số ngày API trả về cho tháng đó**, không phải số ngày của tháng
 * theo dương lịch. Lý do: API bắt đầu từ hôm nay, nên tháng đầu của cửa sổ chỉ
 * có những ngày còn lại. In "8/31" cho tháng hiện tại là nói sai — 23 ngày kia
 * không phải đã đặt, chúng đã qua.
 */
export function tomTatLich(days: NgayTrong[] | null | undefined): TomTatLich {
    if (!Array.isArray(days) || days.length === 0) {
        return { ngaySom: null, thang: [], tongTrong: 0 };
    }

    const thang = theoThang(days).map((m) => ({
        khoa: m.khoa,
        nam: m.nam,
        thang: m.thang,
        trong: m.ngay.filter((d) => conCho(d)).length,
        tong: m.ngay.length,
    }));

    // `ngaySom` KHÔNG lấy `days[0]`: API trả theo thứ tự ngày, nhưng ngày đầu
    // có thể đã hết chỗ. Phải tìm ngày còn chỗ đầu tiên.
    const som = days.find((d) => conCho(d));

    return {
        ngaySom: som ? som.date : null,
        thang,
        tongTrong: days.filter((d) => conCho(d)).length,
    };
}

/**
 * Ngày còn chỗ hay không.
 *
 * `remaining_sov_pct` thiếu hoặc không phải số thì coi là **không còn chỗ**,
 * không phải còn: với một trang để ra quyết định mua, đoán rộng về phía "còn
 * chỗ" dẫn người mua tới một ngày không đặt được.
 */
function conCho(d: NgayTrong): boolean {
    return typeof d.remaining_sov_pct === 'number' && d.remaining_sov_pct > 0;
}

/** `2026-11-05` → `5/11`. Trả chuỗi rỗng cho giá trị không đọc được. */
export function ngayGon(iso: string | null | undefined): string {
    if (typeof iso !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '';

    const ngay = Number(iso.slice(8, 10));
    const thang = Number(iso.slice(5, 7));

    return `${ngay}/${thang}`;
}
