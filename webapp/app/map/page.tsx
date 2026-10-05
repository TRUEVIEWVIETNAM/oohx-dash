import type { Metadata } from 'next';
import { buildMetadata, OgType } from '@/lib/seo';
import { SiteHeader } from '@/components/SiteHeader';
import { ScreenMap } from '@/components/ScreenMap';

/**
 * `/map` — bản đồ màn hình.
 *
 * ══ Khung trang render ở máy chủ, bản đồ ở trình duyệt ══
 *
 * Tiêu đề, mô tả, thẻ SEO và thanh điều hướng nằm trong HTML máy chủ trả về.
 * Chỉ bản đồ là client component, vì Leaflet cần DOM.
 *
 * Trang này **không** có danh sách màn hình dạng chữ trong HTML, nên công cụ
 * tìm kiếm không đọc được nội dung kho từ đây — giống bản Blade, và đó là đúng:
 * `/explore` là trang để index, `/map` là công cụ để duyệt. Hai trang cùng dữ
 * liệu mà cùng được index là tự tạo nội dung trùng.
 *
 * Vì vậy không có JSON-LD ở đây: không có thực thể nào để khai mà `/explore`
 * chưa khai rồi.
 */

export const metadata: Metadata = buildMetadata({
    title: 'Bản đồ màn hình OOH/DOOH | OOHX',
    description:
        'Xem vị trí màn hình quảng cáo ngoài trời trên bản đồ Việt Nam. ' +
        'Lọc theo thành phố, loại điểm đặt và mạng lưới.',
    path: '/map',
});

type SearchParams = {
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
    const filters = await searchParams;

    return (
        <>
            <SiteHeader active="map" />
            <OgType type="website" />

            <main>
                <div className="pg-hero">
                    <div className="w">
                        <h1>Bản đồ màn hình</h1>
                        <p>
                            Kéo và thu phóng để xem màn hình trong từng vùng. Bấm một điểm để xem
                            thông tin và mở trang chi tiết.
                        </p>
                    </div>
                </div>

                <div className="w">
                    <ScreenMap filters={filters} />
                </div>
            </main>
        </>
    );
}
