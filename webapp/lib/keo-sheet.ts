/**
 * Phép tính của cử chỉ kéo ngăn — phần **không** chạm DOM.
 *
 * Ba ngăn kéo lên của trang công khai (`.map-panel.open` ở `/map`,
 * `.sidebar.open` ở `/explore` và `/products`) đều có một tay nắm hình viên
 * thuốc ở trên. Tay nắm đó là ký hiệu quy ước của một bottom sheet kéo được —
 * nhưng từ lát 6 tới nay nó **chỉ đóng ngăn khi bấm**. Người dùng kéo xuống
 * thì không có gì xảy ra; kéo lên cũng không có gì xảy ra. Giao diện hứa một
 * cử chỉ rồi không làm.
 *
 * Tệp này giữ phần quyết định: ngăn cao bao nhiêu trong lúc ngón tay còn
 * trên màn hình, và khi thả thì ngăn về đâu. Phần gắn vào DOM ở
 * `dung-keo-sheet.ts`.
 *
 * ══ Vì sao tách ra ══
 *
 * Không chốt nào của dự án chạy được một cử chỉ thật: jsdom không chạy layout,
 * `khoi-giao-dien.mjs` đọc HTML đã render, và Chrome thì phải đo tay. Nên luật
 * của cử chỉ — ngưỡng vẩy, điểm nghỉ gần nhất, chuyện ngăn quá cao trên màn
 * hình thấp — nằm ở đây, nơi `tests/js/sheet-keo-duoc.test.mjs` import thẳng
 * và gọi bằng số. Phần còn lại ở `dung-keo-sheet.ts` mỏng đi đúng chừng ấy:
 * đọc toạ độ, ghi `style.height`, đổi class.
 *
 * ══ Quy ước dấu ══
 *
 * `dy` và `vy` dương là **xuống dưới** (cùng chiều `clientY` của trình duyệt),
 * tức chiều làm ngăn thấp đi. Chiều cao luôn là px.
 */

/** Ba điểm nghỉ của một ngăn. */
export type Moc = 'dong' | 'thu' | 'rong';

/**
 * Ngón tay phải đi quá bấy nhiêu px thì mới tính là kéo.
 *
 * Dưới mức này cử chỉ vẫn là một cú **nhấn**, và nhấn thì đóng ngăn — hành vi
 * đã có từ lát 6, không đổi. Ngưỡng tồn tại vì ngón tay không bao giờ đứng
 * yên: một cú nhấn thường trôi 2–4px, và nếu không chừa khoảng ấy thì mỗi lần
 * nhấn lại thành một cú kéo dài 3px rồi nảy về chỗ cũ, trông như ngăn giật.
 */
export const NGUONG_KEO = 6;

/**
 * Ngưỡng vẩy, px/ms.
 *
 * Trên mức này thì **hướng** quyết định, không phải vị trí: vẩy nhanh xuống là
 * ý muốn bỏ ngăn đi, dù ngón tay chỉ mới đi được 20px. Thiếu luật này thì một
 * cú vẩy ngắn luôn nảy về chỗ cũ vì vị trí vẫn còn gần điểm nghỉ ban đầu, và
 * người dùng phải kéo chậm hết nửa màn hình mới đóng được.
 *
 * 0.5px/ms ≈ 500px/s. Cùng khoảng với ngưỡng của iOS sheet; đo trên máy thì
 * một cú kéo bình thường rơi vào 0.1–0.3 và một cú vẩy vào 0.8–2.
 */
export const NGUONG_VAY = 0.5;

/** Khe chừa lại giữa đỉnh ngăn khi mở rộng hết và chân header, px. */
export const KHE_TREN = 12;

/**
 * Sàn của chiều cao mở rộng, px.
 *
 * Trên màn hình rất thấp (hoặc khi bàn phím ảo đang chiếm chỗ), phép trừ bên
 * dưới có thể ra số âm. Ngăn cao 0px thì không ai kéo lại lên được.
 */
export const CAO_TOI_THIEU = 120;

/**
 * Chiều cao tối đa ngăn được phép lấy.
 *
 * `dayNgan` là khoảng từ đỉnh vùng nhìn tới **đáy ngăn** — đo thẳng bằng
 * `getBoundingClientRect().bottom`, không tính lại từ `--nav` và
 * `env(safe-area-inset-bottom)`. Lý do: đáy ngăn đã nằm đúng chỗ CSS đặt nó,
 * nên đo thì khỏi phải chép lại phép tính của CSS vào JS — hai bản của cùng
 * một phép tính sẽ trôi khỏi nhau.
 *
 * Trừ `caoHeader` để đỉnh ngăn không trèo lên header: header là lối duy nhất
 * ra khỏi trang trên điện thoại.
 */
export function caoToiDa(dayNgan: number, caoHeader: number): number {
    return Math.max(CAO_TOI_THIEU, dayNgan - caoHeader - KHE_TREN);
}

/**
 * Chiều cao ngăn trong lúc ngón tay còn trên màn hình.
 *
 * Chặn trên ở `caoRong` (không cho trèo lên header) và chặn dưới ở 0 — chứ
 * không chặn ở chiều cao thu gọn. Chặn dưới ở 0 là phần **xem trước của việc
 * đóng**: kéo xuống thì ngăn thấp dần và trôi khỏi màn hình theo ngón tay, nên
 * người dùng thấy mình đang bỏ nó đi trước khi thả tay.
 */
export function caoKhiKeo(caoDau: number, dy: number, caoRong: number): number {
    return Math.min(caoRong, Math.max(0, caoDau - dy));
}

/**
 * Khi thả tay thì ngăn về đâu.
 *
 * Hai luật, theo thứ tự:
 *
 *  1. **Vẩy** (|vy| ≥ `NGUONG_VAY`): hướng thắng vị trí, và đi **một bậc** chứ
 *     không nhảy thẳng. Vẩy xuống từ bậc rộng về bậc thu, từ bậc thu thì đóng.
 *     Một bậc, vì vẩy mạnh từ trạng thái rộng mà đóng luôn là lấy đi của người
 *     dùng cả ngăn khi họ chỉ muốn thấy bản đồ nhiều hơn.
 *  2. **Gần nhất**: trong ba điểm nghỉ 0 / `caoThu` / `caoRong`, về điểm gần
 *     chiều cao hiện tại nhất. Ngang nhau thì giữ ngăn lại — thứ tự xét là
 *     thu → rộng → đóng, nên ở đúng giữa `caoThu/2` ngăn ở lại chứ không đóng.
 *     Thả tay là một hành động mơ hồ; đóng là hành động mất nhiều nhất.
 *
 * `caoThu` bị kẹp xuống `caoRong`: trên màn hình thấp, chiều cao thu gọn mà
 * CSS tính ra có thể lớn hơn cả chiều cao tối đa. Khi đó hai bậc trùng nhau và
 * ngăn chỉ còn mở/đóng — đúng hơn là có một bậc "rộng" thấp hơn bậc "thu".
 */
export function mocKhiTha({
    cao,
    caoThu,
    caoRong,
    vy,
}: {
    cao: number;
    caoThu: number;
    caoRong: number;
    vy: number;
}): Moc {
    const thu = Math.min(caoThu, caoRong);

    // Hai bậc trùng nhau thì bậc rộng **không tồn tại**: trả 'rong' ở đó là
    // đặt `aria-expanded="true"` cho một ngăn không cao lên được chút nào.
    const coBacRong = caoRong > thu;

    if (vy >= NGUONG_VAY) return cao > thu ? 'thu' : 'dong';
    if (vy <= -NGUONG_VAY) return cao < thu || !coBacRong ? 'thu' : 'rong';

    const diem: [Moc, number][] = coBacRong
        ? [
              ['thu', thu],
              ['rong', caoRong],
              ['dong', 0],
          ]
        : [
              ['thu', thu],
              ['dong', 0],
          ];

    let chon: Moc = 'thu';
    let gan = Infinity;

    for (const [moc, moc_cao] of diem) {
        const xa = Math.abs(cao - moc_cao);

        if (xa < gan) {
            gan = xa;
            chon = moc;
        }
    }

    return chon;
}
