'use client';

import { useState } from 'react';
import { dao, TOI_DA, type MucDaChon } from '@/lib/danh-sach-rut-gon';
import { dungDanhSach } from '@/lib/dung-danh-sach';

/**
 * Ô tick thêm một vị trí vào danh sách rút gọn (lát 4).
 *
 * ══ Vì sao là `<span role="checkbox">` chứ không phải `<button>` ══
 *
 * Trên thẻ màn hình, ô này nằm **bên trong** `<a class="sc-card">` — cả thẻ
 * là một liên kết, và đó là cấu trúc của bản Blade mà CSS được viết cho.
 * `<button>` lồng trong `<a>` là HTML không hợp lệ; trình duyệt tự sửa bằng
 * cách tách phần tử ra khỏi liên kết, và chỗ hỏng chỉ hiện ở một số trình
 * duyệt. `<span>` thì hợp lệ, và `role="checkbox"` + `aria-checked` +
 * `tabIndex` cho trình đọc màn hình đúng thứ nó cần.
 *
 * `preventDefault()` và `stopPropagation()` là bắt buộc: thiếu chúng thì mỗi
 * cú tick cũng là một cú bấm vào liên kết, và người dùng bị đẩy sang trang
 * chi tiết.
 */
export function OTick({ muc, nhan = 'Chọn vị trí này' }: { muc: MucDaChon; nhan?: string }) {
    const ds = dungDanhSach();
    const [canhBao, setCanhBao] = useState(false);

    const dangChon = ds.some((m) => m.slug === muc.slug);

    function tick(e: { preventDefault: () => void; stopPropagation: () => void }) {
        e.preventDefault();
        e.stopPropagation();

        const kq = dao(muc);

        // Chạm trần thì nói ra ngay tại chỗ người dùng vừa bấm. Một ô tick
        // không phản ứng là thứ người ta thử lại ba lần rồi bỏ đi.
        setCanhBao(!kq.ok && kq.lyDo === 'day');
    }

    return (
        <span
            className={dangChon ? 'o-tick on' : 'o-tick'}
            role="checkbox"
            aria-checked={dangChon}
            aria-label={nhan}
            tabIndex={0}
            onClick={tick}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') tick(e);
            }}
        >
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z" />
            </svg>

            {canhBao ? (
                <span className="o-tick-day" role="status">
                    Danh sách đã đủ {TOI_DA} vị trí
                </span>
            ) : null}
        </span>
    );
}
