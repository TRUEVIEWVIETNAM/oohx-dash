'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import type { Map as LeafletMap, MarkerClusterGroup } from 'leaflet';
import type { components } from '@api-types';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';

type MapPin = components['schemas']['MapPin'];

/**
 * Bản đồ màn hình. Client component — Leaflet cần DOM.
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
 * Nên bản này nạp lại theo từng lần người dùng di chuyển bản đồ. Hôm nay kết
 * quả giống bản Blade vì cả kho nằm trong một khung nhìn Việt Nam; khi kho lớn
 * lên, nó hiện pin của vùng đang xem thay vì âm thầm cắt mất phần còn lại.
 *
 * ══ `meta.truncated` phải nói ra ══
 *
 * Khi API cắt ở 500 pin, nó trả `truncated: true` và `total` trong khung nhìn
 * đang hỏi. Im lặng ở chỗ này là để người dùng tin rằng vùng đó chỉ có 500 màn
 * hình — một con số sai mà bản đồ trông vẫn bình thường.
 *
 * ══ Leaflet từ npm, không từ unpkg ══
 *
 * Bản Blade nạp `leaflet` và `leaflet.markercluster` từ `unpkg.com`. Nghĩa là
 * trang công khai phụ thuộc một CDN bên thứ ba lúc chạy: unpkg chậm hoặc chặn
 * thì bản đồ không hiện, và không có gì ở phía mình để sửa. Ở đây chúng là
 * dependency npm, nên chúng đi cùng bản build.
 */

/** Khung nhìn ban đầu: cả Việt Nam. */
const VIETNAM = {
    center: [16.0, 107.5] as [number, number],
    zoom: 5.5,
};

export function ScreenMap({ filters }: { filters?: Record<string, string | undefined> }) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<LeafletMap | null>(null);
    const clusterRef = useRef<MarkerClusterGroup | null>(null);
    const seqRef = useRef(0);

    const [meta, setMeta] = useState<{ returned: number; total: number; truncated: boolean } | null>(
        null,
    );
    const [loi, setLoi] = useState<string | null>(null);

    /**
     * Nạp pin cho khung nhìn hiện tại.
     *
     * `seqRef` chống kết quả về trễ: người dùng kéo bản đồ nhanh thì có vài lời
     * gọi chồng nhau, và lời gọi cũ về sau sẽ vẽ lại pin của vùng đã rời khỏi.
     * Chỉ kết quả của lần gọi mới nhất được dùng.
     */
    const napPin = useCallback(
        async (map: LeafletMap) => {
            const L = await import('leaflet');
            const bounds = map.getBounds();
            const seq = ++seqRef.current;

            const params = new URLSearchParams({
                north: String(bounds.getNorth()),
                south: String(bounds.getSouth()),
                east: String(bounds.getEast()),
                west: String(bounds.getWest()),
            });

            for (const [key, value] of Object.entries(filters ?? {})) {
                if (value) params.set(key, value);
            }

            try {
                const response = await fetch(`/api/v2/screens/map?${params}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    // 422 nghĩa là khung nhìn không hợp lệ — xảy ra khi bản đồ
                    // chưa đo xong kích thước container. Bỏ qua, lần `moveend`
                    // sau sẽ gọi lại với khung nhìn thật.
                    if (response.status !== 422) {
                        setLoi('Không tải được dữ liệu bản đồ.');
                    }

                    return;
                }

                const body = (await response.json()) as {
                    data: MapPin[];
                    meta: { returned: number; total: number; truncated: boolean };
                };

                if (seq !== seqRef.current) return;

                setLoi(null);
                setMeta(body.meta);

                const cluster = clusterRef.current;
                if (!cluster) return;

                cluster.clearLayers();

                for (const pin of body.data) {
                    const marker = L.marker([pin.lat, pin.lng], {
                        title: pin.name,
                    });

                    marker.bindPopup(popupHtml(pin));
                    cluster.addLayer(marker);
                }
            } catch {
                if (seq === seqRef.current) setLoi('Không tải được dữ liệu bản đồ.');
            }
        },
        [filters],
    );

    useEffect(() => {
        if (!containerRef.current || mapRef.current) return;

        let huy = false;
        let hen: ReturnType<typeof setTimeout> | undefined;

        (async () => {
            const L = await import('leaflet');
            await import('leaflet.markercluster');

            if (huy || !containerRef.current) return;

            const map = L.map(containerRef.current, {
                center: VIETNAM.center,
                zoom: VIETNAM.zoom,
                scrollWheelZoom: true,
            });

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
            }).addTo(map);

            const cluster = (L as unknown as { markerClusterGroup: () => MarkerClusterGroup })
                .markerClusterGroup();
            map.addLayer(cluster);

            mapRef.current = map;
            clusterRef.current = cluster;

            // Gọi lại sau mỗi lần di chuyển, có hoãn 300ms: kéo bản đồ sinh
            // hàng chục sự kiện `moveend`, và gọi API cho từng cái là tự làm
            // cạn hạn mức tần suất của chính mình.
            const moveend = () => {
                if (hen) clearTimeout(hen);
                hen = setTimeout(() => void napPin(map), 300);
            };

            map.on('moveend', moveend);

            void napPin(map);
        })();

        return () => {
            huy = true;
            if (hen) clearTimeout(hen);
            mapRef.current?.remove();
            mapRef.current = null;
            clusterRef.current = null;
        };
        // `napPin` đổi khi `filters` đổi, nhưng bản đồ chỉ dựng MỘT lần —
        // dựng lại là mất vị trí và mức thu phóng người dùng đang xem. Lần nạp
        // theo bộ lọc mới do effect dưới lo.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Bộ lọc đổi thì nạp lại trên đúng khung nhìn hiện tại.
    useEffect(() => {
        if (mapRef.current) void napPin(mapRef.current);
    }, [napPin]);

    return (
        <div>
            <div
                ref={containerRef}
                style={{ height: '70vh', minHeight: 420, width: '100%' }}
                aria-label="Bản đồ màn hình quảng cáo"
                role="application"
            />

            <div style={{ padding: '12px 0', fontSize: 14, color: 'var(--t2)' }}>
                {loi ? (
                    <span style={{ color: 'var(--rd, #d00)' }}>{loi}</span>
                ) : meta ? (
                    <>
                        Hiện {new Intl.NumberFormat('vi-VN').format(meta.returned)} trên{' '}
                        {new Intl.NumberFormat('vi-VN').format(meta.total)} màn hình trong vùng
                        đang xem.
                        {meta.truncated ? (
                            <strong>
                                {' '}
                                Danh sách đã bị cắt — thu nhỏ vùng xem để thấy đủ.
                            </strong>
                        ) : null}
                    </>
                ) : (
                    'Đang tải bản đồ…'
                )}
            </div>
        </div>
    );
}

/**
 * Nội dung popup.
 *
 * Mọi giá trị đi qua `thoat()` trước khi vào HTML: tên màn hình và địa chỉ do
 * media owner nhập, nên chúng là dữ liệu người dùng nhập đi thẳng vào
 * `innerHTML` của Leaflet. Không thoát là một lỗ XSS trên trang công khai.
 */
function popupHtml(pin: MapPin): string {
    const dong: string[] = [
        `<strong>${thoat(pin.name)}</strong>`,
    ];

    if (pin.address || pin.city) {
        dong.push(`<div>${thoat([pin.address, pin.city].filter(Boolean).join(', '))}</div>`);
    }

    if (pin.owner?.name) {
        dong.push(`<div>${thoat(pin.owner.name)}</div>`);
    }

    const gia = giaPin(pin);
    if (gia) dong.push(`<div>${thoat(gia)}</div>`);

    dong.push(`<a href="/explore/${encodeURIComponent(pin.slug)}">Xem chi tiết</a>`);

    return dong.join('');
}

function giaPin(pin: MapPin): string | null {
    const price = pin.price as
        | { amount?: number | null; currency?: string | null; unit?: string | null }
        | undefined;

    if (!price?.amount) return null;

    const so = new Intl.NumberFormat('vi-VN').format(price.amount);
    const tien = (price.currency || 'VND') === 'VND' ? `${so} ₫` : `${so} ${price.currency}`;

    return price.unit ? `${tien}/${price.unit}` : tien;
}

function thoat(s: string): string {
    return s
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
