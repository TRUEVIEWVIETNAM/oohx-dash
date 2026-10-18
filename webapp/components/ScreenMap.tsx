'use client';

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import type { Map as LeafletMap, MarkerClusterGroup } from 'leaflet';
import {
    cumHtml,
    gomTheoSite,
    mauPin,
    pinHtml,
    type CumSite,
    type MapPin,
} from '@/lib/ban-do';
import type { Filters, Region } from '@/lib/api';
import { BoLocThongMinh } from '@/components/BoLocThongMinh';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster/dist/MarkerCluster.css';

/**
 * Trang bản đồ: panel danh sách bên trái, bản đồ bên phải.
 *
 * Dựng lại `frontpage/map.blade.php` (510 dòng) cùng `map-shared-js` (232) và
 * `smart-filter-js` (98). Bản Next trước lát 6 chỉ có **bản đồ và một dòng
 * đếm**: không bộ lọc hiện ra, không danh sách, không thanh thành phố, không
 * nút thu phóng, không popup riêng.
 *
 * ══ Nạp pin theo KHUNG NHÌN, không nạp một lần rồi thôi ══
 *
 * Bản Blade gọi `getMapPins($request)` **không có khung nhìn**: nó lấy mọi màn
 * hình có toạ độ rồi gom cụm ở trình duyệt. Với 104 màn hình thì chạy được.
 *
 * `/api/v2/screens/map` **bắt buộc** có khung nhìn (CLAUDE.md mục 2), và lý do
 * nằm trong `MapViewportRequest`: không có khung nhìn thì "lấy pin bản đồ"
 * nghĩa là lấy toàn bộ kho dưới một cái tên vô hại. Trên khung nhìn vẫn còn
 * giới hạn cứng 500 pin.
 *
 * ══ `meta.truncated` phải nói ra ══
 *
 * Khi API cắt ở 500 pin, nó trả `truncated: true` và `total` trong khung nhìn
 * đang hỏi. Im lặng ở chỗ này là để người dùng tin rằng vùng đó chỉ có 500 màn
 * hình — một con số sai mà bản đồ trông vẫn bình thường.
 *
 * ══ Gom theo site ở client, không ở máy chủ ══
 *
 * Bản Blade gom pin theo `site_id` **trước khi** gửi xuống, nên một marker là
 * một toà nhà. `/api/v2/screens/map` trả theo màn hình, và hợp đồng đó không
 * đổi vì nó là dữ liệu thô nhiều bên dùng. `gomTheoSite()` trong
 * `lib/ban-do.ts` làm việc gom, khóa là tên site + toạ độ — DTO bản đồ cố ý
 * không trả khóa nội bộ.
 *
 * ══ Leaflet từ npm, không từ unpkg ══
 *
 * Bản Blade nạp `leaflet` và `leaflet.markercluster` từ `unpkg.com`, tức trang
 * công khai phụ thuộc một CDN bên thứ ba lúc chạy. Ở đây chúng là dependency
 * npm nên đi cùng bản build.
 */

/** Khung nhìn ban đầu: cả Việt Nam. */
const VIETNAM = {
    center: [16.0, 107.5] as [number, number],
    zoom: 5.5,
};

/**
 * Toạ độ tâm của các tỉnh thành có nút riêng trên thanh công cụ.
 *
 * Cùng bảng `OOHXMap.CITY_COORDS` của bản Blade. Tỉnh không có trong bảng vẫn
 * bấm được: khi đó bản đồ **fit theo chính các pin trả về** thay vì bay tới
 * một toạ độ viết cứng — cùng cách `BanDoTrangChu` (lát 2) làm, và là lý do
 * thêm một tỉnh vào hệ thống không làm bản đồ mở ra giữa biển.
 */
const TOA_DO_TINH: Record<string, [number, number, number]> = {
    hanoi: [21.0285, 105.8542, 12],
    hcm: [10.7769, 106.7009, 12],
    danang: [16.0544, 108.2022, 13],
    haiphong: [20.8449, 106.6881, 13],
    cantho: [10.0452, 105.7469, 13],
    thainguyen: [21.5928, 105.8442, 13],
    namdinh: [20.4388, 106.1621, 13],
    thanhhoa: [19.8067, 105.7852, 13],
    dongnai: [10.9574, 106.8426, 12],
};

/** Số nút thành phố hiện thẳng trên thanh; phần còn lại vào dropdown "Khác". */
const SO_NUT_TINH = 5;

function soVN(n: number): string {
    return new Intl.NumberFormat('vi-VN').format(n);
}

function giaCum(cum: CumSite): string | null {
    const p = cum.manHinh[0]?.price;

    if (!p?.amount) return null;

    const tien =
        (p.currency ?? 'VND') === 'VND'
            ? `${soVN(p.amount)} ₫`
            : `${soVN(p.amount)} ${p.currency}`;

    return p.unit ? `${tien}/${p.unit}` : tien;
}

export function ScreenMap({
    filters: thamSo,
    regions = [],
    boLoc,
}: {
    /** Tham số URL đang áp dụng — truyền thẳng cho API và cho bộ lọc. */
    filters?: Record<string, string | undefined>;
    regions?: Region[];
    boLoc: Filters | null;
}) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<LeafletMap | null>(null);
    const clusterRef = useRef<MarkerClusterGroup | null>(null);
    const seqRef = useRef(0);

    const [pins, setPins] = useState<MapPin[]>([]);
    const [meta, setMeta] = useState<{ returned: number; total: number; truncated: boolean } | null>(
        null,
    );
    const [loi, setLoi] = useState<string | null>(null);
    const [dangChon, setDangChon] = useState<CumSite | null>(null);
    const [moPanel, setMoPanel] = useState(false);
    const [tinhDangXem, setTinhDangXem] = useState<string>('all');
    const [moDropdown, setMoDropdown] = useState(false);

    const cums = useMemo(() => gomTheoSite(pins), [pins]);
    const soManHinh = pins.length;

    // Tỉnh thành cho thanh công cụ: nhiều màn hình nhất trước.
    const tinhThanh = useMemo(
        () =>
            (boLoc?.cities ?? [])
                .filter((c) => c.code)
                .map((c) => ({ ma: c.code as string, ten: c.name ?? '', dem: c.count ?? 0 })),
        [boLoc],
    );

    const napPin = useCallback(
        async (map: LeafletMap) => {
            const L = (await import('leaflet')).default;
            const bounds = map.getBounds();
            const seq = ++seqRef.current;

            const params = new URLSearchParams({
                north: String(bounds.getNorth()),
                south: String(bounds.getSouth()),
                east: String(bounds.getEast()),
                west: String(bounds.getWest()),
            });

            for (const [k, v] of Object.entries(thamSo ?? {})) {
                if (v && k !== 'page') params.set(k, v);
            }

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

                const body = (await res.json()) as {
                    data: MapPin[];
                    meta: { returned: number; total: number; truncated: boolean };
                };

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

                const lop = gomTheoSite(body.data).map((cum) => {
                    const icon = L.divIcon({
                        className: 'oohx-pin oohx-pin--rich',
                        html: pinHtml(cum),
                        iconSize: [200, 48],
                        iconAnchor: [16, 48],
                    });

                    const marker = L.marker([cum.lat, cum.lng], { icon });
                    marker.on('click', () => {
                        setDangChon(cum);
                        map.panTo([cum.lat, cum.lng]);
                    });

                    return marker;
                });

                cluster.addLayers(lop);
            } catch (e) {
                if (seq !== seqRef.current) return;
                setLoi(e instanceof Error ? e.message : 'Không nạp được pin bản đồ.');
            }
        },
        [thamSo],
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

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
            }).addTo(map);

            L.control
                .attribution({ position: 'bottomleft', prefix: false })
                .addAttribution('© <a href="https://openstreetmap.org">OSM</a>')
                .addTo(map);

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

            // Leaflet đo container ngay trong `L.map()`. Bản đồ này nằm trong
            // một flex layout cao `100vh - hdr`, và nếu trình duyệt chưa xong
            // layout tại thời điểm đó thì map có kích thước 0 — không xin tile
            // nào và `getBounds()` trả một khung vô nghĩa. Đo lại ở frame sau.
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
        };
        // Bản đồ chỉ dựng MỘT lần — dựng lại là mất vị trí và mức thu phóng
        // người dùng đang xem. Lần nạp theo bộ lọc mới do effect dưới lo.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (mapRef.current) void napPin(mapRef.current);
    }, [napPin]);

    function thuPhong(buoc: number) {
        const map = mapRef.current;
        if (!map) return;

        map.setZoom(map.getZoom() + buoc);
    }

    async function vuaKhung() {
        const map = mapRef.current;
        if (!map || pins.length === 0) return;

        const L = (await import('leaflet')).default;
        map.fitBounds(
            L.latLngBounds(pins.map((p) => [p.lat, p.lng] as [number, number])),
            { padding: [40, 40], maxZoom: 14 },
        );
    }

    async function chonTinh(ma: string) {
        const map = mapRef.current;
        setTinhDangXem(ma);
        setMoDropdown(false);

        if (!map) return;

        if (ma === 'all') {
            map.setView(VIETNAM.center, VIETNAM.zoom);

            return;
        }

        const toaDo = TOA_DO_TINH[ma];

        if (toaDo) {
            map.flyTo([toaDo[0], toaDo[1]], toaDo[2]);

            return;
        }

        // Tỉnh không có trong bảng toạ độ: hỏi API pin của tỉnh đó rồi fit
        // theo chính chúng. Thêm một tỉnh vào hệ thống vì thế không cần sửa
        // bảng trên — thiếu phép này thì bản đồ mở ra ở giữa biển.
        try {
            const res = await fetch(
                `/api/v2/screens/pins?city=${encodeURIComponent(ma)}&limit=200`,
                { headers: { Accept: 'application/json' } },
            );

            if (!res.ok) return;

            const body = (await res.json()) as { data: MapPin[] };

            if (!body.data.length) return;

            const L = (await import('leaflet')).default;
            map.fitBounds(
                L.latLngBounds(body.data.map((p) => [p.lat, p.lng] as [number, number])),
                { padding: [40, 40], maxZoom: 13 },
            );
        } catch {
            // Không fit được thì giữ nguyên khung nhìn — thà không nhảy còn
            // hơn nhảy tới một chỗ sai.
        }
    }

    const nutTinh = tinhThanh.slice(0, SO_NUT_TINH);
    const tinhConLai = tinhThanh.slice(SO_NUT_TINH);

    return (
        <div className="map-layout">
            <div className={moPanel ? 'map-panel open' : 'map-panel'}>
                <div
                    className="mp-drag"
                    onClick={() => setMoPanel(false)}
                    role="button"
                    tabIndex={0}
                    aria-label="Đóng danh sách"
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') setMoPanel(false);
                    }}
                >
                    <div className="mp-drag-bar" />
                </div>

                <BoLocThongMinh target="/map" regions={regions} filters={boLoc} params={thamSo} />

                <div className="mp-count">
                    <span>
                        Hiển thị <strong>{soVN(soManHinh)}</strong> kết quả
                    </span>
                </div>

                <div className="mp-list">
                    {loi ? (
                        <div style={{ padding: 16, fontSize: 13, color: 'var(--red)' }}>{loi}</div>
                    ) : cums.length === 0 ? (
                        <div style={{ padding: 16, fontSize: 13, color: 'var(--t4)' }}>
                            Không có vị trí nào trong vùng đang xem.
                        </div>
                    ) : (
                        cums.slice(0, 80).map((cum) =>
                            cum.manHinh.length > 1 ? (
                                <div className="mp-site-group" key={cum.khoa}>
                                    <div
                                        className={
                                            dangChon?.khoa === cum.khoa
                                                ? 'mp-site-header active'
                                                : 'mp-site-header'
                                        }
                                        onClick={() => {
                                            setDangChon(cum);
                                            mapRef.current?.panTo([cum.lat, cum.lng]);
                                        }}
                                        role="button"
                                        tabIndex={0}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') setDangChon(cum);
                                        }}
                                    >
                                        <div className="mp-site-name">{cum.tenSite}</div>
                                        <div className="mp-site-meta">
                                            <span>{cum.city ?? ''}</span>
                                            <span className="mp-site-badge">
                                                {cum.manHinh.length} màn hình
                                            </span>
                                        </div>
                                    </div>

                                    {cum.manHinh.slice(0, 5).map((pin) => (
                                        <TheManHinh key={pin.slug} pin={pin} nested />
                                    ))}

                                    {cum.manHinh.length > 5 ? (
                                        <div className="mp-site-more">
                                            +{cum.manHinh.length - 5} màn hình khác
                                        </div>
                                    ) : null}
                                </div>
                            ) : (
                                <TheManHinh key={cum.khoa} pin={cum.manHinh[0]} />
                            ),
                        )
                    )}
                </div>
            </div>

            <div className="map-area">
                <div
                    ref={containerRef}
                    className="map-canvas"
                    aria-label="Bản đồ màn hình quảng cáo"
                    role="application"
                />

                <div className="map-toolbar">
                    <button
                        type="button"
                        className={tinhDangXem === 'all' ? 'mt-btn on' : 'mt-btn'}
                        onClick={() => void chonTinh('all')}
                    >
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style={{ width: 14, height: 14 }}>
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z" />
                        </svg>{' '}
                        Tất cả
                    </button>

                    <div className="mt-top-chips">
                        {nutTinh.map((t) => (
                            <button
                                key={t.ma}
                                type="button"
                                className={tinhDangXem === t.ma ? 'mt-btn on' : 'mt-btn'}
                                onClick={() => void chonTinh(t.ma)}
                            >
                                {t.ten}
                            </button>
                        ))}
                    </div>

                    {tinhConLai.length ? (
                        <div className="mt-more">
                            <button
                                type="button"
                                className="mt-btn mt-more-trigger"
                                onClick={() => setMoDropdown(!moDropdown)}
                                aria-expanded={moDropdown}
                            >
                                Khác{' '}
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style={{ width: 12, height: 12 }}>
                                    <path d="M7 10l5 5 5-5z" />
                                </svg>
                            </button>

                            <div className={moDropdown ? 'mt-dropdown open' : 'mt-dropdown'}>
                                <div className="mt-dd-head">Chọn tỉnh/thành</div>
                                <div className="mt-dd-list">
                                    {tinhConLai.map((t) => (
                                        <button
                                            key={t.ma}
                                            type="button"
                                            className={
                                                tinhDangXem === t.ma ? 'mt-dd-item on' : 'mt-dd-item'
                                            }
                                            onClick={() => void chonTinh(t.ma)}
                                        >
                                            {t.ten}
                                            <span className="mt-dd-count">{t.dem}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                    ) : null}
                </div>

                <div className="map-count-badge">
                    <strong>{soVN(cums.length)}</strong> vị trí đang hiển thị
                    {meta?.truncated ? (
                        // Nói ra khi API đã cắt. Im lặng ở đây là để người
                        // dùng tin rằng vùng này chỉ có chừng ấy màn hình.
                        <span style={{ display: 'block', fontSize: 11, color: 'var(--org)' }}>
                            Đã cắt ở {soVN(meta.returned)}/{soVN(meta.total)} — thu nhỏ vùng xem
                        </span>
                    ) : null}
                </div>

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

                {dangChon ? <PopupBanDo cum={dangChon} dong={() => setDangChon(null)} /> : null}

                <button type="button" className="panel-toggle" onClick={() => setMoPanel(true)}>
                    <svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true" style={{ width: 16, height: 16 }}>
                        <path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z" />
                    </svg>
                    Danh sách ({soVN(soManHinh)})
                </button>
            </div>
        </div>
    );
}

/** Một thẻ màn hình trong panel trái. */
function TheManHinh({ pin, nested = false }: { pin: MapPin; nested?: boolean }) {
    const gia = pin.price?.amount
        ? (pin.price.currency ?? 'VND') === 'VND'
            ? `${soVN(pin.price.amount)} ₫`
            : `${soVN(pin.price.amount)} ${pin.price.currency}`
        : null;

    return (
        <Link
            href={`/explore/${pin.slug}`}
            className={nested ? 'mp-card mp-card-nested' : 'mp-card'}
        >
            <div className="mp-card-img">
                {pin.photo_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={pin.photo_url} loading="lazy" alt={pin.name ?? ''} />
                ) : null}
            </div>

            <div className="mp-card-info">
                <div className="mp-card-nm">{pin.name}</div>

                {gia ? (
                    <div className="mp-card-pr">
                        {gia}
                        <span>/{pin.price?.unit ?? 'tháng'}</span>
                    </div>
                ) : (
                    <div className="mp-card-pr mp-card-pr-quote">Liên hệ báo giá</div>
                )}
            </div>
        </Link>
    );
}

/**
 * Popup của bản đồ.
 *
 * Bản Blade dựng một `div#popup` cố định ngoài bản đồ rồi điền bằng JS, chứ
 * không dùng popup của Leaflet — vì nó có bố cục riêng (ảnh, giá, danh sách
 * màn hình trong site) và phải đứng yên khi bản đồ di chuyển. Giữ nguyên cách
 * đó, nhưng nội dung là JSX nên không có chuỗi HTML nào phải tự thoát.
 */
function PopupBanDo({ cum, dong }: { cum: CumSite; dong: () => void }) {
    const gia = giaCum(cum);
    const dau = cum.manHinh[0];

    return (
        <div className="map-popup">
            <button type="button" className="mpop-close" onClick={dong} aria-label="Đóng">
                ×
            </button>

            <div className="mpop-img">
                {dau?.photo_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={dau.photo_url} alt={cum.tenSite} />
                ) : null}
            </div>

            <div className="mpop-body">
                <div className="mpop-bc">
                    {[cum.city, cum.networkName].filter(Boolean).join(' · ')}
                </div>

                <div className="mpop-name">{cum.tenSite}</div>

                {cum.address ? <div className="mpop-loc">{cum.address}</div> : null}

                {gia ? (
                    <div className="mpop-price">
                        {gia}
                        <span className="mpop-price-unit">/{dau?.price?.unit ?? 'tháng'}</span>
                    </div>
                ) : (
                    <div className="mpop-price mpop-price-quote">Liên hệ báo giá</div>
                )}

                {cum.manHinh.length > 1 ? (
                    <div className="mpop-list">
                        <div className="mpop-list-title">{cum.manHinh.length} màn hình tại đây</div>
                        <div className="mpop-list-body">
                            {cum.manHinh.slice(0, 5).map((p) => (
                                <Link key={p.slug} href={`/explore/${p.slug}`} className="mpop-product">
                                    {p.name}
                                </Link>
                            ))}
                        </div>
                    </div>
                ) : null}

                <div className="mpop-actions">
                    <Link href={`/explore/${dau?.slug ?? ''}`} className="btn btn-p btn-sm">
                        Xem chi tiết
                    </Link>
                </div>
            </div>

            <div className="mpop-foot">
                <span
                    className="oohx-dot"
                    style={{ background: `var(--${mauPin(cum.venueType) === 'bl' ? 'bl' : mauPin(cum.venueType)})` }}
                />
                {cum.ownerName}
            </div>
        </div>
    );
}
