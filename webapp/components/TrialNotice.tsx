'use client';

import { useEffect, useState } from 'react';

/**
 * Thông báo website đang chạy thử nghiệm, chưa hoàn tất đăng ký với Bộ Công Thương.
 *
 * Dựng lại từ `resources/views/frontpage/partials/trial-notice.blade.php`, và
 * giữ nguyên ba quyết định đã chốt ở đó:
 *
 * - Hiện ở đầu trang, tự ẩn sau **6,5 giây** (yêu cầu review: giữ 5–7s).
 * - **Không ghi nhớ trạng thái đã đóng** — không cookie, không localStorage.
 *   Yêu cầu là mọi khách đều phải nhìn thấy, nên nó hiện lại ở mỗi lần tải.
 * - Tắt toàn site bằng `OOHX_TRIAL_MODE=false` khi đã có xác nhận đăng ký.
 *
 * ══ Vì sao là client component ══
 *
 * Nó có hiệu ứng theo thời gian và một nút đóng, nên cần JS. Nhưng nội dung
 * chữ nằm trong HTML máy chủ trả về ngay từ đầu, không chờ JS — một thông báo
 * pháp lý mà chỉ hiện khi JS chạy xong thì có người không bao giờ thấy.
 *
 * ══ Cờ bật/tắt đọc ở máy chủ ══
 *
 * `OOHX_TRIAL_MODE` là biến môi trường của tiến trình Next, đọc ở layout và
 * truyền xuống. Không đọc `config('policies.trial_mode')` của Laravel được —
 * Next không chạy trong cùng tiến trình. Hệ quả: đổi cờ phải đổi ở **hai**
 * chỗ, `.env` của Laravel và unit systemd của Next. Đó là chi phí của việc
 * chạy song song hai bản.
 */
export function TrialNotice() {
    const [state, setState] = useState<'dau' | 'hien' | 'dang-an' | 'an'>('dau');

    useEffect(() => {
        const vao = requestAnimationFrame(() => setState('hien'));
        const hen = setTimeout(() => setState('dang-an'), 6500);

        return () => {
            cancelAnimationFrame(vao);
            clearTimeout(hen);
        };
    }, []);

    useEffect(() => {
        if (state !== 'dang-an') return;

        // Bỏ khỏi luồng đọc của trình đọc màn hình sau khi hiệu ứng chạy xong.
        const hen = setTimeout(() => setState('an'), 400);

        return () => clearTimeout(hen);
    }, [state]);

    if (state === 'an') return null;

    const classes = ['trial-notice'];
    if (state === 'hien') classes.push('is-in');
    if (state === 'dang-an') classes.push('is-in', 'is-gone');

    return (
        <div className={classes.join(' ')} role="status" aria-live="polite">
            <div className="w trial-notice-in">
                <span className="material-symbols-outlined trial-notice-ico" aria-hidden="true">
                    warning
                </span>
                <span className="trial-notice-txt">
                    Website đang hoạt động ở chế độ thử nghiệm, đang thực hiện đăng ký với Bộ
                    Công Thương.
                </span>
                <button
                    type="button"
                    className="trial-notice-x"
                    aria-label="Đóng thông báo"
                    onClick={() => setState('dang-an')}
                >
                    &times;
                </button>
            </div>
        </div>
    );
}
