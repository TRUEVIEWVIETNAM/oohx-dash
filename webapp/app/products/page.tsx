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

/**
 * Chữ và màu cho `products.type`.
 *
 * Chép đúng bản Blade (`products/partials/product-card.blade.php`). Màu đi
 * trong `style` chứ không thành class: bản Blade cũng vậy, và `frontpage.css`
 * không có lớp màu nào cho ba giá trị này.
 */
const KIEU_GOI: Record<string, { nhan: string; mau: string }> = {
    single: { nhan: 'Đơn lẻ', mau: 'var(--bl)' },
    package: { nhan: 'Gói', mau: 'var(--grn)' },
    custom: { nhan: 'Combo', mau: 'var(--org)' },
};

/**
 * Thẻ một gói sản phẩm.
 *
 * ══ Vì sao viết lại 14/10/2026 ══
 *
 * Bản trước mở bằng `<article className="sc">` và dùng tiếp bộ `sc-*` — tức bộ
 * class của **thẻ màn hình**, và `sc` lại là một ô bảng thông số. Bản Blade có
 * bộ riêng cho gói: `pd-card`, `pd-photo`, `pd-badges`, `pd-units`, `pd-body`,
 * `pd-name`, `pd-owner`, `pd-loc`, `pd-desc`, `pd-foot`, `pd-price`, `pd-btn`.
 * Chúng vẫn nằm đủ trong `frontpage.css`.
 *
 * Hai thứ bản Blade in mà DTO không có: `category_label` làm chữ cho ảnh thay
 * thế, và `price_display` (chuỗi đã định dạng ở phía PHP). Giá ở đây tự định
 * dạng — xem `formatProductPrice()` về việc vì sao không in `0 ₫`.
 */
export function ProductCard({ product }: { product: ProductSummary }) {
    const kieu = product.type ? KIEU_GOI[product.type] : undefined;
    const gia = formatProductPrice(product);
    const laGoi = product.type === 'package';
    const soViTri = product.quantity?.total_units ?? 0;

    return (
        <Link href={`/products/${product.slug}`} className="pd-card">
            <div className="pd-photo">
                {product.cover_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={product.cover_url} alt={product.name ?? ''} loading="lazy" />
                ) : null}

                <div className="pd-badges">
                    {product.type ? (
                        <span
                            className="pd-badge"
                            style={{ background: kieu?.mau ?? 'var(--bl)' }}
                        >
                            {kieu?.nhan ?? product.type}
                        </span>
                    ) : null}

                    {/*
                      `category` là một đối tượng `{code, label}`, không phải
                      chuỗi — in `label`, không in cả khối. Type checker bắt
                      được chỗ này; nếu không thì trang hiện `[object Object]`.
                    */}
                    {product.category?.label ? (
                        <span className="pd-badge pd-badge-cat">{product.category.label}</span>
                    ) : null}
                </div>

                {/*
                  `quantity.total_units` là số **suất bán**, khác
                  `quantity.screen_count` là số màn hình. Bản Blade in số suất ở
                  đây, nên giữ đúng con số đó.
                */}
                {laGoi && soViTri > 1 ? <div className="pd-units">{soViTri} vị trí</div> : null}
            </div>

            <div className="pd-body">
                <div className="pd-name">{product.name}</div>

                {product.owner?.name ? <div className="pd-owner">{product.owner.name}</div> : null}

                {product.location?.city ? (
                    <div className="pd-loc">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" />
                        </svg>
                        {product.location.city}
                    </div>
                ) : null}

                {product.short_description ? (
                    <div className="pd-desc">{product.short_description}</div>
                ) : null}
            </div>

            <div className="pd-foot">
                <div>
                    <div className="pd-price">{gia ?? 'Liên hệ báo giá'}</div>
                </div>
                <span className="btn btn-p btn-sm pd-btn">{laGoi ? 'Chọn gói' : 'Chi tiết'}</span>
            </div>
        </Link>
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
