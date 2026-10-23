import { AuthState } from './AuthState';
import { MenuTruot } from './MenuTruot';
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

/**
 * Không còn `'map'`.
 *
 * Nó đã là một khoá chết từ lát 2 (22/10/2026): `/explore?view=map` render
 * `<SiteHeader active="explore">`, nên **không trang nào** từng truyền
 * `'map'` vào. Mục "Bản đồ" ở thanh đầu chưa bao giờ sáng lên — kể cả khi
 * người dùng đang đứng trong kiểu xem bản đồ, mục sáng là "Khám phá".
 *
 * Tức giao diện đã coi đây là MỘT khu vực từ hôm đó; chỉ có danh sách mục là
 * còn hai. Gộp menu chỉ là nói ra điều đã đúng.
 */
export type NavKey = 'home' | 'explore' | 'products' | 'owners' | 'agency' | '';

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
                    {/*
                      MỘT mục cho cả lưới lẫn bản đồ (23/10/2026).
                      ──────────────────────────────────────────────────────
                      Tới lát 2 (22/10) bản đồ thôi là một trang riêng và
                      thành kiểu xem thứ hai của chính trang này. Thanh đầu
                      vẫn giữ hai mục thêm một ngày, và hai mục cho một trang
                      nói sai hai lần:

                       · "Bản đồ" trông như một khu vực khác, nên người dùng
                         mất bộ lọc đang đặt mới dám bấm — thật ra không mất
                         gì, `?view=map` giữ nguyên mọi tham số;
                       · bấm "Bản đồ" rồi bấm "Khám phá" trông như đi hai nơi,
                         trong khi đó là một nút bật tắt của cùng một trang.

                      Đường tới bản đồ KHÔNG mất: nó là cặp liên kết
                      "Lưới · Bản đồ" trong thanh kết quả (`.ex-doi-xem` ở
                      `KhoiExplore`), hiện ở mọi bề ngang kể cả 360px, và
                      nằm cạnh kết quả thay vì cách đó một thanh điều hướng.

                      Đánh đổi, nói thẳng: từ một trang KHÁC (ví dụ
                      `/owners`) tới bản đồ nay mất hai cú bấm thay vì một.
                      Đổi lại không còn ai hiểu nhầm rằng bấm sang bản đồ là
                      mất chỗ đang đứng.
                    */}
                    <NavLink href="/explore" active={active === 'explore'}>
                        Khám phá
                    </NavLink>
                    {/*
                      `/products` chưa từng có trong thanh đầu: trang gói chỉ
                      tới được từ đúng một liên kết trên trang chủ, nên phần
                      lớn người xem không biết sàn bán theo gói.
                    */}
                    <NavLink href="/products" active={active === 'products'}>
                        Gói chiến dịch
                    </NavLink>
                    {/*
                      "Chủ sở hữu màn hình" mơ hồ: người đọc không biết đó là
                      danh sách nhà cung cấp để mình xem, hay mục dành cho
                      mình nếu mình có màn hình. "Media owner" là từ cả hai
                      bên trong ngành đang dùng, và trang đích là danh sách
                      đối tác.
                    */}
                    <NavLink href="/owners" active={active === 'owners'}>
                        Media owner
                    </NavLink>
                    <NavLink href="/agency" active={active === 'agency'}>
                        Đại lý
                    </NavLink>
                </nav>

                <div className="hdr-acts">
                    <AuthState cartHref="/cart" loginHref="/login" registerHref="/register" />

                    {/*
                      Hamburger + menu trượt — điều hướng cho mọi bề ngang
                      dưới 1024px. Trước 18/10/2026 không có gì ở đây, nên
                      dưới 768px thanh đầu chỉ còn một cái logo. Xem
                      docblock của `MenuTruot`.
                    */}
                    <MenuTruot active={active} />
                </div>
            </div>
        </header>
    );
}
