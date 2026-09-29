# Trạng thái công việc — cập nhật 29/09/2026

Một trang duy nhất trả lời: **đã làm gì, đang dở gì, chưa làm gì.** Chi tiết kỹ thuật ở `IMPLEMENTATION-P0-CLAUDE.md`; các vòng review ở `REVIEW-*.md`.

Baseline: `112e2aa`. **Giai đoạn 0 đã push** lên `feat/tmdt-review-1107` (11 commit, CI xanh). **Giai đoạn 1 đã code xong**, phần cuối còn ở working tree — xem mục 1b.

---

## 1. Đã xong

| # | Việc | Commit | Test |
|---|---|---|---|
| T1a | Phân quyền API: policy cho mọi action, tách `ability:manage`, `owner_id` suy từ người đăng nhập, DTO lọc trường nhạy cảm, ẩn `device_token` | `b9923a0` | 28 |
| T1a+ | Scope chặn mặc định khi mất tenant; quyền giá (`manage_pricing`) tách khỏi quyền kho; `TenantPermission` đòi owner còn hoạt động | `b9923a0` | ↑ |
| T2 | Máy chủ quyết định số kỳ và số CPM; `updateItem` tính lại từ ngày; đóng băng giá trong giỏ, chặn chốt đơn khi giá đổi | `eb1f32c` | 24 |
| T2+ | **Tháng lịch** thay quy ước 30 ngày; **VAT 8%** hợp nhất từ 13 chỗ về config | `38a97c9` | ↑ |
| T3 | Cổng bán hàng phủ cả sửa giỏ, chốt đơn, gửi booking | `7386fc0` | 4 |
| T4 | Giới hạn tần suất (api/login/token/player/geocode); chặn SSRF webhook hai lớp; webhook thử lại thật; `event_id` ổn định; cache proxy geocode | `66c6bc5` | 17 |
| CI | Workflow test trên MySQL có bước chặn an toàn; deploy **gọi lại** workflow test nên chỉ deploy khi xanh trên đúng SHA | `3a11989`, `9da1032` | — |
| Nhỏ | `fresh(["status"])` sai API; vai trò khi tự đăng ký; file import sang disk riêng | `6fc554b` | — |
| Baseline | Sửa 3 trong 10 ca hỏng baseline, gồm một lỗi sản phẩm: link mời hết hạn trả 500 thay vì 410 | `31218f8` | — |

## 1b. Giai đoạn 1 — đóng đường tiền (đã code xong 29/09)

| # | Việc | Test |
|---|---|---|
| 1.1 | Giữ chỗ nguyên tử: khóa hàng màn hình, hết hạn 30 phút, nhả khi bỏ giỏ hoặc hủy đơn | 17 |
| 1.2 | Mở gói thành N dòng đặt chỗ, chia tiền theo giá niêm yết, chụp thành phần gói | 17 |
| 1.3 | Cổng nội dung: duyệt mới được lên sóng, từ chối thì gỡ khỏi dòng đặt chỗ | 13 |
| 1.4 | Tiền theo từng owner: kích hoạt theo dòng, chống trùng thanh toán, số hóa đơn không trùng, số tiền do máy chủ tính | ↑ |
| 1.5 | Hủy và hoàn tiền bậc thang, nhả suất về kho, ghi nghĩa vụ hoàn tiền | 12 |
| 1.6 | Bảng giá có phiên bản; chiết khấu theo thời lượng | 11 |
| 1b | Bốn lỗ phân quyền: scope owner khi duyệt, quyền `manage_bookings`, quyền giá ép ở tầng lưu, `canAccessPanel` theo tenant đang chọn; thêm `CampaignPolicy` | 12 |

Chi tiết và những gì giai đoạn 1 **không** đóng: `REVIEW-REQUEST-GIAI-DOAN-1-CLAUDE.md`.

**Bốn quyết định nghiệp vụ chốt 29/09:** giữ chỗ 30 phút · hoàn tiền bậc thang 14/7 ngày · chiến dịch chạy theo từng dòng · gói được gồm nhiều owner, chia theo giá từng dòng.

---

## 1c. Lỗi do test bắt được, không nhìn ra khi đọc code

1. Đóng băng giá **chặn mọi đơn hàng** — MySQL sắp xếp lại khóa cột JSON, `===` báo khác nhau.
2. DNS trục trặc **tắt vĩnh viễn webhook** của đối tác — phải tách "cấm hẳn" khỏi "lỗi tạm thời".
3. Loopback IPv6 `[::1]` **lọt qua luật chặn SSRF** vì `parse_url` giữ dấu ngoặc vuông.
4. Gán vai trò khi đăng ký làm **đăng ký hỏng hoàn toàn** nếu bản ghi vai trò chưa có.
5. `store` của ScreenController **vẫn cho scheduler đặt giá** — tôi từng khẳng định sai rằng nó không nhận `inventory`.
6. Chia tiền gói lệch **16 đồng** vì quy giá về một ngày bằng phép chia làm tròn — tỉ lệ 1:3 hóa ra 1.999.984 với 6.000.016.
7. Cột `campaign_activities.action` bị tôi viết thành `type` trong test — lỗi test, nhưng nó chặn đúng lúc.

---

## 2. Chưa làm — cần anh gật đầu trước

| Việc | Vì sao chờ |
|---|---|
| **Gỡ số liệu bịa trên trang công khai** (F-15): "30M+", bộ đếm impression chạy bằng `Math.random()`, "AI Match 94%", "fill rate 40%", nút CTA không hành vi, badge "Còn trống" in vô điều kiện | Đụng giao diện khách hàng nhìn thấy; cần chốt thay bằng số thật hay gỡ hẳn |
| **Hợp nhất quan hệ Màn hình ↔ Mạng lưới** (F-12) | Migration trên dữ liệu production, cần dry-run và đối chiếu trước/sau. Đây cũng là nguyên nhân 7/10 ca hỏng baseline |
| **Xoay khóa deploy** đang nằm trong git (F-07) | Việc quản trị, cần thao tác trên VPS |

---

## 3. Chưa làm — thuộc các đợt sau

### Còn lại của T1b
- DTO lọc trường cho Site/Screen ở `/api/v2`. `price_per_slot_vnd` trả `floor_cpm` dưới cái tên sai (F09) — **cố ý chưa sửa** vì `/api/v1` là hợp đồng với đối tác; đã ghi chú tại chỗ trong code. Chờ trả lời câu hỏi 2.
- Quyền cho các action Filament còn lại (CRUD product, settings của owner).

### Nợ giao dịch còn lại
- **Sửa đường ghi bằng chứng phát sóng** (F-04/F08): `impression_logs` hiện **không ghi được** — khóa chính `char(26)` không có mặc định, model thiếu `HasUlids`, đã dựng probe tái hiện. Cần schema ULID, xác thực thiết bị, chống trùng (khóa unique **phải chứa cột phân vùng** `played_at`, bảng đang có 6 phân vùng), và bảng tổng hợp. Đây là giai đoạn 2. Chủ dự án xác nhận 29/09: **chưa có thiết bị player nào gửi dữ liệu thật**, nên được làm lại schema cho sạch thay vì migration bảo toàn dữ liệu.
- **Đường phát sóng cho thiết bị**: chưa tồn tại. Không endpoint nào trả lịch phát; `playlist_version` trong heartbeat chỉ là dấu thời gian của bảng kho. Vì vậy cổng nội dung của 1.3 đặt ở bước kích hoạt chứ không phải bước phát.
- **Khiếu nại**: luồng hủy và hoàn tiền đã có (1.5), phần khiếu nại chưa.

### Chuyển sang Next.js
Chưa bắt đầu — xem `PLAN-API-FIRST-NEXTJS.md` và `LO-TRINH-SAU-GIAI-DOAN-0.md`. Phạm vi đã thu hẹp còn trang công khai vì chỉ có một người làm.

---

## 4. Câu hỏi nghiệp vụ còn treo

| # | Câu hỏi | Chặn việc gì |
|---|---|---|
| 1 | Khoảng ngày tối đa cho một dòng đặt chỗ — giữ 365, nới 730, hay bỏ? | Đang tạm 365 |
| 2 | Có đối tác nào đang **ghi** dữ liệu qua API không? | Deploy giai đoạn 0 sẽ chặn họ; cũng chặn việc đặt tên lại F09 |
| 3 | Media owner có được **tự** xác nhận đã nhận tiền không? | Tôi đang giả định: owner chỉ ghi nhận, quản trị xác nhận mới đóng công nợ |
| 4 | Số liệu bịa trên trang công khai: thay bằng số thật hay gỡ hẳn? | Giai đoạn 4 |

**Đã chốt 29/09, không còn treo:** giữ chỗ 30 phút · hoàn tiền bậc thang · chiến dịch chạy theo từng dòng · gói nhiều owner chia theo giá từng dòng · **chưa có thiết bị player nào gửi dữ liệu thật**, nên giai đoạn 2 được làm lại schema `impression_logs` cho sạch thay vì migration bảo toàn dữ liệu.

---
## 5. Mốc số liệu test

| Thời điểm | Tổng | Pass | Fail |
|---|---|---|---|
| Baseline `112e2aa` | 229 | 219 | 10 |
| Sau T1a | 257 | 247 | 10 |
| Sau T2 | 281 | 271 | 10 |
| Sau tháng lịch + VAT | 286 | 276 | 10 |
| Sau T3 + T4 + việc nhỏ | 307 | 297 | 10 |
| Sau khi sửa 3 ca baseline | 307 | 300 | 7 |

Trong 10 ca hỏng baseline, **3 ca đã sửa ngày 27/09**: 2 ca `InvitationFlowTest` — trong đó có một lỗi sản phẩm thật, `InvitationController::show` dùng sai tham số thứ ba của `view()` nên link mời hết hạn trả 500 thay vì 410 — và 1 ca `PanelAccessTest` (test dùng `status = inactive`, enum chỉ có `pending/active/suspended`).

**7 ca còn lại là F-12** (hai đường quan hệ Màn hình ↔ Mạng lưới). Đã gắn `#[Group('f12-network-relation')]` và CI chạy `--exclude-group` để cổng chắn deploy dùng được. Đây là **nợ có hẹn**: gỡ nhãn ngay khi migration hợp nhất quan hệ xong. Chừng nào nhãn còn đó, CI **không** bảo vệ đường lọc và đếm theo mạng lưới.
