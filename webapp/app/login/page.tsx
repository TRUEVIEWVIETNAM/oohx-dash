import type { Metadata } from 'next';
import Link from 'next/link';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { LoginForm } from '@/components/AuthForms';

/**
 * `/login` — đăng nhập người mua.
 *
 * ══ `noindex` có chủ ý ══
 *
 * Trang xác thực không có gì cho công cụ tìm kiếm, và để nó trong chỉ mục chỉ
 * tạo một kết quả vô dụng cạnh tranh với trang thật. Nhưng vẫn phát đủ thẻ
 * mô tả và Open Graph: khi ai đó dán liên kết vào tin nhắn, thẻ xem trước vẫn
 * phải đúng.
 */
export const metadata: Metadata = {
    ...buildMetadata({
        title: 'Đăng nhập – OOHX',
        description: 'Đăng nhập để quản lý chiến dịch và booking trên sàn OOHX.',
        path: '/login',
    }),
    robots: { index: false, follow: true },
};

export default function LoginPage() {
    return (
        <>
            <SiteHeader />
            <OgType type="website" />

            {/*
              `.v2` — phạm vi của `webapp/app/oohx.css` (lát 4, 09/11/2026).
              Mất lớp này thì trang rơi về `frontpage.css` và **không có lỗi
              nào bắn ra** — chỉ là một trang trông như bản cũ.
            */}
            <main className="v2">
                <div className="auth-page">
                    <div className="auth-card">
                        <div className="auth-header">
                            <h1 className="auth-title">Đăng nhập</h1>
                            <p className="auth-sub">Đăng nhập để quản lý campaign và booking</p>
                        </div>

                        <LoginForm />

                        <div className="auth-footer">
                            Chưa có tài khoản? <Link href="/register">Đăng ký miễn phí</Link>
                        </div>
                    </div>
                </div>
            </main>
        </>
    );
}
