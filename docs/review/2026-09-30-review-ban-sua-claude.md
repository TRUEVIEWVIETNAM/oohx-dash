# Phạm vi và giới hạn

- Ngày review: 2026-09-30. reviewed_sha: `4887b0a82a620a2aa892e9f0a2356ac9f4db1b99`.
- Đối chiếu bản sửa sau review tại `2665dde03f5aa997862269e7d9fa741f3254df0a`, phản hồi `docs/review/2026-09-29-phan-hoi-claude.md`, các service/controller/view/migration và test liên quan. Đã đọc bổ sung thay đổi R05 sau SHA b850dfd.
- Áp dụng skill pr-review. Chỉ ghi tài liệu theo sự cho phép trước đó của người dùng; không sửa mã ứng dụng.
- Review tĩnh, evidence_level = read-source. Không build, chạy test, truy cập production hay thiết bị. Không đối chiếu repo khác; không tuyên bố đã đọc toàn bộ codebase.
- Số “456 ca, 0 đổ”, run 36563974878 là thông tin Claude cung cấp, chưa được người review xác minh độc lập. Các cách tái hiện dưới đây là đề xuất kiểm chứng từ source, không phải kết quả chạy.

## Kết luận đối chiếu

Chưa đủ cơ sở chấp nhận “21/21 đã đóng”. Trong 21 mục cũ, 14 mục có bản sửa phù hợp với tình huống gốc ở mức đọc source; 7 mục còn thiếu hoặc gây hồi quy trong luồng liên quan. Có 8 finding đề xuất bên dưới: 5 P1, 3 P2, gồm cả vấn đề chất lượng test R05.

“Phù hợp ở source” không đồng nghĩa đã xác nhận runtime hay đủ điều kiện phát hành.

| Mục cũ | Kết luận ở source | Căn cứ / phần còn thiếu |
|---|---|---|
| R01 | Phù hợp | Tách quyền xem buyer và owner, inbox giới hạn theo owner. |
| R02 | Phù hợp | Loại chính booking line khi giành lại hold. |
| R03 | Phù hợp | Cập nhật hold cho toàn bộ màn hình, kiểm tra ngày và SOV khi chuyển hold. |
| R04 | Phù hợp | Đọc sức chứa bằng locking read. Test SQL chưa chứng minh service không hồi quy. |
| R05 | Phù hợp | Khóa screen trước FK insert ở addItem/addProduct/createFromCart; chứng cứ test còn lỗi R29. |
| R06 | Chưa trọn vẹn | Guard phát hiện lệch tiền nhưng cart update tính theo giá màn hình: R28. |
| R07 | Phù hợp | Tách idempotency key khỏi CSRF token phiên. |
| R08 | Phù hợp | Serialize createPayment theo campaign; chưa đo hai request cạnh tranh. |
| R09 | Chưa trọn vẹn | Breakdown đã làm tròn, getSummary còn công thức cũ: R23. |
| R10 | Phù hợp | Duyệt creative gọi lại activation. |
| R11 | Phù hợp | Gate creative theo tập dòng cần kích hoạt. |
| R12 | Chưa trọn vẹn | Breakdown trừ refunds; summary và phân bổ tiền khi hủy lần sau chưa nhất quán: R23/R24. |
| R13 | Phù hợp | Lock và đọc lại dòng trước quyết định hủy. |
| R14 | Phù hợp | Tính lại trạng thái từ các dòng còn lại. |
| R15 | Chưa trọn vẹn | Unique event đúng khóa nghiệp vụ nhưng receipt/log không atomic: R22. |
| R16 | Chưa trọn vẹn | Bỏ đoán dòng và kiểm campaign; chưa kiểm creative gắn đúng line: R27. |
| R17 | Chưa trọn vẹn | Lưu timestamp gốc nhưng timestamp kẹp vẫn dùng quy thuộc và báo cáo: R26. |
| R18 | Phù hợp | Dùng chung cửa sổ nhận muộn và rollup. |
| R19 | Chưa trọn vẹn | Chưa phát hiện xung đột bên trong nguồn network_code: R25. |
| R20 | Phù hợp | Sắp thứ tự số hóa đơn theo số. Fixture test còn yếu. |
| R21 | Phù hợp | Giới hạn trọng số theo tổng tiền để tránh tràn phép nhân. |

## R22 — P1 — Receipt được ghi trước log làm mất vĩnh viễn event khi ghi log thất bại

**Vị trí:** `app/Http/Controllers/Api/V1/PlayerController.php:124`, liên quan dòng 130–165. Mở lại phần R15.

Receipt được insert trước ImpressionLog, không có transaction bao quanh cả hai và mapping. Khi log insert thất bại, receipt vẫn tồn tại với impression_log_id rỗng. Retry đi vào duplicate và trả 202; không có đường phục hồi để tạo log còn thiếu.

**Tác động:** Thiết bị retry đúng giao thức vẫn mất proof và lượt phát trong báo cáo.

**Tái hiện đề xuất:** Gửi event hợp lệ với proof_url dài 300 ký tự: validation cho tối đa 500 ở dòng 96 nhưng cột string proof_url trong migration impression_logs chỉ 255, MySQL strict bật. Log insert thất bại sau receipt insert. Gửi lại cùng event_id với URL ngắn: duplicate 202, không tạo log. Có thể kiểm chứng tương đương bằng fault injection tại log insert.

**Đề nghị:** Atomic receipt + log + mapping, hoặc inbox có trạng thái và cơ chế retry/khôi phục rõ ràng. Test orphan receipt hiện chỉ xác nhận 202/không có log, chưa chứng minh eventual recovery.

## R23 — P1 — Tổng kết thanh toán vẫn dùng tiền gộp trước hoàn và VAT chưa làm tròn

**Vị trí:** `app/Services/PaymentService.php:293`; caller `resources/views/buyer/booking/payment.blade.php:35`. Liên quan R09/R12.

getSummary vẫn cộng toàn bộ completed payments và nhân VAT dạng float; breakdownByOwner đã trừ nghĩa vụ hoàn tiền và làm tròn. View dùng is_fully_paid của summary để quyết định có hiện form thanh toán.

**Tái hiện đề xuất:** Hai dòng cùng owner, mỗi dòng 1.000.000, VAT 8%, đã trả 1.080.000. Hủy sớm một dòng, nghĩa vụ hoàn 540.000. Breakdown dòng còn lại thiếu 540.000 nhưng summary thấy gross paid 1.080.000 bằng toàn bộ tiền cần trả, nên ẩn form top-up. Trường hợp giá 1.000.001 trả đủ số nguyên 1.080.001 còn có thể bị summary coi thiếu 0,08.

**Tác động:** Người mua không thể thanh toán phần còn thiếu qua trang hiện tại; số tổng và trạng thái không nhất quán.

**Đề nghị:** Summary phải dùng cùng phép tính VAT và phân bổ net paid theo owner với breakdown. Test cả getSummary và trang thanh toán sau hoàn tiền, không chỉ breakdown.

## R24 — P1 — Hủy sau top-up phân bổ tiền mới vào cả dòng đã hủy

**Vị trí:** `app/Services/Booking/CancellationService.php:225`, dòng 234–239. Liên quan R12.

paidForLine lấy gross completed payments chia tỷ lệ theo tổng chi phí bao gồm cancelled lines. Sau khi hoàn lần đầu rồi trả thêm cho dòng còn sống, tiền mới vẫn được chia vào dòng đã hủy.

**Tái hiện đề xuất ở service:** A/B cùng owner, mỗi dòng 1.000.000; cả hai đủ điều kiện hoàn 100%. Trả 1.080.000, hủy A => hoàn 540.000. Top-up và xác nhận 540.000 cho B => net paid B đủ 1.080.000. Hủy B: công thức hiện tại trả 1.620.000 × 1/2 = 810.000, thay vì 1.080.000. Tổng hoàn 1.350.000 so với đã trả 1.620.000, thiếu 270.000 dù đều hủy trong kỳ hoàn 100%.

**Tác động:** Tính thiếu nghĩa vụ hoàn tiền. Đây là lỗi service; không khẳng định đã có UI hủy công khai.

**Đề nghị:** Ghi nhận phân bổ tiền theo line và bảo toàn phần đã chốt khi hủy; top-up chỉ phân bổ cho nghĩa vụ còn mở. Kiểm chứng chuỗi trả một phần → hủy → trả thêm → hủy tiếp.

## R25 — P1 — Reconciler không chặn xung đột bên trong nguồn network_code

**Vị trí:** `app/Services/Network/NetworkRelationReconciler.php:140`, liên quan dòng 152 và 208. Liên quan R19.

ambiguousSites chỉ xét DISTINCT screen_inventory.network_id. Nguồn network_code có nhiều network bị loại bởi HAVING COUNT(DISTINCT networks.id) = 1, nên mất dấu xung đột trước khi đối chiếu hai nguồn.

**Tái hiện đề xuất:** Site chưa có network; hai screen có inventory đều trỏ A, nhưng network_code lần lượt trỏ B và C, đều là network hợp lệ. Inventory không ambiguous; nguồn code không trả candidate; kế hoạch vẫn chọn A và không báo conflict. Nếu inventory cũng rỗng, site bị bỏ qua mà xung đột B/C vẫn không được báo.

**Tác động:** Có thể tự điền quan hệ khi dữ liệu còn mâu thuẫn, trái cam kết loại site mâu thuẫn ở bất kỳ nguồn nào.

**Đề nghị:** Thu thập tập candidate và conflict độc lập cho mỗi nguồn trước bước chọn; thêm test đảo chiều nguồn so với các test hiện có.

## R26 — P2 — Timestamp bị kẹp vẫn quy thuộc booking và đưa vào báo cáo

**Vị trí:** `app/Http/Controllers/Api/V1/PlayerController.php:153`, liên quan resolveBookingLine ở dòng 142 và `app/Services/ImpressionRollupService.php`. Liên quan R17.

reported_played_at và played_at_clamped giúp truy vết nhưng resolveBookingLine vẫn dùng thời điểm đã kẹp, rollup vẫn nhóm theo played_at và không loại clamped.

**Tái hiện đề xuất:** Event xảy ra 60 ngày trước được gửi hôm nay với campaign có line khớp screen ở biên now−7 ngày. Timestamp bị kéo vào cửa sổ mới, có thể gắn vào line không tồn tại ở thời điểm phát thật và tăng số liệu ngày mới. Event tương lai xa cũng bị kéo về hiện tại.

**Tác động:** Lưu được bằng chứng gốc nhưng chưa ngăn quy thuộc sai hợp đồng/ngày báo cáo.

**Đề nghị:** Tách dữ liệu nhận được khỏi dữ liệu đã xác minh; quarantine event ngoài cửa sổ khỏi rollup và attribution cho đến khi có chính sách xử lý. Test kết quả báo cáo, không chỉ hai cột timestamp.

## R27 — P2 — Creative cùng campaign nhưng không gắn với booking line vẫn được nhận

**Vị trí:** `app/Http/Controllers/Api/V1/PlayerController.php:190`. Liên quan R16.

resolveCreativeId chỉ kiểm creative thuộc campaign của line, không kiểm liên kết booking_line_creatives. Một campaign có nhiều dòng không có nghĩa mọi creative đều được phân phối trên mọi dòng.

**Tái hiện đề xuất:** Campaign có L1/L2; C2 chỉ gắn L2. Thiết bị của L1 gửi booking_line_id=L1 và creative_id=C2. Hàm vẫn lưu cặp L1/C2 như một liên kết bình thường.

**Tác động:** Proof mang liên kết nội dung không được xác minh cho suất đã bán; kiểm campaign chéo chưa đủ đóng toàn bộ lỗi chuỗi tham chiếu.

**Đề nghị:** Kiểm pivot line–creative khi xác nhận attribution. Nếu cần lưu việc phát sai nội dung như bằng chứng thô, lưu riêng trạng thái chưa xác minh thay vì đồng nhất với liên kết hợp lệ. Thêm test sai line trong cùng campaign.

## R28 — P1 — Sửa giỏ sản phẩm mua lẻ khiến checkout báo đổi giá dù giá không đổi

**Vị trí:** `app/Services/CampaignService.php:193`; nguồn giá lệch tại `app/Services/CartService.php:297` và dòng 308. Liên quan R06.

Guard mới so unitPrice sản phẩm × số screen với estimated_cost trong cart. Nhưng updateItem dùng estimateCost của screen đầu tiên để ghi lại estimated_cost, kể cả item được tạo bằng addProduct.

**Tái hiện đề xuất:** Sản phẩm individual giá 1.500.000/screen, chọn hai screen => cart 3.000.000; screen đầu có inventory IO giá 1.000.000, effective_count=1, kỳ ngày 1–31 cùng tháng. Gửi cập nhật spot_length=20 cho item. updateItem ghi lại giá theo screen đầu. Checkout thấy khác 3.000.000 và trả 409 giá thay đổi, dù owner không sửa giá sản phẩm.

**Tác động:** Chỉnh sửa giỏ bình thường có thể chặn mua hàng. Với package, expected lấy lại totalVnd nên guard cũng không sửa được việc update tính giá theo screen.

**Đề nghị:** Dùng chung chính sách định giá sản phẩm cho add/update/checkout; giữ guard phát hiện thay đổi giá thật. Thêm test update product item rồi checkout, cả individual và package.

## R29 — P2 — Test deadlock có thể đạt do chờ tiến trình lỗi thay vì chờ khóa

**Vị trí:** `tests/Feature/Concurrency/DeadlockOrderTest.php:152`, liên quan dòng 28, 77–106, 155, 215. Liên quan chứng cứ R05.

Test dùng RefreshDatabase; fixture screen/cart được insert trên connection mặc định trong transaction test, chưa commit. PDO của tiến trình con không thể thấy fixture như dữ liệu đã commit và có thể bị chặn ngay khi khóa/kiểm FK. RefreshDatabase của framework mở transaction ở beginDatabaseTransaction.

Ở test khóa trước, thời gian waited được tính sau proc_close, gồm thời gian chờ child kết thúc. Child có thể timeout vì fixture chưa commit, trong khi thao tác của connection cha hoàn tất ngay; waited vẫn vượt 0,5 giây. Exit code/stderr của child không được assert. Ở test thứ tự sai, không có handshake thì markTestSkipped, nên fixture lỗi không tạo bằng chứng về deadlock mong đợi.

**Tái hiện đề xuất:** Chạy nhóm trên MySQL và ghi thời gian riêng quanh thao tác DB của cha, SQLSTATE/exit code của child, kiểm fixture từ connection thứ hai trước khi spawn. So sánh với assertion waited hiện tại. Đây là phân tích source, chưa quan sát một lần chạy cụ thể.

**Tác động:** Bộ test không đủ làm căn cứ cho tuyên bố đã đo tranh chấp thật thành công, dù sửa lock order ở service là phù hợp.

**Đề nghị:** Dùng fixture đã commit riêng cho nhóm concurrency, teardown rõ ràng; handshake sau khi child giành khóa; đo chỉ thời gian câu lệnh DB; assert child thành công và đúng SQLSTATE cho ca lỗi. Test phải gọi đường service cần bảo vệ, không chỉ bản sao SQL.

## Khoảng trống kiểm chứng

- R03/R06 dẫn test hiện có nhưng chưa có ca product update → checkout hoặc product đổi giá theo đúng tình huống mới.
- R04 chứng minh khác biệt snapshot/locking read bằng SQL, chưa bảo vệ call site service trước hồi quy.
- R08/R13 kiểm vị trí serialize/thứ tự SQL không thay thế hai request thực sự cạnh tranh.
- R07 truyền trực tiếp các key khác nhau chưa bắt được hồi quy controller dùng lại CSRF; cần test HTTP/form.
- R15 cần fault injection giữa receipt/log và retry đến khi có đúng một log, ngoài test duplicate tuần tự.
- R20 fixture 9999/10000/10001 chưa đủ loại bản cũ: vòng retry vẫn có thể tìm được 10002. Cần fixture vượt số lần retry hoặc kiểm thứ tự chọn trực tiếp.
- Chưa đo migration trên dữ liệu hiện hữu, thời gian khóa, thông lượng player và hành vi trên thiết bị thật. Phiên sở hữu mã cần chạy các ca trên DB test MySQL và cung cấp kết quả/CI artifact nếu dùng làm bằng chứng đóng finding.

## Bàn giao

Giữ nguyên 21 record lịch sử trong findings.jsonl; trạng thái fixed ở các record đó là phản hồi Claude, không phải xác nhận mới của lần review này. Các finding R22–R29 được thêm với status=proposed, evidence_level=read-source và SHA đầy đủ nêu trên.

Phiên sở hữu mã cần kiểm chứng, phản hồi từng finding và commit cả báo cáo này cùng findings.jsonl, giữ nguyên reviewed_sha. Codex không commit.

