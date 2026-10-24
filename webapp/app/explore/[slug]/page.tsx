import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getScreen, getScreenAvailability, type ScreenDetail } from '@/lib/api';
import { moTaDiaGioi, tachDiaGioi } from '@/lib/dia-gioi';
import { buildMetadata, jsonLd, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { ScreenCard } from '@/components/ScreenCard';
import { ThuVienAnh } from '@/components/ThuVienAnh';
import { TabChiTiet } from '@/components/TabChiTiet';
import { KhoiInsights } from '@/components/KhoiInsights';
import { LichTrong } from '@/components/LichTrong';
import { LichGon } from '@/components/LichGon';
import { PanelGia } from '@/components/PanelGia';
import { KhoiViTri } from '@/components/KhoiViTri';

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
 *
 * ══ Lát 4: từ 189 dòng lên trang đầy đủ ══
 *
 * Bản Blade 776 dòng; bản Next trước lát 4 là 189 và mất gần hết: một ảnh thay
 * cho thư viện ảnh, không hàng số liệu, không tab nào, không insights, không
 * lịch trống, không panel giá, không thẻ owner, không bản đồ vị trí, không
 * màn hình tương tự. Lát này dựng lại tất cả — trừ hai thứ nói rõ ở dưới.
 *
 * ══ Một thứ KHÔNG dựng lại ══
 *
 * **Bốn thẻ đánh giá 4.9 sao** ("Minh Hằng · Ogilvy Vietnam"…). Bản Blade đã
 * bọc chúng trong `@if(false)` — tức chính tác giả đã tắt vì chúng là dữ liệu
 * bịa. Tab "Đánh giá" dựng lại đúng trạng thái đang bật: chưa có đánh giá nào.
 *
 * ══ Và một thứ chủ dự án chốt dựng lại nguyên văn (18/10/2026) ══
 *
 * Khối "Điểm nổi bật" bốn thẻ `hl-card` — "AI Traffic Data / Cập nhật theo
 * giờ thực", "Proof of Play / Video xác nhận hàng ngày", "Verified Location /
 * Kiểm tra thực địa 2024", "Fast Booking / Xác nhận trong 48h". Bốn câu, không
 * câu nào kiểm được, và chúng in **vô điều kiện cho mọi màn hình**. Danh sách
 * đầy đủ những gì không kiểm được ở chú thích ngay trên markup, trong
 * `TabThongTin`.
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
        image: screen.photo_urls?.[0] ?? screen.photo_url,
        // Bản Blade dùng `product`, giữ nguyên: đổi `og:type` là đổi cách
        // mạng xã hội dựng thẻ xem trước cho đúng URL đó.
        type: 'product',
    });
}

export default async function ScreenDetailPage({ params }: { params: Promise<Params> }) {
    const { slug } = await params;

    // Hai lời gọi song song: chi tiết là nội dung của trang, lịch trống là một
    // tab. `getScreenAvailability()` tự trả `null` khi lỗi, nên không cần
    // `allSettled` ở đây — mất lịch thì tab nói "chưa lấy được".
    const [screen, lich] = await Promise.all([getScreen(slug), getScreenAvailability(slug, 2)]);

    if (!screen) {
        notFound();
    }

    const price = screen.pricing?.io_rate?.amount ?? screen.pricing?.floor_cpm?.amount ?? 0;
    const currency =
        screen.pricing?.io_rate?.currency ?? screen.pricing?.floor_cpm?.currency ?? 'VND';

    const anh = screen.photo_urls?.length
        ? screen.photo_urls
        : screen.photo_url
          ? [screen.photo_url]
          : [];

    // Breadcrumb lấy CẤP TỈNH, phần còn lại đi xuống dòng mô tả dưới `h1`:
    // một breadcrumb mang theo cả "Cần Thơ > Phường Tân An" thì dài và lặp lại
    // thứ nằm ngay bên dưới nó.
    const diaGioi = tachDiaGioi(screen.location?.city ?? screen.site?.city);
    const thanhPho = diaGioi.tinh;

    // `?? 100` giữ nguyên cho `LichTrong` — đổi mặc định của nó là đổi hành vi
    // một khối khác, ngoài phạm vi lát này. Nhưng ô số liệu **không** dùng nó:
    // một màn hình không khai `sov_max_pct` mà hiện "100%" là nói với người mua
    // rằng họ mua được toàn bộ thời lượng, trong khi hệ thống không biết điều
    // đó. Ô đọc trường thật, trống thì để trống.
    const sovMax = screen.specs?.sov_max_pct ?? 100;
    const sovThat = screen.specs?.sov_max_pct;

    // ══ Tiêu đề không còn là mã kho ══
    //
    // `screen.name` là mã kho — vị trí đo được ngày 24/10/2026 mang tên
    // `OOHX_LED_CTO_CAN_THO`, và nó đang là `h1` của trang. Người mua đọc
    // `site.name` ("LED Cần Thơ"); mã kho xuống một nhãn phụ để người vận hành
    // vẫn tra được.
    //
    // Bản thiết kế ghép thêm tên đường tách từ `site.address`. Không làm: số
    // nhà trong dữ liệu có đủ dạng ("59–59A", "Lô 12", "KM 5+300"), nên mọi
    // regex tách số nhà khỏi tên đường sẽ sai ở một phần kho — và sai ở tiêu
    // đề thì sai ở chỗ dễ thấy nhất. Địa chỉ đầy đủ đứng riêng một dòng ngay
    // dưới, không bị cắt gì.
    //
    // `generateMetadata`, JSON-LD và breadcrumb vẫn dùng `screen.name`: đó là
    // thứ công cụ tìm kiếm đã lập chỉ mục.
    const tenKho = screen.name ?? '';
    const tenHienThi = screen.site?.name?.trim() || tenKho;

    const tabs = [
        { khoa: 'info', nhan: 'Thông tin', noiDung: <TabThongTin screen={screen} /> },

        // ══ Lát 2: tab Insights LUÔN có mặt ══
        //
        // Trước lát này tab bị bỏ hẳn khi `screen.insights` là null — và đó là
        // hai lỗi trong một điều kiện. Thứ nhất, nó không bắt được trường hợp
        // phổ biến hơn: đối tượng `insights` có, mọi trường bên trong null, nên
        // tab vẫn hiện và rỗng. Thứ hai, ẩn tab nói với người mua rằng sàn
        // không có phần số liệu khán giả, trong khi sự thật là chủ vị trí chưa
        // khai — hai điều đó dẫn tới hai hành động khác nhau.
        //
        // `KhoiInsights` tự lo cả hai trạng thái, nên quyết định nằm ở MỘT chỗ
        // thay vì chia đôi giữa trang và khối.
        {
            khoa: 'insights',
            nhan: 'Insights',
            noiDung: <KhoiInsights insights={screen.insights ?? null} />,
        },
        {
            khoa: 'avail',
            nhan: 'Lịch trống',
            noiDung: <LichTrong lich={lich} sovMax={sovMax} />,
        },
        { khoa: 'review', nhan: 'Đánh giá', noiDung: <TabDanhGia /> },
    ];

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
                    image: anh[0] ?? '',
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
                {/*
                  Breadcrumb. Bản Blade tra slug loại hình bằng một truy vấn
                  `DB::table('venue_categories')` ngay trong view; ở đây chỉ có
                  nhãn từ API, nên mục loại hình là **chữ**, không phải liên
                  kết. Một liên kết trỏ tới `/explore?venue_type=` với slug
                  đoán sai thì ra trang rỗng — tệ hơn không có liên kết.
                */}
                <nav className="bc" aria-label="Đường dẫn">
                    <Link href="/">OOHX</Link>
                    <BcSep />
                    <Link href="/explore">Inventory</Link>

                    {screen.specs?.venue_label ? (
                        <>
                            <BcSep />
                            <span>{screen.specs.venue_label}</span>
                        </>
                    ) : null}

                    {thanhPho ? (
                        <>
                            <BcSep />
                            <span>{thanhPho}</span>
                        </>
                    ) : null}

                    <BcSep />
                    <span style={{ color: 'var(--t2)' }}>{screen.name}</span>
                </nav>

                <ThuVienAnh
                    anh={anh}
                    ten={screen.name ?? ''}
                    conTrong={Boolean(screen.availability?.has_capacity)}
                    soNgay={screen.availability?.window_days ?? 30}
                />

                <div className="detail-layout">
                    <div>
                        {/*
                          Nhãn mã kho chỉ hiện khi nó KHÁC tiêu đề. Màn hình
                          không có `site.name` thì tiêu đề rơi về chính mã kho,
                          và in nó hai lần liền nhau là một lỗi trình bày.
                        */}
                        {tenKho && tenKho !== tenHienThi ? (
                            <div className="dkho">{tenKho}</div>
                        ) : null}

                        <h1 className="dtitle">{tenHienThi}</h1>

                        {screen.site?.address ? (
                            <div className="daddr">{screen.site.address}</div>
                        ) : null}

                        <div className="dmeta">
                            <div className="dloc">
                                <svg viewBox="0 0 24 24" fill="var(--bl)" aria-hidden="true">
                                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                                </svg>{' '}
                                {moTaDiaGioi(screen.location?.city ?? screen.site?.city)}
                            </div>
                            <span className="msep" />
                            <span style={{ fontSize: 13, color: 'var(--t3)' }}>
                                {screen.owner?.name}
                            </span>
                        </div>

                        {screen.owner?.slug ? <OwnerMini screen={screen} /> : null}

                        {/*
                          ══ Bốn ô này đổi nội dung ở lát 1 ══

                          Trước: Site · Network · Kích thước · Floor CPM.
                          "Site" và "Network" là cách **sàn** tổ chức kho,
                          không phải thứ một agency cân nhắc khi sàng vị trí;
                          còn "Floor CPM" rỗng trên phần lớn kho vì mô hình bán
                          là IO (thuê trọn theo tháng). Ba ô trên bốn ô nói về
                          chuyện nội bộ hoặc không nói gì.

                          Cả Site và Network vẫn còn trong bảng thông số ở tab
                          "Thông tin" — không mất đi, chỉ nhường chỗ.

                          Sau: bốn con số một người mua DOOH thật sự dùng để
                          sàng — giá, lượng người thấy, cỡ biển, phần hiển thị
                          mua được. Trên vị trí đo được ngày 24/10/2026 thì hai
                          ô đầu trống, và đó là thông tin đúng: hồ sơ ấy chưa
                          đủ để ra quyết định. Lát 2 cho chúng nói ra vì sao
                          trống thay vì chỉ một dấu gạch ngang.
                        */}
                        <div className="stats-row">
                            <StatBox
                                nhan="Giá thuê"
                                giaTri={moTaGia(screen)}
                                khiTrong="media owner chưa niêm yết"
                            />
                            <StatBox
                                nhan="Impressions/ngày"
                                giaTri={moTaImpressions(screen)}
                                khiTrong="media owner chưa khai số liệu"
                            />
                            <StatBox
                                nhan="Kích thước"
                                giaTri={
                                    screen.size?.width_m && screen.size?.height_m
                                        ? `${screen.size.width_m}×${screen.size.height_m}m`
                                        : null
                                }
                            />
                            <StatBox
                                nhan="SOV tối đa"
                                giaTri={sovThat != null ? `${sovThat}%` : null}
                            />
                        </div>

                        {/*
                          ══ Lát 3: lịch ra khỏi tab thứ ba ══

                          "Đặt được từ khi nào" là câu người mua hỏi TRƯỚC khi
                          quan tâm tới giá, và nó đang nằm sau một cú bấm vào
                          tab thứ ba. Một người sàng mười vị trí sẽ không mở ba
                          tab cho mỗi vị trí.

                          Tab vẫn giữ: nó chứa mức SOV của TỪNG NGÀY — thứ một
                          người đã quyết định mua cần để chọn ngày, và không
                          nhồi được vào một khối gọn.

                          Cùng một `lich`, truyền cho hai khối. Trang gọi
                          `getScreenAvailability()` đúng MỘT lần; để khối tự
                          gọi là hai lượt vào một endpoint có giới hạn tần suất
                          để hiện hai cách nhìn của cùng một dữ liệu.
                        */}
                        <LichGon lich={lich} />

                        <TabChiTiet tabs={tabs} />
                    </div>

                    <div className="detail-sidebar">
                        <PanelGia screen={screen} />
                        <KhoiLienHe screen={screen} />
                    </div>
                </div>
            </div>

            {screen.site?.lat != null && screen.site?.lng != null ? (
                <KhoiViTri
                    lat={screen.site.lat}
                    lng={screen.site.lng}
                    tenSite={screen.site.name ?? ''}
                    diaChi={[screen.site.address, moTaDiaGioi(screen.site.city)]
                        .filter(Boolean)
                        .join(', ')}
                    tenManHinh={screen.name ?? ''}
                    pois={screen.nearby_pois ?? []}
                />
            ) : null}

            {screen.similar?.length ? (
                <div className="similar-section">
                    <div className="w">
                        <div
                            style={{
                                display: 'flex',
                                alignItems: 'flex-start',
                                justifyContent: 'space-between',
                                gap: 16,
                                marginBottom: 20,
                            }}
                        >
                            <div>
                                <div
                                    style={{
                                        fontSize: 13,
                                        fontWeight: 700,
                                        color: 'var(--bl)',
                                        marginBottom: 6,
                                    }}
                                >
                                    GỢI Ý THÊM
                                </div>
                                <h2
                                    style={{
                                        fontSize: 'clamp(20px,3vw,28px)',
                                        fontWeight: 800,
                                        color: 'var(--t1)',
                                        letterSpacing: '-.5px',
                                    }}
                                >
                                    Inventory tương tự
                                </h2>
                            </div>

                            <Link
                                href="/explore"
                                className="btn btn-s btn-sm"
                                style={{ marginTop: 6, flexShrink: 0 }}
                            >
                                Xem tất cả
                            </Link>
                        </div>

                        <div className="inv-grid">
                            {screen.similar.map((s) => (
                                <ScreenCard key={s.slug} screen={s} compact />
                            ))}
                        </div>
                    </div>
                </div>
            ) : null}
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

/**
 * Ô số liệu — và khi trống thì nó nói VÌ SAO trống.
 *
 * `—` một mình không phân biệt được "sàn chưa đo" với "vị trí này bằng không"
 * hay "có lỗi". Trên hai ô quan trọng nhất (giá và impressions), người mua cần
 * biết là chủ vị trí chưa khai, vì đó là thứ họ đòi được.
 *
 * `khiTrong` chỉ hiện khi ô trống, nên nó không chiếm chỗ ở ô có số.
 */
function StatBox({
    nhan,
    giaTri,
    khiTrong,
}: {
    nhan: string;
    giaTri?: string | null;
    khiTrong?: string;
}) {
    const co = Boolean(giaTri);

    return (
        <div className={co ? 'stat-box' : 'stat-box stat-box--trong'}>
            <div className="stat-n">{co ? giaTri : khiTrong ? 'Chưa có' : '—'}</div>
            <div className="stat-l">{nhan}</div>
            {!co && khiTrong ? <div className="stat-src">{khiTrong}</div> : null}
        </div>
    );
}

/**
 * Giá cho ô số liệu — và nó phải nói rõ đang là giá GÌ.
 *
 * Hai mô hình bán nằm trong cùng một trường `pricing`: `io_rate` là thuê trọn
 * theo tháng, `floor_cpm` là giá sàn trên nghìn lượt. Hai con số ấy khác nhau
 * khoảng ba bậc độ lớn, nên in một trong hai mà không ghi đơn vị thì người mua
 * đọc sai mà không có cách nào biết.
 */
function moTaGia(screen: ScreenDetail): string | null {
    const io = screen.pricing?.io_rate;
    if (io?.amount) {
        const donVi = io.unit === 'month' ? '/tháng' : io.unit ? '/' + io.unit : '';
        return dinhDangTien(io.amount, io.currency ?? 'VND') + donVi;
    }

    const cpm = screen.pricing?.floor_cpm;
    if (cpm?.amount) {
        return dinhDangTien(cpm.amount, cpm.currency ?? 'VND') + ' CPM';
    }

    return null;
}

/**
 * Impressions mỗi ngày — `null` khi chưa có, KHÔNG suy ra từ gì khác.
 *
 * `<= 0` cũng trả `null`: số 0 ở đây nghĩa là "chưa đo", không phải "không ai
 * thấy màn hình này". In "0" lên ô số liệu là một khẳng định sai về vị trí.
 */
function moTaImpressions(screen: ScreenDetail): string | null {
    const n = screen.insights?.daily_impressions_est;
    if (typeof n !== 'number' || n <= 0) return null;

    return new Intl.NumberFormat('vi-VN').format(n);
}

function dinhDangTien(so: number, donVi: string): string {
    if (donVi === 'VND') {
        return new Intl.NumberFormat('vi-VN').format(so) + ' ₫';
    }

    return new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(so) + ' ' + donVi;
}

/** Thẻ owner một dòng — biến thể `mini` của `partials/owner-card.blade.php`. */
function OwnerMini({ screen }: { screen: ScreenDetail }) {
    const ten = screen.owner?.name ?? '';
    const chuDau = ten
        .split(/\s+/)
        .slice(0, 2)
        .map((t) => t[0] ?? '')
        .join('')
        .toUpperCase();

    return (
        <Link href={`/owners/${screen.owner?.slug}`} className="oc-mini">
            {screen.owner?.logo_url ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={screen.owner.logo_url} className="oc-mini-logo" alt={ten} />
            ) : (
                <div
                    className="oc-mini-logo oc-mini-logo--txt"
                    style={{ background: 'var(--bl-lt)', color: 'var(--bl)' }}
                >
                    {chuDau}
                </div>
            )}

            <div className="oc-mini-body">
                <div className="oc-mini-name">{ten}</div>
            </div>

            <div className="oc-mini-right">
                {screen.network?.name ? (
                    <span className="oc-mini-extra">{screen.network.name}</span>
                ) : null}
                <svg viewBox="0 0 24 24" fill="var(--t4)" aria-hidden="true" style={{ width: 16, height: 16 }}>
                    <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" />
                </svg>
            </div>
        </Link>
    );
}

/**
 * Bốn thẻ "Điểm nổi bật", nguyên văn `frontpage/detail.blade.php`.
 *
 * In **vô điều kiện cho mọi màn hình** — không đọc trường nào của `screen`.
 * Chủ dự án chốt dựng lại ngày 18/10/2026 sau khi đọc danh sách bốn câu không
 * kiểm được (xem chú thích tại chỗ render, trong `TabThongTin`).
 */
const DIEM_NOI_BAT: { tieuDe: string; phu: string; path: string }[] = [
    {
        tieuDe: 'AI Traffic Data',
        phu: 'Cập nhật theo giờ thực',
        path: 'M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z',
    },
    {
        tieuDe: 'Proof of Play',
        phu: 'Video xác nhận hàng ngày',
        path: 'M20 3h-1V1h-2v2H7V1H5v2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 18H4V8h16v13z',
    },
    {
        tieuDe: 'Verified Location',
        phu: 'Kiểm tra thực địa 2024',
        path: 'M23 12l-2.44-2.79.34-3.69-3.61-.82-1.89-3.2L12 2.96 8.6 1.5 6.71 4.69 3.1 5.5l.34 3.7L1 12l2.44 2.79-.34 3.7 3.61.82 1.89 3.2L12 21.04l3.4 1.46 1.89-3.19 3.61-.82-.34-3.69L23 12zm-12.91 4.72l-3.8-3.81 1.48-1.48 2.32 2.33 5.85-5.87 1.48 1.48-7.33 7.35z',
    },
    {
        tieuDe: 'Fast Booking',
        phu: 'Xác nhận trong 48h',
        path: 'M20.38 8.57l-1.23 1.85a8 8 0 0 1-.22 7.58H5.07A8 8 0 0 1 15.58 6.85l1.85-1.23A10 10 0 0 0 3.35 19a2 2 0 0 0 1.72 1h13.85a2 2 0 0 0 1.74-1 10 10 0 0 0-.27-10.44zm-9.79 6.84a2 2 0 0 0 2.83 0l5.66-8.49-8.49 5.66a2 2 0 0 0 0 2.83z',
    },
];

/** Tab "Thông tin": bảng thông số + mô tả. */
function TabThongTin({ screen }: { screen: ScreenDetail }) {
    const spec = screen.specs;

    const loaiNoiDung = [spec?.allow_image ? 'Ảnh' : null, spec?.allow_video ? 'Video' : null]
        .filter(Boolean)
        .join(', ');

    return (
        <>
            <div className="specs-grid">
                <SpecO nhan="Chủ sở hữu" giaTri={screen.owner?.name} />
                <SpecO nhan="Network" giaTri={screen.network?.name} />
                <SpecO nhan="Site" giaTri={screen.site?.name} />
                <SpecO nhan="Địa điểm" giaTri={spec?.venue_label} />
                <SpecO
                    nhan="Kích thước"
                    giaTri={
                        screen.size?.width_m && screen.size?.height_m
                            ? `${screen.size.width_m} × ${screen.size.height_m} m`
                            : null
                    }
                />
                <SpecO
                    nhan="Độ phân giải"
                    giaTri={
                        spec?.width_px && spec?.height_px
                            ? `${spec.width_px} × ${spec.height_px} px`
                            : null
                    }
                />
                <SpecO nhan="Loại nội dung" giaTri={loaiNoiDung} />
                <SpecO
                    nhan="Floor CPM"
                    giaTri={
                        screen.pricing?.floor_cpm?.amount
                            ? dinhDangTien(
                                  screen.pricing.floor_cpm.amount,
                                  screen.pricing.floor_cpm.currency ?? 'VND',
                              )
                            : null
                    }
                />
                <SpecO nhan="Vị trí" giaTri={screen.site?.address} />
                <SpecO
                    nhan="Tỉnh/Thành"
                    giaTri={moTaDiaGioi(screen.site?.city ?? screen.location?.city) || null}
                />
                <SpecO
                    nhan="Thời lượng QC"
                    giaTri={spec?.spot_length_s ? `${spec.spot_length_s}s` : null}
                />
            </div>

            {screen.description ? (
                <div style={{ marginBottom: 28 }}>
                    <h3
                        style={{
                            fontSize: 18,
                            fontWeight: 700,
                            color: 'var(--t1)',
                            marginBottom: 14,
                            letterSpacing: '-.3px',
                        }}
                    >
                        Mô tả
                    </h3>
                    <div className="desc">
                        <p>{screen.description}</p>
                    </div>
                </div>
            ) : null}

            {/*
              Khối "Điểm nổi bật", nguyên văn bản Blade. Chủ dự án chốt dựng
              lại ngày 18/10/2026.

              Bốn câu này không câu nào kiểm được, ghi lại ở đúng chỗ người sửa
              sau sẽ đọc: không có tính năng AI nào trong mã; `impression_logs`
              chưa có bản ghi nào nên không có proof-of-play; "kiểm tra thực
              địa 2024" là một khẳng định về quy trình, không phải một cột
              trong CSDL; và "48h" là cam kết vận hành.

              Chúng in **vô điều kiện cho mọi màn hình** — không đọc trường nào
              của `screen`.
            */}
            <div style={{ marginBottom: 28 }}>
                <h3
                    style={{
                        fontSize: 18,
                        fontWeight: 700,
                        color: 'var(--t1)',
                        marginBottom: 16,
                        letterSpacing: '-.3px',
                    }}
                >
                    Điểm nổi bật
                </h3>

                <div className="hl-grid">
                    {DIEM_NOI_BAT.map((the) => (
                        <div className="hl-card" key={the.tieuDe}>
                            <div className="hl-icon">
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="var(--bl)"
                                    aria-hidden="true"
                                    style={{ width: 18, height: 18, flexShrink: 0 }}
                                >
                                    <path d={the.path} />
                                </svg>
                            </div>
                            <div>
                                <div className="hl-title">{the.tieuDe}</div>
                                <div className="hl-sub">{the.phu}</div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

function SpecO({ nhan, giaTri }: { nhan: string; giaTri?: string | number | null }) {
    return (
        <div className="sc">
            <div className="sc-l">{nhan}</div>
            <div className="sc-v">{giaTri || '—'}</div>
        </div>
    );
}

/** Tab "Đánh giá" — đúng trạng thái đang bật của bản Blade. */
function TabDanhGia() {
    return (
        <div style={{ textAlign: 'center', padding: '48px 20px', color: 'var(--t4)' }}>
            <svg
                viewBox="0 0 24 24"
                fill="var(--ln2)"
                aria-hidden="true"
                style={{ width: 48, height: 48, margin: '0 auto 16px', display: 'block' }}
            >
                <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" />
            </svg>
            <div style={{ fontSize: 16, fontWeight: 700, color: 'var(--t3)', marginBottom: 6 }}>
                Chưa có đánh giá
            </div>
            <div style={{ fontSize: 13 }}>
                Đánh giá sẽ hiển thị sau khi có booking được hoàn thành.
            </div>
        </div>
    );
}

/** Khối liên hệ media owner ở cột phải. */
function KhoiLienHe({ screen }: { screen: ScreenDetail }) {
    const ten = screen.owner?.name ?? 'OOHX';
    const dienThoai = screen.owner?.phone;
    const email = screen.owner?.email;

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
            <div style={{ fontSize: 14, fontWeight: 700, color: 'var(--t1)', marginBottom: 4 }}>
                Liên hệ {ten}
            </div>
            <div style={{ fontSize: 12, color: 'var(--t4)', marginBottom: 12 }}>
                Media owner quản lý vị trí này
            </div>

            <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
                {dienThoai ? (
                    <a href={`tel:${dienThoai}`} className="btn btn-s" style={nutLienHe}>
                        Gọi: {dienThoai}
                    </a>
                ) : null}

                {email ? (
                    <a href={`mailto:${email}`} className="btn btn-s" style={nutLienHe}>
                        Email: {email}
                    </a>
                ) : null}

                {!dienThoai && !email ? (
                    <a href="mailto:hi@oohx.net" className="btn btn-s" style={nutLienHe}>
                        Liên hệ OOHX: hi@oohx.net
                    </a>
                ) : null}
            </div>

            {screen.owner?.slug ? (
                <Link
                    href={`/owners/${screen.owner.slug}`}
                    style={{
                        display: 'block',
                        marginTop: 10,
                        fontSize: 12,
                        color: 'var(--bl)',
                        fontWeight: 600,
                    }}
                >
                    Xem hồ sơ {ten} →
                </Link>
            ) : null}
        </div>
    );
}

const nutLienHe = {
    justifyContent: 'flex-start' as const,
    gap: 10,
    borderRadius: 10,
    height: 44,
    fontWeight: 600,
    textDecoration: 'none',
};

/**
 * Mô tả dùng cho cả `<meta name="description">` và JSON-LD.
 *
 * Một hàm, hai chỗ dùng: bản Blade dựng hai chuỗi gần giống nhau ở hai dòng
 * khác nhau, và chúng đã lệch — `seoDescription` có giá, `description` trong
 * JSON-LD thì không. Lệch thì không ai thấy, vì cả hai đều hợp lệ.
 */
function describe(screen: ScreenDetail): string {
    // `screen.name` giữ nguyên ở đây: đó là thứ công cụ tìm kiếm đã lập chỉ mục
    // cho URL này. Nhưng chuỗi địa giới thì tách — một dấu `>` giữa câu mô tả
    // là ký tự kỹ thuật của dữ liệu đi ra tới kết quả tìm kiếm.
    const parts = [
        screen.name,
        screen.site?.name ?? screen.location?.site,
        moTaDiaGioi(screen.location?.city) || null,
    ].filter(Boolean);

    return parts.join(', ') + '.';
}
