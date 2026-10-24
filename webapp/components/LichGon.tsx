import type { AvailabilityCalendar } from '@/lib/api';
import { ngayGon, tomTatLich, type NgayTrong } from '@/lib/lich';

/**
 * Lịch trống, bản gọn — nằm trên màn hình đầu, không trong tab.
 *
 * ══ Vì sao có khối này ══
 *
 * Lịch trống đang ở tab thứ ba. Một người mua sàng mười vị trí sẽ không mở ba
 * tab cho mỗi vị trí; "đặt được từ khi nào" là câu họ hỏi **trước** khi quan
 * tâm tới giá, và nó phải trả lời được mà không cần một cú bấm.
 *
 * ══ Tab vẫn giữ ══
 *
 * Khối này nói *có chỗ hay không, từ ngày nào*. Tab nói *từng ngày còn bao
 * nhiêu phần trăm SOV* — thông tin một người đã quyết định mua cần để chọn
 * ngày, và không nhồi được vào bốn ô gọn. Bỏ tab là mất nó.
 *
 * ══ Không gọi API lần thứ hai ══
 *
 * Khối này nhận `lich` qua prop, cùng một đối tượng mà tab nhận. Trang gọi
 * `getScreenAvailability()` đúng một lần. Nếu khối tự gọi thì mỗi lần render
 * trang sẽ là hai lượt vào một endpoint có giới hạn tần suất, để hiện hai cách
 * nhìn của **cùng một** dữ liệu.
 */
export function LichGon({ lich }: { lich: AvailabilityCalendar | null }) {
    // Hai trạng thái rỗng dùng lại đúng cách phân biệt của lát 2, nhưng ngắn
    // hơn vì khối này đứng giữa màn hình đầu: `null` là sàn KHÔNG BIẾT, mảng
    // rỗng là sàn BIẾT không có ngày nào mở bán.
    if (!lich) {
        return (
            <div className="lg lg--trong">
                <span className="lg-nhan">Lịch trống</span>
                <span className="lg-phu">Chưa lấy được — xem tab Lịch trống</span>
            </div>
        );
    }

    const tom = tomTatLich(lich.days as NgayTrong[] | null);

    if (tom.thang.length === 0 || tom.ngaySom === null) {
        return (
            <div className="lg lg--trong">
                <span className="lg-nhan">Lịch trống</span>
                <span className="lg-phu">
                    {tom.thang.length === 0
                        ? 'Chưa mở ngày nào để đặt'
                        : 'Đã đặt hết trong cửa sổ đang xem'}
                </span>
            </div>
        );
    }

    return (
        <div className="lg">
            <div className="lg-chinh">
                <span className="lg-nhan">Đặt được từ</span>
                <strong className="lg-ngay">{ngayGon(tom.ngaySom)}</strong>
            </div>

            <div className="lg-thangs">
                {tom.thang.map((m) => (
                    <div className={m.trong > 0 ? 'lg-thang' : 'lg-thang lg-thang--het'} key={m.khoa}>
                        {/*
                          Ghép bằng template string, KHÔNG `Th{m.thang}`.

                          React chèn `<!-- -->` giữa hai text node liền kề, nên
                          `Th{m.thang}` ra HTML là `Th<!-- -->10` và
                          `{m.trong}/{m.tong}` ra `8<!-- -->/<!-- -->8`. Câu
                          vẫn đọc đúng trên màn hình, nhưng không còn là một
                          chuỗi liền để phép kiểm tìm — nên khối này có thể in
                          `Th` rỗng hoặc một dấu gạch không có số nào mà chốt
                          vẫn xanh.
                          Cùng chỗ sai đã gặp ở `/explore/yeu-cau-bao-gia`.
                        */}
                        <span className="lg-thang-t">{`Th${m.thang}`}</span>
                        <span className="lg-thang-s">{`${m.trong}/${m.tong}`}</span>
                    </div>
                ))}
            </div>

            <span className="lg-phu">
                ngày còn chỗ · chi tiết từng ngày ở tab <strong>Lịch trống</strong>
            </span>
        </div>
    );
}
