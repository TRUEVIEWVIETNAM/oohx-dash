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
 *
 * ══ Lát 5 (24/10/2026): ảnh này là ảnh của ai ══
 *
 * `photo_urls` có năm bậc dự phòng ở phía API, và chỉ bậc đầu là ảnh của chính
 * màn hình. Vị trí đo được ngày 24/10/2026 có đúng một ảnh, và nó là ảnh bìa
 * của chủ sở hữu — thư viện ảnh hiện nó to như ảnh biển, với badge "Còn trống"
 * và "1 ảnh" bên cạnh. Người mua nhìn một tấm ảnh họ tưởng là mặt biển.
 *
 * Trường `photo_source` của API nói bậc nào đã dùng, và khối này nói lại điều
 * đó cho người đọc. Không ẩn ảnh: một ảnh thay thế vẫn cho biết khu vực đó
 * trông thế nào, nên bỏ nó đi là mất thông tin. Chỉ ngừng để nó giả làm ảnh
 * biển.
 */

/** Nhãn cho từng bậc dự phòng không phải ảnh màn hình. */
const NGUON_THAY_THE: Record<string, string> = {
    site: 'Đây là ảnh của địa điểm, chưa có ảnh chụp vị trí',
    network: 'Đây là ảnh của mạng lưới màn hình, chưa có ảnh chụp vị trí',
    owner_cover: 'Đây là ảnh bìa của chủ vị trí, chưa có ảnh chụp vị trí',
    venue_category: 'Đây là ảnh minh hoạ loại địa điểm, chưa có ảnh chụp vị trí',
};

/*
 * `sizes` cho hai cỡ ảnh của thư viện — lát 4, 25/10/2026.
 *
 * `srcset` với đơn vị `w` **bắt buộc** đi cùng `sizes`: thiếu `sizes` thì
 * trình duyệt mặc định ô ảnh rộng `100vw` và luôn chọn bản lớn nhất, tức
 * `srcset` không tiết kiệm được gì. Hai con số dưới đây đọc từ CSS thật:
 *
 *   `.gallery` ở `resources/css/frontpage.css:2043` là `1.65fr 1fr` trong
 *   khung `--mw: 1280px` trừ padding 52px — nên ảnh chính ≈ 1176 × 1,65/2,65
 *   ≈ 730px, và mỗi thumbnail ≈ 1176 × 1/2,65 ≈ 444px. Dưới 768px lưới về
 *   một cột (ảnh chính `100vw`) và thumbnail thành hai cột (`50vw`).
 *
 * Hai hằng này sẽ lệch nếu ai đổi tỉ lệ lưới trong CSS. Lệch theo chiều nào
 * cũng chỉ là chọn bản to hơn hay nhỏ hơn một bậc, không phải ảnh vỡ — nên
 * chúng là một phép tối ưu có chú thích, không phải một bất biến cần chốt.
 */
const SIZES_CHINH = '(max-width: 767px) 100vw, 730px';
const SIZES_THUMB = '(max-width: 767px) 50vw, 444px';

export function ThuVienAnh({
    anh,
    srcset,
    ten,
    conTrong,
    soNgay,
    nguon,
}: {
    anh: string[];
    /**
     * `photo_srcsets` của API — **cùng thứ tự** với `anh`, phần tử `null` ở
     * ảnh chưa có bản dẫn xuất (lát 4).
     *
     * Tuỳ chọn, và thiếu thì mọi ảnh về `<img>` không `srcset` như trước lát
     * 4. Dãy ngắn hơn `anh` cũng không sao: `?? null` ở mỗi chỗ đọc giữ ảnh
     * đó không có `srcset` thay vì gán cho nó `srcset` của ảnh khác.
     */
    srcset?: (string | null)[];
    ten: string;
    conTrong: boolean;
    soNgay: number;
    /** `photo_source` của API. `screen` hoặc thiếu = không nói gì. */
    nguon?: string | null;
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
    const canhBao = nguon ? NGUON_THAY_THE[nguon] : undefined;

    return (
        <>
            <div className={soAnh <= 1 ? 'gallery gallery--single' : 'gallery'}>
                <div className="gal-main">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img
                        src={anh[chon]}
                        // `?? undefined`, không `?? ''`: React in ra
                        // `srcset=""` cho chuỗi rỗng, và một thuộc tính rỗng
                        // là một thuộc tính trình duyệt vẫn phải xử lý.
                        srcSet={srcset?.[chon] ?? undefined}
                        sizes={srcset?.[chon] ? SIZES_CHINH : undefined}
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
                                <img
                                    src={thumb}
                                    srcSet={srcset?.[i] ?? undefined}
                                    sizes={srcset?.[i] ? SIZES_THUMB : undefined}
                                    loading="lazy"
                                    alt=""
                                />

                                {i === 4 && soAnh > 5 ? (
                                    <div className="gal-more">+{soAnh - 5}</div>
                                ) : null}
                            </div>
                        ))}
                    </div>
                ) : null}
            </div>

            {canhBao ? (
                <div className="gal-nguon">
                    <svg viewBox="0 0 24 24" fill="var(--org)" aria-hidden="true">
                        <path d="M12 2L1 21h22L12 2zm1 15h-2v-2h2v2zm0-4h-2V8h2v5z" />
                    </svg>{' '}
                    {canhBao}
                </div>
            ) : null}

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

                    {/*
                      Lightbox giữ **bản gốc**, cố ý không có `srcset` (lát 4).
                      Người dùng mở lightbox để xem to; đưa cho họ bản `w1280`
                      ở đây là làm đúng cái việc mà nút này sinh ra để tránh.
                      Bản dẫn xuất lo đường danh sách và ảnh trong trang, không
                      lo đường này.
                    */}
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
