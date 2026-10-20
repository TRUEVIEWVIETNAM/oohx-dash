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
 * Nút **Yêu cầu báo giá** vào đây ở lát 6, khi màn hình gửi và endpoint đã
 * có thật. Ở lát 4 chỗ này để trống có chủ ý: một nút dẫn tới trang chưa tồn
 * tại là hứa với người mua một việc sàn chưa làm được.
 *
 * "Sao chép" vẫn giữ: nó chạy mà không cần máy chủ, và có người mua chỉ muốn
 * dán danh sách vào email của chính họ thay vì đi qua sàn. Lát 7 sẽ thêm tệp
 * tải về bên cạnh nó.
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
                    <button type="button" className="btn btn-s btn-sm ds-nut-phu" onClick={() => void chep()}>
                        {daChep ? 'Đã sao chép' : 'Sao chép'}
                    </button>

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
