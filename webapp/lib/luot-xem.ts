/**
 * Gửi một lượt xem lên `POST /api/v2/screens/{slug}/luot-xem` — lát 2 của
 * `docs/PLAN-LUOT-XEM.md`.
 *
 * ══ Cái gì được gửi đi, và cái gì KHÔNG ══
 *
 * Thân request **rỗng**. Màn hình nằm trong đường dẫn, và không có trường nào
 * khác — không id phiên, không đường dẫn giới thiệu, không user agent, không
 * gì nhận dạng được người gửi. Bảng đích cũng không có cột nào nhận chúng.
 *
 * Đó là thứ làm bảng lượt xem **không phải dữ liệu cá nhân**, tức là thứ làm
 * câu hỏi "có phải xin đồng ý theo Nghị định 13/2023/NĐ-CP không" không phát
 * sinh. Một lời hứa về **hình dạng**, nên nó cần người canh: xem ca `thân
 * request rỗng` trong `tests/js/luot-xem.test.mjs`.
 *
 * ══ Gộp theo phiên trình duyệt, và nó KHÔNG phải một bảo đảm ══
 *
 * Máy chủ không biết người gửi là ai, nên nó không gộp được hai lượt của cùng
 * một người — xem docblock `LuotXemService`. Nếu client cũng không gộp thì mỗi
 * lần bấm F5 là một lượt, và con số đo *độ sốt ruột của người đang xem* chứ
 * không đo *có bao nhiêu người xem*. Một người tải lại trang 30 lần làm một vị
 * trí lên đầu bảng "Phổ biến nhất".
 *
 * `sessionStorage` chặn đúng cái đó: một lượt cho mỗi màn hình trong mỗi phiên
 * (một tab, tới khi đóng tab). Phép gộp này **thiện chí** — nó biến `so_luot`
 * từ "số lần tải trang" thành "số phiên đã mở trang", gần với cái đang cần
 * hơn nhiều, nhưng ai cũng phá được bằng tay. Nên nhãn trên giao diện vẫn phải
 * là **"lượt xem"**, không bao giờ là "người quan tâm".
 *
 * Chọn `sessionStorage` chứ không `localStorage`: `localStorage` sống qua
 * nhiều ngày, nên người quay lại xem một vị trí vào tuần sau sẽ **không** được
 * đếm — và bảng gộp theo ngày thì lượt tuần sau là một con số của một ngày
 * khác, hoàn toàn chính đáng. Chặn nó là làm con số của những vị trí được
 * người ta quay lại thấp hơn sự thật.
 *
 * ══ Ba điều kiện của đường đo đạc, giống `do-dac.ts` ══
 *
 *  1. **Không bao giờ chặn luồng.** `fetch` không `await`, lỗi nuốt im lặng.
 *  2. **Không gửi ở máy phát triển** — dùng chung `nenGuiDoDac()`, xem dưới.
 *  3. **Không gửi cookie phiên.** `credentials: 'omit'`: đường này công khai
 *     và không đọc người gửi là ai. Để `fetch` kèm cookie theo mặc định cùng
 *     nguồn gốc thì mỗi lượt xem mang theo danh tính người dùng tới máy chủ —
 *     không vào bảng, nhưng vào nhật ký truy cập của OpenLiteSpeed, nơi nó
 *     sống ngoài mọi lời hứa của plan này.
 *
 * Không `keepalive`: khác `do-dac.ts`, lượt này gửi **ngay khi trang hiện ra**
 * chứ không sau 12 giây, nên không có ca "người dùng đang rời trang" để cứu.
 */

// Phép kiểm "hostname này có phải sàn thật không" dùng chung với `do-dac.ts`,
// không chép lại.
//
// Hai bản chép rời sẽ lệch, và lệch ở đây có hệ quả cụ thể: thiếu dấu chấm thì
// `oohx.net.evil.com` gửi được số vào bảng của sàn; thiếu cả phép kiểm thì mỗi
// lần `next start` ở máy lại bơm số rác vào bảng production — và rác đó **không
// gỡ ra được**, vì bảng chỉ có số cộng dồn và không cột nào nói lượt nào từ đâu
// tới.
//
// Tên hàm nói "đo đạc" chứ không nói "quyền vị trí", và một lượt xem cũng là
// một phép đo đạc, nên nó đọc đúng ở đây. Phép kiểm ấy đã có bốn ca chốt riêng
// trong `tests/js/do-dac.test.mjs`, kể cả hai ca tên miền giả mạo.
import { nenGuiDoDac } from './do-dac';

/** Tiền tố khoá `sessionStorage`. Một khoá cho mỗi màn hình. */
const KHOA = 'oohx:luot-xem:';

/**
 * Phiên này đã đếm màn hình này chưa?
 *
 * `try/catch` quanh **cả** phép đọc: `sessionStorage` ném khi site data bị
 * chặn (cửa sổ ẩn danh ở vài trình duyệt, chính sách doanh nghiệp). Khi đó câu
 * trả lời là "chưa đếm" và lượt vẫn được gửi — thà đếm hơi nhiều còn hơn mất
 * hẳn những người duyệt web ở chế độ ẩn danh.
 */
export function daDemTrongPhien(slug: string): boolean {
    try {
        return window.sessionStorage.getItem(KHOA + slug) === '1';
    } catch {
        return false;
    }
}

function danhDauDaDem(slug: string): void {
    try {
        window.sessionStorage.setItem(KHOA + slug, '1');
    } catch {
        // Không ghi được thì lượt sau của phiên này sẽ đếm lại. Không phải lỗi
        // người dùng cần biết.
    }
}

/**
 * Cộng một lượt vào ô `(hôm nay, màn hình này)`.
 *
 * Không trả gì và không ném gì. Phía gọi **không được** chờ nó.
 *
 * Đánh dấu `sessionStorage` **trước** khi gửi, không phải sau: đánh dấu trong
 * `.then()` thì hai lần render của React Strict Mode (chạy effect hai lượt ở
 * môi trường phát triển) đều thấy "chưa đếm" và đều gửi, vì phản hồi chưa về.
 * Lỗi ấy chỉ hiện ở máy phát triển — nơi `nenGuiDoDac` đã chặn gửi — nhưng
 * cùng hình dạng với một ca thật: hai component cùng gọi trong một lượt render.
 */
export function ghiLuotXem(slug: string): void {
    if (typeof window === 'undefined') return;
    if (!slug) return;
    if (!nenGuiDoDac(window.location.hostname)) return;
    if (daDemTrongPhien(slug)) return;

    danhDauDaDem(slug);

    try {
        void fetch(`/api/v2/screens/${encodeURIComponent(slug)}/luot-xem`, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            credentials: 'omit',
        }).catch(() => {
            // Một lượt xem mất đi không phải một lỗi người dùng cần biết.
        });
    } catch {
        // `fetch` ném đồng bộ được (CSP, trình duyệt cũ). Vẫn im lặng.
    }
}
