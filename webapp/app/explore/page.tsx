import type { Metadata } from 'next';
import { listScreens, getFilters, getLocations, type Filters, type Region } from '@/lib/api';
import { MegaSearch } from '@/components/MegaSearch';
import { buildMetadata, OgType } from '@/lib/seo';
import { ScreenCard } from '@/components/ScreenCard';
import { BoLocKho } from '@/components/BoLocKho';
import { SiteHeader } from '@/components/SiteHeader';
import { Pagination } from '@/components/Pagination';
import { KhoiExplore } from '@/components/KhoiExplore';

/**
 * `/explore` — danh sách màn hình. Đường dẫn ĐẦU TIÊN chuyển sang Next.js.
 *
 * Lộ trình chọn đường này trước vì nó là trang danh mục nặng nhất và được
 * index nhiều nhất, nhưng không nhận tham số nào phức tạp ngoài bộ lọc.
 *
 * ══ Server Component, không `use client` ══
 *
 * Trang này phải render ở máy chủ: công cụ tìm kiếm cần thấy nội dung trong
 * HTML, và đó là toàn bộ lý do giai đoạn 6 tồn tại. Thêm `use client` vào đây
 * là bỏ mất chính thứ đang đi tìm.
 *
 * ══ Lọc bằng URL, không bằng state ══
 *
 * Mỗi bộ lọc là một URL riêng, nên chia sẻ được, quay lại được, và index được
 * — giống hệt bản Blade. Giữ dạng tham số **một khóa phân tách bằng dấu phẩy**
 * (`city=hanoi,hcm`) vì đó là hợp đồng đã khai trong `docs/openapi/v2.yaml`
 * (`style: form, explode: false`), và PHP không ghép khóa lặp thành mảng.
 */

const PER_PAGE = 24;

export const metadata: Metadata = buildMetadata({
    title: 'Khám phá OOH/DOOH Inventory | OOHX',
    path: '/explore',
});

type SearchParams = {
    page?: string;
    q?: string;
    city?: string;
    venue_type?: string;
    screen_type?: string;
    owner?: string;
    /**
     * `quoted` / `unquoted`, phân tách bằng dấu phẩy.
     *
     * Thêm 19/10/2026 cùng cột lọc. Nó thay chỗ của thanh lọc giá, vốn không
     * lọc được gì: `/api/v2/filters` trả `price_range {min: 0, max: 0}` vì
     * hầu hết kho chưa báo giá.
     */
    price_status?: string;
    sort?: string;

    /**
     * `map` để xem kiểu bản đồ; mọi giá trị khác (kể cả thiếu) là kiểu lưới.
     *
     * Thêm 22/10/2026 khi `/explore` và `/map` gộp làm một — lát 2 của
     * `docs/PLAN-GOP-EXPLORE-BAN-DO.md`. Kiểu xem là một URL chứ không phải
     * một state, nên nó chia sẻ được và nút Back quay lại được.
     */
    view?: string;
};

/**
 * Kiểu xem khi URL không nói gì.
 *
 * **Lưới**, và lý do là người đến từ công cụ tìm kiếm: kiểu lưới mang theo
 * phân trang và chân trang — những đường duy nhất để Google bò sang trang 2,
 * 3 và các trang chính sách — còn kiểu bản đồ tải Leaflet, tile và tới 500
 * pin ngay lần đầu, kể cả trên 3G.
 *
 * Đổi mặc định là đổi đúng dòng này.
 */
const KIEU_XEM_MAC_DINH = 'grid' as const;

export default async function ExplorePage({
    searchParams,
}: {
    searchParams: Promise<SearchParams>;
}) {
    const params = await searchParams;

    const page = Math.max(1, Number.parseInt(params.page ?? '1', 10) || 1);

    const kieuXem = params.view === 'map' ? 'map' : KIEU_XEM_MAC_DINH;

    /*
     * Ba lời gọi song song, và `allSettled` chứ không `all`.
     *
     * Danh sách là nội dung của trang; hai cái sau chỉ nuôi ô tìm kiếm.
     * Để `/locations` hỏng làm trắng cả trang danh mục là biến một lỗi nhỏ
     * thành một lỗi toàn phần — cùng lý do `getHomeData()` chọn
     * `allSettled`. Thiếu dữ liệu thì dropdown rỗng, không phải trang trắng.
     *
     * Nhưng danh sách thì KHÔNG được lặng lẽ rỗng: lỗi của nó phải nổi lên
     * để Next trả 500, thay vì một trang nói "không tìm thấy inventory" khi
     * thật ra API không trả lời được.
     */
    const [ketQua, vungMien, boLoc] = await Promise.allSettled([
        listScreens({
            page,
            per_page: PER_PAGE,
            q: params.q,
            city: params.city,
            venue_type: params.venue_type,
            screen_type: params.screen_type,
            owner: params.owner,
            price_status: params.price_status,
            sort: params.sort,
        }),
        getLocations(),
        getFilters(),
    ]);

    if (ketQua.status === 'rejected') throw ketQua.reason;

    const { data: screens, meta } = ketQua.value;
    const regions: Region[] = vungMien.status === 'fulfilled' ? vungMien.value : [];
    const filters: Filters | null = boLoc.status === 'fulfilled' ? boLoc.value : null;

    return (
        <>
            <SiteHeader active="explore" />
            <OgType type="website" />

            <main>

            {/*
              Hero chỉ có ở kiểu lưới.

              Kiểu bản đồ cần cả chiều cao khung nhìn cho bản đồ; một khối
              tiêu đề 120px đẩy nó xuống dưới nếp gấp, đúng lý do bản Blade
              cho `/map` bỏ hero. Nhưng `h1` vẫn phải có cho công cụ tìm kiếm
              và trình đọc màn hình, nên nó ẩn khỏi mắt chứ không biến mất.
            */}
            {kieuXem === 'map' ? (
                <h1 className="visually-hidden">Khám phá Inventory trên bản đồ</h1>
            ) : (
            <div className="ex-hero">
                <div className="w">
                    <div className="ex-top">
                        <h1 className="ex-title">Khám phá Inventory</h1>

                        {/*
                          Nút `ph-map-btn` sang `/map` gỡ ngày 22/10/2026.
                          Bản đồ không còn là một trang khác để sang: nó là
                          kiểu xem thứ hai của chính trang này, và hai liên
                          kết đổi kiểu xem nằm trong thanh kết quả — đúng chỗ
                          người dùng đang nhìn danh sách.

                          Giữ cả hai là có hai cái nút cùng làm một việc, và
                          một trong hai sẽ trôi khỏi cái kia.
                        */}
                    </div>
                    {/*
                      Ô tìm kiếm lớn, biến thể `compact` — đúng chỗ bản Blade
                      đặt nó (`listing.blade.php` gọi partial với
                      `'variant' => 'compact'`).

                      Trước lát 3, trang này đọc `city`, `venue_type`,
                      `screen_type`, `sort` từ URL rồi vẽ lưới mà **không có
                      bộ lọc nào hiện ra** — và thông báo rỗng vẫn viết "Không
                      tìm thấy inventory phù hợp", tức mời người dùng đổi một
                      bộ lọc không tồn tại trên trang.

                      `variant="compact"` khác `hero` ở đúng hai chỗ: không
                      chip gợi ý, và `ms-wrap` không mang thêm `ms-hero`
                      (`max-width` 820px thay vì 760px). Không có `.ms-compact`
                      trong CSS — ở bản Blade nó là một class rỗng.
                    */}
                    <MegaSearch
                        variant="compact"
                        target="/explore"
                        regions={regions}
                        categories={filters?.categories_networks ?? []}
                        params={params}
                    />
                </div>
            </div>
            )}

            {/*
              Một khung, hai kiểu xem — `KhoiExplore` dựng lớp bọc cho cả hai.
              Kiểu lưới: dải chip lọc trên một lưới rộng hết bọc (4 thẻ 280px
              từ 1200px). Kiểu bản đồ: canvas trải hết khung nhìn, danh sách
              là thẻ nổi 400px bên trái, cùng dải chip đó ở đầu panel.

              Thẻ do **máy chủ** dựng ở cả hai kiểu và đi xuống qua
              `children`. Đó là hàng rào của việc gộp: `/map` cố ý không có
              danh sách trong HTML, và nếu trang gộp cũng thế thì trang danh
              mục nặng nhất của sàn biến mất khỏi công cụ tìm kiếm.
            */}
                <KhoiExplore
                    kieuXem={kieuXem}
                    tong={meta.total ?? 0}
                    sort={params.sort ?? ''}
                    query={params}
                    boLoc={<BoLocKho filters={filters} params={params} />}
                    oTim={
                        kieuXem === 'map' ? (
                            <MegaSearch
                                variant="compact"
                                target="/explore"
                                regions={regions}
                                categories={filters?.categories_networks ?? []}
                                params={params}
                            />
                        ) : null
                    }
                    pagination={<Pagination basePath="/explore" params={params} meta={meta} />}
                >
                    {screens.length === 0 ? (
                        <div
                            style={{
                                gridColumn: '1/-1',
                                textAlign: 'center',
                                padding: '60px 0',
                                color: 'var(--t4)',
                            }}
                        >
                            Không tìm thấy inventory phù hợp
                        </div>
                    ) : (
                        screens.map((screen) => (
                            <ScreenCard key={screen.slug} screen={screen} choPhepChon />
                        ))
                    )}
                </KhoiExplore>
            </main>
        </>
    );
}
