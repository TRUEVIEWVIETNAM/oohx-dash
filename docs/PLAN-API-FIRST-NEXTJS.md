# Chuyển OOHX sang kiến trúc API-first + Next.js — đánh giá và kế hoạch

Người viết: Claude Code · 26/09/2026 · Baseline `112e2aa`, nhánh `feat/tmdt-review-1107`.

Tài liệu này trả lời hai câu: **có nên làm không**, và **nếu làm thì làm theo thứ tự nào**. Nó dựa trên kết quả audit ngày 23/09 (`docs/audit-5-vung-2026-09-23/`), không phải trên cảm tính về công nghệ.

---

## 1. Kết luận ngắn

**Nên chuyển sang API-first. Không nên bỏ Filament trong cùng một đợt.**

Ba ý chính:

1. **API-first là việc phải làm dù thế nào.** Audit đã chỉ ra phân quyền hiện nằm rải trong Filament Resource chứ không nằm ở tầng miền; API ghi dữ liệu thì gần như không kiểm quyền. Dựng API tử tế chính là việc sửa lỗi P0 số 2, không phải việc thêm.
2. **Thay giao diện không sửa được lỗi đang mất tiền.** Giá do client quyết định số lượng, không khóa chống đặt trùng, bằng chứng phát sóng không ghi được — cả ba nằm ở tầng service và CSDL. Viết lại giao diện bằng Next.js mà giữ nguyên lõi thì vẫn mất tiền y như cũ, chỉ là mất bằng React.
3. **Filament admin là thứ rẻ nhất đang có.** 113 file resource cho khu quản trị nội bộ, gồm cả cụm Data Engine (bảng cấu hình công thức, health monitor, analytics, import Excel có AI). Dựng lại bằng Next.js là hàng tháng công, cho **người dùng nội bộ**, không mang lại đồng doanh thu nào.

Khuyến nghị: **chuyển phần khách hàng nhìn thấy sang Next.js, giữ Filament cho khu quản trị nội bộ.** Đây không phải thỏa hiệp tạm thời mà là một lựa chọn kiến trúc hợp lý lâu dài — nhiều sàn vận hành đúng như vậy.

---

## 2. Đối chiếu: được gì, mất gì

### Được

| Lợi ích | Thực chất |
|---|---|
| Trải nghiệm mua hàng | Giỏ hàng, bản đồ chọn nhiều vị trí, so sánh, kế hoạch truyền thông — đây là ứng dụng thật sự, Blade + Alpine đang đuối. Next.js hợp |
| SEO và tốc độ | Kiểm soát tốt hơn: render tĩnh cho trang danh mục, ISR cho trang chi tiết, ảnh tối ưu. Hiện `/map` nạp toàn bộ dữ liệu, `/explore` render server mỗi lần |
| Mở đường cho mobile và đối tác | Đã có đối tác gọi `/api/v1`. Một API chung cho cả web, mobile, đối tác là đúng hướng |
| Tách đội | Đội frontend làm Next.js, đội backend làm miền nghiệp vụ, giao nhau qua hợp đồng API |
| Đúng ý định ban đầu | `docs/instructions.md` từ tháng 3 đã mô tả đúng kiến trúc này: OOHX là Next.js gọi API |

### Mất

| Rủi ro | Mức độ |
|---|---|
| Mất toàn bộ CRUD miễn phí của Filament nếu bỏ admin | Rất lớn: 113 file, kèm bộ lọc, hành động hàng loạt, relation manager, import |
| Trong giai đoạn chuyển đổi phải nuôi hai giao diện | Chi phí kép, dễ lệch hành vi |
| Phân quyền phải viết lại ở tầng API | Đây vừa là rủi ro vừa là cơ hội — bắt buộc phải làm đúng |
| Tải file và quyền truy cập file | Filament đang lo phần private disk; Next.js phải thay bằng URL ký hạn |
| SEO có thể tụt trong lúc chuyển | Phải giữ nguyên URL, canonical, sitemap, chuyển hướng |
| **Trang pháp lý bắt buộc** | Quy chế, chính sách, tiếp nhận phản ánh TCXH, banner thử nghiệm đang phục vụ hồ sơ TMĐT. Đổi nền tảng mà làm hỏng là rủi ro pháp lý, không chỉ kỹ thuật |

---

## 3. Phạm vi đề xuất: cái gì chuyển, cái gì ở lại

| Khu vực | Hiện tại | Đề xuất | Lý do |
|---|---|---|---|
| Trang công khai (`/`, `/explore`, `/map`, `/owners`, `/products`, chính sách) | 38 Blade view | **Chuyển sang Next.js** | Khách hàng thấy, cần SEO và tốc độ |
| Khu người mua (`/cart`, `/booking/*`, `/my/*`) | 13 Blade view | **Chuyển sang Next.js** | Tương tác nhiều, là nơi kiếm tiền |
| Khu media owner (`/publisher`) | 33 file Filament | **Chuyển sau, hoặc giữ** | Là khách hàng bên cung, trải nghiệm có giá trị, nhưng không gấp |
| Khu quản trị nội bộ (`/admin`) | 113 file Filament | **Giữ Filament** | Nội bộ, đổi không tạo giá trị, chi phí rất lớn |
| Cụm Data Engine (`/admin/oohx-*`) | Filament | **Giữ Filament, không bàn thêm** | Công cụ nội bộ chuyên sâu, viết lại là lãng phí |
| Lõi nghiệp vụ (16 service, 37 model) | Laravel | **Giữ, nhưng phải siết** | Đây mới là chỗ đang hỏng |

Nếu sau này thật sự cần Next.js cho `/publisher` (ví dụ media owner đòi app di động), làm ở giai đoạn 5, khi hợp đồng API đã ổn định.

---

## 4. Điều kiện tiên quyết — không được bỏ qua

**Không bắt đầu Next.js trước khi xong nhóm này.** Lý do đơn giản: API sắp trở thành cửa duy nhất vào hệ thống. Nếu cửa đó hở, mọi thứ sau đều hở theo.

| # | Việc | Nguồn |
|---|---|---|
| T1 | Phân quyền ở tầng miền: policy cho mọi hành động, `HasOwnerScope` chặn mặc định, tách quyền token đọc và token ghi | P0 mục 2, R02 |
| T2 | Giá do máy chủ quyết định, có đóng băng giá | P0 mục 3, R03 |
| T3 | Cổng bán hàng dùng chung cho mọi lối vào | P0 mục 5, R05 |
| T4 | Giới hạn tần suất và chặn SSRF | P0 mục 10, R10 |
| T5 | CI chạy test trên MySQL, có bước chặn an toàn | P0 mục 0.1, R01 |

Ba việc T1, T2, T3 đều nằm ở service. Khi giao diện gọi qua API, chúng càng bắt buộc — Filament còn có lớp giao diện che bớt, API thì không.

---

## 5. Thiết kế API

### 5.1. Nguyên tắc

- **Một tầng miền, nhiều cửa.** Filament, API, lệnh artisan đều gọi cùng service. Không nhân đôi logic. Hiện `InventoryController` và `FrontpageService` đã trôi khỏi nhau — đó là bài học.
- **Không đụng `/api/v1` của đối tác.** Mở `/api/v2` cho ứng dụng nội bộ. Hai nhóm người dùng khác nhau, vòng đời khác nhau.
- **DTO danh sách trắng.** Không trả thẳng model. Audit đã thấy API trả cả tỷ lệ chia doanh thu và thông tin ngân hàng của owner.
- **Có đặc tả OpenAPI**, sinh kiểu TypeScript cho Next.js từ đó. Không chép tay kiểu dữ liệu ở hai nơi.
- **Lỗi có định dạng thống nhất** như `docs/instructions.md` đã quy định: `{error, message, code, details[]}`.

### 5.2. Xác thực

Ba nhóm người gọi, ba cách:

| Nhóm | Cách | Ghi chú |
|---|---|---|
| Người mua, media owner (trình duyệt) | **Sanctum dạng SPA, cookie phiên** | An toàn hơn để token trong localStorage. Cần bật `EnsureFrontendRequestsAreStateful` — hiện **chưa bật**; cần `SANCTUM_STATEFUL_DOMAINS`, CORS cho gửi cookie, và cùng miền cha (`oohx.net` và `api.oohx.net`) |
| Đối tác | Token Sanctum như hiện tại | Giữ nguyên `/api/v1` |
| Next.js phía máy chủ | Gọi nội bộ kèm token dịch vụ | Cho phần render tĩnh và ISR, không cần phiên người dùng |

**[CẦN QUYẾT ĐỊNH]** Tên miền: `oohx.net` cho Next.js và `api.oohx.net` cho Laravel. Cách này giữ được cookie cùng miền cha. Nếu để Next.js proxy toàn bộ (`oohx.net/api/*` → Laravel) thì đơn giản hơn về cookie nhưng thêm một chặng.

### 5.3. Nhóm endpoint cần có (v2)

```
Công khai (không cần đăng nhập, phục vụ SSR/ISR)
  GET  /api/v2/public/screens            lọc, sắp xếp, phân trang
  GET  /api/v2/public/screens/{slug}
  GET  /api/v2/public/screens/map        bắt buộc có khung nhìn + giới hạn
  GET  /api/v2/public/owners[/{slug}]
  GET  /api/v2/public/products[/{slug}]
  GET  /api/v2/public/taxonomies         tỉnh/thành, loại địa điểm, mạng lưới
  GET  /api/v2/public/policies/{slug}    nội dung trang pháp lý

Người mua (phiên đăng nhập)
  POST /api/v2/auth/{login,register,logout}
  GET|POST|PATCH|DELETE /api/v2/cart[/items/{id}]
  POST /api/v2/campaigns                 tạo từ giỏ
  GET  /api/v2/campaigns[/{id}]
  POST /api/v2/campaigns/{id}/submit
  POST /api/v2/campaigns/{id}/creatives
  GET  /api/v2/campaigns/{id}/payments   + POST xác nhận đã chuyển khoản
  GET  /api/v2/campaigns/{id}/report
  POST /api/v2/owners/{id}/reviews

Media owner (giai đoạn 4-5)
  GET  /api/v2/owner/bookings[/{id}]     + duyệt/từ chối
  CRUD /api/v2/owner/{sites,screens,networks,products}
  GET  /api/v2/owner/availability
```

Mỗi endpoint phải có: policy, kiểm tenant, phân trang, DTO danh sách trắng, và test cho cả trường hợp bị từ chối.

---

## 6. Thiết kế phía Next.js

| Hạng mục | Lựa chọn đề xuất | Lý do |
|---|---|---|
| Phiên bản | Next.js App Router, TypeScript | Chuẩn hiện nay, hợp với render phía máy chủ |
| Cách render | Trang danh mục và chi tiết: tĩnh hóa có làm mới (ISR). Khu người mua: render phía máy chủ theo yêu cầu | Danh mục cần SEO, khu người mua cần dữ liệu tươi |
| Gọi dữ liệu | Server Component gọi thẳng API; phần tương tác dùng Route Handler làm cầu nối | Không lộ token dịch vụ ra trình duyệt |
| Kiểu dữ liệu | Sinh từ OpenAPI | Chống lệch hợp đồng |
| Bản đồ | MapLibre hoặc giữ Leaflet | Cần gom cụm phía máy chủ, xem P0 mục 11.6 |
| Giao diện | Tailwind + một bộ component | CSS hiện tại là một file `frontpage.css` lớn, nên viết lại |
| Ngôn ngữ | Tiếng Việt là mặc định, chuẩn bị sẵn khung đa ngữ | Hồ sơ TMĐT yêu cầu 100% tiếng Việt |
| Triển khai | Container Node sau Caddy, cùng VPS hoặc tách | Giữ dữ liệu trong nước; tránh phụ thuộc nền tảng ngoài |

**SEO là chỗ dễ mất nhất.** Bắt buộc:
- Giữ **nguyên đường dẫn**: `/explore/{slug}`, `/owners/{slug}`, `/products/{slug}`, các trang chính sách.
- Chuyển `sitemap.xml` và `robots.txt` sang Next.js, giữ đúng nội dung.
- Giữ dữ liệu có cấu trúc (JSON-LD) và thẻ canonical đang có ở `detail.blade.php` và `owner-detail.blade.php`.
- Chuyển hướng 301 cho mọi URL cũ không còn dùng.
- So sánh trước và sau bằng công cụ thu thập dữ liệu, không đoán.

---

## 7. Lộ trình

Mỗi giai đoạn phải **chạy được và lên production** trước khi sang giai đoạn sau. Không có nhánh sống nhiều tháng.

### Giai đoạn 0 — Siết lõi (điều kiện tiên quyết)
T1 đến T5 ở mục 4. Không viết dòng Next.js nào.
**Xong khi:** phân quyền có test; giá không can thiệp được từ client; CI chặn merge khi test đỏ.
**Quy mô:** L.

### Giai đoạn 1 — Dựng API v2 cho phần công khai
Endpoint công khai, đặc tả OpenAPI, sinh kiểu TypeScript, bộ nhớ đệm và cách xóa đệm.
**Xong khi:** Blade hiện tại đọc dữ liệu **qua chính API đó** (tự mình ăn món mình nấu), không còn gọi thẳng service. Đây là bước chống lệch hợp đồng tốt nhất.
**Quy mô:** M.

### Giai đoạn 2 — Next.js cho trang công khai
Trang chủ, danh sách, bản đồ, chi tiết, owner, sản phẩm, trang chính sách, sitemap.
Chạy song song: Next.js ở môi trường thử, Blade vẫn phục vụ thật. Khi đạt ngang bằng thì chuyển tên miền.
**Xong khi:** đối chiếu SEO không tụt; các trang pháp lý giữ nguyên nội dung và đường dẫn; thời gian phản hồi tốt hơn hiện tại.
**Quy mô:** L.

### Giai đoạn 3 — Next.js cho khu người mua
Giỏ hàng, đặt chỗ, tải nội dung, thanh toán, chiến dịch, báo cáo. Cần xong giai đoạn 0 vì phần này động tới tiền.
**Xong khi:** một chiến dịch đi trọn vòng trên Next.js; luồng Blade cũ bị gỡ.
**Quy mô:** L.

> **Cân nhắc lại vì chỉ có một người làm Next.js (chốt 26/09):** giai đoạn 3 nên **hoãn**, không làm liền sau giai đoạn 2. Khu người mua nằm sau đăng nhập nên **không có lợi ích SEO**; Blade hiện tại vẫn chạy được. Một người làm xong trang công khai rồi mới tính tiếp là hợp lý hơn là mở hai mặt trận. Xem mục 12.

### Giai đoạn 4 — Dọn Blade
Gỡ view cũ, gỡ route cũ, gỡ `FrontpageService` phần render. Giữ Laravel làm API và Filament admin.
**Quy mô:** S.

### Giai đoạn 5 — (Tùy chọn) Next.js cho media owner
Chỉ làm khi có lý do rõ ràng. Trước đó `/publisher` vẫn dùng Filament.
**Quy mô:** L.

**Không có giai đoạn nào cho `/admin`.** Nếu sau này vẫn muốn bỏ Filament ở đó, đó là một dự án riêng, cần lý do riêng.

---

## 8. Ba thứ dễ vỡ khi chuyển đổi

**1. Phân quyền nhân đôi.** Filament kiểm quyền trong Resource; Next.js sẽ kiểm ở API. Nếu hai bên lệch nhau thì có đường vòng. Cách phòng: quyền chỉ định nghĩa **một chỗ** (policy + `TenantPermission`), Filament và API cùng gọi. Kèm test ma trận vai trò chạy qua cả hai lối.

**2. Tệp riêng tư.** Giấy phép kinh doanh và nội dung quảng cáo đang dựa vào cơ chế của Filament. Next.js phải dùng URL ký hạn từ Laravel. Không bao giờ trả đường dẫn tệp trực tiếp.

**3. Trang pháp lý.** Quy chế, chính sách bảo mật, cơ chế tranh chấp, tiếp nhận phản ánh TCXH, banner chế độ thử nghiệm, checkbox chấp thuận kèm bằng chứng — tất cả đang phục vụ hồ sơ với Sở Công Thương. Khi chuyển sang Next.js, bằng chứng chấp thuận (`policy_consents`, kèm phiên bản chính sách, IP, thời điểm) vẫn phải được ghi **ở phía máy chủ**, không phải ở trình duyệt.

---

## 9. Điều cần thống nhất trước khi khởi động

| # | Câu hỏi | Ảnh hưởng |
|---|---|---|
| 1 | Đội có người làm Next.js thành thạo không, mấy người? | Quyết định phạm vi. Nếu chỉ một người thì nên dừng ở giai đoạn 2 |
| 2 | Tên miền: `api.oohx.net` tách riêng hay Next.js proxy? | Cách xác thực và cấu hình cookie |
| 3 | Có chấp nhận đóng băng tính năng mới trong lúc chuyển không? | Nếu không, phải nuôi hai giao diện lâu hơn |
| 4 | ~~Hồ sơ TMĐT đang chờ duyệt~~ | **Đã xong 26/09** — Sở xem xong, không còn ràng buộc thời điểm. Vẫn phải giữ nguyên nội dung và đường dẫn các trang pháp lý khi chuyển sang Next.js |
| 5 | Có kế hoạch mobile app không? | Nếu có thì API v2 phải tính trước |

---

## 10. Nếu vẫn muốn bỏ Filament hoàn toàn

Tôi nêu để anh cân nhắc trên số liệu, không phải để can:

- 113 file resource admin, trong đó khoảng 40 thuộc cụm Data Engine với bảng cấu hình, nhật ký, trang health, trang analytics.
- Mỗi resource trung bình cần: bảng có lọc và sắp xếp, form nhiều mục, hành động hàng loạt, phân quyền, xuất dữ liệu. Làm tay bằng React là **hàng tuần cho mỗi nhóm resource**, không phải hàng ngày.
- Đổi lại: một giao diện quản trị đẹp hơn cho khoảng vài người dùng nội bộ.

So sánh: cùng khối lượng công đó, đội có thể làm xong toàn bộ P0 giao dịch — RFQ, báo giá có phiên bản, giữ chỗ, đối soát — tức là những thứ trực tiếp tạo doanh thu.

Nếu vẫn quyết bỏ, làm **sau cùng**, khi Next.js đã ổn định ở ba khu vực kia, và chấp nhận đây là dự án riêng nhiều tháng.

---

## 11. Trạng thái quyết định

**Đã chốt 26/09/2026:**

- Hướng đi: **API-first**. `CLAUDE.md` đã được viết lại theo mô hình này — Laravel là miền nghiệp vụ và API, Next.js cho phần khách hàng, Filament giữ cho `/admin` và `/publisher`, mọi nghiệp vụ nằm ở service dùng chung. File này được git theo dõi nên cả đội nhận được khi commit.
- Hồ sơ TMĐT đã được Sở xem xong, không còn chặn thời điểm.

- **Đội Next.js: một người.** Phạm vi đã được điều chỉnh, xem mục 12.
- **Tên miền: `api.oohx.net`** cho Laravel, `oohx.net` cho Next.js. Cấu hình xác thực cụ thể ở mục 13.

**Còn chờ:** câu 3 và câu 5 ở mục 9 — có đóng băng tính năng mới trong lúc chuyển không, và có kế hoạch mobile app không.

---

## 12. Điều chỉnh phạm vi khi chỉ có một người làm Next.js

Một người nghĩa là công việc **nối tiếp nhau**, không song song. Nên tôi cắt bớt tham vọng thay vì kéo dài vô hạn.

### Làm gì

| Ưu tiên | Việc | Vì sao |
|---|---|---|
| 1 | **Trang công khai** trên Next.js: trang chủ, danh sách, bản đồ, chi tiết màn hình, owner, sản phẩm, 5 trang chính sách, 2 trang phản ánh, đăng nhập/đăng ký | Khoảng 15-18 route. Đây là nơi duy nhất Next.js mang lại lợi ích đo được: SEO và tốc độ |
| 2 | **Dừng lại, đo, rồi mới quyết** | Sau khi trang công khai chạy thật một thời gian, nhìn số liệu rồi quyết có làm tiếp khu người mua không |
| 3 | Khu người mua — **chỉ khi** có thêm người, hoặc trang công khai đã ổn định và không tốn công bảo trì | Không có lợi ích SEO; Blade đang chạy được |

### Không làm

- Không đụng `/publisher` và `/admin`. Với một người thì đây là điều hiển nhiên, nhưng cần ghi ra để không ai đề xuất lại giữa chừng.
- Không dựng hệ thống thiết kế riêng. Dùng Tailwind cùng một bộ component có sẵn. Lấy màu, khoảng cách, bo góc từ `resources/css/frontpage.css` hiện tại để giao diện không lệch.

### Giảm rủi ro một người

| Rủi ro | Cách xử lý |
|---|---|
| Người đó nghỉ hoặc bận việc khác thì dự án đứng | Quy ước chặt và thống nhất; kiểu dữ liệu sinh tự động từ OpenAPI; PR nhỏ; README dựng môi trường trong 10 phút. Mục tiêu: một lập trình viên Laravel đọc được và sửa được trang đơn giản |
| Làm nhiều tháng không ai thấy kết quả | Lên production sớm theo từng trang, không chờ xong hết. Ví dụ: đưa `/explore` và `/explore/{slug}` lên trước, các trang còn lại vẫn do Blade phục vụ |
| Hai giao diện lệch hành vi | Blade và Next.js **cùng đọc một API** (giai đoạn 1). Nếu lệch thì lệch ở một chỗ, sửa một chỗ |
| Trang pháp lý bị sót | Chuyển các trang này **cuối cùng**, sau khi mọi thứ khác đã chạy ổn. Giữ nguyên văn bản và đường dẫn |

### Cách chuyển từng phần

Vì hai bên dùng chung tên miền `oohx.net`, đặt Caddy định tuyến theo đường dẫn: đường nào đã có bên Next.js thì trỏ sang Node, còn lại về Laravel. Nhờ vậy không cần chờ xong hết mới đổi.

```
oohx.net/explore*        → Next.js     (chuyển trước)
oohx.net/owners*         → Next.js
oohx.net/*               → Laravel     (phần chưa chuyển)
api.oohx.net/*           → Laravel
dash.oohx.net/*          → Laravel (Filament)
```

---

## 13. Cấu hình xác thực với `api.oohx.net`

Vì `oohx.net` và `api.oohx.net` **cùng tên miền cha**, cookie phiên dùng được và không cần `SameSite=None`. Đây là lý do nên chọn cách này.

### Phía Laravel

| Nơi | Giá trị | Ghi chú |
|---|---|---|
| `bootstrap/app.php` | thêm `$middleware->statefulApi();` | **Hiện chưa có.** Thiếu dòng này thì cookie phiên không được chấp nhận ở route API |
| `SESSION_DOMAIN` | `.oohx.net` | Có dấu chấm đầu để dùng chung giữa các tên miền con |
| `SESSION_SAME_SITE` | `lax` | Đủ, vì hai bên là cùng site |
| `SESSION_SECURE_COOKIE` | `true` | Bắt buộc khi chạy HTTPS |
| `SESSION_DRIVER` | `database` | **Đã đúng sẵn**, không phải đổi |
| `SANCTUM_STATEFUL_DOMAINS` | `oohx.net,www.oohx.net,localhost:3000` | Hiện chưa đặt; mặc định của Sanctum suy từ `APP_URL` nên sẽ thiếu tên miền Next.js |
| `config/cors.php` → `supports_credentials` | `true` | **Hiện là `false`** — không sửa thì trình duyệt không gửi cookie |
| `config/cors.php` → `allowed_headers` | thêm **`X-XSRF-TOKEN`** | Hiện chỉ có `Content-Type, Authorization, X-Requested-With, Accept`. Thiếu header này thì mọi request ghi dữ liệu bị chặn vì CSRF |
| `config/cors.php` → `paths` | phải gồm cả `sanctum/csrf-cookie` | Nếu không thì không lấy được token CSRF |

Đã kiểm trên code ngày 26/09: `allowed_origins` đã liệt kê sẵn `oohx.net`, `www.oohx.net`, `dash.oohx.net` — chỉ cần thêm địa chỉ dev. `SESSION_DOMAIN` hiện là `.oohx.test` (giá trị môi trường phát triển), production phải là `.oohx.net`.

### Phía Next.js

- Trước khi gửi request thay đổi dữ liệu: gọi `GET https://api.oohx.net/sanctum/csrf-cookie` một lần, rồi gửi kèm header `X-XSRF-TOKEN` đọc từ cookie.
- Mọi `fetch` từ trình duyệt phải có `credentials: 'include'`.
- Render phía máy chủ dùng **token dịch vụ** riêng, để trong biến môi trường của Node, không bao giờ gửi xuống trình duyệt.

### Hai điều cần để ý

1. **`dash.oohx.net` sẽ dùng chung cookie phiên** với `oohx.net` nếu để `SESSION_DOMAIN=.oohx.net`. Filament và khu người mua chung một phiên. Chấp nhận được vì cùng một ứng dụng Laravel, nhưng phải nhớ: đăng xuất ở một nơi là đăng xuất tất cả.
2. **Giới hạn tần suất phải tính theo người dùng khi đã đăng nhập**, theo IP khi chưa. Nếu chỉ tính theo IP, toàn bộ người dùng đi qua render phía máy chủ của Next.js sẽ dùng chung một IP và chặn nhầm nhau.
