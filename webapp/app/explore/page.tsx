import type { Metadata } from 'next';
import Link from 'next/link';
import { listScreens } from '@/lib/api';
import { buildMetadata, OgType } from '@/lib/seo';
import { ScreenCard } from '@/components/ScreenCard';
import { SiteHeader } from '@/components/SiteHeader';
import { Pagination } from '@/components/Pagination';

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

    const { data: screens, meta } = await listScreens({
        page,
        per_page: PER_PAGE,
        q: params.q,
        city: params.city,
        venue_type: params.venue_type,
        screen_type: params.screen_type,
        sort: params.sort,
    });

    return (
        <>
            <SiteHeader active="explore" />
            <OgType type="website" />

            <main>

            <div className="ex-hero">
                <div className="w">
                    <div className="ex-top">
                        <div>
                            <h1>Khám phá inventory</h1>
                            <p>
                                {new Intl.NumberFormat('vi-VN').format(meta.total ?? 0)} vị trí
                                OOH/DOOH trên toàn quốc.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div className="w">
                {screens.length === 0 ? (
                    <div style={{ padding: '64px 20px', textAlign: 'center', color: 'var(--t4)' }}>
                        Không có vị trí nào khớp bộ lọc.
                    </div>
                ) : (
                    <div className="oc-grid oc-grid--full">
                        {screens.map((screen) => (
                            <ScreenCard key={screen.slug} screen={screen} />
                        ))}
                    </div>
                )}

                <Pagination basePath="/explore" params={params} meta={meta} />
                </div>
            </main>
        </>
    );
}
