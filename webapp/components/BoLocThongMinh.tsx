'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import type { Filters, Region } from '@/lib/api';

/**
 * Bộ lọc của trang bản đồ — dựng lại `frontpage/partials/smart-filter.blade.php`
 * và phần hành vi ở `smart-filter-js.blade.php`.
 *
 * Bố cục: ô tìm kiếm + chip vùng miền luôn hiện, nút "Tìm kiếm" / "Xoá", rồi
 * một khối "Bộ lọc nâng cao" đóng sẵn chứa chip tỉnh/thành, network và loại
 * hình. Mỗi nhóm chip dài hơn `max` thì phần dư ẩn sau nút "+N khác".
 *
 * Bản Next trước lát 6 **không có gì trong số này**: `/map` chỉ là bản đồ cộng
 * một dòng đếm, và bộ lọc duy nhất là tham số URL mà không có giao diện nào
 * đặt ra.
 *
 * ══ Dạng tham số: dấu phẩy, không `[]` ══
 *
 * Bản Blade sinh `city[]=hanoi&network[]=NET1`. Ở đây là `city=hanoi,hcm` —
 * hợp đồng `style: form, explode: false` trong `docs/openapi/v2.yaml`, và cùng
 * dạng `MegaSearch` (lát 2) và `BoLocGoi` (lát 3) sinh ra. Ba chỗ cùng một
 * dạng là điều kiện để không ai phải nhớ chỗ nào khác chỗ nào.
 *
 * ══ Vùng miền chọn một, các nhóm khác chọn nhiều ══
 *
 * Đúng hành vi bản Blade: chip `region` cư xử như radio (bấm lại thì bỏ),
 * `city`/`network`/`venue_type` cư xử như checkbox.
 *
 * ══ `region` KHÔNG được gửi lên API ══
 *
 * Đây là chỗ bản Blade có mà API v2 không: `FrontpageListingRequest` nhận
 * `city`, `venue_type`, `network`, `q`, `sort` — **không có** `region`. Nên
 * chip vùng miền ở đây dịch thành danh sách `city` của vùng đó trước khi vào
 * URL. Gửi `region=north` lên là gửi một tham số máy chủ bỏ qua, và người
 * dùng thấy bộ lọc không có tác dụng mà không hiểu vì sao.
 */

type ThamSo = Record<string, string | undefined>;

function tach(gia_tri?: string): Set<string> {
    if (!gia_tri) return new Set();

    return new Set(
        gia_tri
            .split(/[,|]/)
            .map((s) => s.trim())
            .filter(Boolean),
    );
}

/** Một nhóm chip có thể thu gọn. */
function NhomChip({
    nhan,
    muc,
    dangChon,
    toiDa,
    onBam,
}: {
    nhan?: string;
    muc: { ma: string; ten: string; dem?: number }[];
    dangChon: Set<string>;
    toiDa: number;
    onBam: (ma: string) => void;
}) {
    const [moRong, setMoRong] = useState(false);

    // Chip đang chọn luôn hiện, kể cả khi nằm ngoài `toiDa` — đúng cách bản
    // Blade làm (`!chip.classList.contains('on')`). Ẩn một bộ lọc đang bật là
    // để người dùng không thấy vì sao kết quả lại hẹp như thế.
    const hien = moRong
        ? muc
        : muc.filter((m, i) => i < toiDa || dangChon.has(m.ma));

    const soAn = muc.length - hien.length;

    return (
        <div className="sft-section">
            {nhan ? <div className="sft-label">{nhan}</div> : null}

            {/*
              `sft-chips` thôi, KHÔNG kèm `sft-chips-wrap`.

              `.sft-chips` đã có `flex-wrap: wrap` (frontpage.css:1533), và
              `.sft-chips-wrap` **không có định nghĩa nào** — ở bản Blade nó là
              móc để JS tìm nhóm cần thu gọn (`querySelectorAll('.sft-chips-wrap[data-max]')`),
              cùng với `data-max`. Ở đây số chip hiện là state của component,
              nên cái móc đó không còn việc gì. `class-css.test.mjs` bắt đúng
              chỗ này — lần thứ hai sau `ms-drop-close` ở lát 2.
            */}
            <div className="sft-chips">
                {hien.map((m) => (
                    <button
                        key={m.ma}
                        type="button"
                        className={dangChon.has(m.ma) ? 'sft-chip on' : 'sft-chip'}
                        onClick={() => onBam(m.ma)}
                    >
                        {m.ten}
                        {m.dem !== undefined ? (
                            <span className="sft-chip-count">{m.dem}</span>
                        ) : null}
                    </button>
                ))}

                {soAn > 0 || moRong ? (
                    <button type="button" className="sft-more" onClick={() => setMoRong(!moRong)}>
                        {moRong ? 'Thu gọn' : `+${soAn} khác`}
                    </button>
                ) : null}
            </div>
        </div>
    );
}

export function BoLocThongMinh({
    target,
    regions = [],
    filters,
    params = {},
}: {
    target: string;
    regions?: Region[];
    filters: Filters | null;
    params?: ThamSo;
}) {
    const router = useRouter();

    const [q, setQ] = useState(params.q ?? '');
    const [vung, setVung] = useState<string | null>(null);
    const [cities, setCities] = useState<Set<string>>(() => tach(params.city));
    const [networks, setNetworks] = useState<Set<string>>(() => tach(params.network));
    const [loaiHinh, setLoaiHinh] = useState<Set<string>>(() => tach(params.venue_type));

    const soNangCao = cities.size + networks.size + loaiHinh.size;
    const [moNangCao, setMoNangCao] = useState(soNangCao > 0);

    const coBoLoc = soNangCao > 0 || Boolean(q.trim()) || vung !== null;

    // Tỉnh thành phẳng, nhiều màn hình nhất trước — đúng thứ tự bản Blade
    // (`->sortByDesc('count')`).
    const tatCaTinh = regions
        .flatMap((r) => r.provinces ?? [])
        .map((p) => ({ ma: p.code ?? '', ten: p.name ?? '', dem: p.count ?? 0 }))
        .filter((p) => p.ma)
        .sort((a, b) => b.dem - a.dem);

    function doiTap(tap: Set<string>, ma: string): Set<string> {
        const moi = new Set(tap);
        moi.has(ma) ? moi.delete(ma) : moi.add(ma);

        return moi;
    }

    function chonVung(ma: string) {
        const moi = vung === ma ? null : ma;
        setVung(moi);

        // Chọn một vùng = chọn mọi tỉnh của vùng đó. Bỏ chọn = bỏ đúng những
        // tỉnh ấy, giữ lại tỉnh người dùng đã tự bấm ở nhóm nâng cao.
        const tinhCuaVung = (regions.find((r) => r.code === ma)?.provinces ?? [])
            .map((p) => p.code)
            .filter((c): c is string => Boolean(c));

        setCities((truoc) => {
            const sau = new Set(truoc);

            for (const c of tinhCuaVung) {
                moi ? sau.add(c) : sau.delete(c);
            }

            return sau;
        });
    }

    function apDung() {
        const url = new URLSearchParams();

        if (q.trim()) url.set('q', q.trim());
        if (cities.size) url.set('city', [...cities].join(','));
        if (networks.size) url.set('network', [...networks].join(','));
        if (loaiHinh.size) url.set('venue_type', [...loaiHinh].join(','));

        const qs = url.toString();
        router.push(qs ? `${target}?${qs}` : target);
    }

    function xoaHet() {
        setQ('');
        setVung(null);
        setCities(new Set());
        setNetworks(new Set());
        setLoaiHinh(new Set());
        router.push(target);
    }

    return (
        <div className="sft">
            <div className="sft-search">
                <svg viewBox="0 0 24 24" fill="var(--t4)" className="sft-search-icon" aria-hidden="true">
                    <path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                </svg>

                <input
                    type="text"
                    className="sft-search-input"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            apDung();
                        }
                    }}
                    placeholder="Tìm tên, địa chỉ..."
                    aria-label="Tìm theo tên hoặc địa chỉ"
                />
            </div>

            {regions.length ? (
                <div className="sft-section">
                    <div className="sft-chips">
                        {regions.map((r) =>
                            r.code ? (
                                <button
                                    key={r.code}
                                    type="button"
                                    className={vung === r.code ? 'sft-chip on' : 'sft-chip'}
                                    onClick={() => chonVung(r.code as string)}
                                >
                                    {r.name}
                                </button>
                            ) : null,
                        )}
                    </div>
                </div>
            ) : null}

            <div className="sft-actions">
                <button
                    type="button"
                    className="btn btn-p btn-sm"
                    onClick={apDung}
                    style={{ flex: 1, justifyContent: 'center', borderRadius: 10 }}
                >
                    <svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true" style={{ width: 14, height: 14 }}>
                        <path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                    </svg>
                    Tìm kiếm
                </button>

                {coBoLoc ? (
                    <button
                        type="button"
                        className="btn btn-s btn-sm"
                        style={{ borderRadius: 10 }}
                        onClick={xoaHet}
                    >
                        Xoá
                    </button>
                ) : null}
            </div>

            <div
                className={moNangCao ? 'sft-adv-toggle open' : 'sft-adv-toggle'}
                onClick={() => setMoNangCao(!moNangCao)}
                role="button"
                tabIndex={0}
                aria-expanded={moNangCao}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') setMoNangCao(!moNangCao);
                }}
            >
                <svg viewBox="0 0 24 24" fill="var(--bl)" aria-hidden="true" style={{ width: 14, height: 14 }}>
                    <path d="M3 17v2h6v-2H3zM3 5v2h10V5H3zm10 16v-2h8v-2h-8v-2h-2v6h2zM7 9v2H3v2h4v2h2V9H7zm14 4v-2H11v2h10zm-6-4h2V7h4V5h-4V3h-2v6z" />
                </svg>
                <span>Bộ lọc nâng cao</span>
                {soNangCao > 0 ? <span className="sft-adv-badge">{soNangCao}</span> : null}
                <svg
                    className="sft-adv-chevron"
                    viewBox="0 0 24 24"
                    fill="var(--t4)"
                    aria-hidden="true"
                    style={{ width: 16, height: 16, marginLeft: 'auto' }}
                >
                    <path d="M7 10l5 5 5-5z" />
                </svg>
            </div>

            {moNangCao ? (
                <div className="sft-adv-body">
                    {tatCaTinh.length ? (
                        <NhomChip
                            nhan="Tỉnh/Thành"
                            muc={tatCaTinh}
                            dangChon={cities}
                            toiDa={6}
                            onBam={(ma) => setCities(doiTap(cities, ma))}
                        />
                    ) : null}

                    {filters?.networks?.length ? (
                        <NhomChip
                            nhan="Network"
                            muc={filters.networks
                                .filter((n) => n.code)
                                .map((n) => ({ ma: n.code as string, ten: n.name ?? '', dem: n.count }))}
                            dangChon={networks}
                            toiDa={4}
                            onBam={(ma) => setNetworks(doiTap(networks, ma))}
                        />
                    ) : null}

                    {filters?.venue_types?.length ? (
                        <NhomChip
                            nhan="Loại hình"
                            muc={filters.venue_types
                                .filter((f) => f.code)
                                .map((f) => ({ ma: f.code as string, ten: f.name ?? '', dem: f.count }))}
                            dangChon={loaiHinh}
                            toiDa={4}
                            onBam={(ma) => setLoaiHinh(doiTap(loaiHinh, ma))}
                        />
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
