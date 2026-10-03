'use client';

import { useEffect, useState } from 'react';
import type { components } from '@api-types';

type Me = components['schemas']['Me'];

/**
 * Phần header phụ thuộc người đăng nhập, điền ở TRÌNH DUYỆT.
 *
 * ══ Vì sao không render ở máy chủ ══
 *
 * Đây là chỗ nguy hiểm nhất của cả việc dựng lại header. Trang `/explore` cache
 * 60 giây (`revalidate`). Render tên và email vào HTML ở máy chủ nghĩa là bản
 * cache đó phục vụ cho người tiếp theo — **tên và email của người A hiện ra cho
 * người B**. Một lỗi rò dữ liệu, im lặng, và chỉ phát hiện được khi có người
 * báo.
 *
 * Nên khung render dưới dạng khách (xem `SiteHeader`), và component này thay
 * phần đó sau khi tải. Trang vẫn cache được, vì phần cache không chứa gì riêng
 * của ai.
 *
 * ══ `credentials: 'include'` ══
 *
 * Bắt buộc, dù cùng tên miền: `fetch` mặc định là `same-origin` cho cookie —
 * thực ra đủ ở đây — nhưng khai rõ để không ai đổi `OOHX_PUBLIC_ORIGIN` sang
 * một host khác rồi mất phiên mà không hiểu vì sao.
 *
 * ══ 401 là câu trả lời ══
 *
 * Khách chưa đăng nhập nhận 401. Đó không phải lỗi cần báo; nó nghĩa là "giữ
 * nguyên khung khách". Không `console.error`, không thử lại.
 */
/**
 * Đăng xuất thật, qua đúng route `/logout` của Laravel.
 *
 * ══ Vì sao phải lấy cookie CSRF trước ══
 *
 * `/logout` là route web có `VerifyCsrfToken`. Bản Blade gửi `_token` trong
 * form; ở đây không có form nào, nên dùng đường Sanctum dạng SPA đã cấu hình
 * sẵn: gọi `/sanctum/csrf-cookie` để Laravel đặt cookie `XSRF-TOKEN`, rồi gửi
 * lại giá trị đó trong header `X-XSRF-TOKEN`. `VerifyCsrfToken` chấp nhận
 * header này bên cạnh `_token`.
 *
 * Cân nhắc đã bỏ: để nút này thành `<a href="/my/settings">` nhãn "Đăng xuất".
 * Nó chạy được theo nghĩa người dùng tới được chỗ đăng xuất, nhưng là một nhãn
 * nói sai việc nó làm — đúng loại lỗi audit F-15 vừa dọn khỏi trang công khai.
 * Thà làm đúng mười lăm dòng.
 */
async function dangXuat(): Promise<void> {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' });

    const token = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];

    await fetch('/logout', {
        method: 'POST',
        credentials: 'include',
        headers: {
            // Laravel mã hoá giá trị cookie theo kiểu URL, nên phải giải mã
            // trước khi gửi lại — gửi nguyên chuỗi đã mã hoá thì token không
            // khớp và trả 419.
            'X-XSRF-TOKEN': token ? decodeURIComponent(token) : '',
            Accept: 'application/json',
        },
    });

    // Tải lại thay vì tự xoá state: phiên vừa mất nên mọi thứ trên trang phụ
    // thuộc nó đều phải dựng lại, kể cả phần Laravel phục vụ.
    window.location.reload();
}

export function AuthState({ cartHref, loginHref, registerHref }: {
    cartHref: string;
    loginHref: string;
    registerHref: string;
}) {
    const [me, setMe] = useState<Me | null>(null);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        let huy = false;

        (async () => {
            try {
                const response = await fetch('/api/v2/me', {
                    credentials: 'include',
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) return; // 401 = khách, giữ nguyên khung
                const body = (await response.json()) as { data: Me };
                if (!huy) setMe(body.data);
            } catch {
                // Mạng lỗi thì vẫn là khung khách. Thanh điều hướng không phải
                // chỗ báo lỗi mạng.
            }
        })();

        return () => {
            huy = true;
        };
    }, []);

    // Đóng menu khi bấm ra ngoài — cùng hành vi bản Blade.
    useEffect(() => {
        if (!open) return;

        const dong = () => setOpen(false);
        document.addEventListener('click', dong);

        return () => document.removeEventListener('click', dong);
    }, [open]);

    if (!me) {
        return (
            <>
                {/*
                  `<a>` chứ không `next/link`: /login và /register do Laravel
                  phục vụ, không nằm trong app này. `next/link` sẽ thử điều
                  hướng phía client và hiện 404 của Next cho một trang có thật.
                */}
                <a href={loginHref} className="btn btn-s btn-sm hdr-login">
                    Đăng nhập
                </a>
                <a href={registerHref} className="btn btn-p btn-sm hdr-register">
                    Đăng ký
                </a>
            </>
        );
    }

    return (
        <>
            <a href={cartHref} className="hdr-ico hdr-cart" aria-label="Giỏ hàng">
                <svg viewBox="0 0 24 24" fill="var(--t3)" style={{ width: 20, height: 20 }}>
                    <path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96C5 16.1 6.9 18 9 18h12v-2H9.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63H19c.75 0 1.41-.41 1.75-1.03l3.58-6.49A1 1 0 0 0 23.43 5H5.21l-.94-2H1zm16 16c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z" />
                </svg>
                {me.cart_count > 0 ? <div className="hdr-cart-badge">{me.cart_count}</div> : null}
            </a>

            <div className="usr-menu" onClick={(e) => e.stopPropagation()}>
                <button
                    className="usr-trigger"
                    onClick={() => setOpen((v) => !v)}
                    aria-expanded={open}
                >
                    <div className="hdr-avatar">{me.initial}</div>
                    <svg
                        viewBox="0 0 24 24"
                        fill="var(--t4)"
                        style={{ width: 14, height: 14, flexShrink: 0 }}
                    >
                        <path d="M7 10l5 5 5-5z" />
                    </svg>
                </button>

                <div className={open ? 'usr-drop open' : 'usr-drop'}>
                    <div className="usr-drop-head">
                        <div className="usr-drop-name">{me.name}</div>
                        <div className="usr-drop-email">{me.email}</div>
                        {me.organization ? (
                            <div className="usr-drop-org">{me.organization.name}</div>
                        ) : null}
                    </div>

                    <div className="usr-drop-body">
                        {/*
                          `<a>` chứ không `next/link`: những đường này do Laravel
                          phục vụ, không nằm trong app Next. `next/link` sẽ thử
                          điều hướng phía client và không tìm thấy route.
                        */}
                        <a href="/my" className="usr-drop-item">Dashboard</a>
                        <a href="/my/campaigns" className="usr-drop-item">Campaigns</a>
                        <a href={cartHref} className="usr-drop-item">
                            Plan của tôi
                            {me.cart_count > 0 ? (
                                <span className="usr-drop-badge">{me.cart_count}</span>
                            ) : null}
                        </a>
                        <a href="/my/settings" className="usr-drop-item">Cài đặt</a>
                    </div>

                    <div className="usr-drop-foot">
                        <button
                            type="button"
                            className="usr-drop-item usr-drop-logout"
                            onClick={dangXuat}
                        >
                            Đăng xuất
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}
