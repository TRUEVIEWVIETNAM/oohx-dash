import type { Metadata } from 'next';
import Link from 'next/link';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { ReflectionForm } from '@/components/ReflectionForm';

/**
 * `/phan-anh-to-chuc-xa-hoi` — tiếp nhận phản ánh của tổ chức xã hội.
 *
 * Trang là Server Component; chỉ biểu mẫu là client (`ReflectionForm`). Nhờ
 * vậy tiêu đề, phần dẫn và thẻ SEO có trong HTML máy chủ phát ra — công cụ tìm
 * kiếm thấy được, và người dùng thấy nội dung trước khi JavaScript tải xong.
 *
 * Đây là trang bắt buộc của hồ sơ TMĐT, nên nó phải đọc được kể cả khi
 * JavaScript hỏng. Phần duy nhất cần JavaScript là nút gửi.
 */

const TIEU_DE = 'Tiếp nhận phản ánh của tổ chức xã hội';

export const metadata: Metadata = buildMetadata({
    title: `${TIEU_DE} – OOHX`,
    description:
        'OOHX tiếp nhận phản ánh của tổ chức xã hội về hoạt động của sàn và thông tin đăng tải trên sàn. Mọi phản ánh đều được ghi nhận, xử lý và công bố kết quả.',
    path: '/phan-anh-to-chuc-xa-hoi',
});

export default function ReflectionCreatePage() {
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
                <section className="pol">
                    <div className="w pol-w">
                        <nav className="pol-crumb" aria-label="Breadcrumb">
                            <Link href="/">Trang chủ</Link>
                            <span aria-hidden="true">/</span>
                            <span>{TIEU_DE}</span>
                        </nav>

                        <h1 className="pol-h1">{TIEU_DE}</h1>
                        <p className="pol-lead">
                            OOHX tiếp nhận phản ánh của các tổ chức xã hội về hoạt động của sàn
                            và về thông tin đăng tải trên sàn. Mọi phản ánh đều được ghi nhận,
                            xử lý và công bố kết quả tại{' '}
                            <Link href="/phan-anh-to-chuc-xa-hoi/danh-sach">
                                danh sách phản ánh
                            </Link>
                            .
                        </p>

                        <ReflectionForm />
                    </div>
                </section>
            </main>
        </>
    );
}
