# Lộ trình sau giai đoạn 0

Cập nhật 09/10/2026 · Viết bởi Claude Code · Trạng thái hiện tại ở `audit-5-vung-2026-09-23/STATUS.md`

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
| ~~5~~ | ~~API v2 + OpenAPI~~ **XONG 03/10** | M | — (mốc 1, 2, 3 xong. **Sửa 10/10:** bảy trang khu người mua đã đọc API, nhưng còn **bốn** trang luồng đặt chỗ render ở máy chủ — xem "SỬA 10/10/2026" trong giai đoạn 5) |
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

---

## Đóng nợ — hủy/hoàn tiền có lối vào thật (30/09/2026)

Xen giữa mốc 2 và mốc 3, vì đây là chỗ duy nhất tôi **báo xong mà chưa xong**.

Giai đoạn 1 tôi báo "hủy và hoàn tiền: xong". Thực tế `CancellationService` không được gọi từ đâu cả — không controller, không route, không Filament action, chỉ có test của chính nó. Chính sách bậc thang đã chốt (≥14 ngày 100%, 7–13 ngày 50%, <7 ngày 0%) chỉ tồn tại trong test. Khách muốn hủy thì không ai bấm được, và với một sàn đang nộp hồ sơ TMĐT thì hoàn tiền là nghĩa vụ chứ không phải tiện ích.

Đã có, ba lối vào: người mua tự hủy từ `/my/campaigns/{campaign}` kèm số tiền hoàn dự kiến do máy chủ tính; quản trị sàn hủy hộ từ `/admin` và có danh sách hoàn tiền; media owner thấy nghĩa vụ hoàn tiền của mình ở `/publisher` và khai "đã hoàn". 23 test mới, CI xanh (run `36709847798`, 550 test, 0 lỗi).

Hai thứ rút ra, ghi lại để không lặp:

- **Bản đầu tôi lại viết hai bộ luật quyền** — phép kiểm nằm trong cả hai `RefundResource`, và sẽ thành ba bộ khi có endpoint v2. Gom về `RefundPolicy` (CLAUDE.md mục 4). Cũng phát hiện `TenantPermission::check()` đọc `auth()->user()`, nên một policy dùng nó sẽ trả lời về người đang đăng nhập chứ không về người được hỏi.
- **Test "nhả suất về kho" dựng `BookingLine` bằng tay thì xanh giả**: không có `InventoryHold` nào nên không có gì để nhả. Phải đi qua giỏ hàng thật.

**Chưa làm:** không có thông báo (email/in-app) khi một dòng bị hủy — media owner phải tự mở trang mới thấy nghĩa vụ hoàn tiền. Cũng chưa có hạn xử lý cho khoản `pending`, nên một khoản có thể chờ mãi mà không ai bị nhắc.

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

## Tám cái chốt tự tìm phạm vi (ghi 10/10/2026)

Mỗi cái tự khám phá phạm vi của nó, nên thêm một trang / một bảng chữ / một
endpoint là nó tự vào tầm. Ghi ở đây vì chúng là thứ quyết định việc **lần sau
sửa gì thì đỏ** — và vì năm trong số chúng từng có lỗ, mỗi lỗ chỉ lộ ra khi mở
rộng phạm vi.

| Chốt | Canh trục nào |
|---|---|
| `class-css.test.mjs` | mọi tên class trong Blade đều có người định nghĩa trong `frontpage.css` |
| `NhanEnumMotNoiTest` | enum CSDL ↔ `<CỘT>_LABELS`, hai chiều; cột `varchar` thì đối chiếu với hằng model |
| `TheEnumPhaiCoChuTest` | thẻ `->badge()` trên cột enum phải có chữ; danh sách nợ giờ **trống** |
| `MocDomTrangBladeTest` + `bo-khung.mjs` | móc DOM khai một chỗ, hai phía cùng đọc |
| `TruongTrangDocTest` | trường trang đọc ↔ schema của **đúng** endpoint, cả GET và GHI |
| `ChuPanelPhaiCoDauTest` | chữ hiển thị trong PHP của cả ba panel phải có dấu tiếng Việt |
| `ChuBladePanelPhaiCoDauTest` | chữ trong tệp Blade của panel |
| CI `Kiểu TypeScript còn khớp OpenAPI` | `api-v2.d.ts` sinh lại phải khớp bản đã commit |

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

1. ~~**Mốc 3 của giai đoạn 5.**~~ **XONG 03/10**, và lý lẽ của nó **đã thành** — cập nhật 09/10/2026. Lúc viết mục này, nhóm cần quyền chưa có bên tiêu thụ nào và lựa chọn còn mở. Nay đã chọn, và đã làm năm lần: giỏ hàng, thanh toán, chi tiết chiến dịch, danh sách chiến dịch, trang đầu — tất cả gọi `/api/v2` **từ trình duyệt**, không từ PHP. Nên tầng DTO/phân trang/lỗi có lưu lượng thật chạy qua, và nó đã bắt được ba lỗi mà test không bắt: VAT nhân hai lần ở trang giỏ, hai trong ba trạng thái nội dung hiện tiếng Anh, và khe phạm vi của trang đầu.

   ~~Còn hai trang đọc chưa chuyển (`report`, `settings`)~~ — **cả hai XONG**: `report` ở PR #57 (07/10), `settings` ở PR #71 (10/10). `settings` là trang đầu tiên của khu người mua **ghi** qua API, không chỉ đọc.

   **Nhưng còn bốn trang nữa mà mục này chưa từng kể** — xem "SỬA 10/10/2026" ở giai đoạn 5. Ba trong bốn nằm trên đường tiền.
2. ~~**Ban hành ba trang chính sách.**~~ **XONG 09/10/2026** (PR #56): `quy-che-hoat-dong`, `chinh-sach-bao-mat`, `giai-quyet-tranh-chap` đều `effective_from = 09/10/2026`, phiên bản `1.0`. `bang-phi` giữ `22/09/2026`.
3. **Cho Codex review khối từ R41 tới nay** (**151** commit kể từ vòng review 30/09, đếm lại 10/10): API v2, giỏ hàng, hoàn tiền, đường tiền, và nay thêm bảy trang khu người mua đọc API cùng tám cái chốt tự tìm phạm vi. Đúng loại code nên có người thứ hai đọc.

### Mục mới 10/10/2026 — bốn trang luồng đặt chỗ

Thứ tự tôi đề xuất, và lý do:

1. **`booking/create`** — rẻ nhất, hai endpoint đều đã có, và nó cùng hình dạng với trang `settings` vừa làm (biểu mẫu + khung chờ + ô `disabled` tới khi dữ liệu về).
2. **`booking/payment-success`** — 42 dòng, chỉ đọc. Cần một cách trỏ tới đúng một lần trả.
3. **`booking/creative`** — cần xem DTO `campaigns/{campaign}` có đủ `creatives` và `lines`; trang này còn tải tệp lên, nên nó là bài khó hơn hai cái trên.
4. **`booking/review`** — để cuối, không vì nó khó nhất mà vì `$conflicts` **chưa có endpoint nào**, và nó là trang người mua xác nhận nghĩa vụ tiền. Một con số sai ở đây đắt hơn ở ba trang kia cộng lại.

Làm cả bốn hay chỉ một là **quyết định phạm vi**, không phải việc tự chọn: trang thứ tư kéo theo một endpoint mới cho `$conflicts`, và trang thứ ba kéo theo đường tải tệp.

**Lưu ý thứ tự:** mốc SEO, sinh TypeScript và cấu hình proxy OpenLiteSpeed (01–03/10) đều thuộc **giai đoạn 6**, làm trước khi xong giai đoạn 5. Chúng không vô ích — mốc SEO là điều kiện "xong" của giai đoạn 6, và cấu hình proxy đã ghi lại hạ tầng thật khác với giả định Caddy trong lộ trình — nhưng chúng không đưa giai đoạn 5 tiến thêm bước nào.
