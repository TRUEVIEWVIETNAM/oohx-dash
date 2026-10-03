import Link from 'next/link';
import type { ScreenSummary } from '@/lib/api';

/**
 * Thẻ một màn hình trong danh sách.
 *
 * Dùng **đúng tên class** của `resources/views/frontpage/partials/screen-card.blade.php`
 * (`sc-photo`, `sc-body`, `sc-title`…), nên stylesheet dùng chung áp vào mà
 * không cần CSS mới. Đổi tên class ở đây là tự tạo một giao diện thứ hai.
 *
 * ══ Không có badge "Còn trống" ══
 *
 * Bản Blade chỉ in badge khi người gọi truyền dữ liệu suất vào, và
 * `NoFabricatedMetricsTest` canh đúng điều đó: thiếu dữ liệu thì không nói gì,
 * chứ không mặc định là còn trống (audit F-15). `/api/v2/screens` **không** trả
 * thông tin suất — nó chỉ có ở `/api/v2/screens/{slug}` — nên ở đây không có gì
 * để in, và in bừa là lặp lại đúng lỗi vừa dọn.
 */
export function ScreenCard({ screen }: { screen: ScreenSummary }) {
    const price = formatPrice(screen);

    return (
        <article className="sc">
            <Link href={`/explore/${screen.slug}`} className="sc-photo">
                {screen.photo_url ? (
                    // Dùng `<img>` chứ không `next/image`: ảnh do media owner
                    // tải lên và phục vụ từ Laravel trên cùng tên miền. Cho
                    // Next tối ưu lại là thêm một tầng xử lý ảnh cho thứ đã
                    // được phục vụ xong, và là một đường tải ảnh hộ nếu
                    // `remotePatterns` nới ra sau này.
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={screen.photo_url} alt={screen.name ?? ''} loading="lazy" />
                ) : null}
            </Link>

            <div className="sc-body">
                <div className="sc-breadcrumb">
                    {screen.location?.city ? <span>{screen.location.city}</span> : null}
                    {screen.location?.city && screen.location?.district ? (
                        <span className="sc-bc-sep">·</span>
                    ) : null}
                    {screen.location?.district ? <span>{screen.location.district}</span> : null}
                </div>

                <h3 className="sc-title">
                    <Link href={`/explore/${screen.slug}`}>{screen.name}</Link>
                </h3>

                {screen.location?.site ? (
                    <div className="sc-loc">
                        <span className="sc-loc-icon" aria-hidden="true" />
                        {screen.location.site}
                    </div>
                ) : null}

                <div className="sc-specs">
                    {screen.screen_type ? (
                        <div className="sc-spec">
                            <span className="sc-spec-l">Loại</span>
                            <span className="sc-spec-v">{screen.screen_type}</span>
                        </div>
                    ) : null}

                    {screen.size?.width_m && screen.size?.height_m ? (
                        <div className="sc-spec">
                            <span className="sc-spec-l">Kích thước</span>
                            <span className="sc-spec-v">
                                {screen.size.width_m}×{screen.size.height_m}m
                            </span>
                        </div>
                    ) : null}
                </div>

                <div className="sc-foot">
                    {price ? (
                        <div className="sc-price">
                            {price}
                            <span className="sc-price-sub">chưa gồm VAT</span>
                        </div>
                    ) : (
                        <div className="sc-price-quote">Liên hệ báo giá</div>
                    )}

                    <Link href={`/explore/${screen.slug}`} className="btn btn-p btn-sm sc-btn">
                        Xem chi tiết
                    </Link>
                </div>
            </div>
        </article>
    );
}

/**
 * Giá niêm yết, định dạng theo tiếng Việt.
 *
 * Trả `null` khi không có giá — chỗ gọi hiện "Liên hệ báo giá" thay vì in `0 ₫`.
 * In số 0 là nói sai: nó đọc như miễn phí.
 *
 * **Không quy đổi tiền tệ ở đây.** `floor_cpm.currency` có thể không phải VND,
 * và tự quy đổi là đặt ra một chính sách tỷ giá mà không ai duyệt — cùng lý do
 * `CartService` chặn đặt trực tuyến với giá ngoài VND. Nên in kèm đúng đơn vị
 * mà API trả về.
 */
function formatPrice(screen: ScreenSummary): string | null {
    const io = screen.pricing?.io_rate;

    if (io?.amount) {
        return `${formatAmount(io.amount, io.currency)}/${unitLabel(io.unit)}`;
    }

    const cpm = screen.pricing?.floor_cpm;

    if (cpm?.amount) {
        return `${formatAmount(cpm.amount, cpm.currency)}/1.000 lượt`;
    }

    return null;
}

function formatAmount(amount: number, currency?: string | null): string {
    const code = currency || 'VND';

    if (code === 'VND') {
        return `${new Intl.NumberFormat('vi-VN').format(amount)} ₫`;
    }

    return `${new Intl.NumberFormat('vi-VN').format(amount)} ${code}`;
}

function unitLabel(unit?: string | null): string {
    switch (unit) {
        case 'month':
            return 'tháng';
        case 'week':
            return 'tuần';
        case 'day':
            return 'ngày';
        default:
            // Đơn vị lạ thì in nguyên văn, không đoán. Đoán sai ở chỗ này là
            // nói sai về giá.
            return unit || 'kỳ';
    }
}
