'use client';

import { useEffect, useState } from 'react';
import { AppLink } from './AppLink';
import { usePhien } from '@/lib/phien';
import { LOGO_DATA_URI } from '@/lib/logo';
import type { NavKey } from './SiteHeader';

/**
 * Nút hamburger và menu trượt — điều hướng cho mọi màn hình dưới 1024px.
 *
 * ══ Vì sao tệp này tồn tại: thanh đầu không có menu nào dưới 1024px ══
 *
 * Báo cáo từ chủ dự án ngày 18/10/2026: "Header menu đang bị mất". Đo lại
 * thì không phải mất — nó **chưa bao giờ được dựng**. Ba luật trong
 * `frontpage.css` quyết định thanh đầu trông ra sao:
 *
 *     .hdr-nav{display:none}                              dòng 117
 *     @media(min-width:1024px){.hdr-nav{display:flex}}     dòng 118
 *     .hdr-login,.hdr-register{display:none}               dòng 158
 *     @media(min-width:768px){…{display:inline-flex}}      dòng 159
 *
 * Nên trên điện thoại dưới 768px, thanh đầu của mọi trang công khai chỉ còn
 * **một cái logo**: không điều hướng, không đăng nhập, không đăng ký. Từ
 * 768 đến 1023px có hai nút đăng nhập nhưng vẫn không có điều hướng.
 *
 * `frontpage.css` ĐÃ mang đủ CSS cho hamburger (`.hdr-hamburger`, dòng 131)
 * và menu trượt (`.slide-menu*`, dòng 137–152), và
 * `frontpage/partials/header.blade.php` đã dựng cả hai từ lâu. Bản Next chép
 * `<nav>` sang rồi dừng ở đó — docblock của `SiteHeader` thậm chí còn liệt
 * kê "nút hamburger" trong những thứ nó render.
 *
 * ══ Chép từ bản Blade, không thiết kế lại ══
 *
 * Cùng bộ class, cùng bộ icon, cùng hành vi: mở thì khoá cuộn trang, bấm nền
 * mờ hoặc nút đóng thì tắt. Thêm hai thứ bản Blade không có, vì cả hai là
 * lỗi chứ không phải lựa chọn:
 *
 *  · `Escape` đóng menu. Một lớp phủ toàn màn hình không đóng được bằng bàn
 *    phím là một cái bẫy.
 *  · Menu đóng khi điều hướng xong. Bản Blade tải lại cả trang nên lớp phủ
 *    biến mất theo; ở Next điều hướng là phía client, nên lớp phủ sẽ ở lại
 *    che trang mới.
 *
 * ══ Chân menu mang nút đăng nhập, và đó không phải trùng lặp ══
 *
 * `.hdr-acts` đã có hai nút ấy, nhưng chúng `display:none` dưới 768px — tức
 * đúng dải mà menu này phục vụ. Trạng thái phiên đọc qua `usePhien()` dùng
 * chung với `AuthState`, nên hai chỗ không bao giờ hiện hai trạng thái khác
 * nhau và chỉ có MỘT lời gọi `/api/v2/me` cho cả trang.
 */

const MUC: { ma: NavKey; duong: string; nhan: string; icon: string }[] = [
    {
        ma: 'home',
        duong: '/',
        nhan: 'Trang chủ',
        icon: 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
    },
    {
        ma: 'explore',
        duong: '/explore',
        nhan: 'Khám phá',
        icon: 'M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z',
    },
    {
        ma: 'map',
        duong: '/map',
        nhan: 'Bản đồ',
        icon: 'M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z',
    },
    {
        ma: 'products',
        duong: '/products',
        nhan: 'Gói chiến dịch',
        icon: 'M20 6h-4V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2H4a2 2 0 00-2 2v11a2 2 0 002 2h16a2 2 0 002-2V8a2 2 0 00-2-2zm-10-2h4v2h-4V4z',
    },
    {
        ma: 'owners',
        duong: '/owners',
        nhan: 'Media owner',
        icon: 'M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z',
    },
    {
        ma: 'agency',
        duong: '/agency',
        nhan: 'Đại lý',
        icon: 'M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z',
    },
];

export function MenuTruot({ active = '' }: { active?: NavKey }) {
    const [mo, setMo] = useState(false);
    const me = usePhien();

    // Khoá cuộn trang khi menu mở — cùng hành vi bản Blade. Dọn trong hàm
    // trả về: nếu component bị gỡ lúc đang mở, `overflow: hidden` sẽ kẹt
    // trên `<body>` và cả trang không cuộn được nữa.
    useEffect(() => {
        if (!mo) return;

        const cu = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        const phim = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setMo(false);
        };

        document.addEventListener('keydown', phim);

        return () => {
            document.body.style.overflow = cu;
            document.removeEventListener('keydown', phim);
        };
    }, [mo]);

    return (
        <>
            <button
                className="hdr-hamburger"
                type="button"
                aria-label="Mở menu"
                aria-expanded={mo}
                aria-controls="slide-menu"
                onClick={() => setMo(true)}
            >
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z" />
                </svg>
            </button>

            <div className={mo ? 'slide-menu open' : 'slide-menu'} id="slide-menu">
                <div className="slide-menu-head">
                    <AppLink href="/" className="hdr-logo" onNavigate={() => setMo(false)}>
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={LOGO_DATA_URI} alt="OOHX" width={128} height={32} />
                    </AppLink>

                    <button
                        className="slide-menu-close"
                        type="button"
                        aria-label="Đóng menu"
                        onClick={() => setMo(false)}
                    >
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z" />
                        </svg>
                    </button>
                </div>

                <nav className="slide-menu-nav">
                    {MUC.map((m) => (
                        <AppLink
                            key={m.duong}
                            href={m.duong}
                            className={active === m.ma ? 'on' : undefined}
                            onNavigate={() => setMo(false)}
                        >
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d={m.icon} />
                            </svg>
                            {m.nhan}
                        </AppLink>
                    ))}
                </nav>

                <div className="slide-menu-foot">
                    {me ? (
                        <>
                            <div className="slide-menu-user">
                                <div className="hdr-avatar">{me.initial}</div>
                                <div style={{ minWidth: 0 }}>
                                    <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--t1)' }}>
                                        {me.name}
                                    </div>
                                    <div style={{ fontSize: 12, color: 'var(--t4)' }}>{me.email}</div>
                                </div>
                            </div>
                            <a
                                href="/my"
                                className="btn btn-s"
                                style={{ width: '100%', justifyContent: 'center', borderRadius: 10 }}
                            >
                                Dashboard
                            </a>
                        </>
                    ) : (
                        <>
                            {/*
                              `<a>` chứ không `next/link`: `/login` và
                              `/register` do Laravel phục vụ. Cùng lý do đã ghi
                              ở `AuthState`.
                            */}
                            <a
                                href="/register"
                                className="btn btn-p"
                                style={{ width: '100%', justifyContent: 'center', borderRadius: 10 }}
                            >
                                Đăng ký
                            </a>
                            <a
                                href="/login"
                                className="btn btn-s"
                                style={{ width: '100%', justifyContent: 'center', borderRadius: 10 }}
                            >
                                Đăng nhập
                            </a>
                        </>
                    )}
                </div>
            </div>

            <div
                className={mo ? 'slide-menu-backdrop open' : 'slide-menu-backdrop'}
                onClick={() => setMo(false)}
                aria-hidden="true"
            />
        </>
    );
}
