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

/**
 * Lõi dùng chung của mọi bản đồ đọc `GET /api/v2/screens/map`.
 *
 * ══ Vì sao tách ra khỏi `ScreenMap` ══
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

    const cums = useMemo(() => gomTheoSite(pins), [pins]);

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

    const napPin = useCallback(
        async (map: LeafletMap) => {
            const L = (await import('leaflet')).default;
            const bounds = map.getBounds();
            const seq = ++seqRef.current;

            const params = new URLSearchParams(truyVan);
            params.set('north', String(bounds.getNorth()));
            params.set('south', String(bounds.getSouth()));
            params.set('east', String(bounds.getEast()));
            params.set('west', String(bounds.getWest()));

            try {
                const res = await fetch(`/api/v2/screens/map?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                });

                // 422 nghĩa là khung nhìn không hợp lệ — xảy ra khi bản đồ chưa
                // đo xong kích thước container. Bỏ qua, lần `moveend` sau sẽ
                // gọi lại với khung nhìn thật.
                if (!res.ok) {
                    if (res.status !== 422) setLoi('Không tải được dữ liệu bản đồ.');

                    return;
                }

                const body = (await res.json()) as { data: MapPin[]; meta: MetaPin };

                // `seqRef` chống kết quả về trễ: kéo bản đồ nhanh thì có vài
                // lời gọi chồng nhau, và lời gọi cũ về sau sẽ vẽ lại pin của
                // vùng đã rời khỏi.
                if (seq !== seqRef.current) return;

                setPins(body.data);
                setMeta(body.meta);
                setLoi(null);

                const cluster = clusterRef.current;
                if (!cluster) return;

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
            } catch (e) {
                if (seq !== seqRef.current) return;
                setLoi(e instanceof Error ? e.message : 'Không nạp được pin bản đồ.');
            }
        },
        [truyVan],
    );

    useEffect(() => {
        let huy = false;
        let hen: ReturnType<typeof setTimeout> | undefined;

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

            L.control.attribution({ position: 'bottomleft', prefix: true }).addTo(map);

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

            // Leaflet đo container ngay trong `L.map()`. Bản đồ nằm trong một
            // khung có chiều cao tính theo khung nhìn, và nếu trình duyệt chưa
            // xong layout tại thời điểm đó thì map có kích thước 0 — không xin
            // tile nào và `getBounds()` trả một khung vô nghĩa. Đo lại ở frame
            // sau.
            requestAnimationFrame(() => map.invalidateSize());

            // Hoãn 300ms: kéo bản đồ sinh hàng chục sự kiện `moveend`, và gọi
            // API cho từng cái là tự làm cạn hạn mức tần suất của chính mình.
            map.on('moveend', () => {
                if (hen) clearTimeout(hen);
                hen = setTimeout(() => void napPin(map), 300);
            });

            void napPin(map);
        })();

        return () => {
            huy = true;
            if (hen) clearTimeout(hen);
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

    return {
        containerRef,
        mapRef,
        markerRef,
        pins,
        cums,
        meta,
        loi,
        thuPhong,
        vuaKhung,
        doLai,
    };
}

export type { CumSite, MapPin };
