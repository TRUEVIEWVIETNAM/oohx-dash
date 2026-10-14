import type { AvailabilityCalendar } from '@/lib/api';

/**
 * Tab "Lịch trống" — lưới ngày theo tháng, ba màu.
 *
 * Dựng lại khối `avail-grid` của `frontpage/detail.blade.php`, nhưng đọc dữ
 * liệu từ `GET /api/v2/screens/{slug}/availability` thay vì tự cộng
 * `BookingLine` trong view.
 *
 * ══ Vì sao điều đó không chỉ là "đổi nguồn" ══
 *
 * Lịch của bản Blade cộng `share_of_voice_pct` của các `BookingLine` có
 * `status` trong `['approved','active']` và **không biết gì về
 * `InventoryHold`** — tức suất người khác đang giữ trong giỏ. Nên nó sơn xanh
 * những ngày đang có người thanh toán, và người thứ hai chỉ biết mình mất suất
 * ở bước cuối.
 *
 * Endpoint mới đi qua `InventoryHoldService` — nơi duy nhất biết công thức sức
 * chứa — nên hai nơi không thể trôi khỏi nhau.
 *
 * ══ Màu là việc của chỗ này, không phải của API ══
 *
 * API trả `remaining_sov_pct` cho từng ngày. Ngưỡng ba màu nằm ở đây: đầy
 * (`0`), còn một phần (thiếu so với trần), còn nguyên. Một client khác muốn
 * vẽ năm bậc thì không cần endpoint mới.
 *
 * ══ Ngày quá khứ trong tháng này ══
 *
 * API bắt đầu từ **hôm nay**, nên lưới tháng đầu có những ô trống ở đầu tháng.
 * `cd-em` là đúng class bản Blade dùng cho cả ô đệm và ngày đã qua.
 */

const DAU_TUAN = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

type Ngay = { date: string; remaining_sov_pct: number };

/** Nhóm các ngày theo `YYYY-MM`, giữ thứ tự API trả về. */
function theoThang(days: Ngay[]): { khoa: string; nam: number; thang: number; ngay: Ngay[] }[] {
    const nhom = new Map<string, Ngay[]>();

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

/**
 * Thứ của ngày 1 trong tháng, với Thứ Hai = 0.
 *
 * Tính từ chuỗi `YYYY-MM-DD` qua `Date.UTC`, **không** qua `new Date(chuỗi)`
 * ở múi giờ địa phương: máy chủ render và trình duyệt có thể khác múi giờ, và
 * lệch một ngày làm cả lưới trượt một ô.
 */
function thuDauThang(nam: number, thang: number): number {
    const dow = new Date(Date.UTC(nam, thang - 1, 1)).getUTCDay(); // 0 = CN

    return (dow + 6) % 7;
}

export function LichTrong({
    lich,
    sovMax,
}: {
    lich: AvailabilityCalendar | null;
    sovMax: number;
}) {
    if (!lich || !lich.days?.length) {
        return (
            <p style={{ fontSize: 14, color: 'var(--t3)' }}>
                Chưa lấy được lịch trống. Thử tải lại trang, hoặc liên hệ media owner để hỏi tình
                trạng chỗ.
            </p>
        );
    }

    const tran = lich.sov_max_pct || sovMax || 100;
    const thangs = theoThang(lich.days as Ngay[]);

    return (
        <>
            <p style={{ fontSize: 14, color: 'var(--t3)', marginBottom: 20 }}>
                Xanh = còn trống · Cam = đã đặt một phần · Đỏ = đã đặt hết. Số đã trừ cả suất người
                khác đang giữ trong giỏ.
            </p>

            <div className="avail-grid">
                {thangs.map((m) => {
                    const dem = thuDauThang(m.nam, m.thang);
                    const ngayTrongThang = new Date(Date.UTC(m.nam, m.thang, 0)).getUTCDate();
                    const theoSo = new Map(m.ngay.map((d) => [Number(d.date.slice(8, 10)), d]));

                    return (
                        <div className="month-card" key={m.khoa}>
                            <div className="month-name">
                                Tháng {m.thang}/{m.nam}
                            </div>

                            <div className="cal-grid">
                                {DAU_TUAN.map((d) => (
                                    <div className="cal-dh" key={d}>
                                        {d}
                                    </div>
                                ))}

                                {Array.from({ length: dem }, (_, i) => (
                                    <div className="cal-day cd-em" key={`dem-${i}`} />
                                ))}

                                {Array.from({ length: ngayTrongThang }, (_, i) => {
                                    const so = i + 1;
                                    const d = theoSo.get(so);

                                    // Không có dữ liệu cho ngày này nghĩa là nó
                                    // nằm trước hôm nay — API bắt đầu từ hôm
                                    // nay. Vẽ mờ, không vẽ xanh: một ngày đã
                                    // qua không phải một ngày còn trống.
                                    if (!d) {
                                        return <div className="cal-day cd-em" key={so} />;
                                    }

                                    const con = d.remaining_sov_pct;
                                    const lop = con <= 0 ? 'cd-bk' : con < tran ? 'cd-pt' : 'cd-ok';

                                    return (
                                        <div
                                            className={`cal-day ${lop}`}
                                            key={so}
                                            title={`${d.date}: còn ${con}% / ${tran}%`}
                                        >
                                            {so}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="cal-legend">
                <div className="cl-i">
                    <div className="cl-d" style={{ background: 'rgba(52,199,89,.4)' }} />
                    Còn trống
                </div>
                <div className="cl-i">
                    <div className="cl-d" style={{ background: 'rgba(255,159,10,.4)' }} />
                    Đã đặt một phần
                </div>
                <div className="cl-i">
                    <div className="cl-d" style={{ background: 'rgba(255,59,48,.3)' }} />
                    Đã đặt hết
                </div>
            </div>
        </>
    );
}
