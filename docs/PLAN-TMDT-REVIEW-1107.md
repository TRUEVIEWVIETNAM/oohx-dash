# Plan chỉnh sửa dash theo review đăng ký sàn TMĐT (1107)

Nguồn: `E:\Projects\OOHX_Legal\Review sàn oohx 1107.docx` (16 mục) — đối chiếu với
`E:\Projects\OOHX_Legal\OOHX_TMDT_BoCongThuong.docx` (hồ sơ đã nộp) và code trên đĩa.

Đơn vị đăng ký: **CÔNG TY TNHH TRUEVIEW**, MSDN 0109944503.

Đơn vị review yêu cầu hai loại phản hồi:
- **Yêu cầu chỉnh sửa giao diện** → sửa, chụp màn hình sau khi sửa, gửi lại.
- **Yêu cầu mô tả** → giải thích bằng văn bản, không cần code.

---

## 0. Ba việc phải quyết trước khi viết dòng code nào

Đây không phải việc kỹ thuật. Tôi không tự quyết được, và ba mục dưới đây chặn
tiến độ của phần còn lại.

### 0.1 Sàn có giữ tiền hay không — mâu thuẫn với hồ sơ đã nộp

Hồ sơ đã khai (mục 5):

> Thanh toán **trực tiếp giữa khách hàng và nhà cung cấp** dịch vụ quảng cáo.
> OOHX.NET **hỗ trợ ghi nhận giao dịch và đối soát**.

Nhưng trang thanh toán đang chạy (`resources/views/buyer/booking/payment.blade.php:74-81`)
hiển thị tài khoản nhận tiền là **CONG TY OOHX VIETNAM** — tức sàn đứng ra thu tiền
của người mua. Hai điều này ngược nhau, và mục 7 của review chỉ đúng vào màn hình đó.

Kèm theo hai vấn đề nhỏ hơn nhưng cụ thể:
- Số tài khoản `1234 5678 9012` là **số giả**, đang nằm trên route công khai.
- Tên chủ tài khoản là "CONG TY OOHX VIETNAM" — **không phải** pháp nhân đăng ký
  (TRUEVIEW). Nếu pháp nhân này không tồn tại, đây là thông tin sai trên website.

Hai hướng, chọn một:

| | (A) Giữ đúng hồ sơ: sàn không giữ tiền | (B) Sàn thu hộ |
|---|---|---|
| Sửa gì | Trang thanh toán hiển thị **tài khoản của từng media owner**; sàn chỉ ghi nhận và đối soát | Sửa lại hồ sơ khai với Bộ Công Thương |
| Code | Thêm trường tài khoản ngân hàng vào `owners`; payment page đọc theo owner của booking | Thay số tài khoản thật của TRUEVIEW vào (hoặc đưa vào config) |
| Rủi ro pháp lý | Thấp | Sàn có chức năng thanh toán → nghĩa vụ nặng hơn, cần rà lại điều kiện trung gian thanh toán |

**Khuyến nghị: (A)** — khớp hồ sơ đã nộp, và ít nghĩa vụ pháp lý hơn. Nhưng đây là
quyết định kinh doanh, anh quyết.

### 0.2 Hồ sơ đã khai một số tính năng chưa tồn tại

Hồ sơ mục 7 và 12 liệt kê các tiện ích đang cung cấp. Đối chiếu code:

| Đã khai | Thực tế |
|---|---|
| Đánh giá nhà cung cấp | **Chưa có** (đúng như review mục 6) |
| Đánh giá dịch vụ quảng cáo | **Chưa có** |
| Quản lý hợp đồng điện tử | **Chưa có** |
| Báo giá trực tuyến | Chưa rõ có tính là gì |
| AI kiểm duyệt nội dung quảng cáo | **Chưa có** — không có service nào |
| Blacklist Keyword | **Chưa có** |
| Bộ quy tắc phát hiện nội dung vi phạm | **Chưa có** |
| Giỏ hàng | Có (`CartService`) |
| Quản lý đơn hàng / sản phẩm / gian hàng / thanh toán | Có |

Kiểm duyệt creative hiện chỉ có trạng thái `pending_review` (`creatives.status`) để
**người duyệt tay** — không có công cụ tự động nào. Cần chọn: xây cho đúng khai, hay
sửa hồ sơ cho đúng thực tế. Khai một đằng làm một nẻo là rủi ro khi hậu kiểm.

### 0.3 Người bán có được tự đăng ký không?

Review mục 10 mở đầu bằng "**Trường hợp** người bán được đăng ký tài khoản trên sàn…".
Hiện tại **không có luồng tự đăng ký cho người bán** — `/register` chỉ dành cho người
mua (`routes/web.php:52-56`). Media owner được admin tạo, hoặc được mời qua
`UserInvitation`.

- Nếu **không** mở tự đăng ký → trả lời mục 10 bằng văn bản, và chỉ cần làm phần KYC
  (mục 8).
- Nếu **có** mở → phải xây thêm luồng đăng ký + hàng đợi duyệt. Việc này lớn hơn nhiều.

**Khuyến nghị: không mở** trong giai đoạn này. `owners.status` đã default `pending`, và
admin onboard thủ công vốn đã là "kiểm soát trước khi cung cấp dịch vụ".

---

## 1. Những gì đã có sẵn (tin tốt)

- `owners.status` enum `pending|active|suspended`, **default `pending`**
  (`2025_01_01_000001_create_owners_table.php:21`). Khái niệm "chờ duyệt" đã có sẵn ở
  tầng dữ liệu — chỉ là chưa ai dùng nó để chặn hiển thị.
- Footer đã có cột `Chủ sở hữu` và ba link `Điều khoản / Bảo mật / Liên hệ`
  (`frontpage/partials/footer.blade.php:49-55`) — nhưng cả ba đều `href="#"`, chưa có trang.
- `campaigns.status` và `booking_lines.status` đã có sẵn giá trị `cancelled`;
  `payments.status` đã có `refunded`. Enum có sẵn, chỉ chưa có code nào ghi vào.
- `owners.verified` boolean đã có (hiện chỉ để hiện badge).

---

## 2. Gói việc

### Gói A — Nội dung pháp lý trên trang công khai (review 1, 2, 3)

| # | Việc | File |
|---|---|---|
| 1 | Pop-up "Website đang hoạt động ở chế độ thử nghiệm, đang thực hiện đăng ký với Bộ Công Thương", giữ 5-7s | Component mới + `frontpage/layouts/app.blade.php` |
| 2 | Thông tin công ty TRUEVIEW dưới chân trang | `frontpage/partials/footer.blade.php` |
| 3 | 5 link chính sách dưới chân trang | footer + 5 route/trang mới |

**Chi tiết mục 1.** Không có sẵn primitive modal/banner nào trên site công khai — phải
viết mới. Layout `frontpage/layouts/app.blade.php` là layout duy nhất cho cả
`frontpage/*` lẫn `buyer/*`, nên đặt ở đó là phủ toàn site. Lưu ý: trang
`register`/`login` render với `hideFooter => true`, nhưng pop-up không nằm trong footer
nên vẫn hiện — cần vậy.

**Chi tiết mục 2.** Nội dung chốt (lấy nguyên văn từ review):

```
CÔNG TY TNHH TRUEVIEW
Mã số doanh nghiệp 0109944503 do Sở Tài Chính thành phố Hà Nội cấp ngày 24/3/2022
Địa chỉ: Số 110 đường Lạc Long Quân, Phường Tây Hồ, Thành phố Hà Nội, Việt Nam.
Người đại diện theo pháp luật: NGUYỄN ANH TUẤN
Đầu mối liên hệ, người đại diện được ủy quyền phối hợp với cơ quan nhà nước
có thẩm quyền: ông NGUYỄN ANH TUẤN
Hotline: 0943668996
Email: tuan.nguyen@attvietnam.vn
```

Ảnh trong review cho thấy khối này **đè lên** dòng tagline và hàng icon mạng xã hội —
cần bố cục lại footer, không chỉ chèn thêm.

**Chi tiết mục 3.** Năm link, ba loại việc khác nhau:

| Link | Loại |
|---|---|
| Quy chế hoạt động | Trang tĩnh — **chờ nội dung từ phía anh** |
| Chính sách bảo mật | Trang tĩnh — **chờ nội dung** |
| Cơ chế giải quyết tranh chấp, khiếu nại, phản ánh | Trang tĩnh — **chờ nội dung** |
| Tiếp nhận phản ánh của TCXH | **Form + bảng + admin** — không phải trang tĩnh |
| Danh sách phản ánh của TCXH | **Trang list công khai** đọc từ bảng trên |

Hai link cuối làm theo mẫu `https://bidu.vn/phan-anh-to-chuc-xa-hoi`. Cần:
- migration `reflections` (hoặc `phan_anh`): tổ chức phản ánh, nội dung, ngày tiếp nhận,
  trạng thái xử lý, kết quả xử lý, ngày trả lời
- form công khai + trang danh sách công khai
- Filament resource cho admin xử lý

Đây là mục nặng nhất của gói A. Ba trang tĩnh kia chỉ chờ văn bản.

---

### Gói B — Checkbox chấp thuận chính sách (review 4, 5, 7)

| Vị trí | Nội dung checkbox | File |
|---|---|---|
| Đăng ký tài khoản | "Tôi đã đọc và đồng ý với **Chính sách bảo mật thông tin**" | `buyer/auth/register.blade.php` + `Buyer/BuyerAuthController.php:63-69` |
| Booking (Gửi Booking) | "Tôi đã đọc và đồng ý với **Quy chế hoạt động** và **Chính sách bảo mật**" | `buyer/booking/review.blade.php:113-116` + `Buyer/BookingController.php` |
| Thanh toán | Dòng chữ "Bằng cách thanh toán, bạn đồng ý với **Quy chế hoạt động**" | `buyer/booking/payment.blade.php` |

**Một cái bẫy phải sửa luôn.** Checkbox đang có ở trang booking
(`review.blade.php:113`) viết thế này:

```html
<input type="checkbox" id="agree" required style="...">
```

Không có thuộc tính `name`, nên nó **không được gửi lên server và không có validation
nào kiểm tra**. Tắt JS là qua. Nếu copy mẫu này cho checkbox chính sách thì checkbox
pháp lý cũng vô hiệu y hệt — tức là có hình thức mà không có bằng chứng chấp thuận.

Vì vậy gói B nên làm:
1. Thêm `name` + validation `accepted` ở server cho **cả** checkbox cũ lẫn checkbox mới.
2. **Ghi nhận chấp thuận**: thời điểm, IP, và **phiên bản chính sách** tại thời điểm
   đồng ý. Không có cái này thì sau tranh chấp không chứng minh được người dùng đã đồng
   ý với bản nào. Đề xuất bảng `policy_consents` + cột `version` trên trang chính sách.

Nội dung chính sách **phía review sẽ gửi sau** — nhưng cấu trúc trang và checkbox làm
trước được.

---

### Gói C — KYC người bán (review 8, 10)

Review yêu cầu tối thiểu, đối chiếu bảng `owners`:

| Trường yêu cầu | Hiện trạng |
|---|---|
| Tên công ty | Chỉ có `name` (tên hiển thị) — **thiếu tên pháp lý** |
| Mã số thuế | **Thiếu** (`tax_id` chỉ có bên `organizations` = người mua) |
| Ngày cấp MST | **Thiếu** |
| Nơi cấp MST | **Thiếu** |
| Địa chỉ trụ sở | Có `address/city/district/province_id/commune_id` |
| Người đại diện theo pháp luật | **Thiếu** |
| Email liên hệ | Có `owners.email` |
| Số điện thoại | Có `owners.phone` |
| Giấy tờ pháp lý (ĐKKD) | **Thiếu** — không có cột file nào, không có bảng documents |

Việc:
1. Migration thêm vào `owners`: `legal_name`, `tax_code`, `tax_code_issued_on`,
   `tax_code_issued_by`, `legal_representative`, `business_license_path`.
2. `OwnerResource.php` — thêm section "Thông tin pháp lý" với các trường trên +
   `FileUpload` cho ĐKKD. Hiện form admin có 3 section (Basic Info / Hồ sơ công khai /
   Revenue & Billing).
3. Quyết định: các trường này người bán tự khai ở `Publisher/Pages/CompanyProfile.php`
   hay admin nhập? Nếu người bán tự khai thì phải khóa không cho sửa sau khi đã duyệt.
4. Lưu trữ ĐKKD: file có tính nhạy cảm, **không để trong disk public**. Cần disk riêng
   + route có kiểm tra quyền.

---

### Gói D — Kiểm soát thông tin đăng tải (review 9, 10) — **quan trọng nhất**

Review mục 9 nói rõ:

> sàn cần kiểm soát đối tác có năng lực cung ứng dịch vụ trước khi đăng tải công khai
> thông tin trên website, các đvu sẽ ở trạng thái chờ duyệt. Sau khi được admin duyệt
> mới công khai.

**Hiện tại điều này không được thực thi.** Toàn bộ cổng chặn hiển thị công khai của
màn hình là một dòng (`app/Services/FrontpageService.php:892-893`):

```php
return Screen::withoutGlobalScope('owner_scope')
    ->where('active', true)
```

Không kiểm tra `owners.status`, không kiểm tra `owners.verified`, không kiểm tra
`sites.status` hay `networks.status`. Nghĩa là: **màn hình của một media owner đang ở
trạng thái `pending` vẫn hiển thị công khai ngay khi vừa tạo** — đúng cái mà review
đang yêu cầu phải chặn.

Việc:
1. Thêm điều kiện `owners.status = 'active'` vào truy vấn công khai. Chú ý có **6 chỗ**
   dùng cùng mẫu này: `FrontpageService.php:40, 52, 266, 388, 892` và
   `SitemapController.php:27`. Sửa thiếu một chỗ là rò rỉ ở chỗ đó.
   → Nên gom vào một scope dùng chung (`Screen::publiclyVisible()`) thay vì sửa rải rác.
2. Products đã chặt hơn (`ProductService.php:18` dùng `->active()`, default `draft`) —
   nhưng vẫn không kiểm tra owner. Bổ sung tương tự.
3. Cân nhắc thêm trạng thái duyệt cho từng đơn vị inventory (Screens/Sites/Networks/
   Products) như ảnh review khoanh vùng, chứ không chỉ duyệt ở cấp owner.
4. Sau khi sửa: **kiểm tra lại xem sàn có còn màn hình nào hiển thị không.** Nếu phần
   lớn owner đang ở `pending`, bật cổng này lên sẽ làm trống sàn. Phải rà và duyệt
   dữ liệu hiện có trước khi deploy.

---

### Gói E — Đánh giá media owner (review 6)

Không tồn tại gì cả: không bảng, không model, không cột tổng hợp. Hồ sơ đã khai hai
tính năng "Đánh giá nhà cung cấp" và "Đánh giá dịch vụ quảng cáo", nên đây là việc
phải làm, không phải tùy chọn.

Việc:
1. Migration `owner_reviews`: `campaign_id`, `organization_id`, `owner_id`, `rating`,
   `comment`, `status` (duyệt trước khi hiện), timestamps.
2. Ràng buộc: chỉ người mua **đã có booking hoàn tất** với owner đó mới được đánh giá,
   mỗi campaign một lần.
3. Điểm vào: trang `buyer/dashboard/campaign-detail.blade.php` sau khi campaign
   `completed`.
4. Hiển thị: trang `frontpage/owner-detail.blade.php` + cột điểm trung bình trên
   `owner-card`.
5. Filament resource cho admin kiểm duyệt nội dung đánh giá.

---

### Gói F — Hủy dịch vụ và hoàn tiền (review 14)

Review hỏi: "Trường hợp hủy dịch vụ, cần xử lý như thế nào?"

Trạng thái hiện tại đáng chú ý: `cancelled` **đã có** trong enum của `campaigns.status`
và `booking_lines.status`; `Campaign::STATUS_CANCELLED` đã khai báo
(`app/Models/Campaign.php:49`); `payments.status` đã có `refunded`; và
`AvailabilityService.php:17` đã lọc `whereNotIn('status', ['cancelled','rejected'])` —
tức là phần đọc đã sẵn sàng cho việc hủy.

**Nhưng không dòng code nào ghi được giá trị đó.** Không có `cancel()` ở
`CampaignService`, không có action ở Filament, không có route. Không có `refund()` ở
`PaymentService`. Không có cột `cancelled_at` hay `cancellation_reason`.

Nghĩa là: hủy là tính năng đã được thiết kế nhưng chưa bao giờ được viết. Trả lời review
mục 14 mà không có tính năng này thì chỉ là mô tả chính sách trên giấy.

Việc:
1. Chốt **chính sách hủy** trước (ai được hủy, mốc thời gian nào, phí hủy bao nhiêu,
   hoàn tiền ra sao) — việc kinh doanh, không phải code.
2. Migration: `cancelled_at`, `cancellation_reason` trên `campaigns`.
3. `CampaignService::cancel()` + `PaymentService::refund()` + bảng `refunds` nếu cần
   theo dõi từng lần hoàn.
4. Nút hủy ở buyer dashboard + publisher booking inbox, theo đúng chính sách ở bước 1.
5. Ghi vào `campaign_activities` để có vết.

Phụ thuộc mục 0.1: nếu sàn không giữ tiền thì "hoàn tiền" là việc giữa hai bên, sàn chỉ
ghi nhận — đơn giản hơn nhiều.

---

### Gói G — Việt hóa 100% (ghi chú ở review mục 1)

> Lưu ý: các nội dung trên website đảm bảo 100% tiếng Việt

**Không có cơ chế i18n nào.** Không có thư mục `lang/`; `config/app.php:81` để
`APP_LOCALE=en`; không có lời gọi `__()` nào trong `frontpage/` hay `buyer/`; chuỗi
tiếng Việt và tiếng Anh trộn cứng trong blade.

Các chuỗi tiếng Anh còn sót trên trang công khai (đã rà, chưa chắc đủ):

| File | Chuỗi |
|---|---|
| `partials/footer.blade.php` | `Explore`, `Markets`, `Agency`, `All inventory`, `Billboard`, `LED Outdoor`, `Mall LCD`, `Dashboard`, `Compare`, `API` |
| `frontpage/index.blade.php` | `Campaign Packages`, `PACKAGES`, `Search & Filter`, `Compare`, `Submit Booking`, `Track & Report`, `Register as Owner`, `Learn more` |
| `partials/header.blade.php` | `Media Owners`, `Agency`, `Dashboard`, `Campaigns` |
| `buyer/booking/review.blade.php` | `Creative`, `Brand`, `Budget` |
| `buyer/dashboard/campaigns.blade.php` | `Campaigns` (thấy trong ảnh review mục 6) |

Hai cách:
- **Sửa cứng trong blade** — nhanh, hợp giai đoạn này, đủ đáp ứng yêu cầu.
- **Dựng i18n thật** (`lang/vi`, `__()`) — đúng bài, nhưng là việc lớn và review không
  yêu cầu song ngữ.

**Khuyến nghị: sửa cứng.** Review chỉ yêu cầu 100% tiếng Việt, không yêu cầu đa ngôn ngữ.

Lưu ý: tên riêng như `OOHX`, `LED`, `LCD`, `API`, `Billboard` có thể giữ — nên hỏi lại
đơn vị review chỗ nào bắt buộc dịch.

---

### Gói H — Mô tả bằng văn bản (review 11, 12, 13, 15, 16)

Không cần code. Cần soạn văn bản mô tả:

| # | Đối tượng | Ghi chú |
|---|---|---|
| 11 | Toàn bộ menu admin: Data Engine (Collectors, Health Monitor, Analytics, Campaign Planner, Recompute Jobs, Formula versions, City baseline traffic, Road class multipliers, Zone factors, Delivery defaults, Seasonality factors, Config audit log), Organizations, System Settings | Mô tả mục đích từng nhóm chức năng |
| 12 | Menu Inventory của Publisher (Products / Screens / Sites / Networks) | Giải thích quan hệ 4 cấp |
| 13 | Khối "Campaign Packages" ở trang chủ | **Lưu ý: 3 nút CTA hiện là `<button>` trống, không có href, bấm không ra gì** (`frontpage/index.blade.php:345`). Mô tả một tính năng không hoạt động là rủi ro — nên nối chức năng hoặc gỡ khối này trước |
| 14 | Xử lý khi hủy dịch vụ | → xem gói F |
| 15 | Sau khi thanh toán, dịch vụ được cung cấp thế nào? Phương thức chung hay riêng từng media owner? Nếu riêng thì phải hiện cách thức của từng đơn vị **trước khi** người mua đặt | Nếu chọn "riêng" → phát sinh code: thêm trường mô tả phương thức cung cấp vào `owners`, hiện ở trang chi tiết màn hình/owner trước bước booking |
| 16 | Cách thức thu phí: đối tượng thu, cách thu và phân chia lợi nhuận | Xem bên dưới |

**Về mục 16 — cần biết trước khi trả lời.** Cột duy nhất liên quan tới phí trong toàn
bộ schema là `owners.revenue_share_pct` (decimal, default **70.00**, ngụ ý sàn lấy 30%).
Nó được lưu, được hiện trong admin, được validate ở API — nhưng **chưa bao giờ được
đem ra tính toán**. Không có cột `commission`/`platform_fee`/`service_fee` ở
`payments`, `campaigns` hay `booking_lines`; không có model đối soát/chi trả nào.

Nghĩa là con số 70/30 hiện là một con số được khai báo chứ chưa phải một cơ chế đang
chạy. Trả lời mục 16 cần chốt: thu của ai (người bán hay người mua), thu lúc nào, và
70/30 có phải là tỷ lệ thật không.

---

## 3. Thứ tự đề xuất

1. **Quyết ba việc ở mục 0** — chặn gói B, D, F, H.
2. **Gói D** (chặn hiển thị theo trạng thái duyệt) — đây là yêu cầu nặng nhất về bản
   chất và đang bị vi phạm trên production. Rà dữ liệu owner trước khi bật.
3. **Gói A** phần footer + pop-up — nhanh, thấy ngay, chụp màn hình gửi lại được.
4. **Gói G** việt hóa — nhanh, thấy ngay.
5. **Gói C** KYC — vừa, độc lập.
6. **Gói B** checkbox + ghi nhận chấp thuận — chờ nội dung chính sách, nhưng sửa lỗi
   checkbox không có `name` thì làm được ngay.
7. **Gói A** phần tiếp nhận phản ánh — nặng, cần bảng + form + admin.
8. **Gói E** đánh giá media owner.
9. **Gói F** hủy + hoàn tiền — phụ thuộc chính sách.
10. **Gói H** soạn văn bản mô tả — làm song song.

## 4. Việc chờ phía ngoài

- Nội dung **Quy chế hoạt động** và **Chính sách bảo mật** — review nói "sẽ gửi sau".
- Chính sách hủy dịch vụ + tỷ lệ/cách thu phí — cần anh chốt.
- Số tài khoản ngân hàng thật (hoặc quyết định bỏ hẳn theo hướng 0.1-A).
