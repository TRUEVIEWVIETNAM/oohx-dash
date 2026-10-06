import { AuthState } from './AuthState';
import { AppLink } from './AppLink';
import { LOGO_DATA_URI } from '@/lib/logo';

/**
 * Thanh điều hướng, dựng lại từ `resources/views/frontpage/partials/header.blade.php`.
 *
 * ══ Khung render ở máy chủ, trạng thái đăng nhập điền ở trình duyệt ══
 *
 * Phần không phụ thuộc người xem — logo, bốn liên kết, nút hamburger — render ở
 * máy chủ, nên nó có trong HTML và công cụ tìm kiếm thấy được. Phần phụ thuộc
 * người xem nằm trong `<AuthState>`, một client component.
 *
 * Lý do không render trạng thái đăng nhập ở máy chủ: trang cache 60 giây, nên
 * bản cache sẽ phục vụ tên và email của người A cho người B. Chi tiết trong
 * `AuthState` và `Api\V2\MeController`.
 *
 * ══ `<a>` chứ không `next/link` cho đường Laravel phục vụ ══
 *
 * Giai đoạn 6 chuyển từng đường dẫn, nên `/agency` và `/` vẫn do Laravel phục
 * vụ. `next/link` tới một đường như thế sẽ thử điều hướng phía client và không
 * tìm thấy route — người dùng thấy trang 404 của Next cho một trang có thật.
 *
 * Không dòng nào ở đây phải sửa khi chuyển thêm một đường: mọi liên kết đi qua
 * `AppLink`, và danh sách `TRONG_APP` ở `lib/duong-dan.ts` là chỗ duy nhất
 * phải sửa — chỗ đó dùng chung với chân trang.
 */

/**
 * Logo, đi qua cùng phép kiểm `AppLink` như các mục nav.
 *
 * Viết cứng `<a href="/">` thì khi `/` được proxy sang Next, logo vẫn tải lại
 * cả trang cho một điều hướng nội bộ — và không ai nhớ sửa thêm một dòng ở
 * đây.
 */
function LogoLink({ children }: { children: React.ReactNode }) {
    return (
        <AppLink href="/" className="hdr-logo">
            {children}
        </AppLink>
    );
}

function NavLink({ href, active, children }: {
    href: string;
    active: boolean;
    children: React.ReactNode;
}) {
    return (
        <AppLink href={href} className={active ? 'ac' : undefined}>
            {children}
        </AppLink>
    );
}

export type NavKey = 'home' | 'explore' | 'map' | 'owners' | 'agency' | '';

export function SiteHeader({ active = '' }: { active?: NavKey }) {
    return (
        <header className="hdr">
            <div className="hdr-in">
                {/*
                  Logo đi qua cùng phép kiểm `TRONG_APP` như các mục nav, không
                  viết cứng `<a>`: khi `/` được proxy sang Next thì nó tự thành
                  điều hướng phía client, và không ai phải nhớ sửa thêm một dòng
                  ở đây.
                */}
                <LogoLink>
                    {/*
                      Logo nhúng sẵn trong HTML, không gán bằng JS sau khi tải.

                      Bản Blade để `<img src="">` rỗng rồi một script đặt `src`
                      ở `DOMContentLoaded` (`resources/js/frontpage/logo.js`).
                      Cách đó làm logo nhảy vào sau khi trang đã vẽ, và nếu JS
                      không chạy thì không có logo. Ở đây data URI nằm ngay
                      trong HTML máy chủ trả về.
                    */}
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src={LOGO_DATA_URI} alt="OOHX" width={128} height={32} />
                </LogoLink>

                <nav className="hdr-nav">
                    <NavLink href="/explore" active={active === 'explore'}>
                        Khám phá
                    </NavLink>
                    <NavLink href="/map" active={active === 'map'}>
                        Bản đồ
                    </NavLink>
                    <NavLink href="/owners" active={active === 'owners'}>
                        Chủ sở hữu màn hình
                    </NavLink>
                    <NavLink href="/agency" active={active === 'agency'}>
                        Đại lý
                    </NavLink>
                </nav>

                <div className="hdr-acts">
                    <AuthState cartHref="/cart" loginHref="/login" registerHref="/register" />
                </div>
            </div>
        </header>
    );
}
