import Link from 'next/link';
import type { components } from '@api-types';

export type ProductSummary = components['schemas']['ProductSummary'];

/**
 * Chữ và màu cho `products.type`.
 *
 * Chép đúng bản Blade (`products/partials/product-card.blade.php`). Màu đi
 * trong `style` chứ không thành class: bản Blade cũng vậy, và `frontpage.css`
 * không có lớp màu nào cho ba giá trị này.
 */
const KIEU_GOI: Record<string, { nhan: string; mau: string }> = {
    single: { nhan: 'Đơn lẻ', mau: 'var(--bl)' },
    package: { nhan: 'Gói', mau: 'var(--grn)' },
    custom: { nhan: 'Combo', mau: 'var(--org)' },
};

/**
 * Thẻ một gói sản phẩm.
 *
 * ══ Vì sao viết lại 14/10/2026 ══
 *
 * Bản trước mở bằng `<article className="sc">` và dùng tiếp bộ `sc-*` — tức bộ
 * class của **thẻ màn hình**, và `sc` lại là một ô bảng thông số. Bản Blade có
 * bộ riêng cho gói: `pd-card`, `pd-photo`, `pd-badges`, `pd-units`, `pd-body`,
 * `pd-name`, `pd-owner`, `pd-loc`, `pd-desc`, `pd-foot`, `pd-price`, `pd-btn`.
 * Chúng vẫn nằm đủ trong `frontpage.css`.
 *
 * Hai thứ bản Blade in mà DTO không có: `category_label` làm chữ cho ảnh thay
 * thế, và `price_display` (chuỗi đã định dạng ở phía PHP). Giá ở đây tự định
 * dạng — xem `formatProductPrice()` về việc vì sao không in `0 ₫`.
 */
export function ProductCard({ product }: { product: ProductSummary }) {
    const kieu = product.type ? KIEU_GOI[product.type] : undefined;
    const gia = formatProductPrice(product);
    const laGoi = product.type === 'package';
    const soViTri = product.quantity?.total_units ?? 0;

    return (
        <Link href={`/products/${product.slug}`} className="pd-card">
            <div className="pd-photo">
                {product.cover_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={product.cover_url} alt={product.name ?? ''} loading="lazy" />
                ) : null}

                <div className="pd-badges">
                    {product.type ? (
                        <span
                            className="pd-badge"
                            style={{ background: kieu?.mau ?? 'var(--bl)' }}
                        >
                            {kieu?.nhan ?? product.type}
                        </span>
                    ) : null}

                    {/*
                      `category` là một đối tượng `{code, label}`, không phải
                      chuỗi — in `label`, không in cả khối. Type checker bắt
                      được chỗ này; nếu không thì trang hiện `[object Object]`.
                    */}
                    {product.category?.label ? (
                        <span className="pd-badge pd-badge-cat">{product.category.label}</span>
                    ) : null}
                </div>

                {/*
                  `quantity.total_units` là số **suất bán**, khác
                  `quantity.screen_count` là số màn hình. Bản Blade in số suất ở
                  đây, nên giữ đúng con số đó.
                */}
                {laGoi && soViTri > 1 ? <div className="pd-units">{soViTri} vị trí</div> : null}
            </div>

            <div className="pd-body">
                <div className="pd-name">{product.name}</div>

                {product.owner?.name ? <div className="pd-owner">{product.owner.name}</div> : null}

                {product.location?.city ? (
                    <div className="pd-loc">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" />
                        </svg>
                        {product.location.city}
                    </div>
                ) : null}

                {product.short_description ? (
                    <div className="pd-desc">{product.short_description}</div>
                ) : null}
            </div>

            <div className="pd-foot">
                <div>
                    <div className="pd-price">{gia ?? 'Liên hệ báo giá'}</div>
                </div>
                <span className="btn btn-p btn-sm pd-btn">{laGoi ? 'Chọn gói' : 'Chi tiết'}</span>
            </div>
        </Link>
    );
}

/**
 * Giá gói, hoặc `null` khi không có.
 *
 * Trả `null` thay vì `0 ₫`: in số 0 đọc như miễn phí. Cùng lý do bỏ `offers`
 * khỏi JSON-LD khi chưa có giá.
 *
 * Ưu tiên `package` (giá cả gói) trước `per_screen` (giá mua lẻ một màn
 * hình): thẻ này giới thiệu một GÓI, nên con số lớn hiện trước là con số người
 * mua sẽ trả. In giá lẻ mà không nói rõ là giá lẻ thì người đọc hiểu đó là giá
 * gói — rẻ hơn thật nhiều lần.
 *
 * KHÔNG quy đổi tiền tệ. `currency` lấy từ cột `products.currency` và có thể
 * không phải VND; tự quy đổi ở tầng hiển thị là đặt ra một chính sách tỷ giá
 * mà không ai duyệt — cùng lý do `CartService` chặn đặt trực tuyến với giá
 * ngoài VND.
 *
 * ══ Đơn vị dịch sang tiếng Việt (18/10/2026) ══
 *
 * Hàm này in thẳng mã enum của API: `250.000.000 ₫/week` trên một trang mà
 * CLAUDE.md quy định tiếng Việt là mặc định. Không ai thấy vì nó chỉ xuất
 * hiện ở `/products`, và kho hôm nay có một gói; lát 3 đưa nó lên trang chủ
 * nên nó thành chữ người đọc đầu tiên gặp.
 *
 * Bảng tra chứ không `switch`: thiếu một khoá thì in lại chính mã, không ném
 * lỗi và không nuốt mất đơn vị. Bốn mã lấy từ enum `pricing.unit` trong
 * OpenAPI; thêm mã thứ năm ở đó mà quên ở đây thì người đọc thấy mã tiếng
 * Anh — xấu, nhưng không sai.
 */
const DON_VI: Record<string, string> = {
    day: 'ngày',
    week: 'tuần',
    month: 'tháng',
    campaign: 'chiến dịch',
};

export function formatProductPrice(product: ProductSummary): string | null {
    const pricing = product.pricing;
    const amount = pricing?.package ?? pricing?.per_screen;

    if (!amount) return null;

    const laGiaLe = !pricing?.package && !!pricing?.per_screen;
    const currency = pricing?.currency || 'VND';
    const formatted = new Intl.NumberFormat('vi-VN').format(amount);

    const price = currency === 'VND' ? formatted + ' ₫' : formatted + ' ' + currency;
    const withUnit = pricing?.unit
        ? price + '/' + (DON_VI[pricing.unit] ?? pricing.unit)
        : price;

    return laGiaLe ? withUnit + ' mỗi màn hình' : withUnit;
}
