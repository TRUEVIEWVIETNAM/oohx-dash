'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { type CumSite } from '@/lib/ban-do';
import { useBanDoPin, type TomTat } from '@/lib/dung-ban-do';
import { dungDanhSach } from '@/lib/dung-danh-sach';
import { PopupBanDo } from '@/components/PopupBanDo';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster/dist/MarkerCluster.css';

/**
 * Bản đồ của **tập kết quả đang lọc** — nền của `/explore?view=map`.
 *
 * Lát 3 (19/10/2026) đặt nó làm cột phải 340px cạnh lưới. Lát 2 của việc gộp
 * bản đồ (22/10/2026) đổi chỗ chứ không đổi ruột: nó trải hết khung nhìn qua
 * `.ex-map-nen` và danh sách nổi lên trên — đúng cách Google Maps làm, nơi
 * canvas không bao giờ co lại vì một panel mở ra.
 *
 * ══ Khác `/map` ở chỗ nào ══
 *
 * `/map` là bản đồ toàn kho: nó có bộ lọc riêng, panel danh sách riêng, thanh
 * tỉnh thành để nhảy vùng. Cái này không có gì trong số đó. Nó chỉ trả lời
 * đúng một câu: *những vị trí vừa lọc nằm ở đâu.*
 *
 * Vì thế nó **không** nhận bộ lọc của riêng mình. Tham số đến từ URL của trang
 * danh sách, cùng một tập, nên không có cách nào để bản đồ và danh sách nói
 * hai con số khác nhau về cùng một bộ lọc.
 *
 * ══ Pin nhiều hơn thẻ, và đó là chủ ý ══
 *
 * Lưới hiện 24 thẻ của trang hiện tại; bản đồ hiện **mọi** vị trí khớp bộ lọc
 * trong khung nhìn, tới giới hạn cứng 500 của `GET /api/v2/screens/map`. Một
 * agency lên plan theo tuyến đường cần thấy cả tuyến, không phải 24 điểm đầu
 * theo thứ tự bảng.
 *
 * Hệ quả phải nói ra: bấm một pin có thể trỏ tới một vị trí **không có thẻ
 * nào trên trang này**. Khi đó popup là đường duy nhất tới nó, nên popup luôn
 * có nút sang trang chi tiết.
 */
export function BanDoKetQua({
    thamSo,
    slugSang,
    khiChonCum,
    khiDoiTomTat,
    khiMoChiTiet,
    viTri,
    hien = true,
}: {
    /** Tham số lọc của danh sách — truyền thẳng, không lọc bớt. */
    thamSo?: Record<string, string | undefined>;

    /** Slug của thẻ đang được rê chuột; pin tương ứng sáng lên. */
    slugSang?: string | null;

    /** Bấm pin: trả về mọi slug trong cụm để cha tìm thẻ tương ứng. */
    khiChonCum?: (slugs: string[]) => void;

    /**
     * Bốn con số của khung nhìn, đẩy lên cha (lát 4, 22/10/2026).
     *
     * Dải tổng quan nằm ở **đầu panel danh sách**, là anh em của khung bản đồ
     * chứ không nằm trong nó — nên con số phải đi lên một cấp. Cách còn lại
     * là nhấc cả `useBanDoPin` lên `KhungBanDo` và truyền năm ref xuống đây;
     * một callback rẻ hơn, và nó giữ cho component này vẫn tự chạy được một
     * mình ở bất cứ trang nào khác.
     *
     * Hàm này PHẢI ổn định giữa các lượt render của cha (`useCallback`), nếu
     * không effect bên dưới chạy lại mỗi lượt render và cha setState vô tận.
     */
    khiDoiTomTat?: (t: TomTat | null) => void;

    /**
     * Bấm "Xem chi tiết" trong popup mở thẻ cạnh danh sách, không rời trang
     * (lát 5, 22/10/2026). Truyền thẳng xuống `PopupBanDo`.
     */
    khiMoChiTiet?: (slug: string) => void;

    /**
     * Toạ độ người dùng, khi họ đã cho phép (lát định vị, 22/10/2026).
     *
     * `null` ở mọi trường hợp còn lại — chưa hỏi, đang hỏi, bị chặn, lỗi —
     * và khi đó khung bản đồ **không đổi gì**: nó vẫn mở ở khung cả nước như
     * trước. Đó là lưới an toàn số 2 của lát này: định vị chỉ *thêm* một
     * đường, không thay đường cũ.
     */
    viTri?: { lat: number; lng: number } | null;

    /**
     * Khung có đang hiện không.
     *
     * Dưới 1280px khung bị `display:none` cho tới khi người dùng bấm nút "Bản
     * đồ". Leaflet đo container lúc dựng, và một container ẩn đo ra 0×0 — nên
     * lúc hiện ra phải bảo nó đo lại, nếu không bản đồ là một ô xám không
     * tile.
     */
    hien?: boolean;

}) {
    const [dangChon, setDangChon] = useState<CumSite | null>(null);

    const chonCum = useCallback(
        (cum: CumSite) => {
            setDangChon(cum);
            khiChonCum?.(cum.manHinh.map((m) => m.slug).filter((s): s is string => !!s));
        },
        [khiChonCum],
    );

    const {
        containerRef,
        markerRef,
        cums,
        tomTat,
        loi,
        capNhat,
        sanSang,
        napLai,
        bayToi,
        veToanQuoc,
        thuPhong,
        vuaKhung,
        doLai,
    } = useBanDoPin({
        thamSo,
        khiChonCum: chonCum,
    });

    /**
     * Quanh người dùng trống thì lùi về cả nước, và **nói ra**.
     *
     * `null` = chưa thử hoặc tìm thấy; chuỗi = câu giải thích. Một bản đồ tự
     * nhảy về khung cả nước mà không nói gì trông như một lỗi tải.
     */
    const [khongCoGiQuanhDay, setKhongCoGiQuanhDay] = useState<string | null>(null);

    /**
     * Bay tới vị trí người dùng, đúng MỘT lần cho mỗi toạ độ.
     *
     * Khoá theo toạ độ chứ không theo object: `viTri` là một object mới ở
     * mỗi lượt render của cha, nên lấy thẳng nó làm deps là kéo bản đồ về
     * chỗ người dùng sau **mỗi** lần họ tick một ô lọc — tức cướp mất khung
     * nhìn họ vừa chọn.
     */
    const khoaViTri = viTri ? `${viTri.lat},${viTri.lng}` : '';

    useEffect(() => {
        // `sanSang` là điều kiện, không phải chi tiết: `bayToi` đọc
        // `mapRef.current`, và ở lượt render đầu ref đó còn `null` vì map
        // dựng trong một effect bất đồng bộ. Thiếu nó thì hiệu ứng này chạy
        // một lần lúc mount, thấy null, thoát — và bản đồ ở nguyên khung cả
        // nước trong khi toạ độ người dùng đã có từ lâu. Đo thấy ở máy.
        if (!viTri || !sanSang) return;

        let huy = false;

        void (async () => {
            const meta = await bayToi(viTri.lat, viTri.lng);

            if (huy || !meta) return;

            if (meta.total === 0) {
                // Lưới an toàn: không có gì quanh đây thì trả lại toàn kho
                // thay vì để người dùng nhìn một bản đồ trống.
                setKhongCoGiQuanhDay(
                    'Chưa có vị trí nào quanh bạn — đang hiện toàn bộ kho.',
                );
                await veToanQuoc();
            } else {
                setKhongCoGiQuanhDay(null);
            }
        })();

        return () => {
            huy = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [khoaViTri, sanSang, bayToi, veToanQuoc]);

    useEffect(() => {
        if (hien) doLai();
    }, [hien, doLai]);

    useEffect(() => {
        khiDoiTomTat?.(tomTat);
    }, [tomTat, khiDoiTomTat]);

    /**
     * Pin sáng theo thẻ đang rê chuột.
     *
     * Giữ phần tử đã tô trong một ref thay vì quét lại cả lớp để gỡ class:
     * ở 500 pin, một lượt quét cho mỗi lần chuột đi qua một thẻ là đủ để thấy
     * giật.
     *
     * Marker nằm trong một cụm gom chưa có mặt trong DOM, `getElement()` trả
     * `null`. Không sáng được thì thôi — không có gì để chữa, và cũng không có
     * gì hỏng.
     */
    const daSang = useRef<HTMLElement | null>(null);

    useEffect(() => {
        daSang.current?.classList.remove('oohx-pin--sang');
        daSang.current = null;

        if (!slugSang) return;

        const cum = cums.find((c) => c.manHinh.some((m) => m.slug === slugSang));
        if (!cum) return;

        const el = markerRef.current.get(cum.khoa)?.getElement() ?? null;
        if (!el) return;

        el.classList.add('oohx-pin--sang');
        daSang.current = el;
    }, [slugSang, cums, markerRef]);

    /**
     * Pin của vị trí **đã chọn** mang dấu riêng (lát 4).
     *
     * Quét lại cả tập mỗi lần danh sách đổi, không giữ phần tử cũ như phép
     * tô sáng ở trên: tick một pin có thể đổi trạng thái của nhiều pin cùng
     * lúc (bấm "Bỏ chọn" xoá hết), và tập này tối đa 500 nên một vòng lặp là
     * đủ rẻ.
     */
    const daChon = dungDanhSach();

    useEffect(() => {
        const chon = new Set(daChon.map((m) => m.slug));

        for (const cum of cums) {
            const el = markerRef.current.get(cum.khoa)?.getElement();
            if (!el) continue;

            el.classList.toggle(
                'oohx-pin--chon',
                cum.manHinh.some((m) => m.slug && chon.has(m.slug)),
            );
        }
    }, [daChon, cums, markerRef]);

    return (
        <div className="ex-map-nen">
            <div
                ref={containerRef}
                className="map-canvas"
                aria-label="Bản đồ các vị trí khớp bộ lọc"
                role="application"
            />

            {/*
              Badge "n vị trí trên bản đồ" gỡ 22/10/2026 cùng lát 4.

              Nó đếm **cụm** (`cums.length`, một cụm là một toà nhà) trong
              khi dải tổng quan ở đầu panel đếm **màn hình** (`meta.total`).
              Hai con số khác nhau cho cùng một câu hỏi, cách nhau 400px trên
              một màn hình: đo trên production ngày 22/10 thì badge nói 81
              còn danh sách nói 83. Dải tổng quan giữ câu hỏi đó, và lời cảnh
              báo "đã cắt ở 500" đi theo nó.
            */}

            {capNhat ? (
                <button type="button" className="ex-tim-lai" onClick={napLai}>
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                    </svg>
                    Tìm trong khu vực này
                </button>
            ) : null}

            <div className="map-controls">
                <button type="button" className="mc-btn" onClick={() => thuPhong(1)} aria-label="Phóng to">
                    +
                </button>
                <button type="button" className="mc-btn" onClick={() => thuPhong(-1)} aria-label="Thu nhỏ">
                    −
                </button>
                <button
                    type="button"
                    className="mc-btn"
                    onClick={() => void vuaKhung()}
                    aria-label="Vừa khung theo kết quả"
                >
                    <svg viewBox="0 0 24 24" fill="var(--t2)" aria-hidden="true" style={{ width: 16, height: 16 }}>
                        <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />
                    </svg>
                </button>
            </div>

            {loi ? <div className="ex-map-loi">{loi}</div> : null}

            {!loi && khongCoGiQuanhDay ? (
                <div className="ex-map-bao">{khongCoGiQuanhDay}</div>
            ) : null}

            {/*
              KHÔNG có chấm "bạn đang ở đây", và đó là một lựa chọn.

              Một chấm đặt giữa khung bằng CSS chỉ đúng chừng nào bản đồ còn
              ở nguyên chỗ nó vừa bay tới — kéo một cái là nó thành lời nói
              dối, chỉ vào một nơi người dùng không ở. Làm đúng thì phải là
              một marker Leaflet thật, neo theo toạ độ; đó là việc của một
              lát riêng, không phải một `<div>` thêm vào đây.
            */}

            {dangChon ? (
                <PopupBanDo
                    cum={dangChon}
                    dong={() => setDangChon(null)}
                    khiMoChiTiet={khiMoChiTiet}
                />
            ) : null}
        </div>
    );
}
