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
