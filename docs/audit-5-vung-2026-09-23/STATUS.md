# Trạng thái công việc — cập nhật 27/09/2026

Một trang duy nhất trả lời: **đã làm gì, đang dở gì, chưa làm gì.** Chi tiết kỹ thuật ở `IMPLEMENTATION-P0-CLAUDE.md`; các vòng review ở `REVIEW-*.md`.

Baseline: `112e2aa`. Ba commit cục bộ (**chưa push**): `b9923a0`, `eb1f32c`, `38a97c9`. Phần T3/T4/CI **chưa commit**, còn ở working tree.

---

## 1. Đã xong

| # | Việc | Commit | Test |
|---|---|---|---|
| T1a | Phân quyền API: policy cho mọi action, tách `ability:manage`, `owner_id` suy từ người đăng nhập, DTO lọc trường nhạy cảm, ẩn `device_token` | `b9923a0` | 28 |
| T1a+ | Scope chặn mặc định khi mất tenant; quyền giá (`manage_pricing`) tách khỏi quyền kho; `TenantPermission` đòi owner còn hoạt động | `b9923a0` | ↑ |
| T2 | Máy chủ quyết định số kỳ và số CPM; `updateItem` tính lại từ ngày; đóng băng giá trong giỏ, chặn chốt đơn khi giá đổi | `eb1f32c` | 24 |
| T2+ | **Tháng lịch** thay quy ước 30 ngày; **VAT 8%** hợp nhất từ 13 chỗ về config | `38a97c9` | ↑ |
| T3 | Cổng bán hàng phủ cả sửa giỏ, chốt đơn, gửi booking | *chưa commit* | 4 |
| T4 | Giới hạn tần suất (api/login/token/player/geocode); chặn SSRF webhook hai lớp; webhook thử lại thật; `event_id` ổn định; cache proxy geocode | *chưa commit* | 17 |
| CI | Workflow test trên MySQL có bước chặn an toàn; deploy **gọi lại** workflow test nên chỉ deploy khi xanh trên đúng SHA | *chưa commit* | — |
| Nhỏ | `fresh(['status'])` sai API; vai trò khi tự đăng ký; file import sang disk riêng | *chưa commit* | — |

**Năm lỗi thật do chính test bắt được, không nhìn ra khi đọc code:**

1. Đóng băng giá **chặn mọi đơn hàng** — MySQL sắp xếp lại khóa cột JSON, `===` báo khác nhau.
2. DNS trục trặc **tắt vĩnh viễn webhook** của đối tác — phải tách "cấm hẳn" khỏi "lỗi tạm thời".
3. Loopback IPv6 `[::1]` **lọt qua luật chặn SSRF** vì `parse_url` giữ dấu ngoặc vuông.
4. Gán vai trò khi đăng ký làm **đăng ký hỏng hoàn toàn** nếu bản ghi vai trò chưa có.
5. `store` của ScreenController **vẫn cho scheduler đặt giá** — tôi từng khẳng định sai rằng nó không nhận `inventory`.

---

## 2. Chưa làm — cần anh gật đầu trước

| Việc | Vì sao chờ |
|---|---|
| **Gỡ số liệu bịa trên trang công khai** (F-15): "30M+", bộ đếm impression chạy bằng `Math.random()`, "AI Match 94%", "fill rate 40%", nút CTA không hành vi, badge "Còn trống" in vô điều kiện | Đụng giao diện khách hàng nhìn thấy; cần chốt thay bằng số thật hay gỡ hẳn |
| **Hợp nhất quan hệ Màn hình ↔ Mạng lưới** (F-12) | Migration trên dữ liệu production, cần dry-run và đối chiếu trước/sau. Đây cũng là nguyên nhân 7/10 ca hỏng baseline |
| **Xoay khóa deploy** đang nằm trong git (F-07) | Việc quản trị, cần thao tác trên VPS |

---

## 3. Chưa làm — thuộc các đợt sau

### T1b — phần R02 chưa đóng
- Quyền cho controller web của người mua (submit, payment, settings).
- Quyền cho action Filament (duyệt/từ chối booking, CRUD product).
- `canAccessPanel` phải kiểm **current owner** còn hoạt động, không chỉ "có owner nào đó còn hoạt động".
- DTO lọc trường cho Site/Screen; `price_per_slot_vnd` đang lấy từ giá sàn (F09).
- Quyền giá trong form Filament — hiện mới ép ở tầng API, form vẫn dựa vào `canPricing()` để ẩn trường.

### Nợ giao dịch (P0 theo PLAN.md của Codex)
- **Giữ chỗ nguyên tử + TTL** (F-02/F05): hiện mới sửa phép tính SOV theo ngày cao điểm, **chưa có khóa**. Hai người gửi cùng lúc vẫn có thể cùng lọt.
- **Mở gói thành N dòng booking** và snapshot composition (R05).
- **Chặn phát sóng khi creative chưa duyệt** (F-06) và ghi `booking_line_creatives`.
- **Thanh toán theo công nợ từng owner** + idempotency + số hóa đơn chống trùng (F07/F12).
- **Sửa đường ghi bằng chứng phát sóng** (F-04/F08): schema ULID, xác thực thiết bị, chống trùng, rollup.
- **Hủy, hoàn tiền, khiếu nại** — enum có, code không.
- **Rate card có phiên bản, bậc giá theo thời lượng** — hiện 12 kỳ vẫn bằng 12 lần một kỳ.

### Chuyển sang Next.js
Chưa bắt đầu, và **không nên bắt đầu** trước khi nhóm trên xong — xem `PLAN-API-FIRST-NEXTJS.md`. Phạm vi đã thu hẹp còn trang công khai vì chỉ có một người làm.

---

## 4. Câu hỏi nghiệp vụ còn treo

| # | Câu hỏi | Chặn việc gì |
|---|---|---|
| 1 | Khoảng ngày tối đa cho một dòng đặt chỗ — giữ 365, nới 730, hay bỏ? | Đang tạm 365 |
| 2 | Có đối tác nào đang **ghi** dữ liệu qua API không? | Deploy T1a sẽ chặn họ |
| 3 | Chính sách hủy dịch vụ và hoàn tiền | Luồng hủy |
| 4 | Chiến dịch chuyển "đang chạy" khi một owner đủ tiền hay tất cả? | Thanh toán theo owner |
| 5 | Media owner có được tự xác nhận đã nhận tiền không? | Như trên |
| 6 | Có thiết bị player nào đang gửi dữ liệu thật không? | Sửa schema bằng chứng phát sóng |
| 7 | Gói hàng có được gồm màn hình của nhiều owner? Chia tiền thế nào? | Mở gói thành N dòng |

---

## 5. Mốc số liệu test

| Thời điểm | Tổng | Pass | Fail |
|---|---|---|---|
| Baseline `112e2aa` | 229 | 219 | 10 |
| Sau T1a | 257 | 247 | 10 |
| Sau T2 | 281 | 271 | 10 |
| Sau tháng lịch + VAT | 286 | 276 | 10 |
| Sau T3 + T4 + việc nhỏ | 307 | 297 | 10 |

10 ca hỏng baseline **chưa được sửa** và vẫn giữ nguyên qua mọi đợt: 7 ca do hai đường quan hệ mạng lưới (F-12), 2 ca `InvitationFlowTest` (dùng sai tham số `view()` và `in_array` nhận chuỗi), 1 ca `PanelAccessTest` (enum không có giá trị `inactive`).
