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
    Bump `version` + đặt `effective_from` trong config/policies.php ở cùng commit.
--}}

<h2>1. Nguyên tắc giải quyết</h2>
<p>Nội dung đang hoàn thiện.</p>

<h2>2. Kênh tiếp nhận</h2>
<p>Sàn tiếp nhận tranh chấp, khiếu nại và phản ánh qua các kênh sau:</p>
<ul>
    <li>
        Biểu mẫu trực tuyến:
        <a href="{{ url('/phan-anh-to-chuc-xa-hoi') }}">Tiếp nhận phản ánh</a>
    </li>
    <li>Hotline: {{ config('policies.company.hotline') }}</li>
    <li>
        Email:
        <a href="mailto:{{ config('policies.company.email') }}">{{ config('policies.company.email') }}</a>
    </li>
    <li>Địa chỉ: {{ config('policies.company.address') }}</li>
</ul>

<h2>3. Quy trình và thời hạn xử lý</h2>
<p>Nội dung đang hoàn thiện.</p>

<h2>4. Trách nhiệm của các bên</h2>
<p>Nội dung đang hoàn thiện.</p>

<h2>5. Trường hợp không đạt được thỏa thuận</h2>
<p>Nội dung đang hoàn thiện.</p>

<h2>6. Công khai kết quả xử lý phản ánh</h2>
<p>
    Kết quả xử lý các phản ánh của tổ chức xã hội được công bố tại
    <a href="{{ url('/phan-anh-to-chuc-xa-hoi/danh-sach') }}">Danh sách phản ánh của TCXH</a>.
</p>
