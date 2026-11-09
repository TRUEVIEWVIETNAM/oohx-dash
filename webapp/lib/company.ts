/**
 * Thông tin đơn vị đăng ký sàn với Bộ Công Thương.
 *
 * ══ Vì sao tách khỏi `SiteFooter` ══
 *
 * Chân trang cần khối pháp lý; khung trang chính sách cần email và hotline cho
 * ô cảnh báo bản nháp (`<x-policy-shell>` của Blade lấy từ
 * `config('policies.company')`). Hai chỗ dùng cùng một dữ liệu, nên nó nằm ở
 * một chỗ — chép sang component thứ hai là tạo bản thứ hai của thông tin pháp
 * nhân, và hai bản sẽ lệch khi công ty đổi địa chỉ hoặc số điện thoại.
 *
 * ══ Nguồn sự thật vẫn là Laravel ══
 *
 * `config/policies.php` là nguồn. Các biến môi trường dưới đây là đường truyền
 * sang Next; giá trị mặc định chỉ là chỗ đỡ khi biến chưa được đặt.
 *
 * Nói thẳng trạng thái hôm nay: unit systemd
 * (`docs/deploy/nextjs-proxy/oohx-webapp.service`) **chưa** đặt các biến này,
 * nên trên production đang chạy chính các giá trị mặc định ở đây. Chúng khớp
 * với `config/policies.php` tại thời điểm viết, nhưng khớp vì có người chép
 * tay — không vì có gì ép chúng khớp. Đổi thông tin pháp nhân thì phải sửa cả
 * hai chỗ, cho tới khi unit truyền biến sang.
 */
export const CONG_TY = {
    legalName: process.env.OOHX_CO_LEGAL_NAME ?? 'CÔNG TY TNHH TRUEVIEW',
    businessCode: process.env.OOHX_CO_BUSINESS_CODE ?? '0109944503',
    businessCodeBy: process.env.OOHX_CO_BUSINESS_CODE_BY ?? 'Sở Tài Chính thành phố Hà Nội',
    businessCodeOn: process.env.OOHX_CO_BUSINESS_CODE_ON ?? '24/3/2022',
    address:
        process.env.OOHX_CO_ADDRESS ??
        'Số 110 đường Lạc Long Quân, Phường Tây Hồ, Thành phố Hà Nội, Việt Nam.',
    legalRep: process.env.OOHX_CO_LEGAL_REP ?? 'NGUYỄN ANH TUẤN',
    authorizedContact: process.env.OOHX_CO_AUTHORIZED_CONTACT ?? 'NGUYỄN ANH TUẤN',
    hotline: process.env.OOHX_CO_HOTLINE ?? '0943668996',
    email: process.env.OOHX_CO_EMAIL ?? 'tuan.nguyen@attvietnam.vn',
} as const;

/**
 * Hotline dạng người đọc: `0943668996` → `0943 668 996`.
 *
 * ══ Vì sao là một hàm, không phải một hằng thứ hai ══
 *
 * `hotline` lưu dạng chỉ-số vì `tel:` cần đúng dạng đó. Dải pháp lý ở thanh
 * đầu cần dạng có khoảng trắng — mười chữ số liền nhau ở cỡ 11,5px thì không
 * đọc được bằng mắt.
 *
 * Thêm một khoá `hotlineHienThi: '0943 668 996'` vào `CONG_TY` là tạo bản thứ
 * hai của cùng một số: đổi số ở một khoá mà quên khoá kia thì trang in một số
 * còn `tel:` gọi một số khác, và không có gì báo. Một hàm thì không có bản thứ
 * hai nào để lệch.
 *
 * Số không khớp dạng 10 chữ số trả về NGUYÊN VĂN, không cố cắt bừa: một số
 * nước ngoài hay số 11 chữ số bị chèn khoảng trắng sai chỗ thì khó đọc hơn là
 * để liền.
 */
export function hotlineHienThi(so: string = CONG_TY.hotline): string {
    const chiSo = so.replace(/\D/g, '');

    if (chiSo.length !== 10) return so;

    return `${chiSo.slice(0, 4)} ${chiSo.slice(4, 7)} ${chiSo.slice(7)}`;
}
