'use client';

import { useEffect, useRef, useState, type ReactNode } from 'react';

/**
 * Hai tab của trang hồ sơ media owner: "Tổng quan" và "Màn hình".
 *
 * Dựng lại `fp-tabs` + `fp-panel` của `frontpage/owner-detail.blade.php`. Bản
 * Blade làm bằng `onclick="fpTab(this,'fp-overview')"` — một hàm toàn cục đổi
 * class trên `document`, cộng `history.replaceState` để nhớ tab trong URL.
 *
 * ══ Tab nào mở lúc đầu do MÁY CHỦ quyết, không do `useEffect` ══
 *
 * Bản Blade mở tab "Tổng quan" rồi, nếu URL có bộ lọc hoặc `?tab=inventory`,
 * **nhảy sang** tab kia trong một `IIFE` sau khi tài liệu dựng xong. Người
 * dùng thấy một nhịp sai: nội dung tổng quan hiện ra rồi biến mất.
 *
 * Ở đây trang (server component) đọc `searchParams` và truyền `tabBanDau`,
 * nên HTML đầu tiên đã đúng tab. Mở một link có bộ lọc thì thấy ngay danh
 * sách màn hình, không thấy một nhịp tổng quan trước đó.
 *
 * ══ Nội dung cả hai tab đều render ở máy chủ ══
 *
 * `children` là hai panel do trang dựng sẵn; component này chỉ đổi class.
 * HTML đầu tiên vì thế chứa **cả hai**, và phép kiểm SEO đọc được danh sách
 * màn hình kể cả khi tab mặc định là "Tổng quan" — đúng như bản Blade, vốn
 * cũng in hết rồi ẩn bằng CSS.
 *
 * ══ Không đẩy URL khi đổi tab ══
 *
 * Bản Blade ghi `?tab=inventory` vào URL bằng `history.replaceState`. Ở đây
 * tab là một `useState` thường: `router.push` sẽ gọi lại server component và
 * nạp lại dữ liệu cho một việc chỉ là đổi class. URL vẫn **đọc được**
 * `?tab=inventory` khi mở từ ngoài vào — chỉ là bấm tab không viết vào nó.
 */
export function HoSoOwner({
    tabBanDau,
    soManHinh,
    tongQuan,
    kho,
}: {
    tabBanDau: 'overview' | 'inventory';
    soManHinh: number;
    tongQuan: ReactNode;
    kho: ReactNode;
}) {
    const [tab, setTab] = useState(tabBanDau);

    return (
        <>
            <div className="fp-nav-wrap">
                <div className="fp-tabs" role="tablist">
                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === 'overview'}
                        className={tab === 'overview' ? 'fp-tab on' : 'fp-tab'}
                        onClick={() => setTab('overview')}
                    >
                        Tổng quan
                    </button>

                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === 'inventory'}
                        className={tab === 'inventory' ? 'fp-tab on' : 'fp-tab'}
                        onClick={() => setTab('inventory')}
                    >
                        Màn hình{' '}
                        <span style={{ fontWeight: 400, color: 'var(--t4)' }}>({soManHinh})</span>
                    </button>
                </div>
            </div>

            <div className="fp-content">
                <div
                    id="fp-overview"
                    role="tabpanel"
                    className={tab === 'overview' ? 'fp-panel on' : 'fp-panel'}
                >
                    {tongQuan}
                </div>

                <div
                    id="fp-inventory"
                    role="tabpanel"
                    className={tab === 'inventory' ? 'fp-panel on' : 'fp-panel'}
                >
                    {kho}
                </div>
            </div>
        </>
    );
}

/**
 * Băng trượt "Màn hình nổi bật" với hai nút mũi tên.
 *
 * `fp-slider` là một vùng cuộn ngang có `scroll-snap`; hai nút chỉ gọi
 * `scrollBy`. Giữ đúng cách bản Blade làm, kể cả bước cuộn 260px — bằng chiều
 * rộng một thẻ cộng khoảng cách, nên mỗi lần bấm đi đúng một thẻ.
 *
 * Nút bị **vô hiệu ở hai đầu**, điều bản Blade không làm: CSS đã có
 * `.fp-slider-btn:disabled` (opacity .3) nhưng không có JS nào đặt `disabled`,
 * nên hai nút ở bản cũ luôn trông bấm được kể cả khi không còn gì để cuộn.
 */
export function BangTruot({ children }: { children: ReactNode }) {
    const ref = useRef<HTMLDivElement>(null);
    const [dauTrai, setDauTrai] = useState(true);
    const [dauPhai, setDauPhai] = useState(false);

    function doHaiDau() {
        const el = ref.current;
        if (!el) return;

        setDauTrai(el.scrollLeft <= 1);
        setDauPhai(el.scrollLeft + el.clientWidth >= el.scrollWidth - 1);
    }

    // Đo một lần sau khi mount: nếu số thẻ ít hơn bề rộng băng thì **cả hai**
    // nút phải vô hiệu ngay từ đầu. Không đo thì nút "Sau" trông bấm được
    // trong khi không có gì để cuộn, và `onScroll` không bao giờ chạy để sửa.
    useEffect(doHaiDau, []);

    function truot(buoc: number) {
        ref.current?.scrollBy({ left: buoc, behavior: 'smooth' });
    }

    return (
        <div className="fp-card fp-featured-card">
            <div className="fp-featured-head">
                <div className="fp-card-title" style={{ marginBottom: 0 }}>
                    Màn hình nổi bật
                </div>

                <div className="fp-slider-nav">
                    <button
                        type="button"
                        className="fp-slider-btn"
                        aria-label="Trước"
                        disabled={dauTrai}
                        onClick={() => truot(-260)}
                    >
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z" />
                        </svg>
                    </button>

                    <button
                        type="button"
                        className="fp-slider-btn"
                        aria-label="Sau"
                        disabled={dauPhai}
                        onClick={() => truot(260)}
                    >
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z" />
                        </svg>
                    </button>
                </div>
            </div>

            <div className="fp-slider" ref={ref} onScroll={doHaiDau}>
                {children}
            </div>
        </div>
    );
}
