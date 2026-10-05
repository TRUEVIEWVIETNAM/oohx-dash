import type { components, paths } from '@api-types';

/**
 * Dữ liệu cho trang chủ, lấy song song từ năm endpoint.
 *
 * ══ Năm lời gọi, không một endpoint `/home` ══
 *
 * Cân nhắc đã bỏ: gộp tất cả vào `GET /api/v2/home`. Nó gọn hơn một lần, rồi
 * thành một endpoint hình dạng theo MỘT trang — đổi layout là đổi hợp đồng
 * API, và endpoint đó không dùng lại được ở đâu khác.
 *
 * Năm lời gọi riêng thì mỗi cái **cache theo nhịp của nó**: số liệu tổng quan
 * đổi theo ngày, danh sách tỉnh thành đổi theo tháng. Một `revalidate` chung
 * sẽ sai cho cả hai. Và chúng chạy song song, nên chi phí là lời gọi chậm
 * nhất, không phải tổng.
 *
 * ══ Một endpoint hỏng không làm trắng cả trang ══
 *
 * `Promise.allSettled`, không `Promise.all`. Trang chủ có bảy mục độc lập; để
 * mục "tỉnh thành" hỏng làm mất cả trang là biến một lỗi nhỏ thành một lỗi
 * toàn phần. Mục nào không có dữ liệu thì không render — và vì không mục nào
 * bịa số, thiếu dữ liệu là thiếu một khối, không phải một con số sai.
 */

import { PUBLIC_ORIGIN } from './api';

const BASE = (process.env.OOHX_API_BASE ?? 'https://oohx.net/api/v2').replace(/\/+$/, '');

export type HeroStats =
    paths['/api/v2/stats']['get']['responses'][200]['content']['application/json']['data'];

export type FeaturedScreen =
    paths['/api/v2/screens/featured']['get']['responses'][200]['content']['application/json']['data'][number];

export type Region = components['schemas']['Region'];
export type MapPin = components['schemas']['MapPin'];
export type OwnerSummary = components['schemas']['OwnerSummary'];
export type Filters =
    paths['/api/v2/filters']['get']['responses'][200]['content']['application/json']['data'];

async function get<T>(path: string, revalidate: number): Promise<T> {
    const response = await fetch(BASE + path, {
        headers: { Accept: 'application/json' },
        next: { revalidate },
    });

    if (!response.ok) {
        throw new Error(`API v2 trả ${response.status} cho ${path}`);
    }

    return (await response.json()) as T;
}

export type HomeData = {
    stats: HeroStats | null;
    featuredScreens: FeaturedScreen[];
    featuredOwners: OwnerSummary[];
    regions: Region[];
    pins: MapPin[];
    filters: Filters | null;
};

/** Thành phố mặc định cho bản đồ nhỏ. Cùng mặc định với bản Blade. */
export const DEFAULT_CITY = 'hanoi';

export async function getHomeData(city: string = DEFAULT_CITY): Promise<HomeData> {
    const [stats, screens, owners, regions, pins, filters] = await Promise.allSettled([
        // Số liệu tổng quan: đổi khi có màn hình mới được duyệt, nên 5 phút.
        get<{ data: HeroStats }>('/stats', 300),

        // Màn hình nổi bật: truy vấn có `inRandomOrder()` và cache 15 phút ở
        // tầng service, nên `revalidate` ngắn hơn không cho dữ liệu mới hơn.
        get<{ data: FeaturedScreen[] }>('/screens/featured?limit=4', 900),

        get<{ data: OwnerSummary[] }>('/owners/featured?limit=6', 900),

        // Tỉnh thành theo vùng: đổi khi có tỉnh mới có màn hình, tức rất ít.
        get<{ data: Region[] }>('/locations', 1800),

        get<{ data: MapPin[] }>(`/screens/pins?city=${encodeURIComponent(city)}&limit=50`, 600),

        get<{ data: Filters }>('/filters', 900),
    ]);

    return {
        stats: stats.status === 'fulfilled' ? stats.value.data : null,
        featuredScreens: screens.status === 'fulfilled' ? screens.value.data : [],
        featuredOwners: owners.status === 'fulfilled' ? owners.value.data : [],
        regions: regions.status === 'fulfilled' ? regions.value.data : [],
        pins: pins.status === 'fulfilled' ? pins.value.data : [],
        filters: filters.status === 'fulfilled' ? filters.value.data : null,
    };
}

export { PUBLIC_ORIGIN };
