'use client';

import { useState } from 'react';

/**
 * Biểu mẫu gửi phản ánh — POST thẳng từ trình duyệt sang `/api/v2/reflections`.
 *
 * ══ Vì sao gọi thẳng, không qua Route Handler ══
 *
 * CLAUDE.md mục 3: "phần cần bí mật đi qua Route Handler, không lộ token ra
 * trình duyệt." Ở đây **không có bí mật** — endpoint công khai, không token,
 * không khóa. Thêm một chặng Route Handler chỉ để chuyển tiếp nguyên văn là
 * thêm một chỗ hỏng và một chỗ phải giữ đồng bộ, đổi lại không được gì.
 *
 * Cùng gốc nên không có CORS: `/api/*` do Laravel phục vụ trên chính
 * `oohx.net`, và `nextjs.conf` cố ý không bao giờ proxy nó.
 *
 * Không cần CSRF: nhóm `api` của dự án **không** bật Sanctum stateful
 * (`bootstrap/app.php` không gọi `statefulApi()`), nên `/api/v2/*` không đi qua
 * `VerifyCsrfToken`. Nếu sau này ai bật nó, form này sẽ bắt đầu nhận 419 — và
 * đó là lúc cần một Route Handler, không phải lúc này.
 *
 * ══ Bẫy mật phải CÓ ở đây ══
 *
 * `StorePublicReflectionRequest` có luật `website => prohibited`, và bộ luật đó
 * dùng chung giữa trang Blade và API. Nên trường ẩn phải được gửi đi: thiếu nó
 * thì bẫy vẫn hoạt động (trống là hợp lệ), nhưng bot điền nó sẽ không bị chặn
 * vì nó không tồn tại trong DOM để bot thấy.
 *
 * ══ Ba mã trả về, ba cách hiển thị khác nhau ══
 *
 * 201  mã tra cứu — người gửi cần giữ nó.
 * 422  lỗi từng trường, lấy từ `details[]` của envelope v2.
 * 429  vượt hạn mức (`throttle:5,60`). KHÔNG hiện như lỗi nhập liệu: người
 *      dùng không sửa được gì bằng cách đổi nội dung, nên nói rõ là phải chờ.
 */

type Truong = {
    ten: string;
    nhan: string;
    bat_buoc?: boolean;
    kieu?: 'text' | 'email' | 'tel';
    toi_da?: number;
    ghi_chu?: React.ReactNode;
};

const CHI_TIET: Truong[] = [
    { ten: 'organization_name', nhan: 'Tên tổ chức', bat_buoc: true, toi_da: 255 },
    { ten: 'subject', nhan: 'Tiêu đề phản ánh', bat_buoc: true, toi_da: 255 },
];

type LoiTruong = { field?: string; message?: string };

export function ReflectionForm() {
    const [dangGui, setDangGui] = useState(false);
    const [maTraCuu, setMaTraCuu] = useState<string | null>(null);
    const [loi, setLoi] = useState<string[]>([]);
    const [quaHanMuc, setQuaHanMuc] = useState(false);

    async function gui(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();

        const form = e.currentTarget;
        const data = Object.fromEntries(new FormData(form).entries());

        setDangGui(true);
        setLoi([]);
        setQuaHanMuc(false);
        setMaTraCuu(null);

        try {
            const res = await fetch('/api/v2/reflections', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(data),
            });

            if (res.status === 429) {
                setQuaHanMuc(true);

                return;
            }

            let body: {
                data?: { code?: string };
                message?: string;
                details?: LoiTruong[];
            } | null = null;

            try {
                body = await res.json();
            } catch {
                // 500 từ tầng web có thể trả HTML. Giữ `null` thay vì để lỗi
                // phân tích JSON che mất mã trạng thái thật.
            }

            if (res.ok && body?.data?.code) {
                setMaTraCuu(body.data.code);
                form.reset();

                return;
            }

            const chiTiet = (body?.details ?? [])
                .map((d) => d.message)
                .filter((m): m is string => Boolean(m));

            setLoi(
                chiTiet.length > 0
                    ? chiTiet
                    : [body?.message ?? `Máy chủ trả mã ${res.status}. Vui lòng thử lại.`],
            );
        } catch {
            // Mất mạng, hoặc trình duyệt chặn. Nói đúng cái biết được.
            setLoi(['Không gửi được. Vui lòng kiểm tra kết nối rồi thử lại.']);
        } finally {
            setDangGui(false);
        }
    }

    return (
        <>
            {maTraCuu ? (
                <div className="pol-ok" role="status">
                    <strong>Đã tiếp nhận phản ánh của quý tổ chức.</strong> Mã tra cứu:{' '}
                    <code>{maTraCuu}</code>. Chúng tôi sẽ phản hồi qua email liên hệ mà quý tổ
                    chức đã cung cấp.
                </div>
            ) : null}

            {quaHanMuc ? (
                <div className="pol-err" role="alert">
                    <strong>Đã gửi quá số lần cho phép.</strong> Mỗi địa chỉ chỉ gửi được 5 phản
                    ánh trong một giờ. Vui lòng thử lại sau, hoặc liên hệ hotline nếu việc gấp.
                </div>
            ) : null}

            {loi.length > 0 ? (
                <div className="pol-err" role="alert">
                    <strong>Vui lòng kiểm tra lại các thông tin sau:</strong>
                    <ul>
                        {loi.map((l) => (
                            <li key={l}>{l}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            <form onSubmit={gui} className="pol-form">
                {/*
                  Bẫy mật. Người thật không thấy trường này nên không bao giờ
                  điền; `StorePublicReflectionRequest` có `website => prohibited`
                  nên một giá trị bất kỳ làm cả yêu cầu bị từ chối.
                */}
                <div className="pol-hp" aria-hidden="true">
                    <label htmlFor="website">Website</label>
                    <input type="text" name="website" id="website" tabIndex={-1} autoComplete="off" />
                </div>

                {CHI_TIET.map((t) => (
                    <div className="pol-f" key={t.ten}>
                        <label htmlFor={t.ten}>
                            {t.nhan} {t.bat_buoc ? <span className="req">*</span> : null}
                        </label>
                        <input
                            type={t.kieu ?? 'text'}
                            name={t.ten}
                            id={t.ten}
                            required={t.bat_buoc}
                            maxLength={t.toi_da}
                        />
                    </div>
                ))}

                <div className="pol-f">
                    <label htmlFor="content">
                        Nội dung phản ánh <span className="req">*</span>
                    </label>
                    <textarea name="content" id="content" rows={8} required minLength={20} maxLength={5000} />
                    <small>
                        Tối thiểu 20 ký tự. Nội dung này sẽ được công khai nếu phản ánh được đăng.
                    </small>
                </div>

                <div className="pol-f2">
                    <div className="pol-f">
                        <label htmlFor="contact_name">Người liên hệ</label>
                        <input type="text" name="contact_name" id="contact_name" maxLength={255} />
                    </div>
                    <div className="pol-f">
                        <label htmlFor="contact_phone">Số điện thoại</label>
                        <input type="tel" name="contact_phone" id="contact_phone" maxLength={30} />
                    </div>
                </div>

                <div className="pol-f">
                    <label htmlFor="contact_email">
                        Email liên hệ <span className="req">*</span>
                    </label>
                    <input type="email" name="contact_email" id="contact_email" required maxLength={255} />
                    <small>
                        Thông tin liên hệ chỉ dùng để phản hồi kết quả xử lý và{' '}
                        <strong>không được công khai</strong>.
                    </small>
                </div>

                <button type="submit" className="pol-submit" disabled={dangGui}>
                    {dangGui ? 'Đang gửi…' : 'Gửi phản ánh'}
                </button>
            </form>
        </>
    );
}
