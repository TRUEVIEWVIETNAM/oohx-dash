import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { getScreen, type ScreenDetail } from '@/lib/api';
import { buildMetadata, jsonLd, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';

/**
 * `/explore/{slug}` — chi tiết một màn hình.
 *
 * ══ Đường dẫn giữ nguyên tuyệt đối ══
 *
 * CLAUDE.md mục 3 yêu cầu giữ nguyên `/explore/{slug}`, và `sitemap.xml` do
 * Laravel sinh đang liệt kê đúng những URL này (xem
 * `SeoBaselineTest::test_sitemap_co_trang_chi_tiet_cua_man_hinh_owner_san_pham`).
 * Đổi dạng đường dẫn ở đây là làm sai một sitemap mà không chạm vào nó.
 *
 * ══ 404 phải là 404 thật ══
 *
 * `getScreen()` trả `null` khi API trả 404, và trang gọi `notFound()`. Trả một
 * trang 200 rỗng cho slug không tồn tại là để công cụ tìm kiếm giữ URL đó
 * trong chỉ mục mãi, và đó là cách làm chỉ mục bẩn dần mà không ai thấy.
 */

type Params = { slug: string };

export async function generateMetadata({
    params,
}: {
    params: Promise<Params>;
}): Promise<Metadata> {
    const { slug } = await params;
    const screen = await getScreen(slug);

    if (!screen) {
        // Next vẫn gọi `generateMetadata` trước khi component chạy
        // `notFound()`, nên phải chịu được trường hợp không có dữ liệu.
        return buildMetadata({ title: 'Không tìm thấy vị trí | OOHX', path: `/explore/${slug}` });
    }

    return buildMetadata({
        title: `${screen.name ?? 'Chi tiết vị trí'} | OOHX`,
        description: describe(screen),
        path: `/explore/${slug}`,
        image: screen.photo_url,
        // Bản Blade dùng `product`, giữ nguyên: đổi `og:type` là đổi cách
        // mạng xã hội dựng thẻ xem trước cho đúng URL đó.
        type: 'product',
    });
}

export default async function ScreenDetailPage({ params }: { params: Promise<Params> }) {
    const { slug } = await params;
    const screen = await getScreen(slug);

    if (!screen) {
        notFound();
    }

    const price = screen.pricing?.io_rate?.amount ?? screen.pricing?.floor_cpm?.amount ?? 0;
    const currency =
        screen.pricing?.io_rate?.currency ?? screen.pricing?.floor_cpm?.currency ?? 'VND';

    return (
        <>
            <SiteHeader active="explore" />

            <main>
            {/* Bản Blade phát `og:type=product`. Giữ nguyên — xem `OgType`. */}
            <OgType type="product" />

            {/*
              JSON-LD giữ đúng hình dạng bản Blade: Product + Offer + brand.
              `SeoBaselineTest::test_trang_chi_tiet_man_hinh_co_json_ld` canh sự
              có mặt của nó, và công cụ tìm kiếm đọc nó để dựng thẻ sản phẩm.
            */}
            <script
                type="application/ld+json"
                dangerouslySetInnerHTML={jsonLd({
                    '@context': 'https://schema.org',
                    '@type': 'Product',
                    name: screen.name,
                    description: describe(screen),
                    image: screen.photo_url ?? '',
                    brand: { '@type': 'Organization', name: screen.owner?.name ?? 'OOHX' },

                    // `offers` chỉ xuất hiện khi CÓ giá thật.
                    //
                    // Bản Blade viết `'price' => (float) ($screen->...->display_price ?? 0)`,
                    // nên màn hình chưa niêm yết giá phát ra
                    // `{"price":0,"priceCurrency":"VND"}` — và với schema.org
                    // thì `price: 0` nghĩa là **miễn phí**. Đó là cùng loại lỗi
                    // audit F-15: một con số không có nguồn, nói sai, và không
                    // ai thấy vì JSON-LD không hiện trên trang.
                    //
                    // Không có giá thì bỏ `offers` hẳn. Thiếu một khối không
                    // bắt buộc thì công cụ tìm kiếm chỉ hiển thị ít thông tin
                    // hơn; khai giá 0 thì nó hiển thị SAI.
                    ...(price > 0
                        ? {
                              offers: {
                                  '@type': 'Offer',
                                  price,
                                  priceCurrency: currency,
                                  availability: screen.availability?.has_capacity
                                      ? 'https://schema.org/InStock'
                                      : 'https://schema.org/OutOfStock',
                              },
                          }
                        : {}),
                })}
            />

            <div className="w">
                <nav className="sc-breadcrumb" aria-label="Đường dẫn">
                    {screen.location?.city ? <span>{screen.location.city}</span> : null}
                    {screen.location?.city && screen.location?.district ? (
                        <span className="sc-bc-sep">·</span>
                    ) : null}
                    {screen.location?.district ? <span>{screen.location.district}</span> : null}
                </nav>

                <h1>{screen.name}</h1>

                {screen.photo_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                        src={screen.photo_url}
                        alt={screen.name ?? ''}
                        style={{ width: '100%', borderRadius: 12 }}
                    />
                ) : null}

                <dl className="sc-specs">
                    <Spec label="Media owner" value={screen.owner?.name} />
                    <Spec label="Địa điểm" value={screen.location?.site} />
                    <Spec label="Thành phố" value={screen.location?.city} />
                    <Spec label="Loại màn hình" value={screen.screen_type} />
                    <Spec
                        label="Kích thước"
                        value={
                            screen.size?.width_m && screen.size?.height_m
                                ? `${screen.size.width_m}×${screen.size.height_m}m`
                                : null
                        }
                    />
                    <Spec label="Mạng lưới" value={screen.network?.name} />
                </dl>

                {/*
                  Tình trạng suất nói ra bằng SỐ THẬT từ API, không bằng một
                  badge in vô điều kiện — audit F-15, và
                  `NoFabricatedMetricsTest` canh đúng chuyện đó ở bản Blade.
                */}
                {screen.availability ? (
                    <p className="bp-avail">
                        {screen.availability.has_capacity
                            ? `Còn ${screen.availability.remaining_sov_pct}% thời lượng trong ${screen.availability.window_days} ngày tới`
                            : `Đã đầy ${screen.availability.window_days} ngày tới`}
                    </p>
                ) : null}
            </div>
            </main>
        </>
    );
}

function Spec({ label, value }: { label: string; value?: string | number | null }) {
    if (value === null || value === undefined || value === '') return null;

    return (
        <div className="sc-spec">
            <dt className="sc-spec-l">{label}</dt>
            <dd className="sc-spec-v">{value}</dd>
        </div>
    );
}

/**
 * Mô tả dùng cho cả `<meta name="description">` và JSON-LD.
 *
 * Một hàm, hai chỗ dùng: bản Blade dựng hai chuỗi gần giống nhau ở hai dòng
 * khác nhau, và chúng đã lệch — `seoDescription` có giá, `description` trong
 * JSON-LD thì không. Lệch thì không ai thấy, vì cả hai đều hợp lệ.
 */
function describe(screen: ScreenDetail): string {
    const parts = [screen.name, screen.location?.site, screen.location?.city].filter(Boolean);

    return parts.join(', ') + '.';
}
