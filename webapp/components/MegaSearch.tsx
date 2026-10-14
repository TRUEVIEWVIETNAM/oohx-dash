'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import type { Region } from '@/lib/home';

/**
 * Ô tìm kiếm lớn của trang chủ và trang danh sách.
 *
 * Dựng lại `frontpage/partials/mega-search.blade.php` (158 dòng) cùng phần
 * hành vi ở `mega-search-js.blade.php` (187 dòng). Bản Next trước đây **không
 * có khối này** — trang chủ chỉ còn hai nút, và `/explore` không có bộ lọc nào
 * hiện ra cho người dùng dù nó đọc `city`, `venue_type`, `sort` từ URL.
 *
 * ══ Khác bản Blade ở ĐÚNG một chỗ: dạng tham số ══
 *
 * Bản Blade sinh `city[]=hanoi&venue_type[]=mall&network[]=NET1`. Ở đây là
 * `city=hanoi&venue_type=mall&network=NET1` — **một khóa, phân tách bằng dấu
 * phẩy** khi có nhiều giá trị. Lý do: đó là hợp đồng đã khai trong
 * `docs/openapi/v2.yaml` (`style: form, explode: false`) và là dạng
 * `/explore` bản Next đang đọc. `FrontpageListingRequest::prepareForValidation()`
 * nhận cả hai dạng, nên không bên nào vỡ — nhưng sinh ra hai dạng khác nhau
 * trong cùng một ứng dụng thì sớm muộn có người đọc một dạng và quên dạng kia.
 *
 * ══ Trạng thái ban đầu đến từ URL, không từ `useEffect` ══
 *
 * Chip nào đang chọn, pill đếm bao nhiêu — tất cả suy ra từ `params` mà trang
 * (server component) truyền vào. Nên HTML đầu tiên đã đúng trạng thái, và một
 * người mở lại link có bộ lọc thấy ngay bộ lọc đó. Đọc từ `window.location`
 * trong `useEffect` thì một nhịp đầu luôn hiện "chưa lọc gì".
 *
 * ══ Vì sao dropdown phải tự định vị bằng JS ══
 *
 * `.mega-drop` là `position:fixed` (frontpage.css dòng 613) và không có toạ độ
 * nào trong CSS — bản Blade tính `top`/`left`/`width` từ `getBoundingClientRect()`
 * của ô tìm kiếm. Giữ nguyên cách đó, kèm cập nhật lại khi cuộn và khi đổi kích
 * thước: thiếu nó thì dropdown đứng yên trong lúc trang cuộn.
 */
export type NhomLoaiHinh = {
    code?: string | null;
    name?: string | null;
    icon?: string | null;
    count?: number;
    networks?: { code?: string | null; name?: string | null; count?: number }[];
};

type ThamSo = Record<string, string | undefined>;

/** `"a,b"` → `Set{a,b}`. Nhận cả dạng `|` vì API cũng nhận. */
function tachDanhSach(gia_tri?: string): Set<string> {
    if (!gia_tri) return new Set();

    return new Set(
        gia_tri
            .split(/[,|]/)
            .map((s) => s.trim())
            .filter(Boolean),
    );
}

export function MegaSearch({
    variant = 'compact',
    regions = [],
    categories = [],
    chips = [],
    params = {},
    target,
}: {
    variant?: 'hero' | 'compact';
    regions?: Region[];
    categories?: NhomLoaiHinh[];
    chips?: string[];
    params?: ThamSo;
    target: string;
}) {
    const router = useRouter();
    const hopRef = useRef<HTMLDivElement | null>(null);
    const dropCityRef = useRef<HTMLDivElement | null>(null);
    const dropTypeRef = useRef<HTMLDivElement | null>(null);

    const [q, setQ] = useState(params.q ?? '');
    const [cities, setCities] = useState<Set<string>>(() => tachDanhSach(params.city));
    const [types, setTypes] = useState<Set<string>>(() => tachDanhSach(params.venue_type));
    const [networks, setNetworks] = useState<Set<string>>(() => tachDanhSach(params.network));
    const [thuGon, setThuGon] = useState<Set<string>>(new Set());
    const [mo, setMo] = useState<'city' | 'type' | null>(null);

    const hero = variant === 'hero';

    // ── Định vị dropdown ────────────────────────────────────────────────────

    useEffect(() => {
        if (!mo) return;

        const drop = mo === 'city' ? dropCityRef.current : dropTypeRef.current;

        function datViTri() {
            const hop = hopRef.current;

            if (!hop || !drop) return;

            const o = hop.getBoundingClientRect();
            const rong = Math.min(720, window.innerWidth * 0.96);
            let trai = o.left + o.width / 2 - rong / 2;

            if (trai < 8) trai = 8;
            if (trai + rong > window.innerWidth - 8) trai = window.innerWidth - 8 - rong;

            drop.style.top = `${o.bottom + 8}px`;
            drop.style.left = `${trai}px`;
            drop.style.width = `${rong}px`;
        }

        datViTri();
        window.addEventListener('scroll', datViTri, { passive: true });
        window.addEventListener('resize', datViTri);

        return () => {
            window.removeEventListener('scroll', datViTri);
            window.removeEventListener('resize', datViTri);
        };
    }, [mo]);

    // ── Dựng URL ────────────────────────────────────────────────────────────

    function dieuHuong(ghiDe?: {
        cities?: Set<string>;
        types?: Set<string>;
        networks?: Set<string>;
        q?: string;
    }) {
        const url = new URLSearchParams();
        const chu = (ghiDe?.q ?? q).trim();

        if (chu) url.set('q', chu);

        const bo = [
            ['city', ghiDe?.cities ?? cities],
            ['venue_type', ghiDe?.types ?? types],
            ['network', ghiDe?.networks ?? networks],
        ] as const;

        for (const [khoa, tap] of bo) {
            if (tap.size > 0) url.set(khoa, [...tap].join(','));
        }

        // Giữ cách sắp xếp đang chọn: đổi bộ lọc không có nghĩa là đổi thứ tự.
        if (params.sort) url.set('sort', params.sort);

        const qs = url.toString();
        setMo(null);
        router.push(qs ? `${target}?${qs}` : target);
    }

    // ── Cây loại hình ───────────────────────────────────────────────────────

    function bamNhom(nhom: NhomLoaiHinh) {
        const ma = nhom.code ?? '';
        const bat = !types.has(ma);
        const t = new Set(types);
        const n = new Set(networks);

        if (bat) t.add(ma);
        else t.delete(ma);

        // Bấm nhóm là bật/tắt **cả** network con — đúng hành vi bản Blade.
        for (const net of nhom.networks ?? []) {
            const code = net.code ?? '';

            if (bat) n.add(code);
            else n.delete(code);
        }

        setTypes(t);
        setNetworks(n);

        if (bat) {
            const g = new Set(thuGon);
            g.delete(ma);
            setThuGon(g);
        }
    }

    function bamNetwork(nhom: NhomLoaiHinh, code: string) {
        const n = new Set(networks);

        if (n.has(code)) n.delete(code);
        else n.add(code);

        setNetworks(n);

        // Nhóm cha sáng khi **mọi** con đang chọn, tắt nếu không — bản Blade
        // đồng bộ đúng chiều này, và nó là chiều duy nhất không gây nhập nhằng:
        // cha sáng nghĩa là "cả nhóm", không phải "một phần nhóm".
        const con = (nhom.networks ?? []).map((x) => x.code ?? '');
        const t = new Set(types);
        const ma = nhom.code ?? '';

        if (con.length > 0 && con.every((c) => n.has(c))) t.add(ma);
        else t.delete(ma);

        setTypes(t);
    }

    // ── Thẻ bộ lọc đang áp dụng ─────────────────────────────────────────────

    const theLoc: { nhom: 'city' | 'venue_type' | 'network'; ma: string; nhan: string }[] = [];

    for (const code of cities) {
        const tinh = regions
            .flatMap((r) => r.provinces ?? [])
            .find((p) => p.code === code);

        theLoc.push({ nhom: 'city', ma: code, nhan: tinh?.name ?? code });
    }

    for (const code of types) {
        const nhom = categories.find((c) => c.code === code);

        theLoc.push({ nhom: 'venue_type', ma: code, nhan: nhom?.name ?? code });
    }

    for (const code of networks) {
        const net = categories.flatMap((c) => c.networks ?? []).find((n) => n.code === code);

        theLoc.push({ nhom: 'network', ma: code, nhan: net?.name ?? code });
    }

    function boThe(nhom: string, ma: string) {
        if (nhom === 'city') {
            const s = new Set(cities);
            s.delete(ma);
            setCities(s);
            dieuHuong({ cities: s });
        } else if (nhom === 'venue_type') {
            const s = new Set(types);
            s.delete(ma);
            setTypes(s);
            dieuHuong({ types: s });
        } else {
            const s = new Set(networks);
            s.delete(ma);
            setNetworks(s);
            dieuHuong({ networks: s });
        }
    }

    const soLocLoaiHinh = types.size + networks.size;

    return (
        <>
            <div className={hero ? 'ms-wrap ms-hero' : 'ms-wrap'}>
                <div className="ms-box" ref={hopRef}>
                    <button
                        className={mo === 'city' ? 'ms-pill on' : 'ms-pill'}
                        type="button"
                        onClick={() => setMo(mo === 'city' ? null : 'city')}
                    >
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" />
                        </svg>
                        <span className="ms-pill-txt">
                            {cities.size > 0 ? `${cities.size} tỉnh/thành` : 'Tỉnh/Thành'}
                        </span>
                        {cities.size > 0 ? (
                            <span className="ms-pill-badge">{cities.size}</span>
                        ) : null}
                        <svg className="ms-pill-chv" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M7 10l5 5 5-5z" />
                        </svg>
                    </button>

                    <button
                        className={mo === 'type' ? 'ms-pill on' : 'ms-pill'}
                        type="button"
                        onClick={() => setMo(mo === 'type' ? null : 'type')}
                    >
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z" />
                        </svg>
                        <span className="ms-pill-txt">
                            {soLocLoaiHinh > 0 ? `${soLocLoaiHinh} bộ lọc` : 'Loại hình'}
                        </span>
                        {soLocLoaiHinh > 0 ? (
                            <span className="ms-pill-badge">{soLocLoaiHinh}</span>
                        ) : null}
                        <svg className="ms-pill-chv" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M7 10l5 5 5-5z" />
                        </svg>
                    </button>

                    <div className="ms-divider" />

                    <input
                        className="ms-input"
                        type="text"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                dieuHuong();
                            }
                        }}
                        placeholder="Tìm theo tên, địa chỉ, network..."
                        aria-label="Tìm kiếm inventory"
                    />

                    <button className="ms-submit" type="button" onClick={() => dieuHuong()}>
                        <svg
                            viewBox="0 0 24 24"
                            fill="#fff"
                            style={{ width: 16, height: 16 }}
                            aria-hidden="true"
                        >
                            <path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                        </svg>
                        <span className="ms-submit-txt">Tìm kiếm</span>
                    </button>
                </div>

                <div className="ms-drop-anchor">
                    {/* Dropdown tỉnh/thành, nhóm theo vùng */}
                    <div
                        className={mo === 'city' ? 'mega-drop open' : 'mega-drop'}
                        ref={dropCityRef}
                    >
                        <div className="mega-head">
                            <div className="mega-head-title">Chọn tỉnh/thành</div>
                            <span className="mega-head-clear" onClick={() => setCities(new Set())}>
                                Xoá tất cả
                            </span>
                        </div>

                        <div className="mega-body">
                            <div className="ms-loc-cols">
                                {regions.map((vung) => (
                                    <div className="ms-loc-region" key={vung.code ?? vung.name}>
                                        <div className="ms-loc-region-title">{vung.name}</div>

                                        {(vung.provinces ?? []).map((tinh) => (
                                            <div
                                                className={
                                                    cities.has(tinh.code ?? '')
                                                        ? 'ms-loc-item on'
                                                        : 'ms-loc-item'
                                                }
                                                key={tinh.code}
                                                onClick={() => {
                                                    const s = new Set(cities);
                                                    const ma = tinh.code ?? '';

                                                    if (s.has(ma)) s.delete(ma);
                                                    else s.add(ma);

                                                    setCities(s);
                                                }}
                                            >
                                                <div className="ms-loc-check" />
                                                <span>{tinh.name}</span>
                                                <span className="ms-loc-count">{tinh.count}</span>
                                            </div>
                                        ))}
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="mega-foot">
                            <button
                                className="btn btn-s btn-sm"
                                type="button"
                                onClick={() => setMo(null)}
                            >
                                Đóng
                            </button>
                            <button
                                className="btn btn-p btn-sm"
                                type="button"
                                onClick={() => dieuHuong()}
                            >
                                Áp dụng
                            </button>
                        </div>
                    </div>

                    {/* Dropdown loại hình + network, dạng cây */}
                    <div
                        className={mo === 'type' ? 'mega-drop open' : 'mega-drop'}
                        ref={dropTypeRef}
                    >
                        <div className="mega-head">
                            <div className="mega-head-title">Loại hình &amp; Network</div>
                            <span
                                className="mega-head-clear"
                                onClick={() => {
                                    setTypes(new Set());
                                    setNetworks(new Set());
                                }}
                            >
                                Xoá tất cả
                            </span>
                        </div>

                        <div className="mega-body" style={{ maxHeight: '55vh', padding: '8px 12px' }}>
                            <div className="ms-tree">
                                {categories.map((nhom) => {
                                    const ma = nhom.code ?? '';
                                    const con = nhom.networks ?? [];
                                    const coCon = con.length > 0;
                                    const dongLai = thuGon.has(ma);

                                    return (
                                        <div
                                            className={
                                                dongLai ? 'ms-tree-node collapsed' : 'ms-tree-node'
                                            }
                                            key={ma}
                                        >
                                            <div
                                                className={
                                                    types.has(ma)
                                                        ? 'ms-tree-row ms-tree-parent on'
                                                        : 'ms-tree-row ms-tree-parent'
                                                }
                                                onClick={() => bamNhom(nhom)}
                                            >
                                                {coCon ? (
                                                    <span
                                                        className="ms-tree-toggle"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            const g = new Set(thuGon);

                                                            if (g.has(ma)) g.delete(ma);
                                                            else g.add(ma);

                                                            setThuGon(g);
                                                        }}
                                                    >
                                                        <span className="material-symbols-outlined">
                                                            expand_more
                                                        </span>
                                                    </span>
                                                ) : (
                                                    <span className="ms-tree-toggle ms-tree-toggle--leaf" />
                                                )}

                                                <span className="ms-tree-check" />
                                                <span className="material-symbols-outlined ms-tree-icon">
                                                    {nhom.icon || 'tv'}
                                                </span>
                                                <span className="ms-tree-label">{nhom.name}</span>
                                                <span className="ms-tree-count">{nhom.count}</span>
                                            </div>

                                            {coCon ? (
                                                <div className="ms-tree-children">
                                                    {con.map((net) => (
                                                        <div
                                                            className={
                                                                networks.has(net.code ?? '')
                                                                    ? 'ms-tree-row ms-tree-child on'
                                                                    : 'ms-tree-row ms-tree-child'
                                                            }
                                                            key={net.code}
                                                            onClick={() =>
                                                                bamNetwork(nhom, net.code ?? '')
                                                            }
                                                        >
                                                            <span className="ms-tree-check" />
                                                            <span className="ms-tree-label">
                                                                {net.name}
                                                            </span>
                                                            <span className="ms-tree-count">
                                                                {net.count}
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            ) : null}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        <div className="mega-foot">
                            <button
                                className="btn btn-s btn-sm"
                                type="button"
                                onClick={() => setMo(null)}
                            >
                                Đóng
                            </button>
                            <button
                                className="btn btn-p btn-sm"
                                type="button"
                                onClick={() => dieuHuong()}
                            >
                                Áp dụng
                            </button>
                        </div>
                    </div>
                </div>

                {theLoc.length > 0 || q ? (
                    <div className="ms-tags">
                        {theLoc.map((the) => (
                            <span className="ms-tag" key={`${the.nhom}:${the.ma}`}>
                                {the.nhan}{' '}
                                <span
                                    className="ms-tag-x"
                                    onClick={() => boThe(the.nhom, the.ma)}
                                    aria-label={`Bỏ lọc ${the.nhan}`}
                                >
                                    ×
                                </span>
                            </span>
                        ))}

                        <span
                            className="ms-tag ms-tag-clear"
                            onClick={() => {
                                setCities(new Set());
                                setTypes(new Set());
                                setNetworks(new Set());
                                setQ('');
                                router.push(target);
                            }}
                        >
                            Xoá tất cả
                        </span>
                    </div>
                ) : null}

                {hero && chips.length > 0 ? (
                    <div className="ms-chips">
                        {chips.slice(0, 4).map((chip) => (
                            <span
                                className="ms-chip"
                                key={chip}
                                onClick={() => {
                                    setQ(chip);
                                    dieuHuong({ q: chip });
                                }}
                            >
                                {chip}
                            </span>
                        ))}
                    </div>
                ) : null}
            </div>

            {/* Lớp nền — bản Blade đặt NGOÀI `ms-wrap` để không chen vào bố cục. */}
            <div
                className={mo ? 'ms-backdrop open' : 'ms-backdrop'}
                onClick={() => setMo(null)}
            />
        </>
    );
}
