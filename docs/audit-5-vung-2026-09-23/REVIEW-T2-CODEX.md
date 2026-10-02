# Review T2 — Codex — 26/09/2026

**REQUEST CHANGES.** Review working tree trên `b9923a0` (T1a đã commit). Phạm vi T2 suy từ diff: tính kỳ/CPM, validation cart, snapshot giá và chuyển giỏ thành campaign; chưa có REVIEW-REQUEST-T2 trong thư mục audit tại lúc bắt đầu. Đối chiếu mục 3, mục 14 của IMPLEMENTATION-P0-CLAUDE và R03 trong REVIEW-IMPLEMENTATION-P0-CODEX.

Không sửa mã ứng dụng. Test/artifact review nằm trong docs; các kết luận về chính sách dưới đây dựa vào quyết định được ghi trong repo, không giả định đã có phê duyệt ở cuộc trao đổi ngoài repo.

## F1 — Đã được sửa trong lúc review: so sánh thứ tự key snapshot

`app/Services/CampaignService.php:133` so `$current !== $item->rate_snapshot`. PHP so strict array tính cả thứ tự key, trong khi MySQL JSON có thể chuẩn hóa lại thứ tự. rateSnapshot tạo thứ tự pricing_model/floor_cpm/io_rate/io_rate_unit; bản đọc lại từ DB có thứ tự io_rate/floor_cpm/io_rate_unit/pricing_model. Giá hoàn toàn giống nhau vẫn ném 409.

Artifact debug của chính Claude `run-20260926T130436Z-17717/phpunit-mysql.txt` đã ghi rõ SAVED/RELOADED/CURRENT khác thứ tự. Test `test_gia_khong_doi_thi_chot_don_binh_thuong` là regression quan trọng; không coi đây là lỗi baseline cũ. Cần chuẩn hóa schema, kiểu và key trước khi so sánh hoặc dùng value object/canonical representation, giữ kiểm khác giá thật. Không chỉ bỏ strict type một cách mù quáng.

**Cập nhật trong lượt review:** CampaignService đã được bên khác sửa thêm ksort cho cả current/snapshot trước phép so sánh, trong khi runner Codex đang chuẩn bị môi trường. Đây là cách sửa phù hợp cho cấu trúc 4 key phẳng hiện tại. Không tiếp tục coi lỗi thứ tự key là blocker của bản cuối nếu regression pass. Hash trước/sau được lưu riêng trong evidence; test debug cũng đã bị xóa khỏi working tree trong lúc review.

## F2 — P1: giỏ cũ và product mới bỏ qua kiểm giá

`CampaignService.php:127` continue khi snapshot rỗng. Migration để null cho dữ liệu cũ, và `CartService::addProduct` chưa ghi snapshot. Do đó hai nhóm đều tạo booking mà không chứng minh giá đã được chấp nhận còn hợp lệ. Booking vẫn copy estimated_cost cũ nhưng đọc io_rate/floor_cpm hiện hành — chính lỗi R03 yêu cầu đóng.

Comment “không hợp thức hóa, chỉ không chặn” không thay đổi hành vi. Cần trả trạng thái yêu cầu báo giá lại, tính lại theo contract screen/product và yêu cầu buyer chấp nhận; không dùng null như đường miễn kiểm. Thêm test giỏ có sẵn trước migration, product đổi floor/individual price, và dữ liệu không đổi khi bị từ chối.

## F3 — P1: áp công thức nghiệp vụ đang được đánh dấu chưa duyệt

`CartService.php:260` biến forecast thành mức CPM tối thiểu bắt buộc; dòng 300 dùng effective_screen_count (screen_count_override) làm số lượng tính tiền; BillablePeriodCalculator mặc định month=30. Các quyết định này bị yêu cầu không tự mặc định ở R03/mục 3; mục 14 câu 9–10 vẫn đang chờ. Config tự ghi month_days chưa được duyệt nhưng vẫn bật trong code tính tiền, không chỉ chuyển vị trí một hằng số: các lựa chọn client trước đây được chấp nhận nay bị ép theo công thức này.

Chuyển số sang config không thay thế phê duyệt contract. Cần ghi quyết định có nguồn hoặc giữ phần phụ thuộc chưa sẵn sàng/không mở giao dịch; tiếp tục các phần độc lập. Test 181 ngày=7 kỳ chỉ chứng minh implementation theo giả định 30 ngày, không chứng minh giá nghiệp vụ đúng. max_range_days=365 cũng chưa có quyết định được ghi.

## F4 — P1: sửa ngày một phần không validate khoảng ngày đã hợp nhất

`Buyer/CartController.php:105-107` so end_date với start_date của request, không với ngày hiện có trong cart. Chỉ gửi start_date sau end_date cũ thì end_date không được validate. `CartService::updateItem` sau đó merge và BillablePeriodCalculator::days dùng max(1, ...), nên khoảng ngày ngược được biến thành một kỳ thay vì từ chối. Test HTTP độc lập xác nhận trả 200 thay vì 422. Ca đối chứng chỉ sửa end_date hợp lệ đã pass; không báo ca này là lỗi.

Cần merge ngày mới/cũ trước validation, kiểm end>=start ở service entry point và từ chối khoảng ngày ngược; không clamp thành 1. maxRangeRule hiện đo end<=today+365 chứ không phải độ dài end-start: cần phân biệt booking horizon và max duration theo quyết định nghiệp vụ.

## F5 — P2: update không đổi ngày vẫn làm mất lượng buyer đã mua thêm

`CartService.php:195-204` loại booked_cpms/duration_units cũ vô điều kiện, kể cả khi chỉ sửa notes hoặc spot_length. Trong khi addItem cho phép mua thêm và test của Claude khẳng định 30 ngày mua 3 kỳ là hợp lệ. Update không liên quan sẽ hạ về 1 kỳ; CPM mua thêm cũng bị hạ về forecast. Đây là regression do thay đổi updateItem.

Tách quantity đã chọn khỏi quantity suy ra theo contract. Chỉ revalidate/requote khi dữ liệu ảnh hưởng giá thay đổi; thao tác không liên quan phải giữ commitment. Nếu chủ trương không cho mua kỳ dư thì phải sửa contract và add flow nhất quán, không vừa nhận vừa âm thầm bỏ.

## F6 — P1: snapshot không đại diện đủ báo giá và chưa đi tới booking

`CartService.php:168` chỉ chụp 4 trường. Nhưng T2 đã dùng effective_screen_count để tính tiền; thay override không làm snapshot thay đổi. Các yếu tố forecast/CPM, currency, KPI, phiên bản quy đổi kỳ và giá/composition product cũng chưa nằm trong snapshot. Sau khi sửa F1, các thay đổi này vẫn có thể lọt qua guard.

CampaignService lưu các giá live từ relation thay vì copy một snapshot booking đầy đủ; không có lock/version guard cho nguồn giá/composition. Transaction bao ngoài không tự đảm bảo nguồn báo giá còn đúng ở thời điểm chuyển đổi. Cần snapshot có version bao quát yếu tố billable, chốt acceptance, kiểm nguồn bằng lock/version trong transaction và copy snapshot đã chấp nhận xuống booking line. Chưa chạy concurrency trong lượt review này.

## F7 — P2: update product dùng công thức single screen

addProduct tính theo floor_price/individual_price và tập screen, nhưng updateItem vẫn gọi estimateCost trên screen đầu tiên rồi ghi snapshot inventory của screen đó. Sửa ngày/spot có thể biến giá product thành giá inventory một screen và gắn snapshot không đúng nguồn. Đây là nợ có sẵn nhưng R03 yêu cầu update cả product lẫn screen; T2 đang sửa đúng entry point này nên không thể coi pricing đã hoàn chỉnh nếu bỏ qua. Cần hai đường tính giá theo contract, không dùng chung công thức chỉ vì đều là CartItem.

## Phần đúng hướng và yêu cầu kiểm chứng

- Bỏ screen_count từ request, tách calculator và copy Carbon trước startOfDay là cải thiện kỹ thuật; nguồn quantity thay thế vẫn cần contract.
- Từ chối thay vì âm thầm nâng input quá thấp đúng nguyên tắc khi mức thấp/cao đã được định nghĩa hợp lệ.
- Migration nullable, kiểm cột trước thêm, tạo điều kiện nâng cấp; chưa có test upgrade với cart cũ đúng R03.
- Đã thêm test giá đổi/không đổi, kéo dài ngày, tamper input; cần chạy xanh trên MySQL và bổ sung các ca trên. ZzDebugSnapshotTest chỉ dump rồi assertTrue(true), không phải acceptance test; file này đã được xóa trong lúc review.

## Evidence độc lập

Đã chạy `T2ReviewTest.php`: kế thừa 11 test CartPricingTest của Claude và thêm 5 kiểm tra notes-only, hai chiều cập nhật ngày một phần, legacy cart và snapshot quantity. Runner `run-t2-review.sh` dùng PHP 8.4.21/MySQL 8.0.46 cô lập, source read-only, che .env, không chạy probe ngoài T2.

**16 tests, 26 assertions: 12 pass, 4 failures, 0 errors.** 11 test của Claude đều pass sau sửa ksort. Trong 5 ca bổ sung:

| Ca | Kết quả |
|---|---|
| Sửa notes giữ lượng mua thêm | FAIL: 3 kỳ thành 1 |
| Chỉ sửa start_date làm khoảng ngày ngược | FAIL: HTTP 200 thay vì 422 |
| Chỉ sửa end_date hợp lệ | PASS |
| Giỏ cũ thiếu snapshot phải yêu cầu báo giá lại | FAIL: vẫn tạo campaign, không ném lỗi |
| Thay số thiết bị phải làm snapshot thay đổi | FAIL: snapshot giống hệt |

JUnit/log: `evidence-t2-codex/phpunit-mysql.xml` và `phpunit-mysql.txt`. Snapshot hash đầu review ở `source-sha256.txt`, sau sửa ksort trước test ở `source-before-tests-sha256.txt`. Log có warning không ghi được PHPUnit result cache do mount read-only; không liên quan 4 assertion failure. Không chạy full suite, unit calculator riêng, migration upgrade fixture hoặc concurrency. F3/F7 và các phần mở rộng của F6 là kết luận đối chiếu code/contract, không tuyên bố đã có test runtime cho từng trường hợp.

Tái lập với Git Bash/Docker, đặt đường dẫn và OUT mới phù hợp máy:

```bash
SUITE_FILTER=T2ReviewTest REPO='D:/Code-Project/oohx-matrix/oohx-dash' OUT='D:/Code-Project/oohx-matrix/oohx-dash/docs/audit-5-vung-2026-09-23/evidence-t2-codex-rerun' bash docs/audit-5-vung-2026-09-23/run-t2-review.sh
```

Đọc JUnit/phpunit_exit để xác định kết quả; exit cuối runner không phản ánh failure suite vì kế thừa cơ chế thu evidence của runner Claude.
