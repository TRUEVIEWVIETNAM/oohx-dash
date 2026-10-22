import type { MucDaChon } from './danh-sach-rut-gon';

/**
 * Phép tính của bảng tổng kế hoạch — lát 6 của
 * `docs/PLAN-GOP-EXPLORE-BAN-DO.md`.
 *
 * Tách khỏi DOM vì đây là chỗ duy nhất trong trang công khai **cộng tiền
 * lại**. Một phép nhân sai ở đây ra một con số trông hợp lý mà không ai đối
 * chiếu được — người mua mang nó đi lập ngân sách, và chỗ sai lộ ra ở bàn
 * đàm phán. Gọi bằng số thì chốt được.
 *
 * ══ Con số ở đây là ƯỚC TÍNH, và mọi thứ thật vẫn do máy chủ tính ══
 *
 * CLAUDE.md mục 5: "Số lượng và số tiền **do máy chủ tính**, không nhận từ
 * client." Bảng này không đặt chỗ, không tạo đơn, không gửi gì đi — nó cộng
 * những con số người mua đã nhìn thấy trên từng thẻ, để họ biết mình đang
 * khoanh một kế hoạch cỡ nào. Khi thành đơn thật thì
 * `POST /api/v2/quote-requests` gửi **slug**, và máy chủ tự tra giá.
 *
 * ══ Ba con số plan vẽ mà dữ liệu hôm nay không có ══
 *
 * Đo trên production ngày 22/10/2026, cả 104 bản ghi:
 *
 * | Trường | Có | Hệ quả |
 * |---|---|---|
 * | `location.district` | **0/104** | không đếm được quận/huyện |
 * | `pricing.floor_cpm` | **0/104** | không có CPM nào để gộp |
 * | lượt xem/ngày | không có trường | không có mẫu số cho CPM |
 * | `pricing.io_rate` | 19/104 | chi phí tính được, cho 19 vị trí |
 *
 * Nên bảng đổi hai ô: **tỉnh thành** thay cho quận/huyện (tính được cho cả
 * 104, và với một chiến dịch toàn quốc thì đó mới là đơn vị phủ sóng), và
 * **chi phí** thay cho CPM gộp. Ô lượt xem giữ nguyên chỗ và nói thật là
 * chưa có số đo — cùng cách lát 4 làm với dải tổng quan.
 */

/** Số ngày mặc định của một đợt chạy. */
export const SO_NGAY_MAC_DINH = 30;

const NGAY_MOI_KY = { week: 7, month: 30 } as const;

/**
 * Tỉnh/thành từ chuỗi địa điểm.
 *
 * `location.city` trên production có **hai** hình dạng, đo được: 83/104 là
 * tên tỉnh trần (`"Hồ Chí Minh"`), và 21/104 là một đường dẫn
 * (`"Thái Nguyên > Phường Bắc Kạn"`). Lấy phần trước dấu `>`.
 *
 * Không cố đoán quận từ phần sau: ở những bản ghi đó phần sau là **phường**,
 * không phải quận, và gọi nó là quận trong một bảng độ phủ là nói sai với
 * người đang lập kế hoạch truyền thông.
 */
export function tinhTu(thanhPho?: string | null): string | null {
    if (!thanhPho) return null;

    const t = thanhPho.split('>')[0]!.trim();

    return t || null;
}

/**
 * Số kỳ phải trả cho một đợt chạy dài `soNgay`, **làm tròn lên**.
 *
 * Làm tròn lên chứ không chia thẳng: OOH bán theo kỳ, nên 45 ngày trên một
 * bảng giá tháng là trả **hai tháng**, không phải 1,5. Làm tròn xuống hay
 * chia lẻ ra một con số thấp hơn số người mua thật sự phải trả — và một
 * trang bán hàng báo thiếu tiền là loại sai người ta chỉ phát hiện ở bàn
 * đàm phán.
 *
 * Tối thiểu một kỳ: không ai thuê nửa tháng với giá nửa tháng.
 */
export function soKy(soNgay: number, ky: 'week' | 'month' = 'month'): number {
    const ngay = Math.max(1, Math.floor(soNgay) || 0);

    return Math.max(1, Math.ceil(ngay / NGAY_MOI_KY[ky]));
}

/**
 * Kỳ thuê suy từ **nhãn hiển thị** của giá.
 *
 * `ScreenSummary.pricing.io_rate.unit` là mã (`week` / `month`), nhưng
 * `MapPin.price.unit` là nhãn người đọc do
 * `ScreenInventory::getDisplayPriceUnitAttribute()` sinh ra:
 * `màn hình/tháng`, `màn hình/tuần`, hoặc `CPM`. Popup bản đồ chỉ có cái
 * thứ hai.
 *
 * Trả `null` cho `CPM` — và đó là điểm chính của hàm này. Giá CPM là tiền
 * cho 1.000 lượt hiển thị, không phải tiền thuê một kỳ: nhân nó với số
 * tháng ra một con số vô nghĩa, và nó sẽ nằm giữa một bảng ngân sách trông
 * đứng đắn. Thà không cộng vị trí đó và nói ra, còn hơn cộng sai.
 */
export function kyTuNhan(nhan?: string | null): 'week' | 'month' | null {
    if (!nhan) return null;
    if (nhan === 'week' || nhan === 'month') return nhan;
    if (nhan.includes('tuần')) return 'week';
    if (nhan.includes('tháng')) return 'month';

    return null;
}

export type Dai = { ten: string; so: number };

export type TomTatKeHoach = {
    soViTri: number;

    /** Tỉnh thành phủ được — xem `tinhTu` về việc vì sao không phải quận. */
    soTinh: number;

    /** Bao nhiêu mục có `io_rate`, và bao nhiêu thì không. */
    soCoGia: number;
    soChuaGia: number;

    /**
     * Tổng chi phí ước tính, VND. Chỉ cộng những mục **có** giá.
     *
     * `null` khi không mục nào có giá: một tổng bằng 0 trên một kế hoạch 12
     * vị trí nói rằng kế hoạch này miễn phí.
     */
    chiPhi: number | null;

    /** Phân bổ theo loại hình và theo tỉnh, nhiều nhất trước. */
    theoLoai: Dai[];
    theoTinh: Dai[];
};

function dem(gt: (string | null | undefined)[]): Dai[] {
    const m = new Map<string, number>();

    for (const g of gt) {
        if (!g) continue;
        m.set(g, (m.get(g) ?? 0) + 1);
    }

    return [...m.entries()]
        .map(([ten, so]) => ({ ten, so }))
        // Nhiều nhất trước; hoà thì theo bảng chữ cái, để hai lần mở bảng
        // với cùng một kế hoạch cho cùng một thứ tự.
        .sort((a, b) => b.so - a.so || a.ten.localeCompare(b.ten, 'vi'));
}

export function tomTatKeHoach(ds: MucDaChon[], soNgay: number): TomTatKeHoach {
    let chiPhi = 0;
    let soCoGia = 0;

    for (const m of ds) {
        if (!m.gia || m.gia <= 0) continue;

        soCoGia += 1;
        chiPhi += m.gia * soKy(soNgay, m.ky ?? 'month');
    }

    const tinh = ds.map((m) => tinhTu(m.thanhPho));

    return {
        soViTri: ds.length,
        soTinh: new Set(tinh.filter(Boolean)).size,
        soCoGia,
        soChuaGia: ds.length - soCoGia,
        chiPhi: soCoGia > 0 ? chiPhi : null,
        theoLoai: dem(ds.map((m) => m.loaiHinh)),
        theoTinh: dem(tinh),
    };
}

/**
 * Câu cảnh báo về reach, giữ nguyên văn và có chốt canh.
 *
 * Người đi làm qua biển Chợ Bến Thành rồi qua biển Lý Tự Trọng–Pasteur cách
 * 700m bị đếm **hai lần**. Cộng reach của các vị trí gần nhau ra một con số
 * lớn hơn sự thật, và đó đúng là con số agency mang đi báo cáo cho nhãn
 * hàng.
 *
 * Hôm nay sàn chưa có **số đo nào** để cộng, nên câu này chưa đứng dưới một
 * con số. Nó ở đây để ngày số đo về thì cái bẫy đã có người canh:
 * `tests/js/ke-hoach.test.mjs` đòi chuỗi "reach thô" còn nguyên, vì xoá nó
 * là đổi nghĩa con số sẽ hiện ra chỗ này.
 */
export const CANH_BAO_REACH =
    'Lượt xem và chi phí cộng được; reach thì không. Cộng thẳng ra tổng reach thô — '
    + 'một người đi qua hai biển gần nhau bị đếm hai lần. Reach thật phải khử trùng lặp '
    + 'theo vùng, và sàn chưa làm được.';
