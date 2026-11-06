/**
 * Ngày tháng của một đơn đặt chỗ, tính đúng cách máy chủ đếm kỳ.
 *
 * ══ Vì sao là một lib chứ không vài hàm trong component ══
 *
 * Một ngày lệch ở đây không hiện ra như lỗi: `end_date` dôi một ngày làm
 * `BillablePeriodCalculator` đếm thành **hai** kỳ, và một đơn một tháng được
 * tính tiền hai tháng. Không ngoại lệ nào, không cảnh báo nào — chỉ một con
 * số lớn hơn mà người mua không có cách nào biết là sai.
 *
 * Nằm trong `PanelGia.tsx` thì nó không chạy được dưới `node --test`
 * (component React cần cả môi trường dựng cây). Ở đây thì chạy, và
 * `tests/js/nut-booking.test.mjs` chạy nó với đúng những ví dụ mà docblock
 * của `calendarMonths()` nêu.
 *
 * ══ Mọi phép tính ở UTC ══
 *
 * Ngày bắt đầu/kết thúc là ngày theo **lịch**, không phải một thời điểm.
 * `new Date('2026-11-10')` được đọc là nửa đêm UTC, nên ở một múi giờ âm mà
 * tính bằng giờ địa phương thì nó lùi sang mùng 9. Máy CI chạy ở UTC nên lỗi
 * đó không bao giờ hiện ra ở đó — cùng lý do `tests/js/gio.test.mjs` đặt
 * `TZ=Pacific/Honolulu` trước mọi thứ khác.
 */

/** `YYYY-MM-DD` của hôm nay + n ngày. */
export function ngaySau(n: number): string {
    const d = new Date();
    d.setUTCDate(d.getUTCDate() + n);

    return d.toISOString().slice(0, 10);
}

/** `YYYY-MM-DD` của `batDau` + n ngày, giữ nguyên chuỗi vào nếu nó không phải ngày. */
export function congNgay(batDau: string, soNgay: number): string {
    const d = new Date(`${batDau}T00:00:00Z`);

    if (Number.isNaN(d.getTime())) return batDau;

    d.setUTCDate(d.getUTCDate() + soNgay);

    return d.toISOString().slice(0, 10);
}

/**
 * Ngày kết thúc của `soKy` kỳ kể từ `batDau`.
 *
 * ══ Vì sao trừ một ngày ══
 *
 * `BillablePeriodCalculator::calendarMonths()` đếm mỗi kỳ là
 * `[mốc, mốc + 1 tháng - 1 ngày]`, và docblock của nó nêu thẳng ví dụ:
 * *"15/01 – 14/02 = 1 kỳ. 15/01 – 15/02 = 2 kỳ."* Gửi `end = start + n tháng`
 * tròn trĩnh là tự khai thêm một kỳ. Nhánh tuần cũng vậy: `ceil(days / 7)`
 * với `days` tính cả hai đầu mút.
 *
 * ══ Tháng ngắn ══
 *
 * `setUTCMonth` của JS tự tràn: 31/01 + 1 tháng ra 02/03 hoặc 03/03. Máy chủ
 * dùng `addMonthsNoOverflow`, tức 28/02 (29/02 năm nhuận). Nên phải kẹp về
 * ngày cuối của tháng đích trước khi lùi một ngày — không phải làm đẹp, mà là
 * để hai bên đếm ra cùng một số kỳ.
 */
export function ngayKetThuc(batDau: string, soKy: number, theoTuan: boolean): string {
    const d = new Date(`${batDau}T00:00:00Z`);

    if (Number.isNaN(d.getTime())) return batDau;

    if (theoTuan) {
        return congNgay(batDau, soKy * 7 - 1);
    }

    const ngayGoc = d.getUTCDate();

    // Về mùng 1 trước khi cộng tháng, nếu không chính `setUTCMonth` đã tràn
    // trước khi ta kịp kẹp.
    d.setUTCDate(1);
    d.setUTCMonth(d.getUTCMonth() + soKy);

    const ngayCuoiThangDich = new Date(
        Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0),
    ).getUTCDate();

    d.setUTCDate(Math.min(ngayGoc, ngayCuoiThangDich));
    d.setUTCDate(d.getUTCDate() - 1);

    return d.toISOString().slice(0, 10);
}
