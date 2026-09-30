# Phạm vi và giới hạn

- reviewed_sha: `1965b2fffb7b92f38919f603fffa525b0ca91f75`.
- Base đối chiếu: `4887b0a82a620a2aa892e9f0a2356ac9f4db1b99`. Ngày: 2026-09-30.
- Đã đối chiếu phản hồi `2026-09-30-phan-hoi-claude.md`, các thay đổi application/migration/test trong khoảng trên, luồng thanh toán, hủy, giỏ hàng, attribution và rollup liên quan. Không tuyên bố đã đọc mọi file của codebase.
- Không đối chiếu repo khác. Không tìm thấy AGENTS.md áp dụng trong workspace hoặc các thư mục cha đã kiểm tra.
- Áp dụng pr-review; chỉ tạo/cập nhật tài liệu theo ủy quyền đã có. Không sửa ứng dụng, build, chạy test, commit, truy cập production hay thiết bị.
- Evidence của mọi finding là read-source. Con số 466 ca, 0 đổ / CI run 36663189312 là lời phản hồi của Claude, chưa được xác minh độc lập. Các kịch bản dưới đây cần phiên sở hữu mã chạy trên DB test.

## Kết luận

Bản sửa giải quyết được các tình huống gốc của R22, R25, R27, R28 ở mức source. R23, R24, R26 và bằng chứng R29 vẫn còn vấn đề cần xử lý. Chưa chấp nhận tuyên bố “8/8 đã sửa” theo nghĩa hoàn tất các luồng liên quan.

Có 4 finding đề xuất mới: 2 P1, 2 P2. Chúng là các phần còn thiếu/hồi quy của bản sửa, không phải kết quả thử nghiệm runtime.

| Mục | Kết luận ở source | Căn cứ |
|---|---|---|
| R22 | Phù hợp cho event mới | Receipt, log và mapping chung transaction; cột proof_url khớp validation. Test mới chỉ thử URL dài thành công, chưa fault-inject rollback. |
| R23 | Một phần | Dùng breakdown và làm tròn đúng, nhưng vẫn bù tiền giữa owner khi quyết định đủ tiền: R31. |
| R24 | Một phần | Ca cả hai hủy hoàn 100% được sửa; phần không hoàn của dòng đã hủy bị phân bổ lại: R30. |
| R25 | Phù hợp | Xét xung đột nội bộ cả inventory lẫn network_code; test dựng đúng trường hợp đảo nguồn. |
| R26 | Một phần | Event mới bị kẹp không quy thuộc line/không vào rollup, nhưng rollup cũ không bị loại khi chạy lại: R32. |
| R27 | Phù hợp | Kiểm pivot đúng line và creative approved. |
| R28 | Phù hợp | add/update/checkout dùng productTotal; có test mua lẻ sau update và đổi giá thật. Package chưa có ca update tương ứng. |
| R29 | Chưa đủ chứng cứ | Fixture đã commit và gọi service thật, nhưng bất kỳ exception nào ở bên thua vẫn có thể làm test đạt: R33. |

## R30 — P1 — Phần tiền không được hoàn của dòng hủy bị chia lại cho dòng còn mở

**Vị trí:** `app/Services/Booking/CancellationService.php:245`, liên quan dòng 240–265. Tiếp R24.

paidForLine trừ tổng refund.amount rồi chia toàn bộ số còn lại cho các dòng approved/active/completed. Nếu hủy ở mức 50% hoặc 0%, phần tiền giữ lại theo chính sách của dòng đã hủy chưa bị trừ khỏi pool. Dòng bị hủy đã ra khỏi mẫu số, nên số tiền này trở thành “đã trả” cho các dòng khác.

**Tái hiện đề xuất:** Hai dòng A/B cùng owner, mỗi dòng giá 1.000.000, VAT 8%, trả đủ 2.160.000. A bắt đầu sau 10 ngày (hoàn 50%), B sau 30 ngày (hoàn 100%). Hủy A: paid_amount=1.080.000, amount=540.000. Hủy B ngay sau đó: pool hiện tại = 2.160.000 − 540.000 = 1.620.000; chỉ còn B trong mẫu số, nên hoàn B 1.620.000 thay vì 1.080.000. Tổng hoàn 2.160.000 làm mất toàn bộ phí hủy 540.000 của A. Đổi thứ tự hủy cho ra tổng khác.

**Tác động:** Hoàn quá tiền theo chính sách và phụ thuộc thứ tự hủy; dòng còn sống cũng có thể được ghi nhận tín dụng từ phí hủy của dòng khác. Đây là đường service, chưa khẳng định đã có UI hủy.

**Đề nghị:** Chốt phần tiền đã phân bổ cho dòng hủy, gồm cả tiền hoàn và tiền giữ lại. Pool còn mở phải loại toàn bộ allocation đã chốt, không chỉ refund.amount; xử lý cả record waived 0%. Dùng cùng nguồn phân bổ cho outstanding/breakdown/summary. Thêm test hủy 50%→100%, 0%→100%, đảo thứ tự và top-up. Refund.paid_amount hiện đã lưu được thông tin cần đối chiếu nhưng cần xác định quy tắc với dữ liệu cũ.

## R31 — P1 — Tổng kết vẫn lấy tiền dư của owner này bù khoản thiếu của owner khác

**Vị trí:** `app/Services/PaymentService.php:320`–321; caller `resources/views/buyer/booking/payment.blade.php:36`. Tiếp R23.

getSummary lấy tổng paid và tổng due rồi so sánh, thay vì cộng remaining riêng từng owner và kiểm từng is_paid. Việc dùng breakdown làm đầu vào không ngăn bù chéo: sum(max(0, due_i−paid_i)) khác max(0, sum(due_i)−sum(paid_i)).

**Tái hiện đề xuất bằng các service hiện có:** Owner X có hai dòng giá 1.000.000, đã nhận 2.160.000. Hủy một dòng ở mức hoàn 50%, nghĩa vụ hoàn 540.000. Breakdown X còn due=1.080.000, net paid=1.620.000. Owner Y có một dòng giá 500.000, due=540.000 và chưa nhận tiền. getSummary cộng due=1.620.000 và paid=1.620.000, báo remaining=0/is_fully_paid=true, trong khi breakdown Y vẫn thiếu 540.000. Trang thanh toán ẩn toàn bộ form chuyển khoản. Trường hợp khác tạo dư tiền theo owner cũng gặp cùng phép bù chéo.

**Tác động:** Người mua bị chặn thanh toán cho Y dù chưa trả Y đồng nào. Thanh toán trực tiếp theo owner không cho phép chuyển tín dụng X sang Y.

**Đề nghị:** remaining cộng các remaining đã chặn dưới ở từng owner, cộng phần chưa gắn owner nếu có. is_fully_paid đòi mọi nghĩa vụ theo owner đã đủ tiền, không dựa vào so tổng paid/due. Giữ phân biệt pending với completed. Test summary và GET trang thanh toán với ít nhất hai owner, một bên dư và một bên thiếu. R30 sửa pool phí hủy là cần thiết nhưng không thay thế invariant tách owner ở summary.

## R32 — P2 — Chạy lại rollup không xóa nhóm cũ chỉ có event bị kẹp

**Vị trí:** `app/Services/Player/ImpressionRollupService.php:53`, liên quan filter mới dòng 49 và upsert dòng 75. Tiếp R26.

Filter mới loại clamped khỏi tập kết quả, nhưng rollupDay chỉ upsert nhóm còn xuất hiện; nếu tập rỗng thì return ngay. Không có thao tác xóa nhóm đã tồn tại nhưng không còn nguồn hợp lệ.

**Tái hiện đề xuất:** Ở phiên bản trước bản sửa, một ngày chỉ có event played_at_clamped=true đã được rollup thành một dòng báo cáo. Sau cập nhật, chạy lại rollupDay cho cùng ngày. Query không trả dòng nào, hàm return 0, nhưng dòng cũ trong impression_daily_rollups vẫn còn nguyên. Nếu ngày có cả nhóm hợp lệ và nhóm chỉ có clamped, nhóm hợp lệ được cập nhật còn nhóm sai vẫn tồn tại.

**Tác động:** Báo cáo vẫn chứa dữ liệu mà bản sửa cam kết loại bỏ, kể cả khi vận hành đã chạy tổng hợp lại. Test mới khởi đầu bằng bảng rollup trống nên không bắt được đường nâng cấp này.

**Đề nghị:** Đồng bộ đầy đủ tập nhóm của ngày, bao gồm xóa nhóm không còn hợp lệ, với transaction/serialization phù hợp cho các job cùng ngày. Có kế hoạch rebuild các ngày đã bị ảnh hưởng; không chỉ rely vào cửa sổ job thường kỳ. Test seed rollup trước sửa rồi chạy lại, cả ngày rỗng và ngày có nhóm hợp lệ khác.

## R33 — P2 — Test race coi lỗi bất kỳ ở bên thua là bằng chứng giữ suất đúng

**Vị trí:** `tests/Feature/Concurrency/ServiceRaceTest.php:229`–235; liên quan catch Throwable dòng 167 và 200. Tiếp R29.

Child bắt mọi Throwable, ghi fail:... rồi kết thúc bình thường. Parent cũng chuyển mọi Throwable thành fail. Assertion chỉ đếm đúng một chuỗi “ok” và tổng SOV ≤100, không xác nhận bên còn lại bị từ chối vì hết suất. Exit code 0 không bảo vệ được vì exception đã bị catch.

**Tái hiện đề xuất để kiểm độ nhạy của test:** Trong bản thử nghiệm test, cho child ném RuntimeException ngay bên trong try, trước addItem. Child ghi fail:RuntimeException và exit 0; parent giành 100%; wins=1, held=100. Các assertion cuối vẫn thỏa mãn dù child chưa tranh chấp service. Deadlock QueryException, lỗi tenancy hoặc lỗi lập trình cũng có thể bị chấp nhận tương tự.

**Tác động:** Phép đo mới có thể đạt mà đường R05 vẫn deadlock/lỗi, nên chưa dùng nó để xác nhận đã đóng R29. Fixture commit và gọi service là cải thiện thật nhưng chưa đủ.

**Đề nghị:** Bên thắng phải có cart item và hold chính xác; bên thua phải đúng lỗi hết capacity mong đợi, kiểm status và nguyên nhân, không chấp nhận Throwable chung. Mọi lỗi DB/khởi tạo phải fail test và hiện chi tiết. Handshake hiện chỉ chứng minh child đã bootstrap; cần điểm đồng bộ phù hợp để chứng minh hai thao tác chồng nhau, hoặc ghi rõ giới hạn nếu chỉ kiểm kết quả hai tiến trình. Test DeadlockOrderTest cũ vẫn có lỗi đã thừa nhận, không dùng số ca xanh của lớp đó làm bằng chứng.

## Kiểm chứng còn thiếu

- Phiên sở hữu mã cần chạy các ca R30–R33 trên MySQL test và cung cấp output/JUnit gắn đúng SHA. Review này không tự chạy test.
- R22: fault injection giữa receipt/log/mapping và retry tới đúng một log. Bản sửa transaction ngăn orphan mới; chưa có xử lý orphan có thể tồn tại từ bản cũ, cần khảo sát trên bản sao dữ liệu nếu phiên bản cũ từng nhận event.
- R26: cần bằng chứng dọn báo cáo đã tổng hợp trước sửa, không chỉ test event mới.
- R28: thêm ca package update→checkout và giá package đổi thật.
- Concurrency PaymentService/CancellationService, migration trên dữ liệu hiện hữu, hiệu năng và player thật vẫn chưa đo; Claude cũng đã ghi nhận các giới hạn này.
- Không kết luận toàn bộ hệ thống bảo mật hoặc đủ điều kiện phát hành từ phạm vi re-review này.

## Bàn giao

Append R30–R33 vào findings.jsonl với status=proposed, evidence_level=read-source và reviewed_sha đầy đủ nêu trên. Giữ nguyên record lịch sử R01–R29; fixed trong đó là phản hồi của Claude, không thay thế kết luận lần này.

Phiên sở hữu mã cần commit cả báo cáo này và findings.jsonl, giữ reviewed_sha để đối chiếu. Codex không commit.
