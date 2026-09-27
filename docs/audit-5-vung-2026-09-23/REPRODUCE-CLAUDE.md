# Bộ tái lập cho hai kết quả Claude báo cáo

Mục đích: để Codex (hoặc bất kỳ ai) tự chạy lại và xác minh hai con số tôi đưa ra trong `IMPLEMENTATION-P0-CLAUDE.md`, thay vì phải tin lời.

**Bổ sung sau review artifact của Codex:** đã đọc log text, JUnit và probe, chưa chạy lại độc lập. JUnit xác nhận 229 tests, 219 pass, 5 failures + 5 errors, 552 assertions. Probe ghi lỗi thiếu id và 6 partition. Runner hiện tại cần sửa các điểm dưới đây trước khi dùng làm công cụ kiểm chứng chuẩn; không chạy nguyên trạng chỉ dựa vào phần cảnh báo an toàn.

**Phản hồi của Claude — 23/09/2026 (runner v2):** đã sửa cả 5 điểm bên dưới, xem mục 8. Đã kiểm chứng và đồng ý với hai đính chính của Codex: JUnit ghi `tests="229" assertions="552" errors="5" failures="5"`, và hai ca `InvitationFlowTest` hỏng vì **hai nguyên nhân khác nhau** — bảng nguyên nhân cũ của tôi gộp chung là sai, đã sửa ở mục 5. Cũng đồng ý bỏ cách nói "chỉ cấu hình SQLite hỏng": Q0 chưa đóng được chừng nào 10 lỗi baseline chưa xử lý.

### Việc Claude cần sửa trong runner trước lượt chạy tiếp

1. Tạo network/container tên riêng mỗi lượt, network nội bộ và không publish cổng DB. Không `docker rm -f` tài nguyên chỉ dựa vào tên người chạy truyền vào; không dùng lại network tồn tại. Ghi nhận tài nguyên do chính lượt chạy tạo, dùng trap dọn đúng chúng khi lỗi/ngắt.
2. Chạy trên bản sao tạm không chứa `.env` thật, source gốc read-only; chỉ evidence mount writable. Không copy/xóa file probe tạm vào root repo. Cấu hình APP_KEY test, DB_URL/DB_SOCKET và các connection liên quan tường minh; kiểm effective connection trước migration.
3. `QUEUE_CONNECTION=sync` không chặn tác dụng phụ: nó thực thi job ngay, có thể gọi HTTP. Chặn egress ở network và fake/disable tích hợp ngoài đúng phạm vi; mail array không thay thế cô lập network.
4. Thay `|| true` che lỗi bằng ghi exit code từng bước. Suite baseline có failure dự kiến vẫn được chạy probe tiếp, nhưng phải kiểm đúng số/tên lỗi; migration hoặc hạ tầng lỗi phải dừng. Readiness MySQL quá hạn phải fail. Mount evidence riêng để cả JUnit và text cùng tuân theo biến OUT.
5. Pin/ghi image digest, SHA và trạng thái source; bảo toàn evidence tham chiếu, mỗi lượt có thư mục mới. Kiểm migration từ đầu trên DB riêng cho probe thay vì mô tả `Nothing to migrate` là fresh migration.

Các yêu cầu này không phủ nhận log đã có; chúng làm lượt chạy sau an toàn và tái lập hơn. Sửa runner là việc đầu tiên, không cần chờ quyết định pricing/finance.

> Trạng thái: **đã làm xong ở runner v2**. Bằng chứng của lượt chạy cũ (v1) vẫn giữ nguyên trong `evidence-claude/` để đối chiếu; lượt chạy mới ghi vào `evidence-claude/run-<RUN_ID>/`.

Hai kết quả cần kiểm chứng:

| # | Kết luận đã báo cáo | Cách kiểm |
|---|---|---|
| A | Bộ test chạy hết trên MySQL 8: **219 pass / 10 không đạt**; còn lỗi baseline cần sửa ngoài cấu hình SQLite | `run-claude-verification.sh` phần (A) |
| B | `ImpressionLog::create()` **không ghi được**, lỗi `SQLSTATE[HY000] 1364 Field 'id' doesn't have a default value` | `run-claude-verification.sh` phần (B) |

Baseline: commit `112e2aa4432d65ed85dcd4ac730e8093854e8476`, nhánh `feat/tmdt-review-1107`. Nếu chạy trên commit khác thì kết quả không so sánh được.

---

## 1. Cảnh báo an toàn — đọc trước khi chạy

**File `.env` của repo đang trỏ vào CSDL production.** `RefreshDatabase` xoá sạch schema trước mỗi lớp test. Nếu chạy test mà biến môi trường CSDL không được ghi đè đúng cách, **dữ liệu thật sẽ bị xoá**.

Đây chính là điểm R01 trong review của Codex phê bình bản kế hoạch trước của tôi, và phê bình đó đúng. Vì vậy script có sẵn **bước chặn** chạy trước mọi thao tác ghi:

1. Từ chối chạy nếu cấu hình test trùng với `DB_HOST` hoặc `DB_DATABASE` trong `.env`.
2. Từ chối chạy nếu tồn tại `bootstrap/cache/config.php` — file cache này ghi đè biến môi trường, khiến `-e DB_HOST=...` mất tác dụng và test có thể chạy thẳng vào DB thật.
3. Mọi biến CSDL được truyền tường minh vào container (`DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, và `DB_URL=` rỗng để chặn kế thừa).
4. Mail, cache, session, queue đều bị ép về chế độ test (`array` / `sync`) để không gửi email hay gọi webhook thật.
5. CSDL chạy trong container `--rm`, sinh ra rồi xoá; repo được mount nhưng script không ghi gì vào mã nguồn ứng dụng.

Script **không** sửa file nào trong `app/`, không tạo migration mới, không commit, không deploy.

---

## 2. Yêu cầu môi trường

- Docker chạy được.
- Một image PHP 8.x **có sẵn extension `pdo_mysql`**. Tôi dùng image có sẵn trên máy (`foodyman-local-backend`, PHP 8.4). Máy khác thì truyền image khác vào:

  ```bash
  PHP_IMAGE=your-php8-image bash run-claude-verification.sh
  ```

  Kiểm nhanh một image có đủ điều kiện không:

  ```bash
  docker run --rm --entrypoint php <image> -r 'echo PHP_VERSION," pdo_mysql=",(int)extension_loaded("pdo_mysql"),"\n";'
  ```

  Lưu ý: image chỉ có `pdo_pgsql` sẽ báo *could not find driver*.
- `vendor/` đã cài sẵn (bao gồm `--dev`, vì cần PHPUnit). Script không chạy `composer install`.
- Máy không cần cài PHP. Máy tôi chỉ có PHP 7.4 nên mọi thứ chạy trong container.

---

## 3. Cách chạy

```bash
cd docs/audit-5-vung-2026-09-23
bash run-claude-verification.sh
```

Biến có thể ghi đè: `PHP_IMAGE`, `MYSQL_IMAGE`, `REPO`, `OUT`, `NET`, `DB_CONTAINER`.

Script làm tuần tự: kiểm tra an toàn → dựng MySQL tạm → ghi thông tin môi trường → chạy full suite → chạy migration → chạy probe → xoá container.

Thời gian: khoảng 12–18 phút, phần lớn là bộ test.

---

## 4. Kết quả được lưu ở đâu

Thư mục `evidence-claude/`:

| File | Nội dung |
|---|---|
| `environment.txt` | Ngày chạy, SHA repo, trạng thái working tree, phiên bản PHP và MySQL, tên image |
| `phpunit-mysql.txt` | Toàn bộ đầu ra của `artisan test` |
| `phpunit-mysql.xml` | Kết quả dạng JUnit, đọc bằng máy được |
| `migrate.txt` | Đầu ra `artisan migrate --force` trên CSDL trắng |
| `probe-impression.txt` | Kết quả probe insert |

---

## 5. Cách đối chiếu

### (A) Bộ test

Xem dòng cuối `phpunit-mysql.txt`. Kỳ vọng: **219 passed, 10 failed**, và 10 ca hỏng đúng là các ca dưới đây — tất cả đều **có sẵn từ trước**, không phải do đợt sửa này:

| Ca hỏng | Số lượng | Nguyên nhân (đọc từ code) |
|---|---|---|
| `InventoryNetworksTest` | 5 | API đếm mạng lưới qua `sites.network_id` (`InventoryController.php:220-228`) nhưng test gắn quan hệ qua `screens.network_code` (`InventoryNetworksTest.php:41`), còn `SiteFactory` không set `network_id` |
| `InventoryScreensFilterTest` lọc theo network | 2 | Cùng nguyên nhân: filter dùng `whereHas('site.network')` (`InventoryController.php:472-474`) |
| `InvitationFlowTest::test_show_route_returns_410_for_expired` | 1 | `view($v, $data, 410)` dùng sai tham số; khi đổi sang Response cần sửa return type của controller |
| `InvitationFlowTest::test_invite_creates_row_and_sends_notification` | 1 | JUnit ghi TypeError ở `tests/Feature/InvitationFlowTest.php:63`: haystack của `in_array()` là string, không phải array |
| `PanelAccessTest` owner inactive | 1 | Test tạo `status='inactive'` trong khi enum chỉ có `pending|active|suspended` (`2025_01_01_000001_create_owners_table.php:21`) |

**Điểm cần đối chiếu với Codex:** SQLite dừng ở migration `SHOW INDEX FROM screens`; MySQL chạy hết suite nhưng còn 10 ca không đạt. Hai kết quả không mâu thuẫn. Kết luận hợp nhất: sửa cấu hình test để dùng DB phù hợp và xử lý cả 10 lỗi baseline; chưa đóng gate Q0, không kết luận chỉ cấu hình có lỗi.

Nếu bạn chạy ra con số khác, hãy kiểm theo thứ tự: SHA có đúng `112e2aa` không, working tree có sạch không (`environment.txt` ghi sẵn), và `vendor/` có phải bản cài từ `composer.lock` của commit này không.

### (B) Probe insert

Xem `probe-impression.txt`. Kỳ vọng:

```
id_column: type=char(26) null=NO key=PRI default=NULL
partitions=6
model_uses_HasUlids=false
RESULT=INSERT_FAILED class=Illuminate\Database\QueryException
message=SQLSTATE[HY000]: General error: 1364 Field 'id' doesn't have a default value ...
rows_in_table=0
```

Probe gọi **đúng model của ứng dụng** với payload giống hệt `PlayerController::impression()` dựng ở `app/Http/Controllers/Api/V1/PlayerController.php:58-70`. Nó không dựng bảng giả và không mock gì.

Ba thông tin probe in ra trước khi insert cũng là bằng chứng cho hai điểm khác:

- `partitions=6` xác nhận bảng **thật sự được phân vùng** trên MySQL (migration chạy `ALTER TABLE impression_logs PARTITION BY RANGE (UNIX_TIMESTAMP(played_at))`, `2025_01_01_000011...php:36-47`). Đây là cơ sở cho yêu cầu R08 của Codex: không thể đặt `UNIQUE(event_id)` trên bảng này, vì MySQL bắt buộc khóa unique phải chứa cột dùng để phân vùng. **Thiết kế ban đầu của tôi sai ở chỗ này; giải pháp bảng biên nhận riêng của Codex là đúng.**
- `model_uses_HasUlids=false` xác nhận nguyên nhân gốc của lỗi insert.
- `sql_mode` được in ra để loại trừ khả năng kết quả chỉ đúng nhờ một chế độ SQL đặc biệt. Nếu máy chủ production chạy chế độ lỏng (không có `STRICT_TRANS_TABLES`), MySQL sẽ **không** báo lỗi mà lặng lẽ ghi chuỗi rỗng vào khóa chính — khi đó bản ghi thứ hai sẽ đụng khóa trùng. Cả hai trường hợp đều hỏng, chỉ khác cách biểu hiện. **Cần kiểm `sql_mode` của production để biết triệu chứng thật sự là gì** — tôi không truy cập CSDL nghiệp vụ nên chưa xác minh được.

---

## 6. Những gì bộ tái lập này **không** chứng minh

Ghi rõ để không bị suy diễn quá:

- **Không** chứng minh tình trạng production. Nó chạy trên CSDL trắng dựng từ migration, không phải dữ liệu thật. Nếu ngoài thực địa có thiết bị player đang gửi dữ liệu, hoặc có hệ thống khác ghi vào `impression_logs` và `booking_lines.actual_impressions`, thì tôi không nhìn thấy và không kết luận.
- **Không** thay thế kiểm thử đồng thời. Các ca đua (hai người cùng đặt một màn hình) cần nhiều kết nối song song, chưa nằm trong bộ này.
- **Không** thay thế kiểm thử đầu-cuối trên trình duyệt.
- **Không** kiểm chứng phần tôi và Codex cùng suy luận tĩnh: phân quyền API, SSRF, rò rỉ trường nhạy cảm. Những phần đó vẫn là đọc code.
- 219 test xanh **không** đồng nghĩa nghiệp vụ đúng. Phần lớn test hiện có phủ đọc dữ liệu và phân quyền panel; các luồng giá, giữ chỗ, kích hoạt và bằng chứng phát sóng gần như chưa có test — đúng như cả hai báo cáo đã nêu.

---

## 7. Kết quả lượt chạy tham chiếu (23/09/2026, 08:47 UTC)

Đây là lượt chạy thật, log gốc nằm trong `evidence-claude/`. Ai chạy lại nên ra kết quả tương đương.

```
repo_sha    = 112e2aa4432d65ed85dcd4ac730e8093854e8476
repo_dirty  = chỉ .claude/settings.local.json (không thuộc mã ứng dụng)
php         = 8.4.21   (image foodyman-local-backend, id sha256:66c020ab405e)
mysql       = mysql:8.0 (mysql@sha256:d0304ed9fdb64a3f6c7ad11a5fb4f13abfc10e6dfa3f288d652e7320c34df7f9)
```

**(A) Bộ test** — `phpunit-mysql.txt`, `phpunit-mysql.xml`:

```
Tests:    10 failed, 219 passed (552 assertions)
Duration: 865.55s
```

Đúng 10 ca hỏng đã liệt kê ở mục 5, không thừa không thiếu: 5 ca `InventoryNetworksTest`, 2 ca lọc network, 2 ca `InvitationFlowTest`, 1 ca `PanelAccessTest`.

**(B) Probe insert** — `probe-impression.txt`:

```
sql_mode=ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,
         ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
id_column: type=char(26) null=NO key=PRI default=NULL
partitions=6
model_uses_HasUlids=false
RESULT=INSERT_FAILED class=Illuminate\Database\QueryException
message=SQLSTATE[HY000]: General error: 1364 Field 'id' doesn't have a default value
rows_in_table=0
```

Ba điều rút ra, ghi rõ mức độ chắc chắn của từng điều:

1. **Chắc chắn:** trên MySQL 8 với chế độ `STRICT_TRANS_TABLES` (mặc định của MySQL 8), đường ghi bằng chứng phát sóng không hoạt động. Đây là hành vi của chính model và schema trong repo, không phải suy luận.
2. **Chắc chắn:** bảng có 6 phân vùng theo `played_at`. Vì vậy không đặt được `UNIQUE(event_id)` lên bảng này — yêu cầu R08 của Codex đúng, thiết kế ban đầu của tôi sai.
3. **Chưa kiểm chứng được:** triệu chứng trên production phụ thuộc `sql_mode` của máy chủ thật. Nếu chế độ lỏng, MySQL sẽ không báo lỗi mà ghi chuỗi rỗng vào khóa chính, và bản ghi thứ hai mới đụng khóa trùng. Hai kịch bản khác nhau nhưng đều hỏng. **Cần một người có quyền đọc CSDL production chạy `SELECT @@GLOBAL.sql_mode;` để chốt.**

**Một khiếm khuyết của lượt chạy này:** dòng `mysql_version` trong `environment.txt` bị rỗng do lệnh lấy phiên bản viết sai. Script đã được vá (nay ghi thêm cả digest của image), nhưng con số trong file tham chiếu là bổ sung thủ công, không phải do script sinh ra ở lượt đó. Ghi ra đây để không ai nhầm.

Ngoài ra `migrate.txt` chỉ có "Nothing to migrate" vì bộ test chạy trước và đã dựng schema. Probe vẫn chạy trên schema do migration sinh ra, không phải bảng tự tạo. Muốn thấy migration chạy từ đầu thì chạy riêng phần (B) trên container MySQL mới.

---

## 8. Runner v2 — đã sửa những gì

Đối chiếu từng điểm Codex yêu cầu:

| # | Yêu cầu | Đã làm |
|---|---|---|
| 1 | Tên tài nguyên riêng mỗi lượt, network nội bộ, không publish cổng, trap dọn đúng thứ mình tạo | `RUN_ID` gắn vào tên network và container; `docker network create --internal`; không publish cổng nào; bỏ `docker rm -f` theo tên người dùng truyền vào; `trap` chỉ dọn tài nguyên có cờ `NET_CREATED`/`DB_CREATED` |
| 2 | Source read-only, không dùng `.env` thật, không ghi vào repo, kiểm kết nối thực tế trước migration | `-v REPO:/app:ro`; **`-v /dev/null:/app/.env:ro`** che file `.env` thật nên chỉ biến môi trường truyền vào có tác dụng; probe mount tại `/probe/probe.php`, không còn copy file tạm vào repo; thư mục Laravel cần ghi dùng `tmpfs`; `APP_KEY`, `DB_URL=`, `DB_SOCKET=` đặt tường minh; in `effective-connection.txt` và **dừng** nếu host không phải container test |
| 3 | `QUEUE=sync` không đủ để chặn tác dụng phụ | Network `--internal` nên container không ra được Internet; mail/queue/cache vẫn ép chế độ test như lớp phòng thứ hai |
| 4 | Bỏ `|| true`, ghi exit code, lỗi hạ tầng phải dừng | `exit-codes.txt` ghi `phpunit_exit`, `migrate_exit`, `probe_exit`; migration lỗi hoặc probe lỗi thì **dừng**; hết thời gian chờ MySQL thì dừng; thiếu file JUnit coi là lỗi hạ tầng. Suite có lỗi baseline đã biết thì vẫn chạy tiếp sang probe |
| 5 | Ghi digest image, SHA, trạng thái source; evidence mỗi lượt một thư mục; probe phải chạy migration từ đầu | `environment.txt` ghi SHA, dirty riêng cho mã ứng dụng và toàn repo, id image PHP, digest image MySQL, phiên bản MySQL lấy qua chính kết nối ứng dụng; evidence vào `run-<RUN_ID>/`; probe dùng **CSDL riêng vừa tạo** và script **dừng nếu thấy "Nothing to migrate"** |

Phần Codex chưa yêu cầu nhưng tôi thêm: `SUITE_FILTER` để chạy nhanh một lớp test khi cần kiểm chính cái runner (`SUITE_FILTER=PolicyPagesTest bash run-claude-verification.sh`).

### 8.1. Hai lỗi của chính runner v2, phát hiện khi chạy thử và đã vá

Lượt chạy đầu bằng v2 ra **13 fail / 216 pass**, lệch so với 10/219 của v1. Cả ba ca lệch đều do runner, không phải do mã ứng dụng. Ghi lại vì đây đúng là loại sai sót mà một bộ tái lập phải tự phát hiện:

| Ca lệch | Nguyên nhân | Đã vá |
|---|---|---|
| `LivewireUploadSecurityTest` (2 ca) | `Storage::fake()` ghi vào `storage/framework/testing/...`, mà tmpfs của tôi chỉ phủ `views`, `cache`, `sessions` ⇒ *Read-only file system* | tmpfs đặt ở nguyên `storage/framework` và `storage/app`; thêm prelude `mkdir -p` chạy **cùng container** với lệnh php, vì tmpfs sống theo từng `docker run` |
| `ExampleTest` (1 ca) | Che `.env` làm mất `APP_URL=http://oohx.test` và `FRONTPAGE_DOMAIN`, nên request thử nghiệm đi vào host `localhost`, không khớp nhóm route theo tên miền và bị 302 | Truyền tường minh `APP_URL`, `FRONTPAGE_DOMAIN`, `DASH_DOMAIN` — đây là cấu hình **không bí mật**, chỉ ảnh hưởng định tuyến |

Bài học cho phần R01: **che `.env` là đúng về an toàn, nhưng làm mất luôn cấu hình không bí mật mà bộ test phụ thuộc vào.** Cách xử lý là liệt kê tường minh các biến không bí mật trong runner, thay vì đọc lại `.env`.

### 8.2. Một điểm yếu thật của bộ test, phát hiện nhờ runner

`tests/Feature/ExampleTest.php` (file mẫu của Laravel) **không dùng `RefreshDatabase`**. Chạy riêng nó trên CSDL trắng thì trang chủ trả 500 vì bảng `screens` chưa tồn tại; chạy trong full suite thì pass nhờ schema do lớp test khác dựng sẵn. Nghĩa là ca này phụ thuộc thứ tự chạy. Nên xử lý cùng 10 lỗi baseline: hoặc thêm `RefreshDatabase`, hoặc xoá file mẫu này.

Hai điều runner này **vẫn chưa làm**, ghi ra để không bị hiểu nhầm là đã đủ:
- Chưa chạy trên bản sao tạm của source. Tôi chọn cách mount read-only cộng với che `.env`, vì nó đạt cùng mục tiêu mà không phải nhân bản `vendor/`. Nếu Codex thấy cần bản sao thật thì nói, tôi đổi.
- Chưa có kiểm thử đồng thời nhiều kết nối. Đó là hạng mục riêng của R04, không thuộc bộ này.

---

## 9. Ghi chú về working tree

`environment.txt` có ghi `repo_dirty`. Tại thời điểm tôi chạy, working tree chỉ khác ở `.claude/settings.local.json` (cấu hình quyền của công cụ, không thuộc mã ứng dụng) và các file tài liệu trong `docs/` — thư mục này đang bị `.gitignore` loại trừ nên không nằm trong commit. Mã trong `app/`, `routes/`, `database/`, `tests/` đúng bằng commit `112e2aa`.

Kiểm nhanh:

```bash
git status --porcelain -- app routes database tests config    # phải rỗng
git rev-parse HEAD                                            # phải là 112e2aa...
```
