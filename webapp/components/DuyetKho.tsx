'use client';

import { useId, useState } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import type { Browse } from '@/lib/home';

/**
 * Khối "Duyệt kho" — một kho, hai cách nhìn.
 *
 * ══ Vì sao gộp hai mục thành một ══
 *
 * Trước lát này trang chủ có hai mục rời nhau: "Duyệt theo Địa điểm" (lưới
 * loại hình) và "17 Tỉnh thành" (lưới tỉnh), cách nhau một dải nền xám, mỗi
 * cái một tiêu đề và một nút "Tất cả" riêng. Hai mục ấy **cùng dẫn vào một
 * kho** — `/explore?venue_type=…` và `/explore?city=…` — chỉ khác chiều lọc.
 *
 * Người đọc gặp chúng như hai thứ khác nhau và phải cuộn qua cả hai để biết
 * mình đang nhìn cùng một danh sách. Gộp lại thành hai tab thì câu hỏi đổi từ
 * "mục này là gì" thành "tôi muốn lọc theo chiều nào" — đúng câu hỏi người
 * mua đang có.
 *
 * ══ Cả hai lưới đều nằm trong HTML máy chủ trả về ══
 *
 * Tab không hoạt động được ẩn bằng thuộc tính `hidden`, không bằng việc
 * không render. Công cụ tìm kiếm vẫn thấy đủ liên kết tới mọi loại hình và
 * mọi tỉnh thành, và người tắt JavaScript vẫn đọc được tab đầu.
 *
 * Giá phải trả: cả hai lưới ảnh cùng vào HTML. `next/image` vẫn cắt theo cỡ
 * hiển thị như trước (lát ảnh 18/10/2026, 10,68 MB → 0,23 MB), và lưới ẩn
 * không tải ảnh vì `hidden` cho `display: none`.
 */

/**
 * Tám dải màu cho thẻ tỉnh thành, luân phiên theo thứ tự.
 *
 * Chép đúng bảng của bản Blade (`$cityGradients`). Nó là **lớp nền**, không
 * phải lớp trang trí: `vietnam_provinces.photo_url` cho phép null, và một
 * tỉnh chưa có ảnh vẫn phải ra một thẻ đọc được thay vì một ô xám.
 */
const DAI_MAU: Array<[string, string]> = [
    ['#1C3A5E', '#2A4FF6'],
    ['#1A3A2A', '#34C759'],
    ['#3A1A3A', '#AF52DE'],
    ['#3A1C1A', '#FF3B30'],
    ['#1A2A3A', '#0071E3'],
    ['#1D1D1F', '#3A3A3C'],
    ['#2D1B4E', '#7C3AED'],
    ['#1A3A3A', '#06B6D4'],
];

/**
 * `sizes` phải khớp với breakpoint của lưới trong `frontpage.css`, nếu không
 * trình duyệt tải nhầm cỡ.
 *
 *   .cat-grid   2 cột → 3 (≥480) → 4 (≥768) → 5 (≥1024)
 *   .city-grid  2 cột → 3 (≥640) → 4 (≥1024)
 *
 * Sai một bậc ở đây không làm trang vỡ — nó chỉ lặng lẽ tải ảnh to gấp đôi,
 * đúng kiểu hỏng mà lát ảnh 18/10/2026 sinh ra để chữa.
 */
const SIZES_CAT = '(min-width:1024px) 20vw, (min-width:768px) 25vw, (min-width:480px) 33vw, 50vw';

const SIZES_CITY = '(min-width:1024px) 25vw, (min-width:640px) 33vw, 50vw';

type Tab = 'loai' | 'tinh';

export function DuyetKho({ browse }: { browse: Browse }) {
    const [tab, setTab] = useState<Tab>(
        browse.venue_types.length > 0 ? 'loai' : 'tinh',
    );
    const id = useId();

    const coLoai = browse.venue_types.length > 0;
    const coTinh = browse.cities.length > 0;

    return (
        <>
            {/*
              Chỉ vẽ thanh tab khi CÓ cả hai chiều lọc. Một tab đứng một mình
              là một nút không làm gì — và nó nói dối rằng có một cách nhìn
              thứ hai đang trống.
            */}
            {coLoai && coTinh ? (
                <div className="dk-tabs" role="tablist" aria-label="Cách duyệt kho">
                    <Nut
                        id={id}
                        ma="loai"
                        dangChon={tab}
                        chon={setTab}
                        so={browse.venue_types.length}
                    >
                        Theo loại hình
                    </Nut>
                    <Nut
                        id={id}
                        ma="tinh"
                        dangChon={tab}
                        chon={setTab}
                        so={browse.cities.length}
                    >
                        Theo tỉnh thành
                    </Nut>
                </div>
            ) : null}

            {coLoai ? (
                <div
                    role="tabpanel"
                    id={`${id}-loai-panel`}
                    aria-labelledby={`${id}-loai`}
                    hidden={tab !== 'loai'}
                >
                    <div className="cat-grid">
                        {browse.venue_types.map((vt) => (
                            <Link
                                key={vt.code ?? vt.name}
                                href={`/explore?venue_type=${encodeURIComponent(vt.code ?? '')}`}
                                className="cat-card"
                            >
                                <div className="cat-img">
                                    {/* Ảnh là tuỳ chọn; icon bên dưới luôn có, nên
                                        một loại chưa có ảnh vẫn ra thẻ đọc được. */}
                                    {vt.thumb ? (
                                        <Image
                                            src={vt.thumb}
                                            alt={vt.name ?? ''}
                                            fill
                                            sizes={SIZES_CAT}
                                        />
                                    ) : null}
                                    <div className="cat-icon">
                                        <span className="material-symbols-outlined">
                                            {vt.icon}
                                        </span>
                                    </div>
                                </div>
                                <div className="cat-body">
                                    <div className="cat-name">{vt.name}</div>
                                    <div className="cat-meta">
                                        {new Intl.NumberFormat('vi-VN').format(vt.count)} vị trí
                                        <MuiTen />
                                    </div>
                                </div>
                            </Link>
                        ))}
                    </div>
                </div>
            ) : null}

            {coTinh ? (
                <div
                    role="tabpanel"
                    id={`${id}-tinh-panel`}
                    aria-labelledby={`${id}-tinh`}
                    hidden={tab !== 'tinh'}
                >
                    <div className="city-grid">
                        {browse.cities.map((city, i) => (
                            <Link
                                key={city.code ?? city.name}
                                href={`/explore?city=${encodeURIComponent(city.code ?? '')}`}
                                className="city-card"
                            >
                                <div
                                    className="city-bg"
                                    style={{
                                        background: `linear-gradient(135deg,${DAI_MAU[i % DAI_MAU.length][0]},${DAI_MAU[i % DAI_MAU.length][1]})`,
                                    }}
                                >
                                    {city.photo ? (
                                        <Image
                                            src={city.photo}
                                            alt={city.name ?? ''}
                                            fill
                                            sizes={SIZES_CITY}
                                        />
                                    ) : null}
                                </div>
                                <div className="city-info">
                                    <div className="city-name">{city.name}</div>
                                    <div className="city-count">
                                        <svg viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" />
                                        </svg>
                                        {new Intl.NumberFormat('vi-VN').format(city.count)} vị trí
                                    </div>
                                </div>
                            </Link>
                        ))}
                    </div>
                </div>
            ) : null}
        </>
    );
}

function Nut({
    id,
    ma,
    dangChon,
    chon,
    so,
    children,
}: {
    id: string;
    ma: Tab;
    dangChon: Tab;
    chon: (t: Tab) => void;
    so: number;
    children: React.ReactNode;
}) {
    const on = dangChon === ma;

    return (
        <button
            className="dk-tab"
            type="button"
            role="tab"
            id={`${id}-${ma}`}
            aria-selected={on}
            aria-controls={`${id}-${ma}-panel`}
            onClick={() => chon(ma)}
        >
            {children} <span className="dk-tab-so">{new Intl.NumberFormat('vi-VN').format(so)}</span>
        </button>
    );
}

/** Mũi tên ">" của bản Blade, dùng ở dòng đếm vị trí. */
function MuiTen() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            style={{ width: 14, height: 14, flexShrink: 0 }}
            aria-hidden="true"
        >
            <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" />
        </svg>
    );
}
