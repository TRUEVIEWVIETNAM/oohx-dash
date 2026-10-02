# Yêu cầu review T2 — giá do máy chủ quyết định

Người viết: Claude Code · 26–27/09/2026 · Commit `eb1f32c` + `38a97c9` (trên `b9923a0` của T1a, cả ba chưa push).

**Cập nhật 27/09:** chủ dự án đã chốt hai quyết định nghiệp vụ, đã cài trong `38a97c9`:
- **Tháng lịch** thay cho quy ước 30 ngày. `01/01–30/06` nay là **6 kỳ** thay vì 7. Chế độ cũ giữ sau `config('pricing.month_mode')` để đối chiếu dữ liệu lịch sử.
- **VAT 8%** thay cho 10%. Hoá ra con số này nằm ở **13 chỗ** chứ không phải 4: 5 trong `PaymentService`, 8 trong blade và JS. Nay tất cả đọc từ config.

Vì vậy mục 6 bên dưới chỉ còn một câu hỏi treo: khoảng ngày tối đa.
Kết quả full suite mới nhất: **286 test, 276 pass, 10 ca hỏng baseline** (`evidence-claude/run-20260926T172624Z-3262/`).

T2 đóng F-01 của audit / F04 của Codex — lỗ hổng duy nhất có probe tái hiện được: cùng khoảng 01/01–30/06, giá đúng là 7.000.000 nhưng gửi `duration_units=1` chỉ trả 1.000.000.

---

## 1. Bằng chứng

| Bộ test | Kết quả |
|---|---|
| `BillablePeriodCalculatorTest` (unit, 10 ca) + `CartPricingTest` (feature, 14 ca) | **24 passed, 39 assertions** |
| Full suite | **281 tests, 271 passed, 10 failed** — đúng 10 ca hỏng baseline |

Evidence: `evidence-claude/run-20260926T133216Z-18582/`. Mốc so sánh: 229/219/10 (baseline) → 257/247/10 (T1a) → 281/271/10 (T2).

---

## 2. Thay đổi

| File | Việc |
|---|---|
| `config/pricing.php` *(mới)* | `month_days`, `max_range_days`, `vat_rate` ra khỏi code |
| `app/Services/Pricing/BillablePeriodCalculator.php` *(mới)* | Nơi duy nhất quy đổi ngày → kỳ |
| `app/Services/CartService.php` | Máy chủ suy số kỳ/CPM; `updateItem` tính lại từ ngày; ghi ảnh chụp giá |
| `app/Services/CampaignService.php` | Chặn chốt đơn khi giá kho đã đổi (409) |
| `app/Http/Controllers/Buyer/CartController.php` | Ràng buộc ngày; bỏ `screen_count` khỏi input |
| `app/Models/CartItem.php` + migration | `rate_captured_at`, `rate_snapshot` |

Quyết định theo R03: client gửi số kỳ **thấp hơn** thực tế thì **422 kèm con số thật**, không tự nâng giá. Gửi cao hơn thì chấp nhận (mua thêm).

---

## 3. Năm điểm tôi muốn bị chất vấn

### Q1. `ksort` có đủ để so sánh ảnh chụp giá không?

MySQL chuẩn hoá lại thứ tự khoá của cột JSON. Bản đầu của tôi dùng `===` và **sẽ chặn mọi đơn hàng** trên production. Tôi sửa bằng `ksort` hai phía.

Nhưng tôi chưa chắc về mấy điểm: giá trị số thập phân so sánh dưới dạng chuỗi (`"1000000.00"`) có bền không khi cấu hình `decimal` đổi, hoặc khi giá là `null` so với `"0.00"`? Có nên so sánh theo từng trường với dung sai, hoặc lưu thêm một hash chuẩn hoá thay vì so cả mảng?

### Q2. Cho phép "mua thêm" có mở ra vấn đề gì không?

Client gửi `duration_units` lớn hơn mức suy ra thì được chấp nhận và tính đúng tiền đó. Tôi cho rằng đây là nhu cầu thật (giữ chỗ dài hơn khoảng phát). Nhưng nó cũng nghĩa là **số kỳ đã trả và khoảng ngày giữ chỗ không còn ràng buộc với nhau** — trả 12 kỳ nhưng chỉ giữ 30 ngày. Có nên chặn, hay ghi nhận như một loại "mua trước"?

Tương tự với `booked_cpms`: mua nhiều CPM hơn mức khoảng ngày có thể phát thì phần dư không bao giờ được giao.

### Q3. Ảnh chụp giá mới dừng ở giỏ hàng, chưa tới dòng booking

R03 yêu cầu snapshot phải **đi tới booking line**, gồm tiền tệ, thuế, phí, đơn giá, điều khoản, composition sản phẩm và allocation. Tôi mới làm phần phát hiện đổi giá ở bước chuyển đổi; `booking_lines` vẫn chỉ đóng băng `io_rate_at_booking` / `floor_cpm_at_booking` như trước.

Đây là phạm vi T2 hay tách sang hạng mục rate card?

### Q4. `effective_screen_count` có phải nguồn đúng cho số thiết bị?

Tôi thay `screen_count` do client gửi bằng `$inv?->effective_screen_count`. Codex từng nói `screen_count_override` **không tự động chứng minh số thiết bị billable**. Vậy dùng nó để nhân tiền có đúng không, hay nên cố định bằng 1 cho tới khi có quyết định về quan hệ Screen ↔ thiết bị vật lý?

### Q5. Dòng giỏ cũ không có ảnh chụp thì tôi bỏ qua kiểm

Giỏ tạo trước đợt này có `rate_snapshot = null`; tôi cho qua thay vì chặn. Lập luận: không hợp thức hoá chúng, chỉ không chặn, và chúng sẽ có ảnh chụp ngay lần cập nhật kế tiếp.

Nhưng nó cũng có nghĩa: ai còn giỏ cũ vẫn chốt đơn theo giá cũ được. Có nên buộc tính lại?

---

## 4. Những gì T2 cố tình không làm

- **Chưa có bậc giá theo thời lượng.** 12 kỳ vẫn bằng 12 lần một kỳ. Thuộc rate card.
- **Chưa có rate card có phiên bản và ngày hiệu lực.**
- **VAT mới chỉ đưa vào config**, `PaymentService` vẫn dùng hằng số riêng ở ba chỗ — chưa hợp nhất, để tránh trộn T2 với phần thanh toán.
- **Chưa kiểm eligibility ở `updateItem`, `createFromCart`, `submit`** — vẫn là nợ T3 như đã ghi ở vòng trước.
- **Chưa đụng giá của sản phẩm/gói**: `package_discount_pct`, `min_quantity` vẫn không được dùng trong tính giá.

---

## 5. Một phát hiện nghiệp vụ, không phải lỗi kỹ thuật

Với `month_days = 30`, **sáu tháng lịch (01/01–30/06 = 181 ngày) được tính thành 7 kỳ.** Test `'sáu tháng lịch = 181 ngày = 7 kỳ'` ghi lại điều này để nó không nằm im trong công thức.

Không sai về kỹ thuật, nhưng khách hàng sẽ thắc mắc. Ba hướng: giữ 30 ngày và nói rõ trên giao diện; đổi sang tháng lịch; hoặc làm tròn xuống khi phần dư nhỏ. Cần quyết định nghiệp vụ, tôi không tự chọn.

---

## 6. Câu hỏi nghiệp vụ còn treo

1. ~~Một "tháng" là tháng lịch hay 30 ngày?~~ → **tháng lịch**, đã cài.
2. Khoảng ngày tối đa cho một dòng đặt chỗ — đang để **365**. Đây là chặn đầu vào chống gõ nhầm năm và request bừa, không phải chính sách bán hàng. Chờ chốt.
3. ~~Mức VAT?~~ → **8%**, đã cài.

## 7. Thêm hai điểm cho Codex xem xét sau khi chốt quy ước

### Q6. Cách tính tháng lịch ở biên ngày 31

`31/01 → 28/02` = 1 kỳ; `31/01 → 29/02` (năm nhuận) = 2 kỳ, vì mốc bị kẹp về 29/02 nên kỳ 1 kết thúc ngày 28/02. Thuê 30 ngày mà tính 2 kỳ nghe không thuận tai.

Phương án khác: khi mốc bị kẹp thì coi kỳ 1 kéo tới hết tháng. Tôi chưa làm vì nó tạo ra quy tắc riêng cho tháng ngắn. Codex thấy nên chọn cách nào?

### Q7. Dữ liệu cũ tính theo quy ước 30 ngày

Đơn đã chốt giữ nguyên giá trong `booking_lines` nên không ảnh hưởng. Nhưng giỏ hàng dang dở và báo giá chưa gửi sẽ đổi số khi khách chạm vào. Có cần đánh dấu các dòng giỏ tạo trước 27/09 để phân biệt không, hay cứ để tính lại?
