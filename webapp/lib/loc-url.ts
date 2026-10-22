/**
 * Luật dựng URL của bộ lọc kho — tách khỏi DOM để chốt được bằng số.
 *
 * Lát 3 của việc gộp bản đồ (22/10/2026) đổi cột lọc dọc thành một dải chip.
 * Hình dạng đổi, **hợp đồng URL không đổi**: vẫn một khoá cho mỗi chiều lọc,
 * giá trị phân tách bằng dấu phẩy (`city=hanoi,hcm`), đúng như
 * `docs/openapi/v2.yaml` khai (`style: form, explode: false`).
 *
 * ══ Vì sao nó không nằm trong `BoLocKho.tsx` ══
 *
 * Trong component thì luật này chỉ chạy được khi có một trình duyệt, một
 * router và 23 ô tick. Ở đây nó là một hàm thuần nhận hai đối số, nên
 * `tests/js/loc-url.test.mjs` gọi thẳng bằng số — cùng lý do `keo-sheet.ts`
 * bị tách ra khỏi `dung-keo-sheet.ts`.
 *
 * Và có đúng một thứ cần chốt bằng test ở đây: **`view` phải đi qua nguyên
 * vẹn.** Trước lát 3, hàm dựng URL trong `BoLocKho` giữ `q` và `sort` rồi bỏ
 * mọi thứ khác. Sau lát 2 thì `view=map` là "mọi thứ khác" — nên tick một ô
 * lọc trong kiểu xem bản đồ sẽ lặng lẽ ném người dùng về kiểu lưới, mất cả
 * khung nhìn họ vừa kéo tới. Không có gì đỏ lên, và không ai nhìn ra khi đọc
 * riêng từng tệp.
 */

/**
 * Năm chiều lọc, theo đúng thứ tự chúng hiện ra thành chip.
 *
 * `price_status` đứng đầu và đó là chủ ý: đo trên production ngày 18/10/2026,
 * **1 trong 50** bản ghi có giá, nên *đã báo giá chưa* là câu hỏi đầu tiên của
 * bất kỳ người mua nào.
 */
export const KHOA_LOC = ['price_status', 'screen_type', 'city', 'venue_type', 'owner'] as const;

export type KhoaLoc = (typeof KHOA_LOC)[number];

export type ThamSoLoc = Record<string, string | undefined>;

export type ChonLoc = Record<KhoaLoc, Set<string>>;

/** `"a,b"` → `Set{a,b}`. Nhận cả `|` vì `FrontpageListingRequest` cũng nhận. */
export function tachDanhSach(gia_tri?: string): Set<string> {
    if (!gia_tri) return new Set();

    return new Set(
        gia_tri
            .split(/[,|]/)
            .map((s) => s.trim())
            .filter(Boolean),
    );
}

/** Đọc cả năm chiều lọc ra khỏi tham số URL. */
export function docChon(params: ThamSoLoc): ChonLoc {
    const chon = {} as ChonLoc;

    for (const khoa of KHOA_LOC) chon[khoa] = tachDanhSach(params[khoa]);

    return chon;
}

/** Tổng số giá trị đang được chọn trên cả năm chiều. */
export function demChon(chon: ChonLoc): number {
    let tong = 0;

    for (const khoa of KHOA_LOC) tong += chon[khoa]?.size ?? 0;

    return tong;
}

/**
 * Đường dẫn `/explore` cho một bộ lọc.
 *
 * Ba tham số **không** thuộc bộ lọc nhưng phải sống sót qua mỗi lần áp dụng:
 *
 *  - `q` thuộc ô tìm kiếm lớn — bỏ nó là lặng lẽ xoá từ khoá người dùng gõ;
 *  - `sort` thuộc thanh kết quả;
 *  - `view` thuộc hai liên kết đổi kiểu xem (lát 2).
 *
 * Một tham số bị bỏ có chủ ý: **`page`**. Trang 7 của bộ lọc cũ không còn
 * nghĩa gì sau khi tập kết quả đổi, và giữ nó lại là cách chắc chắn để người
 * dùng thấy "không tìm thấy inventory phù hợp" ngay sau khi họ nới bộ lọc.
 *
 * Dấu phẩy ra khỏi `URLSearchParams` dưới dạng `%2C`. Giữ nguyên chứ không
 * tự nối chuỗi: `expandCitySlugs` ở Laravel nhận chuỗi đã giải mã, nên hai
 * cách cho cùng một kết quả — còn tự nối thì mất phần mã hoá của mọi giá trị
 * khác (tên owner có dấu cách, chẳng hạn).
 */
export function duongDanLoc(params: ThamSoLoc, chon: ChonLoc): string {
    const u = new URLSearchParams();

    if (params.q) u.set('q', params.q);

    for (const khoa of KHOA_LOC) {
        const tap = chon[khoa];

        if (tap?.size) u.set(khoa, [...tap].join(','));
    }

    if (params.sort) u.set('sort', params.sort);
    if (params.view === 'map') u.set('view', 'map');

    const qs = u.toString();

    return qs ? `/explore?${qs}` : '/explore';
}
