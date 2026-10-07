'use client';

import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { goiAuth, veKhuNguoiMua, type LoiApi } from '@/lib/auth';

/**
 * Hai biểu mẫu xác thực — đăng nhập và đăng ký.
 *
 * Chỉ phần biểu mẫu là client component. Tiêu đề, phần dẫn và thẻ SEO render ở
 * máy chủ, nên trang đọc được kể cả khi JavaScript chưa tải xong.
 *
 * ══ `<a href="#">Quên mật khẩu?</a>` của bản Blade KHÔNG chép sang ══
 *
 * Bản Blade có liên kết đó và nó không đi đâu. Đó là cùng loại khiếm khuyết
 * audit F-15 ("nút CTA không có hành vi") vừa dọn khỏi chân trang, và chép nó
 * sang bản mới là mang theo một lỗi đã biết. Khi có luồng đặt lại mật khẩu
 * thật thì thêm, không trước.
 */

function KhoiLoi({ loi }: { loi: LoiApi | null }) {
    if (!loi) return null;

    return (
        <div className="auth-error" role="alert">
            {loi.chiTiet.length > 0 ? (
                loi.chiTiet.map((c) => <div key={c}>{c}</div>)
            ) : (
                <div>{loi.thongDiep}</div>
            )}
        </div>
    );
}

function Truong({
    ten,
    nhan,
    kieu = 'text',
    bat_buoc = true,
    goi_y,
    toi_da,
    tu_dong,
    children,
}: {
    ten: string;
    nhan: string;
    kieu?: string;
    bat_buoc?: boolean;
    goi_y?: string;
    toi_da?: number;
    tu_dong?: string;
    children?: ReactNode;
}) {
    return (
        <div className="auth-field">
            <label htmlFor={ten}>{nhan}</label>
            <input
                type={kieu}
                id={ten}
                name={ten}
                required={bat_buoc}
                placeholder={goi_y}
                maxLength={toi_da}
                autoComplete={tu_dong}
            />
            {children}
        </div>
    );
}

/** Lấy dữ liệu biểu mẫu, đổi checkbox thành boolean thật. */
function duLieu(form: HTMLFormElement, cacCheckbox: string[]): Record<string, unknown> {
    const fd = new FormData(form);
    const ra: Record<string, unknown> = Object.fromEntries(fd.entries());

    // `FormData` bỏ hẳn checkbox chưa tick, và gửi `"on"` khi đã tick. Máy chủ
    // nhận JSON nên phải là boolean: `accept_privacy` có luật `accepted`, và
    // chuỗi `"on"` cũng qua được luật đó — nhưng gửi đúng kiểu thì không phải
    // dựa vào chuyện ấy.
    for (const ten of cacCheckbox) {
        ra[ten] = fd.has(ten);
    }

    return ra;
}

/**
 * Người đã đăng nhập và đã có tổ chức thì không ở lại trang này.
 *
 * Bản Blade làm việc đó ở máy chủ (`showLogin()` kiểm `Auth::check() &&
 * organizations()->exists()` rồi `redirect('/my')`). Bản Next không làm được ở
 * máy chủ: trang này cache được và không phụ thuộc người xem, nên đọc phiên ở
 * đó sẽ phục vụ quyết định của người A cho người B — cùng lý do `AuthState`
 * tồn tại.
 *
 * Nên kiểm ở trình duyệt. Hệ quả là có một khoảnh khắc biểu mẫu hiện ra trước
 * khi chuyển đi; chấp nhận được, và nó đúng với người CHƯA đăng nhập — tức
 * phần lớn người vào trang này.
 *
 * Điều kiện khớp bản Blade: có phiên VÀ có tổ chức. Người vừa đăng ký mà chưa
 * có tổ chức thì `/my` sẽ đẩy họ sang bước tạo tổ chức, nên đẩy họ đi từ đây
 * là xen vào một luồng không thuộc về trang này.
 */
function useDaDangNhap() {
    useEffect(() => {
        let huy = false;

        fetch('/api/v2/me', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then((b) => {
                if (!huy && b?.data?.organization) veKhuNguoiMua();
            })
            .catch(() => {
                // Chưa đăng nhập trả 401, và mất mạng thì cũng vào đây. Cả hai
                // đều nghĩa là "cứ hiện biểu mẫu" — không có gì để báo.
            });

        return () => {
            huy = true;
        };
    }, []);
}

function useGui(duong: string) {
    const [dangGui, setDangGui] = useState(false);
    const [loi, setLoi] = useState<LoiApi | null>(null);

    async function gui(body: unknown) {
        setDangGui(true);
        setLoi(null);

        try {
            await goiAuth(duong, body);

            // KHÔNG tắt `dangGui` ở đây: trang đang chuyển đi, và bật lại nút
            // chỉ tạo một khoảnh khắc người dùng bấm được lần hai.
            veKhuNguoiMua();
        } catch (e) {
            setLoi(
                (e as LoiApi)?.thongDiep
                    ? (e as LoiApi)
                    : {
                          status: 0,
                          chiTiet: [],
                          thongDiep: 'Không gửi được. Vui lòng kiểm tra kết nối rồi thử lại.',
                      },
            );
            setDangGui(false);
        }
    }

    return { dangGui, loi, gui };
}

export function LoginForm() {
    useDaDangNhap();

    const { dangGui, loi, gui } = useGui('login');

    function onSubmit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        gui(duLieu(e.currentTarget, ['remember']));
    }

    return (
        <>
            <KhoiLoi loi={loi} />

            <form onSubmit={onSubmit} className="auth-form">
                <Truong ten="email" nhan="Email" kieu="email" goi_y="you@company.com" tu_dong="email" />
                <Truong
                    ten="password"
                    nhan="Mật khẩu"
                    kieu="password"
                    goi_y="Mật khẩu của bạn"
                    tu_dong="current-password"
                />

                <div className="auth-row">
                    <label className="auth-check">
                        <input type="checkbox" name="remember" />
                        <span>Nhớ đăng nhập</span>
                    </label>
                </div>

                <button type="submit" className="btn btn-p auth-submit" disabled={dangGui}>
                    {dangGui ? 'Đang đăng nhập…' : 'Đăng nhập'}
                </button>
            </form>
        </>
    );
}

export function RegisterForm() {
    useDaDangNhap();

    const { dangGui, loi, gui } = useGui('register');

    function onSubmit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        gui(duLieu(e.currentTarget, ['accept_privacy']));
    }

    return (
        <>
            <KhoiLoi loi={loi} />

            <form onSubmit={onSubmit} className="auth-form">
                <Truong ten="name" nhan="Họ tên" toi_da={255} tu_dong="name" />
                <Truong ten="email" nhan="Email" kieu="email" toi_da={255} tu_dong="email" />
                <Truong
                    ten="password"
                    nhan="Mật khẩu"
                    kieu="password"
                    tu_dong="new-password"
                    goi_y="Tối thiểu 8 ký tự"
                />
                <Truong
                    ten="password_confirmation"
                    nhan="Nhập lại mật khẩu"
                    kieu="password"
                    tu_dong="new-password"
                />
                <Truong ten="organization_name" nhan="Tên tổ chức" toi_da={255} tu_dong="organization" />

                <div className="auth-field">
                    <label htmlFor="organization_type">Loại tổ chức</label>
                    <select id="organization_type" name="organization_type" required defaultValue="">
                        <option value="" disabled>
                            Chọn loại tổ chức
                        </option>
                        <option value="agency">Agency</option>
                        <option value="client">Client</option>
                        <option value="brand">Brand</option>
                    </select>
                </div>

                {/*
                  Ô đồng ý là bằng chứng chấp thuận, không phải một ô trang trí:
                  `BuyerRegistrationService` ghi một bản ghi `PolicyConsent` có
                  đóng dấu PHIÊN BẢN của chính sách tại thời điểm đăng ký. Nên
                  liên kết phải trỏ tới đúng trang người dùng đang đồng ý.
                */}
                <div className="auth-row">
                    <label className="auth-check">
                        <input type="checkbox" name="accept_privacy" />
                        <span>
                            Tôi đồng ý với{' '}
                            <a href="/chinh-sach-bao-mat" target="_blank" rel="noopener">
                                Chính sách bảo mật thông tin
                            </a>
                        </span>
                    </label>
                </div>

                <button type="submit" className="btn btn-p auth-submit" disabled={dangGui}>
                    {dangGui ? 'Đang tạo tài khoản…' : 'Đăng ký'}
                </button>
            </form>
        </>
    );
}
