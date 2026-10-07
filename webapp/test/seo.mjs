import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { startStubApi } from './stub-api.mjs';
import { TRONG_APP } from '../lib/duong-dan.ts';

/**
 * Kiểm thẻ SEO trên HTML **đã render** của từng trang Next.
 *
 * ══ Vì sao đo HTML, không unit-test `buildMetadata()` ══
 *
 * Lỗi SEO thật duy nhất tôi đã mắc trong giai đoạn 6: `metadata.other` phát
 * `<meta name="og:type">` thay vì `property="og:type"`. Open Graph đòi
 * `property=`, nên crawler bỏ qua thẻ đó hoàn toàn — trang vẫn hiện, thẻ vẫn
 * nằm trong `<head>`, `next build` vẫn xanh.
 *
 * Một unit test gọi `buildMetadata()` rồi so object trả về **sẽ không bắt
 * được** lỗi đó: object đúng, chỗ sai nằm ở cách Next render nó. Nên phép kiểm
 * phải đọc HTML.
 *
 * ══ Vì sao lớp này cần tồn tại ══
 *
 * `tests/Feature/Frontpage/SeoBaselineTest.php` canh `/`, `/explore`,
 * `/owners`, `/products`, `/map` — và bốn trong năm đường đó giờ do Next phục
 * vụ trên production. Test đó vẫn xanh vì PHPUnit gọi vào Laravel, nên nó canh
 * những trang người dùng **không còn thấy**.
 *
 * Điều kiện hoàn thành giai đoạn 6 theo lộ trình là "SEO không tụt", và lớp
 * này là thứ biến câu đó thành phép kiểm chạy được trên bản đang chạy thật.
 *
 * Chạy:  node test/seo.mjs
 * CI gọi nó sau khi build với API giả.
 */

const PORT = Number(process.env.SEO_TEST_PORT ?? 4999);
const ORIGIN = 'https://oohx.net';

/**
 * Thẻ bắt buộc, chép từ `resources/views/frontpage/partials/seo-meta.blade.php`.
 *
 * Thiếu một thẻ ở đây là "tụt" theo đúng nghĩa lộ trình nói — và không có gì
 * báo cho tới khi ai đó mở công cụ kiểm SEO.
 */
const THE_BAT_BUOC = [
    ['canonical', /<link rel="canonical" href="([^"]+)"/],
    ['description', /<meta name="description" content="([^"]*)"/],
    ['og:title', /<meta property="og:title" content="([^"]*)"/],
    ['og:description', /<meta property="og:description" content="([^"]*)"/],
    ['og:url', /<meta property="og:url" content="([^"]+)"/],
    ['og:image', /<meta property="og:image" content="([^"]+)"/],
    ['og:site_name', /<meta property="og:site_name" content="([^"]+)"/],
    ['og:locale', /<meta property="og:locale" content="([^"]+)"/],
    // `property=`, KHÔNG `name=`. Đây là thẻ đã từng sai, nên biểu thức này
    // cố tình chặt.
    ['og:type', /<meta property="og:type" content="([^"]+)"/],
    ['twitter:card', /<meta name="twitter:card" content="([^"]+)"/],
    ['twitter:title', /<meta name="twitter:title" content="([^"]*)"/],
    ['twitter:description', /<meta name="twitter:description" content="([^"]*)"/],
    ['twitter:image', /<meta name="twitter:image" content="([^"]+)"/],
];

/**
 * Thông tin đơn vị đăng ký sàn với Bộ Công Thương, phải có trên MỌI trang.
 *
 * Chép từ `config/policies.php` — nguồn sự thật — và cố ý chép tay: nếu đọc
 * động từ `lib/company.ts` thì phép kiểm so một hằng số với chính nó và luôn
 * xanh, kể cả khi cả hai cùng sai.
 *
 * Lệch giữa hai nơi là một lỗi THẬT cần biết: `lib/company.ts` ghi rõ rằng
 * unit systemd chưa truyền biến sang, nên bản Next đang chạy giá trị mặc định
 * chép tay. Ngày nào hai bên lệch, ca này đỏ.
 */
const PHAP_NHAN = [
    ['tên pháp nhân', 'CÔNG TY TNHH TRUEVIEW'],
    ['mã số doanh nghiệp', '0109944503'],
    ['nơi cấp', 'Sở Tài Chính thành phố Hà Nội'],
    ['ngày cấp', '24/3/2022'],
    ['người đại diện', 'NGUYỄN ANH TUẤN'],
    ['hotline', '0943668996'],
    ['email', 'tuan.nguyen@attvietnam.vn'],
];

const TRANG = [
    { path: '/', ogType: 'website', jsonLd: 'WebSite' },
    { path: '/explore', ogType: 'website' },
    { path: '/explore/man-hinh-co-gia', ogType: 'product', jsonLd: 'Product', coGia: true },
    { path: '/explore/man-hinh-khong-gia', ogType: 'product', jsonLd: 'Product', coGia: false },
    { path: '/owners', ogType: 'website' },
    { path: '/owners/owner-mot', ogType: 'website', jsonLd: 'Organization' },
    { path: '/products', ogType: 'website' },
    { path: '/products/goi-mot', ogType: 'product', jsonLd: 'Product', coGia: true },
    { path: '/map', ogType: 'website' },

    // Chính sách. `og:type` là `article`, không `website`: đây là một văn bản
    // có phiên bản và ngày hiệu lực, không phải một trang của site.
    //
    // Hai slug này là của API giả, không phải slug thật — `generateStaticParams()`
    // đọc danh sách từ API, nên test chứng minh được đúng điều cần: trang dựng
    // ra từ dữ liệu API, không từ một danh sách viết cứng trong `webapp/`.
    { path: '/da-ban-hanh', ogType: 'article', banNhap: false },
    { path: '/ban-nhap', ogType: 'article', banNhap: true },

    // Hai trang phản ánh. Trang gửi là Server Component bọc một biểu mẫu
    // client — nếu ai lỡ đánh dấu cả trang là client thì thẻ SEO biến mất
    // khỏi HTML máy chủ phát ra, và phép kiểm này đỏ.
    { path: '/phan-anh-to-chuc-xa-hoi', ogType: 'website', bieuMau: true },
    { path: '/phan-anh-to-chuc-xa-hoi/danh-sach', ogType: 'website', danhSachPhanAnh: true },

    // Trang xác thực: noindex, nhưng vẫn phải đủ thẻ chia sẻ.
    { path: '/login', ogType: 'website', noindex: true },
    { path: '/register', ogType: 'website', noindex: true },

    // Trang công khai cuối rời Blade. Nó mang bốn khiếm khuyết F-15 mà bản
    // Next cố ý không chép sang, nên có phép kiểm riêng ở dưới.
    { path: '/agency', ogType: 'website', agency: true },
];

const loi = [];

function bao(trang, thong_diep) {
    loi.push(`${trang}: ${thong_diep}`);
}

async function kiemTrang(base, trang) {
    const response = await fetch(base + trang.path);

    if (response.status !== 200) {
        bao(trang.path, `trả ${response.status}, cần 200`);

        return;
    }

    const html = await response.text();

    // ── Thẻ bắt buộc ──
    for (const [ten, re] of THE_BAT_BUOC) {
        const m = html.match(re);

        if (!m) {
            bao(trang.path, `thiếu thẻ ${ten}`);
            continue;
        }

        // Mô tả rỗng là lỗi riêng, không phải thiếu thẻ: bản Blade có
        // `test_mo_ta_khong_bao_gio_rong` canh đúng chuyện này.
        if (ten === 'description' && m[1].trim() === '') {
            bao(trang.path, 'description rỗng');
        }
    }

    // ── Canonical phải trỏ đúng chính nó, trên tên miền công khai ──
    //
    // Đây là chỗ một biến môi trường sai làm cả site phát canonical trỏ vào
    // `http://127.0.0.1/...` — một URL không ai ngoài máy chủ mở được, và công
    // cụ tìm kiếm coi đó là địa chỉ chuẩn của trang. Trang vẫn hiện bình
    // thường, nên không ai thấy.
    const canonical = html.match(THE_BAT_BUOC[0][1])?.[1];

    // Trang chủ: `https://oohx.net` và `https://oohx.net/` đều hợp lệ và chỉ
    // cùng một tài nguyên. Nhận cả hai, có lý do đo được: bản Blade trên
    // production phát `https://oohx.net` **không** có gạch chéo cuối
    // (`url()->current()` của Laravel bỏ nó), và Next cũng vậy — nên hai bản
    // khớp nhau.
    //
    // Lần đầu tôi viết phép kiểm này đòi đúng `ORIGIN + path`, tức
    // `https://oohx.net/`, và nó đỏ. Code không sai; phép kiểm sai. Ghi lại vì
    // một test đòi sai giá trị thì tệ hơn không có test: nó đẩy người sửa đi
    // đổi code đang đúng.
    const mongDoi =
        trang.path === '/' ? [ORIGIN, ORIGIN + '/'] : [ORIGIN + trang.path];

    if (canonical && !mongDoi.includes(canonical)) {
        bao(trang.path, `canonical là "${canonical}", cần một trong ${mongDoi.join(' hoặc ')}`);
    }

    // ── og:type đúng giá trị ──
    const ogType = html.match(/<meta property="og:type" content="([^"]+)"/)?.[1];

    if (ogType && ogType !== trang.ogType) {
        bao(trang.path, `og:type là "${ogType}", cần "${trang.ogType}"`);
    }

    // `name="og:type"` là lỗi đã từng xảy ra. Canh riêng, vì thẻ đúng có thể
    // tồn tại song song với thẻ sai và phép kiểm trên vẫn qua.
    if (/<meta name="og:type"/.test(html)) {
        bao(trang.path, 'có <meta name="og:type"> — Open Graph đòi property=');
    }

    // ── JSON-LD ──
    if (trang.jsonLd) {
        const khoi = html.match(
            /<script type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/,
        )?.[1];

        if (!khoi) {
            bao(trang.path, 'thiếu khối JSON-LD');
        } else {
            let data;

            try {
                data = JSON.parse(khoi);
            } catch (e) {
                bao(trang.path, `JSON-LD không phân tích được: ${e.message}`);
            }

            if (data) {
                if (data['@context'] !== 'https://schema.org') {
                    bao(trang.path, `JSON-LD @context là "${data['@context']}"`);
                }

                if (data['@type'] !== trang.jsonLd) {
                    bao(trang.path, `JSON-LD @type là "${data['@type']}", cần "${trang.jsonLd}"`);
                }

                // `offers` CHỈ khi có giá.
                //
                // Bản Blade viết `'price' => ... ?? 0`, nên màn hình chưa niêm
                // yết giá phát ra `{"price":0}` — với schema.org nghĩa là MIỄN
                // PHÍ. Cùng loại lỗi audit F-15: một con số không có nguồn,
                // nói sai, và không ai thấy vì JSON-LD không hiện trên trang.
                if (trang.coGia === true && !data.offers) {
                    bao(trang.path, 'có giá mà JSON-LD không khai offers');
                }

                if (trang.coGia === false && data.offers) {
                    bao(
                        trang.path,
                        `không có giá mà JSON-LD vẫn khai offers: ${JSON.stringify(data.offers)}`,
                    );
                }

                if (data.offers && !(data.offers.price > 0)) {
                    bao(trang.path, `offers.price là ${data.offers.price} — 0 nghĩa là miễn phí`);
                }
            }
        }
    }

    // ── Trang chính sách ──
    if (trang.banNhap !== undefined) {
        // `body_html` phải được hiển thị NHƯ HTML. Nếu React escape nó thì
        // trang vẫn 200 và vẫn có đủ thẻ SEO — chỉ là người đọc thấy
        // `<h2>1. Phạm vi</h2>` dưới dạng chữ. Một lỗi không ai báo, vì không
        // có gì hỏng.
        if (!/<article class="pol-body"><h2>/.test(html)) {
            bao(trang.path, 'phần thân không được hiển thị như HTML (body_html bị escape?)');
        }

        if (html.includes('&lt;h2&gt;')) {
            bao(trang.path, 'thấy &lt;h2&gt; trong HTML — body_html bị escape');
        }

        // Ô cảnh báo bản nháp: có đúng khi chưa ban hành, và KHÔNG có khi đã
        // ban hành. Chỉ canh một chiều thì một trang luôn hiện cảnh báo vẫn
        // qua được — và nó nói sai về một văn bản đã có hiệu lực.
        const coCanhBao = html.includes('class="pol-draft"');

        if (trang.banNhap && !coCanhBao) {
            bao(trang.path, 'chưa ban hành mà không có ô cảnh báo bản nháp');
        }

        if (!trang.banNhap && coCanhBao) {
            bao(trang.path, 'đã ban hành mà vẫn hiện ô cảnh báo bản nháp');
        }

        // Khối phiên bản. Người đọc cần biết họ đang xem bản nào — đó là lý do
        // `version` tồn tại, và nó được đóng dấu vào từng bản ghi đồng ý.
        if (!html.includes('class="pol-meta"')) {
            bao(trang.path, 'thiếu khối phiên bản');
        }
    }

    // ── Trang gửi phản ánh ──
    if (trang.bieuMau) {
        // Bẫy mật phải CÓ trong DOM. Thiếu nó thì bộ luật dùng chung với
        // Blade (`website => prohibited`) vẫn chạy, nhưng bot không có gì để
        // điền nên bẫy không bắt được ai — hỏng im lặng.
        if (!/name="website"/.test(html)) {
            bao(trang.path, 'thiếu trường bẫy mật website');
        }

        if (!html.includes('class="pol-hp"')) {
            bao(trang.path, 'bẫy mật không nằm trong .pol-hp — người thật sẽ thấy nó');
        }

        // Phần đọc được phải có trong HTML máy chủ phát ra, không chờ JS.
        for (const can of ['class="pol-h1"', 'class="pol-lead"', 'name="contact_email"', 'name="content"']) {
            if (!html.includes(can)) {
                bao(trang.path, `thiếu ${can} trong HTML máy chủ phát ra`);
            }
        }
    }

    // ── Danh sách phản ánh ──
    if (trang.danhSachPhanAnh) {
        // Nhãn trạng thái phải là chữ, không phải mã thô. Hiện `in_review`
        // ra cho người đọc là dấu hiệu bên tiêu thụ tự tra bảng và tra thiếu.
        for (const nhan of ['Đã xử lý', 'Đang xem xét']) {
            if (!html.includes(nhan)) {
                bao(trang.path, `thiếu nhãn trạng thái "${nhan}"`);
            }
        }

        if (/>in_review</.test(html) || />resolved</.test(html)) {
            bao(trang.path, 'hiện mã trạng thái thô thay vì nhãn');
        }

        // Khối kết quả xử lý: CÓ ở bản đã xử lý, KHÔNG ở bản đang xem xét.
        // Chỉ canh một chiều thì một trang luôn hiện khối đó vẫn qua được.
        const soKhoiKetQua = (html.match(/class="rfl-res"/g) || []).length;

        if (soKhoiKetQua !== 1) {
            bao(trang.path, `có ${soKhoiKetQua} khối kết quả xử lý, cần đúng 1`);
        }

        // Ngày theo giờ Việt Nam. Mốc 01/10 03:00 UTC là 10:00 ngày 01/10 ở
        // Việt Nam — nếu máy chủ chạy UTC mà không ghim múi giờ thì vẫn ra
        // 01/10, nên mốc thứ hai (03/10 09:30 UTC) mới là mốc phân biệt.
        if (!html.includes('03/10/2026')) {
            bao(trang.path, 'ngày xử lý không ra 03/10/2026 — kiểm múi giờ');
        }
    }

    // ── Trang noindex ──
    if (trang.noindex) {
        // Trang xác thực không có gì cho công cụ tìm kiếm. Nhưng thẻ mô tả và
        // Open Graph vẫn phải đủ — kiểm ở khối THE_BAT_BUOC phía trên — vì
        // khi ai đó dán liên kết vào tin nhắn, thẻ xem trước vẫn phải đúng.
        if (!/<meta name="robots" content="[^"]*noindex/.test(html)) {
            bao(trang.path, 'thiếu noindex — trang xác thực không nên nằm trong chỉ mục');
        }

        // Liên kết chết: bản Blade có <a href="#">Quên mật khẩu?</a>, cùng
        // loại khiếm khuyết F-15 đã dọn khỏi chân trang. Không chép sang.
        if (/href="#"/.test(html)) {
            bao(trang.path, 'có liên kết href="#" không đi đâu');
        }
    }

    // ── Trang agency: bốn khiếm khuyết F-15 KHÔNG được chép sang ──
    if (trang.agency) {
        // 1. Thẻ agency không phải liên kết chết. Bản Blade dùng
        //    `<a href="#">` vì chưa có trang chi tiết agency.
        if (/<a[^>]+href="#"/.test(html)) {
            bao(trang.path, 'có thẻ <a href="#"> — liên kết không đi đâu');
        }

        // 2. Nút giả: <span> mang class nút. Bản Blade có hai cái ở chân thẻ
        //    agency — "Xem chi tiết" và "Liên hệ" — trông bấm được mà không
        //    có hành vi.
        //
        //    Kiểm DẤU HIỆU, không kiểm chữ: bản đầu tôi tìm chuỗi "Liên hệ"
        //    và nó đỏ vì chân trang có một liên kết THẬT mang đúng chữ đó.
        //    Một phép kiểm đỏ vì lý do sai thì đẩy người sửa đi gỡ thứ đang
        //    đúng.
        if (/<span[^>]+class="[^"]*btn/.test(html)) {
            bao(trang.path, 'có <span> mang class nút — nút giả, trông bấm được mà không có hành vi');
        }

        if (html.includes('oc-card-foot')) {
            bao(trang.path, 'còn khối oc-card-foot — chân thẻ chứa hai nút giả của bản Blade');
        }

        // 3. Ô "Rating" luôn rỗng — một ô số không có dữ liệu phía sau.
        if (html.includes('Rating')) {
            bao(trang.path, 'có ô Rating — nó không có dữ liệu phía sau');
        }

        // 4. Ô tìm kiếm phải NẰM TRONG form. Bản Blade có `name="q"` mà
        //    không có form bọc, nên gõ xong bấm Enter thì không gì xảy ra.
        if (!/<form[^>]*>[\s\S]*?name="q"[\s\S]*?<\/form>/.test(html)) {
            bao(trang.path, 'ô tìm kiếm q không nằm trong <form> — gõ xong không gửi được');
        }

        // Và placehold.co: ảnh bịa, lại gửi tên agency sang bên thứ ba.
        if (html.includes('placehold.co')) {
            bao(trang.path, 'dùng placehold.co — ảnh bịa, và nó gửi tên agency ra ngoài');
        }
    }

    // ── Khung trang: thiếu một trong hai là thiếu thông tin bắt buộc ──
    if (!html.includes('class="hdr"')) {
        bao(trang.path, 'thiếu thanh điều hướng');
    }

    if (!html.includes('class="ft-legal"')) {
        bao(trang.path, 'thiếu khối thông tin pháp lý ở chân trang');
    }

    // ── Và khối đó phải có ĐỦ NỘI DUNG, không chỉ có mặt ──
    //
    // Phép kiểm trên chỉ đòi cái thẻ tồn tại. Một `<div class="ft-legal">`
    // rỗng cũng qua được — và đó đúng là cách thông tin bắt buộc biến mất mà
    // không ai thấy.
    //
    // Bảy mục dưới đây là thông tin đơn vị đăng ký sàn với Bộ Công Thương.
    // `PolicyPagesTest` từng canh chúng trên chân trang Blade; giai đoạn 7 gỡ
    // trang Blade cuối cùng (07/10/2026) nên phép kiểm chuyển về đây, đo trên
    // HTML bản Next thật sự phát ra.
    for (const [ten, can] of PHAP_NHAN) {
        if (!html.includes(can)) {
            bao(trang.path, `chân trang thiếu ${ten}: "${can}"`);
        }
    }

    // Pop-up thử nghiệm: website chưa hoàn tất đăng ký với Bộ Công Thương thì
    // phải nói ra. Mặc định `OOHX_TRIAL_MODE` là bật, giống Laravel.
    if (!html.includes('chế độ thử nghiệm')) {
        bao(trang.path, 'thiếu thông báo chế độ thử nghiệm');
    }

    // ── lang="vi" ──
    if (!/<html[^>]*lang="vi"/.test(html)) {
        bao(trang.path, 'thiếu lang="vi"');
    }
}

/** Slug lạ phải ra 404 thật, không phải 200 rỗng. */
async function kiem404(base) {
    for (const path of [
        '/explore/khong-ton-tai',
        '/owners/khong-ton-tai',
        '/products/khong-ton-tai',

        // `[slug]` ở gốc app nuốt mọi đường dẫn một cấp. `dynamicParams = false`
        // là thứ chặn nó, và nếu ai bỏ dòng đó thì `/khong-ton-tai` ra 200 với
        // một trang chính sách rỗng — URL đó rồi nằm trong chỉ mục mãi.
        '/khong-ton-tai',
    ]) {
        const response = await fetch(base + path);

        if (response.status !== 404) {
            bao(path, `trả ${response.status}, cần 404 — một trang 200 rỗng để URL đó nằm trong chỉ mục mãi`);
        }
    }
}

/** Dữ liệu riêng của owner không được lộ ra trang công khai. */
async function kiemKhongLo(base) {
    const html = await (await fetch(base + '/owners/owner-mot')).text();

    for (const camKy of ['khong-duoc-lo@example.com', '0900000001', 'contact']) {
        if (html.includes(camKy)) {
            bao('/owners/owner-mot', `để lộ "${camKy}"`);
        }
    }
}

/**
 * `TRONG_APP` phải khớp các context đang mở trong `nextjs.conf`.
 *
 * ══ Vì sao đây là phép kiểm, không phải một dòng ghi chú ══
 *
 * `lib/duong-dan.ts` nói rõ luật này, và luật đó **đã bị vi phạm** một lần:
 * `/map` có trong `TRONG_APP` từ PR #11 trong khi proxy chưa mở nó. Hệ quả là
 * một URL cho hai trang — bấm từ thanh điều hướng thì ra trang Next, tải lại
 * cùng URL thì ra trang Blade. Không có gì báo, vì cả hai đều trả 200.
 *
 * Một ghi chú trong docblock không chặn được chuyện đó. Phép kiểm này đọc
 * thẳng file conf, nên hai bên không thể lệch mà CI vẫn xanh.
 *
 * `/_next` bị bỏ ra: nó là context phát asset của chính Next.js, không phải
 * một đường người dùng điều hướng tới, nên nó không thuộc `TRONG_APP`.
 */
function kiemKhopConf() {
    const conf = readFileSync(
        new URL('../../docs/deploy/nextjs-proxy/nextjs.conf', import.meta.url),
        'utf8',
    );

    // Hai nguồn, vì có hai cách mở một đường dẫn và chúng không thay thế nhau:
    //
    //   `context /x`  — khớp TIỀN TỐ. Dùng cho một nhóm đường (`/explore` và
    //                   mọi `/explore/...`).
    //   RewriteRule   — khớp ĐÚNG một đường. Dùng cho trang chủ, thứ không mở
    //                   được bằng context vì `context /` nuốt cả site.
    //
    // Bỏ sót nguồn thứ hai thì thêm `/` vào TRONG_APP sẽ làm test đỏ dù cấu
    // hình hoàn toàn đúng — và người sửa sẽ đi gỡ `/` ra, tức sửa đúng thành
    // sai.
    const rewrite = readFileSync(
        new URL('../../docs/deploy/nextjs-proxy/urlrewrite-nextjs.conf', import.meta.url),
        'utf8',
    );

    const trongConf = new Set([
        ...conf
            .split('\n')
            .filter((l) => /^context\s/.test(l))
            .map((l) => l.trim().split(/\s+/)[1]),

        // `^/?$` → `/`, `^/gioi-thieu$` → `/gioi-thieu`.
        ...rewrite
            .split('\n')
            .filter((l) => /^\s*RewriteRule\s/.test(l))
            .map((l) => l.trim().split(/\s+/)[1].replace(/^\^/, '').replace(/\$$/, ''))
            .map((p) => (p === '' || p === '/?' ? '/' : p)),
    ]);

    // `/_next` là context asset của chính Next.js, không phải đường người dùng
    // điều hướng tới — nó không thuộc TRONG_APP.
    trongConf.delete('/_next');

    for (const duong of TRONG_APP) {
        if (!trongConf.has(duong)) {
            bao(
                'TRONG_APP',
                `"${duong}" có trong TRONG_APP mà KHÔNG có context hay luật rewrite nào mở nó — ` +
                    'next/link sẽ hiện trang Next còn tải lại cùng URL ra trang Blade',
            );
        }
    }

    for (const duong of trongConf) {
        if (!TRONG_APP.has(duong)) {
            bao(
                'nextjs.conf',
                `"${duong}" đang được mở mà thiếu trong TRONG_APP — ` +
                    'điều hướng nội bộ tải lại cả trang, chậm hơn mức cần',
            );
        }
    }
}

async function chay() {
    const { server, port: apiPort } = await startStubApi();
    const apiBase = `http://127.0.0.1:${apiPort}/api/v2`;

    const next = spawn(
        process.execPath,
        ['node_modules/next/dist/bin/next', 'start', '--port', String(PORT), '--hostname', '127.0.0.1'],
        {
            env: { ...process.env, OOHX_API_BASE: apiBase, OOHX_PUBLIC_ORIGIN: ORIGIN },
            stdio: ['ignore', 'pipe', 'pipe'],
        },
    );

    let log = '';
    next.stdout.on('data', (d) => (log += d));
    next.stderr.on('data', (d) => (log += d));

    const base = `http://127.0.0.1:${PORT}`;

    // Chờ sẵn sàng bằng cách thử gọi, không bằng một `sleep` cố định: máy chậm
    // thì sleep ngắn làm test đỏ vì lý do không liên quan, còn sleep dài làm
    // mọi lần chạy đều chậm.
    let san_sang = false;

    for (let i = 0; i < 60; i++) {
        try {
            await fetch(base + '/explore');
            san_sang = true;
            break;
        } catch {
            await new Promise((r) => setTimeout(r, 500));
        }
    }

    if (!san_sang) {
        console.error('Next không khởi động được trong 30 giây. Log:\n' + log);
        next.kill();
        server.close();
        process.exit(1);
    }

    try {
        // Phép kiểm này không cần máy chủ Next, nhưng để trong `try` để nó
        // cùng chịu `finally` dọn tiến trình.
        kiemKhopConf();

        for (const trang of TRANG) {
            await kiemTrang(base, trang);
        }

        await kiem404(base);
        await kiemKhongLo(base);
    } finally {
        next.kill();
        server.close();
    }

    if (loi.length === 0) {
        console.log(
            `SEO: ${TRANG.length} trang, tất cả thẻ bắt buộc có mặt và đúng giá trị. ` +
                `TRONG_APP khớp ${TRONG_APP.size} đường đang mở (context + luật rewrite).`,
        );
        process.exit(0);
    }

    console.error(`SEO: ${loi.length} lỗi\n`);
    for (const l of loi) console.error('  ' + l);
    process.exit(1);
}

chay().catch((e) => {
    console.error(e);
    process.exit(1);
});
