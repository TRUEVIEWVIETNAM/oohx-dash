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

@php
    // Partial tự khai dữ liệu nó cần, không nhận từ trang gọi nó. Hai bên gọi
    // partial này — trang Blade và `ApiV2PublicContentController::policy()`
    // — và endpoint không có `$fees`/`$company` để truyền vào. Khai ở đây
    // nghĩa là nó render được từ bất cứ đâu, và con số phí vẫn chỉ có một
    // nguồn: `config/policies.php`.
    $fees = config('policies.fees');
    $company = config('policies.company');
@endphp

{{--
    Biểu phí khai với Sở Công Thương (phản hồi review vòng 2, mục 16).
    Con số lấy từ config/policies.php. Đổi mức phí thì bump `version` ở
    cùng commit — trang này là cam kết công khai với media owner.
--}}

<h2>1. Đối tượng thu phí</h2>
<p>
    Sàn chỉ thu phí của <strong>media owner</strong> (đơn vị sở hữu phương tiện
    quảng cáo). <strong>Người mua sử dụng sàn miễn phí.</strong>
</p>

<h2>2. Biểu phí</h2>
<div class="pol-table-wrap">
    <table class="pol-table">
        <thead>
            <tr>
                <th>Giai đoạn</th>
                <th>Mức phí</th>
                <th>Thời gian áp dụng</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Giai đoạn 1</td>
                <td><strong>0 đồng</strong></td>
                <td>
                    Từ khi website hoạt động đến khi sàn có
                    {{ $fees['free_until_active_owners'] }} media owner được duyệt hoạt động
                </td>
            </tr>
            <tr>
                <td>Giai đoạn 2</td>
                <td>
                    <strong>{{ number_format($fees['owner_annual_fee'], 0, ',', '.') }} đồng/năm</strong>
                    cho mỗi doanh nghiệp media owner (chưa bao gồm VAT)
                </td>
                <td>Sau khi đạt mốc trên và hết thời hạn thông báo tại mục 4</td>
            </tr>
        </tbody>
    </table>
</div>
<p>
    Phí tính theo <strong>doanh nghiệp media owner</strong>, không phụ thuộc số
    tài khoản người dùng hay số màn hình của doanh nghiệp đó.
</p>

<h2>3. Những khoản sàn không thu</h2>
<ul>
    <li>Sàn không thu phí trên từng giao dịch giữa người mua và media owner.</li>
    <li>
        Sàn không tham gia phân chia lợi nhuận từ giao dịch. Tiền dịch vụ do người
        mua thanh toán trực tiếp cho media owner.
    </li>
    <li>Sàn không thu bất kỳ khoản phí nào của người mua.</li>
</ul>

<h2>4. Chuyển sang giai đoạn 2</h2>
<p>
    Khi sàn đạt {{ $fees['free_until_active_owners'] }} media owner được duyệt hoạt
    động, sàn thông báo tới các media owner và cập nhật Quy chế hoạt động trước
    tối thiểu {{ $fees['notice_days'] }} ngày trước khi bắt đầu thu phí. Sàn không
    truy thu phí cho thời gian media owner đã sử dụng miễn phí.
</p>

<h2>5. Cách thức đóng phí</h2>
<p>
    Trong giai đoạn 1 không phát sinh việc đóng phí. Từ giai đoạn 2, phí được thu
    theo hợp đồng dịch vụ và hóa đơn, bằng hình thức chuyển khoản vào tài khoản
    của {{ $company['legal_name'] }}.
</p>

<h2>6. Liên hệ</h2>
<p>
    Mọi thắc mắc về phí dịch vụ xin liên hệ
    <a href="mailto:{{ $company['email'] }}">{{ $company['email'] }}</a>
    hoặc hotline {{ $company['hotline'] }}.
</p>
