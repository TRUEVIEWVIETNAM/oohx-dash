import type { components, paths } from '@api-types';

/**
 * Lớp gọi `/api/v2`.
 *
 * ══ Kiểu dữ liệu không viết tay ══
 *
 * Mọi kiểu ở đây lấy từ `@api-types`, sinh từ `docs/openapi/v2.yaml` bằng
 * `npm run types:api` ở gốc repo. CLAUDE.md mục 2: OpenAPI là nguồn sự thật,
 * Next.js sinh TypeScript từ đó chứ không chép tay.
 *
 * Nghĩa là: đổi đặc tả mà quên đổi code ở đây thì `next build` đỏ. Đó là điểm
 * của việc trỏ `@api-types` ra ngoài `webapp/` thay vì giữ một bản sao.
 *
 * ══ Không truy cập CSDL ══
 *
 * CLAUDE.md mục 3 cấm Next.js chạm vào CSDL. Mọi dữ liệu đi qua HTTP, kể cả
 * khi hai bên nằm trên cùng một máy.
 *
 * ══ Vì sao KHÔNG phải `http://127.0.0.1/api/v2` ══
 *
 * Đó là giá trị tôi đặt ban đầu, và nó **không chạy**. Đo trên máy chủ ngày
 * 03/10/2026: mọi lời gọi trả **403** với `body: null` — không phải envelope
 * lỗi của Laravel, mà là OpenLiteSpeed chặn ở tầng ngoài. Yêu cầu tới
 * `127.0.0.1` mang header `Host: 127.0.0.1`, không khớp virtual host
 * `oohx.net`, nên nó không vào tới Laravel lần nào.
 *
 * Cách chữa hiển nhiên — tự đặt `Host: oohx.net` khi gọi — **không làm được**:
 * `fetch` của Node bỏ qua header `Host` do người gọi đặt và luôn gửi host của
 * URL. Đã thử cả `Host` và `host`, cả ba lần máy chủ nhận đúng
 * `127.0.0.1:<cổng>`.
 *
 * Nên cấu hình đúng là trỏ vào **tên miền thật** và cho tên miền đó phân giải
 * về chính máy chủ (một dòng trong `/etc/hosts`). Khi đó header `Host` và SNI
 * đều đúng, kết nối vẫn không ra khỏi máy, và không vòng qua Cloudflare — nên
 * không thêm độ trễ, không thêm một điểm hỏng, và không tính vào hạn mức tần
 * suất của người dùng thật.
 *
 * Chi tiết và lệnh kiểm ở `docs/deploy/nextjs-proxy/README.md`.
 */

const BASE = (process.env.OOHX_API_BASE ?? 'https://oohx.net/api/v2').replace(/\/+$/, '');

/** Tên miền công khai — dùng cho canonical và OG, không dùng để gọi API. */
export const PUBLIC_ORIGIN = (process.env.OOHX_PUBLIC_ORIGIN ?? 'https://oohx.net').replace(/\/+$/, '');

export type ScreenSummary = components['schemas']['ScreenSummary'];
export type PageMeta = components['schemas']['PageMeta'];
export type Availability = components['schemas']['Availability'];
export type FilterFacet = components['schemas']['FilterFacet'];

/**
 * Khóa `200` là **số**, không phải chuỗi — `openapi-typescript` sinh ra như
 * vậy. Viết `['200']` thì TypeScript báo không tìm thấy, và dễ đi tìm nguyên
 * nhân ở chỗ khác.
 */
export type ScreenDetail =
    paths['/api/v2/screens/{slug}']['get']['responses'][200]['content']['application/json']['data'];

/**
 * Dẫn xuất từ `paths`, không viết tay: `/filters` trả một đối tượng có khóa cố
 * định (`cities`, `venue_types`, `networks`, `owners`, `price_range`), và
 * `price_range` **không** cùng kiểu với các nhóm còn lại. Khai nó thành
 * `Record<string, FilterFacet[]>` là nói sai hợp đồng, và chỗ sai chỉ lộ ra khi
 * có người đọc `price_range` như một mảng.
 */
export type Filters =
    paths['/api/v2/filters']['get']['responses'][200]['content']['application/json']['data'];

export type ScreenList = {
    data: ScreenSummary[];
    meta: PageMeta;
};

/** Lỗi API, giữ nguyên envelope `{error, message, code, details}` của v2. */
export class ApiError extends Error {
    constructor(
        readonly status: number,
        readonly body: unknown,
    ) {
        super(`API v2 trả ${status}`);
        this.name = 'ApiError';
    }
}

type FetchOptions = {
    /**
     * Bao lâu thì coi dữ liệu là cũ (giây).
     *
     * Đặt ở từng chỗ gọi, không đặt một giá trị chung: danh sách màn hình đổi
     * theo ngày, còn trang chính sách thì hàng tháng. Một con số chung sẽ sai
     * cho cả hai.
     */
    revalidate?: number;

    /** Tham số truy vấn. `undefined` và chuỗi rỗng bị bỏ, không gửi đi. */
    query?: Record<string, string | number | undefined>;
};

async function get<T>(path: string, options: FetchOptions = {}): Promise<T> {
    const url = new URL(BASE + path);

    for (const [key, value] of Object.entries(options.query ?? {})) {
        if (value === undefined || value === '') continue;
        url.searchParams.set(key, String(value));
    }

    const response = await fetch(url, {
        headers: { Accept: 'application/json' },

        // `revalidate` chứ không `no-store`: trang công khai không phụ thuộc
        // người đang xem, nên render lại cho mỗi yêu cầu là trả giá cho một thứ
        // không ai cần. Nhóm cần quyền thì sẽ phải `no-store`, và đó là việc
        // của giai đoạn 8.
        next: { revalidate: options.revalidate ?? 60 },
    });

    if (!response.ok) {
        let body: unknown = null;
        try {
            body = await response.json();
        } catch {
            // Lỗi 500 từ tầng web có thể trả HTML, không trả JSON. Giữ `null`
            // thay vì để lỗi phân tích JSON che mất mã trạng thái thật.
        }

        throw new ApiError(response.status, body);
    }

    return (await response.json()) as T;
}

export function listScreens(query: FetchOptions['query'] = {}): Promise<ScreenList> {
    return get<ScreenList>('/screens', { query, revalidate: 60 });
}

/**
 * Gọi một endpoint chi tiết, trả `null` khi API trả 404.
 *
 * 404 là **câu trả lời**, không phải sự cố: slug không tồn tại thì trang phải
 * ra 404 thật để công cụ tìm kiếm bỏ nó khỏi chỉ mục, chứ không phải một trang
 * 200 rỗng — một trang 200 rỗng thì URL đó nằm trong chỉ mục mãi.
 *
 * Mọi lỗi KHÁC vẫn ném ra: 500 của API không được biến thành "không tìm thấy".
 * Hai thứ đó cần hai hành vi khác nhau, và gộp chúng là cách biến một sự cố
 * tạm thời thành một URL bị xoá khỏi chỉ mục.
 */
async function getOrNull<T>(path: string, options: FetchOptions = {}): Promise<T | null> {
    try {
        return await get<T>(path, options);
    } catch (error) {
        if (error instanceof ApiError && error.status === 404) {
            return null;
        }

        throw error;
    }
}

export async function getScreen(slug: string): Promise<ScreenDetail | null> {
    const body = await getOrNull<{ data: ScreenDetail }>(
        `/screens/${encodeURIComponent(slug)}`,
        { revalidate: 300 },
    );

    return body?.data ?? null;
}

// ── Media owner ─────────────────────────────────────────────────────────────

export type OwnerSummary = components['schemas']['OwnerSummary'];
export type OwnerDetail = components['schemas']['OwnerDetail'];

export type OwnerListResult =
    paths['/api/v2/owners']['get']['responses'][200]['content']['application/json'];

export type OwnerDetailResult =
    paths['/api/v2/owners/{slug}']['get']['responses'][200]['content']['application/json'];

export function listOwners(query: FetchOptions['query'] = {}): Promise<OwnerListResult> {
    return get<OwnerListResult>('/owners', { query, revalidate: 300 });
}

export function getOwner(slug: string, query: FetchOptions['query'] = {}) {
    return getOrNull<OwnerDetailResult>(`/owners/${encodeURIComponent(slug)}`, {
        query,
        revalidate: 300,
    });
}

// ── Sản phẩm / gói ──────────────────────────────────────────────────────────

export type ProductSummary = components['schemas']['ProductSummary'];
export type ProductDetail = components['schemas']['ProductDetail'];

export type ProductListResult =
    paths['/api/v2/products']['get']['responses'][200]['content']['application/json'];

export type ProductDetailResult =
    paths['/api/v2/products/{slug}']['get']['responses'][200]['content']['application/json'];

export function listProducts(query: FetchOptions['query'] = {}): Promise<ProductListResult> {
    return get<ProductListResult>('/products', { query, revalidate: 300 });
}

export function getProduct(slug: string) {
    return getOrNull<ProductDetailResult>(`/products/${encodeURIComponent(slug)}`, {
        revalidate: 300,
    });
}

// ── Trang chính sách ────────────────────────────────────────────────────────

export type PolicyListResult =
    paths['/api/v2/policies']['get']['responses'][200]['content']['application/json'];

export type PolicyPage =
    paths['/api/v2/policies/{slug}']['get']['responses'][200]['content']['application/json']['data'];

/**
 * `revalidate` dài hơn hẳn danh mục, và đó là chủ ý.
 *
 * Văn bản pháp lý đổi theo tháng, không theo ngày — hỏi API mỗi phút cho một
 * thứ đổi mỗi quý là trả giá cho một thứ không ai cần. Nhưng cũng KHÔNG đặt
 * `revalidate: false`: khi ban hành một bản mới thì nó phải tự ra, không chờ
 * một lượt deploy.
 */
const CHINH_SACH_REVALIDATE = 3600;

export function listPolicies(): Promise<PolicyListResult> {
    return get<PolicyListResult>('/policies', { revalidate: CHINH_SACH_REVALIDATE });
}

export async function getPolicy(slug: string): Promise<PolicyPage | null> {
    const body = await getOrNull<{ data: PolicyPage }>(
        `/policies/${encodeURIComponent(slug)}`,
        { revalidate: CHINH_SACH_REVALIDATE },
    );

    return body?.data ?? null;
}

export async function getFilters(): Promise<Filters> {
    const body = await get<{ data: Filters }>('/filters', { revalidate: 900 });

    return body.data;
}
