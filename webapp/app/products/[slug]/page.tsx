import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getProduct, type ProductDetail, type ProductSummary } from '@/lib/api';
import { buildMetadata, jsonLd, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { ProductCard } from '@/components/ProductCard';
import { KhoiGoi, ThuVienAnhGoi } from '@/components/PanelGiaGoi';

/**
 * `/products/{slug}` — chi tiết một gói.
 *
 * ══ Lát 7: bố cục hai cột, thư viện ảnh, panel giá có tab ══
 *
 * Bản Blade 383 dòng: breadcrumb, thư viện ảnh + thumbnail, ba badge, tiêu đề
 * + meta, mô tả, bảng thông số, **danh sách màn hình chọn được bằng
 * checkbox**, gói tương tự, và cột phải là panel giá có hai tab ("Mua cả gói"
 * / "Chọn lẻ") cùng khối owner.
 *
 * Bản Next trước lát này: `pg-hero` + một ảnh + một dòng giá + `<dl>` thông số
 * + lưới thẻ màn hình. Một cột, không panel, không chọn lẻ được.
 *
 * ══ `seo.meta_title` và `seo.meta_description` được tôn trọng ══
 *
 * API trả hai trường đó, do quản trị nhập cho từng gói. Bỏ qua chúng và tự
 * dựng mô tả là xoá công việc của người đã viết chúng, và làm trang Next khác
 * trang Blade ở đúng thứ công cụ tìm kiếm đọc. Chỉ khi chúng rỗng thì mới tự
 * dựng.
 *
 * ══ `specs` là JSON tự do, in theo cặp khóa–giá trị ══
 *
 * Đặc tả nói rõ: "JSON tự do do quản trị nhập, đã hiển thị công khai... Không
 * whitelist được theo khóa vì bộ khóa đổi theo từng gói." Nên ở đây in mọi cặp
 * mà **không** giả định khóa nào tồn tại, và bỏ qua giá trị không phải chuỗi
 * hay số — một giá trị lồng sẽ in ra `[object Object]`.
 */

type Params = { slug: string };

export async function generateMetadata({
    params,
}: {
    params: Promise<Params>;
}): Promise<Metadata> {
    const { slug } = await params;
    const result = await getProduct(slug);

    if (!result) {
        return buildMetadata({ title: 'Không tìm thấy gói | OOHX', path: `/products/${slug}` });
    }

    const product = result.data;

    return buildMetadata({
        title: product.seo?.meta_title || `${product.name ?? 'Gói sản phẩm'} | OOHX`,
        description: product.seo?.meta_description || describe(product),
        path: `/products/${slug}`,
        image: product.cover_url ?? product.photo_urls?.[0],
        type: 'product',
    });
}

const NHAN_KIEU: Record<string, string> = {
    single: 'Đơn lẻ',
    package: 'Gói',
    custom: 'Combo',
};

export default async function ProductDetailPage({ params }: { params: Promise<Params> }) {
    const { slug } = await params;
    const result = await getProduct(slug);

    if (!result) {
        notFound();
    }

    const product = result.data;
    const similar: ProductSummary[] = result.similar ?? [];
    const price = product.pricing?.package ?? product.pricing?.per_screen;

    // Thư viện ảnh: `cover_url` đứng đầu nếu nó không trùng ảnh đầu của
    // `photo_urls` — bản Blade in `cover_url` làm ảnh lớn rồi liệt kê
    // `photo_urls` làm thumbnail, nên ảnh bìa xuất hiện hai lần khi nó cũng
    // nằm trong danh sách.
    const anh = [
        ...(product.cover_url ? [product.cover_url] : []),
        ...(product.photo_urls ?? []).filter((u) => u !== product.cover_url),
    ];

    return (
        <>
            <SiteHeader active="explore" />
            <OgType type="product" />

            <script
                type="application/ld+json"
                dangerouslySetInnerHTML={jsonLd({
                    '@context': 'https://schema.org',
                    '@type': 'Product',
                    name: product.name,
                    description: product.seo?.meta_description || describe(product),
                    image: anh[0] ?? '',
                    brand: {
                        '@type': 'Organization',
                        name: product.owner?.name ?? 'OOHX',
                    },
                    ...(product.category?.label ? { category: product.category.label } : {}),

                    // `offers` chỉ khi CÓ giá thật — xem `/explore/{slug}` để
                    // biết vì sao `price: 0` là nói sai, không phải thiếu.
                    ...(price
                        ? {
                              offers: {
                                  '@type': 'Offer',
                                  price,
                                  priceCurrency: product.pricing?.currency ?? 'VND',
                                  availability: 'https://schema.org/InStock',
                              },
                          }
                        : {}),
                })}
            />

            <main>
                <div className="w" style={{ paddingTop: 20, paddingBottom: 64 }}>
                    <nav className="bc" aria-label="Đường dẫn">
                        <Link href="/products">Sản phẩm</Link>

                        {product.category?.code ? (
                            <>
                                <BcSep />
                                {/*
                                  Dạng dấu phẩy (`?category=led`), không
                                  `category[]=led` như bản Blade — hợp đồng
                                  `style: form, explode: false`, và cùng dạng
                                  sidebar `/products` sinh ra từ lát 3.
                                */}
                                <Link href={`/products?category=${encodeURIComponent(product.category.code)}`}>
                                    {product.category.label ?? product.category.code}
                                </Link>
                            </>
                        ) : null}

                        {product.location?.city ? (
                            <>
                                <BcSep />
                                <Link href={`/products?city=${encodeURIComponent(product.location.city)}`}>
                                    {product.location.city}
                                </Link>
                            </>
                        ) : null}

                        <BcSep />
                        <span style={{ color: 'var(--t2)' }}>{product.name}</span>
                    </nav>

                    <KhoiGoi
                        product={product}
                        tuongTu={
                            similar.length ? (
                                <div style={{ marginTop: 32 }}>
                                    <h3
                                        style={{
                                            fontSize: 16,
                                            fontWeight: 700,
                                            color: 'var(--t1)',
                                            marginBottom: 12,
                                        }}
                                    >
                                        Sản phẩm tương tự
                                    </h3>
                                    <div className="inv-grid">
                                        {similar.map((s) => (
                                            <ProductCard key={s.slug} product={s} />
                                        ))}
                                    </div>
                                </div>
                            ) : null
                        }
                        owner={product.owner?.slug ? <KhoiOwner product={product} /> : null}
                    >
                        <ThuVienAnhGoi anh={anh} ten={product.name ?? ''} />

                        <div style={{ marginTop: 20 }}>
                            <div
                                style={{
                                    display: 'flex',
                                    gap: 6,
                                    marginBottom: 8,
                                    flexWrap: 'wrap',
                                }}
                            >
                                {product.type ? (
                                    <span className="badge b-bl">
                                        {NHAN_KIEU[product.type] ?? product.type}
                                    </span>
                                ) : null}

                                {product.category?.label ? (
                                    <span className="badge b-gray">{product.category.label}</span>
                                ) : null}

                                {product.listing_mode?.label ? (
                                    <span
                                        className="badge"
                                        style={{ background: 'rgba(255,149,0,.1)', color: '#C93400' }}
                                    >
                                        {product.listing_mode.label}
                                    </span>
                                ) : null}
                            </div>

                            <h1
                                style={{
                                    fontSize: 24,
                                    fontWeight: 800,
                                    color: 'var(--t1)',
                                    letterSpacing: '-.4px',
                                    marginBottom: 8,
                                }}
                            >
                                {product.name}
                            </h1>

                            <div
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 12,
                                    fontSize: 13,
                                    color: 'var(--t3)',
                                    flexWrap: 'wrap',
                                }}
                            >
                                {product.owner?.slug ? (
                                    <Link
                                        href={`/owners/${product.owner.slug}`}
                                        style={{ color: 'var(--bl)', fontWeight: 600 }}
                                    >
                                        {product.owner.name}
                                    </Link>
                                ) : null}

                                {product.network?.name ? <span>{product.network.name}</span> : null}
                                {product.location?.city ? <span>{product.location.city}</span> : null}
                                <span>{product.quantity?.total_units ?? 0} vị trí</span>
                            </div>
                        </div>

                        {product.description ? (
                            <div
                                style={{
                                    marginTop: 20,
                                    fontSize: 14,
                                    color: 'var(--t2)',
                                    lineHeight: 1.7,
                                    whiteSpace: 'pre-line',
                                }}
                            >
                                {product.description}
                            </div>
                        ) : null}

                        <Specs specs={product.specs} />
                    </KhoiGoi>
                </div>
            </main>
        </>
    );
}

function BcSep() {
    return (
        <span className="bc-sep">
            <svg viewBox="0 0 24 24" fill="var(--t4)" aria-hidden="true" style={{ width: 14, height: 14 }}>
                <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" />
            </svg>
        </span>
    );
}

/** Khối owner ở cột phải, dưới panel giá. */
function KhoiOwner({ product }: { product: ProductDetail }) {
    return (
        <div
            style={{
                marginTop: 16,
                padding: 18,
                background: 'var(--bg2)',
                borderRadius: 16,
                border: '1px solid var(--ln2)',
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 10 }}>
                <div>
                    <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--t1)' }}>
                        {product.owner?.name}
                    </div>
                    {/*
                      Bản Blade in "Verified Owner" ngay dưới tên, **không
                      kiểm gì** — một nhãn in vô điều kiện cho mọi owner.
                      `ProductSummary.owner` chỉ có `slug` + `name`, nên ở đây
                      in đúng vai trò thay vì một khẳng định chưa ai xác minh.
                    */}
                    <div style={{ fontSize: 11, color: 'var(--t4)' }}>Media owner của gói này</div>
                </div>
            </div>

            <Link
                href={`/owners/${product.owner?.slug}`}
                className="btn btn-s btn-sm"
                style={{
                    width: '100%',
                    justifyContent: 'center',
                    borderRadius: 8,
                    textDecoration: 'none',
                }}
            >
                Xem hồ sơ
            </Link>
        </div>
    );
}

/**
 * In `specs` theo cặp khóa–giá trị.
 *
 * Bỏ qua giá trị không phải chuỗi/số: một giá trị lồng (mảng, đối tượng) sẽ in
 * ra `[object Object]` trên trang công khai. Thà không in hơn in rác — và vì
 * bộ khóa đổi theo từng gói, không có cách nào biết trước cái gì sẽ tới.
 */
function Specs({ specs }: { specs: ProductDetail['specs'] }) {
    if (!specs || typeof specs !== 'object') return null;

    const pairs = Object.entries(specs).filter(
        ([, value]) => typeof value === 'string' || typeof value === 'number',
    );

    if (pairs.length === 0) return null;

    return (
        <div style={{ marginTop: 24 }}>
            <h3 style={{ fontSize: 16, fontWeight: 700, color: 'var(--t1)', marginBottom: 12 }}>
                Thông số kỹ thuật
            </h3>

            <div className="specs-grid">
                {pairs.map(([key, value]) => (
                    <div className="sc" key={key}>
                        <div className="sc-l">{key}</div>
                        <div className="sc-v">{String(value)}</div>
                    </div>
                ))}
            </div>
        </div>
    );
}

function describe(product: ProductDetail): string {
    if (product.short_description) return product.short_description;
    if (product.description) return product.description;

    const parts = [product.name, product.location?.city].filter(Boolean);

    return parts.length ? `${parts.join(', ')} — gói quảng cáo ngoài trời trên OOHX.` : '';
}
