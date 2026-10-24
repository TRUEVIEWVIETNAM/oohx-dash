'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { bo, demTheoNhom, TOI_DA, xoaHet } from '@/lib/danh-sach-rut-gon';
import { dungDanhSach } from '@/lib/dung-danh-sach';
import { dungCsv, tenTep } from '@/lib/tep-csv';
import { taiTep } from '@/lib/tai-tep';
import { BangKeHoach } from '@/components/BangKeHoach';

/**
 * Thanh đáy của danh sách rút gọn, và ngăn kéo xem lại (lát 4).
 *
 * ══ Nó hiện cái gì, và cố ý KHÔNG hiện cái gì ══
 *
 * Ba con số — vị trí, tỉnh thành, media owner — vì đó là ba chiều người mua
 * dùng để biết danh sách của mình đã đủ rộng chưa: mười màn hình của một
 * owner trong một quận không phải một chiến dịch.
 *
 * Nút **Yêu cầu báo giá** vào đây ở lát 6, khi màn hình gửi và endpoint đã
 * có thật. Ở lát 4 chỗ này để trống có chủ ý: một nút dẫn tới trang chưa tồn
 * tại là hứa với người mua một việc sàn chưa làm được.
 *
 * ══ Hai nút phụ chuyển vào ngăn kéo ở lát 7 ══
 *
 * "Sao chép" và "Tải CSV" là hai cách lấy danh sách ra ngoài sàn, và cả hai
 * chỉ có nghĩa khi người ta đang **nhìn** danh sách. Để cả bốn nút trên
 * thanh đáy thì ở 390px nút chính bị đẩy xuống dòng, và thứ bị đẩy đi lại
 * là hành động quan trọng nhất.
 *
 * Nên thanh giữ đúng việc của nó — đếm, bỏ chọn, gửi — còn hai nút kia nằm
 * trong ngăn kéo, ngay trên danh sách chúng thao tác.
 */
export function ThanhDanhSach() {
    const ds = dungDanhSach();
    const [mo, setMo] = useState(false);
    const [daChep, setDaChep] = useState(false);

    const dem = demTheoNhom(ds);
    const co = ds.length > 0;

    /*
     * Báo cho phần còn lại của trang biết thanh đáy đang chiếm chỗ.
     *
     * Nút nổi "Bộ lọc" của `/products` cũng `position: fixed` ở đáy giữa, nên
     * thiếu dòng này thì hai thứ chồng lên nhau ở mọi bề ngang dưới 1024px.
     * Một class trên `body` để CSS tự lo, thay vì truyền trạng thái qua ba
     * component không liên quan.
     */
    useEffect(() => {
        document.body.classList.toggle('co-thanh-ds', co);

        return () => document.body.classList.remove('co-thanh-ds');
    }, [co]);

    useEffect(() => {
        if (!daChep) return;

        const hen = window.setTimeout(() => setDaChep(false), 2000);

        return () => window.clearTimeout(hen);
    }, [daChep]);

    if (!co) return null;

    function tai() {
        taiTep(dungCsv(ds, window.location.origin), tenTep());
    }

    async function chep() {
        const van = ds
            .map((m, i) => `${i + 1}. ${m.ten}${m.thanhPho ? ' — ' + m.thanhPho : ''}${m.owner ? ' — ' + m.owner : ''}`)
            .join('\n');

        try {
            await navigator.clipboard.writeText(van);
            setDaChep(true);
        } catch {
            // Trình duyệt từ chối quyền clipboard (hay gặp trong khung
            // nhúng). Không có gì để chữa ở đây — ngăn kéo vẫn hiện đủ danh
            // sách để người dùng tự bôi đen.
            setMo(true);
        }
    }

    return (
        <>
            <div className="ds-thanh" role="region" aria-label="Danh sách đã chọn">
                <button
                    type="button"
                    className="ds-dem"
                    onClick={() => setMo(!mo)}
                    aria-expanded={mo}
                >
                    <strong>{dem.viTri}</strong> vị trí
                    <span className="ds-dem-phu">
                        {dem.tinh} tỉnh thành · {dem.owner} media owner
                    </span>
                </button>

                <div className="ds-nut">
                    <button type="button" className="btn btn-s btn-sm ds-nut-phu" onClick={() => xoaHet()}>
                        Bỏ chọn
                    </button>

                    {/*
                      Hành động chính của cả thanh, nên nó là `btn-p` và đứng
                      cuối — chỗ ngón cái với tới trước trên điện thoại.
                    */}
                    <Link href="/explore/yeu-cau-bao-gia" className="btn btn-p btn-sm">
                        Yêu cầu báo giá
                    </Link>
                </div>
            </div>

            <div className={mo ? 'ds-panel open' : 'ds-panel'} aria-hidden={!mo}>
                <div className="ds-panel-dau">
                    {/*
                      Template string, không `{dem.viTri}/{TOI_DA}`: React chèn
                      `<!-- -->` giữa hai text node liền kề, nên dạng kia ra
                      HTML là `3<!-- -->/<!-- -->50`. Người đọc thấy đúng,
                      nhưng con số không còn là một chuỗi liền để phép kiểm
                      tìm — và đây là một con số nói với người mua họ còn chọn
                      được bao nhiêu vị trí nữa.
                    */}
                    <span>{`Đã chọn ${dem.viTri}/${TOI_DA}`}</span>

                    <div className="ds-panel-nut">
                        <button type="button" className="btn btn-s btn-xs" onClick={() => void chep()}>
                            {daChep ? 'Đã chép' : 'Sao chép'}
                        </button>

                        <button type="button" className="btn btn-s btn-xs" onClick={() => tai()}>
                            Tải CSV
                        </button>
                    </div>

                    <button type="button" className="ds-panel-dong" onClick={() => setMo(false)} aria-label="Đóng">
                        ×
                    </button>
                </div>

                <div className="ds-panel-than">
                    {/*
                      Bảng tổng kế hoạch (lát 6, 22/10/2026) đứng TRÊN danh
                      sách, trong cùng vùng cuộn.

                      Cùng một ngăn kéo chứ không phải panel thứ ba: ngăn này
                      đã có bộ đếm, nút mở/đóng và danh sách bỏ được từng
                      dòng — ba trong năm thứ plan liệt kê cho bảng tổng. Và
                      nó hiện ở cả hai kiểu xem, còn chỗ `left:428px` mà plan
                      vẽ thì chỉ tồn tại trong kiểu bản đồ.
                    */}
                    <BangKeHoach ds={ds} />

                    {ds.map((m) => (
                        <div className="ds-dong" key={m.slug}>
                            <Link href={`/explore/${m.slug}`} className="ds-dong-ten">
                                {m.ten}
                            </Link>

                            <span className="ds-dong-phu">
                                {[m.thanhPho, m.owner].filter(Boolean).join(' · ')}
                            </span>

                            <button
                                type="button"
                                className="ds-dong-bo"
                                onClick={() => bo(m.slug)}
                                aria-label={`Bỏ ${m.ten} khỏi danh sách`}
                            >
                                ×
                            </button>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
