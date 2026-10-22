'use client';

import { soVN } from '@/lib/ban-do';
import { gonTien, nhanDonVi, type TomTat } from '@/lib/khung-nhin';

/**
 * Dải tổng quan của khung nhìn — bốn ô ở đầu panel `/explore?view=map`.
 *
 * Lát 4 của `docs/PLAN-GOP-EXPLORE-BAN-DO.md`.
 *
 * ══ Tính từ tập pin đang hiện, không từ một lời gọi thứ hai ══
 *
 * Mọi con số ở đây đến từ cùng một phản hồi `GET /api/v2/screens/map` đã vẽ
 * ra những cái pin người dùng đang nhìn. Gọi một endpoint thống kê riêng là
 * mời hai con số lệch nhau — và chúng sẽ lệch, vì khung nhìn đổi giữa hai
 * lời gọi. Badge "n vị trí trên bản đồ" cũ đã lệch đúng như thế: nó đếm cụm
 * (81) trong khi danh sách đếm màn hình (83).
 *
 * ══ Ô thứ tư KHÔNG in số, và đó là quyết định đáng cãi ══
 *
 * Plan viết: *"Lượt xem/ngày — để chữ xám, tổng của những số ước tính"*, với
 * lý do 83/104 màn hình mang cùng một giá trị 25.000.
 *
 * Khảo lại nguồn thì nó còn kém hơn một ước tính: `daily_footfall` đến từ
 * `app/Console/Commands/SeedInsightsDefaults.php`, một bảng hằng số viết tay
 * theo loại điểm đặt (25.000 cho loại này, 50.000 cho loại kia). Cộng 83 con
 * số seed lại được `2.075.000 lượt/ngày` — một con số trông chính xác tới
 * hàng nghìn, đứng trên một trang bán hàng, và không có một phép đo nào đằng
 * sau. Chữ xám không cứu được điều đó; nó chỉ làm con số bớt to.
 *
 * Nên ô vẫn đứng đúng chỗ plan vẽ, nhưng nó nói thật: chưa có số đo. Ngày
 * OOHX Data Engine đổ `estimated_daily_impressions` vào cột thật thì ô này
 * có số, và không phải đổi bố cục nào.
 */
export function DaiTongQuan({ tomTat }: { tomTat: TomTat }) {
    const { tong, daCat, daBaoGia, giaMin, giaMax, donVi, tienTe } = tomTat;

    const khoangGia =
        giaMin === null || giaMax === null
            ? null
            : giaMin === giaMax
              ? gonTien(giaMin, tienTe)
              : `${gonTien(giaMin, tienTe)}–${gonTien(giaMax, tienTe)}`;

    return (
        <div className="ex-tq">
            <div className="ex-tq-hang">
                <O nhan="Vị trí" gt={soVN(tong)} />
                <O nhan="Đã báo giá" gt={soVN(daBaoGia)} />

                {/*
                  Đơn vị đi kèm khoảng giá, luôn luôn. `price.unit` ra khỏi
                  API dưới ba dạng (`màn hình/tháng`, `màn hình/tuần`, `CPM`),
                  nên một khoảng giá không nhãn là một khoảng giá không đọc
                  được — xem `tomTatKhung` về việc vì sao chỉ lấy một nhóm.
                */}
                <O
                  nhan={khoangGia ? nhanDonVi(donVi) : 'Khoảng giá'}
                  gt={khoangGia ?? '—'}
                  mo={!khoangGia}
                />

                {/*
                  Nhãn `chưa đo`, không `chưa có số đo`: ô này là ô hẹp nhất
                  của dải (0,7fr) vì nó chỉ chứa một gạch ngang, và nhãn dài
                  hơn thì chính nó bị cắt — một ô nói "chưa có số đ…" còn tệ
                  hơn một ô trống. `title` giữ câu đầy đủ cho ai rê chuột.
                */}
                <O nhan="Lượt xem/ngày" gt="—" mo chuThich="chưa đo" />
            </div>

            {daCat ? (
                // Nói ra khi API đã cắt ở trần 500. Im lặng ở đây là để người
                // dùng tin rằng vùng này chỉ có chừng ấy màn hình.
                <p className="ex-tq-cat">
                    Đã cắt ở 500 vị trí — thu nhỏ vùng xem để thấy đủ
                </p>
            ) : null}
        </div>
    );
}

function O({
    nhan,
    gt,
    mo = false,
    chuThich,
}: {
    nhan: string;
    gt: string;
    /** Ô chưa có dữ liệu thật: nhạt đi, không biến mất. */
    mo?: boolean;
    chuThich?: string;
}) {
    return (
        <div className={mo ? 'ex-tq-o ex-tq-o-mo' : 'ex-tq-o'} title={`${nhan}: ${gt}`}>
            <span className="ex-tq-so">{gt}</span>
            <span className="ex-tq-nhan">{chuThich ?? nhan}</span>
        </div>
    );
}
