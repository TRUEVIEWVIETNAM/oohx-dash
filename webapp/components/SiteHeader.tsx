import Link from 'next/link';
import { AuthState } from './AuthState';
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
 * Giai đoạn 6 chuyển từng đường dẫn, nên `/map`, `/agency`, `/` vẫn do Laravel
 * phục vụ. `next/link` sẽ thử điều hướng phía client và không tìm thấy route —
 * người dùng thấy trang 404 của Next cho một trang có thật. Chỉ `/explore` và
 * `/owners` dùng `next/link`, vì chúng nằm trong app này.
 *
 * Khi chuyển thêm đường nào thì đổi `<a>` thành `<Link>` ở đúng dòng đó. Danh
 * sách `TRONG_APP` dưới đây là chỗ duy nhất phải sửa.
 */

/**
 * Những đường dẫn app này phục vụ.
 *
 * ══ Danh sách này PHẢI khớp các context đang mở trong nextjs.conf ══
 *
 * Không phải "nên", mà là phải, và lệch theo chiều nào cũng sai một kiểu:
 *
 * - Có ở đây mà proxy CHƯA mở: `next/link` điều hướng phía client và hiện
 *   trang của app Next, nhưng tải lại cùng URL đó thì OpenLiteSpeed đưa về
 *   Laravel và hiện trang Blade. **Hai trang khác nhau cho một URL**, tuỳ cách
 *   người dùng tới — và không có gì báo.
 * - Proxy đã mở mà thiếu ở đây: `<a>` gây tải lại cả trang cho một điều hướng
 *   nội bộ. Chậm hơn, nhưng không sai.
 *
 * Nên khi mở thêm một context trong `docs/deploy/nextjs-proxy/nextjs.conf`,
 * sửa luôn dòng này trong cùng commit.
 */
const TRONG_APP = new Set(['/explore', '/owners', '/map']);

function NavLink({ href, active, children }: {
    href: string;
    active: boolean;
    children: React.ReactNode;
}) {
    const className = active ? 'ac' : undefined;

    return TRONG_APP.has(href) ? (
        <Link href={href} className={className}>
            {children}
        </Link>
    ) : (
        <a href={href} className={className}>
            {children}
        </a>
    );
}

export type NavKey = 'home' | 'explore' | 'map' | 'owners' | 'agency' | '';

export function SiteHeader({ active = '' }: { active?: NavKey }) {
    return (
        <header className="hdr">
            <div className="hdr-in">
                <a href="/" className="hdr-logo">
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
                </a>

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
