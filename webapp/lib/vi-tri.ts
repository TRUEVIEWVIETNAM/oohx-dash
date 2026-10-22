import type { Khung } from './khung-nhin';

/**
 * Hai phép quyết định của việc định vị — tách khỏi DOM để chốt bằng số.
 *
 * Chủ dự án chốt ngày 22/10/2026: *"người mở oohx.net/explore hệ thống sẽ
 * request location permission và sẽ hiển thị billboard xung quanh location
 * của user"*, và chọn **xin quyền khi mở trang** thay vì sau một cú bấm.
 *
 * ══ Hỏi khi mở trang là một quyết định có giá, và giá nằm ở ĐÂY ══
 *
 * Một hộp thoại quyền bật lên trước khi người dùng làm gì là thứ bị bấm
 * "Chặn" nhiều nhất trên web, và trình duyệt **nhớ** cú chặn đó: Chrome giữ
 * nó cho tới khi người dùng tự vào cài đặt site mà gỡ. Một lần hỏi sai thời
 * điểm là mất tính năng này vĩnh viễn với người đó.
 *
 * Nên `nenLayViTri` tồn tại, và nó là lý do duy nhất tệp này là một tệp
 * riêng: luật "khi nào được phép hỏi" phải gọi được bằng số, vì sai một
 * nhánh ở đây thì hoặc tính năng không bao giờ chạy, hoặc hộp thoại bật lên
 * ở mọi lượt tải trang — và cái thứ hai làm trình duyệt tự chặn luôn.
 */

/** Bán kính "quanh tôi", km. */
export const BAN_KINH_KM = 15;

/** Trái Đất ~111,32 km mỗi độ vĩ. */
const KM_MOI_DO_VI = 111.32;

/**
 * Khung nhìn hình chữ nhật quanh một toạ độ.
 *
 * Độ **kinh** co lại theo vĩ độ — ở xích đạo một độ kinh là 111km, ở vĩ độ
 * 60° chỉ còn 56km. Chia cho `cos(lat)` để 15km theo chiều đông–tây vẫn là
 * 15km thật. Bỏ phép chia đó thì ở Hà Nội (21°) khung rộng hơn mong muốn 7%,
 * và ở Bắc Âu thì gấp đôi.
 *
 * Sàn `0.01` cho `cos`: ở sát cực nó tiến tới 0 và khung đông–tây phình ra
 * những con số vô nghĩa. Không phải `Infinity` — đo thật ở vĩ độ 89,999 thì
 * `cos` còn 1,7e-5 và khung rộng **7,7°**; ở đúng 90° nó là 6,1e-17 và khung
 * rộng **2,2e15 độ**. Cả hai đều hữu hạn, nên một phép kiểm `isFinite` không
 * bắt được gì — đột biến bỏ sàn này đi lọt qua bộ chốt của chính tôi cho tới
 * khi ca test đổi sang đo **độ rộng**.
 *
 * Sàn 0,01 chặn khung ở ~13,5° đông–tây. Không có màn hình nào ở Bắc Cực,
 * nhưng một khung nhìn vô lý gửi lên API là một 422 im lặng và một bản đồ
 * trống không ai lần ra nguyên nhân.
 */
export function khungQuanh(lat: number, lng: number, km: number = BAN_KINH_KM): Khung {
    const doVi = km / KM_MOI_DO_VI;
    const coVi = Math.max(0.01, Math.cos((lat * Math.PI) / 180));
    const doKinh = km / (KM_MOI_DO_VI * coVi);

    return {
        north: Math.min(90, lat + doVi),
        south: Math.max(-90, lat - doVi),
        east: lng + doKinh,
        west: lng - doKinh,
    };
}

/** Trạng thái quyền do `navigator.permissions` trả về, hoặc `null` nếu không hỏi được. */
export type TrangThaiQuyen = 'granted' | 'denied' | 'prompt' | null;

/**
 * Có được gọi `getCurrentPosition` lúc trang vừa mở không?
 *
 * Ba nhánh, và mỗi nhánh đóng một lỗi khác nhau:
 *
 * - **`granted`** → gọi, kể cả khi đã hỏi rồi. Ở đây **không có hộp thoại
 *   nào** bật lên: người dùng đã đồng ý, trình duyệt trả toạ độ thẳng. Chặn
 *   nhánh này lại là bắt người đã đồng ý phải bấm thêm một nút ở mọi lượt
 *   tải trang.
 *
 * - **`denied`** → không gọi. Trình duyệt sẽ từ chối ngay mà không hỏi, nên
 *   gọi chỉ tốn một lần gọi hỏng — nhưng quan trọng hơn: một số trình duyệt
 *   đếm số lần site gọi vào một quyền đã bị chặn và siết thêm.
 *
 * - **`prompt` hoặc không biết** → chỉ gọi **một lần cho mỗi trình duyệt**,
 *   và `daHoi` là thứ nhớ điều đó. Đây là nhánh quan trọng nhất: người dùng
 *   **bỏ qua** hộp thoại (bấm ra ngoài, không chọn gì) thì trạng thái vẫn là
 *   `prompt` mãi mãi. Không nhớ thì hộp thoại bật lên ở **mọi** lượt tải
 *   trang — đúng hành vi làm Chrome tự chặn quyền của cả site.
 *
 * `null` xảy ra khi trình duyệt không có `navigator.permissions` hoặc từ
 * chối truy vấn. Xử như `prompt`: hỏi một lần, rồi thôi.
 */
export function nenLayViTri(daHoi: boolean, quyen: TrangThaiQuyen): boolean {
    if (quyen === 'granted') return true;
    if (quyen === 'denied') return false;

    return !daHoi;
}
