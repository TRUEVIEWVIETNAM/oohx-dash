import type { Metadata } from 'next';
import Link from 'next/link';
import { listScreens, getFilters, getLocations, type Filters, type Region } from '@/lib/api';
import { MegaSearch } from '@/components/MegaSearch';
import { buildMetadata, OgType } from '@/lib/seo';
import { ScreenCard } from '@/components/ScreenCard';
import { SiteHeader } from '@/components/SiteHeader';
import { Pagination } from '@/components/Pagination';
import { KhoiKetQua } from '@/components/KhoiKetQua';

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
    sort?: string;
};

export default async function ExplorePage({
    searchParams,
}: {
    searchParams: Promise<SearchParams>;
}) {
    const params = await searchParams;

    const page = Math.max(1, Number.parseInt(params.page ?? '1', 10) || 1);

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

            <div className="ex-hero">
                <div className="w">
                    <div className="ex-top">
                        <h1 className="ex-title">Khám phá Inventory</h1>

                        {/*
                          Nút sang bản đồ, đúng chỗ bản Blade đặt nó. Hai trang
                          nhìn cùng một kho theo hai cách, nên mỗi bên phải có
                          đường sang bên kia; thiếu nó thì `/map` chỉ đến được
                          từ thanh điều hướng.
                        */}
                        <Link href="/map" className="ph-map-btn" aria-label="Xem kho trên bản đồ">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z" />
                            </svg>
                            <span className="ph-map-btn-txt">Bản đồ</span>
                        </Link>
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

            <div className="w ex-results">
                <KhoiKetQua
                    tong={meta.total ?? 0}
                    sort={params.sort ?? ''}
                    basePath="/explore"
                    query={params}
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
                        screens.map((screen) => <ScreenCard key={screen.slug} screen={screen} />)
                    )}
                </KhoiKetQua>

                <Pagination basePath="/explore" params={params} meta={meta} />
                </div>
            </main>
        </>
    );
}
