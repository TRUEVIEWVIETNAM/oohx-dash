'use client';

import Link from 'next/link';
import { useState } from 'react';
import type { ProductDetail } from '@/lib/api';

/**
 * Panel giá của trang gói sản phẩm, kèm danh sách màn hình chọn được.
 *
 * Dựng lại khối `bp` + `bp-tabs` + `pd-screen-list` của
 * `frontpage/products/show.blade.php`. Ba cách bán cho ba bố cục khác nhau:
 *
 *  - `package_only` — một giá, một nút;
 *  - `individual_only` — giá mỗi màn hình, chọn từ danh sách;
 *  - `both` — hai tab: "Mua cả gói" và "Chọn lẻ".
 *
 * ══ Vì sao panel và danh sách màn hình nằm trong CÙNG một component ══
 *
 * Ở bản Blade chúng là hai khối cách nhau cả trang: danh sách ở cột trái,
 * panel ở cột phải, nối với nhau bằng `document.querySelectorAll` và vài id
 * toàn cục (`#selected-count`, `#selected-total`, `#individual-summary`). Ô
 * nào tick thì JS tìm phần tử theo id rồi sửa `textContent`.
 *
 * Ở đây số màn hình đã chọn là **một state**, và hai khối cùng đọc nó. Nên
 * không có id toàn cục nào, và không có khả năng hai khối lệch nhau — thứ đã
 * xảy ra ở bản Blade khi `listing_mode` là `individual_only`: hai khối cùng
 * khai id `individual-summary`, `selected-count`, `selected-total`, và
 * `getElementById` chỉ trả về **cái đầu tiên**.
 *
 * ══ Số tiền là ƯỚC TÍNH, nói rõ ══
 *
 * CLAUDE.md mục 5: số tiền do máy chủ tính. Bản Blade in `count × unitPrice`
 * và gọi nó là tổng. Ở đây nhãn nói đúng: đây là ước tính, số chính thức do
 * `/api/v2/cart` trả về khi thêm vào kế hoạch. Cùng cách `PanelGia` của trang
 * màn hình (lát 4) làm.
 *
 * ══ Nút "Thêm vào Plan" dẫn tới `/login` ══
 *
 * Khu người mua chưa chuyển sang Next. Một nút `POST /cart/add` vào đường
 * chưa tồn tại tệ hơn một nút nói thật là nó cần đăng nhập.
 */

type Kieu = 'package' | 'individual';

function tien(so: number | null | undefined, donVi: string): string {
    if (so === null || so === undefined) return '—';

    return donVi === 'VND'
        ? `${new Intl.NumberFormat('vi-VN').format(so)} ₫`
        : `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(so)} ${donVi}`;
}

function PanelGiaGoi({
    product,
    daChon,
}: {
    product: ProductDetail;
    daChon: Set<string>;
}) {
    const donVi = product.pricing?.currency ?? 'VND';
    const giaGoi = product.pricing?.package ?? null;
    const giaLe = product.pricing?.per_screen ?? null;
    const choGoi = product.listing_mode?.allows_package ?? false;
    const choLe = product.listing_mode?.allows_individual ?? false;
    const caHai = choGoi && choLe;

    const [tab, setTab] = useState<Kieu>(choGoi ? 'package' : 'individual');

    const duongDangNhap = `/login?next=${encodeURIComponent(`/products/${product.slug}`)}`;

    const uocTinhLe = giaLe !== null ? giaLe * daChon.size : null;

    /** Khối "chọn lẻ": tóm tắt số đã chọn + nút. */
    const khoiLe = (
        <div className="bp-body">
            <div style={{ fontSize: 13, color: 'var(--t3)', marginBottom: 10 }}>
                Chọn màn hình từ danh sách bên dưới.
            </div>

            {daChon.size > 0 ? (
                <div
                    style={{
                        padding: '10px 12px',
                        background: 'var(--bg2)',
                        borderRadius: 10,
                        marginBottom: 12,
                    }}
                >
                    <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--t1)' }}>
                        Đã chọn: {daChon.size} màn hình
                    </div>
                    <div
                        style={{
                            fontSize: 16,
                            fontWeight: 800,
                            color: 'var(--bl)',
                            marginTop: 4,
                        }}
                    >
                        {tien(uocTinhLe, donVi)}
                    </div>
                    <div style={{ fontSize: 11, color: 'var(--t4)', marginTop: 2 }}>
                        Ước tính, chưa gồm VAT
                    </div>
                </div>
            ) : null}

            <Link
                href={duongDangNhap}
                className="btn btn-p btn-lg"
                style={{
                    width: '100%',
                    justifyContent: 'center',
                    borderRadius: 12,
                    height: 52,
                    textDecoration: 'none',
                }}
            >
                Đăng nhập để booking
            </Link>
        </div>
    );

    return (
        <>
            <div className="bp">
                {caHai ? (
                    <div className="bp-tabs">
                        <button
                            type="button"
                            className={tab === 'package' ? 'bp-tab active' : 'bp-tab'}
                            onClick={() => setTab('package')}
                        >
                            Mua cả gói
                        </button>
                        <button
                            type="button"
                            className={tab === 'individual' ? 'bp-tab active' : 'bp-tab'}
                            onClick={() => setTab('individual')}
                        >
                            Chọn lẻ
                        </button>
                    </div>
                ) : null}

                {choGoi ? (
                    <div
                        className={
                            !caHai || tab === 'package'
                                ? 'bp-tab-content active'
                                : 'bp-tab-content'
                        }
                    >
                        <div className="bp-head">
                            <div className="bp-price">{tien(giaGoi, donVi)}</div>
                            <div style={{ fontSize: 12, color: 'var(--t4)', margin: '2px 0 6px' }}>
                                {product.quantity?.total_units ?? 0} vị trí · Chưa bao gồm VAT
                            </div>

                            {product.pricing?.package_discount_pct ? (
                                <div
                                    style={{
                                        fontSize: 12,
                                        fontWeight: 700,
                                        color: '#248A3D',
                                        marginBottom: 6,
                                    }}
                                >
                                    Tiết kiệm {product.pricing.package_discount_pct}% so với mua lẻ
                                </div>
                            ) : null}

                            {/*
                              Bản Blade in `<div class="bp-avail">Còn trống</div>`
                              với điều kiện `status === 'active'` — nhưng
                              `/api/v2/products/{slug}` chỉ trả gói công khai,
                              nên điều kiện đó LUÔN đúng và badge là một badge
                              in vô điều kiện. Đúng thứ audit F-15 đã dọn khỏi
                              trang màn hình. Không dựng lại.
                            */}
                        </div>

                        {product.package_options?.length ? (
                            <div className="bp-body">
                                <div className="bp-lbl">Chọn gói</div>
                                <div
                                    style={{
                                        display: 'flex',
                                        flexDirection: 'column',
                                        gap: 8,
                                        marginBottom: 14,
                                    }}
                                >
                                    {product.package_options.map((opt, i) => (
                                        <label
                                            key={`${opt.name}-${i}`}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: 10,
                                                padding: '10px 12px',
                                                border: '1.5px solid var(--ln2)',
                                                borderRadius: 10,
                                                cursor: 'pointer',
                                            }}
                                        >
                                            <input
                                                type="radio"
                                                name="package_option"
                                                defaultChecked={i === 0}
                                                style={{ accentColor: 'var(--bl)' }}
                                            />
                                            <div style={{ flex: 1 }}>
                                                <div
                                                    style={{
                                                        fontSize: 13,
                                                        fontWeight: 700,
                                                        color: 'var(--t1)',
                                                    }}
                                                >
                                                    {opt.name}
                                                </div>
                                                <div style={{ fontSize: 12, color: 'var(--t4)' }}>
                                                    {opt.quantity} vị trí
                                                </div>
                                            </div>
                                            <div
                                                style={{
                                                    fontSize: 14,
                                                    fontWeight: 800,
                                                    color: 'var(--bl)',
                                                }}
                                            >
                                                {tien(opt.price, donVi)}
                                            </div>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        ) : null}

                        <div className="bp-body">
                            <Link
                                href={duongDangNhap}
                                className="btn btn-p btn-lg"
                                style={{
                                    width: '100%',
                                    justifyContent: 'center',
                                    borderRadius: 12,
                                    height: 52,
                                    textDecoration: 'none',
                                }}
                            >
                                Đăng nhập để booking
                            </Link>
                        </div>
                    </div>
                ) : null}

                {choLe ? (
                    <div
                        className={
                            !caHai || tab === 'individual'
                                ? 'bp-tab-content active'
                                : 'bp-tab-content'
                        }
                    >
                        <div className="bp-head">
                            <div className="bp-price">{tien(giaLe, donVi)}</div>
                            <div style={{ fontSize: 12, color: 'var(--t4)', margin: '2px 0 6px' }}>
                                Giá / màn hình · Chưa bao gồm VAT
                            </div>
                        </div>

                        {khoiLe}
                    </div>
                ) : null}

                {/*
                  Chân panel: bản Blade viết "Thanh toán bảo mật bởi OOHX".
                  Sàn **không thu hộ** — người mua chuyển khoản trực tiếp cho
                  media owner (hồ sơ Bộ Công Thương) — nên câu đó nói sai cách
                  tiền chạy. Cùng chỗ đã sửa ở `PanelGia` của trang màn hình.
                */}
                <div className="bp-foot">
                    <svg viewBox="0 0 24 24" fill="var(--grn)" aria-hidden="true" style={{ width: 14, height: 14 }}>
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z" />
                    </svg>{' '}
                    Điều khoản và phí nền tảng công khai tại{' '}
                    <Link href="/bang-phi" style={{ color: 'var(--bl)' }}>
                        bảng phí
                    </Link>
                </div>
            </div>

        </>
    );
}

/**
 * Bố cục hai cột của trang gói, và là nơi giữ state "đã chọn màn hình nào".
 *
 * Panel giá nằm cột phải, danh sách màn hình nằm cột trái — hai khối cách
 * nhau cả trang nhưng đọc cùng một state. Ở bản Blade chúng nối với nhau bằng
 * `document.querySelectorAll` và bốn id toàn cục (`#selected-count`,
 * `#selected-total`, `#individual-summary`, `#selected-inputs`), và khi
 * `listing_mode` là `individual_only` thì **hai khối cùng khai ba id đó** —
 * `getElementById` chỉ trả về cái đầu tiên, nên khối thứ hai không bao giờ
 * cập nhật.
 *
 * `children` là phần tĩnh của cột trái (thư viện ảnh, tiêu đề, mô tả, thông
 * số) do trang — một server component — dựng sẵn. Nên nội dung đó vẫn nằm
 * trong HTML máy chủ render, và chỉ phần cần tương tác mới chạy ở trình duyệt.
 */
export function KhoiGoi({
    product,
    children,
    tuongTu,
    owner,
}: {
    product: ProductDetail;
    children: React.ReactNode;
    tuongTu?: React.ReactNode;
    owner?: React.ReactNode;
}) {
    const [daChon, setDaChon] = useState<Set<string>>(new Set());

    function doiChon(slug: string) {
        setDaChon((truoc) => {
            const moi = new Set(truoc);
            moi.has(slug) ? moi.delete(slug) : moi.add(slug);

            return moi;
        });
    }

    return (
        <div className="pd-detail-layout">
            <div className="pd-detail-main">
                {children}

                <DanhSachManHinh
                    product={product}
                    chonDuoc={product.listing_mode?.allows_individual ?? false}
                    daChon={daChon}
                    doiChon={doiChon}
                />

                {tuongTu}
            </div>

            <div className="pd-detail-sidebar">
                <PanelGiaGoi product={product} daChon={daChon} />
                {owner}
            </div>
        </div>
    );
}

/**
 * Danh sách màn hình trong gói.
 *
 * Tách khỏi `PanelGiaGoi` vì nó nằm ở **cột trái** còn panel ở cột phải. Hai
 * component, một state — nên trang truyền `daChon`/`doiChon` xuống, và không
 * có id toàn cục nào nối hai khối như bản Blade.
 */
function DanhSachManHinh({
    product,
    chonDuoc,
    daChon,
    doiChon,
}: {
    product: ProductDetail;
    chonDuoc: boolean;
    daChon: Set<string>;
    doiChon: (slug: string) => void;
}) {
    const manHinh = product.screens ?? [];

    if (manHinh.length === 0) return null;

    const donVi = product.pricing?.currency ?? 'VND';
    const giaLe = product.pricing?.per_screen ?? null;

    return (
        <div style={{ marginTop: 24 }}>
            <h3 style={{ fontSize: 16, fontWeight: 700, color: 'var(--t1)', marginBottom: 12 }}>
                {chonDuoc ? `Chọn màn hình (${manHinh.length})` : `Danh sách vị trí (${manHinh.length})`}
            </h3>

            <div className="pd-screen-list">
                {manHinh.slice(0, 20).map((screen) =>
                    chonDuoc ? (
                        <label className="pd-screen-item" key={screen.slug}>
                            <input
                                type="checkbox"
                                className="pd-screen-check"
                                checked={daChon.has(screen.slug ?? '')}
                                onChange={() => doiChon(screen.slug ?? '')}
                            />

                            {screen.photo_url ? (
                                // eslint-disable-next-line @next/next/no-img-element
                                <img
                                    src={screen.photo_url}
                                    className="pd-screen-thumb"
                                    loading="lazy"
                                    alt={screen.name ?? ''}
                                />
                            ) : (
                                <div className="pd-screen-thumb" />
                            )}

                            <ThongTinManHinh screen={screen} />

                            <div
                                style={{
                                    fontSize: 13,
                                    fontWeight: 700,
                                    color: 'var(--bl)',
                                    whiteSpace: 'nowrap',
                                }}
                            >
                                {tien(giaLe, donVi)}
                            </div>
                        </label>
                    ) : (
                        <div className="pd-screen-item" key={screen.slug}>
                            {screen.photo_url ? (
                                // eslint-disable-next-line @next/next/no-img-element
                                <img
                                    src={screen.photo_url}
                                    className="pd-screen-thumb"
                                    loading="lazy"
                                    alt={screen.name ?? ''}
                                />
                            ) : (
                                <div className="pd-screen-thumb" />
                            )}

                            <ThongTinManHinh screen={screen} />
                        </div>
                    ),
                )}
            </div>

            {manHinh.length > 20 ? (
                <div style={{ padding: 12, textAlign: 'center', fontSize: 12, color: 'var(--t4)' }}>
                    và {manHinh.length - 20} vị trí khác
                </div>
            ) : null}
        </div>
    );
}

function ThongTinManHinh({ screen }: { screen: NonNullable<ProductDetail['screens']>[number] }) {
    /*
     * Kích thước in bằng **mét**, không bằng pixel như bản Blade.
     *
     * Bản Blade in `$screen->spec?->width_px x height_px`. `ScreenSummary`
     * không có hai cột đó — nó có `size.width_m`/`height_m`. Mở thêm hai
     * trường pixel vào DTO danh sách chỉ để in một dòng phụ là nhân payload
     * của mọi danh sách màn hình lên; mét nói cùng một thứ cho người đang
     * chọn vị trí.
     */
    const co =
        screen.size?.width_m && screen.size?.height_m
            ? `${screen.size.width_m}×${screen.size.height_m}m`
            : null;

    return (
        <div style={{ flex: 1, minWidth: 0 }}>
            <div
                style={{
                    fontWeight: 600,
                    color: 'var(--t1)',
                    fontSize: 13,
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                }}
            >
                {screen.name}
            </div>
            <div style={{ fontSize: 11, color: 'var(--t4)' }}>
                {[screen.location?.city, co].filter(Boolean).join(' · ')}
            </div>
        </div>
    );
}

/**
 * Thư viện ảnh của trang gói: một ảnh lớn + dải thumbnail bấm để đổi.
 *
 * Dùng bộ class riêng của trang gói (`pd-detail-*`), **không** dùng lại
 * `ThuVienAnh` của trang màn hình (bộ `gal-*`): hai bộ có kích thước và bố cục
 * khác nhau trong `frontpage.css`, và trang gói ở bản Blade **không có
 * lightbox** — ảnh lớn ở đây không mở overlay. Dựng lại đúng thứ đã có, không
 * thêm một tính năng chỉ vì component kia sẵn có nó.
 *
 * Bản Blade in `cover_url` làm ảnh lớn rồi liệt kê **toàn bộ** `photo_urls`
 * làm thumbnail, nên ảnh bìa xuất hiện hai lần khi nó cũng nằm trong danh
 * sách. Ở đây trang đã lọc trùng trước khi truyền vào.
 */
export function ThuVienAnhGoi({ anh, ten }: { anh: string[]; ten: string }) {
    const [chon, setChon] = useState(0);

    if (anh.length === 0) return null;

    return (
        <div className="pd-detail-gallery">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={anh[chon]} alt={ten} className="pd-detail-hero-img" />

            {anh.length > 1 ? (
                <div className="pd-detail-thumbs">
                    {anh.map((url, i) => (
                        // eslint-disable-next-line @next/next/no-img-element
                        <img
                            key={url + i}
                            src={url}
                            alt=""
                            loading="lazy"
                            className="pd-detail-thumb"
                            onClick={() => setChon(i)}
                            style={{
                                cursor: 'pointer',
                                outline: i === chon ? '2px solid var(--bl)' : undefined,
                                outlineOffset: 1,
                            }}
                        />
                    ))}
                </div>
            ) : null}
        </div>
    );
}
