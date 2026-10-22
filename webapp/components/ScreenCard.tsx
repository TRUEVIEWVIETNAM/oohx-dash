import Link from 'next/link';
import type { ScreenSummary } from '@/lib/api';
import { OTick } from '@/components/OTick';

/**
 * Thẻ một màn hình trong danh sách.
 *
 * ══ Vì sao tệp này được viết lại ngày 14/10/2026 ══
 *
 * Bản trước mở bằng `<article className="sc">`. Nhưng trong `frontpage.css`,
 * `.sc` (dòng 1330) là **một ô trong bảng thông số** — `padding` + viền phải +
 * viền dưới + `:nth-child` để ghép thành bảng — còn thẻ màn hình là `.sc-card`
 * (dòng 1939): bo góc 14px, `box-shadow`, `flex-direction:column`,
 * `overflow:hidden`, và `:hover` nâng thẻ 4px kèm phóng ảnh.
 *
 * Nên mọi thẻ màn hình trên production mang kiểu của một ô bảng. Bản Blade
 * (`partials/screen-card.blade.php`) dùng `sc-card`, và tệp này nay dùng lại
 * đúng cấu trúc đó.
 *
 * ══ Ba chỗ cấu trúc, không chỉ một tên class ══
 *
 *  1. `.sc-foot` là **con trực tiếp** của `.sc-card`, không nằm trong
 *     `.sc-body`. Nó có `border-top` và `margin-top:12px`, và `.sc-body` có
 *     `padding:14px 14px 0` — lồng vào trong thì viền không chạy hết chiều
 *     ngang thẻ và lề bị đếm hai lần.
 *  2. `.sc-price-sub` là **div em** của `.sc-price`, không phải `<span>` bên
 *     trong: nó có `margin-top:1px`, mà lề trên của một inline span thì không
 *     có tác dụng.
 *  3. Cả thẻ là **một** liên kết. `.sc-card` khai `text-decoration:none`,
 *     `color:inherit`, `cursor:pointer` — nó được viết cho một `<a>`. Nút
 *     "Chi tiết" vì thế là `<span class="btn">`, không phải `<Link>` lồng
 *     trong `<Link>` (HTML không cho phép, và React sẽ cảnh báo).
 *
 * ══ Badge "Còn trống" chỉ hiện khi CÓ dữ liệu suất ══
 *
 * `availability` là prop **tuỳ chọn**, mặc định không có. Bản Blade cũng vậy:
 * nó chỉ in badge khi người gọi truyền mảng suất vào, và
 * `NoFabricatedMetricsTest` canh đúng điều đó — thiếu dữ liệu thì không nói gì,
 * chứ không mặc định là còn trống (audit F-15).
 *
 * Thực tế hiện nay: `/api/v2/screens`, `/api/v2/owners/{slug}` và
 * `/api/v2/products/{slug}` **không** trả suất, nên thẻ ở các trang đó không có
 * badge. Chỉ `/api/v2/screens/featured` trả, nên chỉ trang chủ có.
 *
 * Và `availability` có thể là `null` ngay cả khi endpoint trả nó: `null` nghĩa
 * là **chưa biết**, khác `remaining_sov_pct: 0` nghĩa là **đã đặt kín**. Gộp
 * hai thứ đó là cách in badge "còn trống" cho một màn hình đã bán kín.
 *
 * Chữ trên badge giữ đúng bản Blade — "Còn trống". Con số phần trăm đi vào
 * `title` chứ không vào thân badge: `.sc-badge` là một viên thuốc nhỏ ở góc
 * ảnh, và "Còn 100% trong 30 ngày" làm nó tràn.
 *
 * ══ `sc-compact` ══
 *
 * Biến thể của bản Blade cho băng trượt "nổi bật" và khối "màn hình gần đó":
 * ảnh thấp hơn, một dòng tiêu đề, không có bảng thông số, ẩn nút. CSS đã có
 * sẵn (`.sc-compact *`), nên đây chỉ là một cờ.
 */
type Availability = {
    window_days?: number;
    remaining_sov_pct?: number;
    has_capacity?: boolean;
} | null;

export function ScreenCard({
    screen,
    availability,
    compact = false,
    choPhepChon = false,
}: {
    screen: ScreenSummary;
    availability?: Availability;
    compact?: boolean;

    /**
     * Hiện ô tick thêm vào danh sách rút gọn (lát 4).
     *
     * Mặc định **tắt**, và bật riêng ở `/explore`. Danh sách rút gọn là để
     * khoanh vùng khi đang duyệt kho; một ô tick trên băng "nổi bật" của
     * trang chủ hay trên thẻ trong trang chi tiết media owner chỉ là một ô
     * tick không ai hiểu để làm gì.
     */
    choPhepChon?: boolean;
}) {
    const price = formatPrice(screen);
    const thongSo = dungThongSo(screen);
    const href = `/explore/${screen.slug}`;
    const networkName = screen.network?.name;
    const siteName = screen.location?.site;

    return (
        /*
         * `data-slug`: cách bản đồ của `/explore` tìm ra thẻ tương ứng với
         * một pin, và ngược lại (lát 3). Một thuộc tính dữ liệu chứ không
         * phải `id`: cùng một màn hình có thể xuất hiện hai lần trên một
         * trang — trong lưới và trong băng "gần đây" — và hai phần tử cùng
         * `id` là HTML sai.
         */
        <Link
            href={href}
            data-slug={screen.slug}
            className={compact ? 'sc-card sc-compact' : 'sc-card'}
        >
            <div className="sc-photo">
                {choPhepChon ? (
                    /*
                      Bốn trường cuối thêm 22/10/2026 (lát 6): bảng tổng kế
                      hoạch cộng chi phí và đếm độ phủ từ chính danh sách
                      này, không gọi lại API. Thiếu chúng ở MỘT chỗ thêm thì
                      cùng một màn hình khoanh từ thẻ có giá, khoanh từ popup
                      bản đồ không có — và bảng nói hai con số khác nhau tuỳ
                      người dùng bấm ở đâu.
                    */
                    <OTick
                        muc={{
                            slug: screen.slug,
                            ten: screen.name ?? screen.slug,
                            thanhPho: screen.location?.city ?? undefined,
                            owner: screen.owner?.name ?? undefined,
                            quan: screen.location?.district ?? undefined,
                            loaiHinh: screen.screen_type_label ?? undefined,
                            gia: screen.pricing?.io_rate?.amount ?? undefined,
                            ky: screen.pricing?.io_rate?.unit ?? undefined,
                        }}
                        nhan={`Thêm ${screen.name ?? 'vị trí này'} vào danh sách`}
                    />
                ) : null}

                {screen.photo_url ? (
                    // Dùng `<img>` chứ không `next/image`: ảnh do media owner
                    // tải lên và phục vụ từ Laravel trên cùng tên miền. Cho
                    // Next tối ưu lại là thêm một tầng xử lý ảnh cho thứ đã
                    // được phục vụ xong, và là một đường tải ảnh hộ nếu
                    // `remotePatterns` nới ra sau này.
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={screen.photo_url} alt={screen.name ?? ''} loading="lazy" />
                ) : null}

                {availability?.has_capacity ? (
                    <div
                        className="sc-badge"
                        title={
                            availability.remaining_sov_pct !== undefined &&
                            availability.window_days !== undefined
                                ? `Còn ${availability.remaining_sov_pct}% thời lượng trong ${availability.window_days} ngày tới`
                                : undefined
                        }
                    >
                        Còn trống
                    </div>
                ) : null}
            </div>

            <div className="sc-body">
                {!compact && (networkName || siteName) ? (
                    <div className="sc-breadcrumb">
                        {networkName ? <span>{networkName}</span> : null}
                        {networkName && siteName ? <span className="sc-bc-sep">›</span> : null}
                        {siteName ? <span>{siteName}</span> : null}
                    </div>
                ) : null}

                <div className="sc-title">{screen.name}</div>

                {screen.location?.city ? (
                    <div className="sc-loc">
                        <svg
                            viewBox="0 0 24 24"
                            fill="currentColor"
                            className="sc-loc-icon"
                            aria-hidden="true"
                        >
                            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 110-5 2.5 2.5 0 010 5z" />
                        </svg>
                        {screen.location.city}

                        {/*
                          Khoảng cách, chỉ khi API trả về — tức chỉ khi danh
                          sách được sắp theo khoảng cách (22/10/2026). Nó
                          đứng cạnh tên thành phố vì đó là chỗ người đọc đang
                          hỏi *"chỗ này ở đâu"*.

                          "cách", không phải "lái xe": đây là đường chim bay
                          từ toạ độ người dùng tới site, không phải quãng
                          đường đi.
                        */}
                        {typeof screen.distance_km === 'number' ? (
                            <span className="sc-xa">
                                · cách{' '}
                                {new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 1 }).format(
                                    screen.distance_km,
                                )}
                                km
                            </span>
                        ) : null}
                    </div>
                ) : null}

                {!compact && thongSo.length > 0 ? (
                    <div className="sc-specs">
                        {thongSo.map(({ nhan, giaTri }) => (
                            <div className="sc-spec" key={nhan}>
                                <span className="sc-spec-l">{nhan}</span>
                                <span className="sc-spec-v">{giaTri}</span>
                            </div>
                        ))}
                    </div>
                ) : null}
            </div>

            <div className="sc-foot">
                {price ? (
                    <div>
                        <div className="sc-price">
                            {price.amount}
                            <sup>{price.currency}</sup>
                        </div>
                        <div className="sc-price-sub">/ {price.unit}</div>
                    </div>
                ) : (
                    <div className="sc-price-quote">
                        <svg
                            viewBox="0 0 24 24"
                            fill="currentColor"
                            className="sc-quote-ic"
                            aria-hidden="true"
                        >
                            <path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z" />
                        </svg>
                        Liên hệ báo giá
                    </div>
                )}

                <span className="btn btn-p btn-sm sc-btn">Chi tiết</span>
            </div>
        </Link>
    );
}

/**
 * Ba hàng thông số, đã bỏ những hàng không có dữ liệu.
 *
 * ══ Vì sao bỏ hẳn hàng thay vì in "—" ══
 *
 * Soát production 18/10/2026: trên 50 màn hình lấy qua API công khai,
 * **0** cái có kích thước và **0** cái có giá. Thẻ nào cũng in
 * "Kích thước —", và ba hàng thông số thành hai hàng thật cộng một hàng
 * nói rằng chúng tôi không biết.
 *
 * Một dấu gạch không nói gì cho người mua mà vẫn chiếm chỗ, vẫn kéo thẻ
 * dài ra, và trên màn hình điện thoại thì chỗ là thứ đắt nhất. Hàng nào
 * không có số thì không hiện.
 *
 * Giữ `—` ở đâu thì hợp lý: bảng so sánh, nơi các cột phải thẳng hàng với
 * nhau. Thẻ thì không.
 */
function dungThongSo(screen: ScreenSummary): Array<{ nhan: string; giaTri: string }> {
    const ra: Array<{ nhan: string; giaTri: string }> = [];

    if (screen.owner?.name) {
        ra.push({ nhan: 'Chủ sở hữu', giaTri: screen.owner.name });
    }

    // `screen_type_label` do API trả, không hoa hoá mã ở đây: bảng chữ sống
    // ở `ScreenSummaryResource::SCREEN_TYPE_LABELS`. Trước 18/10/2026 thẻ in
    // thẳng `screen.screen_type` nên người dùng thấy chữ `lcd`.
    if (screen.screen_type_label) {
        ra.push({ nhan: 'Loại hình', giaTri: screen.screen_type_label });
    }

    const w = screen.size?.width_m;
    const h = screen.size?.height_m;

    if (w && h) {
        ra.push({ nhan: 'Kích thước', giaTri: `${w}x${h}m` });
    }

    return ra;
}

/**
 * Giá niêm yết, tách thành ba phần vì bản Blade in chúng ở ba chỗ khác nhau:
 * số trong `.sc-price`, đơn vị tiền trong `<sup>`, kỳ tính trong
 * `.sc-price-sub`.
 *
 * Trả `null` khi không có giá — chỗ gọi hiện "Liên hệ báo giá" thay vì in `0 ₫`.
 * In số 0 là nói sai: nó đọc như miễn phí.
 *
 * **Không quy đổi tiền tệ ở đây.** `floor_cpm.currency` có thể không phải VND,
 * và tự quy đổi là đặt ra một chính sách tỷ giá mà không ai duyệt — cùng lý do
 * `CartService` chặn đặt trực tuyến với giá ngoài VND. Nên in kèm đúng đơn vị
 * mà API trả về.
 */
function formatPrice(
    screen: ScreenSummary,
): { amount: string; currency: string; unit: string } | null {
    const io = screen.pricing?.io_rate;

    if (io?.amount) {
        return {
            amount: formatAmount(io.amount, io.currency),
            currency: currencyLabel(io.currency),
            unit: `màn hình/${unitLabel(io.unit)}`,
        };
    }

    const cpm = screen.pricing?.floor_cpm;

    if (cpm?.amount) {
        return {
            amount: formatAmount(cpm.amount, cpm.currency),
            currency: currencyLabel(cpm.currency),
            unit: '1.000 lượt',
        };
    }

    return null;
}

/**
 * Số tiền, không kèm đơn vị: đơn vị đi riêng vào `<sup>`.
 *
 * Hàng ngoài VND in hai số thập phân — bản Blade ghi lại lý do: một màn hình
 * 2,50 USD làm tròn về số nguyên thì hiện ra "2", và đó là một con số giá sai
 * (Codex R39).
 */
function formatAmount(amount: number, currency?: string | null): string {
    const code = currency || 'VND';

    if (code === 'VND') {
        return new Intl.NumberFormat('vi-VN').format(amount);
    }

    return new Intl.NumberFormat('vi-VN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
}

function currencyLabel(currency?: string | null): string {
    const code = currency || 'VND';

    return code === 'VND' ? 'đ' : code;
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
