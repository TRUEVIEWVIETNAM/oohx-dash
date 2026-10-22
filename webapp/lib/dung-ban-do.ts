'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { Map as LeafletMap, Marker, MarkerClusterGroup } from 'leaflet';
import {
    cumHtml,
    gomTheoSite,
    pinHtml,
    themLopNen,
    type CumSite,
    type MapPin,
} from './ban-do';
import { canNapLai, tomTatKhung, type Khung, type TomTat } from './khung-nhin';

/**
 * Lõi dùng chung của mọi bản đồ đọc `GET /api/v2/screens/map`.
 *
 * ══ Vì sao tách ra khỏi `ScreenMap` (gỡ 22/10/2026 cùng `/map`) ══
 *
 * Lát 3 của `docs/PLAN-EXPLORE-QUYET-DINH-MUA.md` đặt một bản đồ thứ hai cạnh
 * danh sách `/explore`. Nó cần đúng những thứ `ScreenMap` đã có: dựng Leaflet,
 * gom cụm, nạp pin theo khung nhìn, hoãn `moveend`, chống kết quả về trễ.
 *
 * Chép chúng sang là tạo hai bản của cùng một luật gọi API, và CLAUDE.md mục 1
 * nói thẳng chuyện đó đã xảy ra một lần rồi: `InventoryController` và
 * `FrontpageService` trôi khỏi nhau và làm rò dữ liệu chưa duyệt. Hai bản đồ
 * lọc khác nhau thì không rò gì, nhưng hỏng theo kiểu khó thấy hơn — cả hai
 * đều trả về thứ trông hợp lý.
 *
 * Nên phần chung nằm ở đây, còn khung quanh nó (panel, thanh tỉnh thành, cột
 * phải) là việc của từng trang.
 *
 * ══ Cái hook này CỐ Ý không làm ══
 *
 * Nó không vẽ gì ngoài pin. Không nút thu phóng, không badge đếm, không popup
 * — những thứ đó khác nhau giữa hai trang, và nhét chúng vào đây là đẩy một
 * nhánh `if (trang === 'explore')` xuống tận lớp dữ liệu.
 */

/** Khung nhìn ban đầu: cả Việt Nam. */
export const VIETNAM = {
    center: [16.0, 107.5] as [number, number],
    zoom: 5.5,
};

export type MetaPin = { returned: number; total: number; truncated: boolean };

/**
 * Tham số của danh sách **không** gửi cho endpoint bản đồ.
 *
 * `page` vì bản đồ không phân trang — nó hiện mọi pin khớp bộ lọc trong khung
 * nhìn, tới giới hạn cứng 500. `sort` vì thứ tự không có nghĩa với một tập
 * điểm, nhưng nó đi vào `ORDER BY` và do đó quyết định **500 pin nào** còn lại
 * khi bị cắt; một bản đồ đổi tập điểm vì người dùng đổi ô sắp xếp là thứ không
 * ai đoán được.
 *
 * Danh sách **đen**, không phải trắng, và đó là một lựa chọn. Hệ quả của nó:
 * tham số lạ trong URL (`utm_source`, một cache-buster) cũng được gửi kèm, và
 * API bỏ qua chúng. Hệ quả của danh sách trắng thì nặng hơn: thêm một bộ lọc
 * mà quên khai ở đây là bản đồ lặng lẽ không lọc theo nó — đúng cái lát này
 * sinh ra để chặn.
 */
const BO_QUA = new Set(['page', 'sort']);

export function useBanDoPin({
    thamSo,
    khiChonCum,
}: {
    /** Tham số lọc đang áp dụng — cùng tập của `GET /api/v2/screens`. */
    thamSo?: Record<string, string | undefined>;

    /** Gọi khi người dùng bấm một pin. */
    khiChonCum?: (cum: CumSite) => void;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<LeafletMap | null>(null);
    const clusterRef = useRef<MarkerClusterGroup | null>(null);
    const seqRef = useRef(0);

    /**
     * Marker theo khoá cụm — để bên ngoài làm nổi một pin mà không phải dựng
     * lại cả lớp. Marker đang nằm trong một cụm gom thì `getElement()` trả
     * `null`: nó chưa có mặt trong DOM, và đó là câu trả lời đúng chứ không
     * phải một lỗi phải chữa.
     */
    const markerRef = useRef<Map<string, Marker>>(new Map());

    /**
     * Callback giữ trong ref, không đưa vào deps.
     *
     * Đưa vào deps thì mỗi lượt render của cha — một cú tick, một state mở
     * panel — là một danh tính hàm mới, tức một lượt nạp pin mới. Đúng cái bẫy
     * đã bắt được ở `BoLocKho` ngày 19/10, chỉ khác là ở đây nó tốn một lời
     * gọi API chứ không làm mất một ô tick.
     */
    const chonRef = useRef(khiChonCum);

    useEffect(() => {
        chonRef.current = khiChonCum;
    });

    const [pins, setPins] = useState<MapPin[]>([]);
    const [meta, setMeta] = useState<MetaPin | null>(null);
    const [loi, setLoi] = useState<string | null>(null);

    /** Khung nhìn mà tập pin đang có được nạp từ đó. */
    const khungDaNapRef = useRef<Khung | null>(null);

    /** Lần nạp trước có chạm trần 500 pin không — xem `canNapLai`. */
    const daCatRef = useRef(false);

    /** Người dùng đã kéo ra khỏi vùng dữ liệu: hiện nút "Tìm trong khu vực này". */
    const [capNhat, setCapNhat] = useState(false);

    const cums = useMemo(() => gomTheoSite(pins), [pins]);

    /**
     * Bốn con số của khung nhìn — `null` cho tới khi nạp được một lần.
     *
     * Khoá theo `meta`, không theo `pins`: `meta` chỉ khác `null` sau một
     * phản hồi 200. Nạp hỏng mà vẫn tính thì dải tổng quan in **"0 vị trí"**,
     * tức nói với người mua rằng vùng này không có màn hình nào — trong khi
     * sự thật là chưa hỏi được. Đo thấy ngay ở máy, nơi `next start` không
     * rewrite `/api/v2` nên mọi lời gọi pin đều 404.
     *
     * Lần nạp sau hỏng thì `meta` giữ giá trị tốt cuối cùng, nên dải vẫn hiện
     * số của lần nạp gần nhất thay vì trống đi — cùng tập pin đang vẽ trên
     * bản đồ, nên hai bên vẫn khớp nhau.
     */
    const tomTat = useMemo(() => (meta ? tomTatKhung(pins, meta) : null), [pins, meta]);

    /**
     * Bộ lọc dưới dạng **chuỗi**, và deps của `napPin` là chuỗi đó.
     *
     * `thamSo` là một object mới ở mỗi lượt render của cha. Lấy thẳng nó làm
     * deps là gọi lại API mỗi lần cha render, kể cả khi không bộ lọc nào đổi.
     */
    const truyVan = useMemo(() => {
        const p = new URLSearchParams();

        for (const [k, v] of Object.entries(thamSo ?? {})) {
            if (v && !BO_QUA.has(k)) p.set(k, v);
        }

        return p.toString();
    }, [thamSo]);

    /**
     * Nạp pin cho khung nhìn hiện tại.
     *
     * Trả `meta` của lượt nạp, hoặc `null` khi lượt này bị bỏ (khung nhìn
     * không hợp lệ, lỗi mạng, hoặc một lượt mới đã chồng lên). Người gọi cần
     * nó: `bayToi` phải biết quanh người dùng có bao nhiêu vị trí để quyết
     * định có lùi về toàn quốc không.
     */
    const napPin = useCallback(
        async (map: LeafletMap): Promise<MetaPin | null> => {
            const L = (await import('leaflet')).default;
            const bounds = map.getBounds();
            const seq = ++seqRef.current;

            const khung: Khung = {
                north: bounds.getNorth(),
                south: bounds.getSouth(),
                east: bounds.getEast(),
                west: bounds.getWest(),
            };

            const params = new URLSearchParams(truyVan);
            params.set('north', String(khung.north));
            params.set('south', String(khung.south));
            params.set('east', String(khung.east));
            params.set('west', String(khung.west));

            try {
                const res = await fetch(`/api/v2/screens/map?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                });

                // 422 nghĩa là khung nhìn không hợp lệ — xảy ra khi bản đồ chưa
                // đo xong kích thước container. Bỏ qua, lần `moveend` sau sẽ
                // gọi lại với khung nhìn thật.
                if (!res.ok) {
                    if (res.status !== 422) setLoi('Không tải được dữ liệu bản đồ.');

                    return null;
                }

                const body = (await res.json()) as { data: MapPin[]; meta: MetaPin };

                // `seqRef` chống kết quả về trễ: kéo bản đồ nhanh thì có vài
                // lời gọi chồng nhau, và lời gọi cũ về sau sẽ vẽ lại pin của
                // vùng đã rời khỏi.
                if (seq !== seqRef.current) return null;

                setPins(body.data);
                setMeta(body.meta);
                setLoi(null);

                // Ghi lại khung nhìn mà tập pin này đến từ đó, và trần có bị
                // chạm không. Hai thứ đó là toàn bộ đầu vào của `canNapLai`.
                khungDaNapRef.current = khung;
                daCatRef.current = body.meta?.truncated ?? false;
                setCapNhat(false);

                const cluster = clusterRef.current;
                if (!cluster) return body.meta;

                cluster.clearLayers();
                markerRef.current.clear();

                const lop = gomTheoSite(body.data).map((cum) => {
                    const icon = L.divIcon({
                        className: 'oohx-pin oohx-pin--rich',
                        html: pinHtml(cum),
                        iconSize: [200, 48],
                        iconAnchor: [16, 48],
                    });

                    const marker = L.marker([cum.lat, cum.lng], { icon });
                    marker.on('click', () => {
                        chonRef.current?.(cum);
                        map.panTo([cum.lat, cum.lng]);
                    });

                    markerRef.current.set(cum.khoa, marker);

                    return marker;
                });

                cluster.addLayers(lop);

                return body.meta;
            } catch (e) {
                if (seq !== seqRef.current) return null;
                setLoi(e instanceof Error ? e.message : 'Không nạp được pin bản đồ.');
            }

            return null;
        },
        [truyVan],
    );

    /**
     * `napPin` **mới nhất**, giữ trong ref.
     *
     * Lỗi sửa ngày 22/10/2026, đo được trên production: effect dựng bản đồ có
     * deps `[]`, nên trình xử lý `moveend` đóng kín lời gọi `napPin` của lượt
     * render ĐẦU TIÊN — tức bộ lọc lúc trang mới mở. Mở
     * `/explore?screen_type=lcd&view=map`, danh sách ra 20 thẻ LCD, rồi thu
     * nhỏ bản đồ: lời gọi đi ra **không có** `screen_type=lcd` và bản đồ vẽ
     * lại 95 pin toàn kho. Một URL, hai tập dữ liệu, không có lỗi nào để lần
     * ra.
     *
     * Lát này gỡ `napPin` khỏi `moveend`, nên đường đó khép lại theo. Nhưng
     * ref vẫn phải có: `napLai()` của nút "Tìm trong khu vực này" đi qua đây,
     * và lần sau có ai gắn thêm một việc vào `moveend` thì nó đã đúng sẵn.
     */
    const napRef = useRef(napPin);

    useEffect(() => {
        napRef.current = napPin;
    });

    useEffect(() => {
        let huy = false;

        void (async () => {
            const L = (await import('leaflet')).default;
            await import('leaflet.markercluster');

            if (huy || !containerRef.current || mapRef.current) return;

            const map = L.map(containerRef.current, {
                center: VIETNAM.center,
                zoom: VIETNAM.zoom,
                zoomControl: false,
                attributionControl: false,
            });

            themLopNen(L, map);

            /*
             * `prefix: false`, không phải `true`.
             *
             * `prefix` của Leaflet là **chuỗi HTML** in trước phần ghi công
             * (mặc định là liên kết "Leaflet"), không phải cờ bật/tắt. Truyền
             * `true` thì Leaflet nối thẳng giá trị đó vào DOM, nên dòng ghi
             * công trên production đọc là **"true | © MapTiler ·
             * © OpenStreetMap"** — chữ `true` nằm ngay dưới bản đồ, và nhìn
             * thoáng qua trông như tên một đơn vị bị cắt mất đầu.
             *
             * `false` là cách tắt nó.
             */
            L.control.attribution({ position: 'bottomleft', prefix: false }).addTo(map);

            /*
             * `L` ở đây PHẢI là `.default`, không phải namespace của
             * `await import('leaflet')`.
             *
             * `leaflet.markercluster` là plugin UMD: nó gắn
             * `markerClusterGroup` lên **module.exports của leaflet**, tức
             * `L.default` trong ESM. Namespace object không nhận thuộc tính
             * thêm sau, nên gọi trên nó ném `markerClusterGroup is not a
             * function` — và lỗi đó chỉ hiện ở trình duyệt, không ở
             * `typecheck` hay ở chốt đọc HTML máy chủ render.
             */
            const cluster = (
                L as unknown as {
                    markerClusterGroup: (o: Record<string, unknown>) => MarkerClusterGroup;
                }
            ).markerClusterGroup({
                showCoverageOnHover: false,
                disableClusteringAtZoom: 16,
                chunkedLoading: true,
                iconCreateFunction: (c: { getChildCount: () => number }) => {
                    const { html, px } = cumHtml(c.getChildCount());

                    return L.divIcon({
                        className: 'marker-cluster',
                        html,
                        iconSize: L.point(px, px),
                    });
                },
            });

            map.addLayer(cluster);

            mapRef.current = map;
            clusterRef.current = cluster;

            /*
             * Kéo bản đồ KHÔNG nạp lại — nó chỉ bật nút "Tìm trong khu vực
             * này" (lát 4, 22/10/2026).
             *
             * Lý do là lý do của Google Maps: nạp theo mỗi cú kéo làm danh
             * sách nhảy dưới tay người đang đọc nó. Người mua rà một tuyến
             * đường kéo bản đồ hàng chục lần, và mỗi lần là một tập thẻ khác
             * — không ai đọc xong được một thẻ nào.
             *
             * Hệ quả phụ, và nó đáng kể: trước đây mỗi `moveend` là một lời
             * gọi API (hoãn 300ms, nhưng vẫn là một lời gọi cho mỗi cú kéo).
             * Giờ là **không lời gọi nào** cho tới khi người dùng yêu cầu.
             *
             * Phép quyết định nằm ở `canNapLai` và được chốt bằng số ở
             * `tests/js/khung-nhin.test.mjs` — ở đây chỉ còn đọc toạ độ.
             */
            map.on('moveend', () => {
                const b = map.getBounds();

                setCapNhat(
                    canNapLai(
                        khungDaNapRef.current,
                        {
                            north: b.getNorth(),
                            south: b.getSouth(),
                            east: b.getEast(),
                            west: b.getWest(),
                        },
                        daCatRef.current,
                    ),
                );
            });

            /*
             * Đo lại kích thước RỒI mới nạp lần đầu — hai việc, đúng thứ tự.
             *
             * Leaflet đo container ngay trong `L.map()`. Bản đồ nằm trong một
             * khung cao theo khung nhìn, và nếu trình duyệt chưa xong layout
             * tại thời điểm đó thì map có kích thước 0: không xin tile nào và
             * `getBounds()` trả một khung vô nghĩa.
             *
             * Trước lát 4 thứ tự ngược lại — nạp ngay rồi `invalidateSize()`
             * ở frame sau — và lượt nạp thật là lượt `moveend` theo sau cú đo
             * lại. Giờ `moveend` không nạp nữa, nên nếu giữ thứ tự cũ thì
             * khung nhìn ghi vào `khungDaNapRef` là khung 0×0, và nút "Tìm
             * trong khu vực này" bật ngay khi trang vừa mở.
             */
            requestAnimationFrame(() => {
                map.invalidateSize();
                void napRef.current(map);
            });
        })();

        return () => {
            huy = true;
            mapRef.current?.remove();
            mapRef.current = null;
            clusterRef.current = null;
            markerRef.current.clear();
        };
        // Bản đồ chỉ dựng MỘT lần — dựng lại là mất vị trí và mức thu phóng
        // người dùng đang xem. Lần nạp theo bộ lọc mới do effect dưới lo.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (mapRef.current) void napPin(mapRef.current);
    }, [napPin]);

    const thuPhong = useCallback((buoc: number) => {
        const map = mapRef.current;
        if (!map) return;

        map.setZoom(map.getZoom() + buoc);
    }, []);

    const vuaKhung = useCallback(async () => {
        const map = mapRef.current;
        if (!map || pins.length === 0) return;

        const L = (await import('leaflet')).default;
        map.fitBounds(
            L.latLngBounds(pins.map((p) => [p.lat, p.lng] as [number, number])),
            { padding: [40, 40], maxZoom: 14 },
        );
    }, [pins]);

    /**
     * Đo lại kích thước.
     *
     * Cần khi khung bản đồ vừa đổi từ ẩn sang hiện: Leaflet đo container lúc
     * dựng, và một container `display:none` đo ra 0×0. Không gọi lại thì bản
     * đồ hiện ra dưới dạng một ô xám không tile.
     */
    const doLai = useCallback(() => {
        mapRef.current?.invalidateSize();
    }, []);

    /** Nút "Tìm trong khu vực này": nạp pin cho khung nhìn đang xem. */
    const napLai = useCallback(() => {
        const map = mapRef.current;
        if (map) void napPin(map);
    }, [napPin]);

    /**
     * Kéo bản đồ tới một toạ độ rồi nạp pin ở đó — dùng cho "quanh tôi".
     *
     * `animate: false` là cố ý: Leaflet bắn `moveend` **ngay** khi không có
     * hoạt ảnh, nên `setCapNhat(true)` của trình xử lý đó và `setCapNhat(false)`
     * của lượt nạp rơi vào cùng một nhịp render của React. Để hoạt ảnh thì
     * nút "Tìm trong khu vực này" nhấp nháy một lần trong lúc bản đồ đang
     * bay — một cái nút tự bật rồi tự tắt là thứ người ta học cách không
     * nhìn.
     *
     * Trả `meta` để người gọi biết quanh đó có gì. `null` nghĩa là không nạp
     * được, khác `total: 0` nghĩa là nạp được và quanh đó trống.
     */
    const bayToi = useCallback(
        async (lat: number, lng: number, zoom = 12): Promise<MetaPin | null> => {
            const map = mapRef.current;

            if (!map) return null;

            map.setView([lat, lng], zoom, { animate: false });

            return napPin(map);
        },
        [napPin],
    );

    /** Về khung nhìn cả nước — lưới an toàn khi quanh người dùng trống. */
    const veToanQuoc = useCallback(async (): Promise<MetaPin | null> => {
        const map = mapRef.current;

        if (!map) return null;

        map.setView(VIETNAM.center, VIETNAM.zoom, { animate: false });

        return napPin(map);
    }, [napPin]);

    return {
        containerRef,
        mapRef,
        markerRef,
        pins,
        cums,
        meta,
        tomTat,
        loi,
        capNhat,
        napLai,
        bayToi,
        veToanQuoc,
        thuPhong,
        vuaKhung,
        doLai,
    };
}

export type { TomTat };

export type { CumSite, MapPin };
