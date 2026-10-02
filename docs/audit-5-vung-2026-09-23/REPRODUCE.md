# Tái hiện kết quả audit

Chạy từ root `D:\Code-Project\oohx-matrix\oohx-dash`. Image `captain-test-php:8.4` đã có sẵn trên máy khi audit; không pull/cài dependencies. Docker cần quyền truy cập daemon. Không chạy migration/test vào DB nghiệp vụ.

```powershell
docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges --tmpfs /tmp:rw,exec,size=512m --mount type=bind,source=D:/Code-Project/oohx-matrix/oohx-dash,target=/source,readonly --mount type=bind,source=D:/Code-Project/oohx-matrix/oohx-dash/docs/audit-5-vung-2026-09-23/evidence,target=/results --entrypoint sh captain-test-php:8.4 /source/docs/audit-5-vung-2026-09-23/run-isolated.sh
```

Harness copy source/config cần thiết vào tmpfs, không copy `.env` hoặc application storage; vendor gắn chỉ đọc. `APP_BASE_PATH` đặt về bản tạm, config DB ép SQLite `:memory:`, mail/cache/session dùng array, mạng container tắt. Route inventory không kết nối DB nghiệp vụ. PHPUnit chạy bộ test có sẵn với `--stop-on-error`; không đổi test/migration để làm xanh.

Kết quả chuẩn của snapshot audit: 207 route, PHPUnit dừng ở test thứ hai do migration `SHOW INDEX FROM screens` không hỗ trợ SQLite. Đây là blocked baseline, không phải chứng nhận pass hay thống kê toàn bộ suite.

Probe độc lập (dữ liệu giả, không dựng schema đầy đủ):

```powershell
docker run --rm --network none --read-only --cap-drop ALL --security-opt no-new-privileges --tmpfs /tmp:rw,exec,size=512m --mount type=bind,source=D:/Code-Project/oohx-matrix/oohx-dash,target=/source,readonly --mount type=bind,source=D:/Code-Project/oohx-matrix/oohx-dash/docs/audit-5-vung-2026-09-23/evidence,target=/results --entrypoint sh captain-test-php:8.4 /source/docs/audit-5-vung-2026-09-23/run-probes.sh
```

`defect_reproduced=true` nghĩa là probe chứng minh hành vi sai đang tồn tại; không có nghĩa capability đạt acceptance. Probe pricing gọi CartService thật trên Screen/ScreenInventory in-memory; capacity gọi AvailabilityService query trên fixture booking_lines tối thiểu; PoP gọi validation của PlayerController thật trước screen lookup. Không dùng chúng để kết luận HTTP middleware, production schema, race/concurrency hoặc external delivery đã được kiểm thử.

## Artifact index

| File | Nội dung |
|---|---|
| `README.md` | Executive summary, 5 vùng/gap matrix, transaction traces, current model, open questions |
| `FINDINGS.md` | 18 findings có source/symbol/trigger/impact/root cause/recommendation/acceptance |
| `PLAN.md` | Target model, 10 P0 epics/story/tasks, P1–P3, dependency graph, migration/rollback/gates |
| `SITEMAP.md` | Toàn bộ registered routes theo vùng, domain, handler/source |
| `MODULES.md` | Controller/service/model/job/policy/test/Filament/migration inventory |
| `evidence/routes.json` | Raw Laravel route:list JSON |
| `evidence/routes-classified.json` | Route list có zone và capability status |
| `evidence/source-inventory.json` | Paths, SHA-256, classes/symbols, literal table creates |
| `evidence/test-inventory.json` | 226 phương thức test từ source; không phải 226 cases đã chạy |
| `evidence/inventory-summary.json` | File/model/service/controller/migration counts |
| `evidence/phpunit.txt`, `phpunit.xml`, `phpunit-exit-code.txt` | Baseline stdout/JUnit/exit status |
| `evidence/php-version.txt` | PHP runtime version |
| `evidence/service-probes.json` | Kết quả probe pricing/capacity/PoP |
| `evidence/final-source-check.json` | Kiểm hash source không đổi và local links |

Repository hiện ignore `/docs/*`; các artifact có trên ổ đĩa nhưng chưa được Git theo dõi. Audit không tự thay `.gitignore`, stage/commit tài liệu hoặc source. `.claude/settings.local.json` đã dirty trước audit và được giữ nguyên.
