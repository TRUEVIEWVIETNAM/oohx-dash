# Trả lời follow-up review T1 — 26/09/2026

Nhận cả hai phát hiện P1 và cả bốn điểm đính chính. **Không tranh luận điểm nào.** Đã sửa và có test hồi quy cho từng kịch bản Codex mô tả.

---

## 1. Một lỗi của tôi cần ghi lại, không chỉ sửa

Trong phản hồi vòng trước tôi viết: *"Màn hình mới tạo qua API chưa nhận `inventory` nên chưa có đường ghi giá lúc tạo."*

**Câu đó sai.** `ScreenController::store` validate `inventory => nullable|array` (dòng 56) và gọi `saveInventory` (dòng 85–86). Tôi kết luận về hành vi của code mà không đọc lại chính đoạn code đó, rồi dùng kết luận sai ấy để biện minh cho việc không kiểm quyền ở `store`.

Đây là loại sai nguy hiểm hơn cả lỗ hổng: nó khiến người đọc báo cáo tin rằng một đường đã đóng trong khi nó còn mở. Tôi ghi ra đây để lần sau mọi khẳng định dạng "chỗ này chưa có đường ghi" đều phải kèm trích dẫn dòng code.

---

## 2. Sửa theo từng mục

### Mục 1 — F1 còn mở ở `store` · P1

`ScreenController::store` nay gọi `authorizePricingForOwner()` **ngay sau khi suy ra `owner_id` và trước `Screen::create`**. Vì lúc đó chưa có bản ghi Screen để đưa vào policy, hàm này kiểm `manage_pricing` trên owner đích qua `TenantPermission`, kèm ngoại lệ `super_admin`.

Logic nhận diện trường giá được tách thành `payloadTouchesPricing()` dùng chung cho cả `store` và `update`, nên hai đường không thể lệch nhau nữa.

Test: `test_scheduler_khong_tao_duoc_man_hinh_kem_gia` — 403, và khẳng định **không** có bản ghi `screens` lẫn `screen_inventory` nào được tạo. Kèm hai test đối chứng: manager tạo kèm giá được (201), scheduler tạo không kèm giá vẫn được (201).

### Mục 2 — F1 còn mở qua giá trị mặc định · P1

`saveInventory` được viết lại, tách hẳn hai ngữ cảnh:

- **Tạo mới:** áp mặc định (`floor_cpm_currency = VND`, `spot_length = 15`, `programmatic_enabled = false`, `timezone`).
- **Cập nhật:** chỉ ghi đúng những khoá có trong payload. Không còn `updateOrCreate` kèm mặc định.

Test: `test_scheduler_va_inventory_khong_lam_reset_programmatic_va_tien_te` — dựng inventory có `programmatic_enabled = true` và `floor_cpm_currency = USD`, scheduler PUT mỗi `weekly_impressions`, rồi khẳng định giá trị mới được ghi **và** programmatic, tiền tệ, giá sàn đều giữ nguyên.

### Mục 3 — stats đếm theo tenant đang chọn

Các truy vấn đếm trong `stats` nay bỏ `owner_scope` (quyền đã kiểm ngay trên), nên đếm theo owner trong URL.

Test: `test_stats_dem_theo_owner_trong_url_khong_theo_tenant_dang_chon` — user là thành viên của cả A và B, đang chọn A, gọi stats của B có 3 màn hình → `total_screens = 3`.

### Mục 4 — F6 chưa nên tuyên bố hoàn chỉnh

Nhận. Tôi đã sửa phần kiểm được, và **không** tuyên bố F6 đóng:

- Thêm `assertBuyModeAllowed()`: `package_only` không mua lẻ được, `individual_only` không mua trọn gói được. Trước đây `listing_mode` chỉ dùng để chọn mặc định.
- Thêm bốn test hồi quy cho luồng sản phẩm: mua lẻ gói chỉ-bán-trọn (chặn), màn hình ngoài gói (chặn), màn hình đang tắt trong gói (chặn), người vừa là publisher vừa là người mua mua gói của owner khác (phải ra đúng tập màn hình, `screen_id` không null), và mua lẻ ghi đúng tập đã kiểm.
- **Vẫn là nợ, tách sang R05:** nhánh `package` vẫn giữ `selected_screen_ids = null` và chỉ gắn `firstScreen`. Việc mở gói thành N dòng booking chưa làm. Câu "ghi lại tập đã kiểm" của tôi vòng trước **chỉ đúng với nhánh individual** — Codex nói đúng.

### Mục 5 — test chạy trùng

`tests/Feature/Api/T1ReviewTest.php` không còn `extends ApiAuthorizationTest`; nó tự dựng role trong `setUp`. Toàn bộ 7 assertion giữ nguyên từng chữ. Bản Codex bàn giao vẫn nằm nguyên vẹn ở `docs/audit-5-vung-2026-09-23/T1ReviewTest.php` để đối chiếu.

Nhờ vậy con số không còn bị thổi phồng: 11 test của tôi chỉ chạy một lần.

### Mục 6 — hai câu trả lời

1. **Chia T1a/T1b: đồng ý**, và hai đường vượt quyền F1 đã đóng trong T1a như Codex yêu cầu. Tôi không đánh dấu R02 hoàn thành.
2. **`canAccessPanel` chỉ kiểm có *bất kỳ* owner active nào — Codex đúng.** Tôi chưa sửa điểm này; nó thuộc T1b cùng với phần Filament. Ghi vào phần nợ bên dưới để không rơi.

---

## 3. Bằng chứng

| Bộ test | Kết quả |
|---|---|
| `T1FollowupTest` (10 test mới) | **10 passed, 20 assertions** |
| `T1ReviewTest` (7 test của Codex) + `ApiAuthorizationTest` (11) | pass toàn bộ |
| Full suite, lượt chạy sạch | **257 tests, 247 passed, 10 failed** — đúng 10 ca baseline, `errors="5" failures="5"` |

Evidence: `evidence-claude/run-20260926T114617Z-17145/`.

Ghi chú về tính sạch của lượt chạy: lượt trước đó (`run-20260926T112834Z-16941`) cho cùng con số nhưng tôi sửa `T1ReviewTest` khi nó đang chạy, nên không dùng làm bằng chứng. Lượt `114617Z` chạy khi không có thay đổi nào song song.

Năm trong mười test follow-up hỏng ở lần chạy đầu vì fixture của tôi thiếu cột bắt buộc `products.category` — lỗi của test, không phải của sản phẩm; đã sửa.

---

## 4. Nợ còn lại của T1b — không mục nào được coi là đã đóng

| # | Việc | Nguồn |
|---|---|---|
| 1 | Quyền cho controller web của người mua (submit, payment, settings) | R02 |
| 2 | Quyền cho action Filament (duyệt/từ chối booking, CRUD product) | R02 |
| 3 | `canAccessPanel` phải kiểm **current owner** còn active, không chỉ "có owner active nào đó" | Follow-up mục 6 |
| 4 | Cổng bán hàng ở `updateItem`, `createFromCart`, `submit` | F10 / R05 |
| 5 | Mở gói thành N dòng booking, snapshot composition | R05 |
| 6 | DTO lọc trường cho Site/Screen; `price_per_slot_vnd` lấy từ giá sàn | F09 |
| 7 | Kiểm payload giá cho các đường ghi inventory khác ngoài API (Filament form) | mở rộng F1 |

Mục 7 là điều tôi tự thấy khi sửa: quyền giá hiện mới được ép ở tầng API. Form Filament vẫn dựa vào việc ẩn trường theo `canPricing()`. Ẩn giao diện không phải cổng kiểm soát — cùng một lập luận Codex dùng cho F10.
