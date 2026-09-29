# Lộ trình sau giai đoạn 0

Cập nhật 27/09/2026 · Viết bởi Claude Code · Trạng thái hiện tại ở `audit-5-vung-2026-09-23/STATUS.md`

Giai đoạn 0 đã xong và xanh: phân quyền API, giá do máy chủ quyết, cổng bán hàng, giới hạn tần suất, CI chặn deploy. Tài liệu này trả lời câu hỏi **tiếp theo làm gì, theo thứ tự nào, và vì sao thứ tự đó**.

Nguyên tắc xuyên suốt: **đóng đường tiền trước, đổi giao diện sau.** Một lỗ hổng trong luồng đặt chỗ — thanh toán làm mất tiền thật hoặc mất uy tín với media owner; một trang chậm thì chỉ là chậm.

---

## Bức tranh tổng thể

| Giai đoạn | Nội dung | Quy mô | Chặn bởi |
|---|---|---|---|
| **1** | Đóng đường tiền: giữ chỗ, gói, creative, thanh toán, hủy/hoàn | XL | 5 câu hỏi nghiệp vụ |
| **1b** | Vá nốt phân quyền còn thiếu (T1b) | M | — |
| **2** | Sửa đường ghi bằng chứng phát sóng | L | Câu hỏi 6 |
| **3** | Hợp nhất quan hệ Màn hình ↔ Mạng lưới, gỡ nhãn khỏi CI | M | Duyệt migration |
| **4** | Dọn số liệu bịa trên trang công khai | S | Quyết định của anh |
| **5** | API v2 + OpenAPI, Blade tự đọc qua API | M | Xong 1–3 |
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

## Giai đoạn 2 — Sửa đường ghi bằng chứng phát sóng (F-04 / F08)

`impression_logs` hiện **không ghi được**: khóa chính `char(26)` không có giá trị mặc định và model thiếu trait `HasUlids` — đã dựng probe tái hiện, lỗi SQLSTATE 1364. Nghĩa là toàn bộ bằng chứng phát sóng đang không có thật.

Cần: sửa schema và model, xác thực thiết bị player, chống trùng (khóa duy nhất **phải chứa cột phân vùng** `played_at`, bảng đang có 6 phân vùng), và bảng tổng hợp để báo cáo không quét bảng thô.

**Đã trả lời 29/09/2026: chưa có thiết bị nào gửi dữ liệu thật.** Nghĩa là được làm lại schema cho sạch, không phải viết migration bảo toàn dữ liệu — rẻ hơn nhiều. Vẫn phải giữ ràng buộc của bảng phân vùng: mọi khóa unique phải chứa `played_at`.

---

## Giai đoạn 3 — Hợp nhất quan hệ Màn hình ↔ Mạng lưới (F-12)

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

Thêm một câu treo từ giai đoạn 0: **khoảng ngày tối đa cho một dòng đặt chỗ** — đang tạm 365 ngày, có nới lên 730 hay bỏ hẳn không.

---

## Đề xuất làm gì ngay

1. **Trả lời câu hỏi 2** trước tiên — nó chặn việc đưa toàn bộ giai đoạn 0 lên production, mà code đã xong và xanh rồi.
2. **Cho Codex review giai đoạn 0** (`audit-5-vung-2026-09-23/REVIEW-REQUEST-T3-T4-CLAUDE.md`), sửa xong thì merge và deploy.
3. **Bắt đầu 1.1 giữ chỗ nguyên tử** — nguy hiểm nhất, và chỉ cần trả lời một câu hỏi.
