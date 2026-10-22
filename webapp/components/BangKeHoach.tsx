'use client';

import { useMemo, useState } from 'react';
import type { MucDaChon } from '@/lib/danh-sach-rut-gon';
import { CANH_BAO_REACH, SO_NGAY_MAC_DINH, soKy, tomTatKeHoach } from '@/lib/ke-hoach';
import { gonTien } from '@/lib/khung-nhin';

/**
 * Bảng tổng kế hoạch — lát 6 của `docs/PLAN-GOP-EXPLORE-BAN-DO.md`.
 *
 * ══ Nó nằm trong ngăn kéo danh sách, không phải một panel thứ ba ══
 *
 * Plan vẽ bảng này mở ở chỗ thẻ chi tiết (`left:428px`). Đổi chỗ, và lý do
 * đáng ghi: ngăn kéo `.ds-panel` **đã có sẵn** bộ đếm, nút mở/đóng, và danh
 * sách bỏ được từng dòng — tức ba trong năm thứ plan liệt kê cho bảng này.
 * Dựng một panel thứ ba là chép lại cả ba, và từ hôm sau chúng trôi khỏi
 * nhau.
 *
 * Quan trọng hơn: `.ds-panel` hiện ở **cả hai** kiểu xem, còn chỗ
 * `left:428px` chỉ tồn tại trong kiểu bản đồ. Plan muốn bộ đếm nhìn thấy cả
 * khi đang ở kiểu Lưới; đặt bảng vào đây thì cả bảng cũng thế.
 *
 * Bản đồ vẫn thấy được: từ 768px `.ds-panel` là một thẻ 420px nổi bên phải,
 * dưới 768px nó là ngăn kéo đáy cao 56dvh.
 *
 * ══ Ba ô plan vẽ mà dữ liệu hôm nay không có ══
 *
 * Đo trên production 22/10/2026, cả 104 bản ghi: `location.district` **0/104**
 * (không đếm được quận/huyện), `pricing.floor_cpm` **0/104** và không có
 * trường lượt xem nào (không có CPM nào để gộp, và không có mẫu số cho nó),
 * `pricing.io_rate` 19/104.
 *
 * Nên **tỉnh thành** thay quận/huyện — tính được cho cả 104, và với một
 * chiến dịch toàn quốc thì đó mới là đơn vị phủ sóng. **Chi phí** thay CPM
 * gộp. Ô lượt xem giữ chỗ và nói thật là chưa có số đo, cùng cách dải tổng
 * quan của lát 4 làm.
 *
 * ══ Con số ở đây là ước tính ══
 *
 * CLAUDE.md mục 5: tiền do máy chủ tính. Bảng này không đặt chỗ và không gửi
 * gì đi — nó cộng những con số người mua đã nhìn thấy trên từng thẻ. Dòng
 * dưới bảng nói ra điều đó, và nói cả bao nhiêu vị trí chưa có giá để cộng.
 */

function O({
    nhan,
    nhanNgan,
    gt,
    mo = false,
}: {
    nhan: string;
    /** Chữ in ra khi `nhan` không vừa ô ~90px; `title` vẫn giữ `nhan`. */
    nhanNgan?: string;
    gt: string;
    mo?: boolean;
}) {
    return (
        <div className={mo ? 'kh-o kh-o-mo' : 'kh-o'} title={`${nhan}: ${gt}`}>
            <span className="kh-o-so">{gt}</span>
            <span className="kh-o-nhan">{nhanNgan ?? nhan}</span>
        </div>
    );
}

/** Một thanh phân bổ: tên, số, và một vạch dài theo tỉ lệ. */
function Dai({ ten, so, tren }: { ten: string; so: number; tren: number }) {
    return (
        <div className="kh-dai">
            <span className="kh-dai-ten">{ten}</span>
            <span className="kh-dai-vach">
                <span className="kh-dai-day" style={{ width: `${Math.round((so / tren) * 100)}%` }} />
            </span>
            <span className="kh-dai-so">{so}</span>
        </div>
    );
}

export function BangKeHoach({ ds }: { ds: MucDaChon[] }) {
    const [soNgay, setSoNgay] = useState(SO_NGAY_MAC_DINH);

    /**
     * Ngân sách trần là **chuỗi**, không phải số.
     *
     * Ô trống phải khác ô ghi 0: trống nghĩa là chưa đặt trần, còn 0 nghĩa là
     * trần bằng không. Giữ state là số thì không phân biệt được, và ô nhập
     * nhảy về 0 ngay khi người dùng xoá hết để gõ lại.
     */
    const [tran, setTran] = useState('');

    const t = useMemo(() => tomTatKeHoach(ds, soNgay), [ds, soNgay]);

    const soTran = tran.trim() === '' ? null : Math.max(0, Number(tran.replace(/\D/g, '')) || 0);
    const vuotTran = soTran !== null && t.chiPhi !== null && t.chiPhi > soTran;

    const tongLoai = t.theoLoai.reduce((a, b) => a + b.so, 0);
    const tongTinh = t.theoTinh.reduce((a, b) => a + b.so, 0);

    if (t.soViTri === 0) return null;

    return (
        <div className="kh">
            <div className="kh-hang">
                <O nhan="Vị trí" gt={String(t.soViTri)} />
                <O nhan="Tỉnh thành" gt={String(t.soTinh)} />
                {/*
                  Nhãn `Chi phí`, không `Chi phí 45 ngày`: ô rộng ~90px và
                  nhãn dài ra màn hình thành `Chi phí 45 ng…` — mất đúng con
                  số làm nó có nghĩa. Số ngày nằm ngay dưới, trong ô nhập có
                  nhãn, và lặp lại trong dòng chú thích. `title` giữ câu đầy
                  đủ cho ai rê chuột.
                */}
                <O
                    nhan={`Chi phí ${soNgay} ngày`}
                    nhanNgan="Chi phí"
                    gt={t.chiPhi === null ? '—' : gonTien(t.chiPhi, 'VND')}
                    mo={t.chiPhi === null}
                />
                {/*
                  Ô thứ tư giữ đúng chỗ plan vẽ cho "CPM gộp", và nói thật.
                  CPM gộp = chi phí ÷ (lượt xem ÷ 1.000); đo trên production
                  thì `floor_cpm` có 0/104 bản ghi và không có trường lượt xem
                  nào — tức không có cả tử số lẫn mẫu số.
                */}
                <O nhan="CPM gộp" gt="—" mo />
            </div>

            <div className="kh-nhap">
                <label className="kh-o-nhap">
                    <span className="kh-lbl">Số ngày chạy</span>
                    <input
                        type="number"
                        min={1}
                        max={365}
                        className="kh-inp"
                        value={soNgay}
                        onChange={(e) => setSoNgay(Math.max(1, Math.min(365, Number(e.target.value) || 1)))}
                    />
                </label>

                <label className="kh-o-nhap">
                    <span className="kh-lbl">Ngân sách trần (₫)</span>
                    <input
                        type="text"
                        inputMode="numeric"
                        className="kh-inp"
                        placeholder="chưa đặt"
                        value={tran}
                        onChange={(e) => setTran(e.target.value)}
                    />
                </label>
            </div>

            {/*
              Ba dòng nói ra giới hạn của con số bên trên. Chúng không phải
              chú thích lịch sự: với 85/104 vị trí chưa báo giá trên kho thật,
              một tổng không kèm dòng đầu tiên là một tổng nói dối.
            */}
            <div className="kh-ghi">
                {t.soChuaGia > 0 ? (
                    <p className="kh-canh">
                        <strong>{t.soChuaGia}</strong>/{t.soViTri} vị trí chưa báo giá — chi phí
                        trên chỉ tính {t.soCoGia} vị trí có giá.
                    </p>
                ) : null}

                {vuotTran ? (
                    <p className="kh-canh kh-canh-do">
                        Vượt trần {gonTien((t.chiPhi ?? 0) - (soTran ?? 0), 'VND')}.
                    </p>
                ) : null}

                {soTran !== null && !vuotTran && t.chiPhi !== null ? (
                    <p className="kh-canh kh-canh-xanh">
                        Còn {gonTien(soTran - t.chiPhi, 'VND')} trong trần.
                    </p>
                ) : null}

                <p className="kh-mo">
                    Ước tính, chưa gồm VAT. Giá thuê làm tròn lên theo kỳ —{' '}
                    {soNgay} ngày là {soKy(soNgay)} tháng. Số tiền chính thức do hệ thống tính khi
                    gửi yêu cầu báo giá.
                </p>

                <p className="kh-mo">{CANH_BAO_REACH}</p>
            </div>

            {t.theoLoai.length > 1 ? (
                <div className="kh-nhom">
                    <div className="kh-nhom-ten">Theo loại hình</div>
                    {t.theoLoai.map((d) => (
                        <Dai key={d.ten} ten={d.ten} so={d.so} tren={tongLoai} />
                    ))}
                </div>
            ) : null}

            {t.theoTinh.length > 1 ? (
                <div className="kh-nhom">
                    <div className="kh-nhom-ten">Theo tỉnh thành</div>
                    {/*
                      Sáu dòng là đủ để thấy hình dạng của một kế hoạch; dài
                      hơn thì bảng đẩy danh sách bỏ-từng-dòng xuống dưới nếp
                      gấp của một ngăn kéo cao 56dvh.
                    */}
                    {t.theoTinh.slice(0, 6).map((d) => (
                        <Dai key={d.ten} ten={d.ten} so={d.so} tren={tongTinh} />
                    ))}

                    {t.theoTinh.length > 6 ? (
                        <div className="kh-mo">và {t.theoTinh.length - 6} tỉnh thành khác</div>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
