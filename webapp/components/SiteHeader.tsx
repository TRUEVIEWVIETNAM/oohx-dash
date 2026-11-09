import { AuthState } from './AuthState';
import { MenuTruot } from './MenuTruot';
import { AppLink } from './AppLink';
import { LOGO_DATA_URI } from '@/lib/logo';
import { CONG_TY as CO, hotlineHienThi } from '@/lib/company';

/**
 * Chrome đầu trang: dải pháp lý + thanh đầu hai hàng.
 *
 * Dựng lại theo board `P-TrangChu` của mockup đã chốt (08/11/2026) — lát 2 của
 * `docs/PLAN-GHEP-MOCKUP.md`. Component này có mặt trên **cả 14 trang Next**,
 * nên đây là lát duy nhất đổi mọi trang cùng lúc.
 *
 * ══ Khung render ở máy chủ, trạng thái đăng nhập điền ở trình duyệt ══
 *
 * Phần không phụ thuộc người xem — logo, hai hàng điều hướng, nút hamburger —
 * render ở máy chủ, nên nó có trong HTML và công cụ tìm kiếm thấy được. Phần
 * phụ thuộc người xem nằm trong `<AuthState>`, một client component.
 *
 * Lý do không render trạng thái đăng nhập ở máy chủ: trang cache 60 giây, nên
 * bản cache sẽ phục vụ tên và email của người A cho người B. Chi tiết trong
 * `AuthState` và `Api\V2\MeController`.
 *
 * ══ `<a>` chứ không `next/link` cho đường Laravel phục vụ ══
 *
 * Giai đoạn 6 chuyển từng đường dẫn, nên `/cart` và khu `/my` vẫn do Laravel
 * phục vụ. `next/link` tới một đường như thế sẽ thử điều hướng phía client và
 * không tìm thấy route — người dùng thấy trang 404 của Next cho một trang có
 * thật.
 *
 * Không dòng nào ở đây phải sửa khi chuyển thêm một đường: mọi liên kết đi qua
 * `AppLink`, và danh sách `TRONG_APP` ở `lib/duong-dan.ts` là chỗ duy nhất
 * phải sửa — chỗ đó dùng chung với chân trang.
 *
 * ══ Bốn chỗ KHÔNG ghép như board vẽ ══
 *
 * | Board vẽ | Ở đây | Vì sao |
 * |---|---|---|
 * | `Media owner đã xác minh` | `Media owner` | `owners.verified` là **cờ thủ công**, không phải quy trình kiểm định, và cả 2 owner trên sàn đang `false`. Chốt `khoi-giao-dien` cấm chữ này từ 18/10/2026 |
 * | `Đang xem · Hồ Chí Minh` ở hàng một | **không có** | Sàn không có trạng thái tỉnh toàn cục. Một ô chọn tỉnh ở thanh đầu 14 trang mà không trang nào đọc nó là một ô điều khiển không làm gì — đúng lỗi audit F-15 vừa dọn khỏi trang công khai |
 * | Icon `Hộp thư` | **không có** | Không có route hộp thư cho người mua. Board tự trỏ nó về trang đăng nhập, tức chính nó cũng không có đích |
 * | Logo = ô icon `tv_signin` + chữ | logo thật | Ô icon là chỗ đỡ của mockup — canvas không có tệp logo. Xem chú thích `.ch-logo img` trong `oohx.css` |
 *
 * Hai icon tiện ích board vẽ mà **có** đích thật thì giữ: `Yêu cầu báo giá`
 * (`/explore/yeu-cau-bao-gia`) ở đây, và `Kế hoạch booking` trong `AuthState`
 * — cái sau có badge đọc `me.cart_count`, tức một con số thật.
 *
 * ══ Hàng hai ẩn dưới 768px, và không lối đi nào bị cắt ══
 *
 * Board mobile (`P-M-TrangChu`) chỉ có một hàng: logo + Đăng nhập +
 * hamburger. Nên hàng hai `display:none` dưới 768px — nhưng nó vẫn **nằm
 * trong HTML**, nên công cụ tìm kiếm không mất liên kết nào.
 *
 * Người dùng mobile cũng không mất: `Gói chiến dịch`, `Media owner`, `Đại lý`
 * và `Yêu cầu báo giá` có trong menu trượt; `Bản đồ vị trí` là cặp liên kết
 * "Lưới · Bản đồ" ngay trong thanh kết quả của `/explore` (hiện ở mọi bề
 * ngang, có chốt canh); `Quy chế hoạt động` và `Gửi phản ánh` ở chân trang;
 * `Đăng ký cung cấp vị trí` là `/register`, nằm ở chân menu trượt.
 */

/**
 * Logo, đi qua cùng phép kiểm `AppLink` như các mục nav.
 *
 * Viết cứng `<a href="/">` thì khi `/` được proxy sang Next, logo vẫn tải lại
 * cả trang cho một điều hướng nội bộ — và không ai nhớ sửa thêm một dòng ở
 * đây.
 */
function LogoLink() {
    return (
        <AppLink href="/" className="ch-logo">
            {/*
              Logo nhúng sẵn trong HTML, không gán bằng JS sau khi tải.

              Bản Blade để `<img src="">` rỗng rồi một script đặt `src` ở
              `DOMContentLoaded` (`resources/js/frontpage/logo.js`). Cách đó làm
              logo nhảy vào sau khi trang đã vẽ, và nếu JS không chạy thì không
              có logo. Ở đây data URI nằm ngay trong HTML máy chủ trả về.
            */}
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={LOGO_DATA_URI} alt="OOHX" width={128} height={32} />
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
        // `.v2` bọc chính chrome, không chờ `<main>` của trang.
        //
        // Đó là thứ làm lát này ghép được một mình: 13 trang chưa dựng lại vẫn
        // để thân trang cho `frontpage.css`, nhưng phần đầu và chân của chúng
        // đổi ngay — vì phạm vi `.v2` đi theo component, không theo trang.
        <div className="v2">
            {/*
              ① Dải pháp lý — TRÊN cả logo.

              Giá trị lấy từ `lib/company.ts`. Lát 1 viết cứng bốn chuỗi này
              trong `app/page.tsx`, và đó là đúng cái `company.ts` tồn tại để
              chặn: docblock của tệp đó nói rõ hai bản sẽ lệch khi công ty đổi
              địa chỉ hoặc số điện thoại. Lát này trả nó về một nguồn.

              Dòng thứ hai của board — *"số đăng ký với Bộ Công Thương [cần
              xác nhận]"* — KHÔNG ship. `company.ts` không có số đó, và in một
              ô vàng "[cần xác nhận]" ra trang công khai là in một chỗ trống
              của nội bộ cho người mua đọc.
            */}
            <div className="ch-phaply">
                <div className="wrap">
                    <span>
                        <b>{CO.legalName}</b>
                    </span>
                    <span>
                        MST <span className="num">{CO.businessCode}</span>
                    </span>
                    <span>{CO.address}</span>
                    <span>
                        Hotline <span className="num">{hotlineHienThi()}</span>
                    </span>
                </div>
            </div>

            <header className="ch-hdr">
                {/* ② Hàng một: logo · tiện ích · đăng nhập */}
                <div className="wrap ch-r1">
                    <LogoLink />

                    {/*
                      Yêu cầu báo giá — icon tiện ích DUY NHẤT của board còn
                      lại, vì nó là icon duy nhất có đích thật và không cần
                      đăng nhập. 85 trong 104 vị trí chưa niêm yết giá, nên đây
                      là lối mua chính của sàn, không phải một tiện ích phụ.
                    */}
                    <AppLink
                        href="/explore/yeu-cau-bao-gia"
                        className="ch-ico"
                        aria-label="Yêu cầu báo giá"
                    >
                        <span className="ms" aria-hidden="true">
                            request_quote
                        </span>
                    </AppLink>

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

                {/* ③ Hàng hai: điều hướng kho bên trái, pháp lý + lối owner bên phải */}
                <div className="ch-r2">
                    <div className="wrap">
                        {/*
                          "Tất cả loại hình" dẫn vào `/explore` chứ không mở một
                          menu phủ: sàn có đúng năm nhóm lọc được (ba loại hình,
                          hai loại điểm đặt), và năm mục thì không cần một lớp
                          menu thứ hai — chúng đã nằm trong khối menu ngang ở
                          trang chủ kèm số đếm thật.
                        */}
                        <AppLink href="/explore" className="ch-tatca">
                            <span className="ms" aria-hidden="true">
                                menu
                            </span>
                            Tất cả loại hình
                        </AppLink>

                        <NavLink href="/owners" active={active === 'owners'}>
                            Media owner
                        </NavLink>
                        <NavLink href="/products" active={active === 'products'}>
                            Gói chiến dịch
                        </NavLink>
                        {/*
                          KHÔNG có mục "Bản đồ vị trí", dù board vẽ nó ở đây.

                          Quyết định ngày 23/10/2026 — và nó có một chốt canh
                          riêng (`class-css.test.mjs`: 'gỡ mục Bản đồ khỏi menu
                          thì đường tới bản đồ phải còn trong trang'). Bản đồ
                          thôi là một khu vực từ hôm đó; nó là kiểu xem thứ hai
                          của `/explore`. Hai mục cho một trang nói sai hai lần:
                          "Bản đồ" trông như một khu vực khác nên người dùng mất
                          bộ lọc đang đặt mới dám bấm, và bấm qua bấm lại trông
                          như đi hai nơi.

                          Board là bản vẽ; quyết định kia có lý do đo được và
                          một chốt canh nó. Nên ở chỗ hai cái đá nhau, chốt
                          thắng.

                          Đường tới bản đồ KHÔNG mất: cặp liên kết "Lưới · Bản
                          đồ" nằm trong thanh kết quả của `/explore`, hiện ở mọi
                          bề ngang; và chân trang có mục `Bản đồ vị trí` — chân
                          trang là nơi người ta tìm một đường CỤ THỂ, còn thanh
                          đầu liệt kê KHU VỰC.
                        */}
                        <NavLink href="/agency" active={active === 'agency'}>
                            Đại lý
                        </NavLink>

                        <span className="ch-day" aria-hidden="true" />

                        <AppLink href="/quy-che-hoat-dong">Quy chế hoạt động</AppLink>
                        <AppLink href="/phan-anh-to-chuc-xa-hoi">Gửi phản ánh</AppLink>
                        {/*
                          Lối của media owner, tô màu chính theo board. Đích là
                          `/register` — sàn chỉ có MỘT trang đăng ký, và vai
                          chọn ở trong đó. Trỏ vào một trang "đăng ký bán"
                          riêng là trỏ vào một trang không tồn tại.
                        */}
                        <AppLink href="/register" className="ch-owner">
                            Đăng ký cung cấp vị trí
                        </AppLink>
                    </div>
                </div>
            </header>
        </div>
    );
}
