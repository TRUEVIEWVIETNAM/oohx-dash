'use client';

import { useCallback, useEffect, useState } from 'react';

/**
 * Thư viện ảnh của trang chi tiết: ảnh lớn, dải thumbnail, và lightbox.
 *
 * Dựng lại khối `gallery` của `frontpage/detail.blade.php`. Bản Next trước đó
 * hiện **một** ảnh `<img>` trần, dù `spec.photos[]` có thể có nhiều ảnh —
 * `ScreenSummaryResource` chỉ trả `photo_url`, nên không ai thấy là thiếu.
 *
 * ══ Ba nút `gal-btn` của bản Blade chỉ dựng lại MỘT ══
 *
 * Bản Blade có ba nút: lưu (trái tim), chia sẻ, mở rộng. "Lưu" gọi
 * `POST /save/{id}` và cần đăng nhập — khu người mua chưa chuyển sang Next
 * (lộ trình để nó ở giai đoạn sau), nên một trái tim bấm vào không làm gì là
 * tệ hơn không có trái tim. "Mở rộng" là mở lightbox, mà cả ảnh lớn đã làm
 * việc đó. Còn lại nút chia sẻ, và nó chạy thật.
 *
 * ══ Badge "Còn trống" nói bằng số thật ══
 *
 * Audit F-15 gỡ badge in vô điều kiện trên trang này: một màn hình đã bán kín
 * vẫn mời người mua. Ở đây badge đọc `availability` do API tính — và API tính
 * qua `InventoryHoldService`, nên nó trừ cả suất người khác đang giữ.
 */
export function ThuVienAnh({
    anh,
    ten,
    conTrong,
    soNgay,
}: {
    anh: string[];
    ten: string;
    conTrong: boolean;
    soNgay: number;
}) {
    const [chon, setChon] = useState(0);
    const [moLightbox, setMoLightbox] = useState(false);
    const [daSaoChep, setDaSaoChep] = useState(false);

    const soAnh = anh.length;

    const sang = useCallback(
        (buoc: number) => {
            if (soAnh === 0) return;
            setChon((truoc) => ((truoc + buoc) % soAnh + soAnh) % soAnh);
        },
        [soAnh],
    );

    // Bàn phím trong lightbox: Esc đóng, mũi tên đổi ảnh — đúng như bản Blade.
    // Chỉ gắn khi lightbox mở, nên trang không nghe bàn phím khi không cần.
    useEffect(() => {
        if (!moLightbox) return;

        function nhan(e: KeyboardEvent) {
            if (e.key === 'Escape') setMoLightbox(false);
            if (e.key === 'ArrowLeft') sang(-1);
            if (e.key === 'ArrowRight') sang(1);
        }

        const truoc = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', nhan);

        return () => {
            window.removeEventListener('keydown', nhan);
            document.body.style.overflow = truoc;
        };
    }, [moLightbox, sang]);

    async function chiaSe() {
        const url = window.location.href;

        try {
            if (navigator.share) {
                await navigator.share({ title: document.title, url });

                return;
            }

            await navigator.clipboard.writeText(url);
            setDaSaoChep(true);
            setTimeout(() => setDaSaoChep(false), 1500);
        } catch {
            // Người dùng huỷ hộp chia sẻ, hoặc trình duyệt chặn clipboard:
            // không có gì để báo, và không được làm vỡ trang.
        }
    }

    if (soAnh === 0) {
        return null;
    }

    const thumbs = anh.slice(0, 5);

    return (
        <>
            <div className={soAnh <= 1 ? 'gallery gallery--single' : 'gallery'}>
                <div className="gal-main">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img
                        src={anh[chon]}
                        alt={ten}
                        style={{ cursor: 'zoom-in' }}
                        onClick={() => setMoLightbox(true)}
                    />

                    <div className="gal-actions">
                        <button className="gal-btn" type="button" onClick={chiaSe} aria-label="Chia sẻ">
                            <svg viewBox="0 0 24 24" fill="var(--t3)" aria-hidden="true">
                                <path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z" />
                            </svg>
                        </button>

                        {daSaoChep ? (
                            <span style={{ fontSize: 12, color: 'var(--grn)', alignSelf: 'center' }}>
                                Đã sao chép liên kết
                            </span>
                        ) : null}
                    </div>

                    <div className="gal-badge">
                        {conTrong ? (
                            <span className="badge b-grn">
                                <span
                                    style={{
                                        width: 7,
                                        height: 7,
                                        borderRadius: '50%',
                                        background: 'var(--grn)',
                                        display: 'inline-block',
                                    }}
                                />{' '}
                                Còn trống
                            </span>
                        ) : (
                            <span
                                className="badge"
                                style={{ background: 'rgba(120,120,128,.16)', color: 'var(--t2)' }}
                            >
                                Đã đầy {soNgay} ngày tới
                            </span>
                        )}
                    </div>

                    <div className="gal-count">
                        <svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true">
                            <path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z" />
                        </svg>{' '}
                        {soAnh} ảnh
                    </div>
                </div>

                {soAnh > 1 ? (
                    <div className="gal-thumbs">
                        {thumbs.map((thumb, i) => (
                            <div
                                key={thumb + i}
                                className={i === chon ? 'gal-thumb gal-thumb-on' : 'gal-thumb'}
                                onClick={() => setChon(i)}
                                onDoubleClick={() => {
                                    setChon(i);
                                    setMoLightbox(true);
                                }}
                                role="button"
                                tabIndex={0}
                                aria-label={`Ảnh ${i + 1}`}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') setChon(i);
                                }}
                                style={{ cursor: 'pointer' }}
                            >
                                {/* eslint-disable-next-line @next/next/no-img-element */}
                                <img src={thumb} loading="lazy" alt="" />

                                {i === 4 && soAnh > 5 ? (
                                    <div className="gal-more">+{soAnh - 5}</div>
                                ) : null}
                            </div>
                        ))}
                    </div>
                ) : null}
            </div>

            {moLightbox ? (
                <div
                    className="lb-overlay"
                    onClick={(e) => {
                        if (e.target === e.currentTarget) setMoLightbox(false);
                    }}
                    role="dialog"
                    aria-modal="true"
                    aria-label="Xem ảnh lớn"
                >
                    <button className="lb-close" type="button" onClick={() => setMoLightbox(false)} aria-label="Đóng">
                        ×
                    </button>

                    <button className="lb-prev" type="button" onClick={() => sang(-1)} aria-label="Ảnh trước">
                        <svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true">
                            <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" />
                        </svg>
                    </button>

                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img className="lb-img" src={anh[chon]} alt={ten} />

                    <button className="lb-next" type="button" onClick={() => sang(1)} aria-label="Ảnh sau">
                        <svg viewBox="0 0 24 24" fill="#fff" aria-hidden="true">
                            <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" />
                        </svg>
                    </button>

                    <div className="lb-counter">
                        {chon + 1} / {soAnh}
                    </div>
                </div>
            ) : null}
        </>
    );
}
