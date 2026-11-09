import { AppLink } from './AppLink';
import { CONG_TY as CO, hotlineHienThi } from '@/lib/company';

/**
 * Chân trang — bốn cột liên kết cạnh một cột pháp nhân, nền tối.
 *
 * Dựng lại theo board `P-TrangChu` của mockup đã chốt (08/11/2026) — lát 2 của
 * `docs/PLAN-GHEP-MOCKUP.md`.
 *
 * ══ Khối pháp lý là phần KHÔNG được rút gọn theo board ══
 *
 * Board in bốn thứ: tên công ty, mã số thuế, địa chỉ, hotline. Hồ sơ Bộ Công
 * Thương đòi thêm **ngày và cơ quan cấp** mã số doanh nghiệp, **người đại diện
 * theo pháp luật**, và **đầu mối được uỷ quyền phối hợp với cơ quan nhà nước**.
 * Bỏ ba thứ đó cho chân trang gọn hơn là bỏ thông tin bắt buộc của một sàn
 * TMĐT — nên cột pháp nhân ở đây dài hơn board vẽ, và đó là cố ý.
 *
 * Giá trị lấy từ `lib/company.ts` — một chỗ cho cả chân trang, dải pháp lý ở
 * thanh đầu, và ô cảnh báo bản nháp của trang chính sách.
 *
 * ══ Mục không có đích thì BỎ, không trỏ bừa ══
 *
 * Luật này có từ lần dựng trước và lát này giữ nguyên. Bản Blade có
 * `<a href="#">Hà Nội</a>`, `<a href="#">API</a>`… — liên kết không đi đâu,
 * đúng loại lỗi audit F-15 ("nút CTA không có hành vi").
 *
 * Board thêm vào bốn mục nữa không có đích, và cả bốn đều bị bỏ ở đây:
 *
 * | Board vẽ | Vì sao bỏ |
 * |---|---|
 * | `Về OOHX` | Không có trang giới thiệu |
 * | `Quy trình đặt chỗ sáu bước` | Không có trang mô tả quy trình. Board trỏ nó về trang chính sách, tức một trang nói chuyện khác |
 * | `Chất lượng dữ liệu kho` | Board `P-DuLieuThat` chưa có trang thật tương ứng |
 * | `Biên bản mẫu` | Board `BienBan` là một bước TRONG đơn hàng, không phải trang công khai xem được khi chưa mua |
 *
 * Hai mục board vẽ mà có đích thật thì giữ, và chúng là hai mục mới so với
 * bản trước: `Khu quản lý chiến dịch` (`/my/campaigns`) và `Khu quản lý kho`
 * (`/publisher`) — hai khu đã chạy, trước nay không có đường nào từ trang công
 * khai dẫn vào.
 *
 * ══ Dòng cuối của board KHÔNG ship ══
 *
 * Board viết *"Mockup để duyệt luồng — dữ liệu thật ngày 08/11/2026, giao diện
 * chưa phải bản cuối. Bản đồ chờ gắn BizmapLive."* Đó là chữ của bản nháp nói
 * với người duyệt mockup, không phải chữ nói với người mua.
 */

/** Cột "Người mua". */
const NGUOI_MUA: [string, string][] = [
    ['/explore', 'Toàn bộ vị trí'],
    ['/products', 'Gói chiến dịch'],
    // `/explore?view=map`, không `/map`: đường cũ chỉ còn là chuyển hướng 301.
    //
    // Giữ mục ở chân trang dù thanh đầu hàng hai cũng có: chân trang là nơi
    // người ta tìm một đường CỤ THỂ khi điều hướng trên cùng không có nó, và
    // dưới 768px hàng hai ẩn.
    ['/explore?view=map', 'Bản đồ vị trí'],
    ['/explore/yeu-cau-bao-gia', 'Yêu cầu báo giá'],
    // Khu người mua — do Laravel phục vụ, `AppLink` tự chọn `<a>`.
    ['/my/campaigns', 'Khu quản lý chiến dịch'],
];

/** Cột "Media owner". */
const MEDIA_OWNER: [string, string][] = [
    ['/owners', 'Danh sách đối tác'],
    ['/agency', 'Danh sách đại lý'],
    ['/register', 'Đăng ký cung cấp vị trí'],
    // Panel Filament. Chưa theme theo mockup (lát 5), nhưng nó CHẠY — và một
    // khu đang chạy mà không có đường nào dẫn vào là một khu không ai tìm thấy.
    ['/publisher', 'Khu quản lý kho'],
];

/** Cột "Mua bán an toàn" — chỉ những mục có trang thật. */
const AN_TOAN: [string, string][] = [
    ['/quy-che-hoat-dong', 'Vì sao sàn không giữ tiền'],
    ['/giai-quyet-tranh-chap', 'Giải quyết tranh chấp, khiếu nại'],
    ['/bang-phi', 'Bảng phí dịch vụ'],
];

/** Cột "Pháp lý". */
const PHAP_LY: [string, string][] = [
    ['/quy-che-hoat-dong', 'Quy chế hoạt động'],
    ['/chinh-sach-bao-mat', 'Chính sách bảo mật'],
    ['/phan-anh-to-chuc-xa-hoi', 'Tiếp nhận phản ánh của TCXH'],
    ['/phan-anh-to-chuc-xa-hoi/danh-sach', 'Danh sách phản ánh của TCXH'],
];

const COT: { ten: string; muc: [string, string][] }[] = [
    { ten: 'Người mua', muc: NGUOI_MUA },
    { ten: 'Media owner', muc: MEDIA_OWNER },
    { ten: 'Mua bán an toàn', muc: AN_TOAN },
    { ten: 'Pháp lý', muc: PHAP_LY },
];

export function SiteFooter() {
    return (
        // `.v2` bọc chính chân trang — cùng lý do như `SiteHeader`.
        <div className="v2">
            <footer className="ch-ft">
                <div className="wrap ch-ft-luoi">
                    <div className="ch-ft-br">
                        <div className="ch-ft-logo">
                            {/*
                              Mark + chữ, không phải `LOGO_DATA_URI`: nền tối và
                              logo là PNG màu không xác định. Xem `.ch-mark`
                              trong `oohx.css`.
                            */}
                            <span className="ch-mark" aria-hidden="true">
                                <span className="ms ms-f">tv_signin</span>
                            </span>
                            <span className="ch-ten">OOHX</span>
                        </div>

                        {/*
                          Câu mô tả sàn — nguyên văn board, và nó nói đúng cơ
                          chế: sàn không thu hộ, người mua chuyển khoản trực
                          tiếp cho từng media owner theo từng đơn đã nghiệm thu.
                          Đó là lý do `GET /api/v2/campaigns/{campaign}/payment-recipients`
                          tồn tại, và là ngoại lệ duy nhất được duyệt cho việc
                          trả `bank_*` ra ngoài (CLAUDE.md §2).
                        */}
                        <p className="ch-ft-mo">
                            Sàn giao dịch vị trí quảng cáo ngoài trời. Sàn không thu hộ tiền:
                            người mua chuyển khoản trực tiếp cho từng media owner theo từng đơn
                            đã nghiệm thu.
                        </p>

                        <div className="ch-ft-phaply">
                            <div className="ch-ft-ten">{CO.legalName}</div>
                            <p>
                                Mã số doanh nghiệp {CO.businessCode} do {CO.businessCodeBy} cấp
                                ngày {CO.businessCodeOn}
                            </p>
                            <p>Địa chỉ: {CO.address}</p>
                            <p>Người đại diện theo pháp luật: {CO.legalRep}</p>
                            <p>
                                Đầu mối liên hệ, người đại diện được ủy quyền phối hợp với cơ
                                quan nhà nước có thẩm quyền: ông {CO.authorizedContact}
                            </p>
                            <p>
                                Hotline:{' '}
                                <a href={`tel:${CO.hotline.replace(/\D/g, '')}`}>
                                    {hotlineHienThi()}
                                </a>
                            </p>
                            <p>
                                Email: <a href={`mailto:${CO.email}`}>{CO.email}</a>
                            </p>
                        </div>
                    </div>

                    {COT.map((cot) => (
                        <div className="ch-ft-col" key={cot.ten}>
                            {/*
                              `<h4>`, không `<p>`. Board vẽ nhãn cột bằng chữ
                              nhỏ hoa (`.lbl`) và tôi viết nó thành `<p>` ở bản
                              đầu — mất ngữ nghĩa tiêu đề, nên trình đọc màn
                              hình không còn nhảy được giữa bốn cột. `.lbl` lo
                              phần hình; thẻ lo phần nghĩa.
                            */}
                            <h4 className="lbl">{cot.ten}</h4>
                            <ul>
                                {cot.muc.map(([href, nhan]) => (
                                    <li key={href + nhan}>
                                        <AppLink href={href}>{nhan}</AppLink>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>

                <div className="ch-ft-btm">
                    <div className="wrap">
                        <p>Bản quyền &copy; {new Date().getFullYear()} OOHX. Bảo lưu mọi quyền.</p>
                        <nav>
                            <AppLink href="/quy-che-hoat-dong">Quy chế hoạt động</AppLink>
                            <AppLink href="/chinh-sach-bao-mat">Chính sách bảo mật</AppLink>
                            <AppLink href="/phan-anh-to-chuc-xa-hoi">Gửi phản ánh</AppLink>
                        </nav>
                    </div>
                </div>
            </footer>
        </div>
    );
}
