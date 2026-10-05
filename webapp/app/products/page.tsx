import type { Metadata } from 'next';
import Link from 'next/link';
import { listProducts, type ProductSummary } from '@/lib/api';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { Pagination } from '@/components/Pagination';

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
                        <div className="oc-grid oc-grid--full">
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

export function ProductCard({ product }: { product: ProductSummary }) {
    return (
        <article className="sc">
            <Link href={`/products/${product.slug}`} className="sc-photo">
                {product.cover_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={product.cover_url} alt={product.name ?? ''} loading="lazy" />
                ) : null}
            </Link>

            <div className="sc-body">
                <div className="sc-breadcrumb">
                    {product.location?.city ? <span>{product.location.city}</span> : null}
                    {/*
                      `category` là một đối tượng `{code, label}`, không phải
                      chuỗi — in `label`, không in cả khối. Type checker bắt
                      được chỗ này; nếu không thì trang hiện `[object Object]`.
                    */}
                    {product.category?.label ? (
                        <>
                            <span className="sc-bc-sep">·</span>
                            <span>{product.category.label}</span>
                        </>
                    ) : null}
                </div>

                <h3 className="sc-title">
                    <Link href={`/products/${product.slug}`}>{product.name}</Link>
                </h3>

                {product.short_description ? (
                    <p className="sc-loc">{product.short_description}</p>
                ) : null}

                <div className="sc-specs">
                    {/*
                      `quantity` cũng là đối tượng: `{total_units, min, max,
                      screen_count}`. Số màn hình là `screen_count` —
                      `total_units` là số suất bán, một con số khác và dễ lẫn.
                    */}
                    {product.quantity?.screen_count ? (
                        <div className="sc-spec">
                            <span className="sc-spec-l">Số màn hình</span>
                            <span className="sc-spec-v">{product.quantity.screen_count}</span>
                        </div>
                    ) : null}
                    {product.owner?.name ? (
                        <div className="sc-spec">
                            <span className="sc-spec-l">Chủ sở hữu</span>
                            <span className="sc-spec-v">{product.owner.name}</span>
                        </div>
                    ) : null}
                </div>

                <div className="sc-foot">
                    {formatProductPrice(product) ? (
                        <div className="sc-price">
                            {formatProductPrice(product)}
                            <span className="sc-price-sub">chưa gồm VAT</span>
                        </div>
                    ) : (
                        <div className="sc-price-quote">Liên hệ báo giá</div>
                    )}

                    <Link
                        href={`/products/${product.slug}`}
                        className="btn btn-p btn-sm sc-btn"
                    >
                        Xem chi tiết
                    </Link>
                </div>
            </div>
        </article>
    );
}

/**
 * Giá gói, hoặc `null` khi không có.
 *
 * Trả `null` thay vì `0 ₫`: in số 0 đọc như miễn phí. Cùng lý do bỏ `offers`
 * khỏi JSON-LD khi chưa có giá.
 *
 * Ưu tiên `package` (giá cả gói) trước `per_screen` (giá mua lẻ một màn
 * hình): thẻ này giới thiệu một GÓI, nên con số lớn hiện trước là con số người
 * mua sẽ trả. In giá lẻ mà không nói rõ là giá lẻ thì người đọc hiểu đó là giá
 * gói — rẻ hơn thật nhiều lần.
 *
 * KHÔNG quy đổi tiền tệ. `currency` lấy từ cột `products.currency` và có thể
 * không phải VND; tự quy đổi ở tầng hiển thị là đặt ra một chính sách tỷ giá
 * mà không ai duyệt — cùng lý do `CartService` chặn đặt trực tuyến với giá
 * ngoài VND.
 */
export function formatProductPrice(product: ProductSummary): string | null {
    const pricing = product.pricing;
    const amount = pricing?.package ?? pricing?.per_screen;

    if (!amount) return null;

    const laGiaLe = !pricing?.package && !!pricing?.per_screen;
    const currency = pricing?.currency || 'VND';
    const formatted = new Intl.NumberFormat('vi-VN').format(amount);

    const price = currency === 'VND' ? formatted + ' ₫' : formatted + ' ' + currency;
    const withUnit = pricing?.unit ? price + '/' + pricing.unit : price;

    return laGiaLe ? withUnit + ' mỗi màn hình' : withUnit;
}
