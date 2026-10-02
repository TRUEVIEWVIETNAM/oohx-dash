# Phản hồi review đăng ký sàn TMĐT OOHX.NET (1107)

Đơn vị: **CÔNG TY TNHH TRUEVIEW** — MSDN 0109944503.

Tài liệu này trả lời từng mục của phiếu review. Các mục yêu cầu chỉnh sửa giao
diện đã được thực hiện; các mục yêu cầu mô tả được giải thích bằng văn bản dưới
đây.

---

## Phần A — Các mục đã chỉnh sửa giao diện

| # | Yêu cầu | Tình trạng |
|---|---|---|
| 1 | Pop-up thông báo chế độ thử nghiệm, giữ 5-7s | **Đã làm.** Hiện ở đầu trang trên toàn bộ website, tự ẩn sau 6.5 giây, có nút đóng. |
| 1b | Nội dung website 100% tiếng Việt | **Đã làm.** Đã dịch toàn bộ chuỗi tiếng Anh còn sót trên trang công khai và khu vực người mua. |
| 2 | Thông tin công ty dưới chân trang | **Đã làm.** Hiển thị đầy đủ 7 mục theo đúng nội dung yêu cầu. |
| 3 | 5 tiêu đề chính sách dưới chân trang | **Đã làm.** Đã tạo cột "Chính sách" với 5 liên kết hoạt động. |
| 4 | Checkbox đồng ý ở trang đăng ký | **Đã làm.** |
| 5 | Checkbox đồng ý ở bước booking | **Đã làm.** |
| 6 | Tính năng đánh giá media owner | **Đã làm.** |
| 7 | Tính năng thanh toán | **Đã sửa** — xem mục 7 phần B. |
| 8 | Lưu trữ thông tin đối tác | **Đã làm.** |
| 9 | Kiểm soát thông tin đăng tải | **Đã làm.** |
| 10 | Kiểm soát thông tin người bán | **Đã làm** — xem mục 10 phần B. |

**Lưu ý về nội dung chính sách:** Ba trang *Quy chế hoạt động*, *Chính sách bảo
mật*, *Cơ chế giải quyết tranh chấp* đã được tạo với đầy đủ khung mục và đường
dẫn hoạt động, nhưng **nội dung văn bản còn là bản nháp** và trang có ghi rõ điều
này. Chúng tôi sẽ cập nhật nội dung chính thức ngay khi nhận được.

---

## Phần B — Giải thích các mục yêu cầu mô tả

### Mục 7 — Tính năng thanh toán

**Mô hình:** OOHX.NET **không thu hộ tiền**. Người mua chuyển khoản **trực tiếp
cho từng media owner**; sàn chỉ ghi nhận giao dịch và đối soát. Điều này đúng với
mục 5 của hồ sơ đăng ký đã nộp.

**Cách hoạt động:**
1. Sau khi media owner duyệt booking, người mua vào trang thanh toán.
2. Trang hiển thị **một khối chuyển khoản cho mỗi media owner** trong chiến dịch,
   kèm số tiền tương ứng phần màn hình của owner đó (chi phí + VAT 10%).
3. Một chiến dịch gồm màn hình của nhiều media owner sẽ cần nhiều lần chuyển
   khoản riêng — sàn hiển thị rõ điều này.
4. Người mua chuyển khoản, sau đó bấm "Xác nhận đã chuyển khoản" cho từng owner.
5. Sàn ghi nhận khoản thanh toán (trạng thái *chờ xác nhận*), gắn với đúng media
   owner nhận tiền.
6. Khi đã đủ tiền, chiến dịch được kích hoạt.

**Hình thức thanh toán:** hiện hỗ trợ chuyển khoản ngân hàng. VNPay đang trong kế
hoạch, chưa mở.

**Đã sửa trong đợt này:** trước đây trang thanh toán hiển thị một số tài khoản
mẫu mang tên "CONG TY OOHX VIETNAM". Đây là dữ liệu mẫu còn sót lại từ giai đoạn
dựng giao diện, không phải tài khoản thật và không phản ánh mô hình hoạt động.
Đã thay bằng tài khoản thật của từng media owner.

---

### Mục 10 — Kiểm soát thông tin người bán

**Người bán không tự đăng ký tài khoản trên sàn.** Media owner được OOHX tiếp
nhận và tạo tài khoản sau khi đã rà soát hồ sơ. Quy trình:

1. Media owner liên hệ OOHX và cung cấp hồ sơ.
2. Quản trị viên tạo tài khoản. **Tài khoản mặc định ở trạng thái *chờ duyệt*
   (pending)** — đây là giá trị mặc định của hệ thống, không phải thao tác tay.
3. Ở trạng thái chờ duyệt, media owner **không thể cung cấp dịch vụ**: toàn bộ
   màn hình, địa điểm, mạng lưới và sản phẩm của họ **không hiển thị công khai**
   trên sàn và không thể được đặt.
4. Quản trị viên đối chiếu hồ sơ pháp lý. Hệ thống hiển thị rõ hồ sơ còn thiếu gì.
5. Rà soát xong, quản trị viên chuyển trạng thái sang *đang hoạt động* (active).
   Từ lúc này màn hình của media owner mới xuất hiện công khai.
6. Nếu phát hiện vi phạm, quản trị viên chuyển sang *tạm ngưng* (suspended) —
   toàn bộ thông tin của media owner lập tức ẩn khỏi trang công khai.

**Thông tin tối thiểu bắt buộc lưu trữ** (đã bổ sung theo yêu cầu):

| Thông tin | Trường hệ thống |
|---|---|
| Tên công ty theo ĐKKD | `legal_name` |
| Mã số thuế | `tax_code` |
| Ngày cấp | `tax_code_issued_on` |
| Nơi cấp | `tax_code_issued_by` |
| Địa chỉ trụ sở | `address` + tỉnh/phường |
| Người đại diện theo pháp luật | `legal_representative` |
| Email liên hệ | `email` |
| Số điện thoại | `phone` |
| Giấy đăng ký kinh doanh | `business_license_path` |

Giấy ĐKKD được lưu ở khu vực riêng, chỉ quản trị viên truy cập được, không có
đường dẫn công khai.

---

### Mục 9 — Kiểm soát thông tin đăng tải

Sàn kiểm soát ở **hai tầng**:

**Tầng 1 — Đối tác.** Như mô tả ở mục 10: mọi thông tin của media owner chưa được
duyệt đều không hiển thị công khai. Đây là điều kiện chặn ở mọi truy vấn công
khai của website (trang chủ, danh sách, bản đồ, tìm kiếm, trang chi tiết, sitemap,
số liệu thống kê).

**Tầng 2 — Nội dung quảng cáo.** Nội dung (creative) do người mua tải lên ở trạng
thái *chờ duyệt* (`pending_review`) và phải được quản trị viên duyệt trước khi
phát. Nội dung vi phạm bị từ chối.

---

### Mục 11 — Mô tả các chức năng trong khu vực quản trị

Khu vực quản trị (`/admin`) chỉ dành cho nhân sự OOHX, chia làm bốn nhóm:

**Nhóm "Marketplace"** — vận hành sàn:
- **Products / Campaigns / Creatives** — sản phẩm quảng cáo, chiến dịch của người
  mua, và nội dung quảng cáo chờ kiểm duyệt.
- **Phản ánh của TCXH** — tiếp nhận và xử lý phản ánh của tổ chức xã hội.
- **Đánh giá media owner** — kiểm duyệt đánh giá của người mua trước khi hiển thị.

**Nhóm "OOHX · Data Engine"** — hệ thống ước tính lưu lượng và hiển thị. Đây là
công cụ nội bộ để **ước tính số lượt tiếp cận của mỗi màn hình**, giúp người mua
có cơ sở so sánh khi chọn vị trí. Không liên quan tới giao dịch:
- **Collectors** — thu thập dữ liệu đầu vào (dân cư, giao thông, địa điểm lân cận).
- **Health Monitor** — theo dõi tình trạng hoạt động của các tiến trình thu thập.
- **Analytics** — báo cáo nội bộ về hiệu quả sàn.
- **Campaign Planner** — công cụ gợi ý danh mục màn hình theo mục tiêu chiến dịch.
- **Recompute Jobs** — hàng đợi tính lại số liệu ước tính khi tham số thay đổi.
- **Collector run history** — lịch sử các lần chạy thu thập.
- **Formula versions** — phiên bản công thức ước tính. Mỗi con số ước tính đưa ra
  cho người mua đều ghi kèm phiên bản công thức đã tạo ra nó, để truy vết được.
- **City baseline traffic** — lưu lượng nền theo từng thành phố.
- **Road class multipliers** — hệ số theo cấp đường (quốc lộ, đường đô thị…).
- **Zone factors** — hệ số theo khu vực.
- **Delivery defaults** — tham số mặc định về tần suất phát.
- **Seasonality factors** — hệ số mùa vụ.
- **Config audit log** — nhật ký thay đổi tham số, ghi ai sửa gì lúc nào.

**Nhóm "Organizations"** — quản lý các bên tham gia sàn:
- **Media Owners** — hồ sơ và trạng thái duyệt của người bán (xem mục 10).
- **Users / Roles / Permissions** — tài khoản và phân quyền.

**Nhóm "System Settings"** — dữ liệu danh mục dùng chung: vùng kinh tế, tỉnh/thành
phố, phường/xã, danh mục loại hình địa điểm, và các bảng tra cứu của Data Engine.

---

### Mục 12 — Mô tả menu Inventory của người bán

Khu vực người bán (`/publisher`) là nơi media owner tự quản lý hàng hoá của mình.
Menu **Inventory** có bốn cấp, từ nhỏ đến lớn:

- **Screens (Màn hình)** — đơn vị nhỏ nhất: một màn hình hoặc một biển quảng cáo
  cụ thể. Đây là thứ được đặt và phát nội dung.
- **Sites (Địa điểm)** — một địa chỉ vật lý chứa một hoặc nhiều màn hình. Ví dụ:
  một siêu thị có 5 màn hình LCD.
- **Networks (Mạng lưới)** — nhóm nhiều địa điểm cùng tính chất, thường theo chuỗi.
  Ví dụ: mạng lưới màn hình trong toàn hệ thống siêu thị X.
- **Products (Sản phẩm)** — gói bán hàng mà media owner tạo ra từ các màn hình
  của mình. Ví dụ: gói "10 màn hình LCD khu vực Hà Nội trong 1 tháng". Đây là thứ
  người mua nhìn thấy và chọn mua.

Quan hệ: `Network` → chứa nhiều `Site` → chứa nhiều `Screen`. `Product` là gói
thương mại gom nhiều `Screen` lại.

Media owner không tự công khai được: mọi thông tin ở đây chỉ hiển thị ra sàn sau
khi tài khoản của họ được OOHX duyệt (mục 9, 10).

---

### Mục 13 — Mô tả khối "Gói chiến dịch" ở trang chủ

Đây là **khối giới thiệu mang tính tham khảo**, không phải sản phẩm bán trực tiếp.
Ba gói (City Launch / Retail Activation / Event Blitz) là các cấu hình mẫu để
người mua chưa quen hình dung được ngân sách và quy mô thường gặp. Mức giá hiển
thị là **giá tham khảo khởi điểm**, không phải báo giá.

> **Lưu ý cần xử lý:** ba nút bấm trong khối này hiện chưa gắn chức năng. Chúng
> tôi sẽ gỡ khối này hoặc nối vào luồng tư vấn trước khi website hoạt động chính
> thức, để tránh hiển thị chức năng không dùng được.

---

### Mục 14 — Trường hợp hủy dịch vụ

> **Mục này cần chốt chính sách trước khi trả lời chính thức.**

Hiện trạng: hệ thống đã có sẵn trạng thái *đã hủy* cho chiến dịch và cho từng
dòng đặt chỗ, và phần kiểm tra lịch trống đã loại trừ các dòng đã hủy. Tuy nhiên
**quy trình hủy và chính sách hoàn tiền chưa được ban hành**, nên chưa mở chức
năng hủy trên giao diện.

Cần chốt các điểm sau trước khi triển khai:
- Ai được quyền hủy: người mua, media owner, hay cả hai?
- Mốc thời gian: hủy trước ngày bắt đầu bao lâu thì không mất phí?
- Phí hủy theo từng mốc.
- Do sàn không giữ tiền, việc hoàn tiền là giao dịch **trực tiếp giữa người mua
  và media owner**; sàn ghi nhận và đối soát. Cần xác định trách nhiệm của sàn
  trong trường hợp hai bên không thống nhất — dự kiến đưa vào *Cơ chế giải quyết
  tranh chấp*.

---

### Mục 15 — Phương thức cung cấp dịch vụ sau thanh toán

**Quy trình chung áp dụng trên toàn sàn:**
1. Người mua chọn màn hình, tạo chiến dịch, tải lên nội dung quảng cáo.
2. Media owner phê duyệt hoặc từ chối từng màn hình trong vòng 48 giờ.
3. Người mua chuyển khoản trực tiếp cho từng media owner đã duyệt.
4. Chiến dịch được kích hoạt khi đã thanh toán đủ.
5. Media owner phát nội dung theo đúng thời gian và tần suất đã đặt.
6. Người mua theo dõi tình trạng phát và báo cáo trên bảng điều khiển.

**Về phương thức cung cấp riêng của từng media owner:** các thông số quyết định
cách dịch vụ được cung cấp — thời lượng mỗi spot, tần suất phát, tỷ lệ chia sẻ
thời lượng (share of voice), khung giờ phát — **được khai báo riêng cho từng màn
hình** và hiển thị trên trang chi tiết màn hình, **trước khi** người mua thêm vào
giỏ và đặt. Người mua nhìn thấy các thông số này ở bước chọn màn hình, không phải
sau khi đã đặt.

> **Điểm sẽ bổ sung:** hiện các thông số kỹ thuật đã hiển thị đầy đủ, nhưng chưa
> có mục mô tả bằng lời của từng media owner về quy trình vận hành riêng (thời
> gian dựng biển, quy trình kiểm tra, cách xử lý sự cố…). Chúng tôi sẽ bổ sung
> trường này vào hồ sơ media owner và hiển thị ở trang chi tiết trước bước đặt.

---

### Mục 16 — Cách thức thu phí

> **Mục này cần chốt chính sách trước khi trả lời chính thức.**

Hiện trạng: hệ thống có lưu tỷ lệ chia doanh thu cho từng media owner (mặc định
70% cho media owner / 30% cho sàn), nhưng **cơ chế thu chưa được kích hoạt** —
đây mới là con số khai báo, chưa được đưa vào tính toán trong giao dịch nào.

Cần chốt:
- **Đối tượng thu phí:** thu của media owner (trừ vào doanh thu) hay thu của
  người mua (cộng vào giá)?
- **Cách thu:** do sàn không giữ tiền, sàn không thể tự trừ phí từ dòng tiền. Vì
  vậy phí phải được thu theo một trong hai cách: (a) sàn xuất hoá đơn phí dịch vụ
  cho media owner theo kỳ, dựa trên số liệu đối soát; hoặc (b) tính vào giá hiển
  thị và media owner chuyển lại phần phí cho sàn.
- **Thời điểm thu:** theo từng giao dịch hay theo kỳ (tháng/quý)?
- **Tỷ lệ:** 70/30 có phải là tỷ lệ áp dụng thật không, và có khác nhau theo loại
  media owner không?

Sau khi chốt, nội dung này sẽ được đưa vào *Quy chế hoạt động* mục 4 (Quy trình
thanh toán).

---

## Phần C — Các nội dung chờ cung cấp

1. Nội dung chính thức của **Quy chế hoạt động** và **Chính sách bảo mật**.
2. Chính sách **hủy dịch vụ và hoàn tiền** (mục 14).
3. **Cơ chế thu phí** (mục 16).
