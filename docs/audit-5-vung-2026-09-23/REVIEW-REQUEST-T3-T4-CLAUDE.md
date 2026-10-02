# Yêu cầu review T3 + T4 + CI — hết giai đoạn 0

Người viết: Claude Code · 27/09/2026 · Trên nền `38a97c9` (T2). Các commit của đợt này liệt kê ở mục 2.

Đây là **gói cuối của giai đoạn 0**. Chủ dự án yêu cầu Claude code xong toàn bộ rồi Codex review một lượt, nên tài liệu này gộp ba phần: cổng bán hàng (T3), giới hạn tần suất + webhook (T4), và CI. Kèm theo là ba việc nhỏ phát hiện dọc đường.

Trạng thái tổng thể — đã làm gì, chưa làm gì, câu hỏi nghiệp vụ nào còn treo — ở `STATUS.md`. Cách tái lập ở `REPRODUCE-CLAUDE.md`.

---

## 1. Kết quả test

| Bộ test | Kết quả |
|---|---|
| `PurchaseGateFlowTest` (T3, 4 ca) + `RateLimitAndWebhookTest` (T4, 17 ca) | **21 passed** |
| Full suite | **307 tests, 297 passed, 10 failed** — đúng 10 ca hỏng baseline |

Mốc so sánh: 229/219/10 (baseline `112e2aa`) → 257/247/10 (T1a) → 281/271/10 (T2) → 286/276/10 (tháng lịch + VAT) → **307/297/10** (đợt này). Evidence: `evidence-claude/run-20260927T040645Z-2863/`.

10 ca hỏng baseline **không thuộc phạm vi đã sửa** và giữ nguyên qua mọi đợt: 7 ca do hai đường quan hệ Màn hình ↔ Mạng lưới (F-12), 2 ca `InvitationFlowTest`, 1 ca `PanelAccessTest`.

---

## 2. T3 — cổng bán hàng phủ hết các chuyển trạng thái

**Vấn đề:** `PurchaseEligibilityService` (viết ở T1a) mới được gọi lúc **thêm vào giỏ**. Giữa lúc thêm giỏ và lúc gửi booking có thể cách nhau nhiều ngày — đủ để owner bị tạm ngưng hoặc màn hình bị gỡ bán. Ba cửa còn hở: sửa dòng giỏ, tạo chiến dịch từ giỏ, và gửi booking.

| File | Thay đổi |
|---|---|
| `app/Services/CartService.php` | `updateItem()` gọi `assertScreenPurchasable()` trước khi tính lại |
| `app/Services/CampaignService.php` | `createFromCart()` kiểm từng dòng; `submit()` gọi `assertLinesStillPurchasable()` |

**Bốn ca kiểm chứng** (`tests/Feature/Buyer/PurchaseGateFlowTest.php`): sửa giỏ khi màn hình đã gỡ bán → 422; tạo chiến dịch khi owner bị tạm ngưng → 422; gửi booking khi màn hình bị tắt giữa chừng → 422; đường đi bình thường vẫn qua được.

---

## 3. T4 — giới hạn tần suất và webhook

### 3.1 Trước sửa

`bootstrap/app.php` **không gọi `throttleApi()`**, nên toàn bộ `/api/*` chạy không giới hạn. Cụ thể: `/api/v1/auth/token` cho dò `client_secret` không giới hạn lần, `/login` cho dò mật khẩu, endpoint player cho bơm dữ liệu, và proxy geocode công khai gọi thẳng Nominatim mỗi lần gõ phím.

### 3.2 Sau sửa

| Nhóm | Hạn mức | Đếm theo |
|---|---|---|
| `api` (nền) | 300/phút | người dùng nếu đã đăng nhập, nếu chưa thì IP |
| `login` | 5/phút | IP **và** cặp email+IP |
| `token` | 10/phút | IP |
| `player` | 600/phút | `screen_uuid`, không theo IP |
| `geocode` | 20/phút | IP, kèm cache 24 giờ |

Hai quyết định đáng soi:
- Nhóm nền để **rộng** (300) vì nếu chặt hơn giới hạn riêng của player thì nó chặn trước, và giới hạn riêng thành vô nghĩa.
- Player đếm theo thiết bị chứ không theo IP: nhiều màn hình dùng chung một đường truyền là chuyện bình thường ở ngoài hiện trường.

### 3.3 Webhook — ba lỗi, trong đó hai lỗi do test bắt

**(a) SSRF.** `url` trước đây chỉ validate `url`. Nay có `App\Rules\SafePublicUrl`: chặn scheme lạ, thông tin đăng nhập trong URL, cổng lạ, và mọi IP mà tên miền phân giải ra nếu thuộc loopback / mạng riêng / link-local (gồm `169.254.169.254`). Kiểm **hai lớp**: lúc đăng ký và lại lần nữa ngay trước mỗi lần gửi, vì bản ghi DNS đổi được sau khi đăng ký. Job cũng `withoutRedirecting()` — đích công cộng vẫn có thể 302 về mạng nội bộ.

**(b) DNS trục trặc từng tắt vĩnh viễn webhook của đối tác.** Bản đầu tôi coi "không phân giải được" là không an toàn. Test cho thấy hệ quả: một sự cố DNS thoáng qua sẽ tắt subscription và đối tác phải đăng ký lại. Nay tách `UNSAFE` (tắt hẳn) khỏi `UNRESOLVED` (ném ngoại lệ để hàng đợi thử lại).

**(c) `[::1]` lọt qua.** `parse_url` trả host IPv6 **kèm ngoặc vuông**, nên `filter_var` không nhận ra đó là IP và địa chỉ loopback đi thẳng qua vòng kiểm. Sửa bằng `trim($host, '[]')`.

Ngoài ra, sửa nốt phát hiện F18 vòng trước: job gọi `$this->fail()` nên đánh dấu hỏng ngay và không bao giờ thử lại — nay `throw`, còn `failed()` mới là chỗ tắt subscription. `event_id` sinh trong constructor để mọi lần thử lại mang cùng một mã, bên nhận chống trùng được.

---

## 4. CI

`.github/workflows/tests.yml` (mới) chạy full suite trên MySQL 8, có bước chặn an toàn: dừng ngay nếu thấy `bootstrap/cache/config.php`, và ép toàn bộ biến CSDL của test. `deploy.yml` gọi lại chính workflow đó bằng `uses:` và `needs: tests`, nên **deploy chỉ chạy khi test xanh trên đúng SHA sắp lên production**. Trước đây deploy không chạy test gì cả.

---

## 5. Ba việc nhỏ đi kèm

| Việc | Vì sao |
|---|---|
| `ScreenImportService`: bỏ `$import->fresh(['status'])` | Tham số của `fresh()` là danh sách **quan hệ**, không phải cột. Import lớn tới mốc kiểm hủy sẽ hỏng sau khi đã ghi một phần (Codex F13) |
| `BuyerAuthController`: gán vai trò `buyer` khi tự đăng ký | Hai lối onboarding (tự đăng ký / được mời) cho ra kết quả khác nhau (Codex F15). Dùng `Role::findOrCreate` — `assignRole` trần làm **hỏng toàn bộ đăng ký** nếu thiếu hàng seed, test bắt được |
| `ImportSites`: file tải lên sang disk riêng | File import nằm trên disk công khai; có fallback đọc file cũ trên disk `public` |

---

## 6. Xin Codex soi kỹ những chỗ này

1. **Cổng bán hàng còn cửa nào chưa chặn không** — tôi chặn ở thêm giỏ, sửa giỏ, tạo chiến dịch, gửi booking. Còn đường nào ghi được `booking_lines` mà không qua bốn cửa đó?
2. **Hạn mức có chặn nhầm ai không** — đặc biệt Next.js render phía máy chủ (mọi request chung một IP) và cụm màn hình chung đường truyền.
3. **`SafePublicUrl` còn lối vòng nào** — DNS rebinding giữa lúc kiểm và lúc gửi tôi **chưa** đóng (cần pin IP khi kết nối); tôi coi đó là rủi ro chấp nhận được ở giai đoạn này, xin ý kiến.
4. **`failed()` có chắc chạy không** khi hàng đợi cấu hình theo cách hiện tại.
5. **CI**: workflow gọi lại có tạo vòng lặp hay lỗi quyền gì trên repo này không.

## 7. Những gì giai đoạn 0 **không** đóng

Không nằm trong phạm vi và vẫn hở, liệt kê đủ ở `STATUS.md` mục 3: giữ chỗ nguyên tử + TTL (hai người gửi cùng lúc vẫn cùng lọt), mở gói thành N dòng booking, chặn phát sóng khi creative chưa duyệt, thanh toán theo công nợ từng owner, đường ghi bằng chứng phát sóng, luồng hủy/hoàn tiền, rate card có phiên bản. Phần T1b (quyền cho controller người mua và action Filament) cũng chưa xong.
