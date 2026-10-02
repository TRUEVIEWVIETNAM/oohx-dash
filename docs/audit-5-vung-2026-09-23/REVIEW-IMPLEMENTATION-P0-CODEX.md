# Review kế hoạch triển khai của Claude — 23/09/2026

**Kết luận:** kế hoạch đúng hướng sửa lỗi hiện hữu, nhưng bản trước review chưa đủ để triển khai nguyên văn. Đã hiệu chỉnh các đoạn nguy hiểm trong [IMPLEMENTATION-P0-CLAUDE.md](IMPLEMENTATION-P0-CLAUDE.md); các yêu cầu dưới đây bổ sung cho từng mục tương ứng và được ưu tiên hơn snippet minh họa còn giản lược.

Phạm vi review: đối chiếu tài liệu với source và [FINDINGS.md](FINDINGS.md), [PLAN.md](PLAN.md). Không sửa ứng dụng, chạy migration, xoay khóa, gọi player hay triển khai. Không chạy lại suite MySQL; số 219 pass/10 fail và lỗi insert MySQL là kết quả Claude báo cáo, cần log/SHA để tái lập. Source xác nhận `ImpressionLog` thiếu cơ chế sinh ULID và schema có PK ULID, nhưng không suy từ đó ra tình trạng mọi thiết bị/dữ liệu production.

**Cập nhật sau bàn giao evidence:** Claude đã cung cấp REPRODUCE-CLAUDE.md, runner, probe và log/JUnit trong evidence-claude. Codex đã đọc: 229 tests, 219 pass, 5 failures + 5 errors, 552 assertions; probe thiếu id và 6 partition. Yêu cầu cung cấp artifact đã được đáp ứng; chưa chạy lại độc lập và chưa đóng gate xanh. Hai lỗi invitation khác nguyên nhân (response 410 và assertion notification), đã sửa trong kế hoạch. Cho phép bắt đầu công việc triển khai theo dependency R12, ưu tiên sửa runner R01; phần còn chờ quyết định nghiệp vụ không ngăn làm phần độc lập. Điều này không phải xác nhận đủ điều kiện deploy.

## R01 — Bắt buộc: cô lập test và chặn deploy đúng commit

**Mục liên quan:** 0.1. Bỏ SQLite để test đọc môi trường mặc định có thể làm `RefreshDatabase` tác động DB ứng dụng. `needs` không liên kết job ở hai workflow khác nhau.

- Guard phải chạy **trước** mọi migration/refresh: môi trường testing, connection/host/database test được xác nhận, account chỉ có quyền DB test. Không dựa vào mỗi hậu tố `_test`; thiếu cấu hình thì dừng. Không sao chép `.env` thật vào container test.
- Xóa config cache trong môi trường test cô lập; chặn `DB_URL`, socket và connection phụ kế thừa. Cấp APP_KEY test, mail/cache/session/queue test; build frontend hoặc cấu hình Vite cho test phù hợp. Không để test gửi email/webhook thật.
- Deploy chỉ chạy sau kết quả xanh của **đúng SHA** sẽ deploy. Branch protection không thay thế dependency của workflow deploy. Dùng version PHP/extensions phù hợp runtime/lockfile.
- Lưu command, image/version, SHA, số tests/assertions/failure/skip và log đã che secret. Test dừng vì migration không phải kết quả full suite.
- Fix invitation phải sửa cả return type `show(): View`, vì `response()->view` trả Response. Test nhánh hợp lệ 200 và từng trường hợp 410.

**Nghiệm thu:** cố tình bỏ DB test config thì runner dừng trước thao tác ghi; failure test chặn deploy cùng SHA; fresh install và upgrade fixture MySQL đều qua migration. 10 baseline failures được giải thích và sửa theo contract, không bỏ assertion để đạt xanh.

## R02 — Bắt buộc: quyền nghiệp vụ và tenant, không chỉ middleware API

**Bằng chứng:** `app/Http/Controllers/Controller.php` không có `AuthorizesRequests`; `app/Traits/HasOwnerScope.php` bỏ scope khi không user/ApiClient và khi user không có current owner. F02 trong audit chưa được kế hoạch xử lý đủ.

- Dùng `Gate::authorize`, hoặc bổ sung trait có chủ đích và test. Scope chỉ lọc dữ liệu, không thay thế quyền thực hiện hành động.
- Kiểm membership **còn active** của current owner/organization mỗi thao tác nhạy cảm. Context cũ sau revoke phải bị từ chối. Super_admin cần nhánh rõ ràng trước bộ lọc tenant thông thường.
- Phân biệt User, ApiClient, guest discovery và system job. ApiClient được đọc inventory theo contract không có nghĩa được CRUD owner/site/screen; guest/job không được xử lý bằng gọi method User trên null. System job mang tenant context cụ thể; chỗ bỏ scope phải có giới hạn/policy tương ứng.
- Bổ sung quyền buyer submit/payment/creative/team; owner product CRUD/approve/reject/payment confirmation. Áp dụng cho web controllers, Filament single/bulk actions và service entry points, không chỉ 3 API resource. Buyer viewer không submit/pay; owner read_only không sửa sản phẩm/duyệt booking. Lập ma trận từ `OrganizationUser`, `OwnerUser`, `TenantPermission` và quyết định rõ permission còn thiếu.
- Phân biệt quản trị **Owner** với quản trị inventory của Owner: tạo owner, đổi status/revenue share/tài chính/xóa owner không tự động được cấp cho mọi thành viên có `manage_inventory`. Bảo vệ `stats`, context switch và dữ liệu nhạy cảm trong response tương ứng.
- DTO/resource whitelist cả Owner, Site, Screen và relation lồng nhau. Không trả device credential; public floor CPM/giá nội bộ và badge chưa có chứng cứ phải xử lý trước mở public, không chỉ sửa OwnerResource.

**Nghiệm thu:** test chéo tenant A/B cho read/write; membership bị revoke; viewer/read_only; token inventory-only; ApiClient; super_admin có current owner; guest discovery; job đúng context. Route quản trị bị từ chối trả 403/404 nhất quán; public API chỉ trả whitelist. Hidden button không được coi là enforcement.

## R03 — Bắt buộc: công thức giá là quyết định nghiệp vụ

**Mục liên quan:** 3, 6. Probe 181 ngày → 7 kỳ chứng minh hành vi code hiện tại, không xác nhận tháng phải bằng 30 ngày. Forecast impressions không mặc nhiên là commitment CPM.

- Chốt tháng lịch/kỳ cố định, ngày đầu/cuối, timezone, quantity tính phí, giá theo SOV hay giá cố định, mô hình CPM và giới hạn delivery tương ứng. `screen_count_override` không tự động chứng minh số thiết bị billable. Đơn vị chưa rõ phải chặn báo giá thay vì suy đoán.
- Dùng ngày immutable/copy để calculator không làm biến đổi input; validate khoảng ngày trước tính; test tháng nhuận, biên ngày và update một phần request.
- Quantity client chỉ là lựa chọn có kiểm chứng theo contract. Khi reprice, hiển thị tổng mới và lưu acceptance/version; không tự nâng tiền rồi tạo booking. Update ngày phải tính lại cả product và screen item theo đúng mô hình.
- Snapshot phải đi tới **booking line**, gồm currency, thuế/phí, quantity/unit, đơn giá, điều khoản, source/version, composition sản phẩm và allocation. Không lấy cost cũ nhưng rate mới. Cart cũ thiếu snapshot cần reprice và accept, không tự hợp thức hóa.
- Kiểm snapshot và eligibility trong transaction tạo booking; khóa/version nguồn rate/composition có thể đổi đồng thời. Copy snapshot đã chấp nhận, không đọc rate live lần nữa để ghi lịch sử.

**Nghiệm thu:** giá/quantity/capacity nhất quán; tamper input không giảm tiền giữ quyền cũ; thay rate/composition khiến acceptance cũ mất hiệu lực; booking lịch sử bất biến. Test 181 ngày chỉ có một expected value theo chính sách đã chốt.

## R04 — Bắt buộc: reserve tại submit, tính cả request hiện tại và có TTL

**Mục liên quan:** 4. Nếu draft không chiếm chỗ thì khóa ở createFromCart không ngăn hai draft cùng **submit**. Snippet cộng từng item riêng cũng bỏ sót nhiều line trùng màn hình trong chính một request.

- Quy định transition nào reserve/confirm/release. Tại submit, approval, đổi lịch/SOV, cancel và expire: cùng protocol transaction, khóa campaign/cart nếu cần rồi screen/resource theo thứ tự cố định, đọc capacity mới nhất sau khóa, kiểm và ghi trước commit. Không chỉ khóa approval/create.
- Expand toàn bộ selected screens của gói trước khóa; cộng nhu cầu của **tất cả line ứng viên theo resource và ngày/khung giờ**. Không kiểm mỗi item độc lập với DB.
- Khi recheck approval, loại trừ đúng reservation của line đang chuyển trạng thái rồi cộng lại trạng thái sau transition; không cộng self hai lần hoặc loại cả campaign làm mất các line anh em. Không chỉ lọc parent campaign status: trạng thái parent active không được làm pending owner khác giữ chỗ vô hạn.
- Pending reserve phải có expires_at và trạng thái release; truy vấn bỏ hold đã hết hạn theo thời gian ngay cả khi scheduler chưa chạy. Cancel/reject/expire idempotent, release capacity đúng một lần. Approve sau expiry phải kiểm lại; payment đến muộn không tự khôi phục quyền giữ chỗ.
- Giữ chỗ chi tiết có thể chia đợt, nhưng TTL tối thiểu không được hoãn khi mở giao dịch. Không xóa cứng draft + file chỉ để nhả chỗ; ưu tiên expire/archive, retention riêng đã được quyết định.
- Nếu đang hỗ trợ daypart phải khóa/tính đúng các khoảng thực bán; nếu chỉ full-day, giới hạn contract rõ. Lịch công khai dùng cùng nguồn reservation, trả số theo ngày; peak là số tóm tắt, không thay mọi ngày bằng cùng một số.
- Notification/audit phát sinh sau commit hoặc qua outbox; lỗi gửi mail không để UI báo submit thất bại khi trạng thái đã đổi mà retry lại reserve.

**Nghiệm thu MySQL với hai connection/process:** hai draft cùng submit 100% chỉ một thành công; nhiều line trong cùng request không vượt 100%; approval không tự chặn vì self; pending hết hạn nhả ngay; expire vs approve race chỉ một kết quả; cùng cart chuyển đổi hai lần chỉ tạo một campaign. Không dùng transaction fixture che mất cạnh tranh giữa hai connection.

## R05 — Bắt buộc: sản phẩm, số tiền phân bổ và chuyển đổi giỏ

**Mục liên quan:** 5, 6. N dòng booking là hướng đúng; dùng fallback composition live hoặc chia đều tiền cho nhiều owner chưa có contract là không đủ.

- Validate buy_mode thuộc cấu hình sản phẩm, danh sách unique không rỗng, min/max, owner/eligibility từng screen; package là composition đã snapshot, individual là selection đã xác nhận. Không fallback từ selection null sang toàn bộ sản phẩm hiện tại với cart cũ.
- Chốt quan hệ Screen với thiết bị vật lý trước nghiệm thu số lượng; N booking line bằng N sellable screen hiện tại chưa chứng minh đã đặt N thiết bị.
- Nếu package chỉ cho một owner, enforce invariant. Nếu nhiều owner, cần allocation theo giá/weight/thoả thuận đã chốt, không mặc định chia đều làm căn cứ xác nhận thanh toán. Snapshot allocation; tổng integer VND bằng tổng gói, thứ tự nhận phần dư ổn định; reject n=0 trước chia.
- Update cart product phải giữ mô hình giá gói/selection, không rơi về giá firstScreen. Persist product/source cart item/group để trace và xử lý partial reject. Chốt từ chối một phần có làm reprice hay vô hiệu package không.
- Khóa cart và trạng thái chuyển đổi, ghi campaign + đủ lines + snapshot + converted atomically; unique/idempotency chống double-click. Eligibility kiểm cả submit, trước reservation, theo state transition đã chốt.

**Nghiệm thu:** chọn B chỉ sinh B; gói thay composition cần xác nhận lại; duplicate/foreign ID bị chặn; retry không nhân campaign; lỗi màn hình thứ N rollback toàn bộ; tổng allocation không đổi, mỗi owner nhận đúng phần theo contract.

## R06 — Bắt buộc: payment replay, xác nhận và activation

**Mục liên quan:** 7. Lookup idempotency toàn cục trước authorize có thể trả nhầm payment; select-then-insert chưa chống race. `pending` phải tách khỏi tiền đã xác nhận.

- Key gắn operation/organization/campaign/owner, canonical payload hash và kết quả; authorize trước lookup. Unique DB bảo vệ concurrent insert; cùng key khác payload trả 409. Key tồn tại tenant khác không trả payment đó.
- Khóa payment/campaign/allocation trong confirm; confirm explicit **payment ID**, owner, amount, currency và bằng chứng/actor, không chọn `latest pending`. State transition chỉ một lần, confirmation retry không cấp quyền lần hai. Trang success cũng dùng đúng payment, không lấy latest campaign.
- Tiền xác nhận chỉ phân bổ owner và line đã duyệt tương ứng. Khoản chung thiếu owner cần reconciliation, không tự chia đoán. Thuế/phí dựa trên snapshot đã chốt; không tự đặt biên độ 1%. Overpayment thực nhận được lưu phần chưa phân bổ/hoàn tiền.
- Activation hội đủ payment đúng owner, creative gate, reservation còn hiệu lực và thời điểm lịch. Nếu dùng active cho ready-before-start phải định nghĩa rõ và player vẫn chặn phát sớm; không biến thanh toán thành quyền phát ngoài lịch.
- Re-evaluate khi **payment, creative approval/assignment và lịch bắt đầu** thay đổi. Test cả pay→approve creative và approve creative→pay. Campaign active một phần hay toàn bộ là quyết định còn chờ.
- Sequence mới phải khởi tạo an toàn khi chưa có row, dùng unique + transaction/retry; lock row không tồn tại đơn thuần chưa đủ. Áp dụng cho campaign code max+1 nếu cùng luồng cũng gặp race; số tài liệu payment không được tự gọi là hóa đơn thuế hợp lệ.

**Nghiệm thu:** A trả đủ không mở B; payload mismatch/cross-tenant replay bị chặn; hai create/confirm đồng thời không trùng; pending không giảm confirmed remaining; receipt dư không mất; sequence đầu kỳ concurrent vẫn unique. Test activation phải có fixture creative hợp lệ và lịch/reservation đúng.

## R07 — Bắt buộc: readiness creative và quyền duyệt thực tế

**Mục liên quan:** 9. `approved()->exists()` chỉ chứng minh có một creative được duyệt, chưa chứng minh playlist hợp lệ.

- Assignment kiểm campaign/line/owner, actor permission, start/end/weight và unique pivot đúng schema. Replace/unassign có transaction và semantics rõ; default gán tất cả cần kiểm specs từng line. Không phát creative pending/rejected chỉ vì có creative khác approved.
- Readiness gồm version/file bất biến, metadata đã kiểm và tương thích screen specs theo chính sách. Chưa có ffprobe không được đánh dấu ready; waiver nếu có cần quyền và lý do, không ngầm bỏ gate.
- MIME/container/codec/duration/dimensions phải lấy từ nội dung file bằng xử lý có timeout, size limit và cách gọi process an toàn. Lưu private; download có quyền hoặc URL ngắn hạn phù hợp, không public upload vô điều kiện.
- Approval/reject single/bulk qua service state machine, reason/actor/time được ghi. Cấm thay file đã duyệt qua mọi đường. Khi thu hồi/gỡ assignment đang chạy, playlist/activation phải được đánh giá lại theo chính sách.
- Gọi activation khi readiness đổi như R06; không chỉ kiểm lúc trả tiền. Full creative versioning vẫn thuộc P0.7 của PLAN.md dù P0-H dùng file record bất biến trước.

**Nghiệm thu:** creative approved nhưng sai campaign/spec/ngoài lịch không được phát; unknown metadata bị chặn; pending không lọt playlist; buyer không tự duyệt; bulk action không bypass; pay trước approve cuối cùng vẫn được kích hoạt đúng điều kiện.

## R08 — Bắt buộc: migration PoP không phá legacy/partition

**Bằng chứng:** `2025_01_01_000011_create_impression_logs_table.php` có campaign/creative bigint, PK `(id, played_at)` và partition theo played_at. Bản trước review copy chỉ campaign rồi đề xuất drop cả hai cột; còn `UNIQUE(event_id)` không hợp lệ trên bảng partition hiện tại.

Theo [MySQL 8.0: Partitioning Keys, Primary Keys, and Unique Keys](https://dev.mysql.com/doc/mysql-reslimits-excerpt/8.0/en/partitioning-limitations-partitioning-keys-unique-keys.html), mọi unique key phải chứa các cột dùng trong biểu thức partition. Vì vậy dùng receipt không partition để enforce unique `(device_identity_id, event_id)`; raw giữ PK/partition hiện hữu. Không dùng `(event_id, played_at)` làm dedup vì thay timestamp sẽ qua được.

- Expand thêm linkage nội bộ ULID, giữ cột legacy và endpoint compatibility theo inventory tích hợp thực tế. Không suy ID legacy từ ID nội bộ; dữ liệu chưa map thì quarantine/unmapped.
- Receipt và raw ghi atomic, raw bất biến; receipt lưu payload hash và raw id + played_at. Không dùng updateOrCreate để sửa sự kiện đã nhận. Tránh model update/delete theo riêng id với PK ghép.
- Dual-read/report mapping phải được chuyển có kiểm chứng. Backfill theo batch/checkpoint, không overwrite dữ liệu đã map, kiểm cả campaign lẫn creative. Nếu dùng cột legacy copy riêng thì copy **cả hai** trước switch, không drop ở đợt expand.
- Migration `hasColumn` không tự đảm bảo đúng type/index hoặc an toàn rerun; kiểm schema và fixture old/new, table partition thực. Không thêm foreign key vào partitioned raw khi engine không hỗ trợ; kiểm linkage ở transaction/service và reconciliation.

**Nghiệm thu:** upgrade MySQL có fixture legacy giữ nguyên row count/giá trị, fresh install xanh, rerun backfill không đổi kết quả; rollback ứng dụng vẫn đọc legacy. Không `migrate:rollback` phá raw/receipt mới; chuẩn bị backup/restore và chiến lược rollback app đọc schema mở rộng.

## R09 — Bắt buộc: identity thiết bị, linkage và rollup PoP

**Mục liên quan:** 8. Cùng screen/time có thể có nhiều booking SOV; chọn dòng đầu tiên sẽ gán sai bằng chứng và báo cáo.

- Credential gắn thiết bị/screen, cấp/rotate bằng permission riêng, không xuất trong JSON/log. Ưu tiên lưu hash của token mới và chỉ hiển thị plaintext lúc cấp; có migration/rollout cho thiết bị hiện hữu. Không giữ nhánh unauthenticated âm thầm để tương thích.
- Payload có event ID ổn định qua retry, booking/deployment assignment ID và played_at; validate timestamp, duration, size và cửa sổ offline đã chốt. Kiểm đồng nhất device/screen/owner/campaign/creative/schedule tại thời điểm phát, không chỉ status hiện tại khi proof đến muộn.
- Retry cùng key/cùng payload trả kết quả cũ; khác payload 409; một thiết bị không được sửa proof thiết bị khác. Unknown/unmapped phải tách quarantine, không vào actual_impressions hoặc finance. Không fetch proof_url tuỳ ý từ server.
- Rollup idempotent: recompute bucket có khóa/version hoặc ledger xử lý một lần, có watermark/lateness. Test chạy lại, crash/retry và hai job concurrent. Dùng multiplier có hiệu lực tại played_at đã snapshot, không multiplier hiện tại cho sự kiện cũ.
- Actual impressions không tự động là tiền phải trả; phân biệt proof nhận được, proof được xác thực, số liệu ước tính và dữ liệu billable theo contract. Reader/report dùng cột linkage mới.

**Nghiệm thu:** hai campaign chung screen không lẫn proof; token screen khác bị chặn; malformed ULID/timestamp bị chặn; hai request cùng event chỉ một raw; event sửa timestamp cùng key trả conflict; sự kiện offline hợp lệ được tính một lần dù campaign hiện đã kết thúc; quarantine không tăng báo cáo.

## R10 — Bắt buộc: SSRF transport, rate limit và retry worker

**Mục liên quan:** 10. `gethostbyname()` chỉ kiểm một IPv4 rồi HTTP client resolve lần nữa chưa chặn rebinding hoặc IPv6.

- Chuẩn hóa URL; giới hạn scheme/port; cấm credentials; kiểm mọi A/AAAA, IPv4-mapped IPv6, loopback/link-local/private/reserved và trường hợp không resolve. Pin IP công khai đã kiểm vào kết nối, giữ hostname cho TLS/SNI, không follow redirects. Resolve/validate lại mỗi delivery attempt bằng cùng transport policy; có thể kết hợp egress proxy/firewall. Rule validator đơn lẻ không đủ.
- Rate limit gồm IP trước auth và identity đáng tin sau auth; không chỉ theo screen_uuid client tự đổi. Không để API limiter tổng 120/phút vô tình chặn player trước limiter 600/phút; tách route/budget, dùng shared store và cấu hình trusted proxies phù hợp.
- Event ID, timestamp/payload và signature nhất quán qua retry; sinh event trước dispatch, không sinh ID mới mỗi attempt. Giữ signing hiện hữu. Job dispatch sau commit; worker thực sự nghe queue webhooks.
- Thử lại transient/network/429/5xx theo policy; xử lý 4xx vĩnh viễn rõ ràng. Chốt vô hiệu subscription theo lỗi delivery nào, tránh một job cũ thất bại tắt subscription đã phục hồi. Kiểm `timeout < retry_after`; import job 1800 giây cần queue/worker riêng hoặc retry_after phù hợp, không dùng mặc định 90 giây gây xử lý trùng.

**Nghiệm thu:** URL IPv6/private/redirect/DNS đổi bị chặn ở transport; payload/signature/event ID không đổi khi retry; chạy worker thật với receiver test xác nhận attempts/backoff. Queue::fake chỉ test dispatch. Test cả api+player limiter phối hợp và hai worker không chạy trùng import.

## R11 — Gate phát hành: bằng chứng, rollout và rollback

- Chụp baseline source/schema, migration plan, dữ liệu cần reconcile và integration contract trước thay đổi. Với khóa deploy tracked: không in key, coi là có nguy cơ lộ; kiểm phạm vi sử dụng bằng metadata. Rotation là việc ops được giao riêng; review không tự rotate/deploy/rewrite git history. Xóa khỏi working tree không thu hồi quyền của key cũ.
- Từng hạng mục có PR/commit, acceptance evidence và migration notes. Lưu actor/tenant/entity/action/before-after hoặc snapshot transition cho approval, payment, assignment, cancel/expire; không log secret/file nội dung nhạy cảm.
- Chạy full suite trên MySQL thật sau tích hợp, smoke API/web/Filament và luồng cart→submit→approve→creative→payment→proof→report, gồm cả failure/retry. Test riêng không thay E2E hoặc concurrency.
- Thử pilot tenant/device; rollout credential và API contract có lịch chuyển đổi. Rollback ứng dụng phải tương thích schema đã expand, không khôi phục quyền fail-open hoặc xóa giao dịch mới để rollback.
- Giữ phân loại P0-H và P0.1–P0.10 rõ ràng. RFQ, quote version, media plan, hold, creative version, finance basics chưa làm không được báo đã hoàn thành P0 của sitemap.

**Nghiệm thu:** checklist có link log đúng SHA, quyết định nghiệp vụ phụ thuộc đã ghi, migration dry-run/restore được kiểm, E2E + concurrency xanh; không còn release blocker bị che dưới chữ “việc nhỏ”.

## R12 — Các bổ sung còn lại và thứ tự tích hợp

- **11.1:** dùng số liệu/badge có nguồn, gỡ claim chưa có chứng cứ; public floor CPM cần xử lý tại resource/template, không chỉ owner financial fields. Không suy claim pháp lý từ review code.
- **11.2:** thay fresh(['status']) nhưng test import >50 rows/cancel/resume/partial failure để xác nhận checkpoint và không nhân dữ liệu.
- **11.3:** backfill role chỉ cho user có org membership hợp lệ tương ứng; không gán buyer đại trà. Seeder role tồn tại; command dry-run và idempotent.
- **11.4:** xác nhận disk private có cấu hình, hoặc dùng local private root; đọc/xóa đúng disk. Xử lý file public cũ có manifest/dry-run, không chỉ đổi upload mới; link tải phải kiểm tenant/quyền. Kết hợp queue timeout tại R10.
- **11.5:** chưa chọn sites.network_id làm nguồn chuẩn trước khi kiểm một site có nhiều network không. Dry-run báo orphan/conflict/multi-network; không overwrite last-wins. Quyết định contract cần ở đầu 0.1, migration lớn có thể triển khai riêng sau.
- **11.6:** viewport/filter và clustering/pagination phải rõ; cap 2.000 không được âm thầm làm mất điểm rồi hiển thị tổng sai.
- **11.7:** thêm navigation sau khi quyền/visibility product nhất quán, kiểm cả trạng thái empty.

Thứ tự tích hợp đã hiệu chỉnh:

1. R01: môi trường test, evidence baseline; chốt network contract. Ops xử lý key theo phạm vi riêng.
2. Mục 2 + R02: ma trận quyền, context và response contract; mục 10 + R10: transport/rate/worker nền.
3. Mục 3/5/6 + R03/R05: pricing, eligibility, composition/allocation, conversion atomic.
4. Mục 4 + R04: reservation/submit locking/TTL với đầy đủ screens đã expand.
5. Mục 9 + R07: assignment/spec/review gate; mục 7 + R06 nối payment và activation hai chiều.
6. Mục 8 + R08/R09: expand PoP/credential/linkage/dedup/rollup, dùng booking + assignment đã ổn định.
7. R11: upgrade fixture, E2E/concurrency, pilot và rollback. Các sửa nhỏ độc lập triển khai sớm được; không hoãn quyền, private upload, public data leak tới cuối.

Các công việc độc lập vẫn có thể được triển khai đồng thời theo tổ chức của đội, nhưng thay đổi dùng chung phải theo contract và dependency trên. Chưa có quyết định nghiệp vụ thì làm phần độc lập, không tự lấy ví dụ 30 ngày, 1%, 5% hay TTL đề xuất làm yêu cầu đã được duyệt.
