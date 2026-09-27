# Review T1 — Codex — 26/09/2026

Kết luận: **REQUEST CHANGES**. Bản sửa đi đúng hướng nhưng chưa đạt nghiệm thu T1/R02. Review working tree trên baseline `112e2aa`, không sửa mã ứng dụng. Những lỗi dưới đây phần lớn là khoảng trống cũ chưa được đóng khi controller bắt đầu gọi policy; không quy tất cả thành regression mới.

## Các phát hiện cần sửa

### F1 — P1: scheduler vẫn sửa được giá và programmatic

`app/Http/Controllers/Api/V1/ScreenController.php:126,172,193` dùng quyền `update` cho cả inventory, multiplier và programmatic. `ScreenPolicy::update` chỉ kiểm `manage_inventory`, mà `OwnerUser::PERMISSIONS` cho scheduler quyền này; `manage_pricing` ở dòng 57 chỉ cho owner/manager.

Vì vậy scheduler có token manage có thể PUT screen với `inventory.floor_cpm`, hoặc gọi updateMultipliers/toggleProgrammatic. Cần quyền pricing riêng, kiểm cả payload trong store/update và endpoint chuyên dụng, trước mọi mutation. Test phải kiểm 403 và dữ liệu không đổi.

### F2 — P1: context còn sót cho phép đọc sau revoke; owner suspended vẫn ghi được

`HasOwnerScope.php:25` tin trực tiếp current_owner_id. `ScreenPolicy.php:10-22` và `SitePolicy.php:10-22` không kiểm membership khi view/viewAny. Gỡ pivot nhưng giữ current_owner_id vẫn GET được inventory tenant cũ.

Đính chính Q3: ghi Site/Screen **có kiểm pivot lại**, qua `TenantPermission::getOwnerUser()`; không đúng khi nói thành viên bị gỡ vẫn tiếp tục sửa qua các action này. Tuy nhiên TenantPermission không kiểm owner active, nên owner suspended có pivot owner/manager/scheduler vẫn có thể ghi inventory qua API. Hai trường hợp cần test riêng. Đây thuộc quyền API của T1, không nên hoãn toàn bộ sang Filament.

### F3 — P1: response Screen/Site vẫn trả credential

`ScreenController.php:117` trả nguyên Screen; model Screen không có `$hidden` cho device_token. Site show cũng serialize screens lồng nhau. OwnerResource đã whitelist đúng hướng nhưng không giải quyết đường trả Screen. Người có quyền đọc inventory có thể nhận device_token; R02 yêu cầu không trả device credential. Cần DTO theo endpoint và test cả relation lồng nhau. Không cần đợi sửa giao thức player của T4 mới đóng response này.

### F4 — P1: stats bỏ qua quyền view_reports đã có

`OwnerController.php:116` dùng view(owner), cho mọi member qua. `OwnerUser.php:63` đã có view_reports và không cho scheduler/read_only. Đây không phải câu hỏi nghiệp vụ hoàn toàn chưa có lời giải: áp bảng quyền hiện hành, kiểm trên owner trong URL, kể cả khi khác current_owner_id. Nếu muốn thay bảng quyền mới cần quyết định nghiệp vụ riêng.

### F5 — P2: reporting_only vẫn đọc được inventory

Screen/Site viewAny/view chỉ xét current_owner_id, không xét `view_inventory`. Bảng quyền `OwnerUser.php:59` loại reporting_only, nhưng role này vẫn GET screens/sites được khi token có manage. Sửa policy đọc để dùng quyền đúng cùng membership/owner state.

### F6 — P2: cổng mua sản phẩm chưa kiểm màn hình thực tế

`CartService.php:40` gọi findPurchasableProduct, nhưng hàm này chỉ kiểm product active + owner active. `assertScreensBelongToProduct` không được gọi ở bất cứ đâu. selected_screen_ids chỉ được controller kiểm exists, nên màn hình ngoài product vẫn được lưu vào cart; screen inactive/maintenance trong product chưa bị gate chặn. Ngoài ra eager load `screens.inventory` còn owner_scope, khiến người vừa là publisher vừa là buyer mua product owner khác có thể nhận screens rỗng và screen_id null.

Cần resolve tập màn hình một lần theo buy_mode, kiểm membership của từng screen vào product và eligibility của toàn bộ tập, bỏ owner_scope có giới hạn cho truy vấn mua. Không chỉ nối helper hiện tại: vòng foreach trong helper còn dùng collection relation có thể đã bị scope lọc.

Đây là khoảng trống của phần service mới được đưa vào diff. Update cart, createFromCart và submit cũng chưa gọi eligibility; nếu tách sang đợt khác phải ghi rõ đây mới là gate lúc thêm giỏ, chưa đóng toàn bộ F10/R05. Phát hiện F6 dựa trên đối chiếu code, chưa có test chạy độc lập cho luồng product trong lượt này.

## Trả lời Q1–Q6

- **Q1:** nên mặc định đóng truy vấn quản trị khi không có tenant hợp lệ; discovery/purchase/system query dùng context và giới hạn rõ ràng. Không coi “không còn membership” là bằng chứng user là buyer. Việc buyer GET management hiện bị policy chặn là đúng, nhưng không đồng nghĩa global scope đã fail-closed. Không đổi scope hàng loạt mà thiếu regression cho guest, job, buyer và user có hai vai trò.
- **Q2:** bỏ scope ở relation có thể đúng ngữ nghĩa lịch sử/multi-owner; bản thân relation không chứng minh caller được phép đọc parent. Chấp nhận có điều kiện khi mọi entry point authorize cart/campaign/booking line và serialize whitelist; hiện chưa đủ evidence. Không thể kết luận chỉ chuyển withoutGlobalScope về caller là hết rủi ro. Comment nói buyer không tenant sẽ bị scope chặn cũng không khớp implementation hiện tại.
- **Q3:** sửa membership/owner state trên các API đang review ngay trong T1; phần web/Filament có thể chia commit nhưng R02 chưa hoàn thành cho tới khi đủ các bề mặt.
- **Q4:** giữ ability manage + policy. Nhóm route chứa cả GET nên tác động chuyển đổi không chỉ lệnh ghi. ApiClient không phải User trong chữ ký policy, không thể đơn thuần cấp manage cho partner để có quản trị hợp lệ. Có thể tách F09 thành task rõ ràng, nhưng không coi task này đã đóng public-data leak hoặc đạt release gate R02.
- **Q5:** service nhỏ resolve tenant context + membership/state dùng chung hợp lý hơn trait chỉ gom vài dòng. Việc lặp hai hàm không phải blocker độc lập.
- **Q6:** dùng view_reports hiện có; role cho phép là owner/manager/reporting_only/sales_manager, cộng ngoại lệ super_admin theo contract hiện hành.

## Phần làm đúng

- Dùng Gate::authorize phù hợp base Controller hiện tại; đã phủ action của ba controller.
- Tách ability manage và giới hạn owner_id khi tạo, ngăn chỉ định tenant khác.
- OwnerResource whitelist; member không tự đổi status/revenue share/billing_info qua update.
- Có test inventory-only, cross-tenant, buyer, owner whitelist và super_admin.
- Ghi chú AppServiceProvider nói thiếu đăng ký policy thì authorize “cho qua âm thầm” là sai; không có policy/ability phù hợp mặc định không được phép. Đăng ký tường minh vẫn hợp lệ.

## Kiểm chứng

Đã parse JUnit baseline và `evidence-claude/run-20260926T095757Z-15146/phpunit-mysql.xml`: 229/552 so với 240/575 tests/assertions, cùng 5 errors + 5 failures, tên 10 ca không đạt trùng khít. Đây xác minh artifact, không phải chạy lại full suite; artifact dirty-tree không có hash nội dung từng file để chứng minh tuyệt đối source hiện tại giống lượt chạy cũ.

Lượt chạy độc lập dùng `T1ReviewTest.php` kế thừa 11 test của Claude và thêm 7 regression về hành vi cần bảo vệ. Runner `run-t1-review.sh` dùng MySQL 8.0.46/PHP 8.4.21 dùng một lần, internal network, source read-only, che .env; evidence ở `evidence-t1-codex/`.

**Kết quả: 18 tests, 31 assertions, 12 pass, 6 failures, 0 errors.** Toàn bộ 11 test của Claude pass; test revoked member không được ghi cũng pass. Sáu test bổ sung còn lại fail đúng hành vi được mô tả:

| Kiểm tra | Kỳ vọng | Thực tế |
|---|---|---|
| Revoked member đọc screen | 403 | 200 |
| Owner suspended sửa screen | 403 | 200 |
| Scheduler sửa floor_cpm | 403 | 200 |
| Read_only xem revenue | 403 | 200 |
| Reporting_only xem inventory | 403 | 200 |
| Screen response không có device_token | Không có key | Có key |

JUnit: `evidence-t1-codex/phpunit-mysql.xml`; log: `evidence-t1-codex/phpunit-mysql.txt`; hash source: `evidence-t1-codex/source-sha256.txt`. Không chạy lại full suite trong lượt này. Các test bổ sung cố ý assert hành vi bảo vệ cần có, vì vậy fail là bằng chứng lỗ hổng còn tồn tại, không phải lỗi hạ tầng.

Tái lập bằng Git Bash với Docker đang chạy, đặt REPO/OUT thành đường dẫn tuyệt đối phù hợp máy:

```bash
SUITE_FILTER=T1ReviewTest REPO='D:/Code-Project/oohx-matrix/oohx-dash' OUT='D:/Code-Project/oohx-matrix/oohx-dash/docs/audit-5-vung-2026-09-23/evidence-t1-codex-rerun' bash docs/audit-5-vung-2026-09-23/run-t1-review.sh
```

Runner kế thừa bước probe ImpressionLog từ runner Claude; probe này không được dùng để kết luận lỗi T1. Kết quả suite phải đọc JUnit/phpunit_exit, không suy từ exit code cuối runner vì runner tiếp tục chạy probe sau failure.
