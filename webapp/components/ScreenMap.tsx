'use client';

import { useCallback, useMemo, useState } from 'react';
import Link from 'next/link';
import { soVN, type CumSite, type MapPin } from '@/lib/ban-do';
import { useBanDoPin, VIETNAM } from '@/lib/dung-ban-do';
import { dungKeoSheet } from '@/lib/dung-keo-sheet';
import type { Filters, Region } from '@/lib/api';
import { BoLocThongMinh } from '@/components/BoLocThongMinh';
import { PopupBanDo } from '@/components/PopupBanDo';
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

/*
 * Khung nhìn ban đầu, phép nạp pin và phép gom cụm nay ở `lib/dung-ban-do.ts`,
 * còn popup ở `components/PopupBanDo.tsx`: lát 3 đặt một bản đồ thứ hai cạnh
 * danh sách `/explore`, và hai bản của cùng một luật gọi API sẽ trôi khỏi
 * nhau. Trang này giữ phần riêng của nó — panel danh sách và thanh tỉnh thành.
 */

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
    const [dangChon, setDangChon] = useState<CumSite | null>(null);
    const [moPanel, setMoPanel] = useState(false);
    const [tinhDangXem, setTinhDangXem] = useState<string>('all');
    const [moDropdown, setMoDropdown] = useState(false);

    const { containerRef, mapRef, pins, cums, meta, loi, thuPhong, vuaKhung } = useBanDoPin({
        thamSo,
        khiChonCum: setDangChon,
    });

    const dongPanel = useCallback(() => setMoPanel(false), []);

    // Tay nắm `.mp-drag` kéo được: xuống thì thấp dần rồi đóng, lên thì cao
    // tới sát header. Nhấn vẫn đóng, như từ lát 6.
    const { refNgan, rong, tay } = dungKeoSheet<HTMLDivElement>({
        mo: moPanel,
        dong: dongPanel,
        nhan: 'danh sách',
    });

    const soManHinh = pins.length;

    // Tỉnh thành cho thanh công cụ: nhiều màn hình nhất trước.
    const tinhThanh = useMemo(
        () =>
            (boLoc?.cities ?? [])
                .filter((c) => c.code)
                .map((c) => ({ ma: c.code as string, ten: c.name ?? '', dem: c.count ?? 0 })),
        [boLoc],
    );

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
            <div
                ref={refNgan}
                className={
                    moPanel
                        ? rong
                            ? 'map-panel open sheet-rong'
                            : 'map-panel open'
                        : 'map-panel'
                }
            >
                <div className="mp-drag" {...tay}>
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
