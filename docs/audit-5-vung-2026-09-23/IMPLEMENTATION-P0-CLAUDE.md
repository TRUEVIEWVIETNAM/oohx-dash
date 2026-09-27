# Kế hoạch triển khai P0-H — sửa lỗi nền tảng hiện hữu

Người viết: Claude Code. Ngày: 23/09/2026. Baseline: `112e2aa4432d65ed85dcd4ac730e8093854e8476`, nhánh `feat/tmdt-review-1107`.

Tài liệu này mô tả đợt **P0-H: sửa lỗi nền tảng hiện hữu**, dựa trên audit của Claude Code và Codex (`FINDINGS.md`, `PLAN.md`). Đây là một phần của lộ trình, không thay thế toàn bộ P0 giao dịch trong `PLAN.md`.

**Review Codex — 23/09/2026:** đọc kèm [REVIEW-IMPLEMENTATION-P0-CODEX.md](REVIEW-IMPLEMENTATION-P0-CODEX.md). Các yêu cầu R01–R12 trong bản review là điều kiện triển khai/nghiệm thu của tài liệu này và được ưu tiên khi khác các ví dụ bên dưới. Các snippet chỉ là phác thảo, không phải code đã kiểm chứng. Kết quả test MySQL do Claude báo cáo chưa được Codex chạy lại trong lượt review này. Chưa đủ cơ sở đánh dấu release-ready.

**Trạng thái bàn giao sau khi đọc evidence của Claude:** có thể bắt đầu triển khai theo từng đợt bên dưới; chưa được coi là đủ điều kiện deploy. Codex đã đọc log PHPUnit/JUnit và probe: 229 tests, 219 pass, 5 failures + 5 errors, 552 assertions; probe báo SQLSTATE 1364 thiếu id và 6 partition. Đây là xác minh nội dung artifact, chưa phải lượt chạy độc lập.

- **Làm ngay:** sửa runner theo R01 và ghi chú trong REPRODUCE-CLAUDE.md; sửa invitation cả response/return type và assertion notification; lập ma trận quyền rồi triển khai các quyền đã rõ; sửa import/private storage và các lỗi độc lập đã có acceptance test. Với network, kiểm mapping/contract trước khi sửa 7 test liên quan.
- **Tiếp theo:** triển khai pricing/composition, reserve/TTL, creative, payment và PoP đúng dependency R12. Chỉ phần phụ thuộc quyết định nghiệp vụ còn chờ mới phải dừng; tiếp tục các phần độc lập.
- **Không tự mặc định:** tháng 30 ngày, CPM tối thiểu từ forecast, chia đều tiền gói nhiều owner, TTL, dung sai creative hoặc quyền owner xác nhận tiền. Ghi quyết định vào mục 14 trước khi chốt công thức/state transition tương ứng.
- **Trước deploy:** full suite MySQL, migration upgrade, concurrency và E2E theo R11 phải có evidence đúng SHA. Không xoay khóa, ghi DB thật hay triển khai production chỉ vì tài liệu đã được review.

Cách đọc: mỗi hạng mục có 7 phần cố định — **Vấn đề → Hiện trạng code → Cách sửa → Thay đổi CSDL → File đụng tới → Test bắt buộc → Nghiệm thu**. Chỗ nào cần anh Tuấn quyết định nghiệp vụ thì ghi rõ **[CẦN QUYẾT ĐỊNH]**.

---

## 0. Quy ước chung cho cả đợt

Những điều sau áp dụng cho mọi hạng mục, không nhắc lại ở từng mục:

1. **Nghiệp vụ nằm ở Service, không nằm ở Controller.** Controller chỉ nhận request, gọi service, trả response.
2. **Mọi migration phải chạy lại được an toàn** — kiểm tra `Schema::hasTable` / `Schema::hasColumn` trước khi tạo, đúng như quy ước đang có trong `docs/system_design.md`.
3. **Khóa ngoại phải đúng kiểu:** trỏ tới Owner/Site/Screen/Campaign dùng `char(26)` (ULID); trỏ tới Network dùng `unsignedBigInteger`.
4. **Mọi thay đổi logic phải kèm test.** Không có test thì coi như chưa xong.
5. **Không đổi hành vi API công khai nếu không có lý do.** Riêng phần siết quyền ở mục 2 là cố ý đổi — xem cảnh báo ở đó.
6. **Giao diện quản trị dùng Filament gốc** (Resource, Page, Action, Form, Table, Infolist), không viết Livewire component rời.
7. **Đơn vị tiền là VND, không có phần lẻ.** Khi tính toán, làm tròn về số nguyên đồng ở bước cuối cùng, không làm tròn giữa chừng.
8. **Không sửa dữ liệu lịch sử.** Booking đã xác nhận giữ nguyên giá và điều khoản cũ. Dữ liệu cũ không rõ ràng thì đánh dấu để rà soát, không đoán.

### Thứ tự làm

```
Đợt 0 (nền)      → 0.1 CI chạy test + 0.2 xoay khóa deploy
Đợt 1 (chặn máu) → 2. Phân quyền API   3. Giá   5. Cổng bán hàng   10. Rate limit + webhook
Đợt 2 (đặt chỗ)  → 6. Gói hàng + snapshot → 4. Lịch trống + reserve/TTL
Đợt 3 (tiền+phát)→ 9. Assignment/creative gate → 7. Thanh toán/activation → 8. Bằng chứng
Đợt 4 (dọn)      → 11. Các việc nhỏ
```

Các mục 3/4/5/6 cùng sửa CartService và CampaignService; mục 2/8/10 cùng sửa quyền hoặc routes: cần thống nhất contract rồi tích hợp các thay đổi dùng chung. Contract chọn màn hình và giá của mục 6 phải có trước khi khóa tài nguyên ở mục 4. Làm assignment/gate mục 9 trước khi nối activation mục 7; mục 8 phụ thuộc định danh booking/assignment của mục 6/9. Đưa quyết định network 11.5 lên 0.1. TTL giữ chỗ tối thiểu phải hoàn tất trước khi mở giao dịch thật; xem thứ tự đã hiệu chỉnh trong bản review.

---

## 0.1. Dựng lại nền chạy test (làm đầu tiên)

**Vấn đề.** `phpunit.xml` khai báo chạy trên SQLite, nhưng nhiều migration dùng cú pháp riêng của MySQL (`SHOW INDEX FROM screens`, `UPDATE ... JOIN`). Vì vậy `php artisan test` theo mặc định chết ngay ở migration đầu tiên. Codex ghi nhận Q0 FAIL vì lý do này.

**Kết quả Claude báo cáo.** Chạy trên **MySQL 8**: **219 pass / 10 fail**. Cần đính kèm log đầy đủ, SHA, phiên bản PHP/MySQL và command/cấu hình đã che secret. Codex chưa chạy lại kết quả này; 10 failure vẫn là baseline chưa xanh, không thể kết luận chỉ cấu hình SQLite có lỗi.

**Cách sửa.** Không sửa migration để chiều SQLite (sẽ phải sửa hàng loạt và vẫn không chắc đúng với production). Thay vào đó chạy test trên MySQL, đúng loại CSDL của production:

1. Dùng MySQL test riêng, bắt buộc `APP_ENV=testing` và guard trước `RefreshDatabase`/migration. Thiếu cấu hình test thì dừng, không fallback sang `.env` của ứng dụng.
2. Thêm `.env.testing.example` và script dựng DB test cô lập. Vô hiệu `DB_URL`/`DB_SOCKET` kế thừa; APP_KEY, mail/cache/session/queue dùng cấu hình test không gọi dịch vụ thật. Xem R01 trước khi chạy ví dụ bên dưới.
3. Thêm CI (`.github/workflows/tests.yml`) chạy trước khi deploy:

```yaml
name: Tests
on: [push, pull_request]
jobs:
  phpunit:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: test
          MYSQL_DATABASE: oohx_test
        options: >-
          --health-cmd="mysqladmin ping -uroot -ptest"
          --health-interval=10s --health-timeout=5s --health-retries=10
        ports: ['3306:3306']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', extensions: pdo_mysql, gd, intl, zip, bcmath }
      - run: composer install --no-interaction --prefer-dist
      - run: php artisan test
        env:
          DB_CONNECTION: mysql
          DB_HOST: 127.0.0.1
          DB_DATABASE: oohx_test
          DB_USERNAME: root
          DB_PASSWORD: test
```

4. `needs: phpunit` chỉ tham chiếu job trong cùng workflow. Cho deploy phụ thuộc test cùng workflow, hoặc kiểm kết quả và đúng SHA qua workflow liên kết. Branch protection riêng không chặn một workflow deploy-on-push độc lập. Không cấp deployment secrets cho test PR không tin cậy. YAML/container ở đây phải được bổ sung các guard R01 trước khi chạy.

**Chạy test trên máy cá nhân khi không có PHP 8:** dùng container, xem công thức trong `docs/audit-5-vung-2026-09-23/REPRODUCE.md` của Codex hoặc cách tôi đã dùng:

```bash
docker run -d --rm --name t-mysql --network t-net -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=oohx_test mysql:8.0
docker run --rm --network t-net --entrypoint php \
  -e DB_CONNECTION=mysql -e DB_HOST=t-mysql -e DB_DATABASE=oohx_test \
  -e DB_USERNAME=root -e DB_PASSWORD=test -e DB_URL= \
  -v "$PWD:/app" -w /app <php8-image> artisan test
```

**Sửa luôn 10 test đang đỏ** (đều là lỗi có sẵn, không phải do đợt này):

| Test | Nguyên nhân | Cách sửa |
|---|---|---|
| `InventoryNetworksTest` (5), `InventoryScreensFilterTest` lọc network (2) | API đếm mạng lưới qua `sites.network_id`, còn test gắn qua `screens.network_code` — hai đường quan hệ song song | Chốt contract và kiểm dữ liệu theo R12 trước khi sửa API/test; không đổi assertion chỉ để xanh |
| `InvitationFlowTest::test_show_route_returns_410_for_expired` (1) | `view($view, $data, 410)` — tham số thứ ba là dữ liệu bổ sung, không phải mã HTTP | Đổi sang `response()->view(..., 410)` và sửa return type `show(): View` thành `View\|Response` với đúng import, hoặc trả Response ở mọi nhánh; test cả 200/410 |
| `InvitationFlowTest::test_invite_creates_row_and_sends_notification` (1) | JUnit ghi TypeError tại test dòng 63: `in_array()` nhận haystack là string | Sửa assertion theo kiểu route mail thực tế, vẫn kiểm đúng địa chỉ nhận; không gộp với lỗi HTTP 410 hoặc bỏ kiểm notification |
| `PanelAccessTest` owner inactive (1) | Test tạo owner `status='inactive'`, enum chỉ có `pending|active|suspended` | Sửa test dùng `suspended` |

---

## 0.2. Xoay khóa deploy đang nằm trong git

**Vấn đề.** File `github_actions_deploy` (private key SSH) đang được git theo dõi. Người có bản clone có thể có bản sao khóa; chưa xác minh khóa còn được máy chủ chấp nhận. Cần coi khóa là có nguy cơ lộ và giao ops kiểm tra/thu hồi, không in nội dung khóa vào log hoặc tài liệu.

**Cách làm** (việc của người quản trị, không phải code):
1. Tạo cặp khóa mới trên máy cá nhân: `ssh-keygen -t ed25519 -f oohx_deploy_new -C "github-actions"`.
2. Thêm khóa công khai mới vào `~/.ssh/authorized_keys` của user `deploy` trên VPS.
3. Cập nhật GitHub Secret `VPS_SSH_KEY` bằng khóa riêng mới.
4. Chạy thử một lần deploy để chắc khóa mới hoạt động.
5. **Xóa khóa cũ** khỏi `authorized_keys` trên VPS.
6. `git rm --cached github_actions_deploy github_actions_deploy.pub`, thêm hai tên này vào `.gitignore`, commit.
7. Nếu repo từng public hoặc có nhiều người clone: cân nhắc xóa khỏi lịch sử bằng `git filter-repo`. Việc này viết lại lịch sử, phải báo cả đội trước.

---

## 1. Hạng mục P0-H — chi tiết

### Mục 2 — Phân quyền cho API và đóng lỗ hổng tenant

**Vấn đề.** Ai có token Sanctum đều đọc, sửa, xóa được dữ liệu của mọi media owner. Ngoài ra, khi người dùng không có tenant đang chọn thì bộ lọc theo tenant tự tắt, nên người mua đọc được toàn bộ màn hình kèm giá sàn.

**Hiện trạng code.**
- `routes/api.php:47-62` — nhóm CRUD chỉ có `auth:sanctum`, không kiểm quyền.
- `app/Http/Controllers/Api/V1/OwnerController.php`, `SiteController.php`, `ScreenController.php` — không gọi `authorize()` lần nào, dù policy đã tồn tại.
- `app/Traits/HasOwnerScope.php:18` — `if ($user->current_owner_id)` rồi mới lọc; không có nhánh chặn khi rỗng.
- `ScreenController.php:41` và `SiteController::store` — nhận `owner_id` từ request, chỉ kiểm `exists`.
- `OwnerController` trả nguyên model, lộ `revenue_share_pct`, `billing_info`, `bank_*`, `tax_code`, `business_license_path`.

**Cách sửa.**

*Bước 1 — Bộ lọc tenant mặc định là chặn.* Snippet dưới chưa xử lý guest/job/ApiClient, membership đã thu hồi, hoặc super_admin đang chọn owner. Triển khai theo context rõ ràng ở R02; không dán đoạn này vào global scope và không mở quản trị chỉ vì có token:

```php
// Trước: không có tenant thì thấy tất cả.
// Sau:   không có tenant thì không thấy gì.
if ($user->current_owner_id) {
    $builder->where($model->getTable().'.owner_id', $user->current_owner_id);
    return;
}
if ($user->hasRole('super_admin')) {
    return; // super admin thấy tất cả, giữ nguyên
}
$builder->whereRaw('1 = 0'); // mọi trường hợp còn lại: không trả về gì
```

**Cảnh báo:** đây là thay đổi có thể làm hỏng các chỗ chạy nền không có tenant (lệnh artisan, job hàng đợi, sitemap, trang công khai). Trước khi sửa phải rà: `grep -rn "withoutGlobalScope('owner_scope')" app/` — những chỗ đã tự bỏ scope thì không ảnh hưởng. Chỗ nào chạy dưới quyền hệ thống thì phải thêm `withoutGlobalScope` một cách có chủ đích.

*Bước 2 — Tách quyền của token.* Hiện `CheckTokenAbility` đã có sẵn cơ chế. Chia làm hai nhóm:
- `inventory` — chỉ đọc. Token cấp cho đối tác (OOHX, TapON) chỉ có quyền này.
- `manage` — ghi. Chỉ cấp cho token nội bộ, và **vẫn phải qua policy**.

Route đổi thành:

```php
Route::prefix('v1')->middleware(['auth:sanctum', 'ability:manage'])->group(function () {
    Route::apiResource('owners', OwnerController::class);
    // ... sites, screens
});
```

*Bước 3 — Gọi policy trong controller.* Dùng `Gate::authorize(...)` với đúng import: base `app/Http/Controllers/Controller.php` hiện không có `AuthorizesRequests`, không thể copy `$this->authorize()`/`authorizeResource()` rồi coi là hoạt động. Bổ sung quyền ở service/Filament/web routes theo R02. Nếu policy chưa đủ thì bổ sung, nguyên tắc:
- `viewAny`/`view`: super_admin, hoặc thành viên của owner sở hữu bản ghi.
- `create`: chỉ thành viên có quyền `manage_inventory` trong owner đó.
- `update`/`delete`: như trên và bản ghi phải thuộc owner hiện tại.

*Bước 4 — `owner_id` suy ra từ người đăng nhập, không nhận từ request.* Trong `store()`:

```php
$ownerId = $request->user()->current_owner_id;
abort_if(! $ownerId, 403, 'Tài khoản chưa chọn media owner.');
// super_admin muốn tạo hộ owner khác thì phải truyền owner_id và được policy cho phép
```

*Bước 5 — Lọc trường nhạy cảm khi trả JSON.* Tạo `app/Http/Resources/Api/OwnerResource.php` chỉ trả các trường công khai: `id`, `name`, `slug`, `type`, `status`, `logo_url`, `website`, `city`. Các trường tài chính và giấy tờ chỉ trả cho super_admin.

**Thay đổi CSDL.** Không có.

**File đụng tới.**
```
app/Traits/HasOwnerScope.php
routes/api.php
app/Http/Controllers/Api/V1/{Owner,Site,Screen}Controller.php
app/Policies/{Owner,Site,Screen}Policy.php     (bổ sung nếu thiếu)
app/Http/Resources/Api/OwnerResource.php       (mới)
```

**Test bắt buộc** (`tests/Feature/Api/ApiAuthorizationTest.php`):
1. Token chỉ có `inventory` gọi `PUT /api/v1/screens/{id}` → **403**.
2. Người dùng thuộc owner A gọi `PUT` lên màn hình của owner B → **403 hoặc 404**, và dữ liệu B không đổi.
3. Người mua không có quyền quản trị gọi `GET /api/v1/screens` → **403** theo contract route quản trị; discovery riêng chỉ trả dữ liệu công khai.
4. `POST /api/v1/screens` kèm `owner_id` của owner khác → màn hình được tạo cho owner của người gọi, hoặc bị từ chối; không bao giờ tạo cho owner khác.
5. `GET /api/v1/owners` với tư cách không phải super_admin → JSON **không chứa** `revenue_share_pct`, `billing_info`, `bank_account_number`, `tax_code`.
6. Super_admin vẫn làm được mọi thứ như cũ (test chống hồi quy).

**Nghiệm thu.** Sáu test trên và ma trận R02 xanh; kiểm hành vi request/action và dữ liệu không đổi khi bị từ chối. Tìm chuỗi `authorize(` không đủ chứng minh phân quyền đúng.

**Rủi ro.** Đối tác đang dùng token cũ để ghi sẽ bị chặn sau khi lên. Kiểm inventory tích hợp và quyền đã được giao; không tự cấp `manage` cho mọi đối tác đang gọi API ghi. Chỉ cấp quyền cần thiết, gắn tenant và policy, kèm kế hoạch chuyển đổi được thống nhất.

---

### Mục 3 — Máy chủ tự quyết định số lượng tính tiền

**Vấn đề.** Client có thể giảm số lượng tính tiền mà giữ nguyên quyền sử dụng. Probe Codex: cùng 01/01–30/06/2027, code suy ra 7.000.000 theo ceil(181/30), nhưng input `duration_units=1` cho 1.000.000. Đây là bằng chứng chênh lệch, **không xác nhận 7.000.000 là giá nghiệp vụ đúng**; tháng lịch hay kỳ 30 ngày cần quyết định.

**Hiện trạng code.**
- `app/Http/Controllers/Buyer/CartController.php:61-63` — ba trường chỉ validate `min:1`.
- `app/Services/CartService.php:229-233` (nhánh CPM) và `:250-260` (nhánh I/O) — lấy thẳng giá trị client gửi.
- `CartService::updateItem:166-188` — khi người mua đổi ngày, `duration_units` cũ vẫn được giữ.
- `CartController::update:91-96` — không kiểm `end_date` phải sau `start_date`.

**Cách sửa.** Máy chủ xác thực quantity theo **contract sản phẩm**. Chốt kỳ I/O, quantity vật lý và CPM commitment trước khi viết công thức. Forecast không tự động là lượng CPM tối thiểu phải mua. Không im lặng nâng tiền; trả báo giá tính lại và yêu cầu buyer chấp nhận trước booking. **Các ví dụ `minimumCpms`, `max(...)`, tháng=30 và tự nâng quantity bên dưới chưa được duyệt để triển khai**; thay bằng contract R03.

Tạo `app/Services/Pricing/BillablePeriodCalculator.php`:

```php
final class BillablePeriodCalculator
{
    /** Số ngày tính cả ngày đầu và ngày cuối. */
    public function days(CarbonInterface $start, CarbonInterface $end): int
    {
        return $start->startOfDay()->diffInDays($end->startOfDay()) + 1;
    }

    /** Số kỳ I/O — làm tròn lên. Tuần = 7 ngày, tháng = 30 ngày. */
    public function ioUnits(CarbonInterface $start, CarbonInterface $end, string $unit): int
    {
        $divisor = $unit === 'week' ? 7 : 30;
        return max(1, (int) ceil($this->days($start, $end) / $divisor));
    }

    /** Số CPM tối thiểu phải mua, suy từ lượng hiển thị ước tính. */
    public function minimumCpms(int $estimatedImpressions): int
    {
        return max(1, (int) ceil($estimatedImpressions / 1000));
    }
}
```

Trong `CartService::estimateCost`:

```php
// I/O
$derivedUnits = $this->periods->ioUnits($start, $end, $rateUnit);
$requested    = (int) ($data['duration_units'] ?? $derivedUnits);
if ($requested < $derivedUnits) {
    $requested = $derivedUnits;   // im lặng nâng lên, KHÔNG nhận giá trị thấp hơn
}
$durationUnits = $requested;

// CPM
$minimum   = $this->periods->minimumCpms($totalImpressions);
$bookedCpms = max($minimum, (int) ($data['booked_cpms'] ?? $minimum));
```

`screen_count` **bỏ hẳn khỏi input của client**. Một dòng giỏ hàng ứng với một màn hình nên luôn bằng 1; nếu màn hình là cụm nhiều thiết bị thì lấy từ `screen_inventory.screen_count_override` (cột đã có).

`updateItem` phải **tính lại** chứ không giữ giá trị cũ: bỏ `duration_units` và `booked_cpms` khỏi `$mergedData`, để `estimateCost` tự suy lại từ ngày mới.

Bổ sung validation trong `CartController` (cả `add` và `update`):

```php
'start_date'     => ['required','date','after_or_equal:today'],
'end_date'       => ['required','date','after_or_equal:start_date'],
'share_of_voice_pct' => ['nullable','integer','min:1','max:100'],
// bỏ hẳn: screen_count
// giữ lại nhưng chỉ là "mua thêm": booked_cpms, duration_units
```

Thêm chặn khoảng ngày quá dài: tối đa 365 ngày cho một dòng. **[CẦN QUYẾT ĐỊNH]** con số này.

**Đóng băng giá.** Thêm hai cột vào `cart_items`: `rate_captured_at` (thời điểm lấy giá) và `rate_snapshot` (JSON gồm `io_rate`, `io_rate_unit`, `floor_cpm`). Khi chuyển giỏ thành chiến dịch, so giá hiện tại với giá đã chụp:
- Giống nhau → tiếp tục.
- Khác nhau → **dừng lại**, báo người mua "giá của màn hình X vừa thay đổi, vui lòng xem lại giỏ hàng", cập nhật giỏ rồi mới cho gửi. Không im lặng lấy giá mới.

**Thay đổi CSDL.**
```php
// database/migrations/2026_09_2x_000001_add_rate_snapshot_to_cart_items.php
if (! Schema::hasColumn('cart_items', 'rate_captured_at')) {
    $table->timestamp('rate_captured_at')->nullable();
}
if (! Schema::hasColumn('cart_items', 'rate_snapshot')) {
    $table->json('rate_snapshot')->nullable();
}
```

**File đụng tới.**
```
app/Services/Pricing/BillablePeriodCalculator.php   (mới)
app/Services/CartService.php
app/Http/Controllers/Buyer/CartController.php
app/Services/CampaignService.php                    (kiểm giá trước khi chuyển đổi)
database/migrations/...add_rate_snapshot_to_cart_items.php  (mới)
```

**Test bắt buộc** (`tests/Unit/Pricing/BillablePeriodCalculatorTest.php` + `tests/Feature/Buyer/CartPricingTest.php`):
1. Bảng giá trị cho `ioUnits`: 1 ngày → 1 kỳ; 30 ngày → 1; 31 ngày → 2; 181 ngày → 7 (đơn vị tháng). Tuần: 7 → 1; 8 → 2.
2. Cùng 01/01–30/06/2027 và `duration_units=1` không được giảm tiền mà giữ quyền sử dụng; số kỳ theo chính sách đã chốt (7 nếu kỳ 30 ngày, không vừa kỳ vọng 6 vừa 7).
3. CPM commitment không hợp contract → validation error hoặc báo giá cần xác nhận, không tự nâng tiền.
4. CPM commitment hợp contract → capacity và giá thống nhất; không mặc nhiên nhận mọi số lớn hơn forecast.
5. Đổi `end_date` dài thêm qua `PUT /cart/{item}` → chi phí tăng tương ứng.
6. `end_date` trước `start_date` → 422.
7. Đổi giá màn hình sau khi đã thêm vào giỏ → `POST /booking/create` bị chặn kèm thông báo.

**Nghiệm thu.** Cùng contract/snapshot và quyền sử dụng đã chọn, client không thể giảm tiền bằng sửa quantity. Thay đổi commitment hợp lệ có thể đổi giá và phải được buyer chấp nhận rõ ràng.

---

### Mục 5 — Cổng bán hàng: không bán thứ đang bị ẩn

**Vấn đề.** Trang công khai đã ẩn màn hình của owner chưa duyệt hoặc bị tạm ngưng, nhưng nếu biết ID thì vẫn thêm vào giỏ và đặt được. Câu "không hiển thị công khai và không thể được đặt" trong hồ sơ TMĐT hiện mới đúng một nửa.

**Hiện trạng code.**
- `CartController.php:43,55` chỉ validate `exists`.
- `CartService::addProduct:34` và `addItem:102` dùng `findOrFail`, không qua `publiclyVisible()`.
- `CampaignService::createFromCart` và `submit` không kiểm lại.

**Cách sửa.** Tạo một nơi duy nhất quyết định "thứ này có bán được không":

```php
// app/Services/PurchaseEligibilityService.php
final class PurchaseEligibilityService
{
    /** Ném lỗi 422 kèm lý do đọc được nếu màn hình không bán được. */
    public function assertScreenPurchasable(Screen $screen): void
    {
        $ok = Screen::publiclyVisible()->whereKey($screen->id)->exists();
        abort_unless($ok, 422, "Màn hình \"{$screen->name}\" hiện không nhận đặt chỗ.");
        abort_if($screen->status === 'maintenance', 422,
            "Màn hình \"{$screen->name}\" đang bảo trì.");
    }

    public function assertProductPurchasable(Product $product): void { /* tương tự */ }

    /** Các màn hình được chọn phải thuộc đúng gói. */
    public function assertScreensBelongToProduct(Product $product, array $screenIds): void
    {
        $valid = $product->screens()->whereIn('screens.id', $screenIds)->pluck('screens.id');
        abort_unless(count($screenIds) === $valid->count(), 422,
            'Danh sách màn hình được chọn không hợp lệ.');
    }
}
```

Gọi ở **bốn** chỗ, không chỉ một:
1. `CartService::addItem` / `addProduct` — lúc thêm vào giỏ.
2. `CartService::updateItem` — lúc sửa giỏ.
3. `CampaignService::createFromCart` — lúc biến giỏ thành chiến dịch (vì giữa hai bước, owner có thể đã bị tạm ngưng).
4. `CampaignService::submit` — lúc gửi cho media owner duyệt.

Booking đã xác nhận trước đó **không bị ảnh hưởng**: người mua vẫn xem được lịch sử, chỉ không tạo mới được.

**Thay đổi CSDL.** Không có.

**Test bắt buộc** (`tests/Feature/Buyer/PurchaseEligibilityTest.php`):
1. Owner ở trạng thái `pending` → `POST /cart/add` với ID màn hình của họ → 422, giỏ rỗng.
2. Owner `suspended` → như trên.
3. Màn hình `active=false` → 422.
4. Màn hình đang `maintenance` → 422.
5. Thêm vào giỏ hợp lệ, **sau đó** owner bị tạm ngưng → `POST /booking/create` bị chặn.
6. Chọn màn hình không thuộc gói → 422.
7. Booking đã được duyệt từ trước vẫn mở xem được sau khi owner bị tạm ngưng.

**Nghiệm thu.** Không có đường nào tạo được `booking_line` trỏ tới màn hình mà trang công khai đang ẩn.

---

### Mục 10 — Giới hạn tần suất và chặn webhook độc hại

**Vấn đề gồm ba phần.**
1. Không có giới hạn tần suất ở bất cứ đâu: dò mật khẩu và `client_secret` thoải mái.
2. Webhook cho đăng ký URL bất kỳ, kể cả trỏ vào mạng nội bộ của máy chủ.
3. Webhook **không hề thử lại**: `SendWebhookJob.php:65` gọi `$this->fail()` — lệnh này đánh dấu hỏng ngay lập tức. Hệ quả: `backoff()` là code chết, và nhánh "sau 3 lần lỗi thì tắt subscription" không bao giờ chạy tới. *(Codex phát hiện, tôi đã đọc `vendor/laravel/framework/src/Illuminate/Queue/InteractsWithQueue.php:53-62` xác nhận đúng.)*

**Cách sửa phần 1 — giới hạn tần suất.**

Trong `bootstrap/app.php`, thêm `$middleware->throttleApi();`. Định nghĩa giới hạn trong `AppServiceProvider::boot()`:

```php
RateLimiter::for('api',   fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
RateLimiter::for('login', fn (Request $r) => [
    Limit::perMinute(5)->by($r->ip()),
    Limit::perMinute(5)->by(strtolower($r->input('email')).'|'.$r->ip()),
]);
RateLimiter::for('token',   fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
RateLimiter::for('player',  fn (Request $r) => Limit::perMinute(600)->by($r->input('screen_uuid') ?: $r->ip()));
RateLimiter::for('geocode', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
```

Gắn vào route: `/login` → `throttle:login`; `/api/v1/auth/token` → `throttle:token`; player → `throttle:player`; `/geocode/search` → `throttle:geocode` **và** thêm cache kết quả 24 giờ theo từ khóa để đỡ gọi Nominatim.

**Cách sửa phần 2 — chặn SSRF.** Viết `app/Rules/SafePublicUrl.php`, dùng lúc đăng ký và trong transport lúc gửi. Đoạn `gethostbyname()` sau **không đủ**: chỉ kiểm một IPv4, chưa kiểm toàn bộ A/AAAA và chưa khóa IP kết nối nên vẫn có DNS rebinding. Thay bằng thiết kế R10; giữ xác thực TLS theo hostname và không theo redirect:

```php
// Chặn: scheme khác http/https; host phân giải ra IP riêng tư, loopback,
// link-local (169.254.x.x — gồm cả endpoint metadata của cloud), hoặc IPv6 tương đương.
$ip = gethostbyname($host);
$blocked = ! filter_var($ip, FILTER_VALIDATE_IP,
    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
```

Trong `SendWebhookJob`, thêm `->withoutRedirecting()` cho HTTP client để tránh bị chuyển hướng vào mạng nội bộ.

**Cách sửa phần 3 — thử lại thật.**

```php
if (! $response->successful()) {
    Log::warning('Webhook delivery failed', [...]);
    throw new RuntimeException("Webhook returned HTTP {$response->status()}");
    // throw → hàng đợi thử lại theo tries + backoff
}

/** Chỉ chạy khi đã hết số lần thử. */
public function failed(Throwable $e): void
{
    $this->subscription->update(['status' => 'inactive']);
    Log::error('Webhook subscription deactivated', [...]);
}
```

Thêm `event_id` (ULID) vào payload để bên nhận chống trùng. Và sửa `docker-compose.yml`: worker phải chạy `--queue=webhooks,default`, nếu không job webhook nằm mãi trong hàng đợi không ai xử lý.

**Thay đổi CSDL.** Không bắt buộc. Nếu muốn lưu vết giao hàng thì thêm bảng `webhook_deliveries` (event_id, subscription_id, status, attempts, last_error) — xếp vào P1.

**File đụng tới.**
```
bootstrap/app.php
app/Providers/AppServiceProvider.php
routes/api.php, routes/web.php
app/Rules/SafePublicUrl.php                 (mới)
app/Http/Controllers/Api/V1/WebhookController.php
app/Jobs/SendWebhookJob.php
docker-compose.yml
```

**Test bắt buộc.**
1. Gọi `/api/v1/auth/token` 11 lần trong một phút → lần thứ 11 trả **429**.
2. Đăng nhập sai 6 lần → **429**.
3. Đăng ký webhook trỏ `http://127.0.0.1/x`, `http://169.254.169.254/latest/meta-data/`, `http://192.168.1.1/` → đều **422**.
4. Webhook trả HTTP 500 → worker thật trên queue test thử lại theo tries/backoff; `Queue::fake()` chỉ chứng minh dispatch, không chứng minh retry.
5. Hết số lần thử → subscription chuyển `inactive`.

**Nghiệm thu.** Không endpoint nhạy cảm nào còn gọi được không giới hạn; webhook hỏng tạm thời thì tự thử lại.

---

### Mục 4 — Lịch trống: tính đúng và giữ chỗ an toàn

Đây là hạng mục khó nhất. Chia làm hai giai đoạn để có thể lên sớm phần quan trọng.

#### Giai đoạn A — sửa phép tính và khóa khi ghi

**Vấn đề 1 — cộng sai.** `AvailabilityService.php:14-22` cộng tất cả phần trăm thời lượng của mọi dòng **chồng ngày**. Hai booking 50% ở **hai nửa tháng rời nhau** bị cộng thành 100% cho cả tháng, nên đơn thứ ba bị từ chối oan. *(Codex phát hiện và đã chạy thử tái hiện.)*

**Vấn đề 2 — không khóa.** Kiểm tra ở bước xem lại (`BookingController.php:132`), ghi ở request sau (`:157`). Hai người gửi cùng lúc có thể cùng lọt. Ngoài ra `CampaignService::approveLines` và `approveAllForOwner` không kiểm tra lịch lần nào.

**Vấn đề 3 — đơn nháp giữ chỗ mãi mãi.** `createFromCart` tạo ngay dòng `pending`, và vì `cancelled` không bao giờ được ghi nên chỗ đó bị giữ vĩnh viễn.

**Cách sửa.**

*Tính theo ngày cao điểm, không cộng dồn cả khoảng:*

```php
/** Phần trăm thời lượng đã bị chiếm cao nhất trong khoảng yêu cầu. */
public function peakBookedSov(string $screenId, Carbon $start, Carbon $end, ?string $excludeCampaignId = null): int
{
    $lines = BookingLine::query()
        ->where('screen_id', $screenId)
        ->where('start_date', '<=', $end)
        ->where('end_date', '>=', $start)
        ->whereNotIn('status', ['cancelled', 'rejected'])
        // chỉ tính đơn đã thật sự gửi đi, không tính đơn còn nháp
        ->whereHas('campaign', fn ($q) => $q->whereIn('status', [
            'pending_approval', 'approved', 'active', 'paused', 'completed',
        ]))
        ->when($excludeCampaignId, fn ($q) => $q->where('campaign_id', '!=', $excludeCampaignId))
        ->get(['start_date', 'end_date', 'share_of_voice_pct']);

    $peak = 0;
    for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
        $sum = $lines
            ->filter(fn ($l) => $day->betweenIncluded($l->start_date, $l->end_date))
            ->sum('share_of_voice_pct');
        $peak = max($peak, (int) $sum);
    }
    return $peak;
}
```

Khoảng tối đa 365 ngày nên vòng lặp này rẻ. Nếu sau này thấy chậm thì chuyển sang thuật toán quét mốc (chỉ xét ngày bắt đầu và ngày kết thúc của từng dòng).

*Khóa khi ghi.* Đoạn sau chưa đủ chống oversell: **submit** mới chuyển draft sang trạng thái tính capacity và phải khóa–kiểm–ghi trong cùng transaction. R04 quy định khóa cart/campaign, toàn bộ màn hình gói, cộng line cùng request và loại trừ self. `createFromCart` vẫn phải khóa cart/chống chuyển đổi lặp:

```php
// Khóa các màn hình theo thứ tự ID cố định để tránh deadlock
$screenIds = $items->pluck('screen_id')->unique()->sort()->values();
Screen::whereIn('id', $screenIds)->orderBy('id')->lockForUpdate()->get();

// Kiểm lại lịch SAU khi đã khóa
foreach ($items as $item) {
    $peak = $this->availability->peakBookedSov(...);
    $max  = $item->screen->inventory?->share_of_voice_max_pct ?? 100;
    abort_if($peak + $item->share_of_voice_pct > $max, 409,
        "Màn hình \"{$item->screen->name}\" đã hết chỗ trong khoảng ngày này.");
}
```

Làm y như vậy trong `approveLines` và `approveAllForOwner` — bọc transaction, khóa, kiểm lại rồi mới duyệt. Đây là chỗ hiện đang hoàn toàn không kiểm.

*Đơn nháp không giữ chỗ.* Hai cách bổ trợ nhau:
- Truy vấn lịch chỉ tính chiến dịch đã gửi (đã có trong đoạn code trên).
- Nếu cần dọn draft, dùng expire/archive; retention và xóa file là quyết định riêng, không xóa cứng để giải quyết capacity. **[CẦN QUYẾT ĐỊNH]** thời hạn giữ draft. Pending reserve phải có TTL riêng theo R04.

*Đồng bộ lịch hiển thị.* `FrontpageController::detail:59-73` gộp mảng theo ngày nên bị ghi đè, và chỉ đọc dòng `approved`/`active` trong khi dịch vụ lại tính cả `pending`. Cho trang chi tiết dùng chung `peakBookedSov` để con số hiển thị khớp với lúc đặt.

#### Giai đoạn B — sổ giữ chỗ (TTL tối thiểu bắt buộc trước khi mở giao dịch)

Bảng hold/reservation cần liên kết tenant, cart/booking line và resource, khoảng thời gian, SOV, expires_at, status. Chốt transition reserve tại submit/confirmation theo R04; không để pending giữ chỗ vô hạn trước khi vào thanh toán. Khi hold chuyển thành booking confirmed, capacity chỉ được tính một lần. Truy vấn loại hold hết hạn ngay theo thời gian; scheduler làm cleanup và ghi transition, không phải điều kiện để nhả chỗ.

**Thay đổi CSDL (giai đoạn A).** Thêm cột để hỗ trợ hủy, dùng luôn cho mục 11:
```php
if (! Schema::hasColumn('campaigns', 'cancelled_at')) { $table->timestamp('cancelled_at')->nullable(); }
if (! Schema::hasColumn('campaigns', 'cancellation_reason')) { $table->text('cancellation_reason')->nullable(); }
```

**File đụng tới.**
```
app/Services/AvailabilityService.php
app/Services/CampaignService.php
app/Http/Controllers/FrontpageController.php
app/Console/Commands/PruneDraftCampaigns.php   (mới)
routes/console.php
```

**Test bắt buộc** (`tests/Feature/AvailabilityTest.php`):
1. **Chống hồi quy cho lỗi Codex tìm ra:** hai booking 50% ở 1–15/01 và 16–31/01 → xin thêm 50% cho cả tháng → **được chấp nhận**.
2. Một booking 50% đang chiếm cùng khoảng, capacity 100% → thêm 50% được, 60% bị từ chối. Nếu đã có **hai** booking 50% cùng khoảng thì mọi yêu cầu thêm SOV dương đều bị từ chối.
3. Chiến dịch còn `draft` không chiếm chỗ.
4. Chiến dịch đã hủy nhả chỗ ra.
5. **Đồng thời:** hai tiến trình cùng gửi 100% một màn hình cùng khoảng → đúng một cái thành công, cái kia nhận 409. *(Test này phải chạy trên MySQL, không chạy SQLite; dùng hai kết nối riêng.)*
6. Media owner duyệt hai chiến dịch cùng đòi 100% → cái thứ hai bị chặn.
7. Lịch trên trang chi tiết khớp với kết quả `peakBookedSov`.

**Nghiệm thu.** Không có thứ tự thao tác nào khiến tổng thời lượng một màn hình vượt mức tối đa. Đồng thời không từ chối nhầm đơn ở khoảng ngày rời nhau.

---

### Mục 6 — Gói hàng phải nở ra đủ màn hình

**Vấn đề.** Mua gói 10 màn hình nhưng hệ thống chỉ tạo **một** dòng booking cho màn hình đầu tiên. 9 màn còn lại không được giữ chỗ, media owner không biết phải phát ở đâu, và `product_id` để trống nên sau này không truy được đã bán gói nào.

**Hiện trạng code.**
- `CartService::addProduct:59-70` — `'screen_id' => $firstScreen?->id`, `duration_units => 1` cứng.
- `CampaignService::createFromCart:23-67` — mỗi dòng giỏ tạo đúng một dòng booking theo `item.screen_id`, không đọc `selected_screen_ids`, không set `product_id`.

**Cách sửa.**

*Lúc thêm vào giỏ:* giữ nguyên một dòng giỏ cho một gói (người mua nhìn thấy một dòng), nhưng:
- Với `buy_mode = package`: `selected_screen_ids` = **toàn bộ** màn hình của gói.
- Với `buy_mode = individual`: kiểm tra danh sách chọn thuộc gói (dùng `PurchaseEligibilityService` ở mục 5) và số lượng nằm trong `min_quantity`–`max_quantity`.
- Mỗi màn hình được chọn đều phải qua `assertScreenPurchasable`.

*Lúc chuyển thành chiến dịch:* một dòng giỏ gói → **N dòng booking**, mỗi màn hình một dòng:

```php
$screenIds = $item->product_id
    ? ($item->selected_screen_ids ?: $item->product->screens->pluck('id')->all())
    : [$item->screen_id];

$shares = $this->splitMoney((float) $item->estimated_cost, count($screenIds));

foreach ($screenIds as $i => $screenId) {
    BookingLine::create([
        'campaign_id'    => $campaign->id,
        'product_id'     => $item->product_id,     // hiện đang bị bỏ trống
        'screen_id'      => $screenId,
        'owner_id'       => $screens[$screenId]->owner_id,
        'estimated_cost' => $shares[$i],
        // ... các trường còn lại như cũ
    ]);
}
```

*Chia tiền không để lẻ:* chia đều phần nguyên, phần dư cộng vào dòng đầu tiên, để **tổng các dòng luôn bằng đúng giá gói**:

```php
private function splitMoney(float $total, int $n): array
{
    $cents = (int) round($total);          // VND không có phần lẻ
    $base  = intdiv($cents, $n);
    $rest  = $cents - $base * $n;
    return array_map(fn ($i) => (float) ($base + ($i === 0 ? $rest : 0)), range(0, $n - 1));
}
```

*Gói không có màn hình nào* → báo lỗi rõ ràng, không để `$firstScreen` null đi tiếp.

**Thay đổi CSDL.** `booking_lines.product_id` đã có sẵn, chỉ cần điền. Kiểm tra `BookingLine::$fillable` có `product_id` chưa, nếu chưa thì thêm.

**Test bắt buộc** (`tests/Feature/Buyer/PackageBookingTest.php`):
1. Gói 10 màn hình, mua cả gói → tạo **10** dòng booking, tất cả có `product_id`.
2. Tổng `estimated_cost` của 10 dòng = đúng giá gói (kể cả khi chia lẻ, ví dụ 1.000.003 đồng cho 3 màn).
3. Gói A+B, chỉ chọn B → chỉ tạo dòng cho B.
4. Chọn màn hình không thuộc gói → 422.
5. Chọn ít hơn `min_quantity` → 422.
6. Gói có màn hình thuộc owner đang tạm ngưng → 422.
7. Lịch trống được tính cho **cả 10** màn hình (nối với test ở mục 4).

**Nghiệm thu.** Số dòng booking bằng số màn hình thực bán; tổng tiền không đổi; truy ngược được gói đã bán.

---

### Mục 7 — Thanh toán tính theo từng media owner

**Vấn đề.** Sàn không giữ tiền, người mua chuyển thẳng cho từng owner. Nhưng việc kích hoạt lại dựa trên **tổng tiền toàn chiến dịch**: trả đủ cho owner A thì line của owner B cũng được kích hoạt dù B chưa nhận đồng nào. Ngoài ra không có chống trùng: bấm hai lần tạo hai bản ghi; số hóa đơn sinh bằng max+1 nên hai người bấm cùng lúc có thể trùng số; và bản ghi `pending` (người mua tự báo đã chuyển) đang được tính như đã trả.

**Hiện trạng code.**
- `PaymentService::checkAndActivate:103-130` — cộng tổng toàn chiến dịch rồi kích hoạt mọi dòng `approved`.
- `PaymentService::createPayment:20-51` — không có khóa chống trùng; `amount` nhận tùy ý.
- `PaymentService::generateInvoiceNumber:215-225` — `max + 1`, không khóa.
- `breakdownByOwner:186-205` — gộp `completed`, `pending`, `processing` vào cùng một con số `paid`.

**Cách sửa.**

*Tách "người mua báo đã chuyển" khỏi "đã nhận được tiền".*

```php
public function breakdownByOwner(Campaign $campaign): Collection
{
    // ... như cũ, nhưng tách hai nhóm:
    $confirmed = $payments->where('status', 'completed')->sum('amount');  // đã xác nhận
    $declared  = $payments->whereIn('status', ['pending','processing'])->sum('amount'); // buyer báo

    return [
        'total'       => $total,
        'paid'        => $confirmed,
        'declared'    => $declared,        // hiện riêng trên giao diện
        'remaining'   => max(0, $total - $confirmed),
        'is_paid'     => $confirmed >= $total,
    ];
}
```

Giao diện thanh toán sửa nhãn cho đúng: "Bạn đã báo chuyển khoản, đang chờ xác nhận" thay vì "Đã thanh toán".

*Kích hoạt theo từng owner.*

```php
public function checkAndActivate(Campaign $campaign): void
{
    foreach ($this->breakdownByOwner($campaign) as $row) {
        if (! $row['is_paid']) continue;

        $lines = $campaign->bookingLines()
            ->where('owner_id', $row['owner']->id)
            ->where('status', 'approved')->get();

        foreach ($lines as $line) {
            if (! $this->creativeGate->isReady($line)) continue;   // xem mục 9
            $line->update(['status' => 'active']);
        }
    }

    // Chiến dịch chuyển sang "đang chạy" khi có ít nhất một dòng active
    $hasActive = $campaign->bookingLines()->where('status','active')->exists();
    if ($hasActive && $campaign->status === Campaign::STATUS_APPROVED) {
        $campaign->update(['status' => Campaign::STATUS_ACTIVE, 'activated_at' => now()]);
    }
}
```

**[CẦN QUYẾT ĐỊNH]** chiến dịch chuyển sang "đang chạy" khi **một** owner đã đủ tiền, hay phải **tất cả**? Tôi đề xuất "một" vì phần của owner đó đã có thể phát thật, nhưng đây là quyết định kinh doanh.

*Chống trùng.* Dùng unique theo scope operation + tenant/campaign/owner, lưu payload hash và kết quả; kiểm quyền trước replay, payload khác trả 409, xử lý race bằng unique constraint + transaction. **Không triển khai lookup toàn cục bên dưới**: nó có thể trả payment của tenant khác và không chống hai insert đồng thời. Chi tiết R06:

```php
$existing = Payment::where('idempotency_key', $key)->first();
if ($existing) return $existing;      // bấm lại không tạo bản ghi mới
```

*Chặn số tiền vô lý.* Số tiền intent dương, đúng currency và chính sách đã chốt; **không tự đặt biên độ 1%**. Tiền ngân hàng thực nhận vượt số dư phải được ghi nhận, tách phần chưa phân bổ/hoàn tiền, không xóa hoặc từ chối ghi nhận sự thật tài chính. Xem R06.

*Số hóa đơn không trùng.* Dùng bảng đếm riêng:

```php
// bảng document_sequences: key (unique), next_number
return DB::transaction(function () use ($prefix) {
    $row = DB::table('document_sequences')->where('key', $prefix)->lockForUpdate()->first();
    // ... tăng rồi trả về
});
```

*Chỉ cho tạo thanh toán khi chiến dịch ở trạng thái hợp lệ:* `approved` hoặc `active`. Hiện `process()` không kiểm (chỉ `show()` kiểm).

*Người xác nhận.* Hiện chỉ admin sàn xác nhận được, trong khi tiền vào tài khoản owner. **[CẦN QUYẾT ĐỊNH]** có cho media owner tự xác nhận "đã nhận tiền" trong panel `/publisher` không? Nếu có, thêm action vào `BookingInboxResource` kèm ghi nhật ký ai xác nhận.

**Thay đổi CSDL.**
```php
// payments
if (! Schema::hasColumn('payments','idempotency_key')) {
    $table->string('idempotency_key', 64)->nullable()->unique();
}
// document_sequences (mới)
if (! Schema::hasTable('document_sequences')) {
    Schema::create('document_sequences', function (Blueprint $t) {
        $t->string('key', 32)->primary();
        $t->unsignedBigInteger('next_number')->default(1);
        $t->timestamps();
    });
}
```

**Test bắt buộc** (bổ sung vào `tests/Feature/PaymentPerOwnerTest.php`):
1. **Chống hồi quy cho lỗi Codex tìm ra:** chiến dịch của A (100tr) và B (120tr); trả đủ cho A → **chỉ dòng của A** chuyển `active`, dòng của B vẫn `approved`.
2. Gửi hai lần cùng `idempotency_key` → chỉ một bản ghi thanh toán.
3. Intent vượt hạn mức theo chính sách đã chốt bị chặn; khoản thực nhận dư vẫn ghi nhận và không mở khóa dịch vụ owner khác.
4. Bản ghi `pending` không làm `is_paid` thành true.
5. Tạo thanh toán cho chiến dịch `draft` hoặc `rejected` → 422.
6. Hai tiến trình cùng sinh số hóa đơn → hai số khác nhau.

**Nghiệm thu.** Tiền của owner nào chỉ mở khóa dịch vụ của owner đó.

---

### Mục 9 — Chưa duyệt nội dung thì không được phát

**Vấn đề.** Duyệt creative hiện chỉ đổi trạng thái, không được dùng làm gate activation. Audit source chưa thấy luồng ghi assignment vào `booking_line_creatives`; không suy ra bảng production rỗng nếu chưa có bằng chứng dữ liệu.

**Hiện trạng code.**
- `PaymentService::checkAndActivate:103-130` — không hỏi gì tới bảng creatives.
- `BookingController::uploadCreative:98-115` — chỉ kiểm định dạng và dung lượng; `width_px`, `height_px`, `duration_sec` không bao giờ được ghi.
- `CreativeResource.php:119-164` — duyệt/từ chối bằng `update()` trực tiếp, từ chối không cần lý do.

**Cách sửa.**

*Gán nội dung cho từng dòng booking.* Ở bước tải nội dung, người mua chọn nội dung này dùng cho màn hình nào. Mặc định: gán cho tất cả các dòng của chiến dịch (đa số trường hợp là vậy), cho sửa lại khi cần.

```php
// app/Services/CreativeAssignmentService.php
public function assign(Creative $creative, array $bookingLineIds): void
{
    // kiểm mọi dòng thuộc đúng chiến dịch của creative
    DB::table('booking_line_creatives')->upsert(
        collect($bookingLineIds)->map(fn ($id) => [
            'booking_line_id' => $id,
            'creative_id'     => $creative->id,
        ])->all(),
        ['booking_line_id','creative_id']
    );
}
```

*Cổng chặn phát sóng.* `approved()->exists()` sau chỉ minh họa, **chưa đủ nghiệm thu**: R07 yêu cầu assignment đúng line/owner/khung giờ, file bất biến, kết quả kỹ thuật hợp lệ và chỉ phát creative đã duyệt; creative khác pending không được lọt vào playlist. Không coi metadata chưa kiểm là ready.

```php
// app/Services/CreativeGateService.php
public function isReady(BookingLine $line): bool
{
    return $line->creatives()                 // qua booking_line_creatives
        ->where('creatives.status', 'approved')
        ->exists();
}

public function blockingReason(BookingLine $line): ?string
{
    if (! $line->creatives()->exists())  return 'Chưa gán nội dung quảng cáo.';
    if (! $this->isReady($line))         return 'Nội dung quảng cáo chưa được duyệt.';
    return null;
}
```

Gọi trong `checkAndActivate` (xem mục 7). Dòng nào chưa sẵn sàng thì **không** chuyển `active`, và ghi lý do vào `campaign_activities` để người mua nhìn thấy trên trang chiến dịch.

*Kiểm tra kỹ thuật khi tải lên.* Đọc kích thước ảnh/video và ghi vào `creatives`:
- Ảnh: `getimagesize()` → `width_px`, `height_px`.
- Video: cần `ffprobe`; nếu máy chủ chưa có thì để trống và đánh dấu "chưa kiểm" thay vì bịa số.
- So với `screen_specs` của các màn hình được gán: lệch tỉ lệ khung hình quá 5% → cảnh báo (chưa chặn, vì có thể co giãn được). **[CẦN QUYẾT ĐỊNH]** cảnh báo hay chặn.

*Từ chối phải có lý do.* Thêm `rejection_reason` vào `creatives` nếu chưa có; Filament action bắt nhập lý do (giống cách `rejectAll` ở Booking Inbox đang làm).

*Nội dung đã duyệt là bất biến.* Muốn đổi file thì tải bản mới; bản cũ giữ nguyên để đối chiếu về sau. Phiên bản đầy đủ (`creative_versions`) xếp vào P1; trước mắt chặn sửa file của creative đã `approved`.

**Thay đổi CSDL.**
```php
if (! Schema::hasColumn('creatives','rejection_reason')) { $table->text('rejection_reason')->nullable(); }
if (! Schema::hasColumn('creatives','reviewed_by'))      { $table->foreignId('reviewed_by')->nullable(); }
if (! Schema::hasColumn('creatives','reviewed_at'))      { $table->timestamp('reviewed_at')->nullable(); }
```

**Test bắt buộc** (`tests/Feature/Buyer/CreativeGateTest.php`):
1. Trả đủ tiền nhưng **không có** creative → dòng booking **không** chuyển `active`.
2. Creative ở trạng thái `pending_review` → không kích hoạt.
3. Creative bị `rejected` → không kích hoạt, chiến dịch ghi lý do.
4. Creative `approved` và đã gán → kích hoạt bình thường.
5. Chiến dịch hai owner, chỉ owner A có creative đã duyệt → chỉ dòng của A chạy.
6. Gán creative cho dòng booking của chiến dịch khác → bị chặn.
7. Từ chối không nhập lý do → không thực hiện được.

**Nghiệm thu.** Không có đường nào đưa dòng booking sang `active` khi nội dung chưa được duyệt và gán.

---

### Mục 8 — Sửa đường ghi bằng chứng phát sóng

**Vấn đề.** Endpoint nhận bằng chứng phát sóng **hoàn toàn không ghi được**. Tôi đã chạy thử trên MySQL 8 với schema thật:

```
INSERT_FAILED: SQLSTATE[HY000] 1364 Field 'id' doesn't have a default value
PK của impression_logs: id char(26) NOT NULL, Default: null
```

Nguyên nhân: khóa chính là ULID nhưng model `ImpressionLog` không dùng `HasUlids` nên không sinh id. Cộng thêm hai vấn đề Codex chỉ ra: `campaign_id`/`creative_id` khai kiểu số nguyên trong khi chiến dịch dùng ULID, và endpoint không xác thực thiết bị.

**Cách sửa.**

*Bước 1 — sửa model:*

```php
class ImpressionLog extends Model
{
    use HasUlids;
    public $incrementing = false;
    protected $keyType = 'string';
    // ...
}
```

*Bước 2 — mở rộng schema, không drop cột cũ.* Giữ nguyên `campaign_id`/`creative_id` số nguyên cho compatibility; thêm cột liên kết nội bộ ULID với tên tường minh (ví dụ `internal_campaign_id`, `internal_creative_id`, `booking_line_id`). Chưa gán ID legacy sang ULID nếu không có mapping được xác minh. Sửa reader/report để dùng đúng cột theo nguồn; backfill có checkpoint và đối chiếu dữ liệu. Không drop/recreate các cột hiện hữu trong đợt này.

Bảng raw đang partition theo `played_at`, PK `(id, played_at)`. **Không thêm `UNIQUE(event_id)` vào bảng này**: MySQL yêu cầu unique key chứa cột partition. Dùng bảng receipt không partition với unique `(device_identity_id, event_id)`, payload hash và liên kết raw `(id, played_at)`, ghi receipt + raw trong cùng transaction. Không thay bằng unique `(event_id, played_at)` vì retry thay thời gian sẽ lọt trùng. Chi tiết và nguồn MySQL ở R08.

*Bước 3 — xác thực thiết bị.* Cột `screens.device_token` đã có sẵn. Yêu cầu player gửi header:

```
X-Screen-Uuid:  <uuid>
X-Device-Token: <token>
```

So sánh bằng `hash_equals`. Token rỗng → từ chối. Kèm `throttle:player` ở mục 10. Việc cấp và xoay token làm trong panel publisher (Filament action "Cấp lại token thiết bị").

*Bước 4 — chống trùng.* Player giữ `event_id` qua retry/offline. Insert receipt/raw bất biến trong transaction; replay cùng key và payload trả kết quả cũ, khác payload trả 409. Không `updateOrCreate` vì có thể sửa bằng chứng cũ. Test hai request đồng thời và rollback giữa receipt/raw.

*Bước 5 — liên kết với dòng booking.* Payload phải chỉ rõ booking line hoặc deployment/assignment ID; xác thực thuộc thiết bị/màn hình, owner, campaign, creative và lịch có hiệu lực tại `played_at`. Không suy duy nhất từ screen + thời gian vì nhiều booking SOV có thể cùng chạy. Sự kiện không map được đưa vào quarantine riêng, không tính proof hợp lệ hoặc tiền. Xem R09.

*Bước 6 — tổng hợp số liệu.* `oohx:rollup-delivery` chỉ tổng hợp proof đã xác thực; chạy lại không cộng đôi, nhận được sự kiện đến muộn và không để job cũ ghi đè kết quả mới. Cập nhật reader/report sang linkage nội bộ mới. Audit source chưa thấy writer cho `actual_impressions`; chưa có bằng chứng để khẳng định mọi báo cáo production bằng 0. Xem R09.

**Test bắt buộc** (`tests/Feature/Api/PlayerProofTest.php`):
1. **Chống hồi quy:** gửi impression hợp lệ → ghi được 1 dòng (test này hiện đang thất bại 100% trên code cũ).
2. Không có `X-Device-Token` → 401.
3. Token sai → 401.
4. Gửi cùng `event_id` hai lần → chỉ một dòng.
5. `campaign_id` dạng ULID được chấp nhận (code cũ trả 422).
6. Sau khi chạy rollup, `actual_impressions` của dòng booking khớp tổng số đã ghi.
7. Gửi quá tần suất → 429.

**Nghiệm thu.** Gửi bằng chứng phát sóng ghi được, không trùng, và số liệu chảy lên báo cáo của người mua.

**Rủi ro.** Nếu ngoài thực địa **đang có** player gửi dữ liệu theo định dạng cũ thì việc đổi kiểu cột sẽ làm hỏng tích hợp đó. **[CẦN QUYẾT ĐỊNH]** hiện có thiết bị nào đang gửi thật không? Nếu có thì phải giữ endpoint cũ song song một thời gian.

---

## 11. Việc nhỏ, làm cùng đợt cuối

**11.1. Gỡ số liệu bịa trên trang công khai.** `index.blade.php:65` ("30M+"), `:75` và `:388-390` (bộ đếm "Live Impressions" tăng bằng `Math.random()`), `:91` ("AI Match 94%"), `:369` ("fill rate 40%"), `:345` và `:361-362` (nút bấm không có hành vi), `screen-card.blade.php:24` (badge "Còn trống" in vô điều kiện). Thay bằng số thật từ `FrontpageService::getHeroStats` hoặc gỡ hẳn. Đây là mục có rủi ro pháp lý khi cơ quan quản lý hậu kiểm.

**11.2. Sửa lỗi kiểm tra tiến độ import.** `ScreenImportService.php:293` gọi `$import->fresh(['status'])` — tham số của `fresh()` là danh sách quan hệ để nạp kèm, không phải tên cột, mà `ScreenImport` không có quan hệ tên `status`. Sửa thành `$import->newQuery()->whereKey($import->id)->value('status')`. *(Codex phát hiện.)*

**11.3. Gán vai trò khi tự đăng ký.** `BuyerAuthController::register` tạo tài khoản nhưng không gọi `assignRole('buyer')`, nên người tự đăng ký không vào được panel `/buyer`, trong khi người được mời thì vào được. Sửa và thêm lệnh backfill cho tài khoản cũ. *(Codex phát hiện.)*

**11.4. File import để ở ổ đĩa riêng.** `Publisher/Pages/ImportSites.php:80-87` lưu ở disk `public`, nhưng cleanup dùng `local`. Dùng disk có private root đã được cấu hình (không mặc định tên `private` tồn tại), đọc/xóa cùng disk. Kiểm file public cũ bằng manifest/dry-run, download có kiểm quyền. Xem R12. *(Codex phát hiện.)*

**11.5. Hợp nhất quan hệ Màn hình ↔ Mạng lưới.** Có ba đường `screens.network_code`, `screen_inventory.network_id`, `sites.network_id`. Chốt contract ở đợt 0.1 sau dry-run orphan/conflict và kiểm khả năng một site có nhiều network. Chưa được mặc định `sites.network_id` là nguồn chuẩn chỉ vì có FK. Migration thực hiện riêng, giữ compatibility, đối chiếu mapping trước/sau; không overwrite conflict hoặc sửa test chỉ để xanh. Xem R12.

**11.6. Giới hạn dữ liệu trang bản đồ.** `FrontpageService::getMapPins:695-711` nạp toàn bộ kèm 7 quan hệ. Thêm lọc theo khung nhìn bản đồ và giới hạn cứng (ví dụ 2.000 điểm), gom cụm phía máy chủ khi vượt ngưỡng.

**11.7. Trang sản phẩm chưa có lối vào.** `/products` hoạt động tốt và nằm trong sitemap nhưng không có link nào ở header/footer. Thêm vào menu.

---

## 12. Bảng tổng hợp để theo dõi tiến độ

| # | Hạng mục | Đợt | Công | Phụ thuộc | Rủi ro dữ liệu |
|---|---|---|---|---|---|
| 0.1 | CI chạy test trên MySQL | 0 | S | — | Không |
| 0.2 | Xoay khóa deploy | 0 | S | — | Không (việc ops) |
| 2 | Phân quyền API + scope chặn mặc định | 1 | M | 0.1 | Không, nhưng có thể chặn đối tác |
| 3 | Giá do máy chủ quyết định | 1 | M | 0.1 | Thêm 2 cột |
| 5 | Cổng bán hàng | 1 | S | 0.1 | Không |
| 10 | Rate limit + webhook | 1 | M | 0.1 | Không |
| 4 | Lịch trống + reserve/TTL + khóa | 2 | L | 3, 5, contract/expansion 6 | Reservation/expiry theo R04, không chỉ thêm 2 cột |
| 6 | Gói hàng nở đủ màn hình | 2 | M | 3, 5; chốt contract trước 4 | Snapshot composition/allocation; xem R05 |
| 7 | Thanh toán theo owner | 3 | L | 4, 6, 9 | Scoped idempotency + sequence; xem R06 |
| 9 | Assignment và creative gate | 3 (trước activation 7) | M | 2, 6 | Schema assignment/review theo R07 |
| 8 | Bằng chứng phát sóng | 3 | L | 2, 6, 9, 10 | **Expand linkage + receipt, giữ schema cũ** |
| 11 | Việc nhỏ | 4 | M | — | 11.5 có rủi ro |

Ước lượng S/M/L là độ lớn tương đối, không phải ngày công — đội tự quy đổi theo năng lực.

---

## 13. Những gì tài liệu này **không** giải quyết

Sau P0-H, hệ thống vẫn chưa hoàn thành toàn bộ P0 giao dịch của `PLAN.md`. RFQ/quote có phiên bản, media plan, hold/booking, creative workflow, proof và finance basics vẫn giữ phân loại P0 theo tài liệu đó; không tự chuyển tất cả sang P1/P2. Các phần còn lại phải được đối chiếu riêng với P0.1–P0.10 và backlog.

P0-H nhằm sửa hành vi hiện hữu. Hoàn tất P0-H không đồng nghĩa hoàn tất sitemap 5 vùng hoặc sẵn sàng vận hành toàn bộ quy trình giao dịch. TTL chống pending giữ chỗ vô hạn vẫn là gate của P0-H khi cho phép đặt chỗ thật.

---

## 14. Các quyết định đang chờ

| # | Câu hỏi | Chặn việc nào |
|---|---|---|
| 1 | Có đối tác nào đang gọi API ghi không? | Mục 2 |
| 2 | Khoảng ngày tối đa cho một dòng đặt chỗ? | Mục 3 |
| 3 | Chiến dịch nháp bao lâu thì tự dọn? | Mục 4 |
| 4 | Chiến dịch chuyển "đang chạy" khi một owner đủ tiền hay tất cả? | Mục 7 |
| 5 | Media owner có được tự xác nhận đã nhận tiền không? | Mục 7 |
| 6 | Nội dung sai tỉ lệ khung hình: cảnh báo hay chặn? | Mục 9 |
| 7 | Hiện có thiết bị player nào đang gửi dữ liệu thật không? | Mục 8 |
| 8 | Chính sách hủy dịch vụ và hoàn tiền | Release capacity và payment exceptions mục 4/7; workflow đầy đủ đối chiếu PLAN.md |
| 9 | I/O tính tháng lịch hay kỳ 30 ngày; CPM commitment liên hệ forecast/capacity thế nào? | Công thức và test mục 3 |
| 10 | Một Screen là một thiết bị hay một cụm; giá gói chia cho screen/owner theo quy tắc nào? | Mục 3, 6, 7; không tự chia đều cho nhiều owner |
| 11 | Pending giữ chỗ bao lâu; hết hạn, gia hạn, hủy và phản hồi khi thanh toán muộn? | Mục 4, 7 |
| 12 | Một site có thể thuộc nhiều network; nguồn quan hệ nào là contract chuẩn? | Mục 0.1, 11.5 |
| 13 | Proof offline đến muộn được nhận trong khoảng nào; phần dư thanh toán xử lý ra sao? | Mục 7, 8 |

Chưa có quyết định thì tiếp tục phần độc lập, nhưng không tự mặc định các con số/công thức đang được hỏi hoặc đánh dấu hạng mục phụ thuộc đã nghiệm thu.
