import type { AvailabilityCalendar } from '@/lib/api';
import { theoThang, type NgayTrong } from '@/lib/lich';

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

type Ngay = NgayTrong;

// `theoThang` chuyển sang `@/lib/lich` ở lát 3: khối gọn trên màn hình đầu
// (`LichGon`) nhóm cùng một lịch theo cùng cách. Hai bản luật là cách hai khối
// nói hai con số khác nhau về cùng một dữ liệu, và chỗ lệch sẽ không ai thấy
// vì hai khối không bao giờ hiện cạnh nhau.

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
    // ══ Lát 2: hai trạng thái này KHÔNG giống nhau ══
    //
    // Trước lát này cả hai rơi vào cùng một câu "Chưa lấy được lịch trống", và
    // người mua không phân biệt được:
    //
    //   - `lich === null` — `getScreenAvailability()` trả null khi lời gọi
    //     lỗi. Sàn KHÔNG BIẾT tình trạng chỗ. Tải lại trang có thể ra kết quả.
    //   - `days: []` — lời gọi thành công, API trả về không ngày nào trong cửa
    //     sổ. Sàn BIẾT, và câu trả lời là không có ngày nào để đặt. Tải lại
    //     trang một trăm lần cũng vậy.
    //
    // Nói "thử tải lại trang" cho trường hợp thứ hai là dẫn người mua đi làm
    // một việc vô ích thay vì liên hệ chủ vị trí.
    if (!lich) {
        return (
            <p className="lt-trong">
                <strong>Chưa lấy được lịch trống.</strong> Sàn không đọc được tình trạng chỗ của vị
                trí này lúc này. Thử tải lại trang; nếu vẫn vậy, gửi yêu cầu báo giá để hỏi trực
                tiếp media owner.
            </p>
        );
    }

    if (!lich.days?.length) {
        return (
            <p className="lt-trong">
                <strong>Không có ngày nào mở bán trong cửa sổ đang xem.</strong> Lịch đã lấy được —
                vị trí này chưa mở ngày nào để đặt, chứ không phải sàn thiếu dữ liệu. Gửi yêu cầu
                báo giá để hỏi media owner về đợt kế tiếp.
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
                            {/*
                              Template string, không `{m.thang}/{m.nam}`: React
                              chèn `<!-- -->` giữa hai text node liền kề, nên
                              dạng kia ra HTML là `10<!-- -->/<!-- -->2026`.
                              Người đọc thấy đúng, nhưng tên tháng không còn là
                              một chuỗi liền để phép kiểm tìm.
                            */}
                            <div className="month-name">{`Tháng ${m.thang}/${m.nam}`}</div>

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
