import type { Metadata } from 'next';
import { getFilters, getLocations, type Filters, type Region } from '@/lib/api';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { ScreenMap } from '@/components/ScreenMap';

/**
 * `/map` — bản đồ màn hình.
 *
 * ══ Khung trang render ở máy chủ, bản đồ ở trình duyệt ══
 *
 * Thẻ SEO, thanh điều hướng và dữ liệu bộ lọc nằm trong HTML máy chủ trả về.
 * Bản đồ là client component vì Leaflet cần DOM.
 *
 * Trang này **không** có danh sách màn hình dạng chữ trong HTML máy chủ render
 * — panel trái điền sau khi bản đồ hỏi pin của khung nhìn. Giống bản Blade ở
 * chỗ quan trọng: `/explore` là trang để index, `/map` là công cụ để duyệt.
 * Hai trang cùng dữ liệu mà cùng được index là tự tạo nội dung trùng.
 *
 * Vì vậy không có JSON-LD ở đây: không có thực thể nào để khai mà `/explore`
 * chưa khai rồi.
 *
 * ══ Lát 6: bộ lọc, danh sách, thanh thành phố, nút thu phóng ══
 *
 * Trước lát này trang chỉ có bản đồ và một dòng đếm. Bản Blade có panel trái
 * (bộ lọc thông minh + danh sách gom theo site), thanh chọn tỉnh thành có
 * dropdown "Khác", ba nút thu phóng, bộ đếm nổi và popup riêng.
 *
 * ══ `hideFooter` của bản Blade không dựng lại ══
 *
 * Layout Blade nhận `hideFooter => true` cho trang này vì bố cục chiếm trọn
 * màn hình. Ở bản Next, chân trang nằm trong `app/layout.tsx` dùng chung; ẩn
 * nó cho đúng một đường dẫn là thêm một nhánh điều kiện vào layout của mọi
 * trang. `.map-layout` có chiều cao riêng nên chân trang chỉ nằm bên dưới,
 * không đè lên gì.
 */

export const metadata: Metadata = buildMetadata({
    title: 'Bản đồ màn hình OOH/DOOH | OOHX',
    description:
        'Xem vị trí màn hình quảng cáo ngoài trời trên bản đồ Việt Nam. ' +
        'Lọc theo thành phố, loại điểm đặt và mạng lưới.',
    path: '/map',
});

type SearchParams = {
    q?: string;
    city?: string;
    venue_type?: string;
    screen_type?: string;
    network?: string;
    owner?: string;
};

export default async function MapPage({
    searchParams,
}: {
    searchParams: Promise<SearchParams>;
}) {
    const params = await searchParams;

    /*
     * `allSettled`: bộ lọc và danh sách tỉnh thành chỉ nuôi khung điều khiển.
     * Một trong hai hỏng thì bản đồ vẫn chạy với chip rỗng — trang trắng thì
     * không. Pin thì bản đồ tự hỏi sau khi biết khung nhìn.
     */
    const [boLoc, vungMien] = await Promise.allSettled([getFilters(), getLocations()]);

    const filters: Filters | null = boLoc.status === 'fulfilled' ? boLoc.value : null;
    const regions: Region[] = vungMien.status === 'fulfilled' ? vungMien.value : [];

    return (
        <>
            <SiteHeader active="map" />
            <OgType type="website" />

            <main>
                {/*
                  Không `pg-hero` ở đây, khác các trang danh mục: bản Blade cho
                  bản đồ chiếm trọn khung nhìn ngay dưới thanh điều hướng, và
                  một khối tiêu đề cao 120px đẩy bản đồ xuống dưới nếp gấp.
                  `h1` vẫn có — cho công cụ tìm kiếm và trình đọc màn hình —
                  nhưng ẩn khỏi mắt.
                */}
                <h1 className="visually-hidden">Bản đồ màn hình quảng cáo ngoài trời</h1>

                <ScreenMap filters={params} regions={regions} boLoc={filters} />
            </main>
        </>
    );
}
