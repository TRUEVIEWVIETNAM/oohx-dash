'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { nenLayViTri, type TrangThaiQuyen } from './vi-tri';
import { CHO_TRA_LOI_MS, ghiDoDac } from './do-dac';

/**
 * Vị trí người dùng, xin **khi mở trang** — chốt của chủ dự án 22/10/2026.
 *
 * ══ Ba lưới an toàn, và cả ba đều phải có ══
 *
 * Hỏi quyền lúc trang vừa mở là cách nhanh nhất để bị bấm "Chặn", và trình
 * duyệt nhớ cú chặn đó cho tới khi người dùng tự vào cài đặt site mà gỡ. Nên:
 *
 *  1. **Hỏi đúng một lần cho mỗi trình duyệt.** Người dùng bỏ qua hộp thoại
 *     (bấm ra ngoài, không chọn gì) thì trạng thái vẫn là `prompt` mãi mãi —
 *     không nhớ thì hộp thoại bật ở mọi lượt tải trang, đúng hành vi làm
 *     Chrome tự chặn quyền của cả site. Luật ở `nenLayViTri`, có chốt.
 *  2. **Chặn hay quanh đó trống thì trang không đổi gì.** Giao diện vẫn là
 *     toàn kho; định vị chỉ *thêm* một đường, không thay đường cũ.
 *  3. **HTML máy chủ không phụ thuộc vị trí.** Máy chủ không biết người dùng
 *     ở đâu và không được đoán — `/explore` do máy chủ dựng vẫn là toàn kho,
 *     nên công cụ tìm kiếm và người dùng chặn quyền thấy cùng một trang.
 *
 * ══ `enableHighAccuracy: false`, cố ý ══
 *
 * Tính năng này chỉ cần biết người dùng ở thành phố nào để kéo bản đồ tới
 * đó. Bật GPS là lấy nhiều dữ liệu hơn mức cần, chậm hơn, tốn pin hơn — và
 * trên một trang công khai thì xin ít hơn mức có thể xin là mặc định đúng.
 *
 * `maximumAge: 5 phút`: một toạ độ vừa lấy xong trong phiên trước dùng lại
 * được, không cần đánh thức bộ định vị.
 *
 * ══ Và từ 23/10/2026, nó đếm ══
 *
 * Lời hứa khi chốt cách hỏi này gồm một lưới thứ ba: *đếm tỉ lệ bấm "Chặn"*.
 * Lát 2 của `docs/PLAN-DO-DAC-TOI-THIEU.md` trả nợ đó. Số đi qua
 * `ghiDoDac()`, ba trường, không toạ độ, không định danh — và **không phải
 * lượt nào cũng được đếm**: xem docblock của `useEffect` bên dưới.
 */

const KHOA = 'oohx_da_hoi_vi_tri';

export type TrangThaiViTri =
    /** Không hỏi: đã hỏi rồi, hoặc bị chặn, hoặc trình duyệt không có API. */
    | { pha: 'tat' }
    | { pha: 'dang-hoi' }
    | { pha: 'co'; lat: number; lng: number }
    | { pha: 'tu-choi' }
    | { pha: 'loi' };

function docDaHoi(): boolean {
    try {
        return window.localStorage.getItem(KHOA) === '1';
    } catch {
        // Chế độ riêng tư, site data bị chặn. Coi như chưa hỏi: hỏi một lần
        // thừa còn hơn không bao giờ hỏi.
        return false;
    }
}

function ghiDaHoi(): void {
    try {
        window.localStorage.setItem(KHOA, '1');
    } catch {
        // Không nhớ được thì thôi — trình duyệt vẫn nhớ quyết định quyền của
        // chính nó, và `navigator.permissions` đọc ra được ở lượt sau.
    }
}

export function dungViTri() {
    const [trangThai, setTrangThai] = useState<TrangThaiViTri>({ pha: 'tat' });

    /** Chặn lượt hỏi thứ hai khi React dựng hai lần ở chế độ nghiêm ngặt. */
    const dangChay = useRef(false);

    /** Hẹn giờ `chua_tra_loi`, hoặc `null` khi không có cái nào đang chờ. */
    const hetGio = useRef<number | null>(null);

    const huyHetGio = useCallback(() => {
        if (hetGio.current !== null) {
            clearTimeout(hetGio.current);
            hetGio.current = null;
        }
    }, []);

    /**
     * @param coDo Lượt này có đếm không — xem docblock `useEffect` bên dưới.
     */
    const lay = useCallback(
        (coDo: boolean) => {
            if (dangChay.current) return;

            dangChay.current = true;
            ghiDaHoi();
            setTrangThai({ pha: 'dang-hoi' });

            if (coDo) {
                ghiDoDac('hoi');

                // Trình duyệt **không báo gì** khi người dùng bấm ra ngoài hộp
                // thoại: không callback nào chạy, `permissions.query` vẫn trả
                // `prompt` mãi mãi. Nên `chua_tra_loi` chỉ suy ra được bằng
                // cách chờ — và hẹn giờ này phải bị huỷ ở **cả hai** callback,
                // nếu không một câu trả lời về chậm sẽ được đếm hai lần.
                hetGio.current = window.setTimeout(() => {
                    hetGio.current = null;
                    ghiDoDac('chua_tra_loi');
                }, CHO_TRA_LOI_MS);
            }

            navigator.geolocation.getCurrentPosition(
                (p) => {
                    dangChay.current = false;
                    huyHetGio();
                    if (coDo) ghiDoDac('cho_phep');
                    setTrangThai({ pha: 'co', lat: p.coords.latitude, lng: p.coords.longitude });
                },
                (e) => {
                    dangChay.current = false;
                    huyHetGio();

                    const biTuChoi = e.code === e.PERMISSION_DENIED;
                    if (coDo) ghiDoDac(biTuChoi ? 'tu_choi' : 'loi');

                    setTrangThai(biTuChoi ? { pha: 'tu-choi' } : { pha: 'loi' });
                },
                { enableHighAccuracy: false, timeout: 10_000, maximumAge: 300_000 },
            );
        },
        [huyHetGio],
    );

    /** Nhánh `denied` chỉ được đếm một lượt cho mỗi lần mở trang. */
    const daDemDaChan = useRef(false);

    /**
     * ══ Lượt nào được đếm, và vì sao KHÔNG phải mọi lượt ══
     *
     * Mẫu số `hoi` chỉ đếm những lần gọi ở **nhánh có thể bật hộp thoại**.
     * Hai nhánh còn lại không bật hộp thoại nào, và trộn chúng vào làm bản
     * tổng hợp vô nghĩa theo hai cách khác nhau:
     *
     *  · **`granted`** — người đã đồng ý từ trước. Đếm nhánh này thì mỗi lượt
     *    họ quay lại cộng thêm một `hoi` và một `cho_phep`, nên tỉ lệ cho phép
     *    **tiến dần tới 100%** theo số lượt truy cập của chính những người đã
     *    đồng ý. Con số sẽ trông càng lúc càng đẹp mà không có ai mới đồng ý,
     *    và đó là kiểu sai tệ nhất: nó xác nhận quyết định đang cần kiểm chứng.
     *  · **`denied`** — trình duyệt đã khoá, không hỏi gì. Nó được đếm vào
     *    `da_chan_tu_truoc`, nằm **ngoài** mẫu số (xem
     *    `DoDacService::tongHopQuyenViTri()`).
     *
     * Nên `coDo` là `quyen !== 'granted'`, không phải `true`.
     */
    useEffect(() => {
        if (typeof navigator === 'undefined' || !('geolocation' in navigator)) return;

        let huy = false;

        void (async () => {
            let quyen: TrangThaiQuyen = null;

            try {
                // `navigator.permissions` không có ở mọi trình duyệt và có thể
                // ném với tên quyền nó không biết. Không truy vấn được thì xử
                // như "chưa quyết" — hỏi một lần, rồi thôi.
                quyen = (await navigator.permissions?.query({ name: 'geolocation' }))?.state
                    ?? null;
            } catch {
                quyen = null;
            }

            if (huy) return;

            if (quyen === 'denied' && !daDemDaChan.current) {
                daDemDaChan.current = true;
                ghiDoDac('da_chan_tu_truoc');
            }

            if (!nenLayViTri(docDaHoi(), quyen)) return;

            lay(quyen !== 'granted');
        })();

        // Cố ý **không** huỷ hẹn giờ ở đây. Hộp thoại quyền thuộc về tab, không
        // thuộc về component: nó vẫn đứng đó sau khi người dùng chuyển sang
        // trang khác trong cùng ứng dụng, và callback của
        // `getCurrentPosition` vẫn chạy để huỷ hẹn giờ nếu họ trả lời. Huỷ ở
        // đây là bỏ mất đúng những lượt do dự lâu nhất — tức ô `chua_tra_loi`
        // hụt theo chiều làm tính năng trông đỡ tệ hơn thực tế.
        return () => {
            huy = true;
        };
    }, [lay]);

    /**
     * Nút "Thử lại": bỏ qua lưới an toàn số 1, vì người dùng vừa tự yêu cầu.
     *
     * **Không đếm.** Câu hỏi cần trả lời là *"hỏi khi mở trang có đáng
     * không"*, và một lượt hỏi **sau** cú bấm của người dùng có tỉ lệ cho phép
     * cao hơn hẳn — trộn vào thì mẫu số lẫn hai loại lượt khác bản chất, và
     * bản tổng hợp không còn nói về quyết định đang cần kiểm chứng nữa.
     */
    const hoiLai = useCallback(() => {
        if (typeof navigator !== 'undefined' && 'geolocation' in navigator) lay(false);
    }, [lay]);

    return { trangThai, hoiLai };
}
