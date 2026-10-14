import type { Metadata } from 'next';
import Link from 'next/link';
import { listProducts, type ProductSummary } from '@/lib/api';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { Pagination } from '@/components/Pagination';
import { ProductCard } from '@/components/ProductCard';

/**
 * `/products` — danh sách gói sản phẩm.
 *
 * ══ Giá in kèm đúng đơn vị API trả về, không quy đổi ══
 *
 * `pricing` có thể mang đơn vị khác VND. Tự quy đổi ở tầng hiển thị là đặt ra
 * một chính sách tỷ giá mà không ai duyệt — cùng lý do `CartService` chặn đặt
 * trực tuyến với giá ngoài VND. Nên in nguyên đơn vị.
 */

const PER_PAGE = 24;

export const metadata: Metadata = buildMetadata({
    title: 'Gói sản phẩm OOH/DOOH | OOHX',
    description:
        'Các gói quảng cáo ngoài trời đã dựng sẵn: combo màn hình theo khu vực, ' +
        'theo loại điểm đặt, theo mạng lưới. Đặt booking trực tiếp trên OOHX.',
    path: '/products',
});

type SearchParams = { page?: string; q?: string; city?: string; type?: string };

export default async function ProductsPage({
    searchParams,
}: {
    searchParams: Promise<SearchParams>;
}) {
    const params = await searchParams;
    const page = Math.max(1, Number.parseInt(params.page ?? '1', 10) || 1);

    const { data: products, meta } = await listProducts({
        page,
        per_page: PER_PAGE,
        q: params.q,
        city: params.city,
        type: params.type,
    });

    return (
        <>
            <SiteHeader />
            <OgType type="website" />

            <main>
                <div className="pg-hero">
                    <div className="w">
                        <h1>Gói sản phẩm</h1>
                        <p>
                            {new Intl.NumberFormat('vi-VN').format(meta.total ?? 0)} gói đang mở
                            bán.
                        </p>
                    </div>
                </div>

                <div className="w">
                    {products.length === 0 ? (
                        <div
                            style={{ padding: '64px 20px', textAlign: 'center', color: 'var(--t4)' }}
                        >
                            Không có gói nào khớp bộ lọc.
                        </div>
                    ) : (
                        <div className="inv-grid">
                            {products.map((product) => (
                                <ProductCard key={product.slug} product={product} />
                            ))}
                        </div>
                    )}

                    <Pagination basePath="/products" params={params} meta={meta} />
                </div>
            </main>
        </>
    );
}
