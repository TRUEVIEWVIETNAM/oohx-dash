# Lộ trình sau giai đoạn 0

Cập nhật 23/10/2026 · Viết bởi Claude Code · Trạng thái hiện tại ở `audit-5-vung-2026-09-23/STATUS.md` · Nhật ký từng ngày ở [`nhat-ky/`](nhat-ky/) — xem mục cuối tệp này

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

## Mục mới 24/10/2026 — cả kho hiện ra bằng hai tấm ảnh

Plan riêng: **[`docs/PLAN-ANH-VI-TRI.md`](PLAN-ANH-VI-TRI.md)** — sáu lát, bốn
quyết định chờ chốt.

Lát 5 của `PLAN-CHI-TIET-VI-TRI.md` thêm `photo_source` vào
`GET /api/v2/screens/{slug}`, tức lần đầu payload nói ảnh đến từ **bậc nào**
của chuỗi dự phòng. Đo ngay sau khi nó lên production, trên cả 104 vị trí:

```
104/104  owners/covers/…    ← ảnh bìa chủ sở hữu, không phải ảnh biển
     83  Kim Ngân ADV       (một tệp .jpg duy nhất)
     21  AdTRUE Platform    (một tệp .webp duy nhất)
      0  screen-photos/…
```

Hai tấm ảnh cho cả kho. `/explore` in cùng một tệp **42 lần** trong một khung
nhìn. Và thư mục mà biểu mẫu tải ảnh của Filament ghi vào — `screen-photos/` —
xuất hiện **0 lần**: đường tải ảnh có đủ (nhiều ảnh, kéo thả xếp lại, ở cả
`/admin` và `/publisher`) và **chưa từng được dùng cho một màn hình nào**.

Đây là con số đo được của đúng điều mục 8 `CLAUDE.md` cảnh báo, và nó nối lại
với ghi chú ngày 18/10 ở mục "Mục mới 14/10/2026" phía trên: **0/50** bản ghi
có ảnh thực địa riêng. Sáu ngày sau, mẫu đầy đủ cho **0/104**.

Plan còn lộ ra **một lỗi đang sống**: `ScreenDetailResource` dựng URL ảnh bằng
`asset('storage/' . $p)` vô điều kiện, trong khi hai accessor của `ScreenSpec`
xử lý đúng URL đầy đủ. Nên một hàng `photos: ["https://…"]` — đúng thứ cột nhập
kho `spec.photo_url` nhận, và cột đó **không có luật xác thực nào** — cho ảnh
hiện bình thường ở thẻ danh sách và **vỡ** ở trang chi tiết. Hai đường đọc cho
cùng một màn hình trả dữ liệu khác nhau: hình dạng lỗi R38. Lát 1 của plan sửa
nó, và nó phải lên **trước** mọi đường nhập ảnh.

---


## Nhật ký công việc — đã tách ra khỏi tệp này (23/10/2026)

Tệp này chạm **2.988 dòng**, và 61% trong đó không phải lộ trình: đó là nhật ký
từng ngày đã làm gì. Hai loại tài liệu ấy được đọc vào hai lúc khác nhau — lộ
trình để biết **sắp làm gì**, nhật ký để biết **vì sao chỗ này thành ra thế
này** — nên chúng nay là hai chỗ.

Tệp này giữ phần **kế hoạch**: bức tranh tổng thể, tám giai đoạn, việc quản
trị, mười cái chốt tự tìm phạm vi, bảy câu hỏi nghiệp vụ đang chặn việc, và
mục "Đề xuất làm gì ngay".

| Nhật ký | Nội dung |
|---|---|
| [`nhat-ky/2026-10-13-hang-rao-mang-luoi.md`](nhat-ky/2026-10-13-hang-rao-mang-luoi.md) | Hàng rào mạng lưới (#83) có hiệu lực thật trên dữ liệu cũ — **kèm câu truy vấn** để biết hàng pivot nào vừa bị thu hẹp tầm nhìn kho |
| [`nhat-ky/2026-10-18-responsive-va-trang-chu.md`](nhat-ky/2026-10-18-responsive-va-trang-chu.md) | Mười hai PR, và **bốn lần cùng một hình dạng lỗi** trong một ngày; rồi responsive, thang chữ tám nấc, bố cục trang chủ (#107 → #118) |
| [`nhat-ky/2026-10-19-20-explore-quyet-dinh-mua.md`](nhat-ky/2026-10-19-20-explore-quyet-dinh-mua.md) | `/explore` từ trang liệt kê thành trang ra quyết định (#126 → #134); ba việc lấy khỏi hàng đợi; bốn lỗi mobile từ hai tấm ảnh |
| [`nhat-ky/2026-10-21-22-gop-explore-ban-do.md`](nhat-ky/2026-10-21-22-gop-explore-ban-do.md) | Ngăn kéo kéo được bằng ngón tay (#144, #145); gộp `/explore` với `/map` (#147 → #157); xin quyền vị trí khi mở trang (#159, #160) |
| [`nhat-ky/2026-10-23-do-dac-va-quy-mo.md`](nhat-ky/2026-10-23-do-dac-va-quy-mo.md) | Lát 0 của plan 10k — **ba giả thuyết của tôi sai**, và đường dốc nhất không nằm trong số đó (#163, #165); rồi `filters` cache nguội, gộp menu, và đường đo đạc ba lát (#166 → #171) |

Phép tách chỉ **di chuyển** chữ, không sửa một câu nào: ghép năm tệp lại sau
tệp này cho ra **đúng** bản cũ, từng dòng. Cách kiểm nằm trong commit tách.

> **Nhật ký mới viết vào `docs/nhat-ky/`, một tệp cho mỗi mốc việc liền mạch
> — đừng nối tiếp vào tệp này nữa.** Tệp này dài ra khi *kế hoạch* đổi, không
> khi có thêm một ngày làm việc. Đó là điều làm nó chạm 2.988 dòng.

---
