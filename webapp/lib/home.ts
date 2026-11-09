import type { components, paths } from '@api-types';

/**
 * Dữ liệu cho trang chủ, lấy song song từ bảy endpoint.
 *
 * Từng là tám. Lời gọi thứ tám — `/screens/featured?limit=4`, nuôi lưới "Vị
 * trí nổi bật" — gỡ ở lát 3 (18/10/2026) cùng với lưới đó: truy vấn đòi màn
 * hình có ảnh RIÊNG và có giá, và đo trên production 05/10/2026 thì chỉ 1
 * trên 104 màn hình thoả cả hai. Một lưới một thẻ không nói được gì về kho,
 * nên cả mục lẫn lời gọi nuôi nó đều đi.
 *
 * ══ Bảy lời gọi, không một endpoint `/home` ══
 *
 * Cân nhắc đã bỏ: gộp tất cả vào `GET /api/v2/home`. Nó gọn hơn một lần, rồi
 * thành một endpoint hình dạng theo MỘT trang — đổi layout là đổi hợp đồng
 * API, và endpoint đó không dùng lại được ở đâu khác.
 *
 * Bảy lời gọi riêng thì mỗi cái **cache theo nhịp của nó**: số liệu tổng quan
 * đổi theo ngày, danh sách tỉnh thành đổi theo tháng. Một `revalidate` chung
 * sẽ sai cho cả hai. Và chúng chạy song song, nên chi phí là lời gọi chậm
 * nhất, không phải tổng.
 *
 * ══ Một endpoint hỏng không làm trắng cả trang ══
 *
 * `Promise.allSettled`, không `Promise.all`. Trang chủ có nhiều mục độc lập; để
 * mục "tỉnh thành" hỏng làm mất cả trang là biến một lỗi nhỏ thành một lỗi
 * toàn phần. Mục nào không có dữ liệu thì không render — và vì không mục nào
 * bịa số, thiếu dữ liệu là thiếu một khối, không phải một con số sai.
 */

import { PUBLIC_ORIGIN } from './api';

const BASE = (process.env.OOHX_API_BASE ?? 'https://oohx.net/api/v2').replace(/\/+$/, '');

export type HeroStats =
    paths['/api/v2/stats']['get']['responses'][200]['content']['application/json']['data'];

export type Region = components['schemas']['Region'];
export type MapPin = components['schemas']['MapPin'];
export type OwnerSummary = components['schemas']['OwnerSummary'];
export type Filters =
    paths['/api/v2/filters']['get']['responses'][200]['content']['application/json']['data'];

/**
 * Hai lưới thẻ 'duyệt': loại địa điểm và tỉnh thành, kèm ảnh.
 *
 * Dẫn xuất từ `paths` chứ không viết tay — hai khóa có hình dạng khác nhau,
 * và khai lại bằng tay là bản thứ hai của một hợp đồng đã có.
 */
export type Browse =
    paths['/api/v2/browse']['get']['responses'][200]['content']['application/json']['data'];

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

export type ProductSummary = components['schemas']['ProductSummary'];

export type ScreenSummary = components['schemas']['ScreenSummary'];

export type HomeData = {
    stats: HeroStats | null;
    featuredOwners: OwnerSummary[];
    regions: Region[];
    pins: MapPin[];
    filters: Filters | null;
    products: ProductSummary[];
    browse: Browse | null;

    /**
     * Vị trí **đã công bố giá** — lát 1 của `docs/PLAN-GHEP-MOCKUP.md`.
     *
     * ══ Vì sao không dùng `/screens/featured` ══
     *
     * Endpoint đó là nguồn hiển nhiên và nó **trả đúng 1 bản ghi**. Đo ngày
     * 08/11/2026: `getFeaturedScreens()` lọc vị trí công khai *có ảnh riêng*
     * **và** *có `floor_cpm > 0`*, và kho hiện chỉ có 2 ảnh riêng biệt (đều là
     * ảnh bìa của owner) nên gần như không bản ghi nào qua được cả hai điều
     * kiện.
     *
     * Một lưới sáu ô mà dữ liệu chỉ đủ một ô là một lưới sẽ được ai đó "sửa"
     * bằng cách nới điều kiện lọc cho đủ số — tức bày ra vị trí chưa có giá ở
     * một khối tên là "đã công bố giá".
     *
     * `?price_status=quoted` hỏi đúng câu khối này hỏi: **19/104** vị trí có
     * giá niêm yết. Sáu cái đầu là sáu cái thật, và con số 19 cũng thật nên
     * dòng "còn 85 vị trí chưa có giá" dưới lưới đọc được.
     *
     * **Không** sắp theo giá: `sort=price_desc` không có tác dụng trên endpoint
     * này (đo cùng ngày — thứ tự trả về không giảm dần). Nên khối này không
     * tuyên bố một thứ tự nào, và cũng không được đặt tên là "hot": sàn chưa có
     * dữ liệu lượt xem hay lượt đặt để xếp hạng (`docs/PLAN-LUOT-XEM.md`).
     */
    quotedScreens: ScreenSummary[];

    /** Tổng số vị trí **đã** có giá, để đối chiếu với tổng kho. */
    quotedTotal: number;

    /**
     * Tổng số gói đang mở — cho tab phạm vi thứ hai ở hero (09/11/2026).
     *
     * `/stats` trả `total_screens` và `total_owners` nhưng **không** trả số
     * gói, nên con số này đọc `meta.total` của `/products`.
     *
     * `?? 0`, không `?? products.length`: `products` là **trang đầu ba bản
     * ghi** (`per_page=3`) cho mục "Gói chiến dịch". Lấy độ dài trang thay
     * tổng sẽ in "Gói 3" trong khi sàn có 1 — một con số sai trông hợp lý.
     * Cùng cái bẫy đã ghi ở `quotedTotal`.
     */
    productsTotal: number;
};

/** Thành phố mặc định cho bản đồ nhỏ. Cùng mặc định với bản Blade. */
export const DEFAULT_CITY = 'hanoi';

type TrangManHinh = { data: ScreenSummary[]; meta?: { total?: number } };

export async function getHomeData(city: string = DEFAULT_CITY): Promise<HomeData> {
    const [stats, owners, regions, pins, filters, products, browse, quoted] = await Promise.allSettled([
        // Số liệu tổng quan: đổi khi có màn hình mới được duyệt, nên 5 phút.
        get<{ data: HeroStats }>('/stats', 300),

        get<{ data: OwnerSummary[] }>('/owners/featured?limit=6', 900),

        // Tỉnh thành theo vùng: đổi khi có tỉnh mới có màn hình, tức rất ít.
        get<{ data: Region[] }>('/locations', 1800),

        get<{ data: MapPin[] }>(`/screens/pins?city=${encodeURIComponent(city)}&limit=50`, 600),

        get<{ data: Filters }>('/filters', 900),

        // Mục "Gói chiến dịch" của bản Blade là ba thẻ viết cứng trong
        // template, và chú thích ở đó đã nêu nghi vấn: không xác nhận được ba
        // tên gói ấy ứng với bản ghi nào. Nên mục này đọc **gói thật** —
        // cùng dữ liệu `/products` đang phục vụ, nên bấm vào là tới đúng trang.
        get<{ data: ProductSummary[]; meta?: { total?: number } }>('/products?per_page=3', 900),

        // Hai lưới thẻ "duyệt". Đổi khi có loại địa điểm hoặc tỉnh thành mới
        // có màn hình — tức rất ít, nên 30 phút.
        get<{ data: Browse }>('/browse', 1800),

        // Vị trí đã công bố giá — xem docblock của `quotedScreens`. 5 phút,
        // cùng nhịp với `/stats`: giá là thứ media owner đổi được bất cứ lúc
        // nào, và khối này in giá ra trang chủ.
        get<TrangManHinh>('/screens?price_status=quoted&per_page=6', 300),
    ]);

    return {
        stats: stats.status === 'fulfilled' ? stats.value.data : null,
        featuredOwners: owners.status === 'fulfilled' ? owners.value.data : [],
        regions: regions.status === 'fulfilled' ? regions.value.data : [],
        pins: pins.status === 'fulfilled' ? pins.value.data : [],
        filters: filters.status === 'fulfilled' ? filters.value.data : null,
        products: products.status === 'fulfilled' ? products.value.data : [],
        browse: browse.status === 'fulfilled' ? browse.value.data : null,
        quotedScreens: quoted.status === 'fulfilled' ? quoted.value.data : [],

        // `?? 0` chứ không `?? quotedScreens.length`: hụt `meta.total` nghĩa là
        // **không biết** tổng, và lấy số bản ghi của trang đầu thay nó sẽ in
        // "6 vị trí có giá" cạnh "104 vị trí" — một con số sai trông hợp lý.
        // 0 làm khối tự ẩn dòng đối chiếu, xem `page.tsx`.
        quotedTotal: quoted.status === 'fulfilled' ? (quoted.value.meta?.total ?? 0) : 0,

        // Cùng luật `?? 0` như `quotedTotal` — xem docblock của `productsTotal`.
        productsTotal: products.status === 'fulfilled' ? (products.value.meta?.total ?? 0) : 0,
    };
}

export { PUBLIC_ORIGIN };
