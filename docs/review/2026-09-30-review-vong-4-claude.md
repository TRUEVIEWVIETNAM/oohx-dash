# Phạm vi và giới hạn

- reviewed_sha: `2e2abd45ac3e0e7f818ec9caafdc46d03f2a3e85`.
- Base: `1965b2fffb7b92f38919f603fffa525b0ca91f75`. Ngày review: 2026-09-30.
- Đối chiếu phản hồi vòng 3, toàn bộ diff application/test mới, các luồng outstanding → createPayment → controller/form, cancellation, rollup và command/scheduler liên quan. Không tuyên bố đã đọc toàn bộ codebase.
- Áp dụng skill pr-review đã đọc ở các vòng trước. Không tìm thấy AGENTS.md áp dụng. Không đối chiếu repo khác.
- Review tĩnh, evidence_level=read-source. Không build, chạy test, truy cập production/thiết bị hoặc sửa ứng dụng. Chỉ ghi tài liệu theo ủy quyền của người dùng; không commit.
- “472 ca, 0 đổ”, CI run 36694109212 là thông tin Claude cung cấp, chưa xác minh độc lập. Các kịch bản dưới đây là đề xuất kiểm chứng từ source.

## Kết luận vòng 4

Chưa chấp nhận “4/4 đã sửa xong”: R31 và lỗi assertion cụ thể của R33 đã sửa phù hợp ở source; R30 và R32 còn thiếu. Có 2 finding mới: R34 P1 và R35 P2.

| Mục | Đánh giá | Bằng chứng và giới hạn |
|---|---|---|
| R30 | Một phần | Cancellation và breakdown đã trừ paid_amount, gồm waived. outstandingForOwner vẫn trừ amount theo cách cũ: R34. |
| R31 | Phù hợp ở source | Summary cộng remaining từng owner và kiểm every(is_paid), không bù chéo tiền nữa. |
| R32 | Một phần | Xóa và ghi trong cùng transaction giải quyết nhóm cũ khi chạy tuần tự. Query nguồn nằm ngoài vùng serialize: R35. |
| R33 | Phù hợp với lỗi được nêu | Throwable ngoài dự kiến bị assertion từ chối; bên thua phải 422 có SOV; kiểm đúng một item, một hold, tổng 100%. Chưa chứng minh hai critical section thực sự chồng nhau. |

## R34 — P1 — Công nợ dùng để tạo payment chưa trừ toàn bộ tiền đã phân bổ cho dòng hủy

**Vị trí:** `app/Services/PaymentService.php:141`–146; liên quan breakdown mới dòng 401–425 và createPayment dòng 78–91. Tiếp R30.

Bản sửa đổi CancellationService và breakdownByOwner sang refunds.paid_amount, gồm waived, nhưng outstandingForOwner vẫn cộng refunds.amount của pending/settled vào công nợ. Đây chính là hàm createPayment gọi để cho phép hoặc từ chối top-up. Vì vậy tuyên bố “ba nơi cùng một nguồn” trong phản hồi chưa đúng.

**Tái hiện đề xuất:**

1. Hai dòng A/B cùng owner, mỗi dòng 1.000.000, VAT 8%. A bắt đầu sau 10 ngày (hoàn 50%), B sau 30 ngày.
2. Tạo và xác nhận payment 1.080.000, tức trả một nửa tổng nghĩa vụ.
3. Hủy A: paid_amount=540.000, amount=270.000.
4. Breakdown mới dành cho B: due=1.080.000, net paid=540.000, remaining=540.000. Form lấy đúng remaining này làm amount gửi lên.
5. outstandingForOwner trả 1.080.000 − 1.080.000 + 270.000 = 270.000.
6. createPayment với amount=540.000 bị 422 “Số tiền vượt quá phần còn nợ”. Nếu tạo mặc định 270.000 rồi xác nhận, breakdown vẫn thiếu 270.000 nhưng outstanding đã bằng 0, không thể trả nốt.

Hủy A ở mức 0% còn rõ hơn: record waived có paid_amount=540.000, nhưng outstanding bỏ qua record đó và báo đã đủ tiền ngay.

**Tác động:** Buyer không hoàn tất thanh toán cho dòng còn sống; trạng thái đủ tiền/kích hoạt có thể bị kẹt. Lỗi này xuất hiện trong luồng API/form thanh toán sau khi service hủy đã chạy, dù hiện chưa có UI hủy.

**Đề nghị:** Dùng chung phép tính allocation đã chốt giữa cancellation, breakdown và outstanding; giữ pending/processing riêng để chống tạo trùng nghĩa vụ. Test đầy đủ trả một phần → hủy 50%/0% → đọc form → tạo payment bằng remaining → xác nhận → cả outstanding và breakdown về 0. Test mới hiện trả đủ trước khi hủy nên không đi qua top-up và bỏ sót lỗi này.

## R35 — P2 — Rollup dùng snapshot cũ có thể xóa kết quả mới của lần chạy khác

**Vị trí:** `app/Services/Player/ImpressionRollupService.php:63`–64; query nguồn tại dòng 29–51. Tiếp R32.

Transaction chỉ bao quanh delete và writeRollups; tập rows đã được tính trước transaction. Hai lần chạy cùng ngày có thể tính hai tập nguồn khác nhau rồi ghi theo thứ tự ngược. Xóa toàn bộ ngày biến một snapshot cũ thành thao tác xóa cả nhóm mới mà nó chưa từng đọc.

**Tái hiện đề xuất bằng hai worker trên DB test:**

1. Worker A query ngày D và nhận tập rỗng; tạm dừng trước DB::transaction.
2. Một impression hợp lệ của D được commit.
3. Worker B chạy rollupDay(D) đầy đủ, tạo nhóm đúng với plays=1 và commit.
4. Cho A tiếp tục: xóa toàn bộ rollup của D, thấy rows rỗng và commit return 0.
5. Log hợp lệ vẫn tồn tại nhưng báo cáo ngày D mất nhóm mà B vừa tạo.

Ca có sẵn nhóm cũ cũng tương tự: A chỉ đọc nhóm G1; B đọc thêm G2 và ghi cả hai; A xóa ngày rồi chỉ ghi lại G1. Trước bản sửa xóa toàn ngày, A không có G2 trong payload sẽ không xóa G2.

**Tác động:** Báo cáo có thể bị lùi dữ liệu sau rebuild chồng lịch chạy, cần một lần tổng hợp khác mới sửa lại; ngày ngoài cửa sổ mặc định có thể không tự được sửa.

**Điều kiện gọi:** Scheduler có withoutOverlapping cho lịch định kỳ, nhưng command hỗ trợ --date/--from/--to gọi trực tiếp service mà không dùng cùng mutex. Rebuild thủ công chồng scheduler, hoặc hai rebuild, vẫn đi vào đường này.

**Đề nghị:** Serialize theo ngày ở tầng dùng chung cho mọi caller, giành khóa trước khi đọc nguồn và giữ đến hết transaction thay thế dữ liệu; hoặc dùng version/watermark ngăn snapshot cũ ghi đè mới. Chỉ đưa query vào transaction mà không kiểm soát thứ tự snapshot/khóa chưa đủ. Thêm test hai worker với barrier sau bước đọc nguồn để chứng minh snapshot cũ không xóa kết quả mới.

## Khoảng trống kiểm chứng

- R31: test theo kịch bản hủy hiện không còn tạo dư tiền sau khi R30 đổi breakdown, nên chưa cô lập invariant bù chéo. Nên có fixture thực sự dư ở X, thiếu ở Y để bắt hồi quy riêng của summary.
- Test GET R31 mới assert tên owner, chưa assert form/amount gửi lên; chưa đủ chứng minh gửi payment thành công.
- R33: assertion lỗi đã được cải thiện đúng. Handshake chỉ chờ child bootstrap; kết quả vẫn có thể là hai lời gọi tuần tự. Không dùng ca này để khẳng định đã đo lock contention xác định. Đây là giới hạn bằng chứng, không lặp lại finding assertion đã được sửa.
- Test R32 mới phủ chạy lại tuần tự, chưa có hai worker; kiểm chứng R35 cần phiên sở hữu mã chạy trên MySQL test.
- Chưa đo concurrency thanh toán/hủy, fault injection R22, migration/dữ liệu tồn tại, rebuild production hay hiệu năng/thiết bị thật. Các giới hạn cũ chưa có bằng chứng mới thì vẫn giữ.
- Không suy diễn từ re-review này thành kết luận bảo mật toàn hệ thống hoặc đủ điều kiện phát hành.

## Bàn giao

Đã thêm R34–R35 vào findings.jsonl với status=proposed và reviewed_sha đầy đủ. Giữ nguyên các record lịch sử; trạng thái fixed do Claude phản hồi không thay thế đánh giá vòng này.

Phiên sở hữu mã cần kiểm chứng hai kịch bản, phản hồi và commit cả báo cáo này cùng findings.jsonl, giữ reviewed_sha để đối chiếu. Codex không commit.
