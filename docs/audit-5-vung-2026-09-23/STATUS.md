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

## 1c. Giai đoạn 2 — đường ghi bằng chứng phát sóng (xong 29/09)

Bốn lỗi trên cùng một đường, nên "bằng chứng phát sóng" của sàn cho tới nay **không tồn tại**:

| # | Lỗi | Sửa |
|---|---|---|
| 1 | **Không ghi được dòng nào** — khóa chính `char(26)` không có mặc định, model thiếu `HasUlids` → SQLSTATE 1364 ở mọi lần chèn | Dựng lại bảng; migration đếm lại trước khi xóa và dừng nếu có bản ghi |
| 2 | **Ai biết UUID cũng ghi được** — UUID nằm trong cấu hình thiết bị và log; `device_token` có sẵn từ đầu nhưng chưa từng dùng | Token băm, gửi qua `X-Device-Token`, cấp bằng `screens:issue-device-token`; chưa cấp token thì không cho qua |
| 3 | **Cộng trùng** khi thiết bị gửi lại sau mất mạng | Khóa `(screen_id, event_id, played_at)` + hỏi theo `event_id` trước khi ghi; `played_at` thành bắt buộc |
| 4 | **Không nối được với đơn hàng** — `campaign_id` khai `bigint` trong khi chiến dịch dùng ULID, nên báo cáo lọc theo nó vĩnh viễn không khớp | ULID + thêm `booking_line_id`, máy chủ tự xác minh dòng thuộc đúng màn hình và khoảng ngày |

Kèm theo: chặn biên đồng hồ thiết bị (muộn tối đa 7 ngày, tương lai kéo về hiện tại), bảng tổng hợp theo ngày và lệnh `impressions:rollup` chạy mỗi giờ để báo cáo không quét bảng thô. 23 ca test.

**Bốn quyết định nghiệp vụ chốt 29/09:** giữ chỗ 30 phút · hoàn tiền bậc thang 14/7 ngày · chiến dịch chạy theo từng dòng · gói được gồm nhiều owner, chia theo giá từng dòng.

---

## 1d. Giai đoạn 3 — hợp nhất quan hệ Màn hình ↔ Mạng lưới (xong 29/09)

Không phải hai mà **ba** đường cùng trả lời "màn hình thuộc mạng lưới nào": `sites.network_id` (trang công khai), `screen_inventory.network_id` (sáu chỗ trong Filament), `screens.network_code` (quan hệ trên model, không code nào ghi). Cùng một mạng lưới, trang quản trị và trang công khai báo hai con số khác nhau.

Chốt `sites.network_id` là nguồn sự thật duy nhất. Phần đối chiếu dữ liệu tách thành `NetworkRelationReconciler` + lệnh `networks:reconcile --dry-run` để chạy thử được trước khi chạy thật; chỉ điền ô trống, không ghi đè, mâu thuẫn thì báo cáo chứ không đoán.

**CI nay chạy toàn bộ** — đã gỡ `--exclude-group f12-network-relation` và bảy nhãn `#[Group]`. 13 ca test mới, cộng 7 ca cũ nay chạy lại.


## 1e. Lỗi do test bắt được, không nhìn ra khi đọc code

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
| **Chạy đối chiếu mạng lưới trên production** (F-12) | Code đã xong ở giai đoạn 3 và CI xanh. Việc còn lại là chạy `php artisan networks:reconcile --dry-run` trên bản sao dữ liệu thật, xem sẽ đụng bao nhiêu địa điểm và còn bao nhiêu chỗ mâu thuẫn, rồi mới deploy |
| **Xoay khóa deploy** đang nằm trong git (F-07) | Việc quản trị, cần thao tác trên VPS |

---

## 3. Chưa làm — thuộc các đợt sau

### Còn lại của T1b
- DTO lọc trường cho Site/Screen ở `/api/v2`. `price_per_slot_vnd` trả `floor_cpm` dưới cái tên sai (F09) — **cố ý chưa sửa** vì `/api/v1` là hợp đồng với đối tác; đã ghi chú tại chỗ trong code. Chờ trả lời câu hỏi 2.
- Quyền cho các action Filament còn lại (CRUD product, settings của owner).

### Nợ giao dịch còn lại
- **Đường phát sóng cho thiết bị**: vẫn chưa tồn tại — không endpoint nào trả lịch phát. Giai đoạn 2 mới làm xong đường thiết bị **báo về**, chưa làm đường máy chủ **gửi xuống**. Cổng nội dung của 1.3 vì thế vẫn đặt ở bước kích hoạt.
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
| **Sau giai đoạn 1** (CI, run 36535168463) | 390 | 383 | 0 ngoài 7 ca F-12 bị loại |
| **Sau giai đoạn 2** (CI, run 36544802797) | 413 | 406 | 0 ngoài 7 ca F-12 bị loại |
| **Sau giai đoạn 3** (CI, run 36548265693) | **426** | **426** | **0 — không loại nhóm nào** |

Trong 10 ca hỏng baseline, **3 ca đã sửa ngày 27/09**: 2 ca `InvitationFlowTest` — trong đó có một lỗi sản phẩm thật, `InvitationController::show` dùng sai tham số thứ ba của `view()` nên link mời hết hạn trả 500 thay vì 410 — và 1 ca `PanelAccessTest` (test dùng `status = inactive`, enum chỉ có `pending/active/suspended`).

**Bảy ca F-12 đã được thả ra ở giai đoạn 3** (29/09): quan hệ Màn hình ↔ Mạng lưới đã hợp nhất về `sites.network_id`, nhãn `#[Group]` và cờ `--exclude-group` đã gỡ. CI nay chạy **toàn bộ**, không loại nhóm nào.
