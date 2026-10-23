import { AppLink } from './AppLink';
import { LOGO_DATA_URI } from '@/lib/logo';
import { CONG_TY as CO } from '@/lib/company';

/**
 * Chân trang, dựng lại từ `resources/views/frontpage/partials/footer.blade.php`
 * và `partials/company-legal.blade.php`.
 *
 * ══ Khối pháp lý là phần KHÔNG được bỏ ══
 *
 * `ft-legal` mang thông tin đơn vị đăng ký sàn với Bộ Công Thương: tên pháp
 * nhân, mã số doanh nghiệp, địa chỉ, người đại diện, đầu mối liên hệ với cơ
 * quan nhà nước. Một trang công khai thiếu khối đó là một trang thiếu thông
 * tin bắt buộc — nên nó phải có trên mọi trang đã chuyển, không phải "làm sau".
 *
 * Giá trị lấy từ `lib/company.ts` — một chỗ cho cả chân trang và ô cảnh báo
 * bản nháp của trang chính sách. Viết cứng ở đây là tạo bản thứ hai của thông
 * tin pháp lý, và hai bản sẽ lệch.
 *
 * ══ Bốn liên kết `href="#"` của bản Blade KHÔNG được chép sang ══
 *
 * Bản Blade có `<a href="#">Hà Nội</a>`, `<a href="#">API</a>`,
 * `<a href="#">So sánh</a>`, `<a href="#">Hỗ trợ</a>`… — liên kết không đi đâu.
 * Đó là cùng loại lỗi audit F-15 ("nút CTA không có hành vi") vừa dọn khỏi
 * trang công khai, và chép chúng sang bản mới là mang theo một khiếm khuyết đã
 * biết.
 *
 * Những mục có đích thật thì giữ. Những mục không có thì bỏ, chứ không trỏ vào
 * `/explore` cho có — một liên kết "API" dẫn tới danh sách màn hình thì sai
 * theo kiểu khác.
 *
 * Hệ quả: cột "Thị trường" và một vài mục trong cột khác **không có** ở bản
 * này. Chân trang hai bản khác nhau cho tới khi bản Blade được dọn nốt.
 */

const CHINH_SACH = [
    ['quy-che-hoat-dong', 'Quy chế hoạt động'],
    ['chinh-sach-bao-mat', 'Chính sách bảo mật'],
    ['giai-quyet-tranh-chap', 'Cơ chế giải quyết tranh chấp, khiếu nại, phản ánh'],
    ['bang-phi', 'Bảng phí dịch vụ'],
] as const;

export function SiteFooter() {
    return (
        <footer className="footer">
            <div className="w">
                {/*
                  `ft-tu-chia` — bản Next có BỐN khối, bản Blade có sáu.
                  `.ft-g` khai sáu cột cứng, nên bốn khối này chỉ dùng 61%
                  chiều rộng và bỏ trống 460px bên phải ở 1440px. Lớp thêm
                  đổi sang flex để lưới chia theo số khối thật. Chi tiết ở
                  `resources/css/frontpage.css`, ngay dưới `.ft-g`.
                */}
                <div className="ft-g ft-tu-chia">
                    <div className="ft-br">
                        <div className="ft-logo">
                            {/* eslint-disable-next-line @next/next/no-img-element */}
                            <img src={LOGO_DATA_URI} alt="OOHX" width={128} height={32} />
                        </div>
                        <p className="ft-desc">
                            OOH/DOOH Intelligence Marketplace. Kết nối agency, brand và media
                            owner tại Việt Nam.
                        </p>

                        <div className="ft-legal">
                            <div className="ft-legal-name">{CO.legalName}</div>
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
                                <a href={`tel:${CO.hotline.replace(/\D/g, '')}`}>{CO.hotline}</a>
                            </p>
                            <p>
                                Email: <a href={`mailto:${CO.email}`}>{CO.email}</a>
                            </p>
                        </div>
                    </div>

                    {/*
                      Hai cột theo VAI, không phải một cột "Khám phá".

                      Sàn phục vụ hai nhóm muốn hai việc ngược nhau: người mua
                      đi tìm chỗ đặt quảng cáo, media owner đi tìm người mua.
                      Gộp họ vào một danh sách liên kết thì không nhóm nào tìm
                      thấy đường của mình — và chân trang là nơi người ta tìm
                      khi thanh đầu không có thứ họ cần.
                    */}
                    <div className="ft-col">
                        <h4>Người mua</h4>
                        <ul className="ft-ls">
                            <li>
                                <AppLink href="/explore">Toàn bộ màn hình</AppLink>
                            </li>
                            <li>
                                <AppLink href="/products">Gói chiến dịch</AppLink>
                            </li>
                            {/*
                              `/explore?view=map`, không `/map`: đường cũ
                              chỉ còn là chuyển hướng 301 từ lát 7, nên mỗi
                              cú bấm ở đây tốn thêm một vòng mạng. Dòng này
                              sót lại khi thanh đầu được sửa ngày 22/10.

                              Giữ mục ở chân trang dù thanh đầu đã bỏ: chân
                              trang là nơi người ta tìm một đường CỤ THỂ, còn
                              thanh đầu là nơi liệt kê KHU VỰC — và bản đồ
                              không còn là một khu vực.
                            */}
                            <li>
                                <AppLink href="/explore?view=map">Bản đồ vị trí</AppLink>
                            </li>
                        </ul>
                    </div>

                    <div className="ft-col">
                        <h4>Media owner</h4>
                        <ul className="ft-ls">
                            <li>
                                <AppLink href="/owners">Danh sách đối tác</AppLink>
                            </li>
                            <li>
                                <AppLink href="/register">Đăng ký bán</AppLink>
                            </li>
                        </ul>
                    </div>

                    <div className="ft-col">
                        <h4>Chính sách</h4>
                        <ul className="ft-ls">
                            {CHINH_SACH.map(([slug, label]) => (
                                <li key={slug}>
                                    <AppLink href={`/${slug}`}>{label}</AppLink>
                                </li>
                            ))}
                            <li>
                                <AppLink href="/phan-anh-to-chuc-xa-hoi">
                                    Tiếp nhận phản ánh của TCXH
                                </AppLink>
                            </li>
                            <li>
                                <AppLink href="/phan-anh-to-chuc-xa-hoi/danh-sach">
                                    Danh sách phản ánh của TCXH
                                </AppLink>
                            </li>
                        </ul>
                    </div>
                </div>

                <div className="ft-btm">
                    <div>Bản quyền &copy; {new Date().getFullYear()} OOHX. Bảo lưu mọi quyền.</div>
                    <div className="ft-bls">
                        <AppLink href="/quy-che-hoat-dong">Quy chế hoạt động</AppLink>
                        <AppLink href="/chinh-sach-bao-mat">Chính sách bảo mật</AppLink>
                        {/*
                          `/phan-anh-to-chuc-xa-hoi` CHƯA chuyển sang Next, nên
                          `AppLink` sẽ tự chọn `<a>`. Để nó đi qua đây thay vì
                          viết cứng `<a>` nghĩa là khi trang đó chuyển, không ai
                          phải nhớ sửa dòng này.
                        */}
                        <AppLink href="/phan-anh-to-chuc-xa-hoi">Liên hệ</AppLink>
                    </div>
                </div>
            </div>
        </footer>
    );
}
