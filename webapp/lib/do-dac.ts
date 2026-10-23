/**
 * Gửi một lượt đếm lên `POST /api/v2/do-dac` — lát 2 của
 * `docs/PLAN-DO-DAC-TOI-THIEU.md`.
 *
 * Chủ dự án chốt ngày 23/10/2026: **bật đo đạc**, và **không** thêm mục vào
 * chính sách bảo mật. Quyết định thứ hai đứng được vì bảng đích là một bảng
 * đếm gộp không chứa dữ liệu cá nhân — nhưng nó chỉ đứng được **chừng nào
 * bảng còn giữ hình dạng đó**. Tệp này gửi đúng ba trường, và không có đường
 * nào để gửi thêm trường thứ tư.
 *
 * ══ Ba điều kiện plan đặt ra, và cả ba đều ở đây ══
 *
 *  1. **Không bao giờ chặn luồng.** `fetch` không `await`, lỗi nuốt im lặng.
 *     Một phép đo làm chậm tính năng nó đo là một phép đo tự huỷ.
 *  2. **Không gửi ở máy phát triển.** Chỉ gửi khi `location.hostname` là tên
 *     miền thật. Thiếu nó thì mỗi lần `next start` ở máy lại bơm số rác vào
 *     bảng production, và rác đó **không gỡ ra được** — bảng chỉ có số cộng
 *     dồn, không có cột nào nói lượt nào từ đâu tới.
 *  3. **Hẹn giờ `chua_tra_loi` huỷ được** — ở `dung-vi-tri.ts`, nơi có cả
 *     callback để huỷ nó.
 *
 * ══ `credentials: 'omit'`, và đó không phải chi tiết nhỏ ══
 *
 * Đường này công khai, không đọc người gửi là ai. Để `fetch` kèm cookie phiên
 * theo mặc định cùng nguồn gốc thì mỗi lượt đếm mang theo danh tính người
 * dùng tới máy chủ — không vào bảng (bảng không có cột nào nhận nó), nhưng
 * vào nhật ký truy cập của OpenLiteSpeed, nơi nó sống ngoài mọi lời hứa của
 * plan này. `omit` là cách nói rằng lượt đếm không cần biết ai gửi.
 *
 * ══ `keepalive: true` ══
 *
 * `chua_tra_loi` gửi sau 12 giây, và người dùng bỏ qua hộp thoại quyền thường
 * là người đang rời trang. Không có `keepalive` thì trình duyệt huỷ request
 * lúc chuyển trang, tức ô quan trọng nhất của bản tổng hợp là ô hụt nhiều
 * nhất — và hụt theo đúng chiều làm tính năng trông đỡ tệ hơn thực tế.
 */

/** Sự kiện duy nhất hôm nay. Lát 4 sẽ thêm, cùng bảng và cùng đường. */
export const SU_KIEN_QUYEN_VI_TRI = 'quyen_vi_tri';

/**
 * Chờ bao lâu rồi mới coi là người dùng không trả lời.
 *
 * **12 giây, dài hơn `timeout: 10_000` của `getCurrentPosition`** — để ca
 * hết-giờ rơi vào `loi` chứ không lẫn vào `chua_tra_loi`. Hai ô đó dẫn tới
 * hai kết luận khác nhau: `loi` là bộ định vị chậm, `chua_tra_loi` là người
 * dùng do dự, và chỉ ô thứ hai là ô còn cứu được bằng cách hỏi lúc khác.
 *
 * Đặt nó ngắn hơn `timeout` thì mọi lượt định vị chậm đều bị đếm thành do dự,
 * và không gì đỏ.
 */
export const CHO_TRA_LOI_MS = 12_000;

export type KetQuaQuyenViTri =
    | 'hoi'
    | 'cho_phep'
    | 'tu_choi'
    | 'da_chan_tu_truoc'
    | 'loi'
    | 'chua_tra_loi';

const MIEN_THAT = 'oohx.net';

/**
 * Hostname này có phải sàn thật không?
 *
 * Khớp `oohx.net` và mọi tên miền con của nó, **không** khớp bất cứ gì khác.
 * Phép so phải là `=== MIEN_THAT` hoặc kết thúc bằng `'.' + MIEN_THAT`:
 *
 *  · `includes('oohx.net')` nhận cả `oohx.net.evil.com` — tức một trang ai
 *    cũng dựng được lại gửi được số vào bảng của sàn;
 *  · `endsWith('oohx.net')` nhận `notoohx.net`, cùng kiểu lỗi, khó thấy hơn.
 *
 * Dấu chấm là thứ chặn cả hai, và nó đáng một ca chốt riêng.
 */
export function nenGuiDoDac(hostname: string): boolean {
    return hostname === MIEN_THAT || hostname.endsWith('.' + MIEN_THAT);
}

/**
 * Loại thiết bị, theo **con trỏ thô** chứ không theo user agent.
 *
 * Plan đòi điều này, và lý do nằm ở chỗ bảng không có chỗ cho một dòng user
 * agent: chuỗi đó định danh được thiết bị tới mức đáng ngạc nhiên, và một khi
 * nó đi qua đường mạng thì nó nằm trong nhật ký máy chủ dù không vào bảng.
 *
 * `(pointer: coarse)` trả lời đúng câu đang cần: ngón tay hay con chuột. Nó
 * cũng đúng cho những ca user agent trả lời sai — máy tính có màn hình cảm
 * ứng, điện thoại ở chế độ desktop.
 *
 * Không đọc được `matchMedia` thì trả `desktop`: tỉ lệ chặn ở desktop thấp
 * hơn, nên đoán sai về phía này làm số **đỡ giật gân hơn** thực tế, và một
 * phép đo đoán sai theo chiều khiêm tốn thì an toàn hơn chiều ngược lại.
 */
export function doThietBi(): 'mobile' | 'desktop' {
    try {
        return window.matchMedia('(pointer: coarse)').matches ? 'mobile' : 'desktop';
    } catch {
        return 'desktop';
    }
}

/**
 * Cộng một lượt vào ô `(hôm nay, quyen_vi_tri, giá trị, thiết bị)`.
 *
 * Không trả gì và không ném gì. Phía gọi **không được** chờ nó: xem điều kiện
 * số 1 ở docblock đầu tệp.
 */
export function ghiDoDac(giaTri: KetQuaQuyenViTri): void {
    if (typeof window === 'undefined') return;
    if (!nenGuiDoDac(window.location.hostname)) return;

    try {
        void fetch('/api/v2/do-dac', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
                su_kien: SU_KIEN_QUYEN_VI_TRI,
                gia_tri: giaTri,
                thiet_bi: doThietBi(),
            }),
            keepalive: true,
            credentials: 'omit',
        }).catch(() => {
            // Một lượt đếm mất đi không phải một lỗi người dùng cần biết.
        });
    } catch {
        // `fetch` ném đồng bộ được (CSP, trình duyệt cũ). Vẫn im lặng.
    }
}
