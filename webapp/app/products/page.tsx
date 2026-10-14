import type { Metadata } from 'next';
import Link from 'next/link';
import {
    listProducts,
    getProductFilters,
    type ProductFilters,
} from '@/lib/api';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { Pagination } from '@/components/Pagination';
import { ProductCard } from '@/components/ProductCard';
import { KhoiKetQua } from '@/components/KhoiKetQua';
import { BoLocGoi } from '@/components/BoLocGoi';

/**
 * `/products` — danh sách gói sản phẩm.
 *
 * ══ Giá in kèm đúng đơn vị API trả về, không quy đổi ══
 *
 * `pricing` có thể mang đơn vị khác VND. Tự quy đổi ở tầng hiển thị là đặt ra
 * một chính sách tỷ giá mà không ai duyệt — cùng lý do `CartService` chặn đặt
 * trực tuyến với giá ngoài VND. Nên in nguyên đơn vị.
 *
 * ══ Lát 3: bộ lọc hiện ra cho người dùng ══
 *
 * Trang này từng là một lưới phẳng: không sidebar, không chip, không ô sắp
 * xếp, trong khi `/api/v2/products` nhận `q`, `category`, `type`, `city`,
 * `owner`, `min_price`, `max_price`, `sort`. Nay dựng lại bố cục của
 * `frontpage/products/index.blade.php`: hero `ph` + chip danh mục, hai cột
 * (sidebar | kết quả), và drawer lọc trên mobile.
 *
 * `min_price`/`max_price` **không** có trong sidebar — bản Blade cũng không có,
 * và `frontpage.css` có sẵn `.sb-price-row`/`.sb-preset` cho nó. Thêm vào là
 * thêm một bộ lọc mới, không phải dựng lại cái cũ.
 */

const PER_PAGE = 24;

export const metadata: Metadata = buildMetadata({
    title: 'Gói sản phẩm OOH/DOOH | OOHX',
    description:
        'Các gói quảng cáo ngoài trời đã dựng sẵn: combo màn hình theo khu vực, ' +
        'theo loại điểm đặt, theo mạng lưới. Đặt booking trực tiếp trên OOHX.',
    path: '/products',
});

type SearchParams = {
    page?: string;
    q?: string;
    city?: string;
    type?: string;
    category?: string;
    sort?: string;
};

export default async function ProductsPage({
    searchParams,
}: {
    searchParams: Promise<SearchParams>;
}) {
    const params = await searchParams;
    const page = Math.max(1, Number.parseInt(params.page ?? '1', 10) || 1);

    /*
     * `allSettled`: danh sách là nội dung của trang, bộ lọc chỉ là khung điều
     * khiển. Để `/products/filters` hỏng làm trắng cả trang danh mục là biến
     * một lỗi nhỏ thành một lỗi toàn phần — sidebar rỗng còn đọc được, trang
     * trắng thì không.
     */
    const [ketQua, boLoc] = await Promise.allSettled([
        listProducts({
            page,
            per_page: PER_PAGE,
            q: params.q,
            city: params.city,
            type: params.type,
            category: params.category,
            sort: params.sort,
        }),
        getProductFilters(),
    ]);

    if (ketQua.status === 'rejected') throw ketQua.reason;

    const { data: products, meta } = ketQua.value;
    const filters: ProductFilters | null = boLoc.status === 'fulfilled' ? boLoc.value : null;

    const danhMucDangChon = new Set(
        (params.category ?? '')
            .split(/[,|]/)
            .map((s) => s.trim())
            .filter(Boolean),
    );

    // "Tất cả" sáng khi không có bộ lọc nào — đúng cách bản Blade tính
    // `$hasFilters`, kể cả `q` và `type` chứ không chỉ danh mục.
    const coBoLoc = Boolean(
        danhMucDangChon.size || params.city || params.q || params.type,
    );

    return (
        <>
            <SiteHeader active="explore" />
            <OgType type="website" />

            <main>
                <div className="ph">
                    <div className="w">
                        <div className="ph-top">
                            <h1 className="ph-title">Sản phẩm quảng cáo</h1>

                            {/*
                              Số trong nút là tổng sản phẩm **sau bộ lọc hiện
                              tại**, đúng như bản Blade (`$products->total()`).
                              Nó là một con số thật của trang, không phải một
                              con số quảng cáo.
                            */}
                            <Link href="/map" className="ph-map-btn">
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z" />
                                </svg>
                                Bản đồ ({new Intl.NumberFormat('vi-VN').format(meta.total ?? 0)})
                            </Link>
                        </div>

                        {/*
                          Chip danh mục. Là **liên kết**, không phải nút có JS:
                          mỗi chip là một URL thật, nên chia sẻ được, quay lại
                          được và công cụ tìm kiếm đi theo được. Cùng cách
                          `cat-chips` ở `/owners` làm từ lát 1.
                        */}
                        <div className="fchips">
                            <Link href="/products" className={coBoLoc ? 'fchip' : 'fchip on'}>
                                Tất cả
                            </Link>

                            {(filters?.categories ?? []).map((cat) =>
                                cat.slug ? (
                                    <Link
                                        key={cat.slug}
                                        href={`/products?category=${encodeURIComponent(cat.slug)}`}
                                        className={
                                            danhMucDangChon.has(cat.slug) ? 'fchip on' : 'fchip'
                                        }
                                    >
                                        {cat.label ?? cat.slug}{' '}
                                        <span className="fchip-count">{cat.count}</span>
                                    </Link>
                                ) : null,
                            )}
                        </div>
                    </div>
                </div>

                <div className="w">
                    <div className="listing-layout">
                        <BoLocGoi filters={filters} params={params} />

                        <div>
                            <KhoiKetQua
                                tong={meta.total ?? 0}
                                donVi="sản phẩm"
                                sort={params.sort ?? ''}
                                basePath="/products"
                                query={params}
                                kieuXem={false}
                            >
                                {products.length === 0 ? (
                                    <div
                                        style={{
                                            gridColumn: '1/-1',
                                            textAlign: 'center',
                                            padding: '60px 0',
                                            color: 'var(--t4)',
                                        }}
                                    >
                                        Không tìm thấy sản phẩm phù hợp
                                    </div>
                                ) : (
                                    products.map((product) => (
                                        <ProductCard key={product.slug} product={product} />
                                    ))
                                )}
                            </KhoiKetQua>

                            <Pagination basePath="/products" params={params} meta={meta} />
                        </div>
                    </div>
                </div>
            </main>
        </>
    );
}
