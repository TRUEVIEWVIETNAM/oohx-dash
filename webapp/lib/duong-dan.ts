/**
 * Những đường dẫn app Next này phục vụ.
 *
 * ══ Danh sách này PHẢI khớp các context đang mở trong nextjs.conf ══
 *
 * Không phải "nên", mà là phải, và lệch theo chiều nào cũng sai một kiểu:
 *
 * - Có ở đây mà proxy CHƯA mở: `next/link` điều hướng phía client và hiện
 *   trang của app Next, nhưng tải lại cùng URL đó thì OpenLiteSpeed đưa về
 *   Laravel và hiện trang Blade. **Hai trang khác nhau cho một URL**, tuỳ cách
 *   người dùng tới — và cả hai đều trả 200, nên không có gì báo.
 * - Proxy đã mở mà thiếu ở đây: `<a>` gây tải lại cả trang cho một điều hướng
 *   nội bộ. Chậm hơn, nhưng không sai.
 *
 * Chiều thứ nhất **đã xảy ra**: `/map` có trong danh sách này từ PR #11 trong
 * khi proxy chưa mở nó. Nên luật này giờ có phép kiểm đọc thẳng file conf —
 * `webapp/test/seo.mjs::kiemKhopConf()` — chứ không chỉ là một dòng ghi chú.
 *
 * ══ Vì sao file này không có JSX ══
 *
 * `seo.mjs` import `TRONG_APP` từ đây để so với `nextjs.conf`. Node bỏ được
 * phần kiểu của `.ts` nhưng **không** phân tích được JSX, nên component
 * `AppLink` nằm ở `components/AppLink.tsx`. Gộp hai thứ vào một file `.tsx`
 * thì phép kiểm không import được, và luật lại quay về làm ghi chú.
 */
export const TRONG_APP: Set<string> = new Set([
    // Trang chủ KHÔNG mở bằng `context /` — OpenLiteSpeed khớp context theo
    // tiền tố nên một `context /` nuốt `/api/v1`, `/cart`, `/sitemap.xml`.
    // Nó mở bằng một luật rewrite khớp đúng một đường dẫn
    // (`docs/deploy/nextjs-proxy/urlrewrite-nextjs.conf`), và `kiemKhopConf`
    // đọc cả file đó.
    //
    // `trongApp()` xử lý `/` riêng: nó KHÔNG tham gia phép so tiền tố, vì
    // mọi đường dẫn đều bắt đầu bằng `/`.
    '/',

    '/explore',
    '/owners',
    '/products',
    '/map',

    // Bốn trang chính sách. Giữ nguyên thứ tự và cách viết như trong
    // `config/policies.php` để đối chiếu bằng mắt được.
    '/quy-che-hoat-dong',
    '/chinh-sach-bao-mat',
    '/giai-quyet-tranh-chap',
    '/bang-phi',

    // Một mục phủ cả trang gửi lẫn '/danh-sach' — trongApp() khớp tiền tố,
    // giống cách OpenLiteSpeed khớp context.
    '/phan-anh-to-chuc-xa-hoi',
]);

/**
 * App này có phục vụ `href` không?
 *
 * Khớp theo TIỀN TỐ, giống cách OpenLiteSpeed khớp context — `/explore/abc`
 * thuộc `context /explore`, nên nó cũng phải dùng `<Link>`. So bằng `has()`
 * trần thì mọi liên kết tới trang chi tiết đều rơi về `<a>`, và đó là cả một
 * nhóm điều hướng nội bộ bị tải lại cả trang mà không ai thấy.
 *
 * `/` là trường hợp riêng: khớp tiền tố với `/` thì mọi đường dẫn đều khớp.
 * Nên nó phải có mặt NGUYÊN VĂN trong danh sách, không qua phép so tiền tố.
 */
export function trongApp(href: string): boolean {
    if (TRONG_APP.has(href)) return true;
    if (href === '/') return false;

    for (const goc of TRONG_APP) {
        if (goc !== '/' && href.startsWith(goc + '/')) return true;
    }

    return false;
}
