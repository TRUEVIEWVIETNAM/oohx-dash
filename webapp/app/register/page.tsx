import type { Metadata } from 'next';
import Link from 'next/link';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { RegisterForm } from '@/components/AuthForms';

/**
 * `/register` — tạo tài khoản người mua.
 *
 * `noindex` cùng lý do với `/login`, và ở đây còn một lý do nữa: trang này tạo
 * bản ghi. Một kết quả tìm kiếm dẫn thẳng vào biểu mẫu tạo tài khoản không
 * giúp ai, và nó kéo thêm lưu lượng tự động vào một đường ghi.
 */
export const metadata: Metadata = {
    ...buildMetadata({
        title: 'Đăng ký – OOHX',
        description:
            'Tạo tài khoản người mua trên sàn OOHX để tìm, so sánh và đặt chỗ quảng cáo ngoài trời.',
        path: '/register',
    }),
    robots: { index: false, follow: true },
};

export default function RegisterPage() {
    return (
        <>
            <SiteHeader />
            <OgType type="website" />

            <main>
                <div className="auth-page">
                    <div className="auth-card">
                        <div className="auth-header">
                            <h1 className="auth-title">Đăng ký</h1>
                            <p className="auth-sub">Tạo tài khoản để bắt đầu đặt chỗ quảng cáo</p>
                        </div>

                        <RegisterForm />

                        <div className="auth-footer">
                            Đã có tài khoản? <Link href="/login">Đăng nhập</Link>
                        </div>
                    </div>
                </div>
            </main>
        </>
    );
}
