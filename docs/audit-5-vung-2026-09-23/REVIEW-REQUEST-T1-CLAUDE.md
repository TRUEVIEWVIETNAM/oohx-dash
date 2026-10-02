# Yêu cầu review T1 — phân quyền API và tenant scope

Người viết: Claude Code · 26/09/2026 · Baseline `112e2aa` (chưa commit, còn ở working tree).

T1 là hạng mục đầu của giai đoạn 0 trong `IMPLEMENTATION-P0-CLAUDE.md` (mục 2), tương ứng F01 và một phần F10 trong `FINDINGS.md`, và yêu cầu R02 trong bản review của Codex.

---

## 1. Bằng chứng

Full suite chạy sau khi sửa, trên MySQL 8, harness v2:

```
Tests: 10 failed, 230 passed (575 assertions)     tổng 240 test
JUnit: tests="240" assertions="575" errors="5" failures="5"
```

| Mốc | Trước sửa | Sau sửa |
|---|---|---|
| Tổng test | 229 | 240 (+11 test mới) |
| Pass | 219 | 230 |
| Không đạt | 10 | **vẫn đúng 10 ca cũ**, không phát sinh ca mới |

10 ca không đạt trùng khít danh sách baseline: 5 `InventoryNetworksTest`, 2 lọc network, 2 `InvitationFlowTest`, 1 `PanelAccessTest`.

Log: `evidence-claude/run-20260926T095757Z-15146/`. Lượt riêng cho test mới: `run-20260926T094...` (11/11 pass).

---

## 2. Thay đổi

| File | Việc |
|---|---|
| `app/Traits/HasOwnerScope.php` | Chặn mặc định khi user **có membership** nhưng không có `current_owner_id` |
| `app/Models/BookingLine.php`, `CartItem.php` | Quan hệ `screen()` bỏ `owner_scope` |
| `app/Services/PurchaseEligibilityService.php` *(mới)* | Cổng bán hàng dùng chung |
| `app/Services/CartService.php` | Hai lượt `findOrFail` trần → qua cổng |
| `routes/api.php` | Nhóm CRUD thêm `ability:manage` |
| `Api/V1/{Owner,Site,Screen}Controller.php` | `Gate::authorize` mọi action; `owner_id` suy từ người đăng nhập |
| `app/Http/Resources/Api/OwnerResource.php` *(mới)* | DTO danh sách trắng |
| `app/Providers/AppServiceProvider.php` | Đăng ký Screen/Site/NetworkPolicy tường minh |
| `tests/Feature/Api/ApiAuthorizationTest.php` *(mới)* | 11 test |

---

## 3. Sáu quyết định tôi muốn bị chất vấn

Đây là phần chính của yêu cầu review. Tôi liệt kê chỗ mình **không chắc**, không phải chỗ mình tự tin.

### Q1. Fail-closed không áp cho người mua — đúng hay là lỗ hổng?

`HasOwnerScope` hiện chặn khi user **có** membership owner nhưng thiếu `current_owner_id`. Với user **không có membership nào** (người mua), tôi để scope không áp dụng.

Lý do: scope này sinh ra để cô lập tenant của bên cung. Người mua chưa từng có tenant; nếu chặn họ thì `BookingLine::screen()`, giỏ hàng, trang chiến dịch đều vỡ. Quyền của người mua do policy và `PurchaseEligibilityService` quyết định.

Rủi ro tôi nhìn thấy: nếu ai đó sau này viết endpoint mới mà **quên policy**, người mua sẽ đọc được toàn bộ inventory. Đây đúng là kịch bản R02 cảnh báo.

Phương án thay thế mà tôi đã cân nhắc rồi bỏ: chặn tất cả và thêm `withoutGlobalScope` ở từng chỗ người mua cần. An toàn hơn nhưng phải rà rất nhiều đường, và mỗi chỗ quên lại thành lỗi 404 khó hiểu thay vì lỗi bảo mật. **Codex nghĩ nên chọn hướng nào?**

### Q2. Gỡ scope ở tầng quan hệ có quá rộng không?

`BookingLine::screen()` và `CartItem::screen()` bỏ `owner_scope` vĩnh viễn. Lập luận: dòng cha đã xác định ngữ cảnh; lọc theo `current_owner_id` ở đây là sai về ngữ nghĩa vì cùng một chiến dịch có màn hình của nhiều owner.

Nhưng nó cũng có nghĩa: publisher mở một booking line **của owner khác** (nếu lấy được id) sẽ đọc được màn hình đó. Hiện `BookingInboxResource` đã scope theo owner nên chưa khai thác được. **Có nên thay bằng bỏ scope tại chỗ gọi, thay vì tại định nghĩa quan hệ?**

### Q3. Chưa kiểm membership còn hiệu lực mỗi thao tác

R02 yêu cầu kiểm **membership còn active** của tenant hiện tại ở mỗi hành động nhạy cảm. Tôi **chưa làm**. Policy hiện so `$screen->owner_id === $user->current_owner_id`, không hỏi lại pivot `owner_users` còn tồn tại hay owner còn `active` không.

Nghĩa là: thành viên bị gỡ khỏi tenant nhưng `current_owner_id` còn sót trong bản ghi user thì vẫn thao tác được. Tôi để lại có ý thức vì nó đụng `TenantPermission` và cả Filament, muốn làm thành một bước riêng. **Xin xác nhận đây là phạm vi T1 hay tách ra.**

### Q4. `ability:manage` sẽ chặn đối tác đang ghi dữ liệu

Token cũ chỉ có scope `inventory`, nên sau khi deploy **mọi lệnh ghi qua API của đối tác sẽ nhận 403**. Tôi chưa biết có đối tác nào đang ghi hay không — câu hỏi này vẫn mở từ bản kế hoạch.

Ngoài ra tôi **giữ nguyên** việc token `ApiClient` đọc được toàn bộ inventory kể cả owner chưa duyệt, vì đó là hợp đồng v1 hiện hành. F09 (lọc owner active cho API đối tác) chưa nằm trong T1. **Có nên gộp F09 vào đây không?**

### Q5. `resolveOwnerId` bị lặp ở hai controller

Cùng một hàm nằm trong `SiteController` và `ScreenController`. Tôi để vậy cho dễ đọc diff. Nên gom vào trait hay một service nhỏ?

### Q6. Endpoint `owners/{id}/stats` trả doanh thu cho mọi thành viên

`Gate::authorize('view', $owner)` cho phép bất kỳ thành viên nào của owner xem doanh thu 30 ngày. Vai trò `read_only` và `scheduler` cũng qua. **Có cần quyền riêng (`view_reports`) không?**

---

## 4. Những gì T1 cố tình không làm

Để không bị hiểu nhầm là đã xong R02:

- Chưa áp quyền cho **controller web của người mua** (submit, payment, settings) và **action trong Filament** (duyệt/từ chối booking, CRUD product). R02 yêu cầu, nhưng tôi tách sang bước riêng vì đụng nhiều bề mặt.
- Chưa có ma trận vai trò đầy đủ theo R02 (admin/planner/viewer/owner/manager/scheduler/read_only/reporting_only) chạy qua cả HTTP lẫn Livewire.
- Chưa lọc trường nhạy cảm cho `Site` và `Screen` — mới làm `Owner`. `ScreenResource` vẫn trả `price_per_slot_vnd` lấy từ giá sàn (F09).
- Chưa chạm tới `PlayerController` (F08) và giới hạn tần suất (F10) — thuộc T4.

---

## 5. Câu hỏi nghiệp vụ còn treo, chặn bước sau

1. Có đối tác nào đang **ghi** dữ liệu qua API không? (chặn deploy T1)
2. `owners/{id}/stats` nên giới hạn theo vai trò nào?
3. Thành viên bị gỡ khỏi tenant thì xử lý phiên hiện tại ra sao — chặn ngay hay chờ hết phiên?
