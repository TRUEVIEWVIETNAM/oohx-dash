'use client';

import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { KhoiKetQua } from '@/components/KhoiKetQua';
import { BanDoKetQua } from '@/components/BanDoKetQua';
import { ThanhDanhSach } from '@/components/ThanhDanhSach';

/**
 * Hai cột phải của `/explore`: kết quả và bản đồ (lát 3).
 *
 * ══ Vì sao phải có một thành phần bọc, thay vì đặt bản đồ thẳng vào trang ══
 *
 * Bản đồ và danh sách phải **nói chuyện với nhau**: rê chuột lên thẻ thì pin
 * sáng, bấm pin thì cuộn tới thẻ. Trạng thái đó sống ở trình duyệt, mà trang
 * `/explore` là server component và phải giữ nguyên như vậy — HTML do máy chủ
 * trả về là toàn bộ lý do giai đoạn 6 tồn tại.
 *
 * Nên trang vẫn dựng thẻ ở máy chủ rồi truyền xuống đây qua `children`. Thành
 * phần này chỉ thêm phần cần trình duyệt. Công cụ tìm kiếm đọc được danh sách
 * y như trước.
 *
 * ══ Rê chuột bắt bằng uỷ nhiệm, không bằng handler trên từng thẻ ══
 *
 * Thẻ do máy chủ dựng, nên không gắn được `onMouseEnter` lên chúng — hàm
 * không đi qua ranh giới server/client. Thay vào đó `ScreenCard` in
 * `data-slug`, và một handler duy nhất ở đây đọc `closest('[data-slug]')`.
 *
 * Cách này còn rẻ hơn: 24 thẻ là 24 listener, ở đây là một.
 *
 * ══ Bản đồ chỉ mount khi có người xem ══
 *
 * Dưới 1280px bản đồ là một kiểu xem phải bấm mới mở. Mount nó sẵn rồi giấu
 * bằng CSS là tải Leaflet, tile và tới 500 pin cho một người dùng điện thoại
 * chưa hề hỏi tới bản đồ.
 *
 * `matchMedia` đọc **cùng** một mốc với CSS (1280px). Lần render đầu
 * `rong = false` ở cả hai phía, nên không có lệch HTML; giá trị thật vào sau
 * trong `useEffect`.
 */

/** Cùng mốc với `.ex-layout` trong `frontpage.css`. Đổi một chỗ là phải đổi cả hai. */
const MOC_BA_COT = '(min-width: 1280px)';

/** Thẻ sáng bao lâu sau khi bấm pin, tính bằng mili giây. */
const SANG_MS = 1600;

export function KhoiExplore({
    tong,
    sort,
    query,
    pagination,
    children,
}: {
    tong: number;
    sort: string;
    /** Tham số URL hiện tại — cho ô sắp xếp và cho bản đồ. */
    query: Record<string, string | undefined>;
    pagination: ReactNode;
    children: ReactNode;
}) {
    const [xemBanDo, setXemBanDo] = useState(false);
    const [rong, setRong] = useState(false);
    const [slugSang, setSlugSang] = useState<string | null>(null);

    const kqRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const mq = window.matchMedia(MOC_BA_COT);
        const doi = () => setRong(mq.matches);

        doi();
        mq.addEventListener('change', doi);

        return () => mq.removeEventListener('change', doi);
    }, []);

    /**
     * Bấm pin: cuộn tới thẻ tương ứng và làm nó sáng lên.
     *
     * Một cụm có thể gom nhiều màn hình cùng toà nhà, và bản đồ hiện tới 500
     * vị trí trong khi lưới chỉ có 24 — nên thử lần lượt từng slug, và nếu
     * không slug nào có thẻ trên trang thì không làm gì. Popup của bản đồ vẫn
     * mở, và nó có đường sang trang chi tiết.
     */
    const chonCum = useCallback((slugs: string[]) => {
        const goc = kqRef.current;
        if (!goc) return;

        for (const slug of slugs) {
            const the = goc.querySelector<HTMLElement>(`[data-slug="${CSS.escape(slug)}"]`);
            if (!the) continue;

            the.scrollIntoView({ behavior: 'smooth', block: 'center' });
            the.classList.add('ex-the-sang');
            window.setTimeout(() => the.classList.remove('ex-the-sang'), SANG_MS);

            return;
        }
    }, []);

    function reVao(e: React.MouseEvent<HTMLDivElement>) {
        const the = (e.target as HTMLElement).closest<HTMLElement>('[data-slug]');

        setSlugSang(the?.dataset.slug ?? null);
    }

    // Bản đồ chiếm chỗ của lưới chỉ khi nó KHÔNG có cột riêng. Người dùng mở
    // bản đồ ở 1100px rồi kéo cửa sổ rộng ra thì ba cột hiện đủ, và lưới phải
    // quay lại — chứ không biến mất vì một lựa chọn đã hết nghĩa.
    const anLuoi = xemBanDo && !rong;

    return (
        <>
            <div
                ref={kqRef}
                className={anLuoi ? 'ex-cot-kq ex-an-luoi' : 'ex-cot-kq'}
                onMouseOver={reVao}
                onMouseLeave={() => setSlugSang(null)}
            >
                <KhoiKetQua
                    tong={tong}
                    sort={sort}
                    basePath="/explore"
                    query={query}
                    nutThem={
                        <button
                            type="button"
                            className={xemBanDo ? 'view-btn ex-nut-map on' : 'view-btn ex-nut-map'}
                            onClick={() => setXemBanDo(!xemBanDo)}
                            aria-pressed={xemBanDo}
                            aria-label={xemBanDo ? 'Xem danh sách' : 'Xem bản đồ'}
                        >
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5zM15 19l-6-2.11V5l6 2.11V19z" />
                            </svg>
                        </button>
                    }
                >
                    {children}
                </KhoiKetQua>

                {anLuoi ? null : pagination}
            </div>

            <div className={xemBanDo ? 'ex-cot-map hien' : 'ex-cot-map'}>
                {rong || xemBanDo ? (
                    <BanDoKetQua
                        thamSo={query}
                        slugSang={slugSang}
                        khiChonCum={chonCum}
                        hien={xemBanDo || rong}
                    />
                ) : null}
            </div>

            {/*
              Thanh đáy của danh sách rút gọn (lát 4). Nó tự ẩn khi chưa chọn
              gì, nên không có nhánh điều kiện ở đây — và nó nằm ngoài hai
              cột vì `position: fixed`.
            */}
            <ThanhDanhSach />
        </>
    );
}
