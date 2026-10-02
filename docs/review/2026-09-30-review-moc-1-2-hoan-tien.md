# Phạm vi và giới hạn

- reviewed_sha: `509dcc3a8be77288074c2e9f7dcc52247c3fb6f0`.
- Base của ba khối: `35e77d459244883f2fed3ad857dcf200301ba10a`. Ngày: 2026-09-30.
- Mốc 1: API v2 stats/screens/screen detail/owners, DTO, OpenAPI và error handling.
- Mốc 2: map có viewport/cap, filters, owner detail, thay đổi FrontpageService và test tương thích Blade.
- Hoàn tiền: đường buyer POST cancel, trang quote, action admin, danh sách/action publisher/admin, RefundPolicy, quan hệ model và luồng service được các entry point mới gọi.
- Đã đọc các file mới/sửa thuộc ba khối, test liên quan và source framework cần thiết để kiểm cơ chế Filament/throttle. Không tuyên bố đã đọc toàn bộ repo. Không đối chiếu repo khác; vendor là dependency trong workspace.
- Áp dụng pr-review, đối chiếu CLAUDE.md và lộ trình. Không tìm thấy AGENTS.md áp dụng. Không build/chạy test, không truy cập production/thiết bị. Không sửa ứng dụng hoặc commit; chỉ ghi tài liệu theo ủy quyền có sẵn.
- evidence_level của các finding là read-source. Các run CI và số 550 test trong lộ trình là báo cáo của Claude, chưa xác minh độc lập. Mọi ca tái hiện dưới đây là đề xuất kiểm chứng.

## Kết luận

Chưa nên chốt cả ba khối là hoàn tất. Có **6 finding: 1 P1, 5 P2**.

| Khối | Nhận định ở source |
|---|---|
| Mốc 1 | Whitelist DTO và cap phân trang có thực; còn sai hợp đồng query, currency/network, validation owners và HTTP headers lỗi. |
| Mốc 2 | Viewport được validate, cap/sắp xếp pin và count cùng builder phù hợp. Các lỗi hợp đồng/giá dùng chung vẫn ảnh hưởng map và owner detail. |
| Hoàn tiền | Đã có entry point thật; buyer kiểm campaign policy và line thuộc campaign, publisher scope theo owner. Còn lỗi xác nhận khoản chuyển tiền sau khi đã hủy làm mất nghĩa vụ hoàn. |

## R36 — P1 — Hủy khi chuyển khoản còn pending có thể làm mất nghĩa vụ hoàn tiền

**Vị trí:** `app/Http/Controllers/Buyer/CancellationController.php:45`; đường được mở gọi `app/Services/Booking/CancellationService.php:96`–122 và `app/Services/PaymentService.php:169`.

Entry point mới cho buyer hủy ngay cả khi payment đang pending/processing. Quote chỉ tính completed payments, nên khi hủy nó ghi paid_amount=0, amount=0, status=waived. Payment đang chờ vẫn tồn tại. Admin vẫn có action confirmPayment (ViewCampaign, dòng 150 trở đi) vì chỉ kiểm có payment pending; confirmBankTransfer vẫn nhận khoản đó nhưng không đối soát lại refund của dòng đã hủy.

**Tái hiện đề xuất qua đường thật:**

1. Một dòng approved, bắt đầu sau 30 ngày, giá 1.000.000; tạo payment bank_transfer 1.080.000. Buyer đã chuyển ngoài ngân hàng nhưng sàn chưa xác nhận, DB còn pending.
2. Buyer POST hủy dòng qua route mới. Refund được ghi waived/0 vì chưa có completed payment.
3. Admin xác nhận chính payment đó sau khi kiểm ngân hàng. Payment thành completed, campaign/line vẫn cancelled, refund vẫn waived/0.
4. Danh sách hoàn tiền không có nghĩa vụ pending để owner xử lý; settle cũng từ chối waived. Buyer không thể hủy lại để tính lại.

**Tác động:** Hệ thống đã ghi nhận tiền thật nhưng không ghi nghĩa vụ hoàn tương ứng, dù buyer hủy trong kỳ hoàn 100%. Ca hoàn tiền hiện tại đều xác nhận tiền trước rồi mới hủy nên không bắt đường này.

**Đề nghị:** Thiết kế chung luồng hủy và đối soát payment chờ; tiền xác nhận muộn phải được phân bổ và tạo/điều chỉnh nghĩa vụ hoàn theo policy snapshot tại thời điểm hủy. Có thể đưa cancellation vào trạng thái chờ đối soát. Không chỉ đổi payment thành failed nếu buyer đã chuyển tiền thật. Serialize các bước có liên quan và test cả pending/processing → cancel → confirm, cùng thứ tự ngược.

## R37 — P2 — Cách serialize mảng trong OpenAPI không khớp request Laravel

**Vị trí:** `docs/openapi/v2.yaml:171`, cùng mẫu ở dòng 120–124 và 260–261; validation `app/Http/Requests/FrontpageListingRequest.php:17`.

Spec khai tên city/owner/network/... với style=form, explode=true. Client theo hợp đồng gửi ví dụ `city=hanoi&city=hcm` hoặc một phần tử `city=hanoi`. PHP/Laravel nhận dạng này là giá trị scalar (key lặp mất các giá trị trước), trong khi FormRequest bắt buộc array. Các test map lại gửi `owner[]=owner-a`, một encoding khác với spec.

**Tái hiện đề xuất:** Gọi `GET /api/v2/screens?city=hanoi` theo mảng một phần tử của spec: validation trả 422 thay vì lọc Hà Nội. Tương tự map có đủ bốn cạnh + owner=owner-a, và owner detail + network=net-a. Client dùng city[]=hanoi đi qua được nhưng đó chưa phải hợp đồng đã khai.

**Tác động:** Client sinh từ OpenAPI không dùng được bộ lọc như tài liệu; phép kiểm route hai chiều vẫn xanh vì không kiểm serialization.

**Đề nghị:** Chốt một encoding hỗ trợ được và đồng bộ spec/backend, giữ được toàn bộ phần tử. Không chỉ bọc scalar vào mảng vì key lặp đã bị PHP làm mất trước đó. Thêm test gửi URL thực tế do serializer/client theo spec sinh, gồm một và nhiều giá trị. Đây là một mẫu: cần rà mọi array query của cả ba endpoint, không chỉ city.

## R38 — P2 — network.code bị null ở danh sách dù có network thật

**Vị trí:** `app/Http/Resources/V2/ScreenSummaryResource.php:47`; eager load tại `app/Services/FrontpageService.php:581` và dòng 449.

DTO mới đọc site.network.code, nhưng getScreensPaginated/getOwnerScreens chỉ select network id,name,banner. Eloquent đã nạp quan hệ, nên đọc code không tự nạp lại cột bị bỏ; cấu hình thông thường trả null. Endpoint chi tiết screen select đủ network nên cùng một screen trả khác dữ liệu tùy endpoint.

**Tái hiện đề xuất:** Network có code=net-a, gắn site và screen công khai. GET /api/v2/screens hoặc /api/v2/owners/{slug}: network có name nhưng code=null. GET /api/v2/screens/{screenSlug}: code=net-a. OpenAPI khai code là string trong object network.

**Tác động:** Client mất khóa dùng để nối facet/network hoặc lọc tiếp, response không khớp schema. Test hiện không dựng network khi kiểm ScreenSummary.

**Đề nghị:** Bổ sung cột code vào các eager load phục vụ DTO, hoặc tập trung projection dùng chung. Test giá trị network ở list/detail/owner screens và validate response theo schema.

## R39 — P2 — API gắn nhãn VND cho CPM có thể đang lưu bằng USD

**Vị trí:** `app/Http/Resources/V2/ScreenSummaryResource.php:59`; cùng mẫu ở `app/Http/Resources/V2/MapPinResource.php:43` và CatalogController price_range_vnd.

floor_cpm_vnd trả round(floor_cpm) nhưng bỏ qua floor_cpm_currency. Đây không phải dữ liệu giả định ngoài mô hình: ScreenImport/FieldCatalog.php dòng 64 nhận VND/USD, ScreenWriter giữ currency, ScreenInventory::computeFloorCpmUsd có nhánh USD. display_price cũng trả số gốc khi CPM-only nên map.amount_vnd chịu cùng lỗi; min/max facet trộn số gốc.

**Tái hiện đề xuất:** Import screen công khai pricing_model=cpm, floor_cpm=2.50, floor_cpm_currency=USD. API screen trả floor_cpm_vnd=3, map trả amount_vnd=3/unit=CPM, dù đây là 2,50 USD, không phải 3 VND.

**Tác động:** Giá công khai và khoảng lọc sai đơn vị, có thể lệch nhiều bậc; client không còn currency gốc để tự sửa. Các test giá mới chỉ dùng VND.

**Đề nghị:** Nếu hợp đồng bắt buộc *_vnd, quy đổi qua một chính sách tỷ giá rõ ràng trước khi làm tròn; hoặc trả amount/currency đúng nghĩa. Đồng bộ list/detail/map/facets và lọc/sort giá. Không đổi hành vi v1. Thêm fixture USD và VND; quét hết mẫu gắn nhãn *_vnd cho số thô.

## R40 — P2 — Endpoint owners nhận input chưa validate và có thể trả 500 với q dạng mảng

**Vị trí:** `app/Http/Controllers/Api/V2/CatalogController.php:207`, gọi getOwnersPaginated; phép nối chuỗi tại `app/Services/FrontpageService.php:323`.

owners dùng Request thường, không FormRequest. q đi thẳng vào phép nối '%' . input('q') . '%'; type và pagination cũng không có bộ luật riêng ở đây. Các endpoint screens/owner detail có FormRequest nên hành vi không nhất quán.

**Tái hiện đề xuất:** `GET /api/v2/owners?q[]=x`. q là array; phép nối chuỗi gây Array to string conversion, được Laravel chuyển thành lỗi server thay vì lỗi 422 có field q. Không cần đăng nhập và không cần dữ liệu đặc biệt.

**Tác động:** Input sai có thể làm endpoint công khai lỗi 500. Lỗi này cũng đi ngoài hai renderer mới (ValidationException/HttpExceptionInterface), nên tuyên bố mọi lỗi v2 có envelope thống nhất chưa đúng.

**Đề nghị:** FormRequest riêng cho owners với q/type string, giới hạn độ dài và luật pagination nhất quán; bổ sung 422 vào spec. Test malformed scalar/array và 500 envelope an toàn cho lỗi hệ thống độc lập, không lộ stack trace.

## R41 — P2 — Renderer lỗi v2 làm mất Retry-After và Allow

**Vị trí:** `bootstrap/app.php:93` (return JSON của renderer HttpExceptionInterface).

Renderer tạo JsonResponse mới chỉ với body/status, không truyền $e->getHeaders(). ThrottleRequests của Laravel đã gắn Retry-After, X-RateLimit-Reset và các header hạn mức vào exception; MethodNotAllowedHttpException cũng mang Allow. Body JSON mới đã bỏ các header đó.

**Tái hiện đề xuất trên DB/cache test:** Gọi endpoint v2 tới mức 429, kiểm thiếu Retry-After dù exception có giá trị; hoặc POST /api/v2/stats để nhận 405 nhưng thiếu Allow. Không cần gọi/thử tải production.

**Tác động:** Client không biết thời điểm retry và mất metadata chuẩn của lỗi HTTP; đổi định dạng body không nên phá thông tin điều khiển giao thức.

**Đề nghị:** Giữ các header của HttpException khi tạo response JSON. Thêm test 429 có Retry-After và 405 có Allow, đồng thời giữ v1 không đổi.

## Những điểm đã đối chiếu và không ghi thành lỗi

- DTO mới liệt kê trường trả ra, không trả thẳng model. Chưa thấy lộ bank/device token qua whitelist đã đọc.
- Map validate đủ bốn cạnh, north > south, cap pin và báo truncated; query đếm và query lấy pin dùng chung builder.
- Buyer cancel gọi policy và kiểm line thuộc campaign; số tiền từ service, không nhận refund amount từ client.
- RefundPolicy kiểm membership theo owner của record; publisher query giới hạn current_owner_id, không có create/edit/delete refund tùy ý.
- Đã đọc vendor Filament: callMountedTableAction kiểm isDisabled(), và isDisabled() bao gồm isHidden(). Vì thế không kết luận “chỉ visible thì có thể gọi action trái quyền” chỉ từ việc callback settle thiếu authorize riêng. Cần test Livewire thực tế để bảo vệ chuỗi này.
- /admin có cổng super_admin. Action hủy hộ là quyền chủ ý khác buyer; nên định nghĩa quyền hủy hộ riêng nếu đưa thêm API sau này, không tái dùng policy buyer một cách máy móc.
- Thay đổi trước base xử lý R34 đã cho outstanding gọi breakdown; R35 đã khóa ngày trước đọc nguồn. Không lặp lại hai finding cũ như thể còn nguyên. Lần này không xác nhận đầy đủ test concurrency của các sửa cũ.

## Kiến trúc và giới hạn test

Việc cho Blade và API dùng chung service đọc, thay vì HTTP nội bộ, có thể chấp nhận về chi phí vận hành; nhưng chưa thực hiện yêu cầu “Blade đi qua hợp đồng API”. Lộ trình đã ghi rõ lệch này. Cần ghi thành quyết định kiến trúc và dùng test hợp đồng request/response thực sự để bù khoảng trống. R37/R38 cho thấy route parity và assertJsonStructure chưa đủ. Riêng screen detail còn query trực tiếp trong CatalogController thay vì dùng getScreenDetail, nên tuyên bố mọi endpoint chỉ có một đường truy vấn cũng chưa hoàn toàn chính xác.

Đã đọc các test CatalogApiTest, CatalogMapApiTest, CatalogFiltersAndOwnerApiTest, OpenApiContractTest, MapUnchangedByApiTest, CancellationEntryPointTest và RefundAccessTest. Các phần còn chưa đo:

- Client sinh từ OpenAPI thực sự gửi request; schema validation toàn bộ response.
- RefundAccessTest kiểm policy/query/service, chưa thao tác action Livewire admin/publisher end-to-end.
- Hủy khi payment chờ đối soát, hủy khác line đồng thời, confirm/settle đồng thời.
- Trình duyệt thật cho confirm hủy, notification, và giao diện xử lý lỗi validation.
- Dữ liệu production, độ trễ/cache, tải bản đồ, tỷ giá dữ liệu hiện hữu, thanh toán ngoài ngân hàng và thiết bị thật.

Phiên sở hữu mã cần chạy các ca đề xuất trên MySQL test an toàn và cung cấp kết quả gắn đúng SHA. Không dùng số ca CI được báo lại để thay cho các phép kiểm còn thiếu.

## Bàn giao

Thêm R36–R41 vào findings.jsonl, status=proposed, evidence_level=read-source, reviewed_sha đầy đủ như trên; giữ nguyên lịch sử R01–R35.

Phiên sở hữu mã cần phản hồi từng finding và commit cả báo cáo này cùng findings.jsonl, giữ nguyên reviewed_sha. Codex không commit.
