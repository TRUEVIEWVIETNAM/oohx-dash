'use client';

import { useEffect, useState, type ReactNode } from 'react';
import { useRouter } from 'next/navigation';

/**
 * Thanh kết quả + lưới kho: số kết quả, ô sắp xếp, hai nút đổi kiểu xem.
 *
 * Dựng lại `results-header` và `inv-grid` của `frontpage/listing.blade.php`.
 * Bản Next trước đó **không có** thanh này: không số kết quả ngoài hero, không
 * ô sắp xếp dù `/api/v2/screens` nhận `sort`, và lưới đặt trong
 * `oc-grid oc-grid--full` — lưới dành cho thẻ **chủ sở hữu**, không phải thẻ
 * màn hình.
 *
 * ══ Vì sao là client component, và vì sao thẻ vẫn render ở máy chủ ══
 *
 * Ba việc ở đây cần trình duyệt: đọc `localStorage`, đổi class của lưới, và
 * đẩy URL khi chọn sắp xếp. Nhưng **thẻ** thì vẫn do trang (server component)
 * dựng và truyền vào qua `children` — nên HTML đầu tiên đã có đủ thẻ, và phép
 * kiểm SEO đọc được chúng. Nếu lưới tự gọi API trong trình duyệt thì
 * `webapp/test/seo.mjs` chỉ thấy một khung rỗng.
 *
 * ══ Khoá `localStorage` giữ nguyên tên của bản Blade ══
 *
 * `listing_view`. Người đã chọn "danh sách" trên bản Blade mở lại trang vẫn ở
 * đúng kiểu xem đó — đổi tên khoá là lặng lẽ quên lựa chọn của họ.
 *
 * Đọc trong `useEffect`, không đọc lúc render: server không có
 * `localStorage`, nên đọc lúc render làm HTML hai phía lệch nhau (hydration
 * mismatch). Hệ quả thấy được: một nhịp đầu luôn là kiểu lưới, rồi đổi sang
 * danh sách nếu người dùng đã chọn thế. Đúng như bản Blade, vốn cũng đặt class
 * sau khi tài liệu đã dựng.
 */
const KHOA_KIEU_XEM = 'listing_view';

export type SapXep = '' | 'price_asc' | 'price_desc' | 'newest';

/** Đúng bốn lựa chọn của bản Blade, và đúng ba giá trị `/api/v2/screens` nhận. */
const SAP_XEP: { gia_tri: SapXep; nhan: string }[] = [
    { gia_tri: '', nhan: 'Mặc định' },
    { gia_tri: 'price_asc', nhan: 'Giá thấp nhất' },
    { gia_tri: 'price_desc', nhan: 'Giá cao nhất' },
    { gia_tri: 'newest', nhan: 'Mới nhất' },
];

export function KhoiKetQua({
    tong,
    donVi = 'vị trí',
    sort = '',
    basePath,
    query = {},
    kieuXem = true,
    children,
}: {
    tong: number;
    donVi?: string;
    sort?: string;
    basePath: string;
    query?: Record<string, string | undefined>;
    /**
     * Hai nút đổi kiểu xem lưới/danh sách.
     *
     * `/products` của bản Blade **không có** chúng, và lý do nằm trong CSS:
     * `.inv-grid.list-view` chỉ đặt lưới về một cột rồi nhắm tiếp vào `.ic-*`
     * — bộ class của `.inv-card`, không phải `.pd-card` của thẻ gói. Dựng hai
     * nút đó ở đây là cho người dùng một công tắc chỉ làm lưới hẹp lại.
     */
    kieuXem?: boolean;
    children: ReactNode;
}) {
    const router = useRouter();
    const [dangList, setDangList] = useState(false);

    useEffect(() => {
        try {
            setDangList(window.localStorage.getItem(KHOA_KIEU_XEM) === 'list');
        } catch {
            // Chế độ riêng tư hoặc bị chặn site data: giữ kiểu lưới, không vỡ.
        }
    }, []);

    function doiKieuXem(list: boolean) {
        setDangList(list);

        try {
            window.localStorage.setItem(KHOA_KIEU_XEM, list ? 'list' : 'grid');
        } catch {
            // Không lưu được thì vẫn đổi được kiểu xem cho lượt này.
        }
    }

    function chonSapXep(gia_tri: string) {
        const url = new URLSearchParams();

        for (const [k, v] of Object.entries(query)) {
            // `page` cố ý bị bỏ: đổi cách sắp xếp thì trang 7 của thứ tự cũ
            // không còn nghĩa gì.
            if (v && k !== 'sort' && k !== 'page') url.set(k, v);
        }

        if (gia_tri) url.set('sort', gia_tri);

        const qs = url.toString();
        router.push(qs ? `${basePath}?${qs}` : basePath);
    }

    return (
        <>
            <div className="results-header">
                <div className="rh-count">
                    Hiển thị <strong>{new Intl.NumberFormat('vi-VN').format(tong)}</strong> {donVi}
                </div>

                <select
                    className="sort-sel"
                    value={sort}
                    onChange={(e) => chonSapXep(e.target.value)}
                    aria-label="Sắp xếp"
                >
                    {SAP_XEP.map((o) => (
                        <option value={o.gia_tri} key={o.gia_tri || 'default'}>
                            {o.nhan}
                        </option>
                    ))}
                </select>

                {kieuXem ? (
                    <>
                    <button
                        type="button"
                        className={dangList ? 'view-btn' : 'view-btn on'}
                        onClick={() => doiKieuXem(false)}
                        aria-label="Xem dạng lưới"
                        aria-pressed={!dangList}
                    >
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M3 3v8h8V3H3zm6 6H5V5h4v4zm-6 4v8h8v-8H3zm6 6H5v-4h4v4zm4-16v8h8V3h-8zm6 6h-4V5h4v4zm-6 4v8h8v-8h-8zm6 6h-4v-4h4v4z" />
                        </svg>
                    </button>

                    <button
                        type="button"
                        className={dangList ? 'view-btn on' : 'view-btn'}
                        onClick={() => doiKieuXem(true)}
                        aria-label="Xem dạng danh sách"
                        aria-pressed={dangList}
                    >
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z" />
                        </svg>
                    </button>
                    </>
                ) : null}
            </div>

            <div className={dangList ? 'inv-grid list-view' : 'inv-grid'}>{children}</div>
        </>
    );
}
