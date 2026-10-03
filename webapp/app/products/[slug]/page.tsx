import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { getProduct, type ProductDetail } from '@/lib/api';
import { buildMetadata, jsonLd, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { ScreenCard } from '@/components/ScreenCard';
import { formatProductPrice } from '../page';

/**
 * `/products/{slug}` — chi tiết một gói.
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

export default async function ProductDetailPage({ params }: { params: Promise<Params> }) {
    const { slug } = await params;
    const result = await getProduct(slug);

    if (!result) {
        notFound();
    }

    const product = result.data;
    const price = product.pricing?.package ?? product.pricing?.per_screen;

    return (
        <>
            <SiteHeader />
            <OgType type="product" />

            <script
                type="application/ld+json"
                dangerouslySetInnerHTML={jsonLd({
                    '@context': 'https://schema.org',
                    '@type': 'Product',
                    name: product.name,
                    description: product.seo?.meta_description || describe(product),
                    image: product.cover_url ?? product.photo_urls?.[0] ?? '',
                    brand: {
                        '@type': 'Organization',
                        name: product.owner?.name ?? 'OOHX',
                    },

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
                <div className="pg-hero">
                    <div className="w">
                        <div className="sc-breadcrumb">
                            {product.location?.city ? <span>{product.location.city}</span> : null}
                            {/* `category` là `{code, label}` — in `label`. */}
                            {product.category?.label ? (
                                <>
                                    <span className="sc-bc-sep">·</span>
                                    <span>{product.category.label}</span>
                                </>
                            ) : null}
                        </div>
                        <h1>{product.name}</h1>
                        {product.short_description ? <p>{product.short_description}</p> : null}
                    </div>
                </div>

                <div className="w">
                    {product.cover_url ? (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img
                            src={product.cover_url}
                            alt={product.name ?? ''}
                            style={{ width: '100%', borderRadius: 12 }}
                        />
                    ) : null}

                    <div className="sc-foot" style={{ padding: '20px 0' }}>
                        {formatProductPrice(product) ? (
                            <div className="sc-price">
                                {formatProductPrice(product)}
                                <span className="sc-price-sub">chưa gồm VAT</span>
                            </div>
                        ) : (
                            <div className="sc-price-quote">Liên hệ báo giá</div>
                        )}
                    </div>

                    {product.description ? (
                        <section style={{ maxWidth: 760, padding: '8px 0 24px' }}>
                            <p>{product.description}</p>
                        </section>
                    ) : null}

                    <Specs specs={product.specs} />

                    {product.package_options && product.package_options.length > 0 ? (
                        <section style={{ padding: '24px 0' }}>
                            <h2 className="section-hed">Các mức gói</h2>
                            <ul className="ft-ls">
                                {product.package_options.map((option, i) => (
                                    <li key={option.name ?? i}>
                                        {option.name}
                                        {option.quantity ? ` — ${option.quantity} màn hình` : ''}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ) : null}

                    {product.screens && product.screens.length > 0 ? (
                        <section style={{ padding: '24px 0' }}>
                            <h2 className="section-hed">Màn hình trong gói</h2>
                            <div className="oc-grid oc-grid--full">
                                {product.screens.map((screen) => (
                                    <ScreenCard key={screen.slug} screen={screen} />
                                ))}
                            </div>
                        </section>
                    ) : null}
                </div>
            </main>
        </>
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
        <dl className="sc-specs">
            {pairs.map(([key, value]) => (
                <div className="sc-spec" key={key}>
                    <dt className="sc-spec-l">{key}</dt>
                    <dd className="sc-spec-v">{String(value)}</dd>
                </div>
            ))}
        </dl>
    );
}

function describe(product: ProductDetail): string {
    if (product.short_description) return product.short_description;
    if (product.description) return product.description;

    const parts = [product.name, product.location?.city].filter(Boolean);

    return parts.length ? `${parts.join(', ')} — gói quảng cáo ngoài trời trên OOHX.` : '';
}
