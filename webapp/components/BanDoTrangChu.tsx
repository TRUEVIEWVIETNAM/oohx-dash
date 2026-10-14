'use client';

import { useEffect, useRef, useState } from 'react';
import type { Map as LeafletMap, MarkerClusterGroup } from 'leaflet';
import { popupHtml, type MapPin } from '@/lib/ban-do';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';

/**
 * Khối "Live Map View" của trang chủ.
 *
 * Dựng lại khối bản đồ nhúng trong `frontpage/index.blade.php` (thanh chọn
 * thành phố, dropdown "Khác", hai nút thu phóng, bộ đếm). Bản Next trước đây
 * **không có khối này** — `/` chỉ còn nút "Xem bản đồ" dẫn sang `/map`.
 *
 * ══ Khác `ScreenMap` ở điểm gì, và vì sao không dùng lại nó ══
 *
 * `ScreenMap` là bản đồ toàn trang của `/map`: cao 70vh, nạp theo khung nhìn
 * mỗi lần người dùng kéo, kèm dòng trạng thái về việc bị cắt ở 500 pin. Khối
 * này là một **ô xem trước** trong một mục của trang chủ: chiều cao do
 * `.map-mock` quyết, điều hướng bằng thanh thành phố chứ không bằng kéo chuột,
 * và nó có thanh dưới `.map-bar` của riêng nó.
 *
 * Gộp hai thứ đó vào một component sẽ là một component với hai chế độ và hai
 * bộ markup — nên chúng đứng riêng, và phần dùng chung thật sự (dựng Leaflet,
 * cụm pin, nội dung popup) nằm ở `lib/ban-do.ts`.
 *
 * ══ Pin lần đầu đến từ máy chủ ══
 *
 * `getHomeData()` đã gọi `/screens/pins?city=hanoi` cho trang chủ, nên lần vẽ
 * đầu không cần một lời gọi nào ở trình duyệt. Đổi thành phố mới gọi
 * `/api/v2/screens/map` — endpoint bản đồ **bắt buộc có khung nhìn**
 * (CLAUDE.md mục 2), nên lời gọi đó mang khung nhìn cả nước kèm `city`.
 *
 * Vì sao không dùng `/screens/pins` cho mọi lần: nó **không nhận** `city=all`
 * (`CityPinsRequest` chỉ nhận slug tỉnh thành có thật), mà thanh thành phố của
 * bản Blade có nút "Toàn quốc". Dùng endwith bản đồ cho cả hai nhánh thì một
 * đường, một hình dạng phản hồi, và bộ đếm là `meta.total` thật của vùng.
 */
const VIET_NAM = {
    center: [16.0, 107.5] as [number, number],
    zoom: 5.5,
    khung: { north: 23.5, south: 8.2, east: 109.6, west: 102.1 },
};

/** Ba thành phố bản Blade ghim sẵn trên thanh, theo đúng thứ tự đó. */
const GHIM = [
    { ma: 'hanoi', ten: 'Hà Nội' },
    { ma: 'hcm', ten: 'TP.HCM' },
    { ma: 'danang', ten: 'Đà Nẵng' },
];

export type ThanhPho = { code?: string | null; name?: string | null; count?: number };

export function BanDoTrangChu({
    pinBanDau,
    thanhPhoBanDau,
    tenThanhPhoBanDau,
    thanhPho = [],
}: {
    pinBanDau: MapPin[];
    thanhPhoBanDau: string;
    tenThanhPhoBanDau: string;
    thanhPho?: ThanhPho[];
}) {
    const oRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<LeafletMap | null>(null);
    const cumRef = useRef<MarkerClusterGroup | null>(null);
    const seqRef = useRef(0);

    const [pin, setPin] = useState<MapPin[]>(pinBanDau);
    const [dangChon, setDangChon] = useState(thanhPhoBanDau);
    const [tenChon, setTenChon] = useState(tenThanhPhoBanDau);
    const [tong, setTong] = useState<number | null>(pinBanDau.length);
    const [moKhac, setMoKhac] = useState(false);
    const [loi, setLoi] = useState<string | null>(null);

    // ── Dựng bản đồ một lần ─────────────────────────────────────────────────

    useEffect(() => {
        let huy = false;

        (async () => {
            const L = (await import('leaflet')).default;
            await import('leaflet.markercluster');

            if (huy || !oRef.current || mapRef.current) return;

            const map = L.map(oRef.current, {
                center: VIET_NAM.center,
                zoom: VIET_NAM.zoom,
                // Thu phóng bằng hai nút của thanh bên phải, đúng như bản
                // Blade: điều khiển mặc định của Leaflet nằm đè lên thanh chọn
                // thành phố.
                zoomControl: false,
                scrollWheelZoom: false,
            });

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19,
            }).addTo(map);

            const cum = (
                L as unknown as { markerClusterGroup: () => MarkerClusterGroup }
            ).markerClusterGroup();

            map.addLayer(cum);
            mapRef.current = map;
            cumRef.current = cum;

            veLai(L, pin);
        })();

        return () => {
            huy = true;
            mapRef.current?.remove();
            mapRef.current = null;
            cumRef.current = null;
        };
        // Chỉ dựng một lần: dựng lại là mất vị trí người dùng đang xem.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // ── Vẽ lại khi danh sách pin đổi ────────────────────────────────────────

    useEffect(() => {
        if (!mapRef.current) return;

        (async () => {
            const L = (await import('leaflet')).default;
            veLai(L, pin);
        })();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pin]);

    function veLai(L: typeof import('leaflet'), danhSach: MapPin[]) {
        const cum = cumRef.current;
        const map = mapRef.current;

        if (!cum || !map) return;

        cum.clearLayers();

        const toaDo: [number, number][] = [];

        for (const p of danhSach) {
            if (typeof p.lat !== 'number' || typeof p.lng !== 'number') continue;

            cum.addLayer(L.marker([p.lat, p.lng]).bindPopup(popupHtml(p)));
            toaDo.push([p.lat, p.lng]);
        }

        // Khung nhìn đi theo dữ liệu, không theo một bảng toạ độ viết cứng:
        // thêm một tỉnh vào `CITY_SLUG_MAP` thì bảng đó sẽ thiếu, và một bản
        // đồ mở ra ở giữa biển là lỗi khó truy.
        if (toaDo.length > 0) {
            map.fitBounds(L.latLngBounds(toaDo), { padding: [24, 24], maxZoom: 13 });
        } else {
            map.setView(VIET_NAM.center, VIET_NAM.zoom);
        }
    }

    // ── Đổi thành phố ───────────────────────────────────────────────────────

    async function chonThanhPho(ma: string, ten: string) {
        setDangChon(ma);
        setTenChon(ten);
        setMoKhac(false);
        setLoi(null);

        const seq = ++seqRef.current;
        const tham = new URLSearchParams({
            north: String(VIET_NAM.khung.north),
            south: String(VIET_NAM.khung.south),
            east: String(VIET_NAM.khung.east),
            west: String(VIET_NAM.khung.west),
        });

        if (ma !== 'all') tham.set('city', ma);

        try {
            const res = await fetch(`/api/v2/screens/map?${tham}`, {
                headers: { Accept: 'application/json' },
            });

            if (!res.ok) {
                setLoi('Không tải được dữ liệu bản đồ.');

                return;
            }

            const body = (await res.json()) as { data: MapPin[]; meta?: { total?: number } };

            // Người bấm nhanh nhiều thành phố thì có nhiều lời gọi chồng nhau;
            // chỉ kết quả của lần bấm mới nhất được vẽ.
            if (seq !== seqRef.current) return;

            setPin(body.data ?? []);
            setTong(body.meta?.total ?? body.data?.length ?? 0);
        } catch {
            if (seq === seqRef.current) setLoi('Không tải được dữ liệu bản đồ.');
        }
    }

    const khac = thanhPho.filter((c) => !GHIM.some((g) => g.ma === c.code));

    return (
        <div className="map-wrap">
            <div className="map-mock">
                <div ref={oRef} style={{ position: 'absolute', inset: 0, zIndex: 0 }} />

                <div className="hp-map-city-bar">
                    <button
                        className={dangChon === 'all' ? 'hmc-btn on' : 'hmc-btn'}
                        type="button"
                        onClick={() => chonThanhPho('all', 'toàn quốc')}
                    >
                        <svg
                            viewBox="0 0 24 24"
                            fill="currentColor"
                            style={{ width: 12, height: 12, flexShrink: 0 }}
                            aria-hidden="true"
                        >
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z" />
                        </svg>{' '}
                        Toàn quốc
                    </button>

                    {GHIM.map((g) => (
                        <button
                            className={dangChon === g.ma ? 'hmc-btn on' : 'hmc-btn'}
                            type="button"
                            key={g.ma}
                            onClick={() => chonThanhPho(g.ma, g.ten)}
                        >
                            {g.ten}
                        </button>
                    ))}

                    {khac.length > 0 ? (
                        <div className="hmc-more">
                            <button
                                className="hmc-btn hmc-more-trigger"
                                type="button"
                                onClick={() => setMoKhac(!moKhac)}
                            >
                                Khác{' '}
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="currentColor"
                                    style={{ width: 12, height: 12, flexShrink: 0 }}
                                    aria-hidden="true"
                                >
                                    <path d="M7 10l5 5 5-5z" />
                                </svg>
                            </button>

                            <div
                                className="hmc-dropdown"
                                style={moKhac ? { display: 'block' } : undefined}
                            >
                                <div className="hmc-dd-head">Chọn tỉnh/thành</div>
                                <div className="hmc-dd-list">
                                    {khac.map((c) => (
                                        <button
                                            className={
                                                dangChon === c.code
                                                    ? 'hmc-dd-item on'
                                                    : 'hmc-dd-item'
                                            }
                                            type="button"
                                            key={c.code}
                                            onClick={() =>
                                                chonThanhPho(c.code ?? '', c.name ?? '')
                                            }
                                        >
                                            {c.name}{' '}
                                            <span className="hmc-dd-count">
                                                {new Intl.NumberFormat('vi-VN').format(
                                                    c.count ?? 0,
                                                )}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                    ) : null}
                </div>

                <div className="hp-map-zoom">
                    <button
                        className="hm-zoom-btn"
                        type="button"
                        aria-label="Phóng to"
                        onClick={() => mapRef.current?.zoomIn()}
                    >
                        +
                    </button>
                    <button
                        className="hm-zoom-btn"
                        type="button"
                        aria-label="Thu nhỏ"
                        onClick={() => mapRef.current?.zoomOut()}
                    >
                        −
                    </button>
                </div>
            </div>

            <div className="map-bar">
                <div className="map-count">
                    {loi ? (
                        loi
                    ) : (
                        <>
                            <strong>
                                {new Intl.NumberFormat('vi-VN').format(tong ?? 0)}
                            </strong>{' '}
                            màn hình tại <span>{tenChon}</span>
                        </>
                    )}
                </div>
                <a
                    href="/map"
                    className="btn btn-s btn-xs"
                    style={{ flexShrink: 0, borderRadius: 8 }}
                >
                    Xem tất cả trên bản đồ →
                </a>
            </div>
        </div>
    );
}
