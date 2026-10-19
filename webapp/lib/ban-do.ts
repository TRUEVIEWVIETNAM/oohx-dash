import type { components } from '@api-types';

export type MapPin = components['schemas']['MapPin'];

/**
 * Nội dung popup.
 *
 * Mọi giá trị đi qua `thoat()` trước khi vào HTML: tên màn hình và địa chỉ do
 * media owner nhập, nên chúng là dữ liệu người dùng nhập đi thẳng vào
 * `innerHTML` của Leaflet. Không thoát là một lỗ XSS trên trang công khai.
 */
export function popupHtml(pin: MapPin): string {
    const dong: string[] = [
        `<strong>${thoat(pin.name)}</strong>`,
    ];

    if (pin.address || pin.city) {
        dong.push(`<div>${thoat([pin.address, pin.city].filter(Boolean).join(', '))}</div>`);
    }

    if (pin.owner?.name) {
        dong.push(`<div>${thoat(pin.owner.name)}</div>`);
    }

    const gia = giaPin(pin);
    if (gia) dong.push(`<div>${thoat(gia)}</div>`);

    dong.push(`<a href="/explore/${encodeURIComponent(pin.slug)}">Xem chi tiết</a>`);

    return dong.join('');
}

export function giaPin(pin: MapPin): string | null {
    const price = pin.price as
        | { amount?: number | null; currency?: string | null; unit?: string | null }
        | undefined;

    if (!price?.amount) return null;

    const so = new Intl.NumberFormat('vi-VN').format(price.amount);
    const tien = (price.currency || 'VND') === 'VND' ? `${so} ₫` : `${so} ${price.currency}`;

    return price.unit ? `${tien}/${price.unit}` : tien;
}

export function thoat(s: string): string {
    return s
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ── Pin và cụm cho trang bản đồ (lát 6) ─────────────────────────────────────
//
// Dựng lại `frontpage/partials/map-shared-js.blade.php`. Bản Blade gắn những
// hàm này lên `window.OOHXMap` để hai trang (trang chủ và `/map`) dùng chung;
// ở đây chúng là hàm export, nên không có gì treo trên `window` và bộ gói biết
// cái nào thật sự được dùng.

/** Một điểm trên bản đồ sau khi gom theo site: nhiều màn hình cùng một toà nhà. */
export type CumSite = {
    khoa: string;
    lat: number;
    lng: number;
    tenSite: string;
    city: string | null;
    address: string | null;
    networkName: string | null;
    venueType: string | null;
    ownerName: string | null;
    manHinh: MapPin[];
};

/**
 * Gom pin theo site.
 *
 * Bản Blade gom ở **máy chủ** (`$screens->groupBy('siteId')`), nên một marker
 * là một toà nhà và badge trên pin là số màn hình trong đó. `/api/v2/screens/map`
 * trả pin theo **màn hình** — hợp đồng đó không đổi, vì nó là dữ liệu thô mà
 * nhiều bên dùng. Nên việc gom chuyển sang client.
 *
 * Khóa gom là `tên site + toạ độ làm tròn 5 chữ số` (~1m), không phải id site:
 * DTO bản đồ cố ý không trả khóa nội bộ. Hai site khác nhau trùng cả tên lẫn
 * toạ độ tới mét thì gom chung — và nếu điều đó xảy ra thì chúng cũng chồng
 * lên nhau trên bản đồ.
 */
export function gomTheoSite(pins: MapPin[]): CumSite[] {
    const theo = new Map<string, CumSite>();

    for (const pin of pins) {
        if (typeof pin.lat !== 'number' || typeof pin.lng !== 'number') continue;

        const tenSite = pin.site?.name ?? pin.name ?? '';
        const khoa = `${tenSite}@${pin.lat.toFixed(5)},${pin.lng.toFixed(5)}`;
        const co = theo.get(khoa);

        if (co) {
            co.manHinh.push(pin);
            continue;
        }

        theo.set(khoa, {
            khoa,
            lat: pin.lat,
            lng: pin.lng,
            tenSite,
            city: pin.city ?? null,
            address: pin.address ?? null,
            networkName: pin.network?.name ?? null,
            venueType: pin.venue_type ?? null,
            ownerName: pin.owner?.name ?? null,
            manHinh: [pin],
        });
    }

    // Nhiều màn hình nhất trước — đúng thứ tự panel trái của bản Blade.
    return [...theo.values()].sort((a, b) => b.manHinh.length - a.manHinh.length);
}

/** Màu pin theo loại điểm đặt. Cùng bảng với `OOHXMap.pinColor`. */
export function mauPin(venueType?: string | null): 'bl' | 'grn' | 'org' | 'red' {
    if (!venueType) return 'bl';
    if (venueType === 'mall' || venueType === 'retail') return 'grn';
    if (venueType === 'transit') return 'org';
    if (venueType === 'healthcare' || venueType === 'education') return 'red';
    if (venueType === 'food-beverage' || venueType === 'entertainment') return 'org';

    return 'bl';
}

/** Số theo lối Việt Nam: `1234` → `1.234`. */
export function soVN(n: number): string {
    return new Intl.NumberFormat('vi-VN').format(n);
}

/** `1234` → `1.2K`. Dùng cho số trong vòng tròn cụm. */
export function gonSo(n: number): string {
    if (n >= 1000) return (n / 1000).toFixed(1).replace('.0', '') + 'K';

    return String(n);
}

/** Hai chữ cái đầu của tên, cho ô avatar khi không có logo. */
export function chuCaiDau(ten: string): string {
    return ten
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((t) => t[0] ?? '')
        .join('')
        .toUpperCase();
}

/**
 * HTML của pin "rich": ô chữ cái đầu + tên site + dòng phụ + badge số màn hình.
 *
 * Mọi chuỗi đi qua `thoat()`: tên site và tên network do media owner nhập, và
 * chúng vào thẳng `innerHTML` của Leaflet qua `divIcon`.
 */
export function pinHtml(cum: CumSite): string {
    const mau = mauPin(cum.venueType);
    const so = cum.manHinh.length;

    const phu = so > 1
        ? `${so} màn hình${cum.networkName ? ' · ' + thoat(cum.networkName) : ''}`
        : thoat(cum.networkName ?? cum.city ?? '');

    const badge = so > 1 ? `<div class="oohx-rich-badge">${so}</div>` : '';

    return (
        `<div class="oohx-rich oohx-rich--${mau}">` +
        `<div class="oohx-rich-initials oohx-rich-initials--${mau}">${thoat(chuCaiDau(cum.ownerName ?? cum.tenSite))}</div>` +
        '<div class="oohx-rich-body">' +
        `<div class="oohx-rich-name">${thoat(cum.tenSite)}</div>` +
        `<div class="oohx-rich-meta">${phu}</div>` +
        '</div>' +
        badge +
        '</div>'
    );
}

/** HTML của vòng tròn cụm, kèm bậc kích thước theo số lượng. */
export function cumHtml(soLuong: number): { html: string; px: number } {
    const bac = soLuong >= 100 ? 'xl' : soLuong >= 50 ? 'lg' : soLuong >= 20 ? 'md' : 'sm';
    const px = bac === 'xl' ? 80 : bac === 'lg' ? 68 : bac === 'md' ? 56 : 44;

    return {
        html: `<div class="oohx-cluster oohx-cluster--${bac}">${gonSo(soLuong)}</div>`,
        px,
    };
}

// ── Nguồn ảnh nền bản đồ ────────────────────────────────────────────────────
//
// Ba bản đồ của trang công khai đọc từ đây; chín view Filament đọc từ
// `config/map.php`. Hai bản sao của cùng một giá trị, và đó là một đánh đổi có
// ý thức: Next không đọc được config PHP, còn kéo URL tile qua một lời gọi API
// là thêm một vòng mạng cho một chuỗi hằng.
//
// Chỗ trôi được bịt bằng một chốt: `tests/js/leaflet.test.mjs` đọc **cả hai**
// tệp và đỏ nếu chúng khác nhau. Nên đổi nhà cung cấp vẫn là sửa hai chỗ,
// nhưng quên một chỗ thì không lọt.
//
// ══ Hai lần đổi trong một ngày, và vì sao (18/10/2026) ══
//
// **Sáng: bỏ `tile.openstreetmap.org`.** Mọi yêu cầu trả HTTP 503 từ mạng
// Việt Nam — đo bằng Chrome trên production, 20/20 tile. Bản đồ vẫn có pin,
// có nút, chỉ thiếu ảnh nền, nên sự cố này **trông như đang chạy**. Thêm nữa
// tile server của OSM là hạ tầng cộng đồng, không dành cho ứng dụng thương
// mại có lưu lượng.
//
// **Trưa: bỏ Carto**, và đây là lỗi đáng ghi lại. `basemaps.cartocdn.com`
// nay bắt buộc API key, nhưng nó **không trả 403** — nó trả **HTTP 200** kèm
// một PNG 2.049 byte in chữ "API KEY REQUIRED" chéo mặt tile. Phép đo lúc
// chọn nguồn chỉ nhìn mã trạng thái và thời gian phản hồi, nên Carto trông
// như nguồn nhanh nhất; mười hai bản đồ lên production với chữ đó trên nền.
//
// Và `themLopNen()` dưới đây **không cứu được**: nó nghe `tileerror`, mà một
// phản hồi 200 kèm ảnh hợp lệ thì không phải lỗi. Dự phòng chống được nguồn
// chết, không chống được nguồn trả rác — lớp chống rác là
// `php artisan map:kiem-tile`, nó so kích thước một tile đã biết.
//
// **Chiều: sang MapTiler**, theo quyết định của chủ dự án — một tài khoản có
// hạn mức rõ ràng thay cho hai nguồn dùng theo lệ. Esri ở lại làm nguồn không
// khóa và làm dự phòng.
//
// ══ Khóa MapTiler nằm trong bundle của trình duyệt, và không tránh được ══
//
// `NEXT_PUBLIC_*` nghĩa là giá trị được nhúng thẳng vào JS gửi cho khách, vì
// chính trình duyệt gọi URL tile. Mọi khóa tile client-side đều vậy, kể cả
// khóa của Google hay Mapbox. Thứ bảo vệ hạn mức không phải sự bí mật mà là
// **giới hạn origin** đặt trong MapTiler Cloud: chỉ cho phép `oohx.net` và
// `dash.oohx.net`.
//
// Không có khóa thì mọi thứ lùi về Esri, nên máy lập trình và CI chạy được
// mà không cần khóa, và một khóa gõ sai ở production cho bản đồ Esri chứ
// không cho nền xám.
//
// ══ Nhãn tiếng Anh: đánh đổi đã biết ══
//
// Raster tile của MapTiler render sẵn nhãn **tiếng Anh** — "West Lake",
// "Red River", "Tu Lien Bridge". Đã thử `?language=vi` và `?lang=vi`: cùng
// trả đúng 82.078 byte, tức không tác dụng. Muốn tiếng Việt thì tạo style
// riêng trong MapTiler Cloud với ngôn ngữ nhãn Vietnamese rồi đặt tên style
// vào `NEXT_PUBLIC_MAPTILER_STYLE` — không phải sửa mã.

const MAPTILER_KEY = process.env.NEXT_PUBLIC_MAPTILER_KEY ?? '';

// `||` chứ KHÔNG `??`, và đây là một lỗi đã xảy ra thật: `??` chỉ bắt
// `undefined`, không bắt chuỗi rỗng. `deploy.sh` export biến này từ một
// secret chưa được đặt, nên nó là `''`, và URL thành
// `api.maptiler.com/maps//13/…` — hai dấu gạch chéo, style rỗng, 503 ở
// mọi ô. Trang không trắng vì dự phòng Esri nhảy vào; chỉ ai đọc tab
// Network mới thấy MapTiler chưa hề chạy.
const MAPTILER_STYLE = process.env.NEXT_PUBLIC_MAPTILER_STYLE || 'streets-v2';

const MAPTILER_URL =
    `https://api.maptiler.com/maps/${MAPTILER_STYLE}/{z}/{x}/{y}.png?key={key}`;

const ESRI_URL =
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}';

export const TILE_KEY = MAPTILER_KEY;

export const TILE_URL = MAPTILER_KEY === '' ? ESRI_URL : MAPTILER_URL;

// Esri không dùng `{s}`; hằng này chỉ có tác dụng nếu đổi sang nguồn có nó.
export const TILE_SUBDOMAINS = 'abc';

const GHI_CONG_MAPTILER =
    '© <a href="https://www.maptiler.com/copyright/">MapTiler</a> · © <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>';

const GHI_CONG_ESRI =
    '© <a href="https://www.esri.com">Esri</a> · nguồn: Esri, HERE, Garmin, © <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>';

export const TILE_ATTRIBUTION =
    MAPTILER_KEY === '' ? GHI_CONG_ESRI : GHI_CONG_MAPTILER;

// Khác nhà với MapTiler và không cần khóa, nên nó đỡ được cả hai kiểu hỏng
// của nguồn chính: nhà cung cấp sập, hoặc hạn mức 100.000 tile/tháng cạn
// giữa tháng.
export const TILE_FALLBACK_URL = ESRI_URL;

export const TILE_FALLBACK_ATTRIBUTION = GHI_CONG_ESRI;

export const TILE_MAX_ZOOM = 19;

/**
 * Thêm lớp ảnh nền vào bản đồ, kèm **một lần** tự chuyển sang nguồn dự phòng.
 *
 * Lần hỏng 18/10 cho thấy một điều: khi tile chết, bản đồ trông như đang chạy
 * — có pin, có nút, chỉ thiếu nền — nên không ai báo lỗi và nó sống nhiều
 * ngày. Một nguồn thứ hai tự nhảy vào biến sự cố đó thành thứ người dùng
 * không phải thấy.
 *
 * Chỉ chuyển **một lần**, và chỉ khi đủ `NGUONG_LOI` ô ảnh hỏng: một tile lỗi
 * lẻ là chuyện thường (ô biển, ô ngoài vùng phủ), và đổi cả lớp nền vì một ô
 * là một bản đồ nhấp nháy.
 *
 * ══ Hàm này KHÔNG cứu được gì, nói thẳng ══
 *
 * Nó nghe `tileerror`. Một nguồn đòi API key mà trả **HTTP 200 kèm ảnh
 * watermark** — đúng chuyện Carto làm trưa 18/10 — không bắn `tileerror` lần
 * nào, nên lớp này đứng yên trong khi mười hai bản đồ hiện chữ "API KEY
 * REQUIRED". Đừng tin nó là lớp canh chất lượng ảnh nền.
 *
 * Lớp bắt được kiểu đó là `php artisan map:kiem-tile`: nó tải một tile đã
 * biết và so **kích thước**, vì rác và bản đồ khác nhau ở số byte chứ không
 * khác ở mã trạng thái.
 *
 * @param L  Module leaflet đã lấy `.default`
 */
export function themLopNen(
    L: typeof import('leaflet'),
    map: import('leaflet').Map,
): void {
    const NGUONG_LOI = 4;
    let loi = 0;
    let daChuyen = false;

    const chinh = L.tileLayer(TILE_URL, {
        attribution: TILE_ATTRIBUTION,
        subdomains: TILE_SUBDOMAINS,
        maxZoom: TILE_MAX_ZOOM,
        // Leaflet thay mọi `{tên}` trong URL bằng option cùng tên, nên
        // `{key}` của MapTiler chỉ cần dòng này. Esri không có chỗ thay đó
        // và bỏ qua option thừa.
        key: TILE_KEY,
    } as L.TileLayerOptions);

    chinh.on('tileerror', () => {
        loi += 1;

        if (daChuyen || loi < NGUONG_LOI) return;

        daChuyen = true;
        map.removeLayer(chinh);

        L.tileLayer(TILE_FALLBACK_URL, {
            attribution: TILE_FALLBACK_ATTRIBUTION,
            maxZoom: TILE_MAX_ZOOM,
        }).addTo(map);
    });

    chinh.addTo(map);
}
