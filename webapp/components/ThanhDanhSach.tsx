'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { bo, demTheoNhom, TOI_DA, xoaHet } from '@/lib/danh-sach-rut-gon';
import { dungDanhSach } from '@/lib/dung-danh-sach';

/**
 * Thanh đáy của danh sách rút gọn, và ngăn kéo xem lại (lát 4).
 *
 * ══ Nó hiện cái gì, và cố ý KHÔNG hiện cái gì ══
 *
 * Ba con số — vị trí, tỉnh thành, media owner — vì đó là ba chiều người mua
 * dùng để biết danh sách của mình đã đủ rộng chưa: mười màn hình của một
 * owner trong một quận không phải một chiến dịch.
 *
 * **Không** có nút "Yêu cầu báo giá" ở đây, dù mock đã duyệt có nó. Màn hình
 * gửi yêu cầu là lát 6 và endpoint là lát 5; dựng sẵn một nút dẫn tới trang
 * chưa tồn tại là hứa với người mua một việc sàn chưa làm được. Nó sẽ vào
 * đúng chỗ này khi lát 6 xong.
 *
 * "Sao chép" thì có, vì nó chạy được **hôm nay** mà không cần máy chủ: người
 * mua khoanh xong, dán vào email gửi thẳng cho owner. Lát 7 sẽ thay nó bằng
 * tệp tải về.
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
                    <button type="button" className="btn btn-s btn-sm" onClick={() => void chep()}>
                        {daChep ? 'Đã sao chép' : 'Sao chép'}
                    </button>

                    <button type="button" className="btn btn-s btn-sm" onClick={() => xoaHet()}>
                        Bỏ chọn
                    </button>
                </div>
            </div>

            <div className={mo ? 'ds-panel open' : 'ds-panel'} aria-hidden={!mo}>
                <div className="ds-panel-dau">
                    <span>
                        Đã chọn {dem.viTri}/{TOI_DA}
                    </span>

                    <button type="button" className="ds-panel-dong" onClick={() => setMo(false)} aria-label="Đóng">
                        ×
                    </button>
                </div>

                <div className="ds-panel-than">
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
