import { createServer } from 'node:http';

/**
 * API v2 giả, chỉ để test SEO chạy được mà không cần production.
 *
 * ══ Vì sao cần nó ══
 *
 * Trước file này, `npm run build` trong CI gọi vào **API production** (mặc
 * định của `OOHX_API_BASE`). Hai hệ quả:
 *
 * - CI phụ thuộc production. Production chậm hoặc đang deploy thì build lấy
 *   dữ liệu khác nhau mỗi lần, và trang chủ prerender tĩnh nên dữ liệu đó bị
 *   nướng vào HTML.
 * - Không test được trường hợp dữ liệu cụ thể. Muốn kiểm "màn hình không có
 *   giá thì JSON-LD không khai `offers`" thì phải có một màn hình như vậy
 *   trong kho thật.
 *
 * Nên test dựng dữ liệu của chính nó. Fixture ở đây cố tình chứa cả hai
 * trường hợp đối nghịch: một màn hình CÓ giá và một màn hình KHÔNG có giá.
 *
 * ══ Fixture không phải dữ liệu đẹp ══
 *
 * Tên có dấu tiếng Việt và dấu `<` để test bắt được hai lỗi riêng: JSON escape
 * ký tự ngoài ASCII (nên `assertStringContainsString` với tiếng Việt luôn
 * trượt), và thoát HTML trong JSON-LD.
 */

const SCREEN_CO_GIA = {
    slug: 'man-hinh-co-gia',
    name: 'Màn Hình Có Giá <test>',
    screen_type: 'billboard',
    owner: { slug: 'owner-mot', name: 'Công Ty Một' },
    location: { site: 'Toà Nhà A', city: 'Hà Nội', district: 'Ba Đình' },
    network: { code: 'NET1', name: 'Mạng Một' },
    size: { width_m: 4, height_m: 2 },
    pricing: {
        model: 'io',
        floor_cpm: { amount: null, currency: 'VND' },
        io_rate: { amount: 1000000, currency: 'VND', unit: 'month' },
    },
    photo_url: 'https://oohx.net/storage/screens/a.jpg',
};

const SCREEN_KHONG_GIA = {
    ...SCREEN_CO_GIA,
    slug: 'man-hinh-khong-gia',
    name: 'Màn Hình Không Giá',
    pricing: {
        model: 'io',
        floor_cpm: { amount: null, currency: 'VND' },
        io_rate: { amount: null, currency: 'VND', unit: null },
    },
};

const AVAILABILITY = { window_days: 30, remaining_sov_pct: 60, has_capacity: true };

const OWNER = {
    slug: 'owner-mot',
    name: 'Công Ty Một',
    type: 'agency',
    cover_url: 'https://oohx.net/storage/owners/a.jpg',
    screen_count: 2,
};

const OWNER_DETAIL = {
    ...OWNER,
    tagline: 'Khẩu hiệu của Công Ty Một',
    about: 'Giới thiệu dài về Công Ty Một.',
    logo_url: 'https://oohx.net/storage/owners/logo.jpg',
    contact: { email: 'khong-duoc-lo@example.com', phone: '0900000001' },
    location: { province: 'Hà Nội', commune: 'Ba Đình' },
    founded: 2015,
    stats: { screens: 2, cities: 1 },
};

const PRODUCT = {
    slug: 'goi-mot',
    name: 'Gói Một',
    type: 'bundle',
    category: { code: 'retail', label: 'Bán lẻ' },
    listing_mode: 'package',
    owner: { slug: 'owner-mot', name: 'Công Ty Một' },
    location: { city: 'Hà Nội', region: 'north', site: null },
    network: { code: 'NET1', name: 'Mạng Một' },
    quantity: { total_units: 10, min: 1, max: 10, screen_count: 2 },
    pricing: { currency: 'VND', unit: 'month', package: 5000000, per_screen: null, package_discount_pct: 10 },
    short_description: 'Mô tả ngắn của Gói Một.',
    cover_url: 'https://oohx.net/storage/products/a.jpg',
    featured: true,
};

/**
 * Hai trang chính sách, cố tình đối nghịch: một ĐÃ ban hành, một CÒN NHÁP.
 *
 * Ô cảnh báo bản nháp là thứ dễ mất im lặng nhất của nhóm trang này — mất nó
 * thì trang vẫn hiện bình thường, chỉ là nó trình bày một bản nháp như văn bản
 * đã có hiệu lực. Nên phép kiểm phải có cả hai trường hợp, không chỉ một.
 *
 * `body_html` chứa dấu `<` trong chữ và một thực thể HTML: nếu trang escape
 * chuỗi này thay vì hiển thị như HTML thì `<h2>` sẽ ra chữ, và phép kiểm thấy
 * `&lt;h2&gt;` trong HTML đã render.
 */
const CHINH_SACH_DA_BAN_HANH = {
    slug: 'da-ban-hanh',
    title: 'Bảng phí dịch vụ',
    version: '1.0',
    effective_from: '22/09/2026',
    is_effective: true,
    url: 'https://oohx.net/da-ban-hanh',
    body_html: '<h2>1. Mức phí</h2>\n<p>Phí 6.668.000 đồng/năm, chưa gồm VAT.</p>',
};

const CHINH_SACH_BAN_NHAP = {
    slug: 'ban-nhap',
    title: 'Quy chế hoạt động',
    version: '0.1-draft',
    effective_from: null,
    is_effective: false,
    url: 'https://oohx.net/ban-nhap',
    body_html: '<h2>1. Phạm vi</h2>\n<p>Áp dụng cho mọi bên &amp; mọi giao dịch &lt; 1 tỷ.</p>',
};

const PAGE_META = { page: 1, per_page: 24, total: 2, last_page: 1, max_per_page: 50 };
const LIMIT_META = { returned: 1, limit: 4, max_limit: 12 };

const ROUTES = {
    '/api/v2/stats': { data: { total_screens: 104, total_cities: 17, total_owners: 2 } },

    '/api/v2/screens': { data: [SCREEN_CO_GIA, SCREEN_KHONG_GIA], meta: PAGE_META },

    '/api/v2/screens/featured': {
        data: [{ ...SCREEN_CO_GIA, availability: AVAILABILITY }],
        meta: LIMIT_META,
    },

    '/api/v2/screens/pins': { data: [], meta: { ...LIMIT_META, city: 'hanoi' } },

    '/api/v2/owners': { data: [OWNER], meta: PAGE_META },
    '/api/v2/owners/featured': { data: [OWNER], meta: LIMIT_META },

    '/api/v2/products': { data: [PRODUCT], meta: PAGE_META },

    '/api/v2/locations': {
        data: [{ code: 'north', name: 'Miền Bắc', provinces: [{ code: 'hanoi', name: 'Hà Nội', count: 104 }] }],
    },

    // Danh sách chỉ siêu dữ liệu — `generateStaticParams()` của trang chính
    // sách đọc đúng endpoint này để biết có những slug nào.
    '/api/v2/policies': {
        data: [CHINH_SACH_DA_BAN_HANH, CHINH_SACH_BAN_NHAP].map(({ body_html, ...meta }) => meta),
    },

    // Hai bản ghi đối nghịch: một ĐÃ xử lý (có resolution + resolved_at), một
    // còn đang xem xét. Khối `rfl-res` chỉ hiện ở bản đầu, nên phép kiểm có
    // cả hai chiều.
    //
    // KHÔNG có contact_email / internal_notes / submitted_ip ở đây, giống
    // endpoint thật — nên nếu trang vô tình in cả đối tượng ra thì cũng không
    // lộ được gì. Phép kiểm lộ dữ liệu nằm ở phía PHP.
    '/api/v2/reflections': {
        data: [
            {
                code: 'PA-202610-001',
                organization_name: 'Hội Bảo vệ Người tiêu dùng',
                subject: 'Phản ánh đã xử lý',
                content: 'Nội dung phản ánh thứ nhất, đủ dài để hiển thị.',
                status: 'resolved',
                status_label: 'Đã xử lý',
                resolution: 'Sàn đã gỡ nội dung vi phạm.',
                received_at: '2026-10-01T03:00:00+00:00',
                resolved_at: '2026-10-03T09:30:00+00:00',
            },
            {
                code: 'PA-202610-002',
                organization_name: 'Hội Tiêu chuẩn <test>',
                subject: 'Phản ánh đang xem xét',
                content: 'Nội dung phản ánh thứ hai.',
                status: 'in_review',
                status_label: 'Đang xem xét',
                resolution: null,
                received_at: '2026-10-05T03:00:00+00:00',
                resolved_at: null,
            },
        ],
        meta: { page: 1, per_page: 20, total: 2, last_page: 1, max_per_page: 50 },
    },

    '/api/v2/filters': {
        data: {
            cities: [{ code: 'hanoi', name: 'Hà Nội', count: 104 }],
            venue_types: [{ code: 'retail', name: 'Bán lẻ', count: 1 }],
            networks: [],
            owners: [],
            price_range: { min: 0, max: 1000000 },
        },
    },
};

/** Đường chi tiết: khớp theo slug, trả 404 cho slug lạ. */
function chiTiet(pathname) {
    if (pathname === '/api/v2/screens/man-hinh-co-gia') {
        return { data: { ...SCREEN_CO_GIA, availability: AVAILABILITY } };
    }

    if (pathname === '/api/v2/screens/man-hinh-khong-gia') {
        return { data: { ...SCREEN_KHONG_GIA, availability: AVAILABILITY } };
    }

    for (const trang of [CHINH_SACH_DA_BAN_HANH, CHINH_SACH_BAN_NHAP]) {
        if (pathname === '/api/v2/policies/' + trang.slug) {
            return { data: trang };
        }
    }

    if (pathname === '/api/v2/owners/owner-mot') {
        return { data: OWNER_DETAIL, screens: { data: [SCREEN_CO_GIA], meta: PAGE_META } };
    }

    if (pathname === '/api/v2/products/goi-mot') {
        return {
            data: {
                ...PRODUCT,
                description: 'Mô tả dài của Gói Một.',
                photo_urls: ['https://oohx.net/storage/products/a.jpg'],
                specs: { 'Độ phân giải': '1920x1080', lồng: { khong: 'in ra' } },
                package_options: [{ name: 'Gói nhỏ', quantity: 2, price: 1000000 }],
                screens: [SCREEN_CO_GIA],
                seo: { meta_title: null, meta_description: null },
            },
        };
    }

    return null;
}

export function startStubApi(port = 0) {
    return new Promise((resolve) => {
        const server = createServer((req, res) => {
            const url = new URL(req.url, 'http://127.0.0.1');
            const body = ROUTES[url.pathname] ?? chiTiet(url.pathname);

            res.setHeader('Content-Type', 'application/json; charset=utf-8');

            if (!body) {
                res.statusCode = 404;
                res.end(
                    JSON.stringify({
                        error: 'not_found',
                        message: 'Không tìm thấy.',
                        code: 404,
                        details: [],
                    }),
                );

                return;
            }

            res.end(JSON.stringify(body));
        });

        server.listen(port, '127.0.0.1', () => {
            resolve({ server, port: server.address().port });
        });
    });
}
