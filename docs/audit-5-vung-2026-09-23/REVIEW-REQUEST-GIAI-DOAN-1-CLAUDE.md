# Yêu cầu review giai đoạn 1 — đóng đường tiền

Người viết: Claude Code · 29/09/2026 · Trên nền giai đoạn 0 (`e019a8d`)

Giai đoạn 1 gồm sáu việc trong `LO-TRINH-SAU-GIAI-DOAN-0.md` cộng phần 1b (vá nốt phân quyền). Tất cả đã code xong và xanh. Chủ dự án yêu cầu làm trọn một lượt rồi Codex review một lần, nên tài liệu này gộp cả bảy phần.

Cách tái lập ở `REPRODUCE-CLAUDE.md`. Trạng thái tổng thể ở `STATUS.md`.

---

## 1. Bốn quyết định nghiệp vụ đã chốt 29/09

| # | Câu hỏi | Chốt |
|---|---|---|
| 1 | Giữ chỗ trong giỏ hết hạn sau bao lâu | **30 phút**, để trong `config/pricing.php` |
| 2 | Hủy đơn đã xác nhận thì hoàn bao nhiêu | **Bậc thang**: ≥14 ngày 100%, 7–13 ngày 50%, <7 ngày 0% |
| 3 | Chiến dịch nhiều owner chuyển "đang chạy" khi nào | **Theo từng dòng** — owner nào đủ tiền thì dòng đó chạy |
| 4 | Gói có được gồm màn hình nhiều owner | **Được**, chia tiền theo giá niêm yết từng dòng |

Hai giả định tôi tự chốt, xin xác nhận lại: media owner chỉ **ghi nhận** "đã nhận tiền", người quản trị xác nhận mới đóng công nợ; và giữ nguyên **365 ngày** cho khoảng ngày tối đa một dòng.

---

## 2. Kết quả test

| Bộ test | Số ca |
|---|---|
| `BundleExpansionTest` + `SplitVndTest` (1.2) | 17 |
| `InventoryHoldTest` + `HoldLockingTest` (1.1) | 17 |
| `CreativeGateAndPaymentTest` (1.3 + 1.4) | 13 |
| `CancellationTest` (1.5) | 12 |
| `RateCardVersionTest` (1.6) | 11 |
| `TenantPermissionGapTest` (1b) | 12 |
| Chống hồi quy sau bốn vòng CI | 2 |
| **Tổng mới** | **84** |

Full suite trên CI (MySQL 8, GitHub Actions): **383 ca chạy, 0 đổ** — run `36535168463`. Bảy ca F-12 vẫn bị loại bằng `--exclude-group`, xem `STATUS.md` mục 5.

**Bốn vòng CI mới xanh, và ba vòng đầu bắt được lỗi thật của tôi** — đáng đọc trước khi review, vì nó cho thấy chỗ nào trong đợt này dễ vỡ:

| Vòng | Đổ | Nguyên nhân |
|---|---|---|
| 1 | 68 | Cổng quyền giá đặt ở `saving` nên chặn cả lúc TẠO kho (63 ca); `auth()->user()` là `ApiClient` không có `hasRole()` (5 ca) |
| 2 | 11 | `CampaignPolicy` làm hộp thư đặt chỗ của publisher trả 403 — Filament tự gọi policy, mà policy chỉ mô tả phía người mua; `auth()->id()` vỡ với `ApiClient`; 5 fixture đổi giá khi đang đóng vai người mua; `PaymentPerOwnerTest` đóng đinh VAT 10% và "bấm nút = đã trả" |
| 3 | 1 | Ca test tôi thêm giả lập sai: `auth()->setUser()` ném TypeError |
| 4 | 0 | — |

Điểm chung: **cả bốn lỗi đều ở chỗ thêm một cơ chế chung** (observer, policy) — loại thay đổi mà Laravel và Filament tự gọi ở những nơi không viết ra. Chạy từng nhóm test không thấy được.

---

## 3. 1.1 — Giữ chỗ nguyên tử

`AvailabilityService` chỉ **đọc rồi so sánh**. Hai người mua chạy qua nó cùng lúc thì cả hai đều thấy còn trống và cả hai đều ghi được — lỗi "kiểm rồi mới làm", và nó **không lộ ra trong test một luồng**.

Cách chặn: khóa hàng `screens` làm mốc (`SELECT ... FOR UPDATE`) trước khi tính sức chứa, nên hai giao dịch buộc phải xếp hàng.

```
SOV đã dùng = tổng SOV dòng đặt chỗ còn hiệu lực + tổng SOV giữ chỗ còn hiệu lực
còn lại     = share_of_voice_max_pct − SOV đã dùng
```

Hai bằng chứng khác nhau, vì một cái không đủ:

1. `test_gianh_giu_cho_khoa_hang_man_hinh_truoc_khi_doc_suc_chua` — nghe toàn bộ SQL của đường đặt chỗ thật, xác nhận câu khóa chạy **trước** câu đếm. Khóa sau khi đọc thì chẳng chặn gì.
2. `test_khoa_hang_man_hinh_chan_that_su_mot_ket_noi_khac` — **hai kết nối thật**, kết nối thứ hai đặt `innodb_lock_wait_timeout=1` và phải nhận lỗi hết thời gian chờ thay vì đọc được kho.

**Đã kiểm ngược:** gỡ `lockForUpdate()` ra thì bằng chứng 1 đổ, còn toàn bộ `InventoryHoldTest` vẫn xanh — đúng như dự đoán, và đó là lý do phải có bằng chứng riêng cho phần khóa.

Hết hạn được tính **ngay trong truy vấn** theo `expires_at`, không dựa vào job dọn đã chạy hay chưa: nếu chỉ lọc theo `status` thì kho bị giam thêm đúng bằng khoảng trễ của job, và vào lúc job hỏng thì giam vĩnh viễn. Lệnh `inventory:purge-holds` chạy 5 phút một lần chỉ để dọn nhà.

---

## 4. 1.2 — Mở gói thành nhiều dòng

`CartService::addProduct` lưu `screen_id` = **màn hình đầu tiên** của gói, và `createFromCart` tạo đúng một dòng từ đó. Gói 10 màn hình của 3 owner thu về một dòng trên một màn hình:

- SOV chỉ bị trừ trên màn hình đầu → 9 màn còn lại vẫn báo trống và bán tiếp.
- Toàn bộ tiền ghi cho owner của màn hình đầu → hai owner kia **không có dòng công nợ nào**.
- Owner sửa thành phần gói sau khi bán thì đơn cũ trôi theo.

Nay mỗi màn hình một dòng với owner và giá riêng. Chia tiền theo giá niêm yết quy về **cùng mẫu số 210** (bội số chung của 7 và 30) — không chia cho 30, vì test bắt được `1.000.000/30` làm tròn thành `33.333` khiến tỉ lệ 1:3 hóa ra `1.999.984` với `6.000.016`. Tổng các phần **luôn** bằng đúng giá gói: phần dư cộng vào phần trọng số lớn nhất, không dùng số thực ở đâu cả.

Trọng số quá lớn (kho tính CPM với hàng chục triệu lượt hiển thị) được rút gọn theo ước chung lớn nhất, rồi hạ dần nếu cần, để `total × weight` không tràn số nguyên 64 bit.

`booking_line_bundles` giữ bản chụp gói lúc mua, đọc lại được cả khi sản phẩm đã bị sửa hoặc xóa.

---

## 5. 1.3 — Cổng nội dung quảng cáo

**Nói rõ phạm vi trước:** hiện **chưa có đường phát sóng** — không endpoint nào trả lịch phát cho thiết bị, `playlist_version` trong heartbeat chỉ là dấu thời gian của bảng kho. Nên tôi **không** gọi việc này là "chặn phát sóng". Cổng đặt ở chỗ duy nhất có thật: **chuyển chiến dịch sang đang chạy**.

Trước đây `creatives.status` có đủ ba trạng thái nhưng không nối vào đâu: duyệt hay không cũng không đổi gì, và `booking_line_creatives` chưa từng có dòng nào dù bảng và model pivot đã tồn tại.

Nay: duyệt nội dung thì gắn vào các dòng chưa có nội dung; từ chối thì **gỡ khỏi mọi dòng**; trả đủ tiền mà chưa có nội dung đã duyệt thì chiến dịch **không** chuyển sang đang chạy và ghi nhật ký `activation_blocked`. Ba action Filament (duyệt, từ chối, duyệt hàng loạt) nay đi qua cùng một service.

Khi nào có đường phát sóng, nó phải gọi lại `assertReadyToAir()` chứ không viết luật thứ hai.

---

## 6. 1.4 — Tiền theo từng media owner

Năm chỗ sai, bốn trong số đó ảnh hưởng trực tiếp tới số tiền:

| Chỗ | Trước | Sau |
|---|---|---|
| Kích hoạt | Chỉ khi **tổng** tiền cả chiến dịch đủ → một owner chậm xác nhận là cả chiến dịch đứng | Theo từng owner, kèm điều kiện nội dung đã duyệt |
| `breakdownByOwner` | Cộng cả khoản **đang chờ** vào phần "đã trả" → owner hiện ra đã nhận đủ tiền ngay khi người mua bấm nút | `paid` và `pending` là hai con số riêng; `is_paid` chỉ tính khoản đã xác nhận |
| `amount` | Nhận thẳng từ request, chỉ chặn `min:1000` | Máy chủ tính phần còn nợ; số khách khai không được vượt quá |
| Bấm hai lần | Hai khoản công nợ | `idempotency_key` (dựng từ token của lần gửi biểu mẫu) + khoản chờ của cùng owner được dùng lại |
| Số hóa đơn | "Đọc số lớn nhất rồi cộng một", không ràng buộc → hai người bấm cùng lúc ra cùng một số | Ràng buộc duy nhất ở CSDL + sinh có thử lại; đếm theo độ dài tiền tố nên không hỏng từ hóa đơn thứ 10.000 |

**Một điểm cần Codex soi:** migration thêm ràng buộc duy nhất cho `invoice_number` **bỏ qua** nếu dữ liệu hiện có đã trùng — nó tạo index thường và ghi `Log::warning` thay vì làm deploy đổ. Lý do: không được tự sửa số hóa đơn đã phát hành. Nhưng nghĩa là trên môi trường đã có số trùng, lỗ hổng còn nguyên tới khi ai đó dọn tay. Tôi chọn nói thật thay vì im lặng — xin ý kiến có nên làm khác.

---

## 7. 1.5 — Hủy và hoàn tiền

`cancelled` từng chỉ là một giá trị trong enum, không có đường nào đi tới.

Nay có `CancellationService` với bậc thang trong config. Ba điều cố ý:

- Tính theo ngày chạy của **từng dòng**, không theo ngày bắt đầu chiến dịch. Chiến dịch chạy tháng 1–6, hủy dòng của tháng 6 vào tháng 2 là hủy **sớm**.
- Hủy **nhả suất về kho ngay** — nối thẳng vào 1.1. Không nhả thì màn hình bị chiếm bởi một đơn không còn tồn tại.
- Chỉ hoàn phần **đã xác nhận chuyển khoản**. Khoản đang chờ thì chưa có đồng nào đi, không có gì để hoàn.

`refunds` ghi **nghĩa vụ**, không phải giao dịch: sàn không giữ tiền, nên việc chuyển lại diễn ra ngoài hệ thống và `settled_at` đánh dấu hai bên đã xong. Bản ghi chụp cả bảng bậc thang lúc hủy, để đổi chính sách sau này không làm hồ sơ cũ khó hiểu.

---

## 8. 1.6 — Bảng giá có phiên bản, chiết khấu theo thời lượng

Hai thiếu sót cùng một gốc: bảng giá được coi như một con số hiện tại chứ không phải văn bản có hiệu lực theo thời gian.

- `screen_rate_versions` chỉ ghi thêm, trả lời được "ngày ấy màn hình này niêm yết bao nhiêu". Ghi ở **observer** chứ không ở service, vì giá bị sửa từ nhiều đường (form hai panel, API v1, lệnh artisan, job nhập kho, scheduler) — nhét vào từng đường là cách chắc chắn để sót một đường, và đường bị sót chính là đường sửa giá không để lại vết. Chỉ ghi khi cột giá thật sự đổi, không ghi cho mọi thay đổi.
- `duration_discounts` trên từng kho: bậc cao nhất đạt tới thì áp, không cộng dồn. Bậc khai sai (150%, `min_units` = 0, không phải mảng) bị bỏ qua chứ không ra hóa đơn âm.

**Một chỗ dễ vỡ đã xử lý:** bậc chiết khấu nằm trong ảnh chụp giá của giỏ, và ảnh chụp là mảng **lồng**. `ksort` tầng ngoài — cách đã dùng từ T2 — không đủ: MySQL chuẩn hoá thứ tự khóa cả bên trong, nên mọi giỏ có khai chiết khấu sẽ bị báo "giá đã đổi". Nay sắp khóa ở mọi tầng, danh sách giữ nguyên thứ tự vì với bậc giá thì thứ tự là dữ liệu.

---

## 9. 1b — Bốn lỗ phân quyền

| Lỗ | Nội dung |
|---|---|
| Scope owner | `approveLines` lọc theo id dòng trong cùng chiến dịch mà **không scope owner** — thành viên của owner A duyệt được dòng của owner B, mà id của họ nằm ngay trên cùng một trang |
| Quyền duyệt đặt chỗ | Không kiểm quyền nào cả. Thêm `manage_bookings` vào ma trận sẵn có (owner/manager/sales_manager), ép trong service, `->visible()` trong Filament chỉ là phép lịch sự |
| Quyền sửa giá | Chỉ thể hiện bằng `->visible($canPricing)` trong form. Nay ép ở tầng lưu qua observer, nên mọi đường ghi đều đi qua |
| `canAccessPanel` | Kiểm "có tenant nào đó còn hoạt động" thay vì **tenant đang chọn** — người thuộc ba owner vẫn vào làm việc trên owner đã bị tạm ngưng |

Thêm `CampaignPolicy`: trước đây mỗi controller tự so `organization_id === current_organization_id`, nên vai trò `viewer` — người được mời **chỉ để xem** — vẫn gửi được booking và xác nhận được thanh toán. Policy đọc đúng `OrganizationUser::PERMISSIONS`, cùng bảng giao diện đang dùng, không đặt luật mới.

---

## 10. Xin Codex soi kỹ

1. **Khóa giữ chỗ**: mốc khóa là hàng `screens`. Còn đường nào ghi `booking_lines` hoặc `inventory_holds` mà **không** đi qua `InventoryHoldService`? Về deadlock: tôi đã sắp thứ tự khóa theo id màn hình ở cả hai chỗ khóa nhiều hàng (giành suất cho gói trong giỏ, và chốt đơn nhiều dòng), vì hai đơn có màn hình chung nhưng thứ tự khác nhau sẽ khóa chéo. Xin kiểm xem còn đường nào khóa ngoài thứ tự đó.
2. **Chia tiền gói**: `splitVnd` có trường hợp nào tổng lệch? Bước hạ trọng số khi quá lớn có làm tỉ lệ sai đáng kể với dữ liệu thật?
3. **Kích hoạt theo owner**: chiến dịch có dòng của ba owner, một owner trả đủ rồi hủy — trạng thái chiến dịch có còn đúng?
4. **Observer chặn quyền giá**: có đường vận hành hợp lệ nào bị chặn oan? (Tôi đã cố ý bỏ qua khi không có người đăng nhập.)
5. **Hoàn tiền**: cách chia tiền đã trả theo tỉ lệ giá dòng có sai khi owner có dòng đã hủy trước đó?
6. **Migration `invoice_number`**: cách xử lý dữ liệu trùng như mục 6 có chấp nhận được không.

---

## 11. Những gì giai đoạn 1 **không** đóng

- **Đường ghi bằng chứng phát sóng** (`impression_logs` vẫn không ghi được: khóa chính `char(26)` không có mặc định, model thiếu `HasUlids`) — giai đoạn 2.
- **Đường phát sóng** cho thiết bị: chưa tồn tại. Cổng nội dung vì thế đặt ở bước kích hoạt, không phải ở bước phát.
- **Hợp nhất quan hệ Màn hình ↔ Mạng lưới** (F-12) — 7 ca test vẫn đang bị loại khỏi CI.
- **Số liệu bịa trên trang công khai** (F-15) — chờ anh quyết.
- **F09** `price_per_slot_vnd` trả `floor_cpm` dưới cái tên sai: cố ý **không** sửa, vì `/api/v1` là hợp đồng với đối tác. Đã ghi chú tại chỗ trong code và chờ trả lời "đối tác nào đang dùng API".

---

# Phụ lục — Giai đoạn 2: đường ghi bằng chứng phát sóng

Làm tiếp ngay sau giai đoạn 1, cùng gửi một lượt. CI: **406 ca chạy, 0 đổ** (run `36544802797`).

## A. Bốn lỗi trên cùng một đường

| # | Lỗi | Hệ quả thật |
|---|---|---|
| 1 | Khóa chính `(id, played_at)` với `id` là `char(26)` không có mặc định, model thiếu `HasUlids` | **Chưa từng ghi được một dòng nào** — SQLSTATE 1364 ở mọi lần chèn. Bằng chứng phát sóng của sàn không tồn tại |
| 2 | Endpoint chỉ hỏi `screen_uuid` trong thân yêu cầu | UUID nằm trong cấu hình thiết bị và trong log. Biết nó là bơm được lượt hiển thị, tức chế ra doanh thu. `device_token` có sẵn từ đầu, chưa từng dùng |
| 3 | Không có cách chống trùng | Thiết bị mất mạng gửi lại là cộng thêm lượt, tức thêm tiền |
| 4 | `campaign_id`/`creative_id` khai `unsignedBigInteger`, luật kiểm ghi `integer`, trong khi cả hai đều là ULID | Báo cáo lọc `campaign_id = <ulid>` trên cột số nguyên — **vĩnh viễn không khớp** |

## B. Quyết định đáng soi

**Dựng lại bảng thay vì migration bảo toàn dữ liệu.** Dựa trên xác nhận của chủ dự án ngày 29/09: chưa có thiết bị nào gửi dữ liệu thật. Nhưng migration **đếm lại trước khi xóa** và ném lỗi nếu có bản ghi — giả định phải được kiểm lúc chạy, không phải tin.

**`played_at` thành bắt buộc.** Chống trùng dựa vào `(screen_id, event_id, played_at)`; khóa unique bắt buộc chứa cột phân vùng. Nếu để thiết bị bỏ trống rồi máy chủ điền `now()` thì lần gửi lại mang mốc khác và khóa không chặn được. Thêm cả `screen_id` vì `event_id` do thiết bị tự sinh, chỉ duy nhất trong phạm vi một thiết bị.

**Chưa cấp token thì không cho gửi.** Cố ý không mở ngoại lệ "chưa cấu hình thì bỏ kiểm" — mở là giữ nguyên đúng lỗ hổng vừa bịt. Đổi lại: **phải cấp token cho mọi màn hình trước khi lắp thiết bị thật**, bằng `php artisan screens:issue-device-token`.

**Chặn biên đồng hồ thiết bị:** báo muộn tối đa 7 ngày (gửi bù sau mất mạng là bình thường), lệch về tương lai thì kéo về hiện tại. Quá hạn thì **kẹp về biên chứ không vứt dữ liệu**. `played_at` quyết định lượt phát rơi vào phân vùng nào, kỳ báo cáo nào, hóa đơn nào.

**Không có khóa ngoại** trên `impression_logs`: MySQL không cho khóa ngoại trên bảng đã phân vùng. Việc kiểm tham chiếu nằm ở tầng ứng dụng — đây là chỗ dễ trôi, xin Codex soi.

## C. Xin Codex soi kỹ

1. Chống trùng có lỗ nào khi hai yêu cầu chạy song song với cùng `event_id` nhưng `played_at` lệch vài giây? (Tôi hỏi trước theo `event_id`, khóa unique là chốt cuối — nhưng hai thứ đó dùng điều kiện khác nhau.)
2. Kẹp biên `played_at` có làm sai số liệu đối soát không: một lượt phát thật ngày thứ 60 bị kẹp về ngày thứ 7 sẽ nằm sai kỳ. Vứt đi hay kẹp — cách nào đúng hơn?
3. `resolveBookingLine` chọn **dòng đầu tiên** khớp khi thiết bị chỉ gửi `campaign_id`. Một màn hình có hai dòng của cùng chiến dịch trong hai khoảng ngày chồng nhau thì chọn sai. Có nên từ chối thay vì đoán?
4. Bảng tổng hợp dùng chuỗi rỗng thay `NULL` cho `campaign_id`/`booking_line_id` để khóa unique chặn được trùng. Có cách nào sạch hơn?
5. Phân vùng hiện tới 2027-Q2 rồi `p_future`. Chưa có việc tự thêm phân vùng theo lịch — cần không?

## D. Giai đoạn 2 **không** đóng

- **Đường phát sóng cho thiết bị**: vẫn chưa tồn tại. Giai đoạn 2 làm xong đường thiết bị **báo về**, chưa làm đường máy chủ **gửi lịch phát xuống**. Cổng nội dung của 1.3 vì thế vẫn đặt ở bước kích hoạt.
- **Đối soát doanh thu từ bằng chứng**: `cpm_charged`, `revenue_gross`, `revenue_owner` vẫn để trống — chưa có bước tính tiền từ lượt phát thật.
- **Khiếu nại** về số liệu phát sóng.

---

# Phụ lục 2 — Giai đoạn 3: hợp nhất quan hệ Màn hình ↔ Mạng lưới (F-12)

CI: **426 ca chạy, 0 đổ, KHÔNG loại nhóm nào** (run `36548265693`). Đây là lần đầu tiên bộ test chạy đủ kể từ khi bắt đầu.

## A. Không phải hai đường mà ba

| Đường | Ai dùng | Ghi chú |
|---|---|---|
| `sites.network_id` | Trang công khai, API `inventory/networks`, bộ lọc khám phá | Trình nhập kho cũng ghi cột này |
| `screen_inventory.network_id` | **Sáu** chỗ trong Filament (admin + publisher) | Chỗ thứ sáu — `NetworkStatsWidget` của publisher — chỉ lộ ra khi rà lại toàn bộ |
| `screens.network_code` | Quan hệ `Screen::network()` | **Không code nào GHI cột này.** Chỉ test dùng — và đó là lý do 7 ca hỏng suốt từ baseline |

Hệ quả: cùng một mạng lưới, trang quản trị và trang công khai báo hai con số màn hình khác nhau, không ai biết con số nào đúng.

## B. Quyết định

**`sites.network_id` là nguồn sự thật duy nhất.** Mạng lưới là một chuỗi địa điểm; màn hình nằm tại một địa điểm nên thừa hưởng mạng lưới của nơi nó đứng. Một màn hình trong cửa hàng Winmart thuộc chuỗi khác là chuyện vô nghĩa về nghiệp vụ.

`Screen::network()` nay là `hasOneThrough` đảo chiều (`screens.site_id → sites.id`, `sites.network_id → networks.id`). Đây là chỗ tôi muốn Codex soi kỹ nhất về mặt kỹ thuật — xem mục D.

**Phần đối chiếu dữ liệu tách khỏi migration** thành `NetworkRelationReconciler` + lệnh `networks:reconcile --dry-run`. Lý do: một phép sửa trên dữ liệu production mà không ai chạy thử được là một phép sửa không ai kiểm được. Ba nguyên tắc cài trong đó, mỗi nguyên tắc có test riêng:

- **Chỉ điền vào ô trống**, không bao giờ ghi đè.
- **Không đoán khi mâu thuẫn**: site có hai màn hình chỉ về hai mạng lưới khác nhau thì để nguyên và báo cáo.
- **Chạy lại không đổi kết quả.**

**Cột `screens.network_code` và `screen_inventory.network_id` vẫn còn trong CSDL**, chỉ là không còn ai đọc. Cố ý chưa xóa: xóa cột trên production là việc một chiều, nên để sau khi đã chạy thật và đối chiếu xong. Nhưng đây là nợ có thật — một cột còn được ghi mà không ai đọc chính là cách F-12 bắt đầu.

## C. Test kiểm cái gì

`NetworkRelationUnifiedTest` **không** kiểm "đếm có đúng 3 không" mà kiểm **hai đường có cho cùng một đáp số không**. Đó mới là thứ đã hỏng, và là thứ sẽ hỏng lại nếu ai đó thêm đường thứ tư. Có một ca đặt `network_code` mâu thuẫn để chứng minh cột cũ không còn quyết định gì.

## D. Xin Codex soi kỹ

1. **`hasOneThrough` đảo chiều** trên `Screen::network()`: `hasOneThrough(Network, Site, 'id', 'id', 'site_id', 'network_id')`. Nó chạy đúng trong test (bao gồm `whereHas`), nhưng đây là cách dùng ngược với ý định của Laravel. Có trường hợp nào — eager load lồng nhau, `withCount`, `has()` — mà nó cho kết quả sai không? Nếu có, thay bằng accessor `site.network` có phải là lựa chọn an toàn hơn?
2. **Lượt hai của reconciler** (`screens.network_code`) chạy sau lượt một trong chế độ ghi, nhưng khi `--dry-run` thì hai lượt được tính độc lập rồi loại trùng. Hai chế độ có thể cho con số khác nhau không?
3. **Chưa xóa hai cột cũ.** Nên xóa ngay hay để một đợt riêng sau khi chạy production?
4. Trình nhập kho vẫn ghi `screen_inventory.network_id`. Nên bỏ hẳn hay giữ như bản sao?
