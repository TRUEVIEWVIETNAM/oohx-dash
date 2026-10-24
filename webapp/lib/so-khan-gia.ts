/**
 * Hỏi xem một khối `insights` có thật sự chứa số liệu hay không.
 *
 * ══ Vì sao `insights != null` không đủ ══
 *
 * Đo trên production ngày 24/10/2026,
 * `GET /api/v2/screens/oohx-led-cto-can-tho` trả:
 *
 *     "insights": {
 *       "placement_zone": null, "placement_label": null,
 *       "daily_footfall": null, "monthly_reach": null,
 *       "daily_impressions_est": null, "audience_profile": null,
 *       "time_performance": null, "nearby_context": null,
 *       "methodology_note": null, "source": "owner"
 *     }
 *
 * Đối tượng **có**, mọi trường bên trong **null**. `KhoiInsights` chỉ chặn
 * `!insights`, nên nó đi qua và render `<div class="ins">` rỗng — một tab mở ra
 * không có gì bên trong, và không chốt nào đỏ vì mọi màn hình giả trước đây
 * hoặc có đủ số liệu hoặc có `insights: null`.
 *
 * Cùng hình dạng ấy lặp lại ở ba nhánh con: `audience_profile`,
 * `time_performance` và `nearby_context` đều được kiểm bằng `object ? (`, nên
 * một đối tượng toàn null vẫn dựng tiêu đề khối và những thanh phần trăm 0%.
 *
 * ══ `source` không phải số liệu ══
 *
 * `source: "owner"` luôn có mặt, kể cả khi không có gì được khai. Đếm nó là
 * nói rằng hồ sơ có dữ liệu trong khi nó chỉ có một nhãn về nguồn của thứ
 * không tồn tại. `methodology_note` và `source_note` cũng vậy: chúng mô tả
 * cách đo, không phải kết quả đo.
 */

/** Trường chỉ mô tả nguồn/cách đo — có một mình thì vẫn là rỗng. */
const TRUONG_META = new Set(['source', 'methodology_note', 'source_note']);

/**
 * Đối tượng con có ít nhất một trường mang nội dung thật.
 *
 * Mảng rỗng tính là không có: `lifestyle_tags: []` và `brands: []` không dựng
 * được gì trên màn hình.
 */
export function coNoiDung(o: unknown): boolean {
    if (o === null || typeof o !== 'object') return false;

    return Object.entries(o as Record<string, unknown>).some(([khoa, v]) => {
        if (TRUONG_META.has(khoa)) return false;
        if (v === null || v === undefined) return false;
        if (typeof v === 'string') return v.trim() !== '';
        if (Array.isArray(v)) return v.length > 0;
        if (typeof v === 'object') return coNoiDung(v);

        // Số 0 tính là KHÔNG có: trong khối này 0 nghĩa là "chưa đo", không
        // phải "đã đo và bằng không". Một màn hình có `daily_footfall: 0` mà
        // hiện ra khối số liệu là nói rằng không ai đi qua điểm đó.
        if (typeof v === 'number') return v !== 0;

        return Boolean(v);
    });
}

/**
 * Khối `insights` có gì để hiển thị hay không.
 *
 * Năm điều kiện này là **đúng năm nhánh render** của `KhoiInsights`. Thêm một
 * nhánh ở đó mà quên thêm vào đây thì khối lại có thể render rỗng; bớt một
 * nhánh mà quên bớt ở đây thì tab hiện ra trống. `tests/js/so-khan-gia.test.mjs`
 * canh hai bên khớp nhau bằng cách đọc thẳng mã nguồn của component.
 */
export function coSoKhanGia(insights: unknown): boolean {
    if (insights === null || typeof insights !== 'object') return false;

    const i = insights as Record<string, unknown>;

    return Boolean(
        i.daily_footfall ||
            i.monthly_reach ||
            i.daily_impressions_est ||
            i.placement_zone ||
            coNoiDung(i.audience_profile) ||
            coNoiDung(i.time_performance) ||
            coNoiDung(i.nearby_context),
    );
}
