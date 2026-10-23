import type { MapPin } from './ban-do';
import type { Khung } from './khung-nhin';

/**
 * Một nơi duy nhất biết `GET /api/v2/screens/map`.
 *
 * ══ Vì sao tách ra khỏi `dung-ban-do.ts` (22/10/2026, lát định vị) ══
 *
 * Lát định vị cần đếm *quanh người dùng có bao nhiêu vị trí* ở **kiểu xem
 * lưới** — nơi không có bản đồ, không có Leaflet, không có `useBanDoPin`.
 * Bản đầu để `BangViTri` tự `fetch`, và `tests/js/leaflet.test.mjs` đỏ ngay:
 * nó giữ một **danh sách đóng** những nơi được gọi endpoint này.
 *
 * Phép kiểm đó đúng, và lý do nó đúng lộ ra khi sửa. Thứ trôi khỏi nhau giữa
 * hai đường gọi là **bộ lọc**: bản đầu của tôi đếm quanh người dùng mà bỏ
 * qua bộ lọc đang bật, nên với `?screen_type=lcd` thì dòng chữ nói "104 vị
 * trí quanh bạn" trong khi danh sách ngay dưới có 20. Hai con số cho hai câu
 * hỏi khác nhau, cách nhau một dòng, và không gì nói ra điều đó.
 *
 * Nên cả hai bên nay đi qua `truyVanPin` và `layPin`. Danh sách đóng trong
 * phép kiểm trỏ vào tệp này.
 */

/**
 * Tham số của danh sách **không** gửi cho endpoint bản đồ.
 *
 * `page` vì bản đồ không phân trang — nó hiện mọi pin khớp bộ lọc trong khung
 * nhìn, tới giới hạn cứng 500. `sort` vì thứ tự không có nghĩa với một tập
 * điểm, nhưng nó đi vào `ORDER BY` và do đó quyết định **500 pin nào** còn lại
 * khi bị cắt; một bản đồ đổi tập điểm vì người dùng đổi ô sắp xếp là thứ không
 * ai đoán được.
 *
 * `view` thêm vào danh sách 22/10/2026: nó là kiểu xem của trang, không phải
 * một chiều lọc, và gửi nó đi chỉ làm URL dài ra.
 *
 * Danh sách **đen**, không phải trắng, và đó là một lựa chọn. Hệ quả của nó:
 * tham số lạ trong URL (`utm_source`, một cache-buster) cũng được gửi kèm, và
 * API bỏ qua chúng. Hệ quả của danh sách trắng thì nặng hơn: thêm một bộ lọc
 * mà quên khai ở đây là bản đồ lặng lẽ không lọc theo nó.
 */
export const BO_QUA_PIN = new Set(['page', 'sort', 'view']);

/** Chuỗi truy vấn lọc, đã bỏ những tham số không thuộc về bản đồ. */
export function truyVanPin(thamSo?: Record<string, string | undefined>): string {
    const p = new URLSearchParams();

    for (const [k, v] of Object.entries(thamSo ?? {})) {
        if (v && !BO_QUA_PIN.has(k)) p.set(k, v);
    }

    return p.toString();
}

/** Một ô lưới: tâm và số màn hình trong ô. Không mang gì nhận dạng được. */
export type Cum = { lat: number; lng: number; count: number };

/**
 * Hai chế độ, và `meta.mode` nói rõ đang ở chế độ nào — **không đoán theo số
 * lượng** (lát A, 23/10/2026).
 *
 * `pin`: trong ngân sách, `data` có pin thật và `clusters` rỗng.
 * `cluster`: trên ngân sách, `data` rỗng và `clusters` có ô lưới có số đếm.
 *
 * Vì sao máy chủ phải nói ra thay vì để client suy: suy từ `data.length === 0`
 * thì "khung nhìn không có gì" và "khung nhìn quá dày nên trả cụm" trông giống
 * hệt nhau, và hai thứ đó cần hai câu trả lời ngược nhau trên màn hình.
 *
 * `truncated` còn lại vì là hợp đồng cũ, và nay **luôn** `false`: trên ngân
 * sách thì endpoint đổi chế độ chứ không cắt bớt.
 */
export type KetQuaPin = {
    data: MapPin[];
    clusters?: Cum[];
    meta: {
        mode?: 'pin' | 'cluster';
        returned: number;
        total: number;
        truncated: boolean;
        cell_deg?: number | null;
    };
};

/**
 * Gọi endpoint pin cho một khung nhìn.
 *
 * Trả `null` khi API từ chối — **kể cả 422**. 422 nghĩa là khung nhìn không
 * hợp lệ, và nó xảy ra bình thường khi Leaflet chưa đo xong container: lần
 * `moveend` sau sẽ gọi lại với khung thật. Người gọi phân biệt được "không
 * có gì" (`meta.total === 0`) với "chưa hỏi được" (`null`).
 */
export async function layPin(
    khung: Khung,
    truyVan: string,
    signal?: AbortSignal,
): Promise<KetQuaPin | null> {
    const p = new URLSearchParams(truyVan);

    p.set('north', String(khung.north));
    p.set('south', String(khung.south));
    p.set('east', String(khung.east));
    p.set('west', String(khung.west));

    const res = await fetch(`/api/v2/screens/map?${p.toString()}`, {
        headers: { Accept: 'application/json' },
        signal,
    });

    if (!res.ok) return null;

    return (await res.json()) as KetQuaPin;
}
