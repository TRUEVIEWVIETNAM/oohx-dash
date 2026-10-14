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
