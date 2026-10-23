# Lộ trình sau giai đoạn 0

Cập nhật 13/10/2026 · Viết bởi Claude Code · Trạng thái hiện tại ở `audit-5-vung-2026-09-23/STATUS.md`

Giai đoạn 0 đã xong và xanh: phân quyền API, giá do máy chủ quyết, cổng bán hàng, giới hạn tần suất, CI chặn deploy. Tài liệu này trả lời câu hỏi **tiếp theo làm gì, theo thứ tự nào, và vì sao thứ tự đó**.

Nguyên tắc xuyên suốt: **đóng đường tiền trước, đổi giao diện sau.** Một lỗ hổng trong luồng đặt chỗ — thanh toán làm mất tiền thật hoặc mất uy tín với media owner; một trang chậm thì chỉ là chậm.

---

## Bức tranh tổng thể

| Giai đoạn | Nội dung | Quy mô | Chặn bởi |
|---|---|---|---|
| ~~1~~ | ~~Đóng đường tiền~~ **XONG 29/09** | XL | — |
| ~~1b~~ | ~~Vá nốt phân quyền~~ **XONG 29/09** | M | — |
| ~~2~~ | ~~Sửa đường ghi bằng chứng phát sóng~~ **XONG 29/09** | L | — |
| ~~3~~ | ~~Hợp nhất quan hệ Màn hình ↔ Mạng lưới~~ **XONG 29/09** | M | — (đã chạy `networks:reconcile` trên dữ liệu thật khi deploy 02/10: 2970→2970, 0 xung đột) |
| ~~4~~ | ~~Dọn số liệu bịa trên trang công khai~~ **XONG 03/10** | S | — |
| ~~5~~ | ~~API v2 + OpenAPI~~ **XONG 03/10, đóng nốt 10/10** | M | — (mốc 1, 2, 3 xong. Bốn trang luồng đặt chỗ bị đếm thiếu ngày 10/10 **đã chuyển xong cùng ngày** ở PR #73: khu người mua không còn trang nào render dữ liệu nghiệp vụ ở máy chủ — xem hai mục "SỬA 10/10/2026" và "ĐÃ LÀM 10/10/2026" trong giai đoạn 5) |
| ~~6~~ | ~~Next.js cho trang công khai~~ **XONG 07/10** | L | — |
| ~~7~~ | ~~Dọn Blade công khai~~ **XONG 08/10** | S | — (chọn nhánh B: bỏ đường lùi) |
| **8** | *(hoãn)* Next.js cho khu người mua | L | Có thêm người |

Giai đoạn 1b chạy song song được với 1 vì không đụng cùng file. Các giai đoạn còn lại nối tiếp.

---

## Giai đoạn 1 — Đóng đường tiền

Đây là phần nguy hiểm nhất còn lại. Sáu việc, xếp theo mức độ nguy hiểm.

### 1.1 Giữ chỗ nguyên tử và có hạn (F-02 / Codex F05) — **nguy hiểm nhất**

Hiện tại **không có khóa nào**. Hai người mua cùng một suất trên cùng một màn hình, gửi booking cùng lúc, thì **cả hai đều lọt**. Giai đoạn 0 mới sửa phép tính SOV theo ngày cao điểm, không đụng tới tranh chấp đồng thời.

Cần: bảng giữ chỗ có thời hạn, khóa ở tầng CSDL (`SELECT ... FOR UPDATE` hoặc ràng buộc duy nhất trên khoảng thời gian), job dọn giữ chỗ hết hạn, và trả lỗi rõ ràng khi suất đã bị người khác lấy.

**Xong khi:** test tranh chấp chạy nhiều kết nối thật, hai luồng cùng đặt một suất thì đúng một luồng thắng, luồng kia nhận lỗi có nghĩa.

**Câu hỏi cần anh trả lời:** giữ chỗ hết hạn sau bao lâu — 15 phút, 24 giờ, hay tới khi thanh toán?

### 1.2 Mở gói hàng thành nhiều dòng đặt chỗ (Codex R05)

Gói hiện được lưu như **một dòng**, nên không biết gói gồm màn hình nào tại thời điểm mua. Owner đổi thành phần gói sau đó thì đơn cũ trôi theo — vi phạm quy tắc "booking đã xác nhận giữ nguyên giá và điều khoản".

Cần: khi chốt đơn, mở gói thành N dòng booking và **chụp lại** thành phần gói.

**Câu hỏi cần anh trả lời:** gói có được gồm màn hình của nhiều media owner không? Nếu có thì chia tiền theo tỉ lệ nào?

### 1.3 Chặn phát sóng khi nội dung chưa duyệt (F-06)

Có enum trạng thái duyệt nhưng **không có đường chạy thật**: nội dung chưa duyệt vẫn ra được lịch phát. Với một sàn quảng cáo ngoài trời thì đây là rủi ro pháp lý, không chỉ rủi ro kỹ thuật.

Cần: bảng nối `booking_line_creatives`, chặn ở bước sinh lịch phát, và ghi nhật ký ai duyệt lúc nào.

### 1.4 Thanh toán theo công nợ từng media owner (F07 / F12)

Một chiến dịch mua màn hình của nhiều owner nhưng tiền đang gom một cục. Không trả lời được câu "owner này đã nhận đủ tiền chưa". Thiếu cả chống trùng khi người mua bấm thanh toán hai lần, và số hóa đơn có thể trùng.

**Câu hỏi cần anh trả lời:** chiến dịch chuyển sang "đang chạy" khi **một** owner đủ tiền hay khi **tất cả** đủ? Media owner có được tự xác nhận đã nhận tiền không?

### 1.5 Hủy, hoàn tiền, khiếu nại

Enum có, code không. Khách hủy thì hiện không có đường nào xử lý.

**Câu hỏi cần anh trả lời:** chính sách hủy và hoàn tiền — hủy trước bao lâu thì hoàn bao nhiêu phần trăm?

### 1.6 Rate card có phiên bản, bậc giá theo thời lượng

Hiện 12 kỳ = 12 lần một kỳ, không có chiết khấu. Đổi giá là đổi thẳng, không lưu lịch sử. Giai đoạn 0 đã đóng băng giá **trong giỏ**, nhưng bảng giá gốc vẫn không có phiên bản.

---

## Giai đoạn 1b — Vá nốt phân quyền (T1b)

Giai đoạn 0 mới siết phía API. Còn lại:

- Quyền cho controller web của người mua (gửi booking, thanh toán, cài đặt).
- Quyền cho action Filament (duyệt/từ chối booking, CRUD sản phẩm).
- `canAccessPanel` phải kiểm **owner đang chọn** còn hoạt động, không chỉ "có owner nào đó còn hoạt động".
- DTO lọc trường cho Site và Screen; `price_per_slot_vnd` đang lấy từ **giá sàn nội bộ** (F09).
- Quyền giá trong form Filament — hiện mới ép ở tầng API, form vẫn chỉ ẩn trường.

Việc nhỏ nhưng là đúng loại lỗ hổng mà audit đã bắt được một lần: **quyền định nghĩa hai chỗ thì sớm muộn lệch nhau.**

**Thêm 13/10/2026 — một món của 1b đã xong, và nó không nằm trong danh sách trên:**
**giới hạn theo mạng lưới** (`allowed_network_ids`) từng là ô cấu hình **không
thực thi gì** — bốn biểu mẫu thu thập, bảng in "N restricted", lời mời mang
theo, không chỗ nào gác. PR #83 làm nó thành một chốt thật ở ba tầng (scope đọc,
policy theo bản ghi, chặn ghi ở `Site::saving`). Chi tiết ở mục cuối tài liệu
này. Đúng loại lỗ mà 1b nói tới, chỉ ở một trục khác: không phải "định nghĩa hai
chỗ" mà **"định nghĩa đẹp ở giao diện, không có chỗ nào thi hành"**.

---

## Giai đoạn 2 — Sửa đường ghi bằng chứng phát sóng (F-04 / F08) — **ĐÃ XONG 29/09/2026**

`impression_logs` hiện **không ghi được**: khóa chính `char(26)` không có giá trị mặc định và model thiếu trait `HasUlids` — đã dựng probe tái hiện, lỗi SQLSTATE 1364. Nghĩa là toàn bộ bằng chứng phát sóng đang không có thật.

Cần: sửa schema và model, xác thực thiết bị player, chống trùng (khóa duy nhất **phải chứa cột phân vùng** `played_at`, bảng đang có 6 phân vùng), và bảng tổng hợp để báo cáo không quét bảng thô.

**Đã trả lời 29/09/2026: chưa có thiết bị nào gửi dữ liệu thật.** Nghĩa là được làm lại schema cho sạch, không phải viết migration bảo toàn dữ liệu — rẻ hơn nhiều. Vẫn phải giữ ràng buộc của bảng phân vùng: mọi khóa unique phải chứa `played_at`.

---

## Giai đoạn 3 — Hợp nhất quan hệ Màn hình ↔ Mạng lưới (F-12) — **ĐÃ XONG 29/09/2026**

Hai đường quan hệ song song, nên đếm và lọc theo mạng lưới ra kết quả khác nhau tuỳ đi đường nào. Đây là nguyên nhân **7 ca test đang bị loại khỏi CI**.

Cần migration trên dữ liệu production: chạy thử, đối chiếu số lượng trước/sau, rồi mới chạy thật. Xong thì **gỡ nhãn `#[Group]`** và bỏ `--exclude-group` khỏi workflow — điều kiện này đã ghi sẵn trong hai file test và trong `tests.yml`.

---

## Giai đoạn 4 — Dọn số liệu bịa trên trang công khai (F-15)

"30M+ lượt hiển thị", bộ đếm impression chạy bằng `Math.random()`, "AI Match 94%", "fill rate 40%", badge "Còn trống" in vô điều kiện, nút CTA không có hành vi. Với một sàn đang nộp hồ sơ TMĐT thì đây là rủi ro pháp lý chứ không phải chuyện thẩm mỹ.

Nhỏ về công sức, nhưng cần anh quyết: thay bằng số thật (nếu có) hay gỡ hẳn.

### Đã làm — hai đợt (29/09 và 03/10/2026)

Đợt đầu gỡ "30M+ impressions", bộ đếm `Math.random()`, "AI Match 94%" và badge "Còn trống" in vô điều kiện.

Đợt sau rà lại toàn bộ view công khai và tìm thêm sáu nhóm. Ba quyết định ngày 03/10:

| Chỗ | Quyết | Vì sao |
|---|---|---|
| "Tăng fill rate lên 40%" | gỡ | Fill rate cần dữ liệu phát sóng; chưa có player nào gửi dữ liệu, nên không có con số nào để thay vào |
| Hai ô `<select>` sắp xếp (/owners, /agency) | gỡ | `FrontpageService` không nhận tham số sắp xếp nào; làm chúng chạy là thêm tính năng mới vào Blade (CLAUDE.md mục 3) |
| Ô tìm kiếm + chip lọc trên /owners | **nối vào** | `getOwnersPaginated()` đã nhận `q` và `type` — thiếu dây nối, không thiếu nghiệp vụ |
| Chip lọc trên /agency | gỡ | `getAgencies()` cố định `type = 'agency'`, không có chỗ nào ở backend để trỏ tới |
| 5 nút CTA trang chủ | gỡ | `<button>` trần, không đích. Không có route đăng ký owner; ba tên gói không xác nhận được là bản ghi thật |
| "VERIFIED PARTNERS", "Đang còn trống" | đổi câu chữ | Truy vấn không đỡ được lời khẳng định; lọc thật thì làm ở Next.js khi có API v2 cho hai danh sách này |

**Lỗi thật lộ ra khi nối dây.** Filter `type` của `getOwnersPaginated()` so **slug nhóm điểm đặt** với cột `screen_inventory.venue_type` — một cột chuỗi khác, giữ mã OpenOOH do Filament ghi. Hai từ vựng không giao nhau nên nó khớp không gì cả. `/explore` làm đúng: quy slug sang `vn_category_id`. Và vì `getOwnersPaginated()` dùng chung với `/api/v2/owners`, nơi `docs/openapi/v2.yaml` **đã công bố** tham số `type`, nên đây là một tham số có hợp đồng mà trả rỗng im lặng — không phải chuyện riêng của Blade.

Chip lọc trước đây chưa phải link nên không ai gọi tới filter đó. Lỗi nằm im vì không có đường chạy — đúng kiểu CLAUDE.md mục 8 nói: có route, có tham số, có cả đặc tả, mà không có đường chạy thật.

**Còn lại, cần xác nhận:** ba thẻ mô tả gói "City Launch" / "Retail Activation" / "Event Blitz" vẫn đứng trên trang chủ (chỉ gỡ nút). Nếu nội dung trong chúng không ứng với bản ghi nào trong bảng `products` thì đó là phần F-15 chưa dọn.

12 test mới: `OwnersFilterTest` (9) và mở rộng `NoFabricatedMetricsTest` (3).

---

## Giai đoạn 5 — API v2 và OpenAPI

Endpoint công khai cho phần khám phá, đặc tả OpenAPI làm nguồn sự thật, sinh kiểu TypeScript từ đó.

Điểm mấu chốt: **Blade hiện tại phải đọc dữ liệu qua chính API đó**, không gọi thẳng service nữa. Tự mình ăn món mình nấu là cách chống lệch hợp đồng tốt nhất — nếu API sai thì trang đang chạy sai ngay, phát hiện được liền, thay vì để Next.js phát hiện sau vài tháng.

Đây cũng là lúc trả món nợ cũ: `InventoryController` và `FrontpageService` từng trôi khỏi nhau và gây rò rỉ dữ liệu chưa duyệt.

### Mốc 1 đã làm — và một chỗ lệch có chủ ý (30/09/2026)

Đã có: bốn endpoint đọc (`stats`, `screens`, `screens/{slug}`, `owners`), DTO danh sách trắng, giới hạn cứng 50 bản ghi mỗi trang, định dạng lỗi thống nhất chỉ áp cho `api/v2/*`, `docs/openapi/v2.yaml`, và `OpenApiContractTest` đối chiếu hai chiều đặc tả ↔ bảng route. 15 test mới, CI xanh (run `36702771684`).

**Chỗ lệch:** mục trên viết "Blade phải đọc dữ liệu qua chính API đó, không gọi thẳng service nữa". Mốc 1 **không** làm vậy. `CatalogController` gọi thẳng `FrontpageService` — đúng lớp truy vấn Blade đang dùng — nên API và trang dùng chung một đường truy vấn, khác nhau ở DTO chứ không ở truy vấn.

**Được gì:** món nợ cũ (`InventoryController` trôi khỏi `FrontpageService`) không thể lặp lại, vì chỉ còn một truy vấn. Không thêm một vòng HTTP nội bộ cho mỗi lần dựng trang.

**Mất gì — nói thẳng:** lý lẽ "tự mình ăn món mình nấu" của lộ trình nhắm vào một thứ mà cách làm này **không** che được. Tầng nằm trên service — DTO, phân trang, định dạng lỗi của controller — chỉ được test canh, chứ không được lưu lượng thật của trang công khai chạy qua mỗi ngày. Một lỗi ở tầng đó sẽ không làm trang đang chạy hỏng, nên sẽ không ai thấy cho tới khi Next.js gọi vào. Đúng kịch bản mà lộ trình muốn tránh.

**Nên xử lý thế nào:** giữ như hiện tại cho phần *đọc*, nhưng khi tới nhóm cần quyền (giỏ hàng, đặt chỗ, thanh toán) thì Next.js là bên tiêu thụ duy nhất và đường đó buộc phải đi qua HTTP thật. Nếu người rà soát thấy rủi ro trên không chấp nhận được, cách đóng lại là cho Blade gọi API qua HTTP ở đúng những trang danh mục — chi phí là một vòng HTTP nội bộ mỗi lần dựng trang. Quyết định này để mở.

### Mốc 2 đã làm (30/09/2026)

Ba endpoint: `screens/map`, `filters`, `owners/{slug}`. 27 test mới, CI xanh (run `36705780699`, 527 test, 0 lỗi).

**Khung nhìn bắt buộc cho bản đồ** — điều khoản CLAUDE.md mục 2. Thiếu một cạnh là 422. Lý do bắt buộc chứ không mặc định: không có khung nhìn thì "lấy pin bản đồ" nghĩa là lấy mọi màn hình có toạ độ, tức một lần xuất toàn bộ kho dưới cái tên vô hại. Chỗ bắt buộc đặt ở `MapViewportRequest` chứ không ở service, nên đường gọi nào quên cũng đỏ ngay.

Trên khung nhìn vẫn còn **giới hạn cứng 500 pin**, vì khung nhìn rộng vẫn trùm được cả kho. Khi bị cắt thì `meta.truncated` và `meta.total` nói ra — tổng đếm **trong khung nhìn đang hỏi**, không phải cả kho, vì đếm cả kho là đẩy client thu nhỏ khung nhìn mãi mà không bao giờ hết. Pin bị cắt sắp theo thứ tự xác định.

`getMapPins()` nhận hai tham số **tùy chọn** nên trang Blade giữ nguyên hành vi, và có `MapUnchangedByApiTest` canh điều đó — trước mốc này không test nào che trang bản đồ Blade.

### Mốc 3 đã làm (01–03/10/2026) — code xong, mục đích chưa

Bốn commit: `787dc9b` (sản phẩm), `4276acd` (giỏ hàng), `dcf2e59` (đặt chỗ + thanh toán),
`180d428` (sửa ba lỗi của vòng CI đầu).

Đã có, và đã dò trên production ngày 08/10/2026:

| Nhóm | Đường | Đo được |
|---|---|---|
| Sản phẩm | `products`, `products/{slug}` | 200, công khai |
| Giỏ hàng | `cart`, `cart/items`, `cart/items/{item}` | 401 khi chưa đăng nhập |
| Đặt chỗ | `campaigns`, `campaigns/{campaign}`, `…/creatives`, `…/submit` | 401 |
| Thanh toán | `campaigns/{campaign}/payments` (GET, POST) | 401 |
| Đăng nhập | `auth/login`, `auth/register`, `auth/logout` | `login` trả `invalid_credentials` 401 — controller chạy, có đọc CSDL |

**401 chứ không 404** là phép phân biệt đáng tiền ở đây: 404 nghĩa là route không tồn tại
trên bản đang chạy, 401 nghĩa là nó tồn tại và lớp xác thực đang làm việc.

55 ca test cho ba nhóm cần quyền (`CartApiTest` 18, `BookingApiTest` 17, `PaymentApiTest` 20).
`docs/openapi/v2.yaml` khai đủ **29** đường và `OpenApiContractTest` đối chiếu hai chiều đặc
tả ↔ bảng route. Kiểu TypeScript đã sinh ở `resources/js/types/api-v2.d.ts`.

Hai điều khoản CLAUDE.md kiểm được từ ngoài, và cả hai đúng: `GET /api/v2/products?per_page=999`
trả `meta.per_page = 50` kèm `max_per_page = 50` (giới hạn cứng có hiệu lực thật, không chỉ
trong test), và 401 trả đúng `{"error","message","code","details"}`.

**Không trôi khỏi nhau** — đây là món nợ cả giai đoạn 5 tồn tại để trả, nên nói rõ bằng chứng:
`Buyer\BookingController` và `Api\V2\BookingController` tiêm **đúng cùng năm service**
(`CampaignService`, `CartService`, `AvailabilityService`, `PolicyConsentService`,
`CreativeService`), và `CampaignService::createFromCart()` là chỗ **duy nhất** trong `app/`
gọi `Campaign::create`. Luật kiểm dùng chung qua FormRequest (`AddToCartRequest`), quyền qua
policy. Hai cửa, một đường.

#### Nhưng lý lẽ của mốc 3 vẫn chưa thành

Mốc 1 ghi rõ chỗ hở: tầng DTO/phân trang/lỗi "chỉ được test canh, không được lưu lượng thật
chạy qua". Và ghi rõ cách đóng: "khi tới nhóm cần quyền thì Next.js là bên tiêu thụ duy nhất
và đường đó buộc phải đi qua HTTP thật".

Tới ngày 08/10 bên tiêu thụ đó **chưa tồn tại**: không file `.ts`, `.tsx`, `.php` hay
`.blade.php` nào gọi `/api/v2/cart`, `/api/v2/campaigns` hoặc `…/payments`. Khu người mua Blade
gọi service trực tiếp, kiểu TypeScript đã sinh nhưng chưa ai import, và bên tiêu thụ dự kiến
là giai đoạn 8 — đang hoãn.

Đó đúng cùng hình dạng với `canary` v6: năm phép kiểm 404 nằm yên không chạy lần nào, vì điều
kiện kích hoạt (conf proxy đổi) gần như không xảy ra — và chúng chỉ chạy lần đầu ngày 08/10
sau khi v7 bỏ điều kiện đó đi. Khác biệt: ở đây điều kiện kích hoạt là một giai đoạn bị hoãn,
nên nó không tự xảy ra, và không có bản "v7" nào bỏ nó đi được.

### Bên tiêu thụ đầu tiên — trang giỏ hàng, 08/10/2026

`buyer/cart.blade.php` giờ đọc dữ liệu qua `GET /api/v2/cart`, không qua model.
`Buyer\CartController::index()` không nạp gì nữa và cũng không gọi `getOrCreateCart()` —
endpoint v2 đã gọi, gọi hai lần là hai lần ghi cho một lần xem trang.

**Gọi từ trình duyệt, không từ PHP.** Hai cách hiển nhiên hơn đều đã bị loại:

| Cách | Vì sao không |
|---|---|
| PHP tự `Http::get('https://oohx.net/api/v2/cart')` | OpenLiteSpeed chạy PHP qua một pool worker có hạn. Một request đang giữ worker mà chờ một request khác cũng cần worker thì dưới tải pool cạn và site đứng. |
| Sub-request nội bộ qua HTTP kernel | Không qua mạng, nhưng phải bịa lại phiên và Sanctum trong request con, và `throttle` đếm đôi mỗi lần dựng trang — hạn mức thật của người dùng còn một nửa mà không ai nhận ra. |

Gọi từ trình duyệt đi qua **nhiều** stack hơn cả hai: đúng web server, đúng middleware,
đúng DTO, đúng bộ dựng lỗi — mà không thêm plumbing nào ở máy chủ. Và nó đúng hình dạng
giai đoạn 8 sẽ dùng, nên công này không phải công bỏ đi.

**Đường GHI vẫn đi route Blade.** Nút xoá còn POST về `buyer.cart.remove`. Cố ý: khoảng
trống cần đóng là tầng ĐỌC, và chuyển cả đường ghi trong cùng một lần là nhân đôi diện rủi
ro trên một trang đụng tiền. `DELETE /api/v2/cart/items/{item}` đã có và đã có test; chuyển
sang nó là một bước riêng.

#### Và nó bắt được một lỗi ngay — đúng việc nó tồn tại để làm

Lý lẽ của mốc 1 là "nếu API sai thì trang đang chạy sai ngay, phát hiện được liền". Lần
chuyển này tìm ra chỗ sai theo đúng chiều ngược lại, mà kết quả thì giống:

Trang giỏ **tự tính VAT** — `sum * (1 + config('pricing.vat_rate'))` — trong khi
`PaymentService::withVat()` được ghi là chỗ duy nhất được phép tính VAT, và chính đặc tả
OpenAPI cố ý không trả VAT với lý do "cộng VAT ở đây là một phép làm tròn thứ hai". Chỗ làm
tròn thứ hai đó **đã tồn tại sẵn**, nằm trong view, suốt thời gian đó. Nó còn nhân trên tổng
dạng float trong khi `withVat` nhận int, nên hai đường lệch nhau được 1₫ — ở đúng con số
người mua đọc.

`summary` giờ trả `vat` và `total` lấy từ `withVat()`. Đó không phải thêm một chỗ tính; đó là
bỏ chỗ đang có, vì view hết lý do tự nhân. `vat` là hiệu `total - subtotal` chứ không phải
một phép nhân riêng — để `subtotal + vat === total` luôn đúng, tức cột cộng trên trang cộng
đúng với tổng của chính nó.

DTO cũng mở thêm `estimate.unit_price` và `delivery.screen_count`. Không phải nới whitelist
cho tiện: thiếu hai trường đó thì trang chỉ còn `estimate.cost` và người mua thấy một tổng
tiền không có cách nào kiểm. Cả hai là dữ liệu của chính dòng giỏ của họ, **không** phải giá
sàn nội bộ mà CLAUDE.md mục 2 cấm.

Một chỗ **không** phải nới: view từng ghép `site.province.name > site.commune.name`, và
`location.city` của DTO công khai đã chứa sẵn đúng chuỗi đó (`"Thái Nguyên > Phường Bắc Kạn"`
— dò production 08/10). Nên không phải thêm trường nào vào DTO công khai để phục vụ một trang
sau đăng nhập. Ghi chú lề: `city` chứa "Tỉnh > Phường" và `location_district` luôn null là mô
hình dữ liệu lệch, nhưng đó là dữ liệu sẵn có, không thuộc phạm vi lần này.

#### Còn lại gì

- ~~**Hai action đọc nữa có endpoint v2 tương ứng**~~ — **khảo sát 08/10: cả hai đều KHÔNG
  phải một lần chuyển, và mỗi cái chặn vì một lý do khác nhau.** Chi tiết ở mục dưới.
- **`/my/campaigns` và dashboard chuyển không được**: v2 chỉ có `POST campaigns`, **không**
  có `GET campaigns` dạng danh sách. Phải viết endpoint mới trước. Năm controller
  `BuyerDashboard`, `BuyerReport`, `BuyerSettings`, `Cancellation`, `OwnerReview` cũng cùng
  tình trạng — đó là phần việc ẩn mà con số "5 controller" ở bản trước của mục này che mất.
- **Phần JS không có test** — *đã lấp 08/10/2026.* Lúc viết mục này, test chỉ canh được
  trang trả 200, có trỏ tới endpoint, và không còn dựng sẵn số tiền trong HTML. Nay có 14
  ca jsdom cho trang giỏ, chạy trên đúng khối script sẽ lên production: ba con số tiền đọc
  thẳng từ API (phản hồi trong test cố ý **không** thoả `subtotal × 1,08 = total`, nên một
  bản tự nhân lại sẽ đỏ), ngày theo lịch không lùi ở múi giờ âm, tên màn hình chứa mã HTML
  không thoát ra khỏi thuộc tính, và 401 không nạp lại trang. Chi tiết ở mục trang thanh
  toán bên dưới.

### Trang thanh toán — ngoại lệ bảo mật đã được duyệt và dựng (08/10/2026)

Trang này hiển thị thẳng từ model `Owner`: `tax_code`, `bank_name`,
`bank_account_number`, `bank_account_name`, `bank_branch`. Đó **không** phải lỗi — mô
hình kinh doanh là người mua chuyển khoản trực tiếp cho từng media owner, ghi trong chú
thích của chính view theo hồ sơ Bộ Công Thương: *"thanh toán trực tiếp giữa khách hàng và
nhà cung cấp dịch vụ quảng cáo; OOHX.NET hỗ trợ ghi nhận giao dịch và đối soát"*. Người
mua buộc phải thấy nơi nhận tiền mới trả được.

Nhưng CLAUDE.md mục 2 và 5 cấm phơi `bank_*` và `tax_code`, nên chuyển trang này là một
**quyết định về chính sách bảo mật**, không phải một bước refactor. Đã trình, đã duyệt
08/10/2026. Ngoại lệ ghi vào CLAUDE.md mục 2 cùng lượt với code — không ghi trước, để
luật và code không bao giờ nói hai điều khác nhau.

Cái giá được trả bằng sáu lớp, không phải bằng một lời hứa trong chú thích:

| Lớp | Cách làm | Test canh |
|---|---|---|
| Phạm vi | Endpoint **không nhận tham số owner nào**. Danh sách dẫn từ `booking_lines` của chính campaign, lọc `approved\|active\|completed` — cùng nguồn với `breakdownByOwner()` | `owner_khong_co_man_hinh_trong_campaign_khong_ra_ngoai`, `owner_chi_con_dong_da_huy_thi_khong_ra_noi_nhan_tien`, `danh_sach_nguoi_nhan_khop_voi_danh_sach_cong_no` |
| Quyền | Cần `manage_payments`, **không phải** quyền xem. 404 khi không được xem, 403 khi xem được mà không được trả | `viewer_xem_duoc_cong_no_nhung_khong_xem_duoc_noi_nhan_tien`, `nguoi_to_chuc_khac_nhan_404_chu_khong_phai_403` |
| Trạng thái | `PaymentService::PAYABLE_STATUSES` — cùng danh sách với đường ghi | `campaign_chua_duyet_thi_chua_co_gi_de_doc` |
| Cache | `no-store, no-cache, must-revalidate, private` + `Pragma` + `Expires: 0` | `chan_cache_o_moi_tang` |
| Nhật ký | Một dòng `campaign_activities` mỗi lần đọc: ai, lúc nào, IP, owner nào. Cửa chống lụt 10 phút vì nhật ký đó hiện nguyên trên `/my/campaigns/{campaign}` và không phân trang | `ghi_lai_ai_doc_noi_nhan_tien_va_luc_nao`, `tai_lai_trang_nhieu_lan_khong_lam_lut_nhat_ky`, `bi_tu_choi_thi_khong_ghi_nhat_ky` |
| Tần suất | `throttle:20,1` — limiter riêng, chặt hơn 300/phút của nhóm | — |

**Hai đường, không một.** `GET payments` giữ nguyên tiền và vẫn chỉ cần quyền xem;
`GET payment-recipients` mang danh tính + nơi nhận tiền và **không mang đồng nào**. Lý do
tách: một bản sao thứ hai của phép tính tiền là một chỗ để trôi khỏi bản gốc, và đường
nhạy cảm càng hẹp càng dễ canh. `khong_mang_so_tien` giữ đúng ranh giới đó khỏi bị nới
dần.

**Một thứ đã siết chặt hơn trước, có chủ ý.** Trang Blade cũ chỉ gọi quyền `view`, nên vai
trò `viewer` đang đọc được số tài khoản của media owner. Sau khi chuyển thì không — người
cần số tài khoản là người đi chuyển tiền. Trang vẽ đúng trường hợp đó (hiện công nợ, nói
rõ thiếu quyền gì) thay vì chết trắng.

**Ba test cũ đã bị đảo chiều, không phải xoá.** `assertSee('0011001234567')` trên HTML giờ
nghĩa là *có ai đó đã nạp lại dữ liệu vào controller và đi vòng qua quyền của endpoint*,
nên nó thành `assertStringNotContainsString`. `assertSee($ownerY->name)` của R31 đã thành
phép kiểm rỗng — nó xanh kể cả khi công nợ của ownerY biến mất — nên bảo đảm thật của R31
chuyển sang hỏi `by_owner` qua API.

**Phần JS đã có người canh — 08/10/2026.** Lúc chuyển hai trang, đây là chỗ duy nhất tôi
ghi là *chưa canh được*. Nay có 60 ca chạy trong jsdom (33 lúc đầu, cộng 27 cho trang chi tiết chiến dịch) trên **đúng khối `<script>` sẽ lên
production** — test trích nó ra khỏi tệp `.blade.php` chứ không chép lại, nên không có bản
sao nào để trôi. Xem `tests/js/README.md`.

Ba lỗi thật của hai trang đó — `chu()` không thoát dấu nháy kép, `new Date()` làm lùi ngày
theo lịch, 401 xử lý bằng `location.reload()` — đều **không** làm đỏ một test PHPUnit nào.
Giờ mỗi lỗi có một ca riêng, và tôi đã kiểm bằng cách gây lại từng lỗi một để xem test có
đỏ thật.

Hai thứ đáng ghi lại:

- **Múi giờ của máy chạy test là một phần của phép kiểm.** Ba tệp test khai
  `process.env.TZ = 'Pacific/Honolulu'` (UTC−10). Chạy ở UTC — như máy CI mặc định — thì
  lỗi "ngày theo lịch lùi một ngày" không bao giờ hiện ra, và cái ghim
  `timeZone: 'Asia/Ho_Chi_Minh'` của lịch sử thanh toán cũng không chứng minh được gì.
- **Một móc DOM biến mất KHÔNG làm trang vỡ ồn ào.** Tôi đột biến thử: đổi tên
  `data-cart-total` trong Blade và để `moc-dom.json` nguyên. Phía PHP đỏ ngay, nhưng phía
  JS chỉ đỏ **một** ca trong mười bốn — script bắt `null` bên trong một `.then()` nên
  chính `.catch()` của nó hứng lấy, và trang hiện "không tải được" với nguyên nhân sai.
  Đó là triệu chứng khó lần nhất. Nên thêm một phép kiểm đối xứng: tập móc script đi tìm
  phải **trùng khít** tập móc được khai, thiếu đỏ mà thừa cũng đỏ.

**Chống trôi giữa hai phía:** `tests/js/moc-dom.json` là nguồn sự thật dùng chung — phía JS
dựng bộ khung DOM từ nó, `MocDomTrangBladeTest` đọc cùng tệp đó và đòi mọi móc có mặt
trong HTML trang thật. Một bộ khung viết tay trong test sẽ xanh mãi trong khi trang đã
đổi; đó là lý do nó không được viết tay.

**Vẫn chưa canh được:** CSS (một khối vẽ đúng mà bị `display:none` thì test vẫn xanh),
việc Blade ghép khối cấu hình, và trình duyệt thật. Vẫn nên mở bằng mắt một lần.

**Sửa kèm:** lịch sử thanh toán từng hiện "09:11 08/10/2026" — `vi-VN` đặt giờ trước ngày
khi một bộ `Intl.DateTimeFormat` khai cả ngày lẫn giờ, trong khi bản render cũ là
`d/m/Y H:i`. Nay dùng hai bộ định dạng để thứ tự do mình quyết, không do phiên bản ICU.

### Action đọc cuối cùng đã chuyển — chi tiết chiến dịch (08/10/2026)

`BuyerCampaignController::show` là action đọc **cuối cùng** của khu người mua còn dựng dữ
liệu từ model. Nó không phải việc chuyển, mà là mở rộng API trước: `GET campaigns/{campaign}`
thiếu đúng bốn thứ view cần — `cancelQuotes`, `reviewableOwners`, `myReviews`, `activities`.

Đã mở rộng thành **sáu** khối, cộng hai thứ trang cũ đọc thẳng từ accessor và `config()`:

| Khối | Vì sao ở API chứ không ở client |
|---|---|
| `stats` | Bốn con số đầu trang, đọc từ cùng accessor model. Client cộng lại từ `lines` là một phép tính thứ hai — và `estimated_cost` trong test cố ý khác tổng các dòng để bắt đúng việc đó |
| `cancel_quotes` | Tiền hoàn do `CancellationService` tính. **Rỗng khi thiếu quyền `manage_payments`** |
| `refund_policy` | Để client **không** chép cứng phần trăm. Chép cứng là để con số trên màn hình lệch khỏi con số máy chủ áp dụng mà không ai biết |
| `reviewable_owners` | `OwnerReviewService` quyết campaign nào đánh giá được |
| `my_reviews` | Kèm `status_label` từ máy chủ — client tự dịch là hai bộ chữ cho một trạng thái |
| `activities` + `activity_count` | **Giới hạn cứng 50 dòng**, kèm tổng số |

**Sáu khối chỉ ở đường ĐỌC.** Ba đường ghi cùng trả `BookingReview`, và nếu gộp vào phần
dùng chung thì mỗi lần tải một tệp quảng cáo sẽ chạy `quote()` cho từng dòng — một phép
tính không ai hỏi, trên đường người dùng đang chờ tệp lên. Có test riêng canh việc đường
ghi **không** mang theo sáu khối đó.

**Giới hạn 50 dòng lịch sử có lý do mới.** Lịch sử của một chiến dịch mọc theo **lượt
đọc** kể từ hôm nay: `remittance_details_viewed` thêm một dòng mỗi lần có người xem thông
tin nhận tiền. Không chặn là trả về một phản hồi lớn dần mà không ai để ý. Và nó nói ra
tổng số chứ không im lặng cắt.

**`metadata` của lịch sử không ra ngoài**, và đây là chỗ quan trọng nhất của đợt này. Cột
đó tự do, do tầng trong ghi vào, và nó **đã** chứa `ip` với `user_agent` của người đọc
thông tin nhận tiền. Trả một cột tự do ra ngoài là hứa một hợp đồng mà không ai kiểm được.
Tương tự `moderation_note` của đánh giá: ghi chú của người kiểm duyệt, viết cho nội bộ —
trả nó ra là biến nó thành câu trả lời chính thức gửi cho khách.

**Một lỗi phân quyền sửa kèm.** `show()` cũ so
`$campaign->organization_id === $request->user()->current_organization_id` — đúng phép so
mà `CampaignPolicy` được viết ra để thay. Hai hệ quả có thật: một người thuộc hai tổ chức,
mở link chiến dịch của tổ chức A trong khi đang chọn tổ chức B, nhận 403 cho chiến dịch
của chính mình; và nó **không kiểm tổ chức còn hoạt động hay không**, trong khi policy có
kiểm. Nay gọi `can('view')`, cùng luật với đường API phục vụ chính trang đó.

27 ca JS cho trang này (tổng 60 ca JS), 19 ca API mới.

### `GET /api/v2/campaigns` dạng danh sách — trang cuối của khu người mua (08/10/2026)

Trang `/my/campaigns` là trang Blade cuối cùng của khu người mua còn dựng dữ liệu từ model.
Nay nó đọc một endpoint danh sách có phân trang, bộ lọc trạng thái, và tìm theo tên/mã.

**Phạm vi là một phép phân quyền, nên nó ở service.** `CampaignService::listForUser()` dùng
chung cho cả hai đường, và nó **chặt hơn** bản cũ ở hai chỗ:

| Tình huống | Bản cũ (`currentOrganization->campaigns()`) | Nay |
|---|---|---|
| Bị gỡ khỏi **mọi** tổ chức | đọc được | 403 từ middleware `buyer` |
| `current_organization_id` trỏ sang tổ chức **không là thành viên** | **đọc được toàn bộ danh sách của nơi đó** | rỗng |
| Tổ chức bị tạm ngưng | đọc được | rỗng |

Khe thứ hai là khe đáng kể: cột `current_organization_id` client đổi được (có bộ chuyển tổ
chức), nên nó là **đầu vào**, không phải một sự thật. Middleware không đóng được khe đó —
nó chỉ hỏi "có thuộc tổ chức nào không".

**Ngoặc quanh nhóm `orWhere` là thứ duy nhất ngăn rò rỉ khi tìm kiếm.** Thiếu nó thì
`organization_id = X AND name LIKE … OR code LIKE …` đọc thành `(… AND …) OR (code LIKE …)`,
tức một mã trùng ở tổ chức khác cũng ra. Có test riêng cho đúng việc đó, và một test nữa
cho việc `%` với `_` trong từ khóa phải được coi là **chữ**: không thoát thì `q=%` khớp mọi
chiến dịch.

**Nhãn trạng thái: một định nghĩa.** `Campaign::STATUS_LABELS` thay cho các bản chép trong
hai khối `@php` của Blade. Máy chủ sở hữu **chữ** (`status_label`), client sở hữu **màu** —
màu là việc trình bày và đổi theo chủ đề. Còn ba bản chép trong Filament, chúng chỉ liệt kê
một phần trạng thái cho bộ lọc nên đổi là một thay đổi hành vi của khu quản trị; để riêng.

**Phân trang không được thụt lùi so với `$campaigns->links()`.** Bản Laravel vốn cho phép
chia sẻ link tới trang 3 và bấm Quay lại về đúng chỗ. Nên trang đọc `?page=` từ URL và ghi
lại bằng `pushState` — `replaceState` cho lần đầu và cho `popstate`, vì `pushState` ở lần
đầu khiến nút Quay lại trông như bị kẹt. Có test cho cả ba hành vi.

**Hai câu "rỗng" khác nhau.** "Chưa có campaign nào" khi đang lọc là nói sai — họ có
campaign, chỉ không có cái nào khớp — và câu sai đó dẫn họ đi tạo cái mới thay vì xoá lọc.

**Thêm vào bộ khung test JS:** trường `the` (thẻ HTML của móc khi không phải `div`). Cần vì
một `<input>` dựng thành `<div>` thì `el.value` ra `undefined`: test vẫn chạy nhưng đang
chạy trên một thứ khác với trang thật. Phía PHP cũng đòi trang render đúng thẻ đó.

23 ca JS cho trang này (tổng 83 ca JS), 19 ca API mới.

~~**Khu người mua từ đây không còn action đọc nào dựng dữ liệu từ model.**~~
**SỬA 09/10/2026: câu đó SAI**, và tôi viết nó mà không đếm lại. `/my`
(`BuyerDashboardController`) vẫn chạy bốn `COUNT(*)` rồi lấy năm chiến dịch
gần nhất qua model; `/my/campaigns/{campaign}/report` và `/my/settings` cũng
dựng từ model. Mục "Còn lại gì" ở trên đã liệt kê đúng năm controller đó —
câu kết này chép sai chính mục ngay trên nó. Xem mục tiếp theo.

### Trang đầu khu người mua — và một khe phạm vi (09/10/2026)

`/my` nay đọc `GET /api/v2/campaigns/summary` và `GET /api/v2/campaigns?per_page=5`
từ trình duyệt. Nhưng điều đáng ghi không phải việc đổi nguồn dữ liệu.

**Bản cũ đọc được dữ liệu của tổ chức khác.** Nó đếm bằng `$org->campaigns()`
với `$org = $user->currentOrganization`, và `currentOrganization` là một
`belongsTo` thuần trên `current_organization_id` — **không** kiểm tư cách thành
viên. Hai tình huống, cả hai có thật:

| Tình huống | Bản cũ | Nay |
|---|---|---|
| Bị **gỡ khỏi tổ chức**, cột vẫn trỏ ở đó | đếm và hiện 5 chiến dịch gần nhất của tổ chức ấy kèm tên, mã, kỳ chạy | 0 |
| Tổ chức bị **tạm ngưng** | đọc được | 0 |

Khu quản trị tổ chức xoá được thành viên và **không chỗ nào dọn cột đó**, nên
tình huống thứ nhất không cần ai tấn công — nó xảy ra khi một người bị cho ra
khỏi nhóm. `listForUser()` đã đóng cả hai khe cho `/my/campaigns` từ mốc trước;
trang đầu bị bỏ lại, nên cùng một người thấy **0 ở danh sách và 12 ở ô thống
kê**. Đó là dấu hiệu của hai bản chép phép scope, đúng thứ CLAUDE.md mục 1 nói
đừng làm.

Cổng nay tách thành `CampaignService::tuCachXemChienDich()` và **cả hai** đường
dùng nó. `CampaignSummaryApiTest::test_dem_khop_voi_danh_sach` so tổng của số
đếm với `meta.total` của danh sách: tách hai cổng ra lần nữa thì đỏ.

**`GET /api/v2/campaigns/summary`** trả tổng cộng đủ tám mã kèm chữ, bằng một
`GROUP BY` thay cho bốn `COUNT(*)`. Một đường riêng chứ không nhét vào `meta`
của danh sách: danh sách đã lọc, số đếm là toàn bộ — hai phạm vi trong một phản
hồi là cách để bên tiêu thụ đọc sai con số.

Đường này khai **trước** `campaigns/{campaign}`. Hai mẫu **cùng** số đoạn, nên
khai sau thì tham số hút chuỗi `summary`, ràng buộc model không tìm thấy ULID,
và đường trả 404 — một 404 trông y như "chưa deploy". Đột biến đảo thứ tự làm
7/8 ca đỏ.

17 ca jsdom cho trang này (tổng 108 ca JS), 7 ca API mới.

**Hai ca của PR #44 đã chuyển chỗ, không mất.** Chúng đọc thẻ trạng thái trong
HTML máy chủ render; máy chủ không còn render thẻ nào. Thứ chúng canh nay nằm ở
`tests/js/trang-dau.test.mjs` và `CampaignSummaryApiTest`, và ghi chú trong
`NhanTrangThaiMotNoiTest` nói rõ chúng đi đâu.

**Còn lại trong khu người mua:** `/my/campaigns/{campaign}/report` (cần DTO cho
`ReportService::getOverview()`) và `/my/settings` (phần lớn là đường ghi, nên nó
là một bài khác). Hai cái đó mới là câu kết đúng của mục này.

### SỬA 10/10/2026 — câu kết trên đếm thiếu BỐN trang

Hai trang đó đã xong (`report` ở PR #57, `settings` ở PR #71), nhưng **câu kết
đó sai**: khu người mua còn **bốn** trang nữa vẫn render dữ liệu ở máy chủ, và
không mục nào của tài liệu này từng nhắc tới chúng.

Chúng là bốn bước của **luồng đặt chỗ** — tức đường tiền:

| Trang | Đọc gì ở máy chủ | Cần gì |
|---|---|---|
| `booking/create.blade.php` | `$items` (giỏ: tên màn hình, kỳ chạy, thông số) | `GET /api/v2/cart` **đã có**. Phần còn lại là biểu mẫu tạo chiến dịch, `POST /api/v2/campaigns` cũng đã có |
| `booking/creative.blade.php` | `$campaign`, `$creatives`, `$lines` | `GET campaigns/{campaign}` đã có; cần xem nó có đủ `creatives` và `lines` hay phải nới DTO. Trang này còn **tải tệp lên** |
| `booking/review.blade.php` | `$campaign`, `$creatives`, `$lines`, **`$conflicts`** | `$conflicts` chưa có endpoint nào. Đây là trang người mua xác nhận trước khi gửi — trang đắt nhất nếu một con số sai |
| `booking/payment-success.blade.php` | `$payment`, `$campaign` | `GET campaigns/{campaign}/payments` đã có; cần một cách trỏ tới **đúng một** lần trả |

Vì sao chúng bị bỏ sót: mốc 3 đặt mục tiêu theo **action đọc** của khu người
mua, và bốn trang này là bước giữa của một luồng ghi. Chúng không nằm trong
danh sách nào, nên mỗi lần tổng kết lại đếm theo danh sách cũ.

**Hệ quả cho câu "giai đoạn 5 XONG":** đúng với mục tiêu đã khai (tầng
DTO/lỗi có lưu lượng thật, và nó đã bắt được bốn lỗi). Không đúng nếu đọc thành
"khu người mua không còn chỗ nào render dữ liệu ở máy chủ" — còn bốn chỗ, và ba
trong bốn nằm trên đường tiền.

### ĐÃ LÀM 10/10/2026 — cả bốn trang, và **không thêm endpoint nào**

PR #73 (`a6c01c5`), 22 tệp, +2.737/−532. CI xanh: 903 test PHPUnit / 3.368 phép
kiểm / 103s; deploy production `38057005734` **success**.

Thứ đáng ghi nhất: mục trên nói `$conflicts` **chưa có endpoint nào** — câu đó
sai. `GET /api/v2/campaigns/{campaign}` đã trả `conflicts` và `summary.can_submit`
từ `reviewPayload()`. Cả bốn trang chạy trên sáu đường **đã có sẵn**:

| Trang | Đọc | Ghi |
|---|---|---|
| `booking/create` | `GET /api/v2/cart` | `POST /api/v2/campaigns` |
| `booking/creative` | `GET campaigns/{campaign}` | `POST campaigns/{campaign}/creatives` |
| `booking/review` | `GET campaigns/{campaign}` (kèm `conflicts`, `can_submit`) | `POST campaigns/{campaign}/submit` |
| `booking/payment-success` | `GET campaigns/{campaign}/payments`, lấy `payments[0]` | — |

Chỉ nới hai trường ở DTO: `BookingLineResource.creative_spec = {width_px,
height_px}` (bảng kích thước tham khảo cần **pixel**, mà `ScreenSummary.size`
chỉ có mét — đặt ở `BookingLine` để không đụng DTO mà giỏ hàng và danh mục công
khai cũng dùng), và `summary.vat` + `summary.total`. Ba cột ảnh-chụp-giá
(`floor_cpm_at_booking`, `io_rate_at_booking`, `negotiated_cpm`) **không** nới:
dòng `CPM` dưới mỗi màn hình bị bỏ có chủ ý, vì đặt một đơn giá chưa nhân bên
cạnh một tổng đã nhân là mời người đọc so hai số không so được với nhau.

**Ba lỗi thật, không phải ba lượt đổi hình dạng:**

1. `payment-success` viết **cứng** thẻ `Chờ xác nhận`, bất kể `$payment->status`
   — một lần trả đã xác nhận vẫn hiện "Chờ xác nhận" trên đúng trang người mua
   mở ra để kiểm. Nay chữ lấy từ `status_label`.
2. `review` tự nhân `config('pricing.vat_rate')` **ngay trong view** — đúng hình
   dạng lỗi "VAT nhân hai lần" trang giỏ từng mắc. Nay `vat` là **hiệu** của hai
   số đã làm tròn, tính một chỗ ở `PaymentService::withVat()`.
3. `creative` và `review` in `strtoupper($c->type)`, nên người mua đọc được
   `VAST_TAG`; và `rejected` dùng chung màu xám với `pending_review` — hai nghĩa
   trái nhau một màu.

**Ba đường GHI của Blade đã gỡ:** `POST /booking/create`, `.../creative`,
`.../submit` cùng ba phương thức controller (`BookingController` 165 → 106
dòng). Thứ kiểm **trước khi** gỡ không phải luật validate mà là **bản ghi đồng
ý**: `/api/v2/.../submit` gọi đúng `PolicyConsentService::record()` với cùng
`CONTEXT_BOOKING` và cùng `subjectId`, nên bằng chứng khi tranh chấp không mất.

**Hai lỗ trong chính các chốt, tìm ra khi mở rộng phạm vi:**

- `TruongTrangDocTest` chỉ đọc `['get']['responses'][200]`, nên nó **không canh
  gì** cho mọi đường GHI của mọi trang — trường một trang gửi lên khai ở
  `requestBody`. (Đây là lỗ số 2 trong danh sách năm lỗ bên dưới.)
- **Trạng thái ban đầu của trang chỉ nằm trong markup.** Bộ khung jsdom dựng mỗi
  móc thành một thẻ **trống**, không mang thuộc tính nào từ Blade — nên `hidden`
  viết trong Blade là thứ không ca test JS nào chạm được, và một trang mở ra với
  khối lỗi rỗng đang hiện vẫn xanh. Sửa ở **sản phẩm**, không ở test: cả bốn
  script tự đặt trạng thái đầu. Giờ hai phía cùng canh — `MocDomTrangBladeTest`
  đòi `hidden` có trong markup (cái người dùng thấy **trước** khi script chạy),
  bốn tệp test JS đòi script tự đặt lại (cái người dùng thấy sau).

44 ca jsdom mới chạy trên **đúng khối script sẽ lên production**; tổng bộ JS nay
**177 ca**. Hai fixture cố ý **không khớp** để một bản tính lại ở client phải
đỏ: giỏ có `subtotal` 7.900.000 trong khi hai món cộng lại 8.000.000, và trang
xác nhận có VAT 9% — một thuế suất không tồn tại trong `config/pricing.php`.

**Câu kết đúng, lần này đếm bằng `find`:** 11 tệp Blade trong
`resources/views/buyer/` và `tests/js/moc-dom.json` khai **đủ 11**. Những gì
controller còn truyền vào view chỉ là tên tổ chức và `$campaign` để sinh liên
kết — không còn dữ liệu nghiệp vụ nào render ở máy chủ trong khu người mua.

---

## Đóng nợ — hủy/hoàn tiền có lối vào thật (30/09/2026)

Xen giữa mốc 2 và mốc 3, vì đây là chỗ duy nhất tôi **báo xong mà chưa xong**.

Giai đoạn 1 tôi báo "hủy và hoàn tiền: xong". Thực tế `CancellationService` không được gọi từ đâu cả — không controller, không route, không Filament action, chỉ có test của chính nó. Chính sách bậc thang đã chốt (≥14 ngày 100%, 7–13 ngày 50%, <7 ngày 0%) chỉ tồn tại trong test. Khách muốn hủy thì không ai bấm được, và với một sàn đang nộp hồ sơ TMĐT thì hoàn tiền là nghĩa vụ chứ không phải tiện ích.

Đã có, ba lối vào: người mua tự hủy từ `/my/campaigns/{campaign}` kèm số tiền hoàn dự kiến do máy chủ tính; quản trị sàn hủy hộ từ `/admin` và có danh sách hoàn tiền; media owner thấy nghĩa vụ hoàn tiền của mình ở `/publisher` và khai "đã hoàn". 23 test mới, CI xanh (run `36709847798`, 550 test, 0 lỗi).

Hai thứ rút ra, ghi lại để không lặp:

- **Bản đầu tôi lại viết hai bộ luật quyền** — phép kiểm nằm trong cả hai `RefundResource`, và sẽ thành ba bộ khi có endpoint v2. Gom về `RefundPolicy` (CLAUDE.md mục 4). Cũng phát hiện `TenantPermission::check()` đọc `auth()->user()`, nên một policy dùng nó sẽ trả lời về người đang đăng nhập chứ không về người được hỏi.
- **Test "nhả suất về kho" dựng `BookingLine` bằng tay thì xanh giả**: không có `InventoryHold` nào nên không có gì để nhả. Phải đi qua giỏ hàng thật.

~~**Chưa làm:** không có thông báo (email/in-app) khi một dòng bị hủy — media owner phải tự mở trang mới thấy nghĩa vụ hoàn tiền.~~ **Đã làm 13/10/2026** (PR #75): `RefundObligationNotification` gửi cho mọi người dùng của owner khi nghĩa vụ **sinh ra hoặc tăng**, qua `DB::afterCommit()` nên một transaction đổ không để lại thư đã bay đi. Kèm một điều kiện chưa kiểm được: `.env` của bản làm việc có `MAIL_MAILER=log`, và tôi không có quyền vào VPS để xem `.env` thật — xem mục B của "việc còn mở".

**Vẫn chưa làm:** không có hạn xử lý cho khoản `pending`, nên một khoản có thể chờ mãi mà không ai bị nhắc.

---

## Giai đoạn 6 — Next.js cho trang công khai — **ĐÃ XONG 07/10/2026**

13 đường dẫn công khai chạy trên Next, đo từ ngoài qua Cloudflare:

| Nhóm | Đường dẫn |
|---|---|
| Danh mục | `/`, `/explore`, `/explore/{slug}`, `/owners`, `/owners/{slug}`, `/products`, `/products/{slug}`, `/map` |
| Pháp lý | `/quy-che-hoat-dong`, `/chinh-sach-bao-mat`, `/giai-quyet-tranh-chap`, `/bang-phi` |
| Phản ánh TCXH | `/phan-anh-to-chuc-xa-hoi`, `/phan-anh-to-chuc-xa-hoi/danh-sach` |
| Xác thực | `/login`, `/register` |

Ranh giới với Laravel còn nguyên: `/api/v1/screens` → 401 (hợp đồng đối tác), `/api/v2/stats`, `/sitemap.xml`, `/robots.txt` → Laravel, `/cart` và `/my` → 302.

**Điều kiện "xong" của lộ trình, và phép đo cho từng điều:**

- *SEO không tụt* — `webapp/test/seo.mjs` đo HTML **đã render** của 15 trang, 13 thẻ bắt buộc mỗi trang, cộng canonical tự trỏ, giá trị `og:type`, JSON-LD phân tích được.
- *sitemap và canonical giữ nguyên* — `sitemap.xml` và `robots.txt` vẫn do Laravel sinh, không proxy. Canonical trang chủ phát `https://oohx.net` không gạch chéo cuối, khớp bản Blade.
- *thời gian phản hồi tốt hơn* — `/` 0,28s (Blade ~1,1s), `/explore` 0,24s.

**Hạ tầng khác với giả định "Caddy" trong lộ trình.** Thực tế là OpenLiteSpeed sau aaPanel, và điều đó đổi cách làm theo hai hướng:

- `context` khớp theo **tiền tố**, nên `context /` là luật bắt tất cả — trang chủ phải mở bằng một luật rewrite khớp đúng một đường dẫn.
- Đổi định tuyến đi qua `oohx-sync-proxy` chạy as-root, gọi từ `deploy.sh`. Sau một lần cài, mọi thay đổi `nextjs.conf` chỉ còn là một lần merge.

Chi tiết ở `docs/deploy/nextjs-proxy/README.md`.

**Một việc chưa kiểm được và cần người làm bằng tay:** đăng nhập thật ở `/login` rồi xem có vào thẳng `/my` không. `EnsureFrontendRequestsAreStateful` chỉ bật phiên khi `Origin` khớp `config('sanctum.stateful')`, mà danh sách đó suy ra từ `APP_URL` trên production. Sai thì đăng nhập **trả 200 mà không đặt phiên**, người dùng quay lại trang đăng nhập và không thấy lỗi gì.

---

## Giai đoạn 7 — Dọn Blade công khai — **ĐÃ XONG 08/10/2026**

Gỡ view cũ, route cũ, phần render của `FrontpageService`. Laravel còn lại làm API và Filament admin.

### Khảo sát 07/10/2026 — giai đoạn này nhỏ hơn và lớn hơn tên gọi của nó

**Nhỏ hơn:** mã *thật sự chết* chỉ có hai file, và đã xoá — `partials/network-card.blade.php`, `partials/site-card.blade.php`. Mọi method của `FrontpageController` vẫn có route trỏ tới, mọi view còn lại vẫn có controller render.

**Và `FrontpageService` KHÔNG phải lớp render.** Nó đang được `Api\V2\CatalogController`, `Api\V2\BookingController`, `CampaignPolicy`, `AvailabilityService`, `CreativeService` dùng — nó là lớp truy vấn dùng chung. Câu "gỡ phần render của FrontpageService" trong lộ trình chỉ đúng với một phần nhỏ, và gỡ nhầm là làm hỏng API.

`policies/bodies/*.blade.php` cũng **không phải view cũ**: `Api\V2\PublicContentController::policy()` render chúng. Chúng là nguồn văn bản pháp lý.

**Lớn hơn:** phần còn lại không phải dọn rác. Nó là **bỏ đường lùi về Laravel**.

~~Đường lùi đang có, ghi ở `docs/deploy/nextjs-proxy/README.md`: xoá `nextjs.conf` rồi `lswsctrl restart` là toàn bộ trang công khai quay về Blade.~~ **Hết hiệu lực 08/10/2026:** đường lùi đó **không còn tồn tại**. `FrontpageController` và các view trang đã xoá, nên xoá `nextjs.conf` giờ cho 404 trên toàn bộ trang công khai chứ không cho trang Blade cũ. Ai đọc mục này trong một sự cố cần biết điều đó trước khi gõ lệnh.

### Hai nhánh, mỗi nhánh kéo theo việc riêng

**A. Giữ đường lùi thêm một thời gian.** Khi ấy nó phải thật sự dùng được, mà hiện tại nó mang hai khiếm khuyết đã biết — vô hại hôm nay vì không ai thấy những trang đó, nhưng chúng là thứ hiện ra đúng lúc phải lùi:

- `detail.blade.php:21` và `FrontpageController.php:182` phát `'price' => 0` cho màn hình chưa niêm yết giá. Với schema.org, 0 nghĩa là **miễn phí**. Bản Next đã sửa (`offers` chỉ xuất hiện khi có giá), bản Blade thì chưa.
- Chân trang Blade còn **8** liên kết `href="#"`. Cùng loại khiếm khuyết F-15 đã dọn khỏi bản Next.

**B. Bỏ đường lùi và xoá.** Rẻ hơn về công, nhưng từ lúc đó một sự cố ở tầng Next không còn chỗ lùi nào ngoài việc sửa tiếp.

### Điều kiện nên có trước khi chọn B

- Luồng đăng nhập đã được kiểm bằng tay một lần (xem ghi chú cuối giai đoạn 6). Đây là flow **duy nhất** chưa có bằng chứng chạy thật, và cũng là flow mà `buyer/auth/*.blade.php` đang làm đường lùi.
- Các đường đã chuyển chạy đủ lâu để lưu lượng thật đi qua mọi nhánh, không chỉ qua phép đo.

**Cập nhật 08/10/2026 — nhánh B đã được chọn, và chọn trọn.** `FrontpageController.php`
đã xoá, `resources/views/frontpage/` chỉ còn `layouts/`, `partials/`, `policies/`, và
`resources/views/buyer/auth/` cũng đã xoá. Không còn đường lùi nào.

Hai điều kiện ở trên **chưa được xác nhận trước khi chọn**, nhưng cả hai đã đóng sau đó.

Điều kiện thứ hai thì thời gian tự trả: các đường đã chạy trên Next từ 07/10.

Điều kiện thứ nhất — luồng đăng nhập kiểm bằng tay — **đã kiểm, 08/10/2026, đăng nhập
thật thành công.** Đây là flow duy nhất của cả chặng chuyển sang Next không có cách nào
kiểm từ xa, vì khúc quyết định nằm ở trình duyệt: cookie phiên, CSRF, chuyển hướng sau
khi vào. Phần máy kiểm được thì đã xanh trước đó (`POST /api/v2/auth/login` với thông
tin giả trả `{"error":"invalid_credentials","code":401}`, tức controller chạy và có đọc
CSDL), nhưng nó không chứng minh được khúc còn lại.

Lần đăng nhập đó cũng gỡ luôn một rủi ro ghi ở cuối giai đoạn 6: `sanctum.stateful` suy
ra từ `APP_URL`, và sai thì đăng nhập **trả 200 mà không đặt phiên** — người dùng quay
lại trang đăng nhập và không thấy lỗi gì. Vào được nghĩa là danh sách đó đúng trên
production.

Và phiên do Next tạo ra dùng được cho khu người mua Blade, điều này đúng theo cấu trúc
chứ không nhờ may: `Api\V2\BuyerAuthController` và `Buyer\BuyerAuthController` gọi **cùng
một** `BuyerLoginService::attempt()`, hàm đó dùng `Auth::attempt` (guard `web`) rồi
`session()->regenerate()`. Một phiên web chuẩn, cùng origin, cùng cookie.

---

## Giai đoạn 8 — Khu người mua trên Next.js *(đang hoãn)*

Nằm sau đăng nhập nên **không có lợi ích SEO**, mà lại đụng tới tiền. Blade hiện chạy được. Chỉ làm khi có thêm người hoặc trang công khai đã ổn định và không tốn công bảo trì.

Không có giai đoạn nào cho `/admin` và `/publisher`. Nếu sau này vẫn muốn bỏ Filament ở đó, đó là một dự án riêng cần lý do riêng.


### Mục mới 14/10/2026 — giao diện bản Next không bằng bản Blade nó thay

Giai đoạn 6 nhận "xong" theo bốn điều kiện: SEO không tụt, sitemap/canonical
giữ nguyên, thời gian phản hồi tốt hơn, ranh giới với Laravel còn nguyên. **Không
điều nào trong bốn điều đó là "giao diện giống bản cũ"** — và khi đo lại ngày
14/10 thì 8 trang danh mục đã bị rút gọn đáng kể: trang chủ còn 5 trong 10 khối,
`/explore` và `/products` mất hẳn bộ lọc hiện ra cho người dùng, `/explore/{slug}`
mất gallery, bốn tab, insights, lịch trống và panel giá.

Kèm theo là ba lỗi tên lớp CSS — trong đó **mọi thẻ màn hình trên production
đang dùng `.sc` (một ô bảng thông số) thay vì `.sc-card`** — sống sót vì
`tests/js/class-css.test.mjs` chỉ soi `resources/views`, không soi `webapp/`.

Việc này có lịch riêng vì nó phục vụ đợt Sở Thông tin vào chụp màn hình:
**`docs/PLAN-DUNG-LAI-GIAO-DIEN-CU.md`** — bảy lát, bảng đối chiếu từng trang,
và danh sách những thứ **không** được dựng lại (số liệu F-15 đã dọn).

**XONG 17/10/2026 — cả bảy lát, tất cả đã lên production.** Sáu PR: #86 (lát
0+1+2) · #87 (lát 3) · #88 (lát 4) · #89 (lát 5) · #90 (lát 6) · #92 (lát 7).
Chi tiết, cùng năm lỗi thật tìm được trên đường và ba thứ còn chờ chủ dự án
quyết, ở mục cập nhật 17/10 phía dưới ("Đề xuất làm gì ngay") và ở cuối
`PLAN-DUNG-LAI-GIAO-DIEN-CU.md`.

**Việc tiếp theo của trang kho có lịch riêng:**
**`docs/PLAN-EXPLORE-QUYET-DINH-MUA.md`** (chốt 19/10/2026) — bảy lát đưa
`/explore` từ trang liệt kê thành trang ra quyết định: lọc theo trạng thái
giá, bản đồ cạnh danh sách, danh sách rút gọn và yêu cầu báo giá hàng loạt.
Lý do nằm ở dữ liệu: đo trên production ngày 18/10, **1/50** bản ghi có giá và
**0/50** có ảnh thực địa riêng, nên một trang danh mục kiểu thương mại điện tử
đang mời người mua làm một việc mà dữ liệu không cho phép làm.

> **XONG cả bảy lát ngày 20/10/2026** (#126, #128, #131, #132, #133, #134).
> Tổng kết, cùng ba lỗi có sẵn lộ ra trên đường, ở mục **19–20/10/2026** cuối
> tài liệu này.

---

## Việc quản trị

- ~~**Xoay khóa deploy** đang nằm trong git — thao tác trên VPS.~~ **XONG 01/10/2026.** Chi tiết ở mục dưới. Lưu ý nhãn: `STATUS.md` ghi việc này là "F-07", nhưng **F07 trong `FINDINGS.md` là chuyện khác** (tổng tiền che khuất công nợ theo owner, đã sửa). Việc khóa deploy là **mục 0.2** của `IMPLEMENTATION-P0-CLAUDE.md`. Tôi đã lặp lại nhãn sai đó trong nhiều báo cáo trước.
- ~~**Đổi remote git** sang địa chỉ mới.~~ **XONG** — `git remote -v` trả `https://github.com/TRUEVIEWVIETNAM/oohx-dash.git` cho cả fetch lẫn push.
- ~~**Dọn cảnh báo PHPUnit**~~ — **XONG CẢ HAI NỬA.** Nửa đầu 01/10/2026: cảnh báo `file_get_contents(.env)` làm 610/619 test thành WARN đã hết (xem `.env.testing`). Nửa sau cũng đã xong, chỉ chưa ai gạch mục này: đếm lại ngày 09/10 thì `tests/` có **0** lần dùng metadata trong doc-comment (`@dataProvider`, `@depends`, `@covers`, `@group`, `@testWith`) và 6 lần dùng attribute (`#[DataProvider]` ×5, `#[Group]` ×1). PHPUnit đang ở `^11.5.50`, nên không còn gì chặn việc lên 12 ở phía này.

### Xoay khóa deploy — đã làm, 01/10/2026

Khóa riêng SSH `github_actions_deploy` bị commit từ 26/03/2026, và **repo này là public**, nên nó công khai khoảng sáu tháng. Đã coi là **đã lộ**, không phải "có nguy cơ".

Trình tự đã chạy, và thứ tự này quan trọng: **đổi GitHub Secret không thu hồi gì cả** — khóa cũ vẫn vào được tới khi bị xóa khỏi `authorized_keys` trên máy chủ.

1. Tạo khóa mới `github-actions-deploy-2026-10-01` (`SHA256:Xcww8f/OG4g…`).
2. Thêm vào `/home/deploy/.ssh/authorized_keys` — **user `deploy`**, không phải `root`. Lần đầu khóa vào sai user và điều đó suýt làm đứt deploy.
3. Nạp khóa riêng vào secret `VPS_SSH_KEY`.
4. Kiểm **xuôi** từ ngoài vào: khóa mới đăng nhập được.
5. Thu hồi `SHA256:RPtyXaKydiJxA0XUnGQX59e3v3YXgDA2QL2Kggy5/t0` khỏi `authorized_keys`.
6. Kiểm **ngược**: khóa cũ bị từ chối. Đây là bằng chứng duy nhất của việc thu hồi; "đã xóa dòng đó" không phải bằng chứng.

Khóa vẫn nằm trong lịch sử git tại `ed6665a`. Viết lại lịch sử **không** làm nó hết lộ (repo public sáu tháng, có thể đã bị clone hoặc index) — nên xoay khóa là bắt buộc, dọn lịch sử là tùy chọn và nên làm sau khi merge.

`deploy.yml` nay có `workflow_dispatch`: lần xoay khóa sau kiểm được mà không phải đẩy commit vào `main`.

**Còn mở, không thuộc dự án này nên chủ dự án tự xử lý:** `/home/deploy/.ssh/authorized_keys` của cùng máy chủ còn khóa `SHA256:LoR24cxb/wZt0E/…`, mà khóa riêng của nó nằm trong repo public `tuanna0703/att_dashboard`. Đó là một đường vào còn mở tới đúng user mà oohx deploy bằng. Ghi lại một lần ở đây để không mất dấu.

---

## Mười cái chốt tự tìm phạm vi (ghi 10/10/2026, thêm hai cái 13/10)

Mỗi cái tự khám phá phạm vi của nó, nên thêm một trang / một bảng chữ / một
endpoint là nó tự vào tầm. Ghi ở đây vì chúng là thứ quyết định việc **lần sau
sửa gì thì đỏ** — và vì **bảy lỗ** đã tìm thấy trong chính chúng (năm ghi ngày
10/10, cái thứ sáu cùng ngày khi chuyển bốn trang đặt chỗ, cái thứ bảy ngày
13/10 khi dựng hàng rào mạng lưới), mỗi lỗ chỉ lộ ra khi mở rộng phạm vi.

| Chốt | Canh trục nào |
|---|---|
| `class-css.test.mjs` | mọi tên class trong Blade đều có người định nghĩa trong `frontpage.css` |
| `NhanEnumMotNoiTest` | enum CSDL ↔ `<CỘT>_LABELS`, hai chiều; cột `varchar` thì đối chiếu với hằng model |
| `TheEnumPhaiCoChuTest` | thẻ `->badge()` trên cột enum phải có chữ; danh sách nợ giờ **trống** |
| `MocDomTrangBladeTest` + `bo-khung.mjs` | móc DOM khai một chỗ, hai phía cùng đọc; từ 10/10 canh thêm **trạng thái ban đầu** trong markup (11 trang) |
| `TruongTrangDocTest` | trường trang đọc ↔ schema của **đúng** endpoint, cả GET và GHI (nhánh GHI vá 10/10) |
| `ChuPanelPhaiCoDauTest` | chữ hiển thị trong PHP của cả ba panel phải có dấu tiếng Việt |
| `ChuBladePanelPhaiCoDauTest` | chữ trong tệp Blade của panel |
| CI `Kiểu TypeScript còn khớp OpenAPI` | `api-v2.d.ts` sinh lại phải khớp bản đã commit |
| `NhanQuyenOwnerPhaiDuTest` *(13/10)* | mọi quyền trong `OwnerUser::PERMISSIONS` đều có nhãn ở `PermissionMatrix`, **và chiều ngược**: không nhãn nào trỏ vào quyền không tồn tại |
| Hai ca trong `GioiHanMangLuoiTest` *(13/10)* | mọi model dùng `HasOwnerScope` phải **thật sự** thu hẹp khi có giới hạn mạng lưới (SQL phải khác); và `['operator','read_only']` chỉ được có đúng **một** bản trong toàn bộ `app/` |

**Năm lỗ đã tìm thấy trong chính các chốt này**, ghi lại vì chúng là hình dạng
dễ lặp:

1. `ChuPanelPhaiCoDauTest` liệt kê tệp bằng `RecursiveIteratorIterator` thiếu
   `SKIP_DOTS` → đệ quy vào `..`, trả **9 mục** cho một thư mục có 115 tệp. Nó
   xanh vì `app/Filament/Publisher` tình cờ trả đủ — **đứng nhờ hình dạng thư
   mục, không nhờ mã đúng**. Cách đúng: `File::allFiles()`.
2. `TruongTrangDocTest` chỉ đọc `['get']`, nên **không canh gì** cho mọi đường
   GHI của mọi trang — trường một trang gửi lên khai ở `requestBody`.
3. `TheEnumPhaiCoChuTest` khớp theo **tên cột**, nên nó đếm sai bản chất 5
   trong 7 món nợ: hai cột `varchar`, và hai bảng **không tồn tại** trong CSDL
   chính (chúng thuộc một CSDL khác do worker Python sở hữu).
4. Bộ dò chữ Blade bản đầu bóc tag bằng regex → một tag có `=>` trong thuộc
   tính Alpine không bị bóc hết, và mã JS lọt ra thành "chữ" (757 báo oan).
5. Một ca test tôi viết (`chay_lai_up_khong_doi_gi`) là **phép kiểm rỗng**: nó
   không bắt được đột biến nào. Phải đổi sang đọc nhật ký truy vấn mới có tác
   dụng.

**Lỗ thứ sáu, tìm 10/10/2026 khi chuyển bốn trang đặt chỗ:** trạng thái ban đầu
của trang (`hidden` của khung chờ, ô lỗi, biểu mẫu) chỉ nằm trong markup Blade,
mà bộ khung jsdom dựng mỗi móc thành một thẻ **trống** — nên không ca JS nào
chạm được, và một trang mở ra với khối lỗi rỗng đang hiện vẫn xanh. Đã vá ở cả
hai phía (chi tiết trong "ĐÃ LÀM 10/10/2026" của giai đoạn 5). Lỗ số 2 cũng đã
vá cùng PR đó.

**Lỗ thứ bảy, tìm 13/10/2026 khi dựng hàng rào mạng lưới:** ca tự tìm phạm vi
mới (duyệt mọi model dùng `HasOwnerScope`) bản đầu dò trait bằng cách tìm chuỗi
`"HasOwnerScope"` trong tệp — nên nó khớp cả **docblock bàn về** trait ở
`OwnerUser`, và đi đỏ với tên một model không hề dùng trait. Đã đổi sang
`class_uses_recursive()`. Hình dạng chung với lỗ số 4: **một phép dò nhận cả lời
bàn về mã sẽ gọi tên sai thủ phạm** — và lần này nó gọi sai ngay ở thông báo lỗi,
tức làm người đọc đi sửa nhầm chỗ.

Bài học chung, đã ghi vào từng docblock: **ngưỡng chống-rỗng chỉ canh được phạm
vi nó biết**, và một chốt xanh chưa chứng minh nó đang canh thứ nó nói.

---

## Bảy câu hỏi nghiệp vụ đang chặn việc

| # | Câu hỏi | Chặn |
|---|---|---|
| 1 | Giữ chỗ hết hạn sau bao lâu? | 1.1 |
| 2 | Có đối tác nào đang **ghi** dữ liệu qua API không? | Deploy giai đoạn 0 lên production |
| 3 | Chính sách hủy và hoàn tiền? | 1.5 |
| 4 | Chiến dịch chạy khi một owner đủ tiền hay tất cả? | 1.4 |
| 5 | Media owner có được tự xác nhận đã nhận tiền? | 1.4 |
| ~~6~~ | ~~Có thiết bị player nào đang gửi dữ liệu thật?~~ **Đã trả lời 29/09: chưa có.** | — |
| 7 | Gói có gồm màn hình nhiều owner? Chia tiền thế nào? | 1.2 |

~~Thêm một câu treo từ giai đoạn 0: **khoảng ngày tối đa cho một dòng đặt chỗ** — đang tạm 365 ngày, có nới lên 730 hay bỏ hẳn không.~~ **Đã trả lời 01/10/2026: giữ 365 ngày.**

### Câu hỏi mới, phát sinh ngày 01/10/2026

**Chính sách tỷ giá cho giá niêm yết ngoài VND.** ~~Đã xác nhận dữ liệu thật có màn hình niêm yết bằng USD.~~ **SỬA 03/10/2026: câu đó SAI.** Truy vấn trên production trả về `{"VND":104}` và `{"VND":1}` — **không có bản ghi USD nào**. Tôi viết câu đó mà không kiểm, và nó làm ba câu hỏi dưới đây trông cấp bách hơn thực tế. Phần chặn giá ngoài VND vẫn đúng như một chốt phòng ngừa, nhưng nó hiện **không chặn việc gì**. Đường tiền không mang đơn vị (`cart_items.estimated_cost` và `booking_lines.estimated_cost` **không có cột currency**), nên nhánh CPM từng cho ra hóa đơn thấp hơn giá thật khoảng 25.000 lần. Hiện **chặn đặt trực tuyến** với giá ngoài VND, vì tự quy đổi là đặt ra một chính sách giá mà không ai duyệt.

Cần quyết ba điều trước khi mở lại: **tỷ giá lấy từ đâu** (cố định trong config, hay nguồn ngoài), **chụp lại lúc nào** (lúc thêm giỏ, lúc chốt đơn, hay lúc xuất hóa đơn), và **ai chịu rủi ro** khi tỷ giá đổi giữa hai mốc đó. `cart_items.rate_snapshot` đã có sẵn chỗ để chụp.

Phương án khác, tránh hẳn chuyện tỷ giá: yêu cầu media owner niêm yết bằng VND, và chuyển đổi dữ liệu USD hiện có một lần.

---

## Đề xuất làm gì ngay

~~Ba mục cũ (trả lời câu hỏi 2, Codex review giai đoạn 0, bắt đầu 1.1) đã hết hạn: giai đoạn 0 và 1 đã lên production từ 01–02/10.~~ Cập nhật 03/10/2026:

1. ~~**Mốc 3 của giai đoạn 5.**~~ **XONG 03/10**, và lý lẽ của nó **đã thành** — cập nhật 09/10/2026. Lúc viết mục này, nhóm cần quyền chưa có bên tiêu thụ nào và lựa chọn còn mở. Nay đã chọn, và ~~đã làm năm lần~~ **đã làm mười một lần — hết khu người mua** (cập nhật 13/10/2026): giỏ hàng, thanh toán, chi tiết chiến dịch, danh sách chiến dịch, trang đầu, báo cáo, cài đặt, và bốn bước luồng đặt chỗ — tất cả gọi `/api/v2` **từ trình duyệt**, không từ PHP. Nên tầng DTO/phân trang/lỗi có lưu lượng thật chạy qua, và nó đã bắt **bảy** lỗi mà test không bắt: VAT nhân hai lần ở trang giỏ, hai trong ba trạng thái nội dung hiện tiếng Anh, khe phạm vi của trang đầu, rồi ba lỗi của bốn trang đặt chỗ (thẻ trạng thái viết cứng, VAT nhân trong view, `VAST_TAG` lọt ra người mua) và `rejected` cùng màu với `pending_review`.

   ~~Còn hai trang đọc chưa chuyển (`report`, `settings`)~~ — **cả hai XONG**: `report` ở PR #57 (07/10), `settings` ở PR #71 (10/10). `settings` là trang đầu tiên của khu người mua **ghi** qua API, không chỉ đọc.

   ~~**Nhưng còn bốn trang nữa mà mục này chưa từng kể**~~ — **cả bốn XONG 10/10/2026** (PR #73): `create`, `creative`, `review`, `payment-success`. Xem "ĐÃ LÀM 10/10/2026" ở giai đoạn 5. Khu người mua nay **không còn trang nào** render dữ liệu nghiệp vụ ở máy chủ, và lượt chuyển đó bắt thêm ba lỗi thật — trong đó một thẻ trạng thái viết cứng trên trang xác nhận thanh toán.
2. ~~**Ban hành ba trang chính sách.**~~ **XONG 09/10/2026** (PR #56): `quy-che-hoat-dong`, `chinh-sach-bao-mat`, `giai-quyet-tranh-chap` đều `effective_from = 09/10/2026`, phiên bản `1.0`. `bang-phi` giữ `22/09/2026`.
3. **Cho Codex review khối từ R41 tới nay** (~~151~~ ~~163~~ ~~173~~ ~~176~~ **183** commit, `git rev-list --count 4887b0a..main`, đếm lại trên `main` ở `8065f6f` ngày 17/10 sau bảy lát giao diện — xem mục C bên dưới về việc hai con số trước đo sai): API v2, giỏ hàng, hoàn tiền, đường tiền, và nay thêm **mười một** trang khu người mua đọc API, mười cái chốt tự tìm phạm vi, cả nhóm B, cùng bốn lượt gom định nghĩa (phân quyền thành viên hai bên, "dòng nào mang nghĩa vụ tiền", và danh sách vai trò bị giới hạn mạng lưới) — cộng **hàng rào theo mạng lưới ba tầng**, thứ đáng có người thứ hai đọc nhất trong khối này vì nó là một chốt bảo mật vừa đổi hành vi trên dữ liệu production. Đúng loại code nên có người thứ hai đọc. **Đây là mục duy nhất trong ba mục này còn mở.**

### ~~Mục mới 10/10/2026 — bốn trang luồng đặt chỗ~~ **XONG 10/10/2026**

Làm **cả bốn** trong một PR (#73), không chỉ một. Quyết định phạm vi đó rẻ hơn
dự đoán ở mục này vì hai giả định dưới đây đều sai: `$conflicts` **đã có** đường
(`GET campaigns/{campaign}`), nên trang thứ tư không kéo theo endpoint mới; và
đường tải tệp cũng **đã có** (`POST campaigns/{campaign}/creatives`), nên trang
thứ ba không kéo theo gì. Tổng cộng không thêm endpoint nào, chỉ nới hai trường
DTO.

Giữ lại thứ tự đề xuất bên dưới vì **lý do** của nó vẫn đúng, và vì nó cũng là
thứ tự tôi đã làm thật:

1. **`booking/create`** — rẻ nhất, hai endpoint đều đã có, và nó cùng hình dạng với trang `settings` vừa làm (biểu mẫu + khung chờ + ô `disabled` tới khi dữ liệu về).
2. **`booking/payment-success`** — 42 dòng, chỉ đọc. Cần một cách trỏ tới đúng một lần trả.
3. **`booking/creative`** — cần xem DTO `campaigns/{campaign}` có đủ `creatives` và `lines`; trang này còn tải tệp lên, nên nó là bài khó hơn hai cái trên.
4. **`booking/review`** — để cuối, không vì nó khó nhất mà vì `$conflicts` **chưa có endpoint nào**, và nó là trang người mua xác nhận nghĩa vụ tiền. Một con số sai ở đây đắt hơn ở ba trang kia cộng lại.

~~Làm cả bốn hay chỉ một là **quyết định phạm vi**, không phải việc tự chọn: trang thứ tư kéo theo một endpoint mới cho `$conflicts`, và trang thứ ba kéo theo đường tải tệp.~~ Hai câu sau dấu hai chấm đều sai — xem đoạn mở đầu mục này.

**Lưu ý thứ tự:** mốc SEO, sinh TypeScript và cấu hình proxy OpenLiteSpeed (01–03/10) đều thuộc **giai đoạn 6**, làm trước khi xong giai đoạn 5. Chúng không vô ích — mốc SEO là điều kiện "xong" của giai đoạn 6, và cấu hình proxy đã ghi lại hạ tầng thật khác với giả định Caddy trong lộ trình — nhưng chúng không đưa giai đoạn 5 tiến thêm bước nào.

### Mục mới 13/10/2026 — việc còn mở sau khi giai đoạn 5 đóng

Giai đoạn 5 đã đóng thật và không còn PR nào mở. Dưới đây là **mọi thứ còn mở
mà tôi biết**, chia theo thứ chặn nó — mục đích là để lần sau không phải đếm lại
bằng ký ức.

> **Cập nhật 17/10/2026 — bảy lát đã xong, tất cả đã lên production.** `main` ở
> `8065f6f`, CI **1.000 ca / 3.804 khẳng định**, mọi lượt deploy thành công,
> không còn PR nào mở.
>
> **Sáu PR cho bảy lát:** #86 (lát 0+1+2) · #87 (lát 3) · #88 (lát 4) · #89
> (lát 5) · #90 (lát 6) · #92 (lát 7). Tổng **56 tệp, +11.904 / −724 dòng**,
> **15 component Next mới**, **+263 dòng** vào `frontpage.css`.
>
> CI đi từ 960 lên **1.000 ca** (3.565 → 3.804 khẳng định). Chốt mới
> `webapp/test/khoi-giao-dien.mjs` đi từ 0 lên **10 trang / 273 khẳng định** —
> nó đọc HTML **đã render** và trả lời câu mà hai chốt cũ không trả lời được:
> *khối này còn trên trang không*. `class-css.test.mjs` hỏi "tên class có người
> định nghĩa không" (`.sc` **có**, nên nó không thấy gì sai), `test:seo` chỉ đo
> thẻ `<meta>`.
>
> **#91 bị GitHub tự đóng** và đó là hệ quả của cách tôi merge: lát 7 mở với
> base là nhánh của lát 6; khi merge #90 kèm `--delete-branch`, nhánh base biến
> mất và PR con đóng theo — một PR đã đóng thì không đổi base và không mở lại
> được. Đã rebase nhánh lên `main` rồi mở lại ở #92. Lần sau với PR xếp chồng:
> merge PR nền **không kèm** `--delete-branch`, hoặc đổi base của PR con trước.

### Kế hoạch đoán sai về API ở bốn trong bảy lát

Bảng bảy lát trong `docs/PLAN-DUNG-LAI-GIAO-DIEN-CU.md` ghi lát nào cần endpoint
mới. **Bốn lát đoán sai**, và cả bốn sai cùng một kiểu:

| Lát | Kế hoạch nói | Thực tế |
|---|---|---|
| 0 | không cần API | `cover_url`/`logo_url` của owner trả **đường dẫn trong disk** → mọi ảnh bìa owner là ảnh hỏng; cộng 4 trường thẻ owner mà DTO không mang ra |
| 3 | `filters` ✔ | `/filters` đếm trên bảng **màn hình**; sidebar gói cần `categories`/`types`/`cities` **của gói** → thêm `GET /api/v2/products/filters` |
| 6 | `filters` ✔ `map` ✔ | `MapPinResource` thiếu `site.name` (gom pin theo toà nhà), `network.name`, `venue_type` (màu pin) |
| 7 | `products/{slug}` ✔ | thiếu `similar` — `getSimilarProducts()` có từ giai đoạn 4, chưa endpoint nào mang ra |

Lát 5 đoán đúng là "đã có", nhưng bỏ sót việc đánh giá công khai cần **DTO
riêng**: `OwnerReviewResource` viết cho tác giả nhận xét (mang `status`, `id`,
`owner.id`) nên không dùng được trên trang công khai.

**Mẫu chung:** trang Blade đọc thẳng model nên không ai thấy nó cần trường gì.
Chỉ khi dựng lại trên API mới lộ ra. Lần sau lập kế hoạch kiểu này, bước *"đối
chiếu từng trường view dùng với DTO đang có"* phải nằm trong khảo sát, không để
tới lúc làm.

### Ba lần CSS sống trong view đã bị gỡ

| Lát | Dòng | Nằm ở đâu | Nếu không chuyển |
|---|---|---|---|
| 4 | 70 | `@push('head')` của `detail.blade.php` | lightbox mở ra là một ảnh trần giữa trang, không nền đen; khối POI không có bố cục |
| 5 | 96 | `@push('head')` của `owner-detail.blade.php` | avatar không tròn và không chồng lên bìa, hai cột thành một, **cả hai tab hiện cùng lúc** |
| 6 | 35 | partial `map-styles` | bản đồ vẽ marker mặc định của Leaflet thay vì pin có tên site và vòng tròn cụm |

Lát 7 là lát **duy nhất** không phải chuyển dòng nào — trang gói viết CSS vào
tệp chung ngay từ đầu.

`tests/js/class-css.test.mjs` bắt được cả ba lần. Nó cũng bắt bốn tên class là
**móc JS của bản Blade** không có định nghĩa nào (`ms-drop-close`, `ms-compact`,
`has-children`, `sft-chips-wrap`) — ở React sự kiện gắn trực tiếp nên chúng
không còn việc gì.

### Ba lần stub nói sai hợp đồng, và cả ba che một lỗi

| Lát | Fixture sai | Hệ quả trong mọi lượt build thử |
|---|---|---|
| 3 | `type: 'bundle'` (ngoài enum `[single, package, custom]`) | thẻ gói in **mã thô** "bundle" ra trang |
| 5 | `stats: { screens, cities }` (hợp đồng là `screen_count`, `city_count`, `venue_types`) | trang hồ sơ in "**0 màn hình · 0 tỉnh/thành**" |
| 7 | `listing_mode: 'package'` (chuỗi, hợp đồng là object) | panel giá **không render nhánh nào** |

Ba lần liên tiếp lỗi nằm ở **dữ liệu giả**, không ở mã — và không chốt nào đỏ,
vì chốt đọc HTML do chính fixture đó sinh ra. Đó là lý do mỗi lát đều phải mở
fixture ra đọc, không chỉ chạy test.

### Năm lỗi thật tìm được khi dựng lại

1. **Mọi ảnh bìa owner là ảnh hỏng** (lát 0) — DTO trả đường dẫn trong disk,
   trình duyệt giải nó tương đối với trang đang mở.
2. **Thẻ owner dùng sáu tên class không ai định nghĩa** (lát 0) — ra DOM không
   có một kiểu nào, kể cả khung.
3. **`.listing-layout` được dùng mà chưa bao giờ được định nghĩa** (lát 3) — bố
   cục hai cột của `/products` thiếu cột trên desktop.
4. **Lịch trống sơn xanh ngày người khác đang thanh toán** (lát 4) — view cũ
   cộng `BookingLine` lấy và **không biết gì về `InventoryHold`**.
5. **Bốn id toàn cục trùng nhau ở trang gói** (lát 7) — khi `listing_mode` là
   `individual_only`, hai khối cùng khai ba id và `getElementById` chỉ trả cái
   đầu tiên, nên khối thứ hai không bao giờ cập nhật.

Thêm một cái bẫy PHP suýt lọt: `parent::toArray() + [...]` giữ giá trị **bên
trái** khi khóa trùng, nên `owner` của resource cha thắng và `phone`/`email`
không bao giờ ra ngoài — một khối "Liên hệ media owner" trống, không lỗi, không
cảnh báo. Một ca test bắt được (lát 4).

### Một quyết định đã đảo, và nó chạm ba chỗ

Trang `/owners/{slug}` của bản Next ghi *"tôi không in `contact`, vì một trang
công khai in email và số điện thoại là một trang cho máy thu thập địa chỉ"*,
kèm *"bản Blade cũng không in"*. **Câu thứ hai sai** — bản Blade in cả bốn
trường trong thẻ "Giới thiệu" cộng hai nút liên hệ. Và sàn **không thu hộ**,
nên không thấy đường liên hệ owner thì không ai đặt được gì.

Nay liên hệ owner hiện trên **cả** `/owners/{slug}` và `/explore/{slug}`, và
luật trong `webapp/test/seo.mjs` canh đúng thứ phải chặn (danh sách cấm của
CLAUDE.md mục 2). Muốn ẩn lại thì đó là một quyết định mới và phải sửa ba chỗ:
DTO, hai trang.

### Còn thiếu so với bản Blade — đều chờ chủ dự án quyết

| Khối | Trang | Vì sao chưa dựng |
|---|---|---|
| Thanh `trust` 5 mục | trang chủ | "Traffic real-time" (`impression_logs` chưa có bản ghi nào) · "AI-powered search" (không có AI trong mã) · "Secure payment" (sàn không thu hộ) |
| Mục "Marketplace OOH đầu tiên có AI" | trang chủ | in **3.2×** không nguồn, ảnh hotlink `images.unsplash.com` |
| Bốn thẻ "Điểm nổi bật" | `/explore/{slug}` | "AI Traffic Data" · "Proof of Play" · "Verified Location 2024" · "Fast Booking 48h" — không câu nào kiểm được |

Và ba việc kỹ thuật nhỏ còn mở:

- **Kiểu xem danh sách ở `/explore` chỉ thu lưới về một cột.**
  `.inv-grid.list-view` nhắm vào `.ic-*` của `.inv-card` — bộ class **chết**,
  không view nào trong repo dùng, kể cả bản Blade trước khi gỡ.
- **`/owners/{slug}` chưa nhận `venue_type`**, nên chip loại hình trong tab kho
  của trang hồ sơ là một bộ lọc máy chủ bỏ qua.
- **Chưa ai xem bảy trang bằng mắt.** Mọi khẳng định của 273 chốt là về HTML và
  tên class; không chốt nào chạy layout.

> **Cập nhật cuối ngày 13/10/2026.** `main` ở `0aed199`, CI **960 ca / 3.565
> khẳng định** (run `38412388041`), mọi lượt deploy thành công, không còn PR nào
> mở.
>
> **Mười PR trong một ngày**, tất cả đã lên production: #74 (tài liệu) · #75
> (bốn món nợ kỹ thuật) · #76 (quyền `planner`) · #77 (tài liệu) · #78 + #79
> (gom phân quyền thành viên, hai bên) · #80 (gom "nghĩa vụ tiền") · #81 (tài
> liệu) · #83 (**thực thi** giới hạn theo mạng lưới) · #82 (hai nhãn quyền còn
> thiếu ở bảng quyền). CI đi từ 903 lên **960** ca — 25 ca của #83, 2 ca của #82.
>
> Nhóm B xong cả năm món. Ba món nó mở ra: hai đã làm luôn trong ngày (#78/#79
> và #80), còn **`MAIL_MAILER`** — món duy nhất cần quyền vào VPS. Nhóm A, C, D
> còn nguyên.
>
> **Bốn câu hỏi từng lặp nhiều chỗ nay mỗi câu có đúng một chỗ trả lời:**
>
> | Câu hỏi | Chỗ duy nhất |
> |---|---|
> | dòng nào tính vào báo cáo phát sóng | `BookingLine::REPORT_STATUSES` |
> | dòng nào mang nghĩa vụ trả tiền | `BookingLine::OBLIGATION_STATUSES` |
> | ai là thành viên của tenant, tenant còn hoạt động không | `OrganizationUser::thanhVien()` · `TenantPermission` |
> | ai bị giới hạn theo mạng lưới, và vào những mạng lưới nào | `OwnerUser::ROLES_GIOI_HAN_NETWORK` · `OwnerUser::networkDuocPhep()` |
>
> **Một thứ đáng để ý về thứ tự merge:** #83 lên `main` trước #82 (13:42 so với
> 14:19), nên "Risks remaining" số 1 của #82 — *"giới hạn theo mạng lưới vẫn là
> ô cấu hình không tác dụng"* — **đã sai ngay lúc nó được merge**. Thân PR là
> bản ghi lịch sử nên không sửa; ghi ở đây để người đọc lại không tin câu đó.

**A. Chặn bởi quyết định nghiệp vụ, không chặn bởi code.** Sáu câu ở mục "Bảy câu
hỏi nghiệp vụ" vẫn chưa có câu trả lời: hạn giữ chỗ (1.1), chính sách hủy/hoàn
tiền (1.5), một owner đủ tiền hay tất cả (1.4), owner tự xác nhận đã nhận tiền
(1.4), chia tiền gói nhiều owner (1.2), và tỷ giá cho giá ngoài VND. Viết code
cho những mục này trước khi có câu trả lời là tự đặt ra chính sách.

**B. ~~Làm được ngay, không chặn bởi gì~~ — XONG CẢ NĂM, 13/10/2026.**

Bốn món ở PR #75, món quyền `planner` ở PR #76 sau khi chủ dự án quyết. Bảng
dưới giữ nguyên hiện trạng đã kiểm, thêm cột kết quả — vì thứ đáng đọc lại
không phải "đã xong" mà là **mỗi món hóa ra là gì khi mở ra**.

| Món | Hiện trạng đã kiểm | Kết quả |
|---|---|---|
| `CampaignReportService` | ba chỗ lọc `booking_lines`, **hai** danh sách: `['active','completed']` (dòng 16, 67) và `['active','completed','approved']` (dòng 108) | Về `BookingLine::REPORT_STATUSES`. Hệ quả người mua thấy được: bảng chi tiết liệt kê 2 màn hình, ô "Tổng màn hình" nói **1** — ca test in đúng `1 vs 2` khi dựng lại lỗi cũ. Chốt là một **bất biến** (ô tổng = tổng của bảng), không phải một danh sách ghim cứng |
| `REPORTABLE_STATUSES` | hằng nằm trên `Api/V2/CampaignReportController`, mà `Buyer/BuyerReportController:27` đọc sang | Về `Campaign::REPORTABLE_STATUSES`. Kèm một lỗi chữ: câu 404 của trang Blade thiếu `paused`, nên nó mâu thuẫn với chính danh sách nó gác |
| Quyền sửa tổ chức của `planner` | `OrganizationPolicy` docblock ghi rõ: `planner` **mất** một quyền đang có (#55) | **Trả lại** (quyết 13/10). Không thêm `manage_team` cho `planner` mà tách `manage_org_profile`: `manage_team` trả lời câu "ai quản lý thành viên", và gộp hai câu vào một quyền chính là nguyên nhân nó mất quyền — không phải một quyết định |
| Thông báo khi hủy dòng | không có email/in-app; owner phải tự mở trang mới thấy nghĩa vụ hoàn tiền | `RefundObligationNotification`, gửi qua `DB::afterCommit()` khi nghĩa vụ sinh ra **hoặc tăng**. Khoản `pending` **vẫn chưa có hạn xử lý** — phần đó còn mở |
| Người đã đồng ý bản chính sách nháp | không có thông báo khi bản đó được ban hành | Lệnh `policies:consents-before-effective` đếm họ — **chỉ đọc**. Chưa gửi gì: thông báo hay đòi lại chấp thuận là quyết định pháp lý. **Chưa chạy trên production** |

**Ba thứ mở ra từ chính lượt sửa này — hai đã làm trong ngày, một còn mở:**

1. ~~**`manage_team` nay không được kiểm ở đâu cả.**~~ **XONG 13/10** (#78, và
   #79 cho bên owner). `OrganizationUserPolicy` gác việc mời/gỡ người bằng cách
   so `role === 'admin'` **trực tiếp**, không qua `PERMISSIONS`.

   Mở ra thì món này không chỉ là đổi một phép so: có **hai** cách hỏi "ai là
   thành viên tổ chức này", khác nhau cả kiểu trả về lẫn việc có kiểm trạng
   thái tổ chức hay không — `OrganizationPolicy::membership()` đòi tổ chức còn
   `active`, `OrganizationUserPolicy::actorRole()` không. Nên #78 gom cả phép
   tìm thành viên về `OrganizationUser::thanhVien()`.

   Bên media owner là cùng lỗi, và ở đó **chỗ tập trung đã có sẵn**:
   `TenantPermission` đã làm đủ ba việc (super_admin đi trước, owner phải còn
   `active`, đọc `PERMISSIONS`), policy chỉ chưa dùng nó. Nên #79 là bỏ bản
   chép, không thêm tầng.

   Hệ quả thật của lỗi cũ, không phải lo xa: thêm một vai trò có `manage_team`
   / `manage_users` sẽ **không** cho nó mời ai, trong khi trang
   `PermissionMatrix` vẫn in ra rằng nó quản lý được thành viên.

2. ~~**Sáu chỗ viết cứng `['approved','active','completed']`**~~ **XONG 13/10**
   (#80) → `BookingLine::OBLIGATION_STATUSES`.

   Thứ đáng ghi: mã **đã biết** đây là một định nghĩa và đã viết ra điều đó.
   `openCostFor()` ghi "Một định nghĩa duy nhất… hai định nghĩa lệch nhau ở đây
   là hai con số tiền khác nhau"; `ownersInCampaign()` ghi "Lọc `status` giống
   hệt bên đó". Hai lời hứa chỉ được giữ bằng việc ai đó nhớ ra.

   Danh sách giữ **nguyên từng phần tử** — không thêm `paused` dù
   `REPORT_STATUSES` có nó. Chỗ lệch là có chủ ý: ở báo cáo, thêm `paused` là
   phòng ngừa và đoán sai thì một con số trên trang sai; ở đường tiền, thêm nó
   là đổi số tiền người mua phải chuyển.

3. **`.env` của bản làm việc có `MAIL_MAILER=log`** — **CÒN MỞ, và là món duy
   nhất trong ba món này tôi không chạm được.** Kèm `MAIL_FROM_ADDRESS` là
   `hello@example.com` và không có thông tin SMTP. Nếu máy chủ cũng vậy thì thư
   nghĩa vụ hoàn tiền — **và cả hai thông báo đã có từ trước**
   (`BookingSubmittedNotification`, `BookingResolvedNotification`) — chỉ ghi vào
   log, không tới ai. Tôi không có quyền vào VPS nên không kiểm được `.env`
   thật. Đây là món đáng kiểm trước nhất: nó quyết định việc món thứ tư ở bảng
   trên có tác dụng hay không.

**Và hai món mới, mở ra từ hai PR phân quyền — món 1 đã đóng cuối ngày:**

1. ~~**`OwnerUser::canAccessNetwork()` so vai trò bằng tên**
   (`in_array($this->role, ['owner','manager'])`). Cùng hình dạng với món đã
   sửa, nhưng nó trả lời câu **khác** — "ai thấy được mọi network" — và
   `PERMISSIONS` không có quyền nào cho câu đó. Thêm một quyền mới là quyết
   định phân quyền, không phải một lần sửa.~~

   **XONG 13/10/2026** (#82 tìm ra, #83 làm) — nhưng **không** bằng cách thêm
   quyền. Hàm đó hóa ra **không có chỗ gọi nào**, và cái sau nó là một ô cấu
   hình không thực thi gì. Chi tiết ở mục cuối tài liệu này.
2. **Câu `paused` nay nằm ở hai chỗ khác nhau** giữa `REPORT_STATUSES` và
   `OBLIGATION_STATUSES`. Docblock của cả hai hằng nói rõ chỗ lệch và lý do,
   nhưng người làm tính năng tạm dừng dòng đặt chỗ phải quyết **cả hai cùng
   lúc** — và hôm nay chưa chỗ nào trong mã đặt `paused` cho một dòng, nên chưa
   ai bị chặn bởi việc chưa quyết.

**C. Cần người thứ hai đọc.** Mục 3 của "Đề xuất làm gì ngay" vẫn mở: cho Codex
review khối từ R41 tới nay — ~~173~~ ~~176~~ **183** commit (đo lại trên `main` ở `8065f6f`, ngày 17/10 sau #86–#92):

```
git rev-list --count 4887b0a..main
```

`4887b0a` là commit đóng vòng review 30/09 ("21/21 finding đã đóng"), nên nó là
mốc đúng cho câu "từ vòng đó tới nay".

**Sửa cách đếm, và vì sao nó từng sai.** Hai con số trước (160, rồi 163) đo bằng
`--count --since=2026-09-30 HEAD` **trong khi đang đứng trên nhánh làm việc**,
nên chúng cộng cả commit chưa merge của chính nhánh đó — đo lại trên `main` thì
cùng lệnh cho 160, không phải 163. Mốc **ngày** còn một chỗ dở nữa: nó bỏ các
commit ngày 29/09 nằm sau vòng review. Mốc **commit** không có cả hai vấn đề và
cho cùng kết quả bất kể ai chạy ở đâu.

**D. Hoãn có chủ ý.** Giai đoạn 8 (Next.js cho khu người mua) vẫn hoãn, chặn bởi
"có thêm người". Nhưng nay nó **rẻ hơn hẳn** so với lúc viết: 11 trang khu người
mua đã đọc toàn bộ dữ liệu qua `/api/v2`, nên việc còn lại là đổi tầng vẽ, không
phải gỡ truy vấn ra khỏi view.

**Một thứ đã nói với chủ dự án và chưa quyết:** từ vựng tiếng Anh trên trang
người mua ("Campaign", "Plan", "Budget", "Brand") giữ nguyên có chủ ý. Đó là
quyết định đặt tên sản phẩm, không phải việc chuyển đổi — nên tôi không tự đổi.

---

## Mục mới cuối ngày 13/10/2026 — hàng rào mạng lưới nay có thực thi

Món số 1 của "hai món mới" bên trên đã đóng, nhưng **không theo cách nó được
mô tả**. Ghi lại ở đây vì chỗ lệch mới là phần đáng đọc.

Mục đó nói: `canAccessNetwork()` so vai trò bằng tên, nên hãy cho nó một quyền.
PR #82 mở ra thì thấy hàm đó **không có chỗ gọi nào** — nên một quyền thêm vào
đó sẽ gác hư không. Và phát hiện lớn hơn: `allowed_network_ids` được bốn biểu
mẫu Filament thu thập, bảng in ra *"N restricted"*, lời mời mang theo — **và
không chỗ nào thực thi**. Owner giới hạn một thành viên vào một mạng lưới thì
thấy "1 restricted", còn thành viên đó vẫn thấy tất cả. Lựa chọn là **thực thi
thật** hay **gỡ cái UI đang hứa hẹn**; chủ dự án chọn thực thi, và PR #83 làm.

### Ba tầng, vì một tầng không đủ

| Tầng | Ở đâu | Canh cái gì |
|---|---|---|
| Đọc theo danh sách | `HasOwnerScope` → `locTheoNetwork()` của **5** model | mọi truy vấn kho đi qua scope: bảng, ô chọn, đếm, resolve bản ghi |
| Đọc theo bản ghi | `NetworkPolicy` · `SitePolicy` · `ScreenPolicy` | bản ghi lấy bằng `withoutGlobalScope` — repo này dùng ở hơn 30 chỗ, nên scope một mình là lời hứa hở |
| Ghi | `Site::saving()` | scope nói được "thấy gì", không nói được "đặt kho vào đâu". Đặt ở model vì `sites.network_id` được ghi từ **bốn** đường: form Filament, `/api/v1/sites`, `SiteImportService`, `ScreenWriter` |

Câu trả lời "ai bị giới hạn" gom về **một chỗ**: `OwnerUser::networkDuocPhep()`,
và lời giải bộ nhớ theo người ở `App\Services\GioiHanNetwork` (bind `scoped()`,
không `singleton()` — một thực thể dùng chung sẽ trả lời bằng giới hạn của
người trước). `['operator','read_only']` từng có **mười** bản chép trong mã nay
còn đúng một: hằng `OwnerUser::ROLES_GIOI_HAN_NETWORK`.

Hình dạng hàng rào **khác nhau theo bảng**, và mỗi chỗ lệch là một quyết định:

| Model | Lọc theo | Khi không biết |
|---|---|---|
| `Network` | chính `id` | — |
| `Site` | `network_id` | `network_id` null → **ngoài** hàng rào |
| `Screen` | `site_id` thuộc địa điểm trong hàng rào (truy vấn con, **không** `whereHas('site')` — nó lồng hàng rào vào trong chính nó) | theo địa điểm |
| `Product` | `network_id` trong danh sách, **hoặc** `network_id` null + `site_id` thuộc địa điểm trong hàng rào | cả hai null → **ngoài** |
| `ScreenImport` | bảng không có đường nối tới mạng lưới → đổi hình dạng: chỉ lượt nhập **của chính mình** | — |

Nguyên tắc đã ghi vào docblock: **hàng rào phải đóng khi không biết, không mở.**

### Phạm vi: chỉ kho, và vì sao chỉ kho là đủ

Không đoán, mà đọc ra từ dữ liệu: ô `allowed_network_ids` **chỉ** hiện cho hai
vai trò `operator` và `read_only`, và `PERMISSIONS` của đúng hai vai trò đó chỉ
có `manage_inventory`, `import_inventory`, `view_inventory`. Nên hàng rào quanh
kho phủ hết những gì họ chạm tới được.

`reporting_only` và `sales_manager` đọc số doanh thu / báo cáo ở mức **cả media
owner**, không tách được theo mạng lưới — fence hai vai trò đó sẽ là một lời hứa
nửa vời, nên **không làm**, và ghi ở đây thay vì làm im lặng.

### Hai lỗi kề bên, sửa cùng PR vì hàng rào thật làm chúng thành lỗi

- **Thẻ "N restricted" nói sai.** Cả ba chỗ đọc thẳng cột thô, nên một hàng
  `manager` còn dữ liệu cũ trong `allowed_network_ids` bị in là "bị giới hạn"
  trong khi nó **không** bị fence. Trước đây vô hại; nay là một câu sai về một
  chốt đang có hiệu lực. Ba thẻ nay hỏi `networkDuocPhep()`.
- **`UserInvitationService`** — một trong mười bản chép, và là đường **ghi** hàng
  pivot thật khi người ta nhận lời mời.

### Hai cái chốt mới, và một ca test từng chỉ sai thủ phạm

`abstract protected static function locTheoNetwork()` trên trait làm PHP từ chối
nạp class nếu một model dùng `HasOwnerScope` mà quên khai — một bảo đảm ở thời
điểm nạp, không phải ở thời điểm chạy. Nhưng nó **không** bắt được một thân hàm
**rỗng**, nên có thêm ca tự tìm phạm vi: duyệt mọi model dùng trait và đòi SQL
sinh ra phải **khác** giữa người bị giới hạn và người không.

Bản đầu của ca đó dò trait bằng cách tìm chuỗi `"HasOwnerScope"` trong tệp, nên
nó khớp cả docblock của `OwnerUser` **bàn về** trait — và đi đỏ với tên một model
không liên quan. Đã đổi sang `class_uses_recursive()`. Bài học cùng họ với sáu lỗ
bên trên: **một phép dò nhận cả lời bàn về mã sẽ gọi tên sai thủ phạm.**

### Một lỗi tôi tự gây và CI bắt được

Kiểu hẹp `?Authenticatable` trên tham số đầu của `GioiHanNetwork::cho()` làm **ba
ca Api đỏ** với `TypeError`: `ApiClient extends Model` và **không** implement
`Authenticatable`, nhưng `auth()->user()` vẫn trả về nó dưới token guard. Tức mọi
lượt ghi `Site` bằng token đối tác sẽ thành 500 trên `/api/v1` — đúng thứ CLAUDE.md
mục 1 cấm. Đã đổi thành `mixed`, ghi lý do tại chỗ, và thêm ca
`test_token_doi_tac_khong_lam_no_hang_rao`.

### Còn mở, có chủ ý

1. **`ImportScreensJob` không chạm được hàng rào ghi.** Job không có người dùng
   đăng nhập nên `Site::saving` không thấy giới hạn nào. Hôm nay **không ai tới
   được**: trang nhập kho chỉ có ở panel `/admin`, mà `canAccessPanel` giới hạn
   cho `super_admin`. Nhưng trước khi panel publisher có trang nhập, hàng rào
   phải được **truyền tường minh** vào job.
2. **`current_owner_id` cũ** — lỗ có từ trước, không do PR này: người bị gỡ khỏi
   tenant mà phiên còn giữ `current_owner_id` cũ.
3. **Vai trò đọc báo cáo không fence** — xem mục phạm vi bên trên.

### Dữ liệu production đã đổi hành vi ngay khi deploy

Đây là một chốt **đang có hiệu lực** trên dữ liệu cũ: mọi hàng pivot
`operator`/`read_only` đang mang `allowed_network_ids` khác rỗng vừa bị thu hẹp
tầm nhìn kho — trước đây ô đó không làm gì. Câu truy vấn để biết hàng nào:

```sql
SELECT ou.owner_id, ou.user_id, ou.role, ou.allowed_network_ids
FROM owner_users ou
WHERE ou.allowed_network_ids IS NOT NULL
  AND JSON_LENGTH(ou.allowed_network_ids) > 0
  AND ou.role IN ('operator', 'read_only');
```

Nếu có hàng nào là dữ liệu cũ bỏ sót chứ không phải chủ ý, bỏ tích hết ô đó là
trả lại "tất cả" — biểu mẫu ghi `[]`, và `[]` nghĩa là tất cả, đúng như nhãn
*"để trống = tất cả"* nói.

---

## Ngày 18/10/2026 — mười hai PR, và bốn lần cùng một hình dạng lỗi

`main` ở `0f6617e`. CI **1.016 ca / 3.901 khẳng định** (sáng nay là 1.000 / 3.804),
`test:js` **190/190**, ba bộ thử shell **102 + 10 + 7**. Không còn PR nào mở.

Ngày bắt đầu bằng việc dựng nốt vài khối giao diện và kết thúc bằng một chuỗi
sự cố nguồn ảnh nền bản đồ. Phần đáng ghi lại không phải từng sự cố, mà là
chuyện **cả bốn đều cùng một hình dạng**.

### Phần đầu ngày — giao diện (#93 → #98)

| PR | Việc |
|---|---|
| #93 | Cập nhật lộ trình cho bảy lát |
| #94 | Ba khối lời hứa dựng lại **nguyên văn** bản Blade, theo quyết định của chủ dự án |
| #95 → #96 | Ô "3.2×": tải ảnh Unsplash về, rồi **đảo lại** thành hotlink đúng như bản cũ |
| #97 | Bốn thẻ WORKFLOW trả lại nguyên văn |
| #98 | Hai lỗi bản đồ thật: `/map` **không hiện pin nào**, và pin trang chủ là **ảnh vỡ** |

#98 tìm ra bằng Chrome, không bằng test: `a.markerClusterGroup is not a function`
vì `await import('leaflet')` trả về namespace ESM chứ không phải `L` — thiếu
`.default` ở sáu chỗ. Pin trang chủ hỏng vì Leaflet đi tìm `marker-icon.png`
theo đường dẫn tương đối; sửa bằng `divIcon`.

Cùng lúc đó phát hiện tile OSM trả **HTTP 503**, 20/20 ô, từ mạng Việt Nam.

### Bốn lần nguồn tile hỏng, và vì sao không lần nào có một dòng lỗi

| # | Nguồn | Hỏng thế nào | Vì sao không ai thấy |
|---|---|---|---|
| 1 | `tile.openstreetmap.org` | HTTP 503 mọi ô | Nền xám; pin, cụm, nút vẫn đúng. Sống nhiều ngày |
| 2 | `basemaps.cartocdn.com` | **HTTP 200** + PNG 2.049 byte "API KEY REQUIRED" | Mã trạng thái đúng, kiểu nội dung đúng, `tileerror` không bắn |
| 3 | MapTiler, khóa chưa tới máy chủ | `deploy.sh` chạy **bản cũ của chính nó** | Deploy báo `success`, in đủ mười một bước |
| 4 | MapTiler, style rỗng | URL `maps//{z}/…` → 503 mọi ô | **Dự phòng Esri nhảy vào**, trang trông hoàn hảo |

Điểm chung: bản đồ **trông như đang chạy**. Không log nào đỏ, không ngoại lệ
nào ném ra, không chốt nào vỡ. Lần 2 lên production và nằm đó cho tới khi có
người mở trang ra nhìn; lần 4 thì ngay cả nhìn cũng không thấy, vì lớp dự
phòng đã vá chỗ thủng — nó vừa cứu trang, vừa giấu lỗi cấu hình.

### Số đo, cùng một ô ở trung tâm Hà Nội (z13), cùng một mạng

| Nguồn | Kết quả | Nhãn địa danh |
|---|---|---|
| MapTiler `streets-v2` | 200, **82.078 byte** | **tiếng Anh** — "West Lake", "Red River" |
| Esri `World_Street_Map` | 200, **27.862 byte** | **tiếng Việt** — "Hồ Tây", "Phường Tứ Liên" |
| Carto `voyager` | 200, **2.049 byte** watermark | — |
| Wikimedia | 403 | — |
| OSM | không nối được | — |

MapTiler **không đổi được ngôn ngữ nhãn** cho raster: `?language=vi` và
`?lang=vi` đều trả đúng 82.078 byte như khi không có tham số. Chủ dự án chấp
nhận nhãn tiếng Anh (18/10). Muốn tiếng Việt thì tạo style riêng trong MapTiler
Cloud, đặt ngôn ngữ nhãn là Vietnamese, rồi `MAPTILER_STYLE=<tên style>` — một
biến môi trường, không phải một lần sửa mã.

### Nguồn tile sống ở đâu, và đường đi của khóa

```
GitHub Secret MAPTILER_KEY
  → envs: của appleboy/ssh-action        (.github/workflows/deploy.yml)
  → deploy.sh bước 3b ghi vào .env
  → bước 8 config:cache                   → 9 view Filament
  → bước 10 build Next (NEXT_PUBLIC_*)    → 3 bản đồ trang công khai
```

`config/map.php` (Blade) và `webapp/lib/ban-do.ts` (Next) giữ **hai bản sao**
của cùng giá trị — đánh đổi có ý thức, vì Next không đọc được config PHP và
kéo một chuỗi hằng qua một lời gọi API là thêm một vòng mạng. Chỗ trôi bịt
bằng một chốt đọc **cả hai tệp** và quy chúng về cùng một dạng trước khi so.

Có khóa thì dùng MapTiler, không khóa thì Esri. Nhờ vậy máy lập trình và CI
chạy được mà không cần khóa, và một khóa gõ sai ở production cho bản đồ Esri
chứ không cho nền xám.

### Ba lớp canh mới, mỗi lớp sinh ra từ một lỗi cụ thể

| Lớp | Sinh ra vì | Canh cái gì |
|---|---|---|
| `php artisan map:kiem-tile` | Carto trả 200 kèm rác | Tải một ô đã biết rồi **cân nó**, vì mã trạng thái nói dối được. Chạy mỗi 6 giờ qua scheduler |
| `tests/shell/thu-nap-lai.sh` | `deploy.sh` chạy bản cũ của chính nó | Script phải `exec` lại khi vừa tự ghi đè — và **không** được exec khi không đổi (vòng lặp vô hạn trên máy chủ) |
| `tests/shell/thu-dat-env.sh` | `dat_env()` là chỗ **duy nhất** ghi đè `.env` production | Cùng tệp đó giữ `APP_KEY` và `DB_PASSWORD` — hai thứ không lấy lại được từ repo |

Cả ba đều trích hàm/khối **thật** ra khỏi script, không chép lại, và cả ba đều
đã mutation-test.

### Bốn cái bẫy ngôn ngữ, ghi lại để không đạp lại

1. **`??` không bắt chuỗi rỗng.** `process.env.X ?? 'mặc định'` giữ nguyên
   `''`, và URL thành `maps//{z}/…`. Dùng `||`. Phía PHP có đúng cái bẫy đó
   với tham số mặc định của `env()` — dùng `?:`.
2. **Bash đọc script đang chạy từ đĩa.** Một script tự cập nhật mình giữa
   chừng là một canh bạc về offset byte.
3. **`[ -n "$X" ] && export X` dưới `set -e` giết script** khi `X` rỗng. Viết
   ra trong lúc sửa lỗi #4 và suýt thành lỗi #5 — deploy sẽ chết giữa lúc site
   đang ở maintenance mode. `bash -n` không bắt được. Dùng `if`.
4. **`replace()` của JS diễn giải `$'` và `$&` trong chuỗi thay thế**, và
   trong một template literal thì `\s` là `s`. Hai lần viết hỏng chốt bằng
   đúng hai chuyện này; chốt vẫn **chạy**, chỉ khớp sai.

### Khóa MapTiler: công khai theo thiết kế

Khóa nằm trong JS gửi cho mọi khách, vì chính trình duyệt gọi URL tile — mọi
khóa tile client-side đều vậy. Thứ bảo vệ hạn mức không phải sự bí mật mà là
**giới hạn origin** trong MapTiler Cloud, đã bật cho `oohx.net` và `*.oohx.net`
(18/10). Đo lại sau khi bật:

| Gửi gì | Kết quả |
|---|---|
| Không `Referer`/`Origin` | **403** kèm ảnh "Invalid key" 10.260 byte |
| `Referer: https://oohx.net/` | 200 |
| `Referer: https://dash.oohx.net/` | 200 |
| `Referer` tên miền lạ | **403** |

Hai hệ quả:

- `map:kiem-tile` chạy từ dòng lệnh **không gửi header nào**, nên nó phải tự
  gửi `Referer` lấy từ `config('app.url')` — nếu không, nó báo HỎNG cho một
  nguồn đang phục vụ khách bình thường, và một phép kiểm báo oan sẽ bị tắt.
- Ảnh 403 là một PNG **hợp lệ**, nên `<img>` vẫn vẽ nó và `tileerror` không
  bắn. Khi hạn mức 100.000/tháng cạn giữa tháng, mười hai bản đồ sẽ hiện chữ
  "Invalid key" mà lớp dự phòng **không** cứu. Đó chính là kịch bản
  `map:kiem-tile` sinh ra để bắt.

### Còn mở sau ngày 18/10

| Việc | Chặn bởi |
|---|---|
| **Kênh cảnh báo cho `map:kiem-tile`** | Chủ dự án chọn. `onFailure` ghi mức `error`, nhưng dự án **không có Sentry, Slack hay bất kỳ kênh nào** — nên dòng đó chưa đánh thức ai. Hai lối rẻ: gửi mail khi đỏ, hoặc một widget trên `/admin` |
| **Xác nhận `schedule:run` có trong crontab của VPS** | Cần người vào máy chủ. Không có thì mục lịch nằm im và không ai biết |
| **Khối tự-nạp-lại chưa chạy thật lần nào** | Nó mới chỉ xanh trong 7 ca của bộ thử. Lần deploy đầu tiên sau một thay đổi `deploy.sh` nữa sẽ là lần nó chứng minh được mình |
| **Chín view Filament không có dự phòng tile** | Có chủ ý: thêm logic vào chín chỗ là thêm chín chỗ có thể sai. Một nguồn chết = nền xám trong khu quản trị |
| **Ngưỡng 8.000 byte của `map:kiem-tile` là số chọn tay** | Nằm giữa 27.862 và 2.049. Ảnh "Invalid key" của MapTiler **nặng 10.260 byte**, tức vượt ngưỡng — lệnh bắt được nó nhờ kiểm mã trạng thái 403 **trước**, không nhờ cân. Nếu MapTiler đổi sang trả 200 như Carto từng làm thì lệnh sẽ nói "ok" |
| **`Referrer-Policy: no-referrer`** | Chưa host nào đặt. Nếu việc *"chặn referrer cho ảnh Unsplash"* được làm bằng cách đặt header đó cho **toàn site** thì bản đồ MapTiler chết. Khi tới việc đó: chỉ đặt trên thẻ `<img>` của Unsplash |
## Chiều 18/10/2026 — responsive, thang chữ, rồi bố cục trang chủ (#107 → #118)

`main` ở `e975dd7`. CI **1.037 ca / 3.973 khẳng định** (sáng nay 1.016 / 3.901),
`test:js` **207/207** (190 sáng nay), `test:khoi` **330 khẳng định trên 10
trang**, `test:seo` **16 trang**. Không còn PR nào mở.

Buổi sáng dựng lại giao diện cũ và chữa nguồn tile. Buổi chiều là ba việc khác
hẳn nhau, theo đúng thứ tự chủ dự án nêu: trang **vỡ** trên điện thoại → cỡ
chữ **không theo tỉ lệ** → các khối **không nối vào nhau**. Mỗi câu là một
tầng sâu hơn câu trước, và lần cuối mới là câu về sản phẩm chứ không về CSS.

### Phần một — responsive (#107 → #111, #113, #114)

| PR | Việc | Đo được |
|---|---|---|
| #107 | Lát 1: padding ngang ở năm chỗ | 0 → 22/32/52px |
| #108 | Lát 3: `screen_type_label`, ẩn hàng rỗng | `lcd` → `LCD` |
| #109 | Hai lưới "Loại địa điểm" + "Tỉnh thành" có ảnh | — |
| #110 | Lát 5: `next/image` + `next/font` | ảnh **10,68 → 0,23 MB** (−97,9%) |
| #111 | Lát 4+6: vùng chạm 44px, nén chiều dọc | 37 vùng dưới chuẩn → **0**; mobile 12,4 → 11,4 màn |
| #113, #114 | Hai lỗi ảnh 400 trên production | — |

**Lát 2 bị bỏ khỏi kế hoạch vì nó không tồn tại.** Tôi báo "khoảng hở ở
1024px" dựa trên một phép đo bằng iframe: iframe có thanh cuộn nên
`innerWidth` bên trong lệch một bậc breakpoint so với cửa sổ thật. Không có
khoảng hở nào.

Lỗi đo đó quay lại ám cả buổi chiều, và lần thứ hai nó đi theo chiều ngược:
ở #116 tôi "sửa" phép đo sang `documentElement.clientWidth` và đọc sai mốc
767/768. **Media query đi theo `innerWidth`** — bề ngang CÓ cả thanh cuộn.
Đo thẳng điều đó trước khi tin bất cứ con số nào khác:

| Khung iframe | `innerWidth` | `clientWidth` | `(max-width:767px)` |
|---|---|---|---|
| 767px | 767 | 752 | **khớp** |
| 768px | 768 | 753 | không |
| 782px | 782 | **767** | không |

Hai lỗi đo khác cùng loại, cũng của tôi: *"8/10 ảnh hỏng"* (`loading="lazy"`
không kích hoạt trong iframe — lặp lại ba lần), và lần đầu viết
`next-image.test.mjs` thì chốt đỏ vì regex bắt phải **câu trích dẫn dòng cũ
nằm trong chú thích**. Một chốt bắt lỗi trong lời kể về lỗi là một chốt cấm
người ta ghi lại bài học.

### Phần hai — một thang chữ tám nấc (#112)

Chủ dự án nói "font chưa chuẩn" và làm rõ: **cỡ chữ không theo tỉ lệ giữa
heading và mô tả**. Đo thì `<h1>` của năm trang ra **năm cỡ khác nhau**:
18, 28, 30, 44, 80px — và 30px là `2em` mặc định của trình duyệt, vì
stylesheet **chưa bao giờ có luật cho `h1`–`h3` trần**.

```
--fs-hero  clamp(36px, 5.5vw, 64px)      --fs-4  clamp(16px, 1.2vw, 18px)
--fs-1     clamp(28px, 3.4vw, 44px)      --fs-5  15px
--fs-2     clamp(22px, 2.4vw, 32px)      --fs-6  13px
--fs-3     clamp(18px, 1.6vw, 22px)      --fs-7  11px
```

Mười tám class tiêu đề quy về tám token, và `h1/h2/h3` trần có mức nền để
chỗ quên gắn class cũng rơi đúng thang. Chủ dự án chốt **"dựng thang mới"**,
chấp nhận trang đổi diện mạo.

### Phần ba — bố cục trang chủ (#115 → #117)

Đây là câu hỏi sản phẩm, không phải câu hỏi CSS: *"các khối trang chủ nên gồm
những gì và sắp xếp ra sao để người mua và người bán đều browse được?"*

Khảo sát trước khi đụng code tìm ra **ba đứt gãy**: ba lối vào kho nằm rời
nhau, hai cách mua cách nhau **bốn khối**, và mục mời media owner chen vào
giữa luồng của người mua. Chủ dự án duyệt phương án C (gom theo nhiệm vụ) qua
hai bản HTML mock, rồi chốt triển khai.

| PR | Lát | Việc |
|---|---|---|
| #115 | 1 | Thanh đầu thêm "Gói chiến dịch"; chân trang tách "Người mua" / "Media owner" |
| #116 | 2 | Hero hai cột: chữ + ô tìm kiếm trái, **bản đồ thật** phải |
| #117 | 3 | Thân trang: **9 khối → 6**, theo thứ tự người mua hỏi |

Thứ tự mới: hero → duyệt kho (hai tab) → hai cách mua → bốn bước → media
owner + ba bảo đảm → mời lên sàn. Khối cho người bán nay đứng **cuối**, không
chen giữa.

Đo ở stub: **5.541 → 3.712px** ở 1440 (−33%), **6.378 → 4.931px** ở 390
(−23%).

Ba khối gỡ, ba lý do khác nhau:

- **"Vị trí nổi bật"** — `getFeaturedScreens()` đòi ảnh RIÊNG + có giá, và
  **1 trên 104** màn hình thoả cả hai, nên mục này luôn ra đúng một thẻ. Lời
  gọi `/screens/featured` nuôi nó cũng đi: tám endpoint còn bảy.
- **"Gói chiến dịch"** — cùng hình dạng, một lưới cho một gói.
- **"Marketplace OOH đầu tiên có AI"** — chủ dự án chốt bỏ, **đảo** quyết
  định dựng nguyên văn của chính sáng hôm đó (#94), và kéo theo tấm ảnh
  hotlink Unsplash đã chốt ba lần (#95 tự host → #96 hotlink). Trang chủ nay
  không còn phụ thuộc CDN bên thứ ba lúc chạy.

Bản đồ lên hero **không tốn thêm một byte nào**: `useEffect` của
`BanDoTrangChu` chạy ngay khi component gắn vào, không chờ cuộn, nên mọi lượt
vào trang chủ đã tải Leaflet và tile từ trước. Tôi đã hỏi chủ dự án chọn "bản
đồ sống ~1,5–2 MB" hay "ảnh tĩnh" — **câu hỏi đó dựa trên một tiền đề sai**.

### Hai lỗi lát 3 làm lộ ra, và một lớp canh mới cho mỗi lỗi

**Va tên class.** `.tabs`/`.tab` thêm cho khối duyệt kho trùng với thanh tab
gạch chân của trang chi tiết (dòng 1533). Cùng độ cụ thể nên luật sau thắng:
thanh mới nhận `border-bottom` của thanh cũ, giữ `border-radius` của mình, và
vẽ một đường kẻ chạy ngang dưới hai viên.

`next build` xanh. `test:js` xanh. `test:khoi` xanh — nó hỏi *"class này có
trong HTML không"*, không hỏi *"class này ra hình gì"*. **Chỉ nhìn ảnh chụp
mới thấy.**

Lớp canh mới trong `class-css.test.mjs`: hai **định nghĩa gốc** của cùng một
tên cách nhau hơn 120 dòng là lỗi. Nó tìm ra **năm chỗ đã trùng từ trước** —
ba cố ý (khối thang chữ #112), hai là lỗi thật (`.loc-item`, `.mpop-price`,
mỗi cái hai định nghĩa khác hẳn nhau, cái sau thắng). Năm chỗ đó vào **danh
sách nợ** khoá theo tên, không khoá theo số dòng.

**Thứ tự khối không ai canh.** `co` chỉ hỏi "khối có mặt không" — đảo chỗ hai
mục thì mọi khẳng định vẫn xanh, trong khi thứ tự **là** toàn bộ nội dung của
lát 3. Thêm khoá `thuTu` vào `khoi-giao-dien.mjs`.

Kèm theo: `formatProductPrice()` in thẳng mã enum — `5.000.000 ₫/week` trên
một trang mà CLAUDE.md quy định tiếng Việt là mặc định. Trước lát 3 nó chỉ
xuất hiện ở `/products`; lát 3 đưa nó lên trang chủ.

### Một lần báo động sai, và cái chốt sinh ra từ nó (#118)

Sau khi #117 deploy, trang chủ production vẫn là bản cũ. Tôi gọi đó là *"lần
thứ tám trong ngày của đúng kiểu hỏng này"* sau **bốn phút** chờ.

Không có lỗi nào. Chẩn đoán qua workflow cho thấy:

```
tệp prerender trên đĩa  : dk-tabs=1, khối AI=0, ghi lúc 20:15:25   ← bản mới
tiến trình trả về 20:47 : dk-tabs=0, khối AI=1, 126.490 byte       ← bản cũ
tiến trình trả về 20:52 : dk-tabs=1,            112.177 byte       ← đã tự đổi
.next/cache             : thư mục mang dấu 06/10 — build KHÔNG xoá
```

Build ghi đúng, service khởi động đúng, và bản cache cũ được phục vụ thêm vài
phút rồi tự thay. **Bảy lần hỏng thật trước đó làm tôi nghiêng về kết luận
sai ở lần thứ tám.**

Việc đo được làm qua `chan-doan-proxy.yml` vì tôi không có SSH — và chính nó
cũng hỏng theo kiểu nó được dựng để chống: `script_stop: true` giết script ở
khối 2 (`grep` trả 2 vì `Permission denied`), nên **khối 3–6 chưa bao giờ in
ra lần nào**. Chú thích đầu tệp đã mô tả đúng loại hỏng này từ lần sửa trước
rồi kết luận `set +e` trong thân đã đủ. Kết luận đó sai.

#118 sửa ba chỗ: `script_stop: false` cho cả hai bước; khối 4 phân biệt
"không tồn tại" với "không vào được" (nó từng in `(chưa tồn tại)` ngay dưới
dòng `Permission denied` cho cùng thư mục); và thêm khối 7 hỏi **"bản nào
đang chạy"** — cặp 7d/7e tách "build ghi sai" khỏi "build đúng nhưng phục vụ
sai", 7k hỏi thẳng "chờ thêm thì có tự hết không".

Khối 3–6 lần đầu chạy được cũng trả lời ngược một giả định cũ: bốn thư mục
cấu hình OpenLiteSpeed là **không vào được**, không phải "chưa tồn tại". Conf
proxy có thể vẫn nằm đúng chỗ.

### Còn mở sau chiều 18/10

| Việc | Chặn bởi |
|---|---|
| **Badge "Đã xác minh" trên thẻ owner** | `owners.verified` là cờ thủ công, không phải quy trình kiểm định — cùng loại với những câu `KhoiLoiHua.tsx` ghi lại. Nó đứng ngay dưới tiêu đề mới của khối ⑤ |
| **Thanh "trust" vẫn nói "Traffic real-time", "AI-powered search", "Secure payment"** | Chủ dự án chốt giữ nguyên văn (#94, #97). Sau khi gỡ khối AI, đây là chỗ **duy nhất** còn những câu đó |
| **Hai định nghĩa CSS trùng** (`.loc-item`, `.mpop-price`) | Nằm trong danh sách nợ của chốt mới. Việc của một lần dọn CSS |
| **`.next/cache` không bị xoá khi deploy** | Trang mới mất vài phút mới hiện. Xoá được, nhưng đánh đổi là mất cache ảnh đã tối ưu ở **mỗi** lần deploy |
| **Dải 768–899px** | Hero một cột, cao 977–1.058px. Vừa iPad dọc, hụt một chút ở 899px |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên style vào `MAPTILER_STYLE` |
| **Tên màn hình `OOHX_LED_SLA_SON_LA`** | Chủ dự án chọn: đổi tiêu đề thẻ hay sửa dữ liệu |
| **Ba loại hình chưa có hàng** | Chủ dự án chọn: hiện mờ "sắp có" hay ẩn |

## Ngày 19–20/10/2026 — `/explore` thành trang ra quyết định (#126 → #134)

`main` ở `1f3253c`. CI **1.086 ca / 4.142 khẳng định** (chiều 18/10: 1.037 /
3.973), `test:js` **252** (207), `test:khoi` **369 khẳng định trên 11 trang**
(330 / 10), `test:seo` **16 trang**. Không còn PR nào mở.

Bảy lát của `docs/PLAN-EXPLORE-QUYET-DINH-MUA.md` đã xong, cộng ba lỗi có sẵn
mà chính việc làm bảy lát ấy làm lộ ra.

### Vì sao plan này tồn tại

Chủ dự án hỏi ngày 18/10: *"nếu chỉ listing thôi nghĩ rất khó để buyer quyết
định mua"*. Đo trên production cùng ngày, câu đó không phải cảm tính:

| Thứ người mua cần để quyết | Tỉ lệ bản ghi có |
|---|---|
| Giá | **1/50** (định nghĩa cũ, chỉ nhìn `floor_cpm`) |
| Ảnh thực địa riêng | **0/50** — cả 50 dùng chung hai tấm ảnh bìa của owner |
| Quận/huyện | 0/50 |
| Toạ độ | **50/50** |

Một trang danh mục thương mại điện tử giả định *giá có sẵn, ảnh có sẵn, người
mua tự so sánh rồi bấm mua*. Ở kho này không điều nào đúng. Việc người mua
**thật sự làm được** là khoanh một danh sách theo vị trí và loại hình rồi hỏi
giá — và bảy lát dựng đúng đường đó.

### Bảy lát

| PR | Lát | Việc | Đo được |
|---|---|---|---|
| #126 | 1 + 2 | `price_status` ở API, cột lọc trái | Thanh lọc giá cũ trả `{0,0}`; định nghĩa mới (nhận cả `io_rate`) tìm ra **19 vị trí có giá**, không phải 1 |
| #128 | 3 | Bản đồ cạnh danh sách, ba cột từ 1280px | Pin khớp `meta.total` tuyệt đối: 19/19 và 85/85 |
| #131 | 4 | Danh sách rút gọn trong `localStorage`, trần 50 | Tick ở thẻ và ở popup bản đồ là **một** trạng thái |
| #132 | 5 | Hai bảng, `POST /api/v2/quote-requests`, hai panel Filament | 24 ca test, trong đó 8 ca chỉ canh ranh giới dữ liệu |
| #133 | 6 | Màn hình gửi `/explore/yeu-cau-bao-gia` | Ba cú bấm → **một** POST |
| #134 | 7 | Tải CSV sinh ở trình duyệt | BOM `EF BB BF`, 6 cột, bọc ô theo RFC 4180 |

Bốn câu chủ dự án chốt ngày 19/10 quyết định hình dạng CSDL của lát 5: khách
ẩn danh **gửi được** · sàn **không cam kết** thời gian trả lời · liên hệ người
mua mở **sau khi quản trị duyệt** · tệp tải về là **CSV**.

### Ba lỗi có sẵn, lộ ra vì có người soát

Cả ba đều có **trước** plan. Cột lọc và các phép đối chiếu chỉ làm chúng đọc
được — và hai trong ba chỉ lộ ra khi tôi soát production sau khi merge, không
phải khi viết mã.

**1. Thẻ nói LCD, bộ lọc LCD trả 0 (#129).** Hai luật cho cùng một tên: nhãn
tính hai cạnh là 0 rồi trả "LCD", còn cả ba nhánh bộ lọc đều đòi
`whereHas('spec')`. 20/104 bản ghi không có dòng `screen_specs` rơi ra ngoài
cả ba nhóm, trong khi thẻ của chúng vẫn mang nhãn. Cột lọc in "LCD 0" ngay
cạnh hai chục thẻ LCD.

Chốt của #126 im lặng vì nó so nhóm đếm với `meta.total` của **cùng một hàm
sai** — hai con số lấy từ một chỗ thì luôn bằng nhau. Chốt mới so **nhãn người
dùng đọc trên thẻ** với nhóm mà bộ lọc xếp bản ghi đó vào.

**2. Lật sang trang 2 thấy lại 29 vị trí của trang 1 (#130).** Đọc hết ba
trang `/api/v2/screens`: 104 dòng trả về, **75 slug duy nhất**. 29 vị trí
không có đường nào để người mua tới. Với `sort=price_asc`: 54/104.

`LIMIT … OFFSET …` không cam kết thứ tự khi khoá sắp xếp có hàng bằng nhau, và
mọi danh sách công khai sắp theo **một** cột không duy nhất. Kho nhập theo lô
nên `created_at` trùng hàng loạt; 103/104 chưa có giá nên khoá sắp giá là NULL
cho gần hết. Sửa bằng trait `PhanTrangOnDinh` cho bốn service — chín chỗ gọi
`paginate()` là chín cơ hội quên.

**3. Bốn vùng chạm dưới 44px.** Nút nổi bộ lọc 38px và dòng tick cột lọc 34px
(hỏng từ #111, lúc đó không ai mở drawer `/products` ra đo) · ô tick trên thẻ
28px · nhãn ô đồng ý chính sách 38px. Cả bốn nay trong danh sách của
`class-css.test.mjs`.

### Năm lỗi của chính tôi, và cái bắt được chúng

| Lỗi | Thứ bắt được |
|---|---|
| Điều kiện "đã báo giá" viết **hai lần** — hằng số SQL cho nhóm đếm, Eloquent cho bộ lọc | Phép đột biến: 2 ca đỏ thay vì 4 |
| Dòng tick định nghĩa **trong thân** component → React tháo và gắn lại cả cây con, tick hai ô chỉ ăn một | Đo tay ở 1440px |
| `getOwnerScreens()` còn `return` ở dòng trên → `phanTrang()` thành mã chết, hàm trả Builder thay vì paginator | Soát lại diff trước khi chạy test |
| Chặn gửi trùng bằng **state** React: `setDangGui(true)` không đổi giá trị ngay, ba cú bấm → ba yêu cầu | Chính chốt mà plan đã nêu trước |
| Phép tính bề ngang lấy padding `.w` là 22px; từ 1024px nó là **52px** | Đo trong iframe |

### Hai lần suýt sửa thứ không hỏng

**`whereHas` và thứ tự `AND`/`OR`.** Tôi đọc
`where(w>=300)->orWhere(h>=300)` trong `whereHas` thành một lỗi ngoặc nuốt mất
ràng buộc khoá ngoại, và đã **báo với chủ dự án** rằng con số 84 trên
production là triệu chứng của nó. `toSql()` cho thấy Laravel tự bọc closure;
phép đột biến bỏ ngoặc không làm đỏ ca nào. 84 là số billboard thật.

**"CSV không có BOM".** `blob.text()` báo không có BOM. Đọc `arrayBuffer()`
thì ba byte đầu là `239 187 191` — `Blob.text()` giải mã UTF-8 và **bỏ BOM
theo spec**.

Cả hai cùng một hình dạng: một phép đo trả lời đúng câu hỏi của **nó**, không
phải câu hỏi của tôi.

### Hai lớp canh mới đáng ghi

**Chốt chạy trên chính mã sẽ lên production.** Node 24 bóc chú thích kiểu khi
nạp, nên `tests/js/*.test.mjs` import **thẳng** module TypeScript của Next —
không có bản sao nào để trôi. Ba tệp mới dùng cách này: danh sách rút gọn (13
ca), phần thuần của màn hình gửi (12), xuất CSV (14).

**Khẳng định phủ định.** `test:khoi` nay canh cả thứ **không được** có trong
HTML máy chủ render: bản đồ (để `/explore` và `/map` không cùng đem một tập
pin đi index), thanh danh sách rút gọn (trạng thái của riêng từng người), và
mọi câu hứa thời gian trả lời — "sẽ liên hệ sớm", "trong 24h", "ngày làm
việc".

### Còn mở sau ngày 20/10

| Việc | Chặn bởi |
|---|---|
| **Chưa ai thử đường gửi yêu cầu thật trên production** | Bản ghi tạo ra không xoá được qua `/admin`: `QuoteRequestPolicy::delete()` đóng có chủ ý. Cần chủ dự án thử, hoặc mở quyền xoá cho `super_admin` trước |
| **Phân trang `/api/v1` mang đúng lỗi #130** | Bốn controller ở đó cũng sắp theo cột không duy nhất. Đổi thứ tự trả về là đổi hành vi hợp đồng đối tác (CLAUDE.md mục 1) — cần chủ dự án chốt |
| **Ghép sai vị trí** | "Aeon Mall Tân Phú" gắn site ở Bắc Kạn. Dữ liệu, không phải mã |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên vào `MAPTILER_STYLE` |
| **Thanh "trust"** | Vẫn in "Verified inventory", "Traffic real-time", "AI-powered search" |
| Mốc ba cột 1280px thay vì 1180px như plan viết | Đã ghi lại trong plan: `.w` lấy 52px mỗi bên, nên ở 1180px lưới còn 140px một thẻ |
| Hai định nghĩa CSS trùng (`.loc-item`, `.mpop-price`) | Việc của một lần dọn CSS |

> **Bảng trên đã cũ tính từ chiều 20/10/2026.** Ba dòng đầu đã xử lý ở #136 →
> #138 (quyền xoá yêu cầu, soát lỗi ghép vị trí, khoá ghép khi nhập). Bảng còn
> hiệu lực ở cuối mục kế tiếp.

## Ngày 20/10/2026 — ba việc lấy ra khỏi hàng đợi (#136 → #138)

`main` ở `6a755da`. CI **1.139 ca / 4.306 khẳng định** (sáng 20/10: 1.086 /
4.142). Không còn PR nào mở, cả ba đã lên production.

| PR | Việc | Đo được |
|---|---|---|
| #136 | Mở quyền xoá yêu cầu báo giá cho quản trị sàn | 12 ca / 46 khẳng định |
| #137 | Báo cáo soát dữ liệu vị trí ở `/admin` | 9 phép đo, 30 ca |
| #138 | Lưu khoá ghép điểm đặt khi nhập | 11 ca, kèm một lỗi có sẵn |

### #136 — xoá được thì mới thử được

Lát 5 đóng cứng `QuoteRequestPolicy::delete()` để không ai tay trượt làm mất
một đầu mối của media owner. Hệ quả ngược lại: **không ai thử được đường gửi
thật trên production**, vì bản ghi thử nằm vĩnh viễn trong danh sách việc của
owner và trong mọi con số báo cáo. Một tính năng không ai thử được trên môi
trường thật là một tính năng chưa biết có chạy.

Ba quyết định bên trong:

| Quyết định | Vì sao |
|---|---|
| Chỉ `super_admin` | Owner chỉ thấy phần của mình, nhưng xoá thì xoá cả bản ghi — tức xoá luôn phần của owner khác |
| Xoá thật, không xoá mềm | Bản ghi chứa tên, email, điện thoại người gửi. Một cột `deleted_at` là **ẩn**, không phải **xoá**, và nó không trả lời được khi người mua đòi xoá dữ liệu của họ |
| Gõ lại mã + lý do bắt buộc | `requiresConfirmation()` một mình chỉ đòi một cú bấm nữa, và cú bấm nữa là thứ người ta làm theo quán tính |

Nhật ký ghi mã, trạng thái, mốc gửi, số mục, **owner nào mất đầu mối**, ai xoá
và lý do — **không** mang tên, email, điện thoại. Có một ca test đi tìm đúng ba
thứ đó trong nội dung đã ghi: xoá bản ghi vì dữ liệu cá nhân rồi chép chính dữ
liệu đó sang tệp log là xoá trên giấy.

Phép đột biến bắt được một thứ đáng ghi: **bỏ `items()->delete()` thì chốt vẫn
xanh** — khoá ngoại MySQL dọn hàng con. Dòng đó là lớp thứ hai, không phải thứ
làm việc; đã ghi lại ngay tại chỗ để không ai đọc nó như một dòng được canh.

### #137 — "ghép sai vị trí" là MỘT hàng

Soát 104 bản ghi công khai qua API công khai, không chạm CSDL production.

| Phát hiện | Số |
|---|---|
| **Thiếu tỉnh/thành** — `OOHX_LED_LCU_LAI_CHAU`, địa chỉ *"TP Lai Châu, Lai Châu"* và toạ độ đủ, ô tỉnh rỗng | 1 |
| **`Màn Hình Quảng Cáo Aeon Mall Tân Phú`** gắn "LED Bắc Kạn" | 1 |
| **`LED Yên Bái`: 6 màn hình một toạ độ**, tên chúng chỉ **ba** con đường | 6 |
| Ô tỉnh chứa hai cấp ("Thái Nguyên > Phường Bắc Kạn") | 21 |

Hàng đợi ghi việc này như thể một lỗi hệ thống. Nó không phải: 20/21 màn hình
của cùng owner theo đúng một quy ước (`OOHX_LED_<mã tỉnh>_<TỈNH>_<chi tiết>`)
và **tất cả** khớp điểm đặt. Đúng một hàng lệch — hình dạng của một bản ghi
nhập tay. Toạ độ của nó **là** ở Bắc Kạn, trùng khít `OOHX_LED_BKN_BAC_KAN`,
nên trường sai là **tên**, hoặc bản ghi gắn sai chỗ.

**Phép đo tôi thử rồi bỏ.** "Tên màn hình không có token nào chung với điểm
đặt / địa chỉ / tỉnh" — nghe hiển nhiên, cho **15 cảnh báo, 14 báo sai**: một
owner đặt tên viết tắt (`Billboard CV 23/9` ↔ *Công viên 23 Tháng 9*;
`Billboard LTT-SVH` ↔ *Giao lộ Lý Thái Tổ - Sư Vạn Hạnh*). Một bảng mà 14/15
dòng là rác thì không ai đọc lần thứ hai, và dòng thật chết cùng những dòng
kia. `test_ten_viet_tat_khong_bi_bao_sai` đóng băng quyết định ấy bằng bốn
hàng thật.

Phép đo **đã** ship và bắt được hàng Aeon lại rất hẹp: *20/21 màn hình của
owner này tên dạng mã, cái này thì không*. Bảy phép đo khác im lặng với nó —
toạ độ đúng, tỉnh đúng, nhóm đúng, không khoá nào để đối chiếu, trong tên
không có tên tỉnh nào.

**Cột "đối chiếu được".** Mỗi phép đo in kèm số bản ghi nó thật sự xét tới, vì
"0 lỗi" trên 0 bản ghi đọc giống hệt "0 lỗi" trên cả kho. Hai mức: `Lỗi` là
một khẳng định, `Cần đọc lại` thì không — badge trên menu chỉ đếm mức đầu,
gộp cả hai thì badge không bao giờ về 0 (21 ô tỉnh hai cấp, không cái nào là
lỗi) và một badge không bao giờ tắt thì không ai nhìn nữa.

### #138 — khoá ghép, và lưu nó TỪ NGUỒN

`ghepLechKhoa()` của #137 hiện `0/104`: cả ba lối nhập tra `site_external_id`
để tìm `site_id` rồi **bỏ mã đi**.

Điểm dễ làm sai nhất không phải việc lưu, mà là lưu **từ đâu**. Lấy mã từ
`$site->external_id` thì hai bên luôn bằng nhau, và một bất biến không bao giờ
vỡ được thì không canh gì — báo cáo sẽ in `104/104` và không bao giờ nói được
điều gì. Nên mã lấy từ ô trong tệp, ô người gọi gửi lên.

Hai hàng rào chống báo oan, vì một báo cáo báo oan sẽ bị tắt: `Screen::saving`
kéo cột theo khi `site_id` đổi mà không ai nói gì về mã nguồn (đúng việc biểu
mẫu Filament làm), và `Site::updated` kéo cột ở màn hình con khi mã địa điểm
đổi.

**Không điền ngược hàng cũ.** Cột nói "nguồn đã nói gì"; với hàng nhập trước
hôm nay thì không ai còn biết. Điền từ điểm đặt hiện tại là tự dựng bằng chứng
rằng chỗ gắn hôm nay đúng — kể cả khi nó sai, và hàng Aeon là đúng loại hàng
sẽ bị dựng bằng chứng cho. Độ phủ lớn dần theo từng lượt nhập lại.

**Lỗi có sẵn ca test tìm ra.** `RowValidator::resolveSiteId()` khai kiểu trả
về `?int` trong khi `sites.id` là ULID `char(26)`, và PHP 8 ném TypeError chứ
không ép. `createSite(): int` mang đúng lỗi đó. Nghĩa là
`ScreenWriter::write()` đổ ở **mọi** dòng — cả lối nhập màn hình ở `/admin`
không ghi được hàng nào. Im lặng tới hôm nay vì **không ca test nào gọi
`ScreenWriter`**.

### Ba lỗi của chính tôi trong ngày

| Lỗi | Thứ bắt được |
|---|---|
| In thẳng "cao" / "xem tay" vào Blade của panel | CI — `ChuBladePanelPhaiCoDauTest` đòi chữ hiển thị phải có dấu |
| Một mảnh chữ kẹp giữa hai biểu thức Blade thành chuỗi không dấu | CI, cùng chốt đó, **lần thứ hai trong một ngày** |
| Commit lên `main` vì quên tạo nhánh sau khi merge #137 | Tự soát trước khi push. Chưa push nên production không bị chạm |

Lỗi thứ ba đáng nhớ nhất: `merge = deploy`, nên một lần `git push` sai chỗ là
một lần deploy không ai duyệt. Việc cứu nó là `git switch -c` rồi
`git branch -f main origin/main` — không `reset --hard`, không mất gì.

### Hai chốt mới đáng ghi

**Trang Filament được dựng thật.** Hai ca nạp đúng URL `/admin/soat-vi-tri` và
đọc nội dung trả về, nên một lỗi Blade hay một biến thiếu trong
`getViewData()` làm chúng đỏ. Lần đầu chúng trả **404**: `AdminPanelProvider`
gắn `->domain(config('domains.dash'))` ở mọi môi trường trừ `local`, nên phải
gọi kèm tên miền của panel. Đó là lỗ hổng đã nêu ở #136 ("chưa có chốt nào
render thật bảng Filament") và nay đã bịt.

**Tệp Excel dựng trong ca test.** `KhoaGhepDiemDatTest` sinh workbook bằng
PhpSpreadsheet chứ không dùng tệp nhị phân cố định trong repo: khuôn tệp là
thứ chốt này canh, nên nó phải đọc được ngay trong mã test.

### Còn mở sau ngày 20/10 (bản cập nhật)

> **Bảng này hết hiệu lực chiều 20/10.** Hai dòng trong nó — phân trang
> `/api/v1` và thanh "trust" — đã làm xong ở #140 và #141. Bảng còn hiệu lực
> ở cuối tệp.

| Việc | Chặn bởi |
|---|---|
| **Chưa ai thử đường gửi yêu cầu thật trên production** | Nay xoá được rồi (#136), nên không còn gì chặn. Cần chủ dự án bấm |
| **Chưa ai chạy thật lối nhập màn hình ở `/admin`** sau khi sửa kiểu ULID | Hai ca hồi quy đi qua `ScreenWriter` trực tiếp, không qua `ScreenImportService` và hàng đợi của nó |
| **Ba việc nhập liệu từ báo cáo soát** | Điền tỉnh cho LED Lai Châu · quyết định tên hay điểm đặt mới là cái sai ở hàng Aeon · cho sáu màn hình Yên Bái toạ độ riêng |
| **Độ phủ `ghepLechKhoa` vẫn 0/104** | Chờ lượt nhập lại đầu tiên. Không có cách trung thực nào làm nó nhảy lên hôm nay |
| **Phân trang `/api/v1` mang đúng lỗi #130** | Đổi thứ tự trả về là đổi hành vi hợp đồng đối tác (CLAUDE.md mục 1) — cần chủ dự án chốt |
| **Thanh "trust"** | Vẫn in "Verified inventory", "Traffic real-time", "AI-powered search" |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên vào `MAPTILER_STYLE` |
| Báo cáo soát chưa xét giá, ảnh thực địa, kích thước | Trang in câu đó ra để không ai đọc nó như một báo cáo đầy đủ |
| Mốc ba cột 1280px thay vì 1180px như plan viết | `.w` lấy 52px mỗi bên, nên ở 1180px lưới còn 140px một thẻ |
| Hai định nghĩa CSS trùng (`.loc-item`, `.mpop-price`) | Việc của một lần dọn CSS |

## Ngày 20/10/2026, chiều — hai việc lấy từ bảng "còn mở" (#140, #141)

`main` ở `87af1d3`. CI **1.157 ca / 4.407 khẳng định** (sáng nay: 1.139 /
4.306). Không còn PR nào mở, cả hai đã lên production và đã đo lại trên đó.

| PR | Việc | Đo được |
|---|---|---|
| #140 | `/api/v1` lật trang không còn trùng và thiếu bản ghi | 18 ca / 101 khẳng định |
| #141 | Thanh "trust" nói việc làm được thay vì năng lực | 5 câu đổi, 1 lỗi bố cục đo trong trình duyệt |

### #140 — vì sao hôm nay sửa được cái #130 phải hoãn

#130 hoãn `/api/v1` vì nó là hợp đồng với đối tác (CLAUDE.md mục 1), và đổi
thứ tự trả về là đổi hành vi. Điều mở khoá cho hôm nay không phải một quyết
định nới tay, mà là một câu hỏi hẹp hơn: **hợp đồng đã công bố cái gì?**

`docs/tapon_api_list.md` và `docs/api_tapon_browse_filters_v1.md` công bố
**những khoá sắp xếp nào tồn tại**. Không chỗ nào công bố thứ tự **trong một
nhóm bằng nhau** — và đó là tất cả những gì khoá phụ chạm tới. Một đối tác
đang lật trang không thể đã dựa vào thứ tự cũ, vì thứ tự cũ không tồn tại:
nó đổi giữa các lần chạy.

Năm chỗ, và cột nào đang sắp trước khi sửa:

| Đường | Khoá sắp xếp cũ |
|---|---|
| `inventory/screens?sort=newest` | `updated_at` — trùng hàng loạt khi nhập theo lô |
| `inventory/screens?sort=price_asc\|price_desc` | `floor_cpm` — NULL cho 103/104 bản ghi thật |
| `inventory/owners` | `featured` (boolean) rồi `name` |
| `owners`, `sites`, `screens` | **không có `ORDER BY` nào** khi không gửi `sort` |

Ba endpoint quản lý là chỗ **duy nhất** đối tác có thể thấy khác trước: nay
chúng đi theo ULID (≈ cũ nhất trước). Cái họ thấy trước đó là thứ tự MySQL
thấy rẻ nhất hôm ấy.

**Phép đột biến nói ra một điều không đoán trước.** Gỡ khoá phụ khỏi
`phanTrang()` làm bốn ca kho hàng đỏ, nhưng **sáu ca endpoint quản lý vẫn
xanh**. Không còn `ORDER BY` nào thì MySQL quét 25 hàng theo khoá chính một
cách nhất quán giữa hai truy vấn, nên không bản ghi nào lặp. Thứ tự *không
xác định* không có nghĩa là *ngẫu nhiên*: nó đổi khi kế hoạch truy vấn đổi —
tức khi dữ liệu đủ nhiều — chứ không đổi ở cỡ một fixture.

Nên hai ca mới đi xuống **câu SQL** (`DB::listen`) và đòi khoá chính phải là
khoá sắp **cuối cùng** — vừa nói khoá phụ có mặt, vừa nói nó đứng sau mọi
khoá khác. Giới hạn của sáu ca kia ghi thẳng lên đầu chúng trong tệp test,
thay vì để người đọc tưởng chúng canh lỗi này.

Đột biến thứ hai — chèn `reorder()` để khoá phụ **giành chỗ** khoá chính —
làm đúng bốn ca "hợp đồng không đổi" đỏ. Bốn ca đó cần thiết vì
`allowedSorts()` của Spatie nạp sort vào query **ngay trong thân nó**: một
thứ tự gọi nằm trong thư viện bên ngoài, không có chốt thì không ai biết nó
đổi.

**Chưa đo được trên production.** `/api/v1` đòi Bearer token đối tác, không
có thì không gọi được; v2 đo được hôm 19/10 vì nó công khai. Đo thay thế:
v2 sau deploy vẫn 104/104 bản ghi duy nhất ở cả bốn cách sắp.

### #141 — chỗ cuối cùng còn những câu không kiểm được

Thanh năm mục dưới hero là chỗ **cuối cùng** còn chúng, sau khi khối AI bị gỡ
ở lát 3. Chủ dự án chốt dựng nguyên văn ngày 18/10 (#94, #97) rồi chốt sửa
ngày 20/10.

| Câu cũ | Câu mới | Mã đứng sau câu mới |
|---|---|---|
| Verified inventory | Vị trí trên bản đồ | `/api/v2/screens/pins`; 104/104 bản ghi công khai có toạ độ |
| Traffic real-time | Dữ liệu từ chủ màn hình | Ba lối nhập đều ghi dữ liệu của owner |
| AI-powered search | Lọc theo tỉnh, loại hình | `/api/v2/filters`: 17 tỉnh, 2 loại địa điểm, 3 loại màn hình |
| Secure payment | Gửi yêu cầu không cần đăng ký | `POST /api/v2/quote-requests` nằm ngoài nhóm Sanctum |
| Agency support 24/7 | Xuất danh sách CSV | `/explore` xuất giỏ ra CSV |

Tiêu chí: mỗi câu nói một **việc làm được**, không nói một **năng lực**. Đó là
khác biệt làm chúng kiểm được — "Vị trí trên bản đồ" sai khi bản đồ không có
pin, còn "Traffic real-time" thì không có phép thử nào.

Ba thứ **cố ý không nói ở đây** vì trang đã nói chỗ khác, và nhắc lại là lấp
chỗ: "Miễn phí đăng ký · Không phí môi giới" (hero), "Sàn không thu hộ" và
"Giá do chủ màn hình đặt" (khối ⑤). Một thứ **không nói vì chưa đo được**:
chất lượng toạ độ — báo cáo soát sáng nay tìm ra sáu màn hình Yên Bái chung
một toạ độ, nên câu là "vị trí trên bản đồ" (có pin), không phải "toạ độ
chính xác" (một khẳng định về chất lượng).

**Chữ mới tạo ra một lỗi bố cục.** Nhãn tiếng Việt dài hơn nhãn tiếng Anh, và
`.trust-in` ở desktop là `flex-wrap:nowrap` + `overflow:visible` — tràn ra
ngoài khung chứ không cuộn. Đo trong trình duyệt bằng iframe (media query
theo chiều rộng iframe, nên là phép đo thật):

| Rộng | Có `wrap` | Nếu tắt `wrap` |
|---|---|---|
| 1280px · 1180px | 1 dòng, không tràn | — |
| 1024px | 2 dòng, không tràn | mục cuối chạm 1052px — tràn 28px |
| 768px | 2 dòng, không tràn | mục cuối chạm 924px — tràn 156px |
| < 768px | cuộn ngang trong thanh (đúng thiết kế mobile), trang không cuộn ngang | — |

Nên thêm `flex-wrap:wrap; row-gap:10px` ở ≥768px và rút hai nhãn dài nhất.
Đánh đổi đã chọn: ở 768–1024px thanh nay hai dòng.

### Hai thứ đáng ghi về cách làm

**Đo trên production, không chỉ xem mã thoát.** Sau mỗi deploy hôm nay đều có
một phép đo thật, không chỉ smoke 200: #140 đếm bản ghi duy nhất khi lật hết
trang, #141 `grep` năm câu mới có mặt **và** năm câu cũ bằng 0 trong HTML
production, cộng một lần kiểm `flex-wrap:wrap` thật sự nằm trong tệp CSS đã
build.

**Đảo một quyết định thì ghi lại cả hai đầu.** Năm câu cũ chuyển từ danh sách
`co` sang `khong` của `khoi-giao-dien.mjs`, cùng kiểu với sáu luật mục AI hồi
lát 3 — mang lại một câu phải là một quyết định, không phải một lần sửa test
cho xanh.

### Còn mở sau chiều 20/10

| Việc | Chặn bởi |
|---|---|
| **Chưa ai thử đường gửi yêu cầu thật trên production** | Nay xoá được rồi (#136), không còn gì chặn. Cần chủ dự án bấm |
| **Chưa ai chạy thật lối nhập màn hình ở `/admin`** sau khi sửa kiểu ULID | Hai ca hồi quy đi qua `ScreenWriter` trực tiếp, không qua `ScreenImportService` và hàng đợi của nó |
| **Chưa đo `/api/v1` trên production** | Cần một API client (scope `inventory`) và token của nó. Không có token thì không gọi được đường nào trong nhóm đó |
| **Ba việc nhập liệu từ báo cáo soát** | Điền tỉnh cho LED Lai Châu · quyết định tên hay điểm đặt mới là cái sai ở hàng Aeon · cho sáu màn hình Yên Bái toạ độ riêng |
| **Độ phủ `ghepLechKhoa` vẫn 0/104** | Chờ lượt nhập lại đầu tiên |
| **Đối tác chưa được báo** về thứ tự mặc định mới của `/owners`, `/sites`, `/screens` | `docs/tapon_api_list.md` đã ghi, nhưng tài liệu không tự đến tay ai — và STATUS.md vẫn đang chờ trả lời "đối tác nào đang dùng API" |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên vào `MAPTILER_STYLE` |
| `price_per_slot_vnd` của `/api/v1` mang giá trị `floor_cpm` | Ghi chú F09 tại chỗ: đổi là làm sai số tiền trong hệ thống đối tác mà họ không được báo trước |
| Báo cáo soát chưa xét giá, ảnh thực địa, kích thước | Trang in câu đó ra để không ai đọc nó như một báo cáo đầy đủ |
| Mốc ba cột 1280px thay vì 1180px như plan viết | `.w` lấy 52px mỗi bên, nên ở 1180px lưới còn 140px một thẻ |
| Hai định nghĩa CSS trùng (`.loc-item`, `.mpop-price`) | Việc của một lần dọn CSS |

> **Bảng trên thiếu một dòng.** #143 (dưới đây) tìm ra rằng `/map` bỏ sót
> `OOHX_LED_LCU_LAI_CHAU` khỏi danh sách — dòng "ba việc nhập liệu" vì thế
> nặng hơn vẻ ngoài của nó. Bảng còn hiệu lực ở cuối mục sau.

## Ngày 20/10/2026, tối — bốn lỗi mobile từ hai tấm ảnh (#143)

Chủ dự án gửi hai ảnh chụp `/explore` và `/map` trên điện thoại, kèm đúng một
câu: *"Giao diện mobile của 2 trang này bị lỗi."* Đo lại ở khung **390×844**
trong trình duyệt (iframe, nên media query ăn theo chiều rộng khung) ra bốn
lỗi, cộng một chênh lệch số liệu hoá ra không phải lỗi giao diện.

### Một biến CSS sai phạm vi, bốn triệu chứng

`--nav: 60px` là chiều cao thanh điều hướng dưới của bản **Blade** (`.bnav`,
chỉ được `include` trong layout Blade). **Không trang Next nào render nó** —
kiểm cả `/`, `/explore`, `/map`: không có phần tử cố định nào ở đáy. Nhưng
`frontpage.css` dùng chung, và **15 quy tắc** trừ hao `var(--nav)` để né một
thanh không tồn tại:

| Đo ở 390px | Trước | Sau |
|---|---|---|
| "Bộ lọc" (`/explore`) cách đáy | 72px — lơ lửng giữa nội dung, đè lên thẻ | 12px |
| "Danh sách" (`/map`) cách đáy | 72px — đè lên dòng ghi công | 12px |
| `body` chừa cuối trang | 60px trống | 0 |
| Khung `.map-layout` | `100vh − hdr − 60px` | 668/720 |

Sửa bằng **một dòng** `--nav: 0px` trong `webapp/app/globals.css`, không đụng
`frontpage.css`: bên đó `var(--nav)` vẫn đúng cho bản Blade, nơi `.bnav` có
thật. Đây là lần thứ hai trong hai ngày mà cách đúng là **sửa ở lớp
`globals.css` của Next** thay vì sửa tệp dùng chung — lần trước là `--font`.

### Tên dạng mã bị cắt giữa chữ

Tên kho thật không có một khoảng trắng nào:
`OOHX_LED_YBI_YEN_BAI_NGUYEN_VAN_CU_1`. `.sc-title` có `overflow:hidden`
nhưng `overflow-wrap: normal`, nên chuỗi ấy không xuống dòng được — **307px
nội dung trong ô 197px**, cắt phũ, không dấu "…".

Tệ hơn chuyện thẩm mỹ: ở mobile ô này clamp **một** dòng, nên
`..._NGUYEN_VAN_CU_1` và `..._CU_2` cắt ra **giống hệt nhau**. Hai vị trí
khác nhau hiện ra như một. Sửa: `overflow-wrap:anywhere` + clamp hai dòng.

### Ô trắng rỗng trên ảnh

`.o-tick` (ô chọn vào danh sách rút gọn) có `color: transparent` khi chưa
chọn, dấu tick chỉ lộ ra khi **trỏ chuột vào**. Điện thoại không có hover,
nên nó hiện ra như một ô trắng 44×44 rỗng nằm trên ảnh. Thêm
`@media(hover:none){.o-tick{color:var(--t4)}}`.

Đây là loại lỗi chỉ tồn tại ở một nửa số thiết bị, và nửa không thấy nó là
nửa mà người viết mã ngồi.

### Dòng ghi công in chữ "true"

`L.control.attribution({ prefix: true })`. `prefix` của Leaflet là **chuỗi
HTML** in trước phần ghi công, không phải cờ bật/tắt — nên dòng dưới bản đồ
production đọc là `true | © MapTiler · © OpenStreetMap`. Nhìn thoáng qua
trông như tên một đơn vị bị cắt mất đầu, và đó đúng là cách nó bị đọc nhầm
khi soát. `false` mới là cách tắt.

### 103 ≠ 104: một vị trí không có đường nào để tới

`/map` hiện "Hiển thị **103** kết quả" trong khi kho có 104.
`/api/v2/screens/map` trả đủ 104, nhưng **đúng một** bản ghi không có tỉnh —
`OOHX_LED_LCU_LAI_CHAU` — nên nó rơi khỏi danh sách gom theo tỉnh.

Báo cáo soát sáng nay đã xếp bản ghi này là *"thiếu tỉnh, mức cao"*, nhưng
chưa ai biết **hệ quả**: nó biến mất khỏi `/map`. Một ô dữ liệu rỗng thành
một vị trí người mua không tìm thấy. Không sửa ở #143 vì có hai cách khác
nhau — điền tỉnh, hay cho danh sách một nhóm "chưa rõ tỉnh" — và đó là lựa
chọn của chủ dự án.

("94 vị trí đang hiển thị" thì đúng: đó là số pin trong khung nhìn.)

### Vì sao không test nào bắt được bốn lỗi này

jsdom không chạy layout, `khoi-giao-dien.mjs` đọc HTML đã render chứ không
chạy CSS, và không chốt nào đọc tới `dung-ban-do.ts`.
`tests/js/mobile-khong-che-khong-cat.test.mjs` **không** mở rộng tầm đó ra
tới cách trang hiện ra — điều ấy vẫn ngoài tầm. Nó đóng một khoảng hẹp hơn và
kiểm được bằng văn bản: bốn dòng đã sửa phải còn nguyên, mỗi chốt mang theo
số đo của lỗi nó chặn.

Một ca đi theo chiều ngược: `frontpage.css` **phải** giữ `--nav: 60px` và
`.bnav` **phải** còn là thanh cố định — nếu bản Blade bỏ thanh đó thì dòng
`--nav: 0px` của Next cũng hết lý do. Chốt nói ra quan hệ ấy thay vì để hai
tệp trôi khỏi nhau.

Đột biến: gỡ từng sửa một → đúng ca tương ứng đỏ, 5/5.

### Lỗi của chính tôi hôm nay, dòng thứ tư

| Lỗi | Thứ bắt được |
|---|---|
| Tạo nhánh `fix/mobile-explore-map` bằng `git switch -c` khi **đang đứng trên nhánh tài liệu**, không phải trên `main` | `git rebase main` của PR tài liệu — nó báo *"patch contents already upstream"* |

Hệ quả: PR #143 mang theo hai tệp `docs/` của PR #142, và **mô tả của #143
không nhắc tới chúng**. Nội dung không sai và không mất gì, nhưng một PR sửa
CSS lại chứa 145 dòng tài liệu mà người duyệt không được báo — nếu có người
duyệt, họ sẽ đọc một PR khác với PR họ nghĩ mình đang đọc.

Cùng họ với lỗi *"commit lên `main` vì quên tạo nhánh"* sáng nay: cả hai đều
là **không kiểm nhánh hiện tại trước khi tạo nhánh mới**. Cách tránh rẻ hơn
mọi cách sửa: `git switch -c <tên> main`, nêu rõ điểm xuất phát.

### Còn mở sau ngày 20/10 (bản cuối ngày)

| Việc | Chặn bởi |
|---|---|
| **`OOHX_LED_LCU_LAI_CHAU` không có đường nào để tới qua `/map`** | Hai cách sửa, cần chủ dự án chọn: điền tỉnh, hay cho `/map` nhóm "chưa rõ tỉnh" |
| **Chưa ai thử đường gửi yêu cầu thật trên production** | Không còn gì chặn (#136). Cần chủ dự án bấm |
| **Chưa ai chạy thật lối nhập màn hình ở `/admin`** sau khi sửa kiểu ULID | Hai ca hồi quy đi qua `ScreenWriter` trực tiếp, không qua `ScreenImportService` và hàng đợi |
| **Chưa đo `/api/v1` trên production** | Cần một API client (scope `inventory`) và token của nó |
| **Hai việc nhập liệu còn lại** | Tên hay điểm đặt mới là cái sai ở hàng Aeon · sáu màn hình Yên Bái chung một toạ độ |
| **Độ phủ `ghepLechKhoa` vẫn 0/104** | Chờ lượt nhập lại đầu tiên |
| **Đối tác chưa được báo** về thứ tự mặc định mới của `/owners`, `/sites`, `/screens` | Tài liệu đã ghi, nhưng vẫn chưa biết đối tác nào đang dùng API |
| **Ô tick trên thiết bị cảm ứng chưa ai nhìn tận mắt** | `@media(hover:none)` không kiểm được từ máy tính; chốt chỉ canh quy tắc tồn tại |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên vào `MAPTILER_STYLE` |
| Bố cục `/map` mobile: bottom sheet chiếm phần lớn màn hình | #143 chỉ trả lại 60px chiều cao, không thiết kế lại tỷ lệ |
| `price_per_slot_vnd` của `/api/v1` mang giá trị `floor_cpm` | Ghi chú F09: đổi là làm sai số tiền trong hệ thống đối tác |
| Báo cáo soát chưa xét giá, ảnh thực địa, kích thước | Trang in câu đó ra để không ai đọc nó như báo cáo đầy đủ |
| Mốc ba cột 1280px thay vì 1180px như plan viết | `.w` lấy 52px mỗi bên |
| Hai định nghĩa CSS trùng (`.loc-item`, `.mpop-price`) | Việc của một lần dọn CSS |

> **Bảng trên có một dòng đã đóng, và thiếu một dòng mới.** #144 và #145 (dưới
> đây) đóng dòng "bottom sheet chiếm phần lớn màn hình" và trả nợ lời hứa của
> tay nắm. Bảng còn hiệu lực ở cuối mục sau.

## Đêm 20 → sáng 21/10/2026 — ngăn kéo lên, hai PR (#144, #145)

Ba ngăn kéo lên của trang công khai — `.map-panel.open` ở `/map`,
`.sidebar.open` ở `/explore` và `/products` — mang hai lỗi cùng một họ: ngăn
**lấy quá nhiều chỗ**, và tay nắm trên đỉnh nó **hứa một cử chỉ rồi không
làm**. #144 sửa cái thứ nhất, #145 cái thứ hai. Cả hai đều là dòng đã nằm
trong bảng "còn mở" từ ngày hôm trước.

### #144 — `60vh` là 60% của cái gì

`height:60vh` cố định. Đo ở viewport 742px: ngăn chiếm **445px**, bản đồ còn
**245px** — trên một trang mà bản đồ *là* nội dung, không phải hình minh hoạ.

Và `vh` tính theo viewport **khi thanh địa chỉ ẩn**. Trên điện thoại thật,
lúc thanh ấy đang hiện, 60vh chiếm tới **~73% vùng nhìn thấy**. Đó là chênh
lệch giữa con số trong CSS và cái người dùng thấy, và nó đi đúng chiều xấu.

| Đo ở 390×844 | Trước | Sau |
|---|---|---|
| `.map-panel.open` | 506px (60vh) | **492px** |
| Bản đồ còn lại | 286px | **300px**, đã chừa trước |
| Trên máy thanh địa chỉ hiện | ~73% vùng nhìn | 60% của vùng nhìn **thật** |

Phép tính mới đọc từ giữa `clamp` ra: lấy **phần còn lại sau khi chừa 300px
cho bản đồ**, nhưng không dưới `40dvh` (ngăn thấp quá thì không đọc được danh
sách) và không quá `60dvh` (cao quá thì quay về đúng lỗi này).

```css
height:60vh;                                              /* dự phòng */
height:clamp(40dvh,calc(100dvh - var(--hdr) - 300px),60dvh);
```

Hai dòng `height` là cố ý: trình duyệt không biết `dvh` bỏ dòng sau và giữ
dòng trước. `.sidebar.open` và `.ds-panel` cũng đổi `vh` → `dvh`, nhưng giữ
nguyên 60% — hai ngăn ấy không có bản đồ phía sau, nên chỉ cần đổi *60% của
cái gì*.

### #145 — tay nắm hứa cử chỉ, nay làm thật

Tay nắm hình viên thuốc ở đỉnh ngăn là ký hiệu quy ước của một bottom sheet
kéo được. Từ lát 6 tới hôm qua nó **chỉ đóng ngăn khi bấm**: kéo xuống không
có gì xảy ra, kéo lên cũng không. Giao diện mượn một ký hiệu rồi không trả nợ
nó.

Nay cả ba ngăn có **hai bậc** và một cử chỉ thật:

| Người dùng làm | Ngăn làm |
|---|---|
| Kéo xuống | thấp dần **theo ngón tay**, trôi khỏi màn hình — xem trước việc đóng |
| Thả ở gần bậc nào | về bậc đó; ngang nhau thì **giữ ngăn lại**, không đóng |
| Vẩy (≥ 0.5px/ms) | đi **một bậc** theo hướng vẩy, không nhảy thẳng tới cuối |
| Nhấn | đóng, như cũ |
| ↑ / ↓ / Enter | mở rộng / thu rồi đóng / đóng, và `aria-expanded` nói ra bậc đang ở |

Vẩy đi một bậc chứ không nhảy thẳng: vẩy mạnh từ bậc rộng mà đóng luôn là
lấy đi cả ngăn của người chỉ muốn thấy bản đồ nhiều hơn.

### Vì sao đổi `height` thật chứ không `transform:translateY()`

Cách thường gặp là dựng ngăn cao sẵn rồi dịch xuống — rẻ hơn với trình duyệt
vì không reflow. Nhưng ngăn này **chứa một danh sách cuộn bên trong**. Dựng
cao sẵn rồi dịch xuống thì phần dưới của khung cuộn nằm ngoài vùng nhìn, và
**mục cuối cùng của danh sách không ai đọc được**: cuộn tới cuối rồi vẫn còn
một đoạn nằm dưới mép màn hình.

Nên: đổi `height` thật, ghi qua ref (`el.style.height`) chứ không qua
`setState`, một lần ghi mỗi `requestAnimationFrame`. Ngăn là `position:fixed`
nên reflow chỉ chạy trong cây con của nó.

Phép đo trên production xác nhận đúng chỗ ấy: ở bậc rộng của `/map` (88 thẻ
thật), cuộn tới cuối thì **thẻ cuối cùng ở 824px trong vùng nhìn 844px**.

### Ba lỗi mà chỉ phép ĐO tìm ra, không phải đọc code

| Lỗi | Biểu hiện | Sửa |
|---|---|---|
| React mất **330ms** mới gắn lớp bậc mới sau `setState` | Thả tay xong ngăn **tụt về bậc cũ** rồi mới bò lên bậc mới | `classList.toggle('sheet-rong', …)` thẳng vào DOM, rồi mới `setRong()` |
| Cờ "vừa kéo" không được xoá ở đầu cử chỉ | `click` không tới sau một cú kéo (chuột nhả ngoài tay nắm) → **cú nhấn kế tiếp bị ăn mất**, ngăn không đóng được | `vuaKeo.current = false` ngay trong `batDau` |
| Phép đo bậc thu rơi vào **giữa animation** | Ghi nhớ ~700px cho bậc thật 492px → cú kéo sau tính ngưỡng theo số sai | Đo lại ở `transitionend` |

Lỗi thứ nhất đo bằng vòng lấy mẫu 14×100ms: chiều cao đứng ở 492 tại mốc
107/216/325ms, rồi mới 654 → 756 → 780 ở mốc 650ms. Không đọc code nào ra
được con số 330ms đó.

Lỗi thứ hai không phải lỗi của phép đo: chuột nhả ngoài tay nắm trên máy thật
cho cùng kết quả.

### Chốt được gì, và chốt bằng cách nào

Không chốt nào của dự án chạy được một cử chỉ thật: jsdom không chạy layout,
`khoi-giao-dien.mjs` đọc HTML đã render chứ không chạy CSS, Chrome thì phải
đo tay. Nên phép **quyết định** của cử chỉ tách khỏi DOM, vào
`webapp/lib/keo-sheet.ts` — hàm thuần, nhận số, trả bậc — và
`tests/js/sheet-keo-duoc.test.mjs` gọi thẳng bằng số (Node 24 bóc chú thích
kiểu khi nạp, nên chốt `.mjs` import được `.ts`). Phần gắn DOM ở
`dung-keo-sheet.ts` mỏng đi đúng chừng ấy: đọc toạ độ, ghi `style.height`,
đổi class.

14 ca, ba nhóm:

1. **Phép tính**: chiều cao tối đa, chiều cao trong lúc kéo, bậc khi thả —
   vẩy một bậc, vẩy thắng vị trí, gần nhất, ngang nhau thì giữ ngăn.
2. **CSS ↔ JS khớp nhau bằng số**: `CAO_TOI_THIEU` và `KHE_TREN` xuất hiện ở
   cả hai bên, lệch nhau thì ngăn **nảy một đoạn đúng lúc nhả ngón tay**. Kèm
   `.sheet-rong` phải nằm trong `@media(max-width:1023px)`, cặp `vh`/`dvh` dự
   phòng, `touch-action:none` ở cả hai tay nắm.
3. **Ba lỗi đo được ở trên**: mỗi lỗi một ca canh đúng dòng đã sửa.

`npm run test:js` **278/278**. Đột biến: gỡ từng sửa một → đúng ca tương ứng
đỏ.

### Một ca test từng sai kỳ vọng, và nó siết được code

Ca "màn hình thấp" đỏ với `'thu' !== 'rong'`. Kỳ vọng của test sai, không
phải code sai — nhưng nhân đó siết luôn `mocKhiTha`: khi bậc rộng **thấp hơn
hoặc bằng** bậc thu (màn hình rất thấp, hoặc bàn phím ảo đang chiếm chỗ) thì
bậc rộng **không tồn tại**, và trả `'rong'` ở đó là đặt `aria-expanded="true"`
cho một ngăn không cao lên được chút nào. Trình đọc màn hình sẽ nói sai.

### Bốn cái bẫy nhỏ, ghi lại để không đạp lại

| Bẫy | Hệ quả nếu không biết |
|---|---|
| `setPointerCapture()` ném `NotFoundError` với pointerId không thật | Cử chỉ chết ngay câu đầu khi đo bằng sự kiện tổng hợp → bọc `try/catch` |
| Đặc hiệu CSS: `.sidebar.open.sheet-rong` (3 class) thắng `.sidebar.open` (2 class) | Không khoanh trong `@media(max-width:1023px)` thì nó đè `height:auto` của desktop |
| `npm start` lần hai đỏ `EADDRINUSE`, nhưng `curl` vẫn "server ok" | `TaskStop` giết wrapper npm, không giết node con → **phép đo chạy trên build cũ** |
| `javascript_tool` chặn giá trị trả về khi *tên khoá* trông như khoá bí mật | Đặt tên khoá trung tính, không phải lỗi của trang |

Cái thứ ba là bẫy thật: một phép đo "xanh" trên bản cũ tệ hơn một phép đo đỏ.

### Số đo trên production, sau khi deploy

Khung 390×844 trên `https://oohx.net`, `/map` có **88 thẻ thật**:

| Bước | `/map` | `/explore` |
|---|---|---|
| Mở ra (bậc thu) | 492px | 506px |
| Kéo lên, theo ngón tay | 532 → 572 → 612 → 652 → 692 | — |
| Thả tay (bậc rộng) | **780px** | **780px** |
| Đỉnh ngăn ở bậc rộng | 64px, dưới header | 64px |
| Khung cuộn bên trong | 499px, cuộn được, thẻ cuối ở 824px | 756px, cuộn được |
| Kéo xuống ~200px rồi thả | về 492px, vẫn mở | về 506px, vẫn mở |
| Kéo xuống > 400px rồi thả | đóng | đóng |

Desktop 1280×900 không đổi: `.sidebar` 260×680 và `.map-panel` 380×848 vẫn là
cột `position:static`, tay nắm vẫn `display:none`.

Phép đo local trước đó cho `ds_cuon_duoc: false` — không phải lỗi: API giả chỉ
có 2 màn hình nên danh sách ngắn hơn khung. Chỉ production có đủ bản ghi để đo
cái cần đo.

### Còn mở sau sáng 21/10

| Việc | Chặn bởi |
|---|---|
| **`OOHX_LED_LCU_LAI_CHAU` không có đường nào để tới qua `/map`** | Hai cách sửa, cần chủ dự án chọn: điền tỉnh, hay cho `/map` nhóm "chưa rõ tỉnh" |
| **Chưa ai thử đường gửi yêu cầu thật trên production** | Không còn gì chặn (#136). Cần chủ dự án bấm |
| **Cử chỉ kéo chưa ai thử bằng ngón tay thật** | Phép đo dùng `PointerEvent` tổng hợp trong iframe; quán tính cuộn và tranh chấp với cử chỉ hệ thống chỉ hiện ra trên máy thật |
| **Chưa ai chạy thật lối nhập màn hình ở `/admin`** sau khi sửa kiểu ULID | Hai ca hồi quy đi qua `ScreenWriter` trực tiếp, không qua `ScreenImportService` và hàng đợi |
| **Chưa đo `/api/v1` trên production** | Cần một API client (scope `inventory`) và token của nó |
| **Hai việc nhập liệu còn lại** | Tên hay điểm đặt mới là cái sai ở hàng Aeon · sáu màn hình Yên Bái chung một toạ độ |
| **Độ phủ `ghepLechKhoa` vẫn 0/104** | Chờ lượt nhập lại đầu tiên |
| **Đối tác chưa được báo** về thứ tự mặc định mới của `/owners`, `/sites`, `/screens` | Tài liệu đã ghi, nhưng vẫn chưa biết đối tác nào đang dùng API |
| **Ô tick trên thiết bị cảm ứng chưa ai nhìn tận mắt** | `@media(hover:none)` không kiểm được từ máy tính; chốt chỉ canh quy tắc tồn tại |
| **Nhãn tiếng Việt trên bản đồ** | Cần tạo style trong MapTiler Cloud rồi đưa tên vào `MAPTILER_STYLE` |
| `price_per_slot_vnd` của `/api/v1` mang giá trị `floor_cpm` | Ghi chú F09: đổi là làm sai số tiền trong hệ thống đối tác |
| Báo cáo soát chưa xét giá, ảnh thực địa, kích thước | Trang in câu đó ra để không ai đọc nó như báo cáo đầy đủ |
| Mốc ba cột 1280px thay vì 1180px như plan viết | `.w` lấy 52px mỗi bên |
| Hai định nghĩa CSS trùng (`.loc-item`, `.mpop-price`) | Việc của một lần dọn CSS |

Ngăn "xem lại" (`.ds-panel`) **cố ý** ở ngoài #145: nó không có tay nắm, nên
không hứa cử chỉ nào. Thêm cử chỉ vào đó là một quyết định thiết kế mới, không
phải trả nợ một lời hứa cũ.

---

## Ngày 22/10/2026 — gộp `/explore` và `/map` làm một trang (#147 → #157)

Mười một PR trong một ngày: một plan, bảy lát của plan đó, và ba bản sửa lỗi
do chính bảy lát kia đẻ ra. Plan đầy đủ ở `docs/PLAN-GOP-EXPLORE-BAN-DO.md`;
mục này ghi cái plan không biết trước.

| PR | Việc | Đổi |
|---|---|---|
| #147 | Plan gộp bản đồ, 274 dòng | — |
| #148 | Lát 1 — pin mang loại hình và kích thước | +285/−57 |
| #149 | Lát 2 — một trang, hai kiểu xem | +520/−153 |
| #150 | Lát 3 — bộ lọc thành dải chip | +818/−309 |
| #151 | *sửa* — chip đang lọc nằm ngoài tầm nhìn | +123/−4 |
| #152 | Lát 4 — "Tìm trong khu vực này" + dải tổng quan | +842/−31 |
| #153 | *sửa* — ô khoảng giá bị cắt mất đầu trên | +55/−5 |
| #154 | Lát 5 — thẻ chi tiết mở cạnh danh sách | +647/−11 |
| #155 | Lát 6 — bảng tổng kế hoạch | +754 |
| #156 | Lát 7 — khai tử `/map`, dọn 1.040 dòng | +212/−1.040 |
| #157 | *sửa* — canary deploy chấp nhận đường đã khai tử | +164/−2 |

Bộ chốt: `test:js` **289 → 334 ca**, chốt shell **102 → 108**, chốt đọc HTML
389 → 406 rồi về **380** khi trang `/map` biến mất cùng 26 khẳng định của nó.

### Vì sao gộp, nói lại bằng con số

Cùng một kho 104 vị trí được nhìn qua hai trang, với **hai** bộ lọc
(`BoLocKho` 405 dòng, `BoLocThongMinh` 330 dòng), **hai** bản đồ
(`BanDoKetQua` và `ScreenMap` 398 dòng) và hai cách bày danh sách. Mỗi
lần thêm một chiều lọc là sửa hai chỗ, và hai chỗ đó đã trôi khỏi nhau:
`/map` có thanh tỉnh thành, `/explore` có trạng thái giá, không bên nào đủ.

Đo `/explore` ở 1440×900 trước khi bắt đầu: bọc nội dung 1176px chia thành
lọc 260 (22%, **cao 1358px**) · kết quả 528 (45%, hai thẻ 255px) · bản đồ 340.
Nội dung chính nhận chưa tới một nửa chiều ngang.

Sau bảy lát, cũng ở 1440×900: dải chip **40px**, lưới **4 thẻ 280px**, và
kiểu xem bản đồ có canvas trải hết với panel 400px nổi bên trái.

### Dữ liệu thật quyết định ba ô của bảng tổng, không phải bản vẽ

Lát 4 và lát 6 đều vẽ những con số mà kho hôm nay không có. Đo trên
production, **cả 104 bản ghi**:

| Trường | Có | Hệ quả |
|---|---|---|
| `location.district` | **0/104** | không đếm được quận/huyện |
| `pricing.floor_cpm` | **0/104** | không có CPM nào để gộp |
| lượt xem/ngày | **không có trường** | không có mẫu số cho CPM |
| `pricing.io_rate` | **19/104** | chi phí tính được, cho 19 vị trí |
| `location.city` chứa `>` | 21/104 | `"Thái Nguyên > Phường Bắc Kạn"` |

Nên bảng tổng đổi hai ô (**tỉnh thành** thay quận/huyện, **chi phí** thay CPM
gộp) và để một ô nói thật là chưa có số đo.

**Và một chỗ tôi cố ý làm khác plan.** Plan viết ô lượt xem "để chữ xám —
tổng của những số ước tính". Khảo lại nguồn thì `daily_footfall` đến từ
`app/Console/Commands/SeedInsightsDefaults.php` — một **bảng hằng số viết
tay** theo loại điểm đặt. Cộng 83 con số seed được `2.075.000 lượt/ngày`:
chính xác tới hàng nghìn, đứng trên trang bán hàng, không một phép đo nào
đằng sau. Chữ xám không cứu được điều đó; nó chỉ làm con số bớt to.

### Ba lỗi của tôi đã lên production, và cách chúng bị bắt

Cả ba đều do **soát production sau khi deploy**, không do test.

**Chip đang lọc nằm ngoài tầm nhìn (#151).** Dưới 1024px và trong panel bản
đồ, dải chip cuộn ngang. Mở `/explore?owner=kim-ngan-adv&view=map`: dải dài
898px trong khung 372px, khung cuộn ở 0, chip đang lọc ở offset **541**. Danh
sách bị cắt từ 104 xuống 83 vị trí, màn hình không có một dấu hiệu nào. Sửa:
cuộn tới chip **trái nhất** đang lọc mỗi khi bộ lọc đã áp dụng đổi.

**Ô khoảng giá bị cắt (#153).** Khoảng giá thật của kho là `54,2tr–729,2tr` —
96px trong một ô 73px, ra màn hình là `54,2tr–7…`. Mất đúng đầu trên của
khoảng. Hai nguyên nhân: bốn cột chia đều (hai ô đếm chỉ chứa số ba chữ số),
và số lẻ giữ ở mọi bậc (phần lẻ của `729,2tr` là **0,03%** giá trị). Fixture
có `5tr–40tr` nên vừa thoải mái — đây đúng là thứ tôi đã ghi "chưa đo bằng
pin thật" trong risks của #152.

**Canary deploy đỏ (#157).** Trên VPS có một canary đòi **mọi** context đã
khai trả 200. Lát 7 đổi `/map` thành 301 nhưng `/map` vẫn phải là context của
Next (bỏ khỏi conf thì Laravel trả 404 và backlink cũ chết). Log liệt kê bốn
nguyên nhân khả dĩ, không cái nào đúng — nguyên nhân thứ năm chưa có tên: một
đường được khai tử có chủ đích.

> Bài học chung của cả ba: tôi soát `TRONG_APP`, soát file conf, soát test —
> nhưng **không soát bảng kỳ vọng của một script chạy ở nơi khác**, và không
> đo bằng dữ liệu có hình dạng của kho thật.

### Một lỗi nặng hơn, có từ trước, chỉ lộ ra khi đo

Trước lát 4, trình xử lý `moveend` đóng kín `napPin` của lượt render **đầu
tiên** — tức bộ lọc lúc trang mới mở. Tái hiện trên production:

| Bước | Kết quả |
|---|---|
| Mở `/explore?view=map` | 95 pin |
| Tick `Loại hình = LCD`, Áp dụng | danh sách 20 thẻ LCD, bản đồ 13 pin |
| Thu nhỏ bản đồ hai nấc | 2 lời gọi `/screens/map`, **cả hai mất** `screen_type=lcd` |
| | bản đồ vẽ lại **95 pin toàn kho**, danh sách vẫn 20 thẻ LCD |

Một URL, hai tập dữ liệu, không có gì đỏ lên. Lát 4 khép đường đó vì `moveend`
không gọi API nữa; `napRef` giữ bản mới nhất cho bất cứ ai gắn thêm việc vào
`moveend` sau này.

### Cú bấm phải chặn ở pha CAPTURE

Lát 5 cần bấm một thẻ mà **không** rời trang bản đồ. Bản đầu gắn `onClick`
lên khung cuộn và nó không chạy kịp: thẻ là một `<Link>` của Next, `Link` gắn
`onClick` lên chính thẻ `<a>`, và React gọi handler của con trước của cha —
nên lúc cú bấm tới nơi thì Next đã bắt đầu điều hướng. Bấm một thẻ là cả
trang bản đồ biến mất.

`onClickCapture` + `stopPropagation()` cắt đường tới `onClick` của `Link`.
Ba thứ **không** chặn: bấm giữa, bấm kèm Ctrl/Cmd/Shift/Alt, và cú bấm rơi
vào ô tick lồng trong thẻ.

Đột biến sau khi commit cho thấy một lỗ: đổi `onClickCapture` về `onClick` —
đúng cái lỗi vừa sửa — **không làm đỏ test nào**. Phải thêm
`tests/js/the-chi-tiet.test.mjs` (7 ca) mới có người canh.

### Cách chốt dùng cả ngày: tách phép quyết định ra khỏi DOM

Bốn tệp `lib` mới, mỗi tệp là một phép quyết định gọi được bằng số:

| Tệp | Quyết định | Ca |
|---|---|---|
| `loc-url.ts` | URL của bộ lọc; `view` phải đi qua nguyên vẹn | 13 |
| `khung-nhin.ts` | khi nào dữ liệu bản đồ cũ đi; bốn số của dải tổng quan | 21 |
| `ke-hoach.ts` | cộng tiền kế hoạch; làm tròn kỳ; tỉnh từ chuỗi địa điểm | 15 |
| `the-chi-tiet` (chốt nối dây) | ba dòng cơ chế của thẻ chi tiết | 7 |

Kèm theo là **chốt nối dây** — hàm có thật sự được gọi không — vì một phép
quyết định đúng mà không ai gọi thì không sửa được lỗi nào.

Đột biến đã thử trong ngày, tất cả đều đỏ đúng chỗ: bỏ `view` khỏi URL (2),
gỡ bộ lọc khỏi panel (4), cho `moveend` gọi lại API (1), lấy nhóm đơn vị đầu
thay vì đông nhất (2), `onClickCapture` → `onClick` (1), `pushState` →
`router.push` (1), làm tròn kỳ `ceil` → `round` (2), cộng mục chưa báo giá
như 0 (2), `kyTuNhan('CPM')` trả `'month'` (1), xoá chữ "reach thô" (1), gõ
sai đích chuyển hướng (1).

### Dọn CSS theo từng quy tắc, không theo khối

Lát 7 gỡ 129 dòng CSS. Lớp sống nằm **xen giữa** lớp chết: `.map-canvas`,
`.map-controls`, `.mc-btn`, `.map-popup`, `.mpop-*`, `.mp-drag-bar` đều còn
hai chỗ dùng. Nên phép dọn quét từng selector, đối chiếu với toàn bộ
`webapp/`, `resources/views/` và `tests/`, và chỉ bỏ quy tắc mà **mọi**
selector đều chết.

Một cái bẫy phải xử: `.on`, `.open`, `.sheet-rong` là lớp **trạng thái**, có
mặt trên nhiều phần tử. Bản đầu coi chúng là bằng chứng còn sống, nên
`.mp-card:hover,.mp-card.on` bị tỉa thành `.mp-card.on` thay vì bỏ hẳn.

Và chốt thì **đổi mục tiêu, không nới lỏng**: ca chiều cao `clamp` chuyển từ
`.map-panel.open` sang `.ex-panel.open` và `.ex-ct` (cùng công thức); ca thanh
đầu đổi `['/map']` thành `['/explore?view=map']` vì nó canh *đường đi* chứ
không canh tên đường dẫn; `SeoBaselineTest` không chỉ bớt `/map` khỏi vòng lặp
mà **thêm một khẳng định ngược** — `/map` phải không còn trong sitemap.

### Bốn cái bẫy nhỏ, ghi lại để không đạp lại

- **`git checkout --` xoá cả thay đổi chưa commit.** Sập **hai lần** trong
  ngày khi hoàn nguyên một đột biến: `next.config.ts` và
  `oohx-sync-proxy.sh` đều mất khối mới viết. Commit trước khi đột biến, hoặc
  copy sang scratchpad.
- **Quên tạo nhánh**, hai commit của lát 5 rơi thẳng lên `main` cục bộ. Chưa
  push nên không ảnh hưởng ai; tách bằng `git branch` rồi `git reset --hard
  origin/main`.
- **Heredoc dài trong Bash tool làm hỏng dấu gạch chéo ngược** của regex —
  viết tệp `.mjs` bằng công cụ Write rồi `node <tệp>`.
- **`next build` gọi bằng `npx` từ `webapp/`** dò gốc workspace ra gốc repo và
  đẻ một thư mục `.next` lạc ở đó. Dùng `npm run build`.

### Còn mở sau ngày 22/10

| Việc | Chặn bởi |
|---|---|
| **Cài `oohx-sync-proxy` v8 trên VPS** | Cần một lệnh `install` chạy as root; tôi không có SSH. Tới lúc đó bước đồng bộ proxy còn đỏ ở mọi deploy — ứng dụng không ảnh hưởng |
| **Lát định vị** — xin quyền vị trí khi mở `/explore` | Chủ dự án đã chốt *"xin quyền khi mở trang"*; chưa làm |
| **Plan gộp chưa sửa cho quy mô 10.000 vị trí** | Gom cụm ở máy chủ, bỏ phân trang sâu, chỉ mục không gian cho sắp xếp theo khoảng cách |
| **Plan nhập 10.000 vị trí từ API đối tác** | Cần biết đối tác nào, tài liệu API, kéo hay đẩy, có toạ độ không, có môi trường thử không |
| **Kế hoạch còn ở `localStorage`** | Lên máy chủ là một PR riêng có CSDL và đăng nhập |
| **Nút chữ cạnh giá thay ô tick 24px** (phần còn lại của lát 6) | Thay đổi markup `ScreenCard`, dùng ở bốn trang |
| **Dải chip chưa có dấu hiệu "còn chip bên phải"** | Quyết định thị giác, không phải lỗi |
| **Chi phí chỉ phủ 19/104 vị trí** | Chờ media owner báo giá |
| **Hai ô chờ dữ liệu**: CPM gộp, lượt xem | Chờ Data Engine và `location.district` được đổ thật |
| `owner.name` có dấu cách thừa; `site.city` chứa đường dẫn phường | Dữ liệu, không phải mã |

Những việc còn mở từ các ngày trước — `OOHX_LED_LCU_LAI_CHAU`, đường gửi yêu
cầu thật, cử chỉ kéo bằng ngón tay thật, `/api/v1` trên production, nhãn
tiếng Việt trên bản đồ, `price_per_slot_vnd` mang `floor_cpm` — vẫn nguyên.

Hai định nghĩa CSS trùng **vẫn còn cả hai**, dù lát 7 có dọn CSS: `.loc-item`
(dòng 631 và 2945) và `.mpop-price` (dòng 968 và 2333). Phép dọn của lát 7
chỉ bỏ quy tắc **không còn ai dùng**; hai cặp này thì cả hai bản đều có chỗ
dùng, nên chúng là việc của một lần dọn khác — và lần đó phải đọc xem bản nào
đang thắng ở đâu, chứ không xoá bớt một bản.

---

## Ngày 22/10/2026, chiều — định vị, và một chỗ lệch nó làm lộ ra (#159, #160)

| PR | Việc | Đổi |
|---|---|---|
| #159 | Xin vị trí khi mở `/explore`, hiện màn hình quanh người dùng | +877/−50 |
| #160 | Sắp danh sách theo khoảng cách (`sort=distance`) | +608/−6 |

Chủ dự án chốt **xin quyền khi mở trang**, không sau một cú bấm.

### Ba lưới an toàn của việc hỏi quyền lúc trang vừa mở

Hỏi quyền trước khi người dùng làm gì là cách nhanh nhất để bị bấm "Chặn", và
trình duyệt **nhớ** cú chặn đó cho tới khi người dùng tự vào cài đặt site mà
gỡ. Một lần hỏi sai thời điểm là mất tính năng vĩnh viễn với người đó.

1. **Hỏi đúng MỘT lần cho mỗi trình duyệt.** Cái bẫy không nằm ở "Chặn" mà ở
   **bỏ qua**: người dùng bấm ra ngoài hộp thoại thì trạng thái vẫn là
   `prompt` *mãi mãi*. Không nhớ thì hộp thoại bật ở mọi lượt tải trang —
   đúng hành vi làm Chrome tự chặn quyền của cả site. Đo: lượt tải thứ hai,
   quyền vẫn `prompt`, cờ đã hỏi `=1` → không hộp thoại, không dòng nào.
2. **Chặn hay quanh đó trống thì trang không đổi gì.** Đo: khung quanh người
   dùng trả `total: 0` → bản đồ lùi về khung cả nước và nói ra.
3. **HTML máy chủ không phụ thuộc vị trí.** Máy chủ không biết người dùng ở
   đâu và không được đoán; có khẳng định canh.

`enableHighAccuracy: false`: tính năng chỉ cần biết người dùng ở thành phố
nào. Trên một trang công khai, xin ít hơn mức có thể xin là mặc định đúng.

### Lời hứa không giữ được: đếm tỉ lệ bấm Chặn

Tôi đã hứa đo con số đó khi thuyết phục rằng hỏi-khi-mở-trang là chấp nhận
được. **Không làm được**: app không có một đường đo đạc nào (`grep` cho
gtag/analytics/telemetry ra rỗng), và dựng một endpoint đo đạc chạm quyền
riêng tư và CSDL — đó là quyết định của chủ dự án, không phải thứ gắn kèm một
lát giao diện.

Nghĩa là **chúng ta không biết** bao nhiêu người bấm Chặn, mà đó đúng là con
số đáng lo nhất của quyết định này. Ghi lại ở đây để nó không im lặng trôi đi.

### Chỗ lệch mà định vị làm lộ ra, và nó có từ trước

Soát production sau khi #159 lên: bản đồ bay tới Hà Nội với 13 vị trí quanh
người dùng, còn **thẻ đầu tiên trong danh sách bên cạnh ở Thái Nguyên**. Hai
tập dữ liệu cạnh nhau trên một màn hình.

Chỗ lệch đó không mới — danh sách luôn do máy chủ dựng (hàng rào SEO của lát
2), còn bản đồ luôn theo khung nhìn. Trước đây bản đồ mở ở khung cả nước nên
hai bên trông khớp. Định vị không tạo ra lỗi; nó làm một giả định cũ hiện ra.

### Toạ độ KHÔNG đi vào URL trang — ràng buộc dẫn dắt #160

Cách rẻ nhất để sắp lại danh sách là đẩy toạ độ vào `/explore?near_lat=…` rồi
để máy chủ dựng. **Không làm.** URL của một trang công khai rơi vào log máy
chủ, vào header `Referer` của mọi tài nguyên bên thứ ba, và vào nút chia sẻ.
Vị trí của một người không thuộc về chỗ đó.

Nên trang do máy chủ dựng vẫn là toàn kho — cũng là thứ giữ cho nó được index
— và phần sắp theo khoảng cách do **trình duyệt** gọi sau khi người dùng cho
phép. Hai ca chốt canh: không tệp nào đặt `near_lat` gần một phép điều hướng.

### Một lỗi bị chặn TRƯỚC khi lên production

`?q=…&sort=distance` trả **500**. Join `sites as sort_site` làm cột `name`
trong bộ lọc tìm kiếm nhập nhằng — `sites` cũng có cột `name`, và MySQL từ
chối cả truy vấn: *Column 'name' in where clause is ambiguous*.

Ca `test_bo_loc_van_chay_cung_voi_sap_theo_khoang_cach` bắt được nó ở lượt
chạy đầu tiên. Không có ca đó thì lỗi chỉ lộ ra khi có người **vừa gõ tìm
kiếm vừa sắp theo khoảng cách** — một tổ hợp không ai thử tay.

Đây là lỗi duy nhất trong ngày bị chặn trước khi deploy. Ba lỗi khác
(#151, #153, #157) đều phải lên production rồi mới bị soát ra.

### Ba cái bẫy của phép sắp theo khoảng cách

| Bẫy | Hỏng ra sao |
|---|---|
| `NULL` xếp **đầu** trong MySQL | Màn hình chưa có toạ độ đứng trên cùng của danh sách "gần tôi nhất" |
| `ACOS` của số lớn hơn 1 trả `NULL` | Sai số dấu phẩy động khi hai điểm **trùng nhau** đẩy tích cosin vượt 1, nên màn hình ở đúng chỗ người dùng đứng rơi xuống **cuối** |
| `sort=distance` thiếu toạ độ | Lặng lẽ rơi về thứ tự mặc định = một danh sách sai thứ tự mà trông bình thường → trả **422** |

### Một lỗ trong chốt của chính tôi

Đột biến bỏ sàn `Math.max(0.01, cos(lat))` **không làm đỏ ca nào**. Ở cực
`cos` không ra 0 mà ra 6,1e-17, nên khung rộng 2,2e15 độ — hữu hạn, và
`Number.isFinite` xanh. Ca phải đổi sang đo **độ rộng** mới bắt được, và
docblock cũng phải sửa: cái sàn đó chặn một khung nhìn vô lý, không chặn
`Infinity`.

### Số đo trên production, với vị trí thật

Chrome có sẵn quyền cho `oohx.net`, nên luồng chạy đủ — lần đầu nó chạy với
toạ độ thật thay vì `getCurrentPosition` giả lập.

| | |
|---|---|
| Kiểu lưới | *"13 vị trí trong 15km quanh bạn · Xem trên bản đồ"* |
| Kiểu bản đồ | bay tới Hà Nội, pin thật |
| Danh sách panel | `1,9km` → `2,7km` → `3,1km`, đều Hà Nội |
| Lọc LCD + sắp | 20 vị trí, đúng loại, `56,8km` → `1.148,6km` |
| `?q=…&sort=distance` | 200 (ca từng 500) |
| `sort=distance` thiếu toạ độ | 422 |
| URL trang | **không có toạ độ** |

### Hai điều đúng nhưng dễ đọc nhầm

**"Sắp theo khoảng cách" không phải "quanh tôi".** Lọc LCD xong, thẻ xa nhất
của trang đầu cách **1.148km**. Phép sắp trả toàn bộ tập khớp bộ lọc, gần
nhất trước, nên trang đầu luôn là 24 cái gần nhất và không bao giờ rỗng. Một
bán kính cứng đúng chữ "xung quanh" hơn nhưng có thể trả về trống.

**Hai con số cạnh nhau trả lời hai câu khác nhau.** Dải tổng quan nói
`13 Vị trí` (trong khung nhìn bản đồ), thanh kết quả nói `Hiển thị 104 vị
trí` (toàn bộ khớp bộ lọc). Mỗi cái có nhãn riêng và đều đúng, nhưng chúng
cách nhau 40px.

### Còn mở sau chiều 22/10

| Việc | Chặn bởi |
|---|---|
| **Không biết tỉ lệ bấm Chặn** | Không có đường đo đạc nào; dựng nó là một quyết định riêng |
| **Chưa bấm "Cho phép" thật trong một phiên sạch** | Hộp thoại quyền là giao diện trình duyệt; công cụ tự động không chạm được. Phiên đo dùng quyền Chrome đã lưu sẵn |
| **Danh sách không theo khung nhìn** | Kéo bản đồ đi nơi khác thì danh sách vẫn "gần bạn nhất" |
| **Haversine chạy trên từng hàng** | Ở 104 bản ghi không đo được gì; ở 10.000 cần lọc thô bằng bounding box trước |
| **Hai lượt nạp pin** khi vào thẳng `?view=map` có vị trí | Khung cả nước rồi khung người dùng |
| `.ds-panel` vs dải tổng quan: hai con số cạnh nhau | Xem mục trên |

Mọi việc còn mở của buổi sáng vẫn nguyên, trừ **lát định vị** (xong) và
**cài `oohx-sync-proxy` v8** — chủ dự án báo đã chạy lệnh `install` trên VPS
chiều 22/10; lượt deploy kế tiếp sẽ in ra phiên bản script và xác nhận.

---

## Ngày 23/10/2026 — đo trước khi sửa, và ba giả thuyết sai (#163, #165)

| PR | Việc | Đổi |
|---|---|---|
| #163 | Plan cho quy mô 10.000 vị trí + lát 0 (dựng kho giả và bấm giờ) | +446/−8 |
| #165 | Lát A — bản đồ gom cụm ở máy chủ | +1.003/−81 |

Hai quyết định chủ dự án chốt sáng 23/10: **`sort=distance` giữ nguyên ngữ
nghĩa** (không bán kính, danh sách không bao giờ rỗng) và **làm lát 0 trước**.

### Vì sao lát 0 không sửa gì cả

Kho thật có hơn 10.000 billboard; mọi con số của plan gộp đo trên **104 bản
ghi** production. Tôi mang vào ba giả thuyết đọc từ mã nguồn — và nói thẳng
trong plan rằng đó là giả thuyết, không phải phép đo, nên lát đầu tiên dựng
chỗ để đo chứ không tối ưu.

Hai lệnh mới: `oohx:gia-lap-kho` gieo kho **dồn cục theo đô thị** (30% Hà Nội,
28% TP.HCM), `oohx:bam-gio-kho` bấm giờ sáu đường kèm `EXPLAIN`.

Phân bố dồn cục không phải chi tiết trang trí. Toạ độ rải đều cho số đo **lạc
quan sai**: mọi ô lưới có số đếm gần như nhau nên gom cụm trông dễ, và mọi
khung nhìn cắt theo tỉ lệ diện tích nên lọc trông chọn lọc tốt. Có một ca chốt
canh điều đó, và nó đỏ nếu ai đó làm phẳng bảng trọng số.

### Số đo

| Đường | 10.000 | 50.000 | Dốc |
|---|---|---|---|
| `map` cả nước | 97ms | 474ms | 4,9× |
| `map` một quận | **10ms** | 27ms | 2,7× |
| `sort=distance` trang 1 | 68ms | 361ms | 5,3× |
| `sort=distance` trang cuối (417) | 66ms | 388ms | 5,9× |
| `q` khớp mọi hàng | 42ms | 258ms | 6,1× |
| `filters` cache nguội | 100ms | **717ms** | **7,2×** |

**Ở 10.000 không đường nào vượt 300ms.** Khoảng dư chừng 3 lần; đọc là 2 lần
cho an toàn, tức quanh 20.000 thì đo lại.

### Ba giả thuyết của tôi sai

**`whereHas` chặn chỉ mục `(lat, lon)` — SAI.** `EXPLAIN` cho thấy optimizer
viết lại `EXISTS` thành semi-join, chọn `sites` làm **bảng dẫn**, quét khoảng
trên đúng chỉ mục đó và phủ được truy vấn bằng nó (`Using index`). Từ 298 site
nó mới dò sang `screens`. Đường tôi nghi nhất lại là đường **co giãn tốt nhất**
cả bảng. Lát C xoá khỏi plan, không hoãn.

**"Phân trang sâu là chỗ chậm nhất" — SAI.** Trang 417 mất 66ms, không chậm
hơn trang 1: `OFFSET 9.984` không thêm gì đáng kể vì phép sắp toàn tập đã phải
chạy dù trang nào.

**"Thiếu chỉ mục" — SAI.** Soát migration trước khi đoán: `sites` đã có
`(lat, lon)` và `city`, `screens` đã có `(active, site_id)`,
`screen_inventory` đã có `(screen_id, floor_cpm)`. Chỗ trống đúng là ba chỗ,
không phải "thiếu index".

Và một đường **không có trong ba giả thuyết** lại dốc nhất: `filters` cache
nguội, 7,2×. Nó lộ ra chỉ vì bảng đo có cột cho nó.

> Đó là toàn bộ lý do lát 0 tồn tại. Nếu làm thẳng lát B/C/D theo trực giác thì
> ba trong bốn lát sửa thứ không đau, và thứ sẽ đau trước tiên thì không ai
> nhìn.

### Cái sai NẶNG nhất không phải chậm, mà là sai (lát A)

`getMapPins()` cắt bằng `orderBy('screens.id')->limit()`. ULID tăng theo thời
gian tạo, nên "500 pin" là **500 màn hình nhập vào sớm nhất**:

```
trả về 500 / tổng 10000 · bị cắt 9500
id lớn nhất trong tập trả về đứng thứ 500 / 10.000 theo thứ tự nhập
→ bản đồ KHÔNG thấy 9.500 màn hình nhập sau đó
```

Ranh giới nằm **chính xác** ở vị trí thứ 500 theo thứ tự nhập. `meta.truncated`
có nói là đã cắt, nhưng không nói rằng phần bị cắt có hệ thống. Người mua rà cả
nước nhận một câu trả lời nhanh (97ms), gọn, và sai — không log, không gì đỏ.

**Lát A là lát duy nhất trong plan đáng làm kể cả khi số đo nói mọi thứ đủ
nhanh**, vì nó không sửa tốc độ.

Cách sửa: hai chế độ, `meta.mode` nói rõ chế độ nào. Trong ngân sách pin thì
trả pin (không giấu gì), trên ngân sách thì trả ô lưới có số đếm (không cắt
gì). **Ngưỡng là mật độ thật trong khung nhìn, không phải một mức thu phóng** —
zoom là một phỏng đoán về mật độ, đếm thì không.

`data` và `clusters` là hai khoá riêng, có chủ ý: một client cũ chưa biết
`clusters` vẽ **bản đồ trống** — hỏng thấy được ngay — thay vì vẽ 500 pin thiên
lệch và trông như đã xong.

| Đường | 10.000 | 50.000 | Truy vấn |
|---|---|---|---|
| `map` cả nước — pin (trước) | 143ms | 548ms | 7 |
| `map` cả nước — **cụm** (sau) | **24ms** | **151ms** | **1** |

Một `GROUP BY` thay cho `COUNT(*)` + 500 pin + 8 lượt nạp quan hệ. Và vì tổng
`count` của các ô *chính là* con số `countMapPins()` trả về, hai truy vấn thành
một — quan trọng hơn: hai con số không thể trôi khỏi nhau nữa vì chỉ còn một.

### Bốn chỗ suýt sập, không chỗ nào thấy bằng mắt

**1. Phóng to vào giữa vùng đã nạp không nạp lại.** Luật `canNapLai` là "chứa
trong", và khung mới nằm gọn trong khung cũ — nên bản đồ **kẹt ở cụm mãi mãi**
dù người dùng phóng tới sát mặt đường. Cờ `daCat` (bật khi chạm trần 500) đổi
thành `chuaDayDu` (bật ở chế độ cụm): cùng một lưới an toàn, đổi điều kiện bật.

**2. Một ca chốt xanh một cách rỗng.** `test_cat_co_thu_tu_xac_dinh` so hai tập
pin bị cắt. Ở chế độ cụm `data` rỗng nên phép so thành `[] === []` — nó **ngừng
canh gì cả mà không đỏ**. Chuyển sang so thứ tự cụm, và thêm `ORDER BY` xác
định vào truy vấn gom.

**3. Thang ô lưới cạn ở đầu mịn.** Dừng ở 0,002° nên khung 0,0045° (≈500m) chỉ
ra **4,5 ô ngang**. Ca chốt bắt, không phải mắt tôi. Nối tới 0,0001° (≈11m).

**4. Đột biến `AVG` → `MIN` cho tâm cụm: cả 30 ca vẫn xanh.** Tâm cụm là trung
bình toạ độ chứ không phải tâm ô — một lựa chọn đã ghi trong docblock mà
**không có gì giữ**. Thêm ca, đột biến lại thì đúng một ca đỏ.

### Hai lần đột biến cho tín hiệu RỖNG

Đáng ghi riêng, vì cả hai lần tôi suýt báo cáo "ca này có canh":

- Làm phẳng bảng trọng số đô thị làm ca phân bố đỏ — nhưng **vì một ngoại
  lệ**: `bangTrongSo()` rút `random_int(0, 99)` vì bảng tình cờ tổng đúng 100,
  nên bảng co lại 16 ô và lệnh chết giữa đường sau khi đã ghi vài nghìn hàng.
  Sửa (rút theo `count()`) rồi đột biến lại mới có tín hiệu thật.
- Đổi tâm cụm sang tâm ô làm sai số binding và **vỡ SQL**, nên 25 ca đỏ vì
  truy vấn crash chứ không vì ngữ nghĩa. Bản đột biến hợp lệ (`AVG`→`MIN`) mới
  lộ ra lỗ ở mục 4 trên.

Bài học: một đột biến làm đỏ **không** đủ. Phải đọc *vì sao* nó đỏ.

### Số đo trên production sau deploy

104 bản ghi, ngân sách 300, nên chế độ mặc định **không bao giờ vào nhánh
cụm**. Buộc bằng `limit` thấp để chạy thật nhánh đó:

| | Mặc định | `limit=10` |
|---|---|---|
| `mode` | `pin` | `cluster` |
| `data` | 104 pin | 0 |
| `clusters` | 0 | **16 cụm, tổng `count` = 104** |
| `cell_deg` | `null` | `1` |

Tổng `count` bằng đúng 104 — không mất một màn hình nào, đúng tính chất mà
đường pin cũ vi phạm khi kho lớn. Cụm dày nhất **55 ở `10.785, 106.680`**
(TP.HCM), rồi Hà Nội 13, Đà Nẵng 9 — khớp phân bố thật, và tâm cụm chỉ vào chỗ
dày chứ không vào tâm ô. Khoá của một cụm đúng là `["lat","lng","count"]`.

### Còn mở sau 23/10

| Việc | Chặn bởi |
|---|---|
| **`filters` cache nguội** — dốc nhất bảng (7,2×) | Chưa làm. Sẽ chạm 300ms **trước** mọi lát còn lại, và cách sửa rẻ hơn lát A nhiều |
| **Cú bấm vào vòng tròn cụm chưa ai bấm bằng chuột** | Production không vào nhánh cụm ở chế độ mặc định; chỉ có chốt tĩnh và chốt API canh |
| **Lát B** (khung nới dần) | Không làm — 68ms ở 10.000. Đo lại quanh 20.000 |
| **Lát D** (`FULLTEXT`) | Không làm — 42ms ở ca xấu nhất của `LIKE` |
| **Ba lớp chặn production của `oohx:gia-lap-kho` chưa đột biến được** | Auto mode classifier chặn mọi lần sửa dòng chốt chặn, cả chiều nới lẫn chiều siết |
| **`oohx:bam-gio-kho` chưa chạy trên production** | Lượt đo duy nhất không mang mấy cái lệch của Docker cùng máy |
| **`meta.truncated` là khoá luôn `false`** | Đã đánh `deprecated` trong OpenAPI, chưa gỡ vì là hợp đồng cũ |

Mọi việc còn mở của 22/10 vẫn nguyên, trừ **Haversine chạy trên từng hàng** —
nay đã đo: 68ms ở 10.000, nên không còn là việc phải làm, chỉ là việc phải đo
lại khi kho lớn hơn.
