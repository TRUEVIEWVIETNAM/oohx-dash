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

    // Bắt buộc theo `ScreenSummary` trong `docs/openapi/v2.yaml` từ
    // 18/10/2026. Thiếu nó ở fixture là lặp lại đúng lỗi đã xảy ra ba lần
    // trong giai đoạn 6: stub nói sai hợp đồng, trang hiện sai, và không
    // chốt nào đỏ vì chốt đọc HTML do chính fixture đó sinh ra.
    screen_type_label: 'Billboard',

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

    // Bắt buộc theo `ScreenSummary.photo_srcset` từ 25/10/2026 (lát 4). Dạng
    // đúng như máy chủ dựng: hai bề rộng, đơn vị `w`, đường dẫn trong
    // `anh-dan-xuat/`. Fixture nói sai hợp đồng ở đây là chốt đọc HTML chạy
    // trên một thế giới không tồn tại — đã xảy ra ba lần ở giai đoạn 6.
    photo_srcset:
        'https://oohx.net/storage/anh-dan-xuat/w480/screens/a.jpg 480w, '
        + 'https://oohx.net/storage/anh-dan-xuat/w1280/screens/a.jpg 1280w',
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

/**
 * Màn hình **thiếu thông tin**, và đây là trường hợp PHỔ BIẾN chứ không phải
 * ngoại lệ.
 *
 * Đo trên production 18/10/2026, 50 màn hình lấy qua API công khai: **0** cái
 * có kích thước, **0** cái có giá. Fixture cũ chỉ có màn hình đầy đủ, nên mọi
 * chốt chạy trên một thế giới không tồn tại — và thẻ in "Kích thước —" suốt
 * nhiều ngày mà không ca nào đỏ.
 */
const SCREEN_THIEU_THONG_TIN = {
    ...SCREEN_CO_GIA,
    slug: 'man-hinh-thieu-thong-tin',
    name: 'Màn Hình Thiếu Thông Tin',
    screen_type: 'lcd',
    screen_type_label: 'LCD',
    size: { width_m: null, height_m: null },
    pricing: {
        model: 'io',
        floor_cpm: { amount: null, currency: 'VND' },
        io_rate: { amount: null, currency: 'VND', unit: null },
    },
};

const AVAILABILITY = { window_days: 30, remaining_sov_pct: 60, has_capacity: true };

// ══ Chi tiết màn hình, lát 4 ══
//
// Fixture này cố ý **đủ các nhánh**: nhiều ảnh (để dải thumbnail và lightbox
// render), insights có cả ba khối con, POI có hai nhóm, và `similar` không
// rỗng. Thiếu một nhánh thì HTML đã render không đi qua đó, và phép kiểm khối
// giao diện canh một trang nghèo hơn trang thật.
const SCREEN_CHI_TIET = {
    description: 'Màn hình LED trong sảnh trung tâm thương mại.',
    photo_urls: [
        'https://oohx.net/storage/screens/a.jpg',
        'https://oohx.net/storage/screens/b.jpg',
        'https://oohx.net/storage/screens/c.jpg',
    ],

    // Lát 4: cùng độ dài và cùng thứ tự với `photo_urls`, và **ảnh thứ ba cố
    // ý là `null`** — đó là hình dạng thật khi một ảnh gốc nhỏ hơn 480px hoặc
    // chưa được lệnh `oohx:sinh-anh-dan-xuat` chạm tới. Fixture toàn ảnh có
    // `srcset` thì nhánh không có `srcset` không bao giờ được render.
    photo_srcsets: [
        'https://oohx.net/storage/anh-dan-xuat/w480/screens/a.jpg 480w, '
            + 'https://oohx.net/storage/anh-dan-xuat/w1280/screens/a.jpg 1280w',
        'https://oohx.net/storage/anh-dan-xuat/w480/screens/b.jpg 480w',
        null,
    ],

    // Lát 5: ba ảnh này là ảnh của chính màn hình, nên thư viện KHÔNG được
    // nói gì thêm. Bậc thay thế canh ở `man-hinh-insights-rong` bên dưới.
    photo_source: 'screen',
    specs: {
        width_px: 1920,
        height_px: 1080,
        allow_image: true,
        allow_video: true,
        spot_length_s: 15,
        orientation: 'landscape',
        venue_label: 'Trung tâm thương mại',
        venue_type: 'indoor',
        sov_max_pct: 100,
        io_kpi_spots_per_day: 240,
    },
    site: {
        name: 'Toà Nhà A',
        address: 'Số 1 Đường Thử',
        city: 'Hà Nội',
        lat: 21.0278,
        lng: 105.8342,
    },
    owner: {
        slug: 'owner-mot',
        name: 'Công Ty Một',
        logo_url: 'https://oohx.net/storage/owners/logo.jpg',
        verified: true,
        phone: '0900000001',
        email: 'lien-he@owner.test',
    },
    insights: {
        placement_zone: 'escalator',
        placement_label: 'Cạnh thang cuốn',
        daily_footfall: 12000,
        monthly_reach: 250000,
        daily_impressions_est: 7200,
        audience_profile: {
            male_pct: 52,
            female_pct: 48,
            age_18_24_pct: 30,
            age_25_34_pct: 40,
            age_35_44_pct: 20,
            age_45_plus_pct: 10,
            income_tier: 'mid',
            lifestyle_tags: ['sinh viên', 'nhân viên văn phòng'],
            source_note: 'Đếm thủ công 3 ngày',
        },
        time_performance: {
            best_day: 'sat',
            morning_pct: 20,
            afternoon_pct: 45,
            evening_pct: 35,
            rationale: 'Cao điểm cuối tuần theo lưu lượng trung tâm.',
        },
        nearby_context: {
            brands: ['Highlands', 'Uniqlo'],
            landmarks: ['Ga metro S9'],
            highlights: 'Cạnh cửa ra ga metro, lối đi chính của trung tâm.',
        },
        methodology_note: 'Media owner đếm thủ công, chưa có đo độc lập.',
        source: 'owner',
    },
    nearby_pois: [
        {
            id: 'p1',
            name: 'Cafe Thử',
            group: 'food_bev',
            label: 'Cafe',
            icon: 'local_cafe',
            color: '#8B4513',
            dist_m: 45,
            lat: 21.028,
            lng: 105.8344,
        },
        {
            id: 'p2',
            name: 'Ngân Hàng Thử',
            group: 'finance',
            label: 'Ngân hàng',
            icon: 'account_balance',
            color: '#16A085',
            dist_m: 120,
            lat: 21.0275,
            lng: 105.8349,
        },
    ],
};

/** Lịch trống giả: N tháng từ hôm nay, một ngày đã đặt hết và một ngày đặt phần. */
function lichTrong(months) {
    const hom_nay = new Date();
    const het = new Date(
        Date.UTC(hom_nay.getUTCFullYear(), hom_nay.getUTCMonth() + Number(months) + 1, 0),
    );

    const days = [];
    const d = new Date(Date.UTC(hom_nay.getUTCFullYear(), hom_nay.getUTCMonth(), hom_nay.getUTCDate()));

    let i = 0;
    while (d <= het) {
        // Ba trạng thái đều có mặt, nên cả ba class `cd-*` được render.
        const con = i === 3 ? 0 : i === 4 ? 40 : 100;
        days.push({ date: d.toISOString().slice(0, 10), remaining_sov_pct: con });
        d.setUTCDate(d.getUTCDate() + 1);
        i++;
    }

    return {
        from: days[0]?.date,
        to: days[days.length - 1]?.date,
        months: Number(months),
        sov_max_pct: 100,
        days,
    };
}

// `logo_url`, `city_count`, `venue_types`, `verified` thêm 14/10/2026 cùng với
// `OwnerSummaryResource`: thẻ owner dựng lại theo bản Blade in cả bốn, nên dữ
// liệu giả phải có chúng — nếu không thì HTML đã render không đi qua những
// nhánh đó và phép kiểm khối giao diện canh một thẻ nghèo hơn thẻ thật.
const OWNER = {
    slug: 'owner-mot',
    name: 'Công Ty Một',
    type: 'agency',
    logo_url: 'https://oohx.net/storage/owners/logo.jpg',
    cover_url: 'https://oohx.net/storage/owners/a.jpg',
    screen_count: 2,
    city_count: 1,
    venue_types: ['Trung tâm thương mại', 'Toà nhà văn phòng'],
    verified: true,
    featured: false,
};

const OWNER_DETAIL = {
    ...OWNER,
    tagline: 'Khẩu hiệu của Công Ty Một',
    about: 'Giới thiệu dài về Công Ty Một.\nDòng thứ hai, để kiểm `white-space: pre-line`.',
    logo_url: 'https://oohx.net/storage/owners/logo.jpg',

    // Bốn trường của `contact`, đủ như `OwnerDetailResource` trả — bản trước
    // chỉ có `email` + `phone`, nên hai nhánh "Website" và "Trụ sở" của thẻ
    // Giới thiệu không bao giờ được render trong lúc test.
    contact: {
        website: 'https://cong-ty-mot.test',
        email: 'lien-he@cong-ty-mot.test',
        phone: '0900000001',
        address: 'Số 1 Đường Thử',
    },

    location: { province: 'Hà Nội', commune: 'Ba Đình' },
    founded: 2015,

    // `stats` **đúng hình dạng hợp đồng**: `screen_count`, `city_count`,
    // `venue_types`. Bản trước viết `{ screens: 2, cities: 1 }` — hai khóa
    // không có trong `OwnerDetail`, nên trang in "0 màn hình · 0 tỉnh/thành"
    // trong mọi lượt build thử và không chốt nào đỏ. Cùng loại lỗi với
    // `type: 'bundle'` đã sửa ở lát 3: một stub nói sai hợp đồng thì nó che
    // đúng loại lỗi nó phải phơi ra.
    stats: {
        screen_count: 2,
        city_count: 1,
        venue_types: ['Trung tâm thương mại', 'Toà nhà văn phòng'],
    },
};

/** Đánh giá đã duyệt + điểm trung bình của owner (lát 5). */
const OWNER_REVIEWS = [
    {
        organization: 'Agency Thử',
        rating: 5,
        comment: 'Vị trí tốt, hỗ trợ nhanh.',
        published_at: '2026-09-20',
    },
    {
        // Không có `comment`: một nhận xét chỉ có số sao là nhánh render riêng.
        organization: 'Brand Hai',
        rating: 4,
        comment: null,
        published_at: '2026-08-02',
    },
];

const PRODUCT = {
    slug: 'goi-mot',
    name: 'Gói Một',

    // `package`, không phải `bundle`: OpenAPI khai `enum: [single, package,
    // custom]`, và `ProductCard` tra nhãn tiếng Việt theo đúng ba khóa đó. Một
    // giá trị ngoài enum làm thẻ in **mã thô** ra trang ("bundle") mà không
    // test nào đỏ — stub nói sai hợp đồng thì nó che đúng loại lỗi nó phải
    // phơi ra.
    type: 'package',
    category: { code: 'retail', label: 'Bán lẻ' },
    // `listing_mode` là một **đối tượng** trong hợp đồng
    // (`{code, label, allows_package, allows_individual}`), không phải chuỗi.
    // Bản trước viết `listing_mode: 'package'` — nên
    // `product.listing_mode?.allows_package` là `undefined` và panel giá của
    // trang gói không render nhánh nào. Lần thứ ba stub nói sai hợp đồng:
    // lát 3 là `type: 'bundle'` ngoài enum, lát 5 là `stats.screens`.
    //
    // `both` để fixture đi qua **cả hai** tab của panel (mua cả gói / chọn lẻ).
    listing_mode: {
        code: 'both',
        label: 'Gói hoặc lẻ',
        allows_package: true,
        allows_individual: true,
    },
    owner: { slug: 'owner-mot', name: 'Công Ty Một' },
    location: { city: 'Hà Nội', region: 'north', site: null },
    network: { code: 'NET1', name: 'Mạng Một' },
    quantity: { total_units: 10, min: 1, max: 10, screen_count: 2 },
    pricing: { currency: 'VND', unit: 'month', package: 5000000, per_screen: 800000, package_discount_pct: 10 },
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

    '/api/v2/screens': {
        // Ba trạng thái dữ liệu mà kho thật có: đủ, thiếu giá, thiếu gần hết.
        data: [SCREEN_CO_GIA, SCREEN_KHONG_GIA, SCREEN_THIEU_THONG_TIN],
        meta: PAGE_META,
    },

    '/api/v2/screens/featured': {
        data: [{ ...SCREEN_CO_GIA, availability: AVAILABILITY }],
        meta: LIMIT_META,
    },

    '/api/v2/screens/pins': { data: [], meta: { ...LIMIT_META, city: 'hanoi' } },

    // Bản đồ hỏi endpoint này từ **trình duyệt** sau khi biết khung nhìn, nên
    // HTML máy chủ render không phụ thuộc nó. Vẫn khai ở đây để stub phản ánh
    // đúng tập endpoint trang `/map` dùng — một stub thiếu đường là một stub
    // nói rằng trang không gọi nó.
    '/api/v2/screens/map': {
        data: [
            {
                slug: 'man-hinh-co-gia',
                name: 'Màn Hình Có Giá <test>',
                lat: 21.0285,
                lng: 105.8542,
                city: 'Hà Nội',
                address: 'Số 1 Đường Thử',
                site: { name: 'Toà Nhà A' },
                network: { code: 'NET1', name: 'Mạng Một' },
                venue_type: 'mall',
                owner: { slug: 'owner-mot', name: 'Công Ty Một' },
                price: { amount: 1000000, currency: 'VND', unit: 'month' },
                photo_url: 'https://oohx.net/storage/screens/a.jpg',
            },
        ],
        clusters: [],
        meta: { mode: 'pin', returned: 1, total: 1, truncated: false, cell_deg: null, max: 500 },
    },

    '/api/v2/owners': { data: [OWNER], meta: PAGE_META },
    '/api/v2/owners/featured': { data: [OWNER], meta: LIMIT_META },

    '/api/v2/products': { data: [PRODUCT], meta: PAGE_META },

    // Hai lưới thẻ "duyệt" của trang chủ.
    //
    // Fixture cố ý **thiếu ảnh một nửa**: `thumb` và `photo` đều cho phép
    // null trong CSDL, và thiết kế thẻ phải chịu được điều đó — thẻ loại địa
    // điểm có icon làm lớp nền, thẻ tỉnh thành có dải gradient. Một fixture
    // chỉ có ảnh đầy đủ sẽ không bao giờ chứng minh được chuyện đó.
    '/api/v2/browse': {
        data: {
            venue_types: [
                { code: 'ngoai-troi', name: 'Ngoài trời', count: 103, icon: 'landscape', thumb: 'https://oohx.net/storage/venue/ngoai-troi.jpg' },
                { code: 'ban-le', name: 'Bán lẻ', count: 1, icon: 'storefront', thumb: null },
            ],
            cities: [
                { code: 'hanoi', name: 'Hà Nội', count: 13, photo: 'https://oohx.net/storage/provinces/hanoi.jpg' },
                { code: 'son-la', name: 'Sơn La', count: 6, photo: null },
            ],
        },
    },

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

    // Hai agency: một có logo + website, một không — để nhánh chữ viết tắt
    // và nhánh thiếu website đều được render ít nhất một lần.
    '/api/v2/agencies': {
        data: [
            {
                name: 'Agency Mot',
                type: 'agency',
                website_host: 'agency-mot.vn',
                logo_url: 'https://oohx.net/storage/agencies/a.jpg',
                campaign_count: 7,
            },
            {
                name: 'Thuong Hieu Hai',
                type: 'agency',
                website_host: null,
                logo_url: null,
                campaign_count: 0,
            },
        ],
        meta: { page: 1, per_page: 12, total: 2, last_page: 1, max_per_page: 50 },
    },

    // Bộ lọc của sidebar /products (lát 3). Đường RIÊNG, không phải
    // /api/v2/filters: ở đó `cities` đếm trên bảng màn hình, còn đây là
    // thành phố có gói — cùng tên khóa, khác con số. Số đếm ở đây cố ý khác
    // hẳn bên kia để một lần nhầm hai endpoint là thấy ngay trên trang.
    '/api/v2/products/filters': {
        data: {
            categories: [
                { slug: 'led', label: 'Màn hình LED', count: 7 },
                { slug: 'billboard', label: 'Biển bảng', count: 2 },
            ],
            types: [
                { slug: 'package', label: 'Gói', count: 6 },
                { slug: 'single', label: 'Đơn lẻ', count: 3 },
            ],
            cities: [
                { name: 'Hà Nội', count: 5 },
                { name: 'Đà Nẵng', count: 4 },
            ],
        },
    },
    '/api/v2/filters': {
        data: {
            cities: [{ code: 'hanoi', name: 'Hà Nội', count: 104 }],
            venue_types: [{ code: 'retail', name: 'Bán lẻ', count: 1 }],
            networks: [],
            owners: [],

            // Cây loại hình có network lồng bên trong — ô tìm kiếm lớn vẽ đúng
            // cây này (thêm 14/10/2026). Một nhóm CÓ network con và một nhóm
            // KHÔNG, vì hai nhánh render khác nhau: nhóm có con thì hiện mũi
            // thu gọn, nhóm không thì hiện ô trống giữ chỗ.
            categories_networks: [
                {
                    code: 'retail',
                    name: 'Bán lẻ',
                    icon: 'storefront',
                    count: 1,
                    networks: [{ code: 'NET1', name: 'Mạng Một', count: 1 }],
                },
                { code: 'transit', name: 'Giao thông', icon: 'train', count: 0, networks: [] },
            ],

            price_range: { min: 0, max: 1000000 },

            // Trạng thái giá và loại màn hình — hai nhóm cột lọc `/explore`
            // dựng (19/10/2026).
            //
            // `led: 0` có chủ ý: nhóm đếm 0 phải hiện ra MỜ chứ không biến
            // mất, và đó là thứ `khoi-giao-dien.mjs` canh. Trên kho thật có
            // đúng tình huống này — ba loại hình chưa có hàng.
            price_status: { quoted: 1, unquoted: 103 },
            screen_types: [
                { code: 'billboard', name: 'Billboard', count: 30 },
                { code: 'led', name: 'LED', count: 0 },
                { code: 'lcd', name: 'LCD', count: 20 },
            ],
        },
    },
};

/** Đường chi tiết: khớp theo slug, trả 404 cho slug lạ. */
function chiTiet(pathname, searchParams = new URLSearchParams()) {
    // Lịch trống đi qua cùng hàm này vì nó cũng là một đường có tham số đường
    // dẫn. `months` đọc từ query, mặc định 2 — đúng mặc định của API thật.
    if (/^\/api\/v2\/screens\/[^/]+\/availability$/.test(pathname)) {
        // ── Lát 2 (24/10/2026): lịch RỖNG nhưng lấy được ──
        //
        // Hai trạng thái phải render khác nhau, và trước lát này chúng dùng
        // cùng một câu: `lich === null` (lời gọi lỗi, sàn KHÔNG BIẾT) và
        // `days: []` (lấy được, sàn BIẾT là không có ngày nào mở bán). Nói
        // "thử tải lại trang" cho trường hợp sau là dẫn người mua đi làm một
        // việc vô ích.
        //
        // Màn hình này là trường hợp thứ hai. Trường hợp thứ nhất không dựng
        // được bằng fixture: `getScreenAvailability()` trả null khi lời gọi
        // **lỗi**, nên nó cần một endpoint hỏng chứ không phải một endpoint
        // trả rỗng — đã ghi vào chốt tĩnh thay vì ở đây.
        if (pathname.startsWith('/api/v2/screens/man-hinh-insights-rong/')) {
            return { data: { sov_max_pct: 100, days: [] } };
        }

        return { data: lichTrong(searchParams.get('months') ?? 2) };
    }

    // ── Lát 2 (24/10/2026): `insights` CÓ nhưng mọi trường bên trong null ──
    //
    // Đúng hình dạng production đo được trên
    // `GET /api/v2/screens/oohx-led-cto-can-tho` ngày 24/10/2026.
    //
    // Fixture cũ chỉ có hai cực: màn hình đủ số liệu, và màn hình
    // `insights: null`. Trường hợp ở giữa — đối tượng tồn tại, rỗng bên trong —
    // là trường hợp PHỔ BIẾN trên kho thật, và nó đi qua phép chặn
    // `if (!insights)` rồi render một tab trống. Không chốt nào đỏ, vì không
    // màn hình giả nào có hình dạng đó.
    if (pathname === '/api/v2/screens/man-hinh-insights-rong') {
        return {
            data: {
                ...SCREEN_KHONG_GIA,
                ...SCREEN_CHI_TIET,
                slug: 'man-hinh-insights-rong',
                name: 'Màn Hình Insights Rỗng',
                availability: AVAILABILITY,

                // ── Lát 5 (24/10/2026): đúng hình dạng ảnh của production ──
                //
                // `oohx-led-cto-can-tho` có đúng MỘT ảnh, và nó là
                // `storage/owners/covers/…` — ảnh bìa của chủ sở hữu dùng thay
                // ảnh biển. Fixture duy nhất đi qua nhánh ấy, và nó cũng là
                // fixture duy nhất có một ảnh (nên `gallery--single` được
                // render ở đâu đó).
                photo_urls: ['https://oohx.net/storage/owners/covers/bia.webp'],

                // Lát 4: ảnh bìa chủ sở hữu **cũng** có bản dẫn xuất — lệnh
                // `oohx:sinh-anh-dan-xuat` đi theo chuỗi dự phòng mà người mua
                // thấy, không chỉ theo cột `photos`. Trên dữ liệu hôm nay đó
                // là 104/104 vị trí, nên đây là nhánh phổ biến nhất chứ không
                // phải ngoại lệ.
                photo_srcsets: [
                    'https://oohx.net/storage/anh-dan-xuat/w480/owners/covers/bia.webp 480w, '
                        + 'https://oohx.net/storage/anh-dan-xuat/w1280/owners/covers/bia.webp 1280w',
                ],
                photo_source: 'owner_cover',

                // Và `verified: false`, cũng như production. Đây là chiều duy
                // nhất của huy hiệu lát 5 được in ra.
                owner: { ...SCREEN_CHI_TIET.owner, verified: false },
                insights: {
                    placement_zone: null,
                    placement_label: null,
                    daily_footfall: null,
                    monthly_reach: null,
                    daily_impressions_est: null,
                    audience_profile: null,
                    time_performance: null,
                    nearby_context: null,
                    methodology_note: null,

                    // `source` luôn có mặt, kể cả khi không có gì được khai.
                    // Đếm nó là nói rằng hồ sơ có dữ liệu.
                    source: 'owner',
                },
                nearby_pois: [],
                similar: [],
            },
        };
    }

    if (pathname === '/api/v2/screens/man-hinh-co-gia') {
        return {
            data: {
                ...SCREEN_CO_GIA,
                ...SCREEN_CHI_TIET,
                availability: AVAILABILITY,
                similar: [SCREEN_KHONG_GIA],
            },
        };
    }

    if (pathname === '/api/v2/screens/man-hinh-khong-gia') {
        return {
            data: {
                ...SCREEN_KHONG_GIA,
                ...SCREEN_CHI_TIET,
                availability: AVAILABILITY,

                // ── Lát 1 (24/10/2026): địa giới HAI CẤP ──
                //
                // Đúng dạng dữ liệu thật. `GET /api/v2/screens/oohx-led-cto-can-tho`
                // trả `"Cần Thơ > Phường Tân An"` — hai cấp hành chính trong
                // một chuỗi, nối bằng dấu `>`.
                //
                // Trước lát này trang in nguyên dấu `>` ra giao diện, và
                // **không chốt nào bắt được**: mọi màn hình giả trong tệp này
                // đều có `city` một cấp ("Hà Nội"), nên cả bộ chốt chạy trên
                // một thế giới không có chuỗi gộp. Cùng một hình dạng lỗi với
                // fixture "màn hình thiếu thông tin" ở trên.
                location: { ...SCREEN_KHONG_GIA.location, city: 'Cần Thơ > Phường Tân An' },
                site: { ...SCREEN_CHI_TIET.site, city: 'Cần Thơ > Phường Tân An' },

                // Màn hình không giá KHÔNG có insights: trang phải ẩn hẳn tab
                // Insights, và đó là một nhánh riêng cần được render ở đâu đó.
                insights: null,
                nearby_pois: [],
                similar: [],
            },
        };
    }

    for (const trang of [CHINH_SACH_DA_BAN_HANH, CHINH_SACH_BAN_NHAP]) {
        if (pathname === '/api/v2/policies/' + trang.slug) {
            return { data: trang };
        }
    }

    if (pathname === '/api/v2/owners/owner-mot') {
        return {
            data: OWNER_DETAIL,
            screens: { data: [SCREEN_CO_GIA], meta: PAGE_META },
            reviews: OWNER_REVIEWS,
            rating: { average: 4.5, count: 2 },
        };
    }

    // Owner thứ hai: KHÔNG có đánh giá nào, không logo, không `founded`.
    // Ba nhánh rỗng của trang hồ sơ (không thẻ đánh giá, avatar là chữ cái
    // đầu, không ô "Kinh nghiệm") phải được render ở đâu đó, nếu không thì
    // chốt chỉ canh trường hợp đầy đủ.
    if (pathname === '/api/v2/owners/owner-khong-danh-gia') {
        return {
            data: {
                ...OWNER_DETAIL,
                slug: 'owner-khong-danh-gia',
                name: 'Công Ty Hai',
                logo_url: null,
                cover_url: null,
                founded: null,
                stats: { screen_count: 0, city_count: 0, venue_types: [] },
            },
            screens: { data: [], meta: { ...PAGE_META, total: 0 } },
            reviews: [],
            rating: { average: null, count: 0 },
        };
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

            // Gói tương tự (lát 7). Một phần tử là đủ để khối render; rỗng
            // là một nhánh khác, và trang phải không in tiêu đề khi rỗng.
            similar: [{ ...PRODUCT, slug: 'goi-hai', name: 'Gói Hai' }],
        };
    }

    return null;
}

export function startStubApi(port = 0) {
    return new Promise((resolve) => {
        const server = createServer((req, res) => {
            const url = new URL(req.url, 'http://127.0.0.1');

            res.setHeader('Content-Type', 'application/json; charset=utf-8');

            /*
             * Đường **ghi** duy nhất của trang công khai: gửi yêu cầu báo giá
             * (lát 6). Stub nhận nó để đo tay chạy được đầu-cuối mà không
             * cần Laravel — và để phép kiểm sau này có chỗ mô phỏng 422 và
             * 429 mà không phải dựng cả máy chủ thật.
             *
             * Trả về đúng hình dạng `/api/v2/quote-requests` khai trong
             * `docs/openapi/v2.yaml`: một stub nói sai hợp đồng là một stub
             * làm chốt xanh trong khi production đỏ — đã xảy ra ba lần trong
             * giai đoạn 6.
             */
            if (req.method === 'POST' && url.pathname === '/api/v2/quote-requests') {
                let tho = '';

                req.on('data', (c) => (tho += c));
                req.on('end', () => {
                    let gui = {};
                    try {
                        gui = JSON.parse(tho || '{}');
                    } catch {
                        gui = {};
                    }

                    const slugs = Array.isArray(gui.slugs) ? gui.slugs : [];

                    if (slugs.length === 0 || gui.dong_y_chinh_sach !== true) {
                        res.statusCode = 422;
                        res.end(
                            JSON.stringify({
                                error: 'validation_failed',
                                message: 'Dữ liệu gửi lên không hợp lệ.',
                                code: 422,
                                details: [
                                    {
                                        field: slugs.length === 0 ? 'slugs' : 'dong_y_chinh_sach',
                                        message: slugs.length === 0
                                            ? 'Chưa chọn vị trí nào để hỏi giá.'
                                            : 'Cần đồng ý chính sách bảo mật để gửi yêu cầu.',
                                    },
                                ],
                            }),
                        );

                        return;
                    }

                    res.statusCode = 201;
                    res.end(
                        JSON.stringify({
                            data: {
                                code: 'YCB-202610-0001',
                                so_vi_tri: slugs.length,
                                so_bi_bo: 0,
                                message: 'Đã nhận yêu cầu báo giá. Vui lòng giữ mã này để tra cứu.',
                            },
                        }),
                    );
                });

                return;
            }

            const body = ROUTES[url.pathname] ?? chiTiet(url.pathname, url.searchParams);

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
