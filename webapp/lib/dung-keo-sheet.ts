'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent, KeyboardEvent as ReactKeyboardEvent } from 'react';
import { caoKhiKeo, caoToiDa, mocKhiTha, NGUONG_KEO } from './keo-sheet';

/**
 * Làm tay nắm của một ngăn kéo lên **kéo được thật**.
 *
 * Dùng cho `.map-panel.open` (`/map`) và `.sidebar.open` (`/explore`,
 * `/products`): cả hai là cùng một hình dạng — ngăn trắng trượt lên từ đáy, bo
 * góc trên, một tay nắm hình viên thuốc ở giữa đỉnh. Tay nắm đó là ký hiệu
 * quy ước của cử chỉ kéo, nhưng tới trước hôm nay nó chỉ đóng ngăn khi bấm.
 *
 * Hook này thêm ba việc, và **giữ nguyên** việc cũ:
 *
 *  - kéo xuống → ngăn thấp dần theo ngón tay, thả ở dưới thì đóng;
 *  - kéo lên → ngăn cao tới sát header, thả thì ở lại đó;
 *  - nhấn (đi dưới `NGUONG_KEO` px) → vẫn đóng, như từ lát 6;
 *  - bàn phím: ↑ mở rộng, ↓ thu lại rồi đóng, Enter/Space đóng.
 *
 * Phép quyết định nằm ở `keo-sheet.ts` và được chốt bằng test; ở đây chỉ còn
 * đo toạ độ, ghi `style.height` và đổi class.
 *
 * ══ Đổi `height`, không đổi `transform` ══
 *
 * Bottom sheet thường được kéo bằng `transform:translateY()` vì transform chạy
 * trên compositor, không gây reflow. Cách đó **không dùng được ở đây**: muốn
 * kéo lên cao hơn thì ngăn phải cao hơn, mà dựng ngăn cao sẵn rồi dịch nó
 * xuống thì phần dưới của danh sách cuộn bên trong nằm **ngoài vùng nhìn** —
 * cuộn tới cuối, mục cuối cùng nằm ở đáy hộp, tức dưới mép màn hình, không ai
 * đọc được. Nên ngăn đổi `height` thật.
 *
 * Giá phải trả là reflow mỗi khung. Hai cách giảm:
 *
 *  - ghi vào `el.style.height` qua ref, **không** qua `setState` — React không
 *    phải render lại 80 thẻ màn hình mỗi lần ngón tay nhích;
 *  - một lần ghi mỗi khung (`requestAnimationFrame`), vì `pointermove` trên
 *    màn hình 120Hz bắn dày hơn số khung vẽ được.
 *
 * Reflow cũng chỉ trong cây con của ngăn: `.map-panel.open` là
 * `position:fixed`, nên bản đồ bên phải không bị tính lại theo.
 *
 * ══ Đo `caoThu`, không chép công thức của CSS ══
 *
 * Chiều cao thu gọn do CSS tính (`clamp(40dvh, calc(100dvh - var(--hdr)
 * - 300px), 60dvh)` ở `/map`). Chép phép đó vào JS là có hai bản của một con
 * số, và bản JS sẽ trôi khỏi bản CSS ở lần sửa sau. Nên hook **đo** nó mỗi
 * khi ngăn đang mở ở bậc thu, rồi nhớ lấy để dùng khi đang ở bậc rộng.
 */
export function dungKeoSheet<T extends HTMLElement>({
    mo,
    dong,
    nhan,
}: {
    mo: boolean;
    dong: () => void;
    /** Ngăn này là ngăn gì, để đọc lên trong `aria-label`: "danh sách", "bộ lọc". */
    nhan: string;
}) {
    /**
     * Gắn vào chính ngăn (phần tử có `.map-panel` / `.sidebar`).
     *
     * `T` để chỗ gọi nói ngăn của nó là thẻ gì: `/map` dựng ngăn bằng `div`,
     * còn cột lọc là một `form` thật (nó submit được khi JS chưa chạy). Một
     * `useRef<HTMLElement>` chung thì React từ chối gắn vào cả hai.
     */
    const refNgan = useRef<T | null>(null);

    /** Ngăn đang ở bậc rộng hay bậc thu. */
    const [rong, setRong] = useState(false);

    /** Chiều cao bậc thu đo được lần gần nhất, px. */
    const caoThuRef = useRef<number | null>(null);

    /** Cử chỉ đang diễn ra; `null` khi không có ngón tay nào trên tay nắm. */
    const keo = useRef<{
        id: number;
        y0: number;
        y: number;
        t: number;
        vy: number;
        caoDau: number;
        caoThu: number;
        caoRong: number;
        cao: number;
        daKeo: boolean;
    } | null>(null);

    /** Khung vẽ đang hẹn, để không ghi `style.height` hai lần một khung. */
    const khung = useRef<number | null>(null);

    /**
     * Cử chỉ vừa rồi là một cú kéo.
     *
     * Trình duyệt vẫn bắn `click` sau một chuỗi pointer event, nên nếu không
     * nhớ việc này thì mọi cú kéo đều kết thúc bằng việc đóng ngăn — tức cử
     * chỉ mới hỏng đúng theo kiểu nó định sửa.
     */
    const vuaKeo = useRef(false);

    // Ngăn đóng lại thì quên bậc rộng: lần mở sau bắt đầu ở bậc thu, là bậc
    // chừa chỗ cho bản đồ (#144).
    useEffect(() => {
        if (!mo) setRong(false);
    }, [mo]);

    // Đo bậc thu mỗi khi ngăn đang mở ở bậc đó. Chạy sau khi trình duyệt đã
    // layout, nên con số là chiều cao CSS thật, không phải phỏng đoán.
    //
    // Đo lại ở `transitionend` vì lần đo đầu có thể rơi vào GIỮA animation thu
    // ngăn lại: khi đó nó ghi nhớ một con số như 700px cho một bậc thật ra cao
    // 492px, và cú kéo sau đó tính ngưỡng theo số sai.
    useEffect(() => {
        if (!mo || rong) return;

        const el = refNgan.current;

        if (!el) return;

        const ghiNho = () => {
            caoThuRef.current = el.getBoundingClientRect().height;
        };

        ghiNho();
        el.addEventListener('transitionend', ghiNho);

        return () => el.removeEventListener('transitionend', ghiNho);
    }, [mo, rong]);

    /**
     * Đổi bậc: gắn lớp **ngay** rồi mới báo cho React.
     *
     * `setRong()` một mình là không đủ, và đo được: trên `/map` ở khung
     * 390×844, từ lúc gõ ↑ tới lúc lớp `.sheet-rong` có mặt trong DOM mất
     * **330ms** — React còn phải render lại cả panel (bộ lọc, bộ đếm, danh
     * sách) trước khi đổi `className`. Ba trăm ba mươi milli giây là đủ để cử
     * chỉ trông như không ăn, và với cú kéo thì tệ hơn: chiều cao nội tuyến đã
     * bị trả lại cho CSS, nên ngăn **tụt về bậc cũ** rồi mới bò lên bậc mới.
     *
     * Nên lớp được gắn thẳng vào DOM, và `setRong()` chạy sau chỉ để giữ trạng
     * thái React cho `aria-expanded` và cho lần render sau. Lần render đó ghi
     * lại đúng tên lớp vừa gắn, nên không có nhấp nháy: React và tay đang nói
     * cùng một thứ.
     */
    const datMoc = useCallback((bacRong: boolean) => {
        refNgan.current?.classList.toggle('sheet-rong', bacRong);
        setRong(bacRong);
    }, []);

    const ghiCao = useCallback(() => {
        khung.current = null;

        const el = refNgan.current;
        const k = keo.current;

        if (el && k) el.style.height = `${k.cao}px`;
    }, []);

    const batDau = useCallback((e: ReactPointerEvent<HTMLElement>) => {
        // Chỉ nút chính của chuột; nút phải và nút giữa không phải cử chỉ kéo.
        if (e.button !== 0) return;

        const el = refNgan.current;

        if (!el) return;

        const o = el.getBoundingClientRect();
        const caoHeader =
            parseFloat(
                getComputedStyle(document.documentElement).getPropertyValue('--hdr'),
            ) || 0;

        if (!rong) caoThuRef.current = o.height;

        // Xoá cờ của cử chỉ TRƯỚC. Nó được đặt ở cuối mỗi cú kéo để cú `click`
        // mà trình duyệt bắn sau đó không đóng ngăn — nhưng `click` không phải
        // lúc nào cũng tới (chuột nhả ngoài tay nắm, hoặc trình duyệt bỏ nó).
        // Để sót thì cú **nhấn** kế tiếp bị ăn mất, và tay nắm im lặng đúng
        // một lần. Đã gặp thật khi đo bằng sự kiện tổng hợp.
        vuaKeo.current = false;

        keo.current = {
            id: e.pointerId,
            y0: e.clientY,
            y: e.clientY,
            t: e.timeStamp,
            vy: 0,
            caoDau: o.height,
            // Chưa đo được bậc thu lần nào (ngăn mở sẵn ở bậc rộng) thì lấy
            // nửa chiều cao khả dụng — một con số đủ hợp lý để còn hai bậc.
            caoThu: caoThuRef.current ?? o.bottom / 2,
            caoRong: caoToiDa(o.bottom, caoHeader),
            cao: o.height,
            daKeo: false,
        };

        // Giữ mọi pointer event về tay nắm, kể cả khi ngón tay trượt ra ngoài
        // nó — ngăn thấp đi dưới ngón tay nên chuyện đó xảy ra gần như luôn.
        //
        // Trong `try`: với một pointerId không có thật — sự kiện tổng hợp từ
        // một phép đo, hay một trình duyệt không cấp capture — hàm này ném
        // `NotFoundError`. Để nó ném ra thì cả cử chỉ chết ngay ở bước đầu,
        // trong khi phần còn lại chạy được mà không cần capture (chỉ kém hơn
        // khi ngón tay trượt khỏi tay nắm).
        try {
            e.currentTarget.setPointerCapture(e.pointerId);
        } catch {
            // Không có capture thì vẫn kéo được, chỉ mong manh hơn.
        }
    }, [rong]);

    const diChuyen = useCallback(
        (e: ReactPointerEvent<HTMLElement>) => {
            const k = keo.current;
            const el = refNgan.current;

            if (!k || !el || e.pointerId !== k.id) return;

            const dy = e.clientY - k.y0;

            if (!k.daKeo) {
                if (Math.abs(dy) < NGUONG_KEO) return;

                k.daKeo = true;

                // Tắt transition trong lúc kéo: để nguyên thì ngăn đuổi theo
                // ngón tay chậm một nhịp và cử chỉ thành ra nhão.
                el.classList.add('sheet-keo');
            }

            // Vận tốc tức thời giữa hai lần `pointermove`, không phải trung
            // bình cả cử chỉ: cái quyết định là ngón tay đang đi thế nào lúc
            // rời màn hình.
            if (e.timeStamp > k.t) {
                k.vy = (e.clientY - k.y) / (e.timeStamp - k.t);
                k.y = e.clientY;
                k.t = e.timeStamp;
            }

            k.cao = caoKhiKeo(k.caoDau, dy, k.caoRong);

            if (khung.current === null) khung.current = requestAnimationFrame(ghiCao);
        },
        [ghiCao],
    );

    const ketThuc = useCallback(
        (e: ReactPointerEvent<HTMLElement>) => {
            const k = keo.current;
            const el = refNgan.current;

            keo.current = null;

            if (!k || e.pointerId !== k.id) return;

            if (khung.current !== null) {
                cancelAnimationFrame(khung.current);
                khung.current = null;
            }

            if (el) {
                // Trả chiều cao về cho CSS và bật lại transition, nên ngăn
                // trượt từ chỗ ngón tay thả tới điểm nghỉ.
                el.style.height = '';
                el.classList.remove('sheet-keo');
            }

            if (!k.daKeo) return; // Một cú nhấn: `khiNhan` lo.

            vuaKeo.current = true;

            const moc = mocKhiTha({
                cao: k.cao,
                caoThu: k.caoThu,
                caoRong: k.caoRong,
                vy: k.vy,
            });

            if (moc === 'dong') {
                dong();

                return;
            }

            datMoc(moc === 'rong');
        },
        [dong, datMoc],
    );

    const khiNhan = useCallback(() => {
        if (vuaKeo.current) {
            vuaKeo.current = false;

            return;
        }

        dong();
    }, [dong]);

    const khiBam = useCallback(
        (e: ReactKeyboardEvent<HTMLElement>) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                dong();

                return;
            }

            if (e.key === 'ArrowUp') {
                e.preventDefault();
                datMoc(true);

                return;
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();

                // Xuống một bậc, cùng luật với cú vẩy xuống: đang rộng thì
                // thu, đang thu thì đóng.
                if (rong) datMoc(false);
                else dong();
            }
        },
        [dong, rong, datMoc],
    );

    return {
        refNgan,
        rong,
        /**
         * Thuộc tính của tay nắm. Rải sau `className` ở chỗ gọi, để
         * `class="mp-drag"` trong HTML đã render không đổi.
         */
        tay: {
            role: 'button' as const,
            tabIndex: 0,
            // Nhãn nói ra **cả hai** việc tay nắm làm. Nhãn cũ chỉ nói "Đóng
            // danh sách", nên người dùng trình đọc màn hình không có đường
            // nào biết tới bậc rộng — mà bàn phím thì mở được nó bằng ↑.
            'aria-label': `Kéo để đổi chiều cao ${nhan}, nhấn để đóng`,
            'aria-expanded': rong,
            onPointerDown: batDau,
            onPointerMove: diChuyen,
            onPointerUp: ketThuc,
            onPointerCancel: ketThuc,
            onClick: khiNhan,
            onKeyDown: khiBam,
        },
    };
}
