# Lộ trình sau giai đoạn 0

Cập nhật 27/09/2026 · Viết bởi Claude Code · Trạng thái hiện tại ở `audit-5-vung-2026-09-23/STATUS.md`

Giai đoạn 0 đã xong và xanh: phân quyền API, giá do máy chủ quyết, cổng bán hàng, giới hạn tần suất, CI chặn deploy. Tài liệu này trả lời câu hỏi **tiếp theo làm gì, theo thứ tự nào, và vì sao thứ tự đó**.

Nguyên tắc xuyên suốt: **đóng đường tiền trước, đổi giao diện sau.** Một lỗ hổng trong luồng đặt chỗ — thanh toán làm mất tiền thật hoặc mất uy tín với media owner; một trang chậm thì chỉ là chậm.

---

## Bức tranh tổng thể

| Giai đoạn | Nội dung | Quy mô | Chặn bởi |
|---|---|---|---|
| ~~1~~ | ~~Đóng đường tiền~~ **XONG 29/09** | XL | — |
| ~~1b~~ | ~~Vá nốt phân quyền~~ **XONG 29/09** | M | — |
| ~~2~~ | ~~Sửa đường ghi bằng chứng phát sóng~~ **XONG 29/09** | L | — |
| ~~3~~ | ~~Hợp nhất quan hệ Màn hình ↔ Mạng lưới~~ **XONG 29/09** | M | Còn: chạy `networks:reconcile --dry-run` trên dữ liệu thật |
| **4** | Dọn số liệu bịa trên trang công khai | S | Quyết định của anh |
| **5** | API v2 + OpenAPI, Blade tự đọc qua API | M | 1–3 đã xong — sẵn sàng bắt đầu |
| **6** | Next.js cho trang công khai | L | Xong 5 |
| **7** | Dọn Blade công khai | S | Xong 6 |
| **8** | *(hoãn)* Next.js cho khu người mua | L | Có thêm người |

Giai đoạn 1b chạy song song được với 1 vì không đụng cùng file. Các giai đoạn còn lại nối tiếp.

---

## Giai đoạn 1 — Đóng đường tiền

Đây là phần nguy hiểm nhất còn lại. Sáu việc, xếp theo mức độ nguy hiểm.

### 1.1 Giữ chỗ nguyên tử và có hạn (F-02 / Codex F05) — **nguy hiểm nhất**

Hiện tại **không có khóa nào**. Hai người mua cùng một suất trên cùng một màn hình, gửi booking cùng lúc, thì **cả hai đều lọt**. Giai đoạn 0 mới sửa phép tính SOV theo ngày cao điểm, không đụng tới tranh chấp đồng thời.

Cần: bảng giữ chỗ có thời hạn, khóa ở tầng CSDL (`SELECT ... FOR UPDATE` hoặc ràng buộc duy nhất trên khoảng thời gian), job dọn giữ chỗ hết hạn, và trả lỗi rõ ràng khi suất đã bị người khác lấy.

**Xong khi:** test tranh chấp chạy nhiều kết nối thật, hai luồng cùng đặt một suất thì đúng một luồng thắng, luồng kia nhận lỗi có nghĩa.

**Câu hỏi cần anh trả lời:** giữ chỗ hết hạn sau bao lâu — 15 phút, 24 giờ, hay tới khi thanh toán?

### 1.2 Mở gói hàng thành nhiều dòng đặt chỗ (Codex R05)

Gói hiện được lưu như **một dòng**, nên không biết gói gồm màn hình nào tại thời điểm mua. Owner đổi thành phần gói sau đó thì đơn cũ trôi theo — vi phạm quy tắc "booking đã xác nhận giữ nguyên giá và điều khoản".

Cần: khi chốt đơn, mở gói thành N dòng booking và **chụp lại** thành phần gói.

**Câu hỏi cần anh trả lời:** gói có được gồm màn hình của nhiều media owner không? Nếu có thì chia tiền theo tỉ lệ nào?

### 1.3 Chặn phát sóng khi nội dung chưa duyệt (F-06)

Có enum trạng thái duyệt nhưng **không có đường chạy thật**: nội dung chưa duyệt vẫn ra được lịch phát. Với một sàn quảng cáo ngoài trời thì đây là rủi ro pháp lý, không chỉ rủi ro kỹ thuật.

Cần: bảng nối `booking_line_creatives`, chặn ở bước sinh lịch phát, và ghi nhật ký ai duyệt lúc nào.

### 1.4 Thanh toán theo công nợ từng media owner (F07 / F12)

Một chiến dịch mua màn hình của nhiều owner nhưng tiền đang gom một cục. Không trả lời được câu "owner này đã nhận đủ tiền chưa". Thiếu cả chống trùng khi người mua bấm thanh toán hai lần, và số hóa đơn có thể trùng.

**Câu hỏi cần anh trả lời:** chiến dịch chuyển sang "đang chạy" khi **một** owner đủ tiền hay khi **tất cả** đủ? Media owner có được tự xác nhận đã nhận tiền không?

### 1.5 Hủy, hoàn tiền, khiếu nại

Enum có, code không. Khách hủy thì hiện không có đường nào xử lý.

**Câu hỏi cần anh trả lời:** chính sách hủy và hoàn tiền — hủy trước bao lâu thì hoàn bao nhiêu phần trăm?

### 1.6 Rate card có phiên bản, bậc giá theo thời lượng

Hiện 12 kỳ = 12 lần một kỳ, không có chiết khấu. Đổi giá là đổi thẳng, không lưu lịch sử. Giai đoạn 0 đã đóng băng giá **trong giỏ**, nhưng bảng giá gốc vẫn không có phiên bản.

---

## Giai đoạn 1b — Vá nốt phân quyền (T1b)

Giai đoạn 0 mới siết phía API. Còn lại:

- Quyền cho controller web của người mua (gửi booking, thanh toán, cài đặt).
- Quyền cho action Filament (duyệt/từ chối booking, CRUD sản phẩm).
- `canAccessPanel` phải kiểm **owner đang chọn** còn hoạt động, không chỉ "có owner nào đó còn hoạt động".
- DTO lọc trường cho Site và Screen; `price_per_slot_vnd` đang lấy từ **giá sàn nội bộ** (F09).
- Quyền giá trong form Filament — hiện mới ép ở tầng API, form vẫn chỉ ẩn trường.

Việc nhỏ nhưng là đúng loại lỗ hổng mà audit đã bắt được một lần: **quyền định nghĩa hai chỗ thì sớm muộn lệch nhau.**

---

## Giai đoạn 2 — Sửa đường ghi bằng chứng phát sóng (F-04 / F08) — **ĐÃ XONG 29/09/2026**

`impression_logs` hiện **không ghi được**: khóa chính `char(26)` không có giá trị mặc định và model thiếu trait `HasUlids` — đã dựng probe tái hiện, lỗi SQLSTATE 1364. Nghĩa là toàn bộ bằng chứng phát sóng đang không có thật.

Cần: sửa schema và model, xác thực thiết bị player, chống trùng (khóa duy nhất **phải chứa cột phân vùng** `played_at`, bảng đang có 6 phân vùng), và bảng tổng hợp để báo cáo không quét bảng thô.

**Đã trả lời 29/09/2026: chưa có thiết bị nào gửi dữ liệu thật.** Nghĩa là được làm lại schema cho sạch, không phải viết migration bảo toàn dữ liệu — rẻ hơn nhiều. Vẫn phải giữ ràng buộc của bảng phân vùng: mọi khóa unique phải chứa `played_at`.

---

## Giai đoạn 3 — Hợp nhất quan hệ Màn hình ↔ Mạng lưới (F-12) — **ĐÃ XONG 29/09/2026**

Hai đường quan hệ song song, nên đếm và lọc theo mạng lưới ra kết quả khác nhau tuỳ đi đường nào. Đây là nguyên nhân **7 ca test đang bị loại khỏi CI**.

Cần migration trên dữ liệu production: chạy thử, đối chiếu số lượng trước/sau, rồi mới chạy thật. Xong thì **gỡ nhãn `#[Group]`** và bỏ `--exclude-group` khỏi workflow — điều kiện này đã ghi sẵn trong hai file test và trong `tests.yml`.

---

## Giai đoạn 4 — Dọn số liệu bịa trên trang công khai (F-15)

"30M+ lượt hiển thị", bộ đếm impression chạy bằng `Math.random()`, "AI Match 94%", "fill rate 40%", badge "Còn trống" in vô điều kiện, nút CTA không có hành vi. Với một sàn đang nộp hồ sơ TMĐT thì đây là rủi ro pháp lý chứ không phải chuyện thẩm mỹ.

Nhỏ về công sức, nhưng cần anh quyết: thay bằng số thật (nếu có) hay gỡ hẳn.

---

## Giai đoạn 5 — API v2 và OpenAPI

Endpoint công khai cho phần khám phá, đặc tả OpenAPI làm nguồn sự thật, sinh kiểu TypeScript từ đó.

Điểm mấu chốt: **Blade hiện tại phải đọc dữ liệu qua chính API đó**, không gọi thẳng service nữa. Tự mình ăn món mình nấu là cách chống lệch hợp đồng tốt nhất — nếu API sai thì trang đang chạy sai ngay, phát hiện được liền, thay vì để Next.js phát hiện sau vài tháng.

Đây cũng là lúc trả món nợ cũ: `InventoryController` và `FrontpageService` từng trôi khỏi nhau và gây rò rỉ dữ liệu chưa duyệt.

### Mốc 1 đã làm — và một chỗ lệch có chủ ý (30/09/2026)

Đã có: bốn endpoint đọc (`stats`, `screens`, `screens/{slug}`, `owners`), DTO danh sách trắng, giới hạn cứng 50 bản ghi mỗi trang, định dạng lỗi thống nhất chỉ áp cho `api/v2/*`, `docs/openapi/v2.yaml`, và `OpenApiContractTest` đối chiếu hai chiều đặc tả ↔ bảng route. 15 test mới, CI xanh (run `36702771684`).

**Chỗ lệch:** mục trên viết "Blade phải đọc dữ liệu qua chính API đó, không gọi thẳng service nữa". Mốc 1 **không** làm vậy. `CatalogController` gọi thẳng `FrontpageService` — đúng lớp truy vấn Blade đang dùng — nên API và trang dùng chung một đường truy vấn, khác nhau ở DTO chứ không ở truy vấn.

**Được gì:** món nợ cũ (`InventoryController` trôi khỏi `FrontpageService`) không thể lặp lại, vì chỉ còn một truy vấn. Không thêm một vòng HTTP nội bộ cho mỗi lần dựng trang.

**Mất gì — nói thẳng:** lý lẽ "tự mình ăn món mình nấu" của lộ trình nhắm vào một thứ mà cách làm này **không** che được. Tầng nằm trên service — DTO, phân trang, định dạng lỗi của controller — chỉ được test canh, chứ không được lưu lượng thật của trang công khai chạy qua mỗi ngày. Một lỗi ở tầng đó sẽ không làm trang đang chạy hỏng, nên sẽ không ai thấy cho tới khi Next.js gọi vào. Đúng kịch bản mà lộ trình muốn tránh.

**Nên xử lý thế nào:** giữ như hiện tại cho phần *đọc*, nhưng khi tới nhóm cần quyền (giỏ hàng, đặt chỗ, thanh toán) thì Next.js là bên tiêu thụ duy nhất và đường đó buộc phải đi qua HTTP thật. Nếu người rà soát thấy rủi ro trên không chấp nhận được, cách đóng lại là cho Blade gọi API qua HTTP ở đúng những trang danh mục — chi phí là một vòng HTTP nội bộ mỗi lần dựng trang. Quyết định này để mở.

### Mốc 2 đã làm (30/09/2026)

Ba endpoint: `screens/map`, `filters`, `owners/{slug}`. 27 test mới, CI xanh (run `36705780699`, 527 test, 0 lỗi).

**Khung nhìn bắt buộc cho bản đồ** — điều khoản CLAUDE.md mục 2. Thiếu một cạnh là 422. Lý do bắt buộc chứ không mặc định: không có khung nhìn thì "lấy pin bản đồ" nghĩa là lấy mọi màn hình có toạ độ, tức một lần xuất toàn bộ kho dưới cái tên vô hại. Chỗ bắt buộc đặt ở `MapViewportRequest` chứ không ở service, nên đường gọi nào quên cũng đỏ ngay.

Trên khung nhìn vẫn còn **giới hạn cứng 500 pin**, vì khung nhìn rộng vẫn trùm được cả kho. Khi bị cắt thì `meta.truncated` và `meta.total` nói ra — tổng đếm **trong khung nhìn đang hỏi**, không phải cả kho, vì đếm cả kho là đẩy client thu nhỏ khung nhìn mãi mà không bao giờ hết. Pin bị cắt sắp theo thứ tự xác định.

`getMapPins()` nhận hai tham số **tùy chọn** nên trang Blade giữ nguyên hành vi, và có `MapUnchangedByApiTest` canh điều đó — trước mốc này không test nào che trang bản đồ Blade.

**Chưa có:** sản phẩm/gói (`/products/{slug}`) và toàn bộ nhóm cần quyền (giỏ hàng, đặt chỗ, thanh toán). Đó là mốc 3.

---

## Đóng nợ — hủy/hoàn tiền có lối vào thật (30/09/2026)

Xen giữa mốc 2 và mốc 3, vì đây là chỗ duy nhất tôi **báo xong mà chưa xong**.

Giai đoạn 1 tôi báo "hủy và hoàn tiền: xong". Thực tế `CancellationService` không được gọi từ đâu cả — không controller, không route, không Filament action, chỉ có test của chính nó. Chính sách bậc thang đã chốt (≥14 ngày 100%, 7–13 ngày 50%, <7 ngày 0%) chỉ tồn tại trong test. Khách muốn hủy thì không ai bấm được, và với một sàn đang nộp hồ sơ TMĐT thì hoàn tiền là nghĩa vụ chứ không phải tiện ích.

Đã có, ba lối vào: người mua tự hủy từ `/my/campaigns/{campaign}` kèm số tiền hoàn dự kiến do máy chủ tính; quản trị sàn hủy hộ từ `/admin` và có danh sách hoàn tiền; media owner thấy nghĩa vụ hoàn tiền của mình ở `/publisher` và khai "đã hoàn". 23 test mới, CI xanh (run `36709847798`, 550 test, 0 lỗi).

Hai thứ rút ra, ghi lại để không lặp:

- **Bản đầu tôi lại viết hai bộ luật quyền** — phép kiểm nằm trong cả hai `RefundResource`, và sẽ thành ba bộ khi có endpoint v2. Gom về `RefundPolicy` (CLAUDE.md mục 4). Cũng phát hiện `TenantPermission::check()` đọc `auth()->user()`, nên một policy dùng nó sẽ trả lời về người đang đăng nhập chứ không về người được hỏi.
- **Test "nhả suất về kho" dựng `BookingLine` bằng tay thì xanh giả**: không có `InventoryHold` nào nên không có gì để nhả. Phải đi qua giỏ hàng thật.

**Chưa làm:** không có thông báo (email/in-app) khi một dòng bị hủy — media owner phải tự mở trang mới thấy nghĩa vụ hoàn tiền. Cũng chưa có hạn xử lý cho khoản `pending`, nên một khoản có thể chờ mãi mà không ai bị nhắc.

---

## Giai đoạn 6 — Next.js cho trang công khai

Khoảng 15–18 route: trang chủ, danh sách, bản đồ, chi tiết màn hình, owner, sản phẩm, 5 trang chính sách, 2 trang phản ánh, đăng nhập/đăng ký.

Vì chỉ có **một người làm Next.js**, phạm vi đã thu hẹp và cách làm là **chuyển từng phần**: Caddy định tuyến theo đường dẫn, đường nào đã có bên Next.js thì trỏ sang Node, còn lại về Laravel. Không chờ xong hết mới đổi.

```
oohx.net/explore*   → Next.js   (chuyển trước)
oohx.net/owners*    → Next.js
oohx.net/*          → Laravel   (phần chưa chuyển)
api.oohx.net/*      → Laravel
dash.oohx.net/*     → Laravel (Filament)
```

Trang pháp lý chuyển **cuối cùng**, giữ nguyên văn bản và đường dẫn.

**Xong khi:** đối chiếu SEO không tụt, sitemap và canonical giữ nguyên, thời gian phản hồi tốt hơn hiện tại.

---

## Giai đoạn 7 — Dọn Blade công khai

Gỡ view cũ, route cũ, phần render của `FrontpageService`. Laravel còn lại làm API và Filament admin.

---

## Giai đoạn 8 — Khu người mua trên Next.js *(đang hoãn)*

Nằm sau đăng nhập nên **không có lợi ích SEO**, mà lại đụng tới tiền. Blade hiện chạy được. Chỉ làm khi có thêm người hoặc trang công khai đã ổn định và không tốn công bảo trì.

Không có giai đoạn nào cho `/admin` và `/publisher`. Nếu sau này vẫn muốn bỏ Filament ở đó, đó là một dự án riêng cần lý do riêng.

---

## Việc quản trị, làm lúc nào cũng được

- **Xoay khóa deploy** đang nằm trong git (F-07) — thao tác trên VPS.
- **Đổi remote git** sang địa chỉ mới: `git remote set-url origin https://github.com/TRUEVIEWVIETNAM/oohx-dash.git`.
- **Dọn cảnh báo PHPUnit** (299 cảnh báo metadata viết trong doc-comment) — không phải lỗi, nhưng nhiều cảnh báo thì cảnh báo thật sẽ chìm.

---

## Bảy câu hỏi nghiệp vụ đang chặn việc

| # | Câu hỏi | Chặn |
|---|---|---|
| 1 | Giữ chỗ hết hạn sau bao lâu? | 1.1 |
| 2 | Có đối tác nào đang **ghi** dữ liệu qua API không? | Deploy giai đoạn 0 lên production |
| 3 | Chính sách hủy và hoàn tiền? | 1.5 |
| 4 | Chiến dịch chạy khi một owner đủ tiền hay tất cả? | 1.4 |
| 5 | Media owner có được tự xác nhận đã nhận tiền? | 1.4 |
| ~~6~~ | ~~Có thiết bị player nào đang gửi dữ liệu thật?~~ **Đã trả lời 29/09: chưa có.** | — |
| 7 | Gói có gồm màn hình nhiều owner? Chia tiền thế nào? | 1.2 |

~~Thêm một câu treo từ giai đoạn 0: **khoảng ngày tối đa cho một dòng đặt chỗ** — đang tạm 365 ngày, có nới lên 730 hay bỏ hẳn không.~~ **Đã trả lời 01/10/2026: giữ 365 ngày.**

### Câu hỏi mới, phát sinh ngày 01/10/2026

**Chính sách tỷ giá cho giá niêm yết ngoài VND.** Đã xác nhận dữ liệu thật có màn hình niêm yết bằng USD. Đường tiền không mang đơn vị (`cart_items.estimated_cost` và `booking_lines.estimated_cost` **không có cột currency**), nên nhánh CPM từng cho ra hóa đơn thấp hơn giá thật khoảng 25.000 lần. Hiện **chặn đặt trực tuyến** với giá ngoài VND, vì tự quy đổi là đặt ra một chính sách giá mà không ai duyệt.

Cần quyết ba điều trước khi mở lại: **tỷ giá lấy từ đâu** (cố định trong config, hay nguồn ngoài), **chụp lại lúc nào** (lúc thêm giỏ, lúc chốt đơn, hay lúc xuất hóa đơn), và **ai chịu rủi ro** khi tỷ giá đổi giữa hai mốc đó. `cart_items.rate_snapshot` đã có sẵn chỗ để chụp.

Phương án khác, tránh hẳn chuyện tỷ giá: yêu cầu media owner niêm yết bằng VND, và chuyển đổi dữ liệu USD hiện có một lần.

---

## Đề xuất làm gì ngay

1. **Trả lời câu hỏi 2** trước tiên — nó chặn việc đưa toàn bộ giai đoạn 0 lên production, mà code đã xong và xanh rồi.
2. **Cho Codex review giai đoạn 0** (`audit-5-vung-2026-09-23/REVIEW-REQUEST-T3-T4-CLAUDE.md`), sửa xong thì merge và deploy.
3. **Bắt đầu 1.1 giữ chỗ nguyên tử** — nguy hiểm nhất, và chỉ cần trả lời một câu hỏi.
