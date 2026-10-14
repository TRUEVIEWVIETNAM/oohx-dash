'use client';

import { useState, type ReactNode } from 'react';

/**
 * Bốn tab của trang chi tiết: Thông tin · Insights · Lịch trống · Đánh giá.
 *
 * Dựng lại khối `tabs` + `tp` của `frontpage/detail.blade.php`. Bản Blade làm
 * việc này bằng `onclick="sw(this,'tp-info')"` — một hàm toàn cục đổi class
 * trên `document`. Ở đây là state, nên không có hàm nào treo trên `window`.
 *
 * ══ Nội dung mọi tab đều render ở máy chủ ══
 *
 * `children` là các panel do trang (server component) dựng sẵn và truyền vào;
 * component này chỉ đổi class `on`. Nên HTML đầu tiên đã chứa **cả bốn** tab,
 * và công cụ tìm kiếm đọc được thông số, insights, lịch trống — đúng như bản
 * Blade, vốn cũng in hết rồi ẩn bằng CSS.
 *
 * Nếu tab chỉ nạp khi bấm thì `webapp/test/khoi-giao-dien.mjs` và phép kiểm
 * SEO chỉ thấy một khung rỗng, và đó là mất chính thứ giai đoạn 6 đi tìm.
 */
export function TabChiTiet({
    tabs,
}: {
    tabs: { khoa: string; nhan: string; noiDung: ReactNode }[];
}) {
    const [dangMo, setDangMo] = useState(tabs[0]?.khoa ?? '');

    return (
        <>
            <div className="tabs" role="tablist">
                {tabs.map((t) => (
                    <button
                        key={t.khoa}
                        type="button"
                        role="tab"
                        aria-selected={dangMo === t.khoa}
                        className={dangMo === t.khoa ? 'tab on' : 'tab'}
                        onClick={() => setDangMo(t.khoa)}
                    >
                        {t.nhan}
                    </button>
                ))}
            </div>

            {tabs.map((t) => (
                <div
                    key={t.khoa}
                    id={`tp-${t.khoa}`}
                    role="tabpanel"
                    className={dangMo === t.khoa ? 'tp on' : 'tp'}
                >
                    {t.noiDung}
                </div>
            ))}
        </>
    );
}
