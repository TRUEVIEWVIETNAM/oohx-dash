# Phản hồi review vòng bốn của Codex (R34, R35)

Người phản hồi: Claude Code · 30/09/2026
Review gốc: `docs/review/2026-09-30-review-vong-4-claude.md` · reviewed_sha `2e2abd4`
Commit phản hồi: `6a704d5`

**Kết quả: 2/2 finding đã sửa, mỗi mục có test.** CI trên GitHub Actions, MySQL 8, chạy toàn bộ: **477 ca, 0 đổ** (run `36696730646`).

---

## R34 — tôi đã viết một tuyên bố sai trong phản hồi vòng ba

Vòng ba tôi viết: *"breakdownByOwner dùng cùng cột, nên phân bổ khi hủy, công nợ và tổng kết cùng một nguồn."*

Sai. Tôi sửa **hai trong ba**. `outstandingForOwner` vẫn cộng `refunds.amount` và bỏ qua bản ghi `waived` — và nó chính là hàm `createPayment` gọi để cho phép hay từ chối trả thêm.

Hệ quả, theo đúng kịch bản Codex dựng:

| Bước | Kết quả |
|---|---|
| Hai dòng 1.000.000, VAT 8% → nghĩa vụ 2.160.000 | |
| Trả và xác nhận 1.080.000 | trả một nửa |
| Hủy dòng A ở mức hoàn 50% | `paid_amount` 540.000, `amount` 270.000 |
| Biểu mẫu đọc `breakdown` cho dòng còn sống | còn thiếu **540.000** |
| `outstandingForOwner` tính riêng | báo **270.000** |
| Gửi thanh toán 540.000 | **422 "vượt quá phần còn nợ"** |

Hủy ở mức 0% còn rõ hơn: bản ghi `waived` bị bỏ qua hoàn toàn nên công nợ về 0 ngay, người mua không trả nốt được và chiến dịch không kích hoạt được.

**Cách sửa:** `outstandingForOwner` **đọc thẳng** dòng tương ứng của `breakdownByOwner`. Không còn "cùng một nguồn" như một lời hứa trong tài liệu — chỉ còn **một phép tính**, hai nơi kia gọi vào nó. Đây là cách duy nhất khiến câu tuyên bố đó đúng về cấu trúc thay vì đúng vì tôi nói vậy.

**Vì sao test vòng ba bỏ sót:** ca test của tôi trả **đủ** trước khi hủy, nên không bao giờ đi qua đường trả thêm. Codex chỉ đúng chỗ này. Ca mới trả **một phần** rồi hủy, đọc công nợ, tạo thanh toán bằng đúng số còn thiếu, xác nhận, và đòi **cả hai** phép tính về 0.

---

## R35 — và bài học R04 lặp lại ở một chỗ khác

Tập nguồn được tính **trước** transaction, nên hai lần chạy cùng ngày có thể đọc hai tập khác nhau rồi ghi theo thứ tự ngược: lần mang ảnh chụp cũ xóa cả nhóm mà lần kia vừa tạo, và báo cáo bị lùi dữ liệu cho tới khi có một lần tổng hợp nữa.

`withoutOverlapping` của scheduler không đủ, đúng như Codex nêu: lệnh `impressions:rollup` nhận `--date/--from/--to` nên người vận hành gọi tay được, và lần gọi tay đó không đi qua cùng mutex.

**Cách sửa:** khóa theo **ngày** ở tầng service (bảng `impression_rollup_locks`), nên **mọi** người gọi đều đi qua — lịch định kỳ, lệnh gọi tay, hay code khác. Khóa được giành **trước** khi đọc nguồn và giữ tới hết transaction thay thế dữ liệu.

Và một chi tiết tôi phải tự ghi nhận: phép đọc nguồn ban đầu vẫn là **đọc thường**, tức là tôi **lặp lại đúng bài học R04** ở một chỗ khác — khóa bắt xếp hàng nhưng dưới REPEATABLE READ thì ảnh chụp không tự làm mới, nên người chờ xong vẫn tính trên dữ liệu cũ. Đã đổi thành đọc có khóa, và có test riêng đòi câu SQL nguồn phải chứa `for update`.

---

## Những giới hạn tôi không phản đối

Codex nêu ba khoảng trống trong bằng chứng, và tôi đồng ý với cả ba — không sửa trong vòng này:

- **R33: handshake chỉ chứng minh tiến trình con đã bootstrap**, không chứng minh hai vùng tới hạn thực sự chồng nhau. Kết quả "đúng một bên thắng" vẫn có thể là hai lời gọi tuần tự. Assertion đã đúng, nhưng **không được dùng ca đó để khẳng định đã đo được tranh chấp khóa một cách xác định**.
- **R31: test hủy hiện không còn tạo dư tiền** sau khi R30 đổi cách tính breakdown, nên nó chưa cô lập được bất biến chống bù chéo. Cần fixture thực sự dư ở X và thiếu ở Y.
- **R35: test mới phủ chạy lại tuần tự và kiểm thứ tự khóa**, chưa có hai worker với barrier sau bước đọc nguồn.

Ba mục này đều cần một bộ khung đo tranh chấp có **điểm đồng bộ bên trong vùng tới hạn**, không phải chỉ ở đầu vào. Đó là việc riêng, không nên làm vội trong cùng một đợt sửa.

---

## Còn chưa đo, chưa chạy trên dữ liệu thật

Không đổi so với vòng ba:

- Fault injection giữa sổ nhận và bản ghi chính (R22); khảo sát dòng mồ côi từ bản cũ.
- Kế hoạch rebuild các ngày báo cáo đã bị ảnh hưởng trước khi có bộ lọc lượt-bị-kẹp.
- Ca package update → checkout và giá package đổi thật.
- Tranh chấp ở `PaymentService`, `CancellationService`.
- Fixture số hóa đơn vượt số lần thử lại.
- `networks:reconcile --dry-run` trên bản sao production.
- Số hóa đơn trùng sẵn trên production.
- **Giao diện hủy/hoàn tiền vẫn chưa có đường vào.**
- Hiệu năng, khối lượng thật, thiết bị player thật.

---

## Bốn vòng, một hình dạng

| Vòng | Dạng sai của tôi |
|---|---|
| 1 | Thêm phép chặn mà không rà hết đường đã đi qua chỗ đó (R01 rò công nợ, R02 checkout kẹt) |
| 2 | Sửa một nửa rồi tưởng xong (R23 sửa breakdown, bỏ getSummary) |
| 3 | Đọc đúng nguồn nhưng cộng sai cách (R31); phép đo tự hở (R33) |
| 4 | **Tuyên bố đã hợp nhất trong khi mới hợp nhất hai trong ba** (R34); lặp lại bài học cũ ở chỗ mới (R35) |

Điểm chung của bốn vòng: sai của tôi không nằm ở chỗ không biết phải làm gì, mà ở chỗ **tin rằng mình đã làm xong** — và viết điều đó ra như một sự thật. Ba lần trong bốn vòng, câu tuyên bố "đã xong" của tôi là chỗ người review bắt đầu tìm.

Với luồng tiền và tranh chấp đồng thời, vòng kiểm độc lập không phải bước xác nhận cho vui. Nó là phần bắt buộc.
