'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { nenLayViTri, type TrangThaiQuyen } from './vi-tri';

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

    const lay = useCallback(() => {
        if (dangChay.current) return;

        dangChay.current = true;
        ghiDaHoi();
        setTrangThai({ pha: 'dang-hoi' });

        navigator.geolocation.getCurrentPosition(
            (p) => {
                dangChay.current = false;
                setTrangThai({ pha: 'co', lat: p.coords.latitude, lng: p.coords.longitude });
            },
            (e) => {
                dangChay.current = false;
                setTrangThai(
                    e.code === e.PERMISSION_DENIED ? { pha: 'tu-choi' } : { pha: 'loi' },
                );
            },
            { enableHighAccuracy: false, timeout: 10_000, maximumAge: 300_000 },
        );
    }, []);

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
            if (!nenLayViTri(docDaHoi(), quyen)) return;

            lay();
        })();

        return () => {
            huy = true;
        };
    }, [lay]);

    /** Nút "Thử lại": bỏ qua lưới an toàn số 1, vì người dùng vừa tự yêu cầu. */
    const hoiLai = useCallback(() => {
        if (typeof navigator !== 'undefined' && 'geolocation' in navigator) lay();
    }, [lay]);

    return { trangThai, hoiLai };
}
