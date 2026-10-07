{{--
    PHẦN THÂN của một văn bản chính sách — NGUỒN DUY NHẤT.

    Dùng bởi `Api\V2\PublicContentController::policy()`, trả `body_html` cho
    app Next.js. Đó là bên tiêu thụ duy nhất còn lại.

    Trang Blade từng bọc partial này trong `<x-policy-shell>`; cả trang lẫn
    component đã gỡ ở giai đoạn 7 (07/10/2026), vì Next phục vụ bốn đường dẫn
    chính sách và giữ hai bản song song là giữ hai nơi có thể trôi khỏi nhau.

    Văn bản ở lại Blade chứ không chuyển sang `webapp/`, và đó là chủ ý: nếu
    app Next chép nội dung này thành component riêng thì có bản thứ hai — mà
    với tài liệu được đóng dấu phiên bản vào từng bản ghi đồng ý, hai bản trôi
    khỏi nhau nghĩa là mất bằng chứng.

    Đổi chữ ở đây thì bump `version` và đặt `effective_from` trong
    `config/policies.php` ở CÙNG commit. Luật đó neo vào file này.
--}}

{{--
    KHUNG MỤC — nội dung chính thức phía đăng ký sàn sẽ gửi sau.
    Bump `version` + đặt `effective_from` trong config/policies.php ở cùng
    commit khi dán nội dung thật.

    Các mục dưới đây bám theo hồ sơ đã khai với Bộ Công Thương (mục 8 và 11):
    HTTPS/TLS toàn site, mật khẩu băm Argon2ID, dữ liệu cá nhân mã hoá
    AES-256, phân quyền theo vai trò, ghi log truy cập, tuân thủ Nghị định
    13/2023/NĐ-CP. Nội dung viết ra phải khớp với những gì hệ thống thực sự
    làm — khai một đằng làm một nẻo là rủi ro khi hậu kiểm.
--}}

<h2>1. Mục đích và phạm vi thu thập thông tin</h2>
<p>Nội dung đang hoàn thiện.</p>

<h2>2. Phạm vi sử dụng thông tin</h2>
<p>Nội dung đang hoàn thiện.</p>

<h2>3. Thời gian lưu trữ thông tin</h2>
<p>Nội dung đang hoàn thiện.</p>

<h2>4. Những người hoặc tổ chức có thể được tiếp cận thông tin</h2>
<p>Nội dung đang hoàn thiện.</p>
<h3>Chia sẻ thông tin người mua cho media owner khi gửi booking</h3>
<p>
    Khi người mua gửi booking, media owner có màn hình trong booking đó được xem
    thông tin liên hệ của người mua gồm: tên đơn vị, họ tên và email của người
    tạo booking, số điện thoại và email thanh toán của đơn vị (nếu có). Mục đích
    duy nhất là để media owner trao đổi, làm rõ yêu cầu và thỏa thuận về booking
    đó.
</p>
<ul>
    <li>Mỗi media owner chỉ xem được thông tin người mua trong các booking có màn hình của mình.</li>
    <li>Media owner không được sử dụng thông tin này cho mục đích khác hoặc chuyển cho bên thứ ba.</li>
    <li>Media owner không có màn hình trong booking không được tiếp cận thông tin này.</li>
</ul>

<h2>5. Đơn vị thu thập và quản lý thông tin cá nhân</h2>
@include('frontpage.partials.company-legal')

<h2>6. Phương thức và công cụ để người dùng tiếp cận và chỉnh sửa dữ liệu</h2>
<p>Nội dung đang hoàn thiện.</p>

<h2>7. Cam kết bảo mật thông tin cá nhân</h2>
<p>Nội dung đang hoàn thiện.</p>

<h2>8. Cơ chế tiếp nhận và giải quyết khiếu nại</h2>
<p>
    Người dùng gửi khiếu nại qua
    <a href="{{ url('/phan-anh-to-chuc-xa-hoi') }}">biểu mẫu tiếp nhận phản ánh</a>,
    hoặc theo thông tin liên hệ nêu tại mục 5.
</p>
