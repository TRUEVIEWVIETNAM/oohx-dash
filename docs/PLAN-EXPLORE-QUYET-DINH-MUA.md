# `/explore` — từ trang liệt kê thành trang ra quyết định

Viết 19/10/2026 · Claude Code · Chủ dự án đã chốt cả ba hướng ngày 19/10/2026

Mock đã duyệt: <https://claude.ai/artifact/5ytRQifieU8xwhGek7T7bc>

## Vấn đề, nói bằng con số đo được

Chủ dự án đặt câu hỏi ngày 18/10/2026: *"nếu chỉ listing thôi nghĩ rất khó để
buyer quyết định mua"*. Đo trên production cùng ngày (lấy 50 trong 104 bản ghi
qua `GET /api/v2/screens`) thì câu đó không phải cảm tính:

| Thứ người mua cần để quyết | Tỉ lệ bản ghi có |
|---|---|
| Giá | **1/50** |
| Ảnh thực địa riêng | **0/50** — cả 50 dùng chung **2 tấm** ảnh bìa của owner |
| Quận/huyện | 0/50 |
| `insights.*` (lưu lượng, tiếp cận) | 0/50 |
| Kích thước | 30/50 |
| Toạ độ, thông số kỹ thuật, lịch trống | 50/50 |

Hệ quả trực tiếp: `GET /api/v2/filters` trả `price_range: {min: 0, max: 0}`,
tức **bộ lọc giá đang chết** — nó không lọc được gì vì không có gì để lọc.

Nên vấn đề của `/explore` không phải bố cục. Một trang danh mục thương mại
điện tử giả định *giá có sẵn, ảnh có sẵn, người mua tự so sánh rồi bấm mua*.
Ở kho này không điều nào đúng. Trang đang mời người mua làm một việc
(so sánh và chọn) mà dữ liệu không cho phép làm.

**Việc người mua thật sự làm được hôm nay là: khoanh một danh sách theo vị trí
và loại hình, rồi hỏi giá.** Ba hướng đã chốt đều phục vụ đúng việc đó.

## Ba hướng đã chốt

| # | Hướng | Nó giải quyết gì |
|---|---|---|
| 1 | **Danh sách rút gọn → yêu cầu báo giá hàng loạt** | 103/104 vị trí chưa có giá. Hỏi giá là bước thật, nên nó phải là hành động chính của trang, không phải một ô liên hệ ở chân trang |
| 2 | **Bản đồ nằm cạnh danh sách** | Vị trí là chiều duy nhất dữ liệu đủ 100%. Agency lên plan theo tuyến đường, không theo thứ tự bảng |
| 3 | **Lọc theo trạng thái giá** | Thay thanh trượt giá không trượt được bằng hai con số trung thực: *đã có giá 1* · *chưa báo giá 103* |

## Những gì đã có sẵn, không phải dựng lại

Khảo sát mã nguồn trước khi chia lát — ba thứ tưởng phải làm thì đã có:

- **Bản đồ lọc theo đúng bộ lọc của danh sách.**
  `FrontpageService::mapPinQuery()` gọi `buildScreenQuery($request)`, tức
  `GET /api/v2/screens/map` nhận y hệt tập tham số của `GET /api/v2/screens`.
  Lát bản đồ **không cần sửa backend**.
- **Thành phần bản đồ.** `webapp/components/ScreenMap.tsx` (672 dòng) đã nối
  Leaflet + MapTiler, đã có lớp tile dự phòng. Dùng lại, không viết mới.
- **Giới hạn cứng.** `MAX_MAP_PINS = 500`, `MAX_PER_PAGE = 50` đã khai trong
  `CatalogController`.

Và một thứ tưởng dùng lại được nhưng **không**: `Cart`/`CartItem`. Giỏ hàng
buộc phải đăng nhập, phải thuộc một tổ chức (`middleware('buyer')`), và dòng
giỏ cần **giá** để tồn tại. Với 103/104 vị trí chưa có giá, nhét danh sách rút
gọn vào giỏ là tạo ra những dòng giỏ không định giá được. Danh sách rút gọn là
thứ khác: nó là **câu hỏi**, giỏ hàng là **đơn**.

## Bảy lát

Mỗi lát là một PR, chạy CI đầy đủ, merge riêng. Thứ tự là thứ tự phụ thuộc.

| Lát | Nội dung | Đụng gì | Ước lượng |
|---|---|---|---|
| 1 | Bộ lọc trạng thái giá — API | Laravel + OpenAPI | nhỏ |
| 2 | Cột lọc trái ở `/explore` | Next + CSS | vừa |
| 3 | Bản đồ cạnh danh sách | Next + CSS | vừa |
| 4 | Danh sách rút gọn (phía trình duyệt) | Next | vừa |
| 5 | Yêu cầu báo giá — CSDL, API, Filament | Laravel + Filament | **lớn** |
| 6 | Màn hình gửi yêu cầu | Next | vừa |
| 7 | Tải danh sách về máy | Laravel + Next | nhỏ |

### Lát 1 — Bộ lọc trạng thái giá (API)

**Thêm tham số** `price_status` vào `FrontpageListingRequest`:
`in:quoted,unquoted` (mảng tối đa 2, cùng dạng `form/explode:false` như các
tham số khác). Trong `buildScreenQuery()`: `quoted` = có `io_rate` hoặc
`floor_cpm` khác null và khác 0; `unquoted` = ngược lại. Chọn cả hai = không
lọc.

**Thêm nhóm đếm** vào `GET /api/v2/filters`:

```json
"price_status": { "quoted": 1, "unquoted": 103 }
```

Tính trong `getFilterAggregates()` cùng một truy vấn với các nhóm đếm khác —
không mở truy vấn thứ hai, vì mọi lượt tải `/explore` đều gọi endpoint này.

Giữ nguyên `price_range` dù nó đang là `{0, 0}`: bỏ đi là đổi hợp đồng đã
công bố trong `docs/openapi/v2.yaml`, và nó sẽ tự sống lại khi có giá.

**Chốt:** bộ lọc `price_status=quoted` trả đúng tập có giá · nhóm đếm khớp số
bản ghi lọc được · hai tham số cùng lúc = không lọc · tham số lạ trả 422 ·
`/api/v1` không đổi một byte.

### Lát 2 — Cột lọc trái ở `/explore`

Hôm nay `/explore` đọc `city`, `venue_type`, `screen_type`, `sort` từ URL
nhưng **không vẽ bộ lọc nào** ngoài ô tìm kiếm lớn — người dùng chỉ lọc được
nếu họ tự sửa URL hoặc đi vòng qua ô tìm kiếm.

Dựng `BoLocKho.tsx`: năm nhóm — **trạng thái giá**, loại hình, tỉnh thành,
media owner, khác. Mỗi dòng kèm số đếm từ `/api/v2/filters`. Nhóm nào đếm 0
thì hiện mờ và vô hiệu, **không ẩn**: một loại hình hiện 0 nói với người mua
rằng sàn biết loại đó tồn tại nhưng chưa có hàng; ẩn nó đi thì người mua
tưởng sàn không bán loại đó.

Lọc đi qua **URL**, không qua state — giữ đúng nguyên tắc của trang hiện tại:
mỗi bộ lọc là một URL chia sẻ được, quay lại được, index được.

Responsive theo đúng thang đã dựng:

| Bề ngang | Cột lọc |
|---|---|
| từ 900px | cột trái 232px, `position: sticky` |
| dưới 900px | nút "Bộ lọc" mở ngăn kéo từ dưới lên, cùng cơ chế `MenuTruot` |

Hàng chip của bộ lọc đang bật nằm trên lưới kết quả, mỗi chip bấm được để bỏ.

**Chốt:** mỗi ô lọc đổi đúng một tham số URL · số đếm khớp `/api/v2/filters` ·
nhóm 0 kết quả bị vô hiệu chứ không biến mất · dưới 900px bộ lọc vẫn tới được
· vùng chạm ≥ 44px.

### Lát 3 — Bản đồ cạnh danh sách

Khung ba cột từ **1180px**: lọc 232px · kết quả 1fr · bản đồ 400px dính theo
cuộn. Dưới 1180px bản đồ thành một kiểu xem trong bộ nút đổi kiểu xem đã có
(`KhoiKetQua`), chiếm toàn bộ chiều ngang.

Pin lấy từ `GET /api/v2/screens/map` với **đúng bộ tham số lọc của danh sách**
cộng khung nhìn hiện tại — endpoint đã hỗ trợ sẵn, không sửa backend. Nghĩa là
bản đồ hiện **mọi** vị trí khớp bộ lọc (tới 500 pin), không chỉ 24 thẻ của
trang hiện tại; `meta.truncated` nói cho người dùng biết khi bị cắt.

Hai chiều liên kết: rê chuột lên thẻ thì pin tương ứng sáng; bấm pin thì cuộn
tới thẻ và tick chọn. Bản đồ không phải trang trí — nó là cách chọn thứ hai.

`/map` giữ nguyên: nó là bản đồ toàn kho, khác với bản đồ của một tập lọc.

**Chốt:** pin đếm khớp `meta.total` của cùng bộ lọc · đổi bộ lọc thì pin đổi
theo · dưới 1180px không có cột bản đồ nào chiếm chỗ · trang không cuộn ngang
ở 390px.

### Lát 4 — Danh sách rút gọn (phía trình duyệt)

Ô tick trên mỗi thẻ và trên mỗi pin. Thanh đáy hiện khi có ít nhất một mục:
số vị trí, số tỉnh thành, số media owner, ba nút — *Bỏ chọn*, *Tải danh sách*,
**_Yêu cầu báo giá_**.

Lưu ở `localStorage` theo `slug`, **không cần đăng nhập**. Lý do: bắt đăng
nhập trước khi người ta kịp khoanh năm vị trí là mất họ ở bước rẻ nhất. Danh
sách sống qua chuyển trang và qua lần mở lại, và chỉ được đọc lại trong
`useEffect` — đọc lúc render thì HTML hai phía lệch nhau, đúng lỗi
`KhoiKetQua` đã ghi lại.

Giới hạn cứng **50 vị trí** một danh sách, đúng bằng `MAX_PER_PAGE`.

**Chốt:** tick rồi tải lại trang thì danh sách còn · tick ở thẻ và ở pin là
cùng một trạng thái · chặn ở mục thứ 51 · `localStorage` bị chặn thì trang vẫn
chạy, chỉ mất tính nhớ.

### Lát 5 — Yêu cầu báo giá: CSDL, API, Filament

Lát lớn nhất, và là lát duy nhất tạo bảng mới.

**Hai bảng** (`char(26)` ULID, trait `HasUlids`, `Schema::hasTable` trước khi
tạo — CLAUDE.md mục 6):

- `quote_requests` — người gửi (tên, email, điện thoại, tổ chức),
  `organization_id` nếu đã đăng nhập, cửa sổ chiến dịch, ngân sách dự kiến,
  ghi chú, `status`, mã tra cứu, IP + user agent để chống spam.
- `quote_request_items` — một dòng một màn hình, mang `owner_id` **tại thời
  điểm gửi** (owner có thể đổi sau; yêu cầu đã gửi thì không đổi — CLAUDE.md
  mục 6: không sửa dữ liệu lịch sử).

**Một yêu cầu, nhiều owner.** Người mua gửi 12 vị trí của 3 owner là **một**
hành động của họ, nhưng là **ba** việc phải trả lời. Nên dữ liệu lưu một
yêu cầu, còn mỗi owner chỉ thấy phần của mình — scope qua `owner_id` của
dòng, theo đúng `HasOwnerScope` (chặn mặc định khi không xác định được
tenant). Owner A không bao giờ biết yêu cầu đó còn hỏi owner B, và **không
bao giờ thấy email hay điện thoại người mua cho tới khi quản trị duyệt** —
nếu không, sàn tự biến mình thành danh bạ khách hàng miễn phí.

**Endpoint công khai** `POST /api/v2/quote-requests`:

- `throttle:5,60` — cùng hạn mức `POST /api/v2/reflections`, cùng lý do: đường
  ghi mở cho người lạ.
- Tối đa 50 mục, validate từng slug có thật và đang công khai.
- Bắt buộc đồng ý chính sách bảo mật (`PolicyConsentService` đã có).
- Ô mồi ẩn (honeypot) chống bot.
- Trả mã tra cứu, **không** trả lại thông tin owner.
- Ghi nhật ký: ai, lúc nào, bao nhiêu mục (CLAUDE.md mục 5).

**Filament**: resource ở `/admin` (thấy toàn bộ) và ở `/publisher` (chỉ phần
của mình, kiểm qua `TenantPermission::check()`), logic chung đặt ở
`app/Filament/Shared/Resources/BaseQuoteRequestResource` theo đúng CLAUDE.md
mục 4.

> **Chưa gửi được email, và đó không phải lỗi của lát này.** Production đang
> `MAIL_MAILER=log` — mọi thư đi vào tệp log, không ra khỏi máy chủ. Nên lát
> này giao **hộp thư trong ứng dụng**: owner thấy yêu cầu ở `/publisher`,
> quản trị thấy ở `/admin`. Thông báo qua email là một lát riêng, sau khi có
> SMTP thật. Dựng tính năng phụ thuộc email trước khi email chạy được là dựng
> một tính năng không ai nhận được.

**Chốt:** yêu cầu 12 mục của 3 owner tạo đúng 12 dòng, chia đúng 3 nhóm ·
owner A truy vấn không thấy dòng của owner B (chốt rò rỉ, chạy thật trên
MySQL) · liên hệ người mua không lộ trước khi duyệt · mục thứ 51 trả 422 ·
slug không công khai trả 422 · quá hạn mức trả 429 · `{error, message, code,
details[]}` đúng định dạng.

### Lát 6 — Màn hình gửi yêu cầu

`/explore/yeu-cau-bao-gia`: danh sách đã chọn (sửa được, bỏ được), nhóm theo
media owner để người mua thấy yêu cầu này đi tới mấy nơi, biểu mẫu liên hệ,
cửa sổ chiến dịch, ngân sách, ghi chú. Đã đăng nhập thì điền sẵn từ
`/api/v2/me`.

Màn hình xác nhận nói đúng ba điều: **gửi tới ai**, **mã tra cứu**, **khi nào
có trả lời**. Câu thứ ba cần chủ dự án chốt một cam kết thật (xem bảng quyết
định) — viết "chúng tôi sẽ liên hệ sớm" là một lời hứa không đo được.

**Chốt:** gửi xong danh sách rút gọn được dọn · bấm hai lần không tạo hai yêu
cầu · lỗi mạng giữa chừng không mất danh sách đã chọn.

### Lát 7 — Tải danh sách về máy

`GET /api/v2/quote-requests/{ma}/tep` và nút tải thẳng từ thanh đáy.

Mock vẽ `.xlsx`. Đề xuất **CSV UTF-8 có BOM** thay vì xlsx: Excel mở trực
tiếp, không thêm thư viện nào vào `composer.json`, và agency dán thẳng vào
bảng kế hoạch của họ. Nếu chủ dự án cần đúng `.xlsx` (có định dạng, có logo)
thì đó là một lát riêng kèm một thư viện mới.

## Ba thứ trong mock sẽ KHÔNG lên trang công khai

**1. Nhãn cảnh báo dữ liệu trên thẻ.** Mock in *"Tên nói Aeon Mall Tân Phú
(TP.HCM) nhưng điểm đặt và toạ độ ở Bắc Kạn"* và *"23,04 × 28,8 m = 663 m²,
nhiều khả năng nhập nhầm đơn vị"*. Hai nhãn đó là **chú thích cho người
duyệt mock**, không phải tính năng. In chúng ra trang bán hàng là tự nói với
người mua rằng dữ liệu của sàn không đáng tin — và người mua không sửa được
gì sau khi đọc.

Chỗ đúng của chúng là một **báo cáo chất lượng dữ liệu trong `/admin`**: lệch
tên ↔ toạ độ, kích thước bất thường, thiếu giá, thiếu ảnh. Đề nghị tách thành
một việc riêng; chủ dự án đã có nó trong hàng đợi dưới tên *soát lỗi ghép vị
trí*.

**2. Bộ lọc "Có ảnh thực địa".** Hôm nay nó trả 0 kết quả cho mọi lượt bấm.
Một bộ lọc luôn rỗng là một bộ lọc hỏng dưới mắt người dùng. Nó nên xuất hiện
cùng lúc với tấm ảnh thực địa đầu tiên, không sớm hơn.

**3. Sắp xếp "Giá thấp → cao".** Với 1/104 bản ghi có giá, thứ tự này không
sắp xếp được gì. Giữ nó trong ô chọn, nhưng khi bộ lọc `unquoted` đang bật
thì vô hiệu hoá.

## Rủi ro

| Rủi ro | Mức | Xử lý |
|---|---|---|
| Owner không trả lời yêu cầu, người mua chờ vô hạn | **cao** | Lát 5 ghi mốc thời gian; `/admin` lọc được yêu cầu quá hạn. Cam kết thời gian trả lời cần chủ dự án chốt |
| Rò liên hệ người mua sang owner | cao | Chốt rò rỉ chạy thật trên MySQL ở lát 5; liên hệ chỉ mở sau khi duyệt |
| Spam vào đường ghi công khai | vừa | `throttle:5,60` + honeypot + ghi IP; cùng công thức `reflections` đang chạy |
| 500 pin trên một bản đồ làm chậm máy yếu | vừa | Giới hạn cứng đã có; gom cụm pin khi quá 150 |
| `localStorage` bị chặn | thấp | Mọi lần đọc/ghi trong `try/catch`, trang chạy không cần nó |

## Bốn việc cần chủ dự án chốt

| Việc | Text copy để duyệt |
|---|---|
| Khách chưa đăng nhập có gửi được yêu cầu báo giá không (đề xuất: **có** — bắt đăng nhập trước khi hỏi giá là mất người mua ở bước rẻ nhất) | `khách ẩn danh gửi được` |
| Cam kết thời gian trả lời in trên màn xác nhận (ví dụ *"owner trả lời trong 2 ngày làm việc"*) | `thời gian trả lời: <số> ngày làm việc` |
| Owner thấy liên hệ người mua ngay, hay chỉ sau khi quản trị duyệt (đề xuất: **sau khi duyệt**) | `liên hệ chỉ mở sau khi duyệt` |
| Tệp tải về: CSV (không thêm thư viện) hay `.xlsx` (thêm một lát và một thư viện) | `tệp tải về: csv` |

## Bắt đầu

| Việc | Text copy để duyệt |
|---|---|
| Làm lát 1 — bộ lọc trạng thái giá ở API | `làm lát 1` |
| Làm lát 1 và 2 trong một PR | `làm lát 1 và 2` |
