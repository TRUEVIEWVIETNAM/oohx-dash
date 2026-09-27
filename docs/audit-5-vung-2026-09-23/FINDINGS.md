# Findings — evidence-first

Snapshot và giới hạn: [README](README.md). Severity mô tả mức ảnh hưởng nếu đường code được sử dụng trong deployment; không khẳng định đã có sự cố production. Ngoại trừ test bootstrap và các service probe được ghi riêng, đây là kết quả trace tĩnh, chưa phải penetration test/runtime exploit.

## F01 — CRUD Site/Screen không enforce quyền, owner scope fail-open

**Shared/Owner · BROKEN · Critical · P0.1.**

- Evidence: `routes/api.php:63` nhóm management chỉ `auth:sanctum`; `app/Http/Controllers/Api/V1/ScreenController.php:17,38,84,91,115,131,150` và `SiteController.php:12,22,39,46,62` không gọi policy/Gate/permission/ability. `app/Traits/HasOwnerScope.php:12–20` bỏ scope cho ApiClient và user không current_owner_id. `SiteController::store` nhận owner_id bất kỳ qua exists rồi create. Các ScreenPolicy/SitePolicy có tồn tại nhưng controller không dùng chúng. Base Controller không authorizeResource.
- Trigger: ApiClient được cấp token cho inventory read gọi `PUT /api/v1/screens/{screen}` hoặc `POST /api/v1/sites` của owner khác; authenticated user có token và current_owner_id rỗng cũng có query không scope. Publisher có current_owner_id vẫn có thể POST Site cho owner khác vì global read scope không ràng buộc create.
- Business impact: dữ liệu supply/pricing của owner có thể bị đọc/sửa/xóa ngoài quyền; public marketplace và booking dựa trên supply đó bị ảnh hưởng. Raw screen serialization cũng không có `$hidden` cho device/internal fields.
- Root cause: authentication được dùng thay authorization; tenant context fail-open; token principal inventory read và management không tách.
- Recommendation: deny-by-default management abilities + policy mỗi action; derive owner_id từ membership đã xác thực; phân biệt API client read principal và owner mutation principal; allowlist response DTO. Không chỉ thêm check frontend hoặc sửa global scope rồi bỏ qua create.
- Acceptance: inventory-only token có read inventory nhưng management CRUD 403; role read_only không mutate; user thiếu current tenant bị từ chối; cross-tenant create/update/delete 403/404; dữ liệu không đổi; token revoke/suspended principal bị chặn. Test cả user token và ApiClient token.
- Test evidence: Inventory*Test kiểm read API; chưa tìm thấy management API authorization tests. Dependency: security hotfix độc lập schema mới.

## F02 — Business action không áp role và active membership nhất quán

**Buyer/Owner/Shared · BROKEN · High · P0.1.**

- Evidence: `OrganizationUser.php:33` định nghĩa viewer/planner/admin permissions; `BookingController.php:177` và `PaymentController.php:119` chỉ so organization_id. `BuyerSettingsController.php:48` cập nhật org không check admin. `EnsureBuyerAuth.php:17` chỉ kiểm có ít nhất một org, không kiểm current membership còn hiệu lực/status. Booking/payment routes dùng `auth`, không buyer middleware.
- Owner evidence: `BookingInboxResource.php:132` scope owner đúng, nhưng `ViewBookingInbox.php:121–169` approve/reject không TenantPermission; `CampaignService.php:149` không check actor permission. ProductResource không override authorization và không có ProductPolicy; Edit/CreateProduct không thêm role check. `User::canAccessPanel` chỉ chứng minh có một active tenant membership, chưa kiểm đúng current tenant. Các policy inventory có check manage_inventory, nên không quy kết mọi resource đều thiếu quyền.
- Trigger: org viewer gửi POST submit/payment hoặc PUT organization; publisher read_only vào product CRUD/booking actions; membership current tenant bị thu hồi nhưng user còn membership tenant khác.
- Impact: vai trò chỉ đọc có thể tạo nghĩa vụ thương mại; tenant suspension/revocation không được đảm bảo trên mọi entrypoint.
- Recommendation: policy/action guard ở backend cho campaign, payment, product, booking decision; kiểm current membership/status và network/client/campaign scope, không chỉ hidden UI. Làm rõ quyền commercial của owner roles.
- Acceptance: matrix admin/planner/viewer/owner/manager/scheduler/read_only/reporting_only trên HTTP + Livewire; stale current IDs và suspended tenant phải denied; giữ test cross-owner booking visibility đang có.
- Test evidence: OrgUserPermissionTest/OwnerUserPermissionTest kiểm team management; BookingInboxBuyerContactTest kiểm đọc contact, không kiểm read_only approve. Dependency: P0.1.

## F03 — Product/package conversion mất danh sách inventory và product identity

**Public→Buyer→Owner · BROKEN · High · P0.2/P0.4/P0.6.**

- Evidence: `CartService.php:32–90` lưu selected_screen_ids nhưng luôn `screen_id = $product->screens->first()->id`; package quantity=1. `CampaignService.php:23–67` mỗi cart item tạo đúng một booking line theo item.screen, không đọc selection, không set product_id. `BookingLine.php:15` và migration products đã có product_id, nên đây là đường ghi bị đứt, không phải cột chưa có.
- Trigger: buyer chọn chỉ màn hình B trong product A+B, hoặc mua gói nhiều màn hình. Booking sinh cho màn hình A đầu tiên; product_id null, số màn hình campaign bằng số cart item. Product không có screen có thể lỗi dereference khi createFromCart.
- Impact: owner giao sai inventory, capacity không được giữ cho toàn gói, report/settlement không truy được sản phẩm đã mua.
- Root cause: product cart được nối vào flow screen cart cũ nhưng không có allocation expansion. UpdateItem còn định giá lại product item theo first screen IO/CPM.
- Recommendation: validate listing mode/min-max/membership selected units tại server; snapshot product composition; expansion thành allocations cho từng unit, money allocation rõ ràng và nhất quán tổng giá. Có thể một commercial line nhiều allocation, không ép một line vừa đại diện gói vừa đại diện screen.
- Acceptance: chọn B chỉ allocate B; gói N units giữ đủ N, tổng amount bảo toàn; product_id/version đầy đủ; screen ngoài product/inactive bị reject; missing inventory trả validation error và rollback sạch.
- Test evidence: không tìm thấy cart→product→booking regression test. Dependency: target Product/BookingAllocation.

## F04 — Giá IO có thể không khớp thời gian booking

**Buyer · BROKEN · High · P0.2/P0.6.**

- Evidence: `CartController.php:54–64` nhận duration_units, screen_count từ client chỉ với min:1. `CartService::estimateCost:252–260` ưu tiên duration_units do client gửi thay ngày; `updateItem:166–188` giữ duration_units cũ khi ngày đổi. `CartController::update:91–96` không validate end_date sau start_date hoặc start_date tương lai.
- Trigger: cùng khoảng sáu tháng, gửi duration_units=1 thì server tính một kỳ; đổi end_date dài hơn qua cart update vẫn giữ kỳ cũ. BookedCPMs/forecast cũng chưa được ràng buộc với khả năng delivery.
- Impact: giá snapshot và ngày phân bổ lệch, thiếu tiền hoặc tranh chấp. Không có báo giá authoritative để giải quyết.
- Recommendation: server derive billable periods từ rate-card policy/đơn vị thời gian được chuẩn hóa; hoặc từ số kỳ được duyệt rồi derive dates, không cho hai nguồn độc lập. Reprice/version khi đổi date/quantity. Ràng buộc end≥start, min duration/lead time/spot length/SOV.
- Acceptance: khoảng ngày giống nhau cho cùng rate card cho cùng amount bất kể giá trị hidden field; update date recalculates; negative dates rejected; boundary weekly/monthly/timezone/rounding có test. Probe `F04_client_duration_override` gọi service thật trên synthetic model.
- Dependency: P0.2 pricing domain, có thể vá validation trước migration.

## F05 — Capacity chưa là allocation có bảo đảm; range sum sai và draft chiếm chỗ vô hạn

**Buyer/Owner/Public · BROKEN · High · P0.6.**

- Evidence: `AvailabilityService.php:14–22,46–77` cộng SOV mọi line có overlap với cả khoảng yêu cầu; không bucket/daypart. Loại cancelled/rejected nhưng vẫn tính pending/paused/completed nếu overlap, không join campaign status. `CampaignService::createFromCart:59` tạo pending ngay khi campaign draft; không hold expiry/job. `BookingController::submit:157–162` check rồi ghi ở hai bước, không lock allocation. Owner approval/payment activation không kiểm capacity lần nữa. Migration booking_lines có index nhưng không unique/exclusion capacity guard.
- Trigger 1: hai booking 50% ở hai nửa tháng không trùng nhau → request 50% cả tháng bị tính existing=100% thay vì peak=50%. Probe `F05_disjoint_intervals` chạy query thật trên fixture tối thiểu.
- Trigger 2: tạo draft rồi bỏ, line pending vẫn bị tính lịch cho các buyer sau; không expiry. Không kết luận race luôn dẫn oversell: việc pending cũng được đếm có thể gây từ chối cả hai, nhưng không có atomic invariant đảm bảo kết quả dưới mọi interleaving/entrypoint.
- Public evidence: `FrontpageController::detail:59–73` dùng flatMap mảng keyed date của từng line; key trùng bị overwrite, không cộng giữa các line. Calendar chỉ đọc approved/active còn service tính pending, nên nguồn hiển thị/validation không thống nhất.
- Impact: vừa có khả năng mất doanh thu vì false conflict, vừa chưa bảo đảm không oversell/static double-booking. Không có channel reservation (owner content/direct/programmatic) hoặc maintenance.
- Recommendation: allocation ledger + expiring hold; lock theo unit/time bucket khi hold/confirm; static exclusive ranges, DOOH actual slots/SOV per daypart; một read model cho public/owner/buyer; DB transaction + idempotency.
- Acceptance: hai concurrent hold vượt capacity chỉ một thành công; hết TTL nhả chỗ; abandoned draft không giữ capacity; non-overlapping 50%+50% chấp nhận thêm 50%; calendar khớp ledger; static không bán trùng; retry/cancel không giải phóng hai lần. Phải chạy multi-connection DB tương ứng production, không chỉ SQLite.

## F06 — Payment kích hoạt campaign dù creative chưa được duyệt/assign

**Buyer/Owner/Admin · BROKEN · High · P0.7.**

- Evidence: `PaymentService::checkAndActivate:103–130` chỉ check campaign status và tổng paid, sau đó tất cả approved lines thành active. Không query creative/assignment/date/capacity. `BookingController::uploadCreative:98–115` chỉ MIME/size, không xác thực resolution/duration theo unit, lưu file vào public disk. `CreativeResource.php:119–164` duyệt/reject trực tiếp, reject không reason. `booking_line_creatives` mới có relation/schema, không tìm thấy assignment writer.
- Trigger: campaign approved không có creative hoặc có creative rejected; đủ payment → active. Có thể upload thêm creative ở trạng thái active vì không có lifecycle/version gate.
- Impact: trạng thái “đang chạy” không chứng minh đủ điều kiện phát; thay đổi asset sau approval không có version/reapproval. Creative URL là bearer public URL, không signed tenant access.
- Recommendation: CreativeVersion immutable + technical validation + assignment + owner/admin approval theo quyền; activation transition kiểm từng allocation, dates và payment rule; public/private asset policy rõ ràng.
- Acceptance: thiếu/rejected/wrong-spec creative không scheduled/live; thay file tạo version mới; assignment cross-tenant bị chặn; approved content immutable; signed private assets hết hạn và trái tenant bị chặn.
- Test evidence: LivewireUploadSecurityTest không kiểm flow buyer creative này. Dependency: P0.6 + P0.7.

## F07 — Tổng tiền toàn campaign che khuất công nợ theo owner

**Buyer/Admin/Owner · BROKEN · High · P0.9.**

- Evidence: `PaymentService.php:109–119` sum completed payments của toàn campaign để activate mọi line. Trong khi `breakdownByOwner:172–205` tách owner, code/tests ghi rõ buyer chuyển trực tiếp từng owner. `createPayment:35` nhận amount tùy nhập, không cap/allocation against receivable.
- Trigger: campaign của A+B tổng 220; completed payment 220 chỉ cho A, B chưa có tiền → global sum vẫn kích hoạt toàn campaign. Overpayment thật được admin xác nhận có thể gây sai phân bổ; đây không phải buyer tự đánh dấu completed.
- Impact: owner chưa nhận thanh toán vẫn bị đánh dấu active; thu hồi/refund/đối soát khó.
- Recommendation: PaymentAllocation/receivable per owner/order + reconciliation có bank evidence; overpayment đi vào credit/unallocated, không bù nợ owner khác tự động. Gate activation per allocation/payment policy đã chốt.
- Acceptance: trả thừa A không làm B paid/active; partial payment và refund phân bổ đúng; giữ legacy payments không owner vào exception queue, không tự split bằng giả định.
- Test evidence: PaymentPerOwnerTest::test_thanh_toan_cho_owner_nay_khong_lam_owner_kia_thanh_da_tra chỉ kiểm breakdown, không gọi checkAndActivate. Dependency: P0.9 + quyết định mô hình thu tiền.

## F08 — Proof pipeline sai kiểu ID, thiếu authentication/dedup và rollup

**Shared/Owner/Buyer · BROKEN · High · P0.8.**

- Evidence: `PlayerController.php:41–48` campaign_id/creative_id `nullable|integer`; migrations campaigns/creatives dùng ULID, model HasUlids. `2025_01_01_000011_create_impression_logs_table.php:14–15` bigint nullable, không booking_line FK. `/api/v1/player/*` không auth middleware; screen_uuid là locator duy nhất (`PlayerController:20,51`). `impression:58` create mới mỗi lần, không event ID uniqueness/signature/replay guard.
- Trigger: gửi ULID campaign/creative hợp lệ bị 422 (probe `F08_player_ulid`); bỏ hai ID vẫn ghi log. Người biết screen UUID có thể gửi heartbeat/impression; lặp cùng event tạo nhiều bản ghi. UUID không được coi là bí mật thiết bị lâu dài.
- Reporting evidence: CampaignReportService::getDailyImpressions lọc campaign ULID, còn getOverview/getScreenBreakdown đọc booking_lines.actual_*; không tìm thấy job/event/service update actual_impressions/actual_spots trong app. Không phủ nhận có thể có external writer, nhưng writer đó chưa được cung cấp/xác minh.
- Impact: proof không chứng minh đúng booking, số liệu bị thiếu hoặc lặp; không đủ căn cứ nghiệm thu/finance.
- Recommendation: tách legacy external IDs và ULID internal có mapping; device credential xoay vòng/signature; ProofEvent key unique, occurred/received time, booking+unit+creativeVersion linkage; reject mismatch; idempotent rollup và exception review.
- Acceptance: đúng ULID accepted; campaign/unit/creative không thuộc booking bị reject; retry một event vẫn một count; event lệch giờ/sai signature/quá hạn bị chặn; dashboard totals reconcile với accepted proof; static proof có review/rejection.

## F09 — Public lộ Floor CPM và claims không dựa trên verification record

**Public/Admin · BROKEN / STUB/MOCK (claims) · High · P0.3/P0.10.**

- Evidence: `resources/views/frontpage/detail.blade.php:185,188` hiển thị giá và nhãn “Floor CPM”; `ScreenInventory::getDisplayPriceAttribute:133–138` fallback floor_cpm. `detail.blade.php:192` luôn có AI Traffic Data “Cập nhật theo giờ thực”, Proof of Play “Video xác nhận hàng ngày”, Verified Location “Kiểm tra thực địa 2024”. Owner verified là boolean có verified_at/by, không verification loại location/traffic/proof và expiry riêng.
- API nuance: ScreenResource:39 dùng floor_cpm làm price_per_slot_vnd, min_booking_days=7 cố định; đây là authenticated inventory API, không gọi nó là anonymous endpoint. `floor_price` của Product hiện được UI đặt tên “Giá gói”, cần quyết định semantic trước khi quy kết là cost price.
- Impact: trái yêu cầu PDF không lộ floor, gây hiểu sai đơn vị CPM/slot và độ tin cậy supply. Không tìm thấy bằng chứng public lộ commission/cost-price trong vòng này; OwnerRevenueShareHiddenTest là điểm tích cực.
- Recommendation: public list/net price riêng với internal floor/cost; public DTO allowlist; verification records theo dimension + provenance/freshness/expiry; chỉ hiển thị claim khi có evidence, tách estimate khỏi đo lường thật.
- Acceptance: anonymous page/API public không có internal floor/cost/commission; giá/đơn vị đúng product; supply chưa verify không hiện checked badge; metadata/claims có nguồn và thời điểm. Dependency: P0.2/P0.3/P0.10.

## F10 — Public visibility gate không áp khi add-to-cart

**Public→Buyer · BROKEN · High · P0.3/P0.6.**

- Evidence: Product/Screen::publiclyVisible và FrontpageService có owner active gate; nhưng `CartController::add:43,55` chỉ exists, `CartService::addProduct:34`/`addItem:102` dùng findOrFail không publiclyVisible hoặc purchase eligibility. CampaignService cũng không revalidate status chủ sở hữu/unit/product.
- Trigger: buyer biết ID của paused/draft product hoặc inactive screen/owner suspended, POST trực tiếp `/cart/add` vẫn qua lookup thông thường (buyer không current_owner_id). Ẩn listing không ngăn đặt hàng direct request.
- Impact: supply bị gỡ/suspended vẫn đi vào transaction. Selected screen IDs chỉ exists, chưa bảo đảm thuộc product.
- Recommendation: centralized purchase eligibility và recheck ở quote/hold/confirm; không chỉ dùng public rendering filter. Historical booking vẫn xem được snapshot dù supply bị ngưng.
- Acceptance: hidden/suspended/unauthorized selection bị reject server-side, code rõ; suspended sau add nhưng trước confirm cũng bị chặn; existing confirmed booking không mất dữ liệu. Test hiện có PublicVisibilityGateTest chủ yếu đọc listing/detail.

## F11 — Rate-card version và commercial snapshot còn thiếu

**Owner/Buyer · PARTIAL · High · P0.2/P0.5.**

- Evidence: ScreenInventory.php:42–58 lưu IO/CPM hiện hành; Product.php:56–65 lưu giá hiện hành; không RateCard model/table. CampaignService::createFromCart:56–65 copy một số field giá/KPI và estimated_cost của cart nhưng đọc rate đang hiện hành tại conversion; không terms/product composition/currency/tax/fee/discount snapshot hoặc approved quote version.
- Trigger: giá inventory đổi sau add cart: estimated_cost có thể là giá cũ nhưng io_rate_at_booking/floor_cpm_at_booking lại là giá mới. Đổi nội dung inventory/terms sau xác nhận không có snapshot để tái lập hợp đồng.
- Impact: không tái tạo được giá và nghĩa vụ đã chấp thuận; agency net/gross/markup/commission chưa có permission boundary.
- Recommendation: authoritative pricing quote với rate_card_version_id, immutable line price components/terms/inventory/product versions; reprice + buyer accept khi thay đổi; historical uncertain data phải đánh dấu legacy/unknown, không bịa valid_from.
- Acceptance: đổi live rate không đổi quote/booking đã accept; expiry bắt buộc re-quote; reconciliation sum line/tax/fee khớp; client không thấy net/markup trừ quyền cho phép.

## F12 — Payment intent thiếu lifecycle/idempotency và confirm không chỉ rõ payment

**Buyer/Admin · BROKEN · High · P0.9.**

- Evidence: `PaymentController::show:28` yêu cầu approved/active nhưng `process:48–89` không kiểm status tương đương; chỉ owner có bất kỳ line. PaymentService::createPayment tạo pending mỗi POST, không idempotency key, invoice_number sinh max+1 không lock. `getSummary:147–158` và `breakdownByOwner:186–205` tính pending vào remaining/is_paid của breakdown. `ViewCampaign::getHeaderActions:118–134` confirm latest pending không đưa payment ID/owner/amount cụ thể vào action.
- Trigger: POST lặp hoặc POST campaign draft/rejected vẫn có thể tạo pending; report pending lớn làm UI hết remaining; admin xác nhận latest record không chọn được đúng giao dịch nhận ở từng owner.
- Impact: UI “đã trả” có thể chỉ là buyer báo chuyển khoản, khó đối soát; duplicate intent và mã invoice cạnh tranh; không có invoice entity dù có invoice_number. VNPay/MoMo là TODO có thông báo chưa hỗ trợ, không coi là gateway đã làm.
- Recommendation: payment intent trạng thái + key chống lặp + payable status + owner allocation; riêng pending/submitted/verified-paid; confirm explicit payment/owner/bank evidence; invoice numbering atomic và phân biệt invoice draft/legal issuance.
- Acceptance: replay tạo một intent; không tạo khoản trả cho rejected/draft/owner không có approved receivable; pending không đổi verified-paid; concurrent numbering unique; confirm sai owner/bank rejected; correction/refund audit.

## F13 — Screen import progress check gọi relationship `status` không tồn tại

**Owner/Admin · BROKEN · Medium · P0.10/P1.**

- Evidence: `ScreenImportService.php:293–302` sau mỗi 50 processed rows đi qua nhánh flush gọi `$import->fresh(['status'])`. Eloquent `fresh($with)` dùng argument để eager-load relationships, không select cột; `ScreenImport` không có relation status. Vendor local: `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php`, method fresh. Outer catch đánh dấu failed sau khi nhiều row đã commit.
- Impact: import lớn đi tới checkpoint này có thể fail sau partial writes; cancel/progress không đạt mục đích. Các row validation fail `continue` trước checkpoint nên không phải mọi lần đếm 50 đều chạy check.
- Recommendation: reload model/column đúng API, checkpoint cancel ở luồng chắc chắn chạy, progress/error accounting rõ, tested recovery. Audit không sửa code này.
- Acceptance: 49/50/51/>100 rows, invalid rows quanh checkpoint, cancel sau chunk, retry/restart đều cho kết quả dự kiến; không relation-not-found; row đã commit truy được import batch.
- Test evidence: chưa tìm thấy import workflow test. Dependency: import recovery design.

## F14 — Baseline test bị chặn và thiếu gate trước deploy

**Shared / Quality · BROKEN · High · P0 prerequisite.**

- Runtime evidence: `evidence/phpunit.txt` và JUnit: feature đầu tiên lỗi `SQLSTATE ... near "SHOW": syntax error ... SHOW INDEX FROM screens`. `phpunit.xml:26–28` SQLite RAM; `database/migrations/2026_03_20_000001_add_performance_indexes.php:10` MySQL-specific SHOW INDEX không guard. Các migration sau còn UPDATE JOIN/ALTER ENUM/INFORMATION_SCHEMA, nên chỉ sửa lỗi đầu chưa đủ.
- CI evidence: `.github/workflows` hiện có deploy lên main qua SSH gọi deploy.sh; không test stage trong workflow được commit. Không suy đoán nội dung deploy.sh trên server vì file đó không được cung cấp.
- Coverage gap: 226 phương thức test chủ yếu inventory read, policies/consent, invitation/panel/team, review, payment breakdown. Không tìm thấy concurrency allocation, price tampering, product expansion, PoP linkage/idempotency, creative activation và settlement scenarios.
- Impact: không có nền xác thực transaction trước rollout; “có test file” không đủ evidence pass.
- Recommendation: chọn DB test tương ứng production cho migrations/concurrency; nếu giữ SQLite thì driver portability phải có chủ đích. Thiết lập required CI gate, không migrate DB thật để chạy test. Không rewrite/lờ đi migration chỉ để test xanh.
- Acceptance: schema dựng từ trắng + upgrade fixture legacy + rollback rehearsal; suite baseline pass; transaction/unauthorized/idempotency/concurrency matrix pass; test artifacts gắn SHA.

## F15 — Self-registration không gán role để vào Buyer Filament panel

**Buyer/Shared · BROKEN · Medium · P0.1.**

- Evidence: `BuyerAuthController::register:88–134` tạo User, Organization, admin membership, current_organization_id nhưng không assignRole('buyer'). `User::canAccessPanel:131–135` yêu cầu system role buyer. InvitationService::attachToTenant:140–141 lại có assign role buyer.
- Trigger: tự đăng ký có thể vào `/my` nhưng không đáp ứng gate `/buyer/team`; invited buyer và self-register có hành vi khác nhau. Không đánh đồng với việc `/my` bị hỏng.
- Recommendation: một onboarding action thống nhất role/membership, kiểm trạng thái active; backfill có dry run cho user tự đăng ký, không tự nâng quyền tenant.
- Acceptance: self-register mở đúng team panel theo tenant-admin permission; viewer không được team management; invited/registered buyer nhất quán. Existing PanelAccessTest và PolicyConsentTest chưa kiểm đầy đủ cầu nối này.

## F16 — Audit trail/outbox chưa bao phủ giao dịch và thay đổi dữ liệu trọng yếu

**Shared/Admin · PARTIAL · Medium · P0.1/P0.10.**

- Evidence: CampaignActivity::log có actor/time/description/metadata, nhưng CampaignService/PaymentService chủ yếu gọi log mô tả không before/after/reason structured. Price/availability/verification mutations không có audit domain tập trung. Oohx Config AuditLog thuộc connection Data Engine, không thể dùng thay marketplace audit.
- Notification evidence: BookingSubmitted/Resolved dùng `via=['mail']`, không ShouldQueue; submit cập nhật status rồi gửi thông báo ngoài transaction/outbox. Không có notification center/conversation; chưa có recovery chứng minh email failure không làm luồng không rõ trạng thái.
- Impact: khó xác minh ai đổi giá/quyền/booking, khó retry side effects hoặc điều tra dispute. Không có admin impersonation workflow được tìm thấy, do đó yêu cầu banner/audit cho tính năng này hiện MISSING, không nói feature đang âm thầm chạy.
- Recommendation: append-only AuditEvent + transactional outbox cho state changes; correlation/idempotency; notifications action-required có retries và delivery status; reason bắt buộc adjustment.
- Acceptance: mỗi mutation trọng yếu có actor/tenant/time/before-after/reason/correlation; notification retry không lặp transition; admin adjustments để lại version/event, export audit có permission.

## F17 — Import file public, cleanup sai disk và batch recovery chưa an toàn

**Owner/Shared · PARTIAL/BROKEN · Medium · P0.10/P1.**

- Evidence: `Publisher/Pages/ImportSites.php:80–87,131,198` lưu/read disk public; cleanup success/back dùng local ở 215/226, nên file public gốc còn lại. Đầu vào có MIME/size validation — không quy kết hoàn toàn không validate upload. ScreenImportService:280 transaction từng row, không batch rollback. ImportScreensJob:26 timeout 1800, tries=1 vì double-run có thể duplicate; config/queue.php defaults retry_after=90, cần so deployed override trước kết luận runtime.
- Impact: workbook nguồn tồn tại ở public path lâu hơn cần thiết; partial import không undo được; queue retry_after nhỏ hơn job timeout có nguy cơ xử lý trùng khi nhiều worker (deployment-dependent).
- Recommendation: private import storage, cleanup đúng disk/TTL; batch/row journal, unique identity và idempotent upsert; retry_after > job timeout hợp lý, checkpoint resume và compensating rollback có audit.
- Acceptance: workbook không truy cập anonymous; complete/cancel/failed cleanup theo retention; cùng batch chạy lại không duplicate; rollback chỉ hoàn nguyên row của batch chưa bị sửa tiếp.
- Test evidence: LivewireUploadSecurityTest kiểm global upload constraints, không chứng minh retention/rollback của import.

## F18 — Webhook SSRF boundary/retry/queue delivery còn thiếu

**Shared · BROKEN · High · P0 security, P1 reliability.**

- Evidence: WebhookController::register:16–20 chỉ validate URL, không allowlist scheme/host/private-IP/redirect. SendWebhookJob:40–46 HTTP POST tới URL đó. Có HMAC SHA-256, timeout và backoff — giữ các phần tốt này.
- Retry defect: SendWebhookJob:65 gọi `$this->fail(...)` cho non-2xx; vendor InteractsWithQueue::fail đánh dấu failed, không release/throw cho retry bình thường. Nhánh “sau 3 failures inactive” không đạt theo cơ chế HTTP failure hiện tại. Payload không event_id ổn định để consumer dedup.
- Queue evidence: job `onQueue('webhooks')`; docker-compose queue command không có `--queue=webhooks,default`, config mặc định queue=default. Cần xác minh production worker override; chưa kết luận webhook production chắc chắn không chạy.
- Impact: credential inventory client có thể đăng ký callback trỏ vùng nội bộ tùy egress runtime; delivery có thể mất sau lỗi HTTP đầu tiên hoặc không có consumer.
- Recommendation: validate destination và resolved IP khi gửi, chặn private/link-local/metadata/redirect ngoài policy; stable event ID + signed body + retry/failed queue observability; worker subscribe đúng queue; after-commit/outbox để tránh event cho transaction rollback.
- Acceptance: private/loopback/link-local URL rejected và redirect/DNS rebind bị chặn; 500/timeout retries đủ chính sách, exhausted thành exception queue; duplicate delivery một business effect; worker inventory/health theo queue được kiểm chứng.
