'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import type { Map as LeafletMap, Marker } from 'leaflet';
import type { NearbyPoi } from '@/lib/api';
import { thoat } from '@/lib/ban-do';

/**
 * Khối "Vị trí trên bản đồ": danh sách POI nhóm theo loại ở bên trái, bản đồ
 * ở bên phải, bấm một bên thì bên kia nhảy theo.
 *
 * Dựng lại `loc-section` của `frontpage/detail.blade.php` (và phần JS kèm
 * nó). Bản Next trước đó **không có khối này**: trang chi tiết không có bản đồ
 * nào, dù `site.lat/lon` vẫn nằm trong CSDL.
 *
 * ══ CSS của khối này vừa được chuyển vào `frontpage.css` ══
 *
 * Toàn bộ luật `.loc-*`, `.poi-pin*`, `.screen-pin*` sống trong `@push('head')`
 * của view đã bị gỡ ở giai đoạn 7 — tức chúng biến mất cùng view. Lát 4 chuyển
 * chúng vào `frontpage.css`, và đổi `#loc-map` thành `.loc-map`: một id trong
 * CSS buộc mọi nơi dùng lại khối này phải đặt đúng id đó.
 *
 * ══ Marker là `divIcon`, nội dung do mình dựng, nên phải thoát ══
 *
 * Tên POI đến từ OSM — dữ liệu ngoài. Nó đi vào `innerHTML` của Leaflet qua
 * `divIcon`, nên mọi chuỗi phải qua `thoat()`. Cùng lý do `lib/ban-do.ts` thoát
 * tên màn hình: một popup không thoát là một lỗ XSS trên trang công khai.
 */

type Nhom = { khoa: string; nhan: string; icon: string; items: NearbyPoi[] };

export function KhoiViTri({
    lat,
    lng,
    tenSite,
    diaChi,
    tenManHinh,
    pois,
}: {
    lat: number;
    lng: number;
    tenSite: string;
    diaChi: string;
    tenManHinh: string;
    pois: NearbyPoi[];
}) {
    const hopRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<LeafletMap | null>(null);
    const markerRef = useRef<Map<string, Marker>>(new Map());

    const [an, setAn] = useState<Set<string>>(new Set());
    const [dangChon, setDangChon] = useState<string | null>(null);

    // Nhóm POI theo loại, giữ thứ tự API trả về (đã sắp theo khoảng cách).
    const nhoms: Nhom[] = useMemo(() => {
        const theoNhom = new Map<string, Nhom>();

        for (const poi of pois) {
            const khoa = poi.group ?? 'other';
            const co = theoNhom.get(khoa);

            if (co) {
                co.items.push(poi);
                continue;
            }

            theoNhom.set(khoa, {
                khoa,
                nhan: poi.label ?? khoa,
                icon: poi.icon ?? 'place',
                items: [poi],
            });
        }

        return [...theoNhom.values()];
    }, [pois]);

    const hien = useMemo(() => pois.filter((p) => !an.has(p.group ?? 'other')), [pois, an]);

    useEffect(() => {
        let huy = false;

        async function dung() {
            const L = await import('leaflet');

            if (huy || !hopRef.current || mapRef.current) return;

            const map = L.map(hopRef.current, {
                center: [lat, lng],
                zoom: 16,
                scrollWheelZoom: false,
            });

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap',
                maxZoom: 19,
            }).addTo(map);

            // Pin của chính màn hình: to hơn, có vòng nhấp nháy, và luôn ở trên.
            const screenIcon = L.divIcon({
                className: 'screen-pin',
                html:
                    '<div class="screen-pin-pulse"></div>' +
                    '<div class="screen-pin-inner"><span class="material-symbols-outlined">tv</span></div>',
                iconSize: [44, 44],
                iconAnchor: [22, 22],
            });

            L.marker([lat, lng], { icon: screenIcon, zIndexOffset: 1000 })
                .addTo(map)
                .bindPopup(
                    `<strong>${thoat(tenManHinh)}</strong><div>${thoat(diaChi)}</div>`,
                );

            mapRef.current = map;
        }

        dung();

        return () => {
            huy = true;
            mapRef.current?.remove();
            mapRef.current = null;
            markerRef.current.clear();
        };
    }, [lat, lng, tenManHinh, diaChi]);

    // Vẽ lại marker POI mỗi khi danh sách hiện đổi (bật/tắt nhóm).
    useEffect(() => {
        let huy = false;

        async function ve() {
            const L = await import('leaflet');
            const map = mapRef.current;

            if (huy || !map) return;

            for (const m of markerRef.current.values()) {
                m.remove();
            }
            markerRef.current.clear();

            for (const poi of hien) {
                if (poi.lat == null || poi.lng == null || !poi.id) continue;

                const icon = L.divIcon({
                    className: 'poi-pin',
                    html:
                        `<div class="poi-pin-inner" style="background:${thoat(poi.color ?? '#7F8C8D')}">` +
                        `<span class="material-symbols-outlined">${thoat(poi.icon ?? 'place')}</span></div>`,
                    iconSize: [30, 30],
                    iconAnchor: [15, 15],
                });

                const marker = L.marker([poi.lat, poi.lng], { icon })
                    .addTo(map)
                    .bindPopup(
                        `<strong>${thoat(poi.name ?? '')}</strong>` +
                            `<div>${thoat(poi.label ?? '')} · ${poi.dist_m ?? 0}m</div>`,
                    );

                marker.on('click', () => setDangChon(poi.id ?? null));
                markerRef.current.set(poi.id, marker);
            }
        }

        ve();

        return () => {
            huy = true;
        };
    }, [hien]);

    function nhayTới(poi: NearbyPoi) {
        setDangChon(poi.id ?? null);

        const map = mapRef.current;
        if (!map || poi.lat == null || poi.lng == null) return;

        map.setView([poi.lat, poi.lng], 18);
        if (poi.id) markerRef.current.get(poi.id)?.openPopup();
    }

    function doiNhom(khoa: string) {
        setAn((truoc) => {
            const moi = new Set(truoc);
            moi.has(khoa) ? moi.delete(khoa) : moi.add(khoa);

            return moi;
        });
    }

    return (
        <section className="loc-section">
            <div className="w">
                <div className="loc-head">
                    <div>
                        <div className="loc-eyebrow">VỊ TRÍ TRÊN BẢN ĐỒ</div>
                        <h2 className="loc-title">{tenSite || tenManHinh}</h2>
                        <div className="loc-addr">
                            <span
                                className="material-symbols-outlined"
                                style={{ fontSize: 16, color: 'var(--bl)', verticalAlign: -3 }}
                                aria-hidden="true"
                            >
                                location_on
                            </span>
                            {diaChi}
                        </div>
                    </div>

                    {pois.length ? (
                        <div className="loc-meta">
                            <span className="loc-poi-count">
                                <strong>{pois.length}</strong> POI lân cận ·{' '}
                                <strong>{nhoms.length}</strong> nhóm
                            </span>
                        </div>
                    ) : null}
                </div>

                <div className="loc-browser">
                    <div className="loc-list">
                        {pois.length === 0 ? (
                            <div className="loc-empty">
                                <span className="material-symbols-outlined" aria-hidden="true">
                                    explore_off
                                </span>
                                <div>Chưa có dữ liệu POI lân cận</div>
                                <div className="loc-empty-sub">
                                    Dữ liệu điểm quan tâm quanh vị trí này chưa được thu thập.
                                </div>
                            </div>
                        ) : (
                            <>
                                <div className="loc-filter">
                                    <button
                                        type="button"
                                        className={
                                            an.size === 0
                                                ? 'loc-filter-pill is-active'
                                                : 'loc-filter-pill'
                                        }
                                        onClick={() => setAn(new Set())}
                                    >
                                        <span className="material-symbols-outlined" aria-hidden="true">
                                            apps
                                        </span>
                                        Tất cả
                                        <span className="loc-filter-ct">{pois.length}</span>
                                    </button>

                                    {nhoms.map((n) => (
                                        <button
                                            key={n.khoa}
                                            type="button"
                                            className={
                                                an.has(n.khoa)
                                                    ? 'loc-filter-pill'
                                                    : 'loc-filter-pill is-active'
                                            }
                                            onClick={() => doiNhom(n.khoa)}
                                        >
                                            <span
                                                className="material-symbols-outlined"
                                                aria-hidden="true"
                                            >
                                                {n.icon}
                                            </span>
                                            {n.nhan}
                                            <span className="loc-filter-ct">{n.items.length}</span>
                                        </button>
                                    ))}
                                </div>

                                {nhoms
                                    .filter((n) => !an.has(n.khoa))
                                    .map((n) => (
                                        <div key={n.khoa}>
                                            <div className="loc-grp-h">
                                                <span
                                                    className="material-symbols-outlined"
                                                    aria-hidden="true"
                                                >
                                                    {n.icon}
                                                </span>
                                                {n.nhan}
                                                <span className="loc-grp-ct">{n.items.length}</span>
                                            </div>

                                            {n.items.map((poi) => (
                                                <div
                                                    key={poi.id ?? poi.name}
                                                    className={
                                                        dangChon === poi.id
                                                            ? 'loc-item is-active'
                                                            : 'loc-item'
                                                    }
                                                    onClick={() => nhayTới(poi)}
                                                    role="button"
                                                    tabIndex={0}
                                                    onKeyDown={(e) => {
                                                        if (e.key === 'Enter' || e.key === ' ')
                                                            nhayTới(poi);
                                                    }}
                                                >
                                                    <div
                                                        className="loc-dot"
                                                        style={{ background: poi.color ?? '#7F8C8D' }}
                                                    >
                                                        <span
                                                            className="material-symbols-outlined"
                                                            aria-hidden="true"
                                                        >
                                                            {poi.icon ?? 'place'}
                                                        </span>
                                                    </div>

                                                    <div className="loc-meta-c">
                                                        <div className="loc-name">{poi.name}</div>
                                                        <div className="loc-sub">
                                                            {poi.label} · <b>{poi.dist_m}m</b>
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ))}
                            </>
                        )}
                    </div>

                    <div className="loc-map-wrap">
                        <div className="loc-map" ref={hopRef} />
                    </div>
                </div>
            </div>
        </section>
    );
}
