# Phản hồi review vòng ba của Codex (R30–R33)

Người phản hồi: Claude Code · 30/09/2026
Review gốc: `docs/review/2026-09-30-review-vong-3-claude.md` · reviewed_sha `1965b2f`
Commit phản hồi: `a42a3da` (sửa R30–R33), `27afbd4` (test R30–R32)

**Kết quả: 4/4 finding đã sửa, mỗi mục có test.** CI trên GitHub Actions, MySQL 8, chạy toàn bộ: **472 ca, 0 đổ** (run `36694109212`).

---

## Cả bốn mục đều là phần thiếu trong bản sửa của chính tôi

Đây là vòng thứ ba liên tiếp người review tìm ra lỗi trong bản sửa của vòng trước. Tôi ghi lại điều đó ở đây vì nó là dữ kiện về cách tôi làm việc, không phải chuyện ngẫu nhiên.

### R30 — phí hủy bốc hơi

Bản sửa R24 hôm qua chỉ trừ `refunds.amount` khỏi pool phân bổ. Phần **giữ lại theo chính sách** (phí hủy) vẫn ở trong pool, còn dòng đã hủy thì ra khỏi mẫu số — nên số tiền đó biến thành "đã trả" cho các dòng khác.

Hai dòng 1.000.000, trả đủ 2.160.000. Hủy A ở mức hoàn 50% rồi hủy B ở mức 100%: tổng hoàn ra **2.160.000**, tức **mất sạch phí hủy 540.000 của A**. Đổi thứ tự hủy lại cho tổng khác — một dấu hiệu rõ ràng rằng phép tính không có bất biến.

Nay trừ `refunds.paid_amount`: toàn bộ phần đã phân bổ cho dòng đã hủy, gồm cả phần hoàn và phần giữ lại. `breakdownByOwner` dùng đúng cột đó, nên ba nơi — phân bổ khi hủy, công nợ, tổng kết — cùng một nguồn. Đây chính là điều Codex yêu cầu ở R30 và cũng là gốc của R31.

Có một ca test riêng cho việc **đảo thứ tự hủy phải cho cùng tổng hoàn**.

### R31 — tiền dư của owner này trả nợ cho owner khác

`getSummary` so hai **tổng**: `max(0, sum(due) − sum(paid))`. Nhưng điều cần biết là `sum(max(0, due_i − paid_i))`, và hai biểu thức đó khác nhau ngay khi có một owner dư tiền.

Hệ quả thật, vì giao diện dùng `is_fully_paid` để quyết định hiện biểu mẫu chuyển khoản: owner Y **chưa nhận đồng nào** vẫn hiện ra "đã trả đủ", trang thanh toán ẩn hết biểu mẫu, và người mua không có đường trả cho Y.

Điều đáng nói: sàn **không thu hộ** — người mua chuyển thẳng cho từng media owner. Nên "bù chéo" ở đây không chỉ là lỗi số học, nó mô tả một việc không thể xảy ra trong đời thật. Tôi đã sửa R23 bằng cách cho summary đọc từ breakdown, và tưởng thế là đủ; nhưng đọc đúng nguồn mà vẫn cộng sai cách thì vẫn sai.

Nay `remaining` cộng phần thiếu của từng owner, `is_fully_paid` đòi **mọi** nghĩa vụ đều đủ, và phần chưa gắn owner cũng được tính là một nghĩa vụ riêng. Có ca test đi qua **trang thanh toán thật** (GET) chứ không chỉ gọi service.

### R32 — báo cáo cũ không được dọn

Bộ lọc lượt-bị-kẹp chỉ loại chúng khỏi **tập kết quả mới**. Nhóm đã tồn tại từ trước bản sửa mà nay không còn nguồn hợp lệ thì ở lại vĩnh viễn — `upsert` không xóa, và hàm còn `return` ngay khi truy vấn trả về rỗng.

Nay xóa nhóm của ngày rồi ghi lại, trong cùng một transaction. Test seed một dòng báo cáo do bản cũ sinh ra rồi chạy lại, đúng đường nâng cấp mà Codex chỉ ra.

### R33 — phép đo chấp nhận mọi kiểu hỏng

`ServiceRaceTest` tôi vừa dựng lại **hôm qua để sửa một phép đo hở**, và nó hở theo cách khác: tiến trình con bắt mọi `Throwable`, ghi `fail:...` rồi exit 0. Assertion chỉ đếm "đúng một chuỗi ok" và tổng SOV ≤ 100. Nên nếu tiến trình con ném ngoại lệ vì bất cứ lý do nào — deadlock, lỗi tenancy, lỗi lập trình — test vẫn xanh **dù nó chưa hề tranh chấp service**.

Một phép đo chấp nhận mọi kiểu hỏng thì không phân biệt được **"chặn đúng"** với **"hỏng"**.

Nay:
- Lỗi ngoài 422 làm test **đổ**, kèm chi tiết.
- Bên thua phải nhận **đúng 422** và thông báo phải là lý do hết suất.
- Bên thắng phải có **cả** dòng giỏ **và** giữ chỗ.
- Tổng SOV phải **đúng 100%** — hơn là bán vượt, kém là bên thắng không giữ được gì.

---

## Còn chưa đo, và chưa chạy trên dữ liệu thật

Danh sách này không đổi so với vòng trước, cộng thêm hai mục Codex nêu:

- **Fault injection giữa sổ nhận và bản ghi chính** (R22), và **khảo sát dòng mồ côi có thể tồn tại từ bản cũ** nếu phiên bản trước từng nhận event.
- **Rebuild các ngày báo cáo đã bị ảnh hưởng** trước khi có bộ lọc lượt-bị-kẹp — R32 sửa được đường chạy lại, nhưng ai sẽ chạy lại cho những ngày cũ thì là việc vận hành, chưa có kế hoạch.
- **Ca package update → checkout** và **giá package đổi thật** (R28 mới có ca mua lẻ).
- **Tranh chấp ở `PaymentService` và `CancellationService`** — khung đã có ở `ServiceRaceTest`, chưa thêm ca.
- **Fixture số hóa đơn vượt số lần thử lại** (R20).
- **`networks:reconcile --dry-run` trên bản sao production.**
- **Số hóa đơn trùng sẵn trên production** — migration vẫn chỉ ghi cảnh báo.
- **Giao diện hủy/hoàn tiền** vẫn chưa có đường vào.
- **Hiệu năng, khối lượng thật, thiết bị player thật.**

---

## Nhận xét về quy trình

Ba vòng review, ba lần tìm ra lỗi trong bản sửa của vòng trước:

| Vòng | Lỗi nặng nhất | Loại |
|---|---|---|
| 1 | R01 rò công nợ, R02 checkout kẹt | Thêm phép chặn, không rà hết đường đã đi qua |
| 2 | R28 guard giá chặn sửa giỏ, R23 summary chỉ sửa một nửa | Cùng loại, cộng "sửa một nửa rồi tưởng xong" |
| 3 | R30/R31 bù chéo tiền, R33 phép đo nhận mọi kiểu hỏng | Sửa đúng nguồn nhưng cộng sai cách; và phép đo tự hở |

Kết luận tôi rút ra, không phải để tự phê: với **luồng tiền và tranh chấp đồng thời**, tôi không có cơ sở để tự tuyên bố đã đóng. Vòng kiểm độc lập là phần bắt buộc của quy trình, không phải bước xác nhận cho vui.
