import type { ScreenDetail } from '@/lib/api';
import { danhGiaHoSo, type TrangThai } from '@/lib/danh-gia-ho-so';

/**
 * Khối "hồ sơ này đủ để ra quyết định chưa?".
 *
 * ══ Khối này phục vụ hai người bằng cùng một đoạn mã ══
 *
 * Người mua biết ngay phải đòi gì, thay vì đọc hết trang rồi tự ghép các
 * khoảng trống lại. Và chủ vị trí — khi `/publisher` dựng cùng khối này — thấy
 * đúng danh sách cần bổ sung để vị trí của mình bán được.
 *
 * ══ Vì sao `dang_ngo` phải hiện riêng ══
 *
 * Điểm đếm cả trường đáng ngờ (`diem = co + dang_ngo`), vì một trường có dữ
 * liệu để đối chiếu khác hẳn một trường trống. Nhưng nếu khối chỉ in `8/18` thì
 * hồ sơ này trông giống một hồ sơ có tám trường sạch — trong khi hai trong tám
 * trường của nó tự mâu thuẫn. Con số `dang_ngo` in cạnh điểm, và mỗi trường
 * đáng ngờ mang câu nói rõ vì sao.
 *
 * ══ Kết luận không đọc điểm ══
 *
 * Màu thanh và câu kết luận đọc theo **giá và số khán giả**, không theo điểm
 * tổng: một hồ sơ 14/18 thiếu hai thứ đó vẫn không ra quyết định được, còn một
 * hồ sơ 10/18 có đủ chúng thì so sánh được với vị trí khác.
 */

const DAU: Record<TrangThai, string> = { co: '✓', dang_ngo: '!', thieu: '—' };

export function KhoiHoSo({ screen }: { screen: ScreenDetail }) {
    const dg = danhGiaHoSo(screen);
    const pct = Math.round((dg.diem / dg.tong) * 100);

    // `du` quyết định màu, và nó KHÔNG phải `pct > ngưỡng`: xem docblock.
    const du = dg.ketLuan === 'Đủ để so sánh';

    return (
        <section className="hoso" aria-labelledby="hoso-h">
            <div className="hoso-top">
                <div className="hoso-diem">
                    <strong className="hoso-diem-n">{`${dg.diem}/${dg.tong}`}</strong>
                    <span className="hoso-diem-l">trường có dữ liệu</span>
                </div>

                <div className="hoso-tx">
                    <h2 className="hoso-h" id="hoso-h">
                        {dg.ketLuan}
                    </h2>
                    <p className="hoso-vi">{dg.vi}</p>

                    <div className="hoso-bar">
                        <i
                            className={du ? 'hoso-bar-i hoso-bar-i--du' : 'hoso-bar-i'}
                            style={{ width: `${pct}%` }}
                        />
                    </div>

                    {dg.dangNgo > 0 ? (
                        <p className="hoso-ngo">
                            {`${dg.dangNgo} trường có dữ liệu nhưng cần xác minh`}
                        </p>
                    ) : null}
                </div>
            </div>

            {dg.nhom.map((n) => (
                <div className="hoso-nhom" key={n.ma}>
                    <div className="hoso-nhom-h">
                        {`${n.nhan} · ${n.truong.filter((t) => t.trangThai !== 'thieu').length}/${n.truong.length}`}
                    </div>

                    <ul className="hoso-flds">
                        {n.truong.map((t) => (
                            <li className="hoso-fld" key={t.ma}>
                                <span className={`hoso-mk hoso-mk--${t.trangThai}`} aria-hidden="true">
                                    {DAU[t.trangThai]}
                                </span>
                                <span className="hoso-fld-tx">
                                    <b>{t.nhan}</b>
                                    {t.ghiChu ? <span className="hoso-fld-gc">{t.ghiChu}</span> : null}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </section>
    );
}
