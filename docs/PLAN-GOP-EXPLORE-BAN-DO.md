# Gộp `/explore` và `/map` thành một trang

Viết 22/10/2026 · Claude Code · Chủ dự án chốt hướng ngày 22/10/2026
(*"chốt gộp bản đồ"*)

Mock đã duyệt: <https://claude.ai/artifact/T5EmRH5NeY3Gsf1PWaCW1k>
Mock bố cục desktop trước đó (kiểu xem Lưới lấy từ đây):
<https://claude.ai/artifact/4riLN97wjS6NBMFqWKsoCJ>

## Vì sao gộp

Hôm nay kho cùng một tập dữ liệu được nhìn qua **hai trang khác nhau**, và
người mua phải tự chọn trước khi biết mình cần gì:

| | `/explore` | `/map` |
|---|---|---|
| Bố cục | 3 cột: lọc 260 · kết quả 528 · bản đồ 340 | bản đồ toàn khung + panel trái |
| Bộ lọc | `BoLocKho` (405 dòng) | `BoLocThongMinh` (330 dòng) |
| Bản đồ | `BanDoKetQua` (176 dòng) | `ScreenMap` (398 dòng) |
| Danh sách | lưới thẻ, phân trang 24 | gom theo site, theo khung nhìn |
| Máy chủ render danh sách | **có** | không |

Hai bộ lọc, hai bản đồ, hai cách bày danh sách — cho **một** kho 104 vị trí.
Mỗi lần thêm một chiều lọc là sửa hai chỗ, và hai chỗ đó đã trôi khỏi nhau:
`/map` có thanh tỉnh thành và gom theo site, `/explore` có trạng thái giá và
danh sách rút gọn. Không bên nào có đủ.

Số đo trên `oohx.net/explore` ở cửa sổ 1440×900 (đọc bằng
`getBoundingClientRect()`, 21/10/2026):

| Phần | Rộng | % của 1176px |
|---|---|---|
| Cột lọc, cao **1358px** | 260 | 22% |
| Lưới kết quả — 2 thẻ 255px | 528 | 45% |
| Cột bản đồ | 340 | 29% |

Cột lọc cao hơn cả khung nhìn, phục vụ 23 ô tick mà người mua chạm vài lần
rồi thôi. Nội dung chính của trang nhận chưa tới một nửa chiều ngang.

## Bố cục đã chốt, đo từ Google Maps

Mở `google.com/maps` ở đúng 1440×900, tìm *"billboard ho chi minh"*, bấm kết
quả đầu, đo:

| Thành phần | x | Rộng |
|---|---|---|
| Canvas bản đồ | 0 | **1440** — trải hết, nằm **dưới** mọi thứ |
| Rail biểu tượng | 0 | 72 |
| Thẻ kết quả | 72 | 408 |
| Thẻ chi tiết | 496 | 408 — mở **cạnh**, không đè danh sách |
| Bản đồ còn thấy khi mở chi tiết | 904→1440 | 536 |

Bốn thói quen mượn:

1. **Bản đồ không bao giờ co lại.** Nó nằm dưới; panel che bớt chứ không đẩy.
2. **Chi tiết mở cạnh danh sách**, nên không mất chỗ đang đứng trong danh sách.
3. **"Tìm trong khu vực này"** nổi giữa trên: kéo bản đồ *không* tự nạp lại.
4. **Mỗi trạng thái một URL**, quay lại được bằng nút Back.

Hai thứ **không** mượn: rail 72px (OOHX đã có thanh điều hướng trên cùng) và
chip lọc nổi trên bản đồ (lọc của mình gắn chặt với danh sách, nên nằm trong
đầu panel).

Bố cục OOHX, dưới thanh điều hướng 52px: panel danh sách `left:16px`
rộng 400, panel thứ hai (chi tiết **hoặc** bảng tổng kế hoạch) `left:428px`
rộng 400, bản đồ trải hết phía sau.

## Rủi ro lớn nhất, và cách chặn

> **Gộp sai cách thì trang danh mục nặng nhất của sàn biến mất khỏi Google.**

`/explore` hôm nay render sẵn 24 thẻ thành chữ trong HTML máy chủ trả về.
`/map` cố ý **không** làm thế — docblock của `webapp/app/map/page.tsx` viết rõ
lý do: *"hai trang cùng dữ liệu mà cùng được index là tự tạo nội dung trùng"*.

Nếu trang gộp để danh sách xuất hiện sau khi bản đồ chạy xong và hỏi pin, thì
công cụ tìm kiếm thấy một trang rỗng. Cái giá đó không hiện ra lúc xem thiết
kế; nó hiện ra sau vài tuần, và lúc ấy khó truy ngược.

**Ba hàng rào, cả ba phải có:**

1. **Panel danh sách vẫn là server component.** Trang gọi `listScreens()` như
   hiện nay và trả 24 mục thành HTML *trong panel*. Bản đồ nạp sau và chỉ
   **đổi** danh sách khi người dùng bấm "Tìm trong khu vực này".
2. **Kiểu xem Lưới giữ nguyên phân trang, chân trang, liên kết nội bộ** — thứ
   một trang ứng dụng toàn màn hình không có chỗ chứa. Cùng URL, tham số
   `?view=grid`.
3. **Chốt tự động** canh rằng HTML máy chủ của `/explore` chứa tên màn hình:
   mở rộng `webapp/test/khoi-giao-dien.mjs`, ca mới đỏ nếu danh sách rơi
   xuống phía trình duyệt.

## Những gì đã có sẵn, không phải dựng lại

Khảo sát mã nguồn trước khi chia lát — phần khó đã làm xong từ lát 3 của plan
trước:

- **Bản đồ và danh sách đã nói chuyện với nhau.** `KhoiExplore` (164 dòng) đã
  có uỷ nhiệm rê chuột qua `data-slug`, bấm pin thì cuộn tới thẻ, và đã giữ
  được nguyên tắc "trang là server component, thẻ dựng ở máy chủ".
- **Bản đồ của tập kết quả đã dùng chung bộ lọc.**
  `FrontpageService::mapPinQuery()` gọi `buildScreenQuery($request)`, nên
  `GET /api/v2/screens/map` nhận y hệt tập tham số của `GET /api/v2/screens`.
  **Không cần sửa backend cho việc gộp.**
- **Ngăn kéo hai bậc trên mobile** (#145) chính là panel trái ở màn hẹp. Giữ
  nguyên `dung-keo-sheet.ts`, không đụng.
- **Danh sách rút gọn** đã có: `dung-danh-sach.ts` + `ThanhDanhSach` +
  `OTick`. Lát 6 nâng nó lên thành bảng tổng, không viết lại từ đầu.
- **`MapPin` giàu hơn tôi tưởng.** Gọi thật trên production, pin đã có
  `price` (kèm `unit` và `currency`), `photo_url`, `address`, `site`,
  `owner`, `network`. Thiếu đúng **hai** trường so với thẻ hiện nay:
  `screen_type_label` và `size`.

  *Sửa lại một câu trong mock:* tôi có viết ở đó rằng cần thêm tham số
  `fields` cho endpoint bản đồ. **Không cần.** Thêm hai trường là đủ, và
  thêm trường là thay đổi cộng thêm, không phá hợp đồng.

## Bảy lát

Mỗi lát một PR, chạy CI đầy đủ, merge riêng. Thứ tự là thứ tự phụ thuộc.

| Lát | Nội dung | Đụng gì | Ước lượng |
|---|---|---|---|
| 1 | Hai trường thêm cho `MapPin` | Laravel + OpenAPI + types | nhỏ |
| 2 | Khung trang gộp, hai kiểu xem | Next + CSS | **lớn** |
| 3 | Bộ lọc vào đầu panel | Next + CSS | vừa |
| 4 | "Tìm trong khu vực này" + dải tổng quan | Next | vừa |
| 5 | Thẻ chi tiết mở cạnh danh sách | Next + CSS | vừa |
| 6 | Thêm vào kế hoạch + bảng tổng | Next | **lớn** |
| 7 | Khai tử `/map`, dọn mã và CSS | Next + Laravel + CSS | vừa |

### Lát 1 — Hai trường thêm cho `MapPin`

`ScreenMapResource` thêm `screen_type_label` (bảng chữ đã sống ở
`ScreenSummaryResource::SCREEN_TYPE_LABELS` — dùng lại, không chép) và `size`
(`width_m`, `height_m`).

Khai vào `docs/openapi/v2.yaml` rồi `npm run types:api`. Hai trường này nhân
lên tới 500 pin một lượt, nên docblock của schema phải nói vì sao đáng:
không có chúng thì sau một lần "Tìm trong khu vực này", thẻ trong panel mất
loại hình và kích thước — tức danh sách **nghèo đi** khi người dùng thao tác,
đúng chiều ngược với kỳ vọng.

Chốt: ca hiện có của `screens/map` thêm khẳng định hai khoá; một ca canh
`screen_type_label` khớp bảng chữ của `ScreenSummaryResource`.

### Lát 2 — Khung trang gộp, hai kiểu xem

Lát nặng nhất. `webapp/app/explore/page.tsx` đổi từ `.listing-layout
.ex-layout` (3 cột) sang khung bản đồ-nằm-dưới:

- `.ex-app` cao `calc(100dvh - var(--hdr))`, `position:relative`, bản đồ
  `position:absolute;inset:0`.
- `.ex-panel` nổi: `left:16px`, rộng 400, `top/bottom:12px`, bo 14px, bóng.
- Tham số `view` (`map` | `grid`) đọc từ `searchParams`, nút đổi kiểu xem ở
  thanh điều hướng, và **cả hai kiểu xem đều render thẻ ở máy chủ**.
- Kiểu xem `grid` dùng lại đúng bố cục đã duyệt ở mock trước: thanh lọc ngang
  + lưới 3–4 cột + phân trang + chân trang.

`BanDoKetQua` đổi từ "cột phải, chỉ mount ≥1280px" thành "nền của trang, mount
khi `view=map`". Mốc `MOC_BA_COT = '(min-width: 1280px)'` trong `KhoiExplore`
đổi nghĩa: dưới 1024px panel là ngăn kéo (#145), trên 1024px là thẻ nổi.

Chốt: `khoi-giao-dien.mjs` thêm ca "HTML máy chủ của `/explore` chứa tên màn
hình" cho **cả hai** giá trị của `view` — hàng rào số 3 ở trên.

### Lát 3 — Bộ lọc vào đầu panel

`BoLocKho` đổi hình dạng, giữ nguyên hợp đồng URL: vẫn là một `<form>` thật,
vẫn `city=hanoi,hcm` phân tách bằng dấu phẩy, vẫn submit được không cần JS.

- Năm nhóm (`Trạng thái giá`, `Loại hình`, `Tỉnh thành`, `Loại điểm đặt`,
  `Media owner`) thành năm chip; bấm chip mở bảng rớt chứa đúng các ô tick
  đang có.
- Chip đang lọc hiện giá trị và nút gỡ; một chip `Bộ lọc n` mở tất cả.
- Ở kiểu xem Lưới, cùng component, cùng chip, đặt trên lưới.

Vùng chạm ≥ 44px — chip cao 38px thì phần chạm phải nới bằng padding, không
bằng chiều cao nhìn thấy.

### Lát 4 — "Tìm trong khu vực này" + dải tổng quan

Hôm nay bản đồ nạp lại mỗi khi khung nhìn đổi. Đổi sang: kéo bản đồ thì hiện
nút nổi giữa trên; bấm mới nạp. Lý do là lý do của Google — nạp theo mỗi cú
kéo làm danh sách nhảy dưới tay người đang đọc nó.

Dải bốn ô ngay dưới ô tìm, tính từ tập pin đang có trong khung nhìn:

| Ô | Nguồn |
|---|---|
| Số vị trí trong khung | `meta.total` của `screens/map` |
| Đã báo giá | đếm pin có `price.amount` |
| Khoảng giá tháng | min–max của `price.amount` khi `unit` là kỳ |
| Lượt xem/ngày | **để chữ xám** — tổng của những số ước tính |

Ô thứ tư cố ý nhạt: 83/104 màn hình đang mang **cùng một** giá trị template
25.000 (xem mục "Số liệu đo đếm" ở lộ trình ngày 21/10). In nó như số thật là
mời người mua mất lòng tin.

### Lát 5 — Thẻ chi tiết mở cạnh danh sách

Panel thứ hai ở `left:428px`. Nội dung lấy từ `/explore/{slug}` đang có, rút
gọn: ảnh, tên, bốn hành động, bốn ô số liệu, các hàng thông tin, khối dự báo.

**URL phải đổi** khi mở — `/explore/{slug}` — và nút Back đóng panel. Đó là
thói quen số 4 mượn từ Google Maps, và nó cũng là thứ giữ cho trang chi tiết
tiếp tục được index: cùng một đường dẫn, cùng một nội dung máy chủ dựng, chỉ
khác là có khung bản đồ bao quanh.

### Lát 6 — Thêm vào kế hoạch + bảng tổng

Hai phần, cùng một PR vì phần sau vô nghĩa nếu thiếu phần trước.

**Thêm vào kế hoạch, ba chỗ:** nút chữ cạnh giá trong danh sách (thay ô tick
24px hiện nay), nút chính trong thẻ chi tiết, và bộ đếm `Kế hoạch n` ở thanh
điều hướng — nhìn thấy cả khi đang ở kiểu xem Lưới. Ghim của vị trí đã thêm
đổi màu, nên nhìn bản đồ là thấy kế hoạch phủ đâu, bỏ trống đâu.

**Bảng tổng** mở ở đúng chỗ thẻ chi tiết (bản đồ vẫn còn 536px):

- Bốn ô: số vị trí · số quận/huyện phủ · lượt xem theo số ngày · **CPM gộp**.
- Ô nhập *số ngày chạy* và *ngân sách trần*; đổi là mọi con số tính lại.
- Thanh phân bổ theo loại hình và theo quận.
- Danh sách đã chọn, bỏ được từng dòng.

> **Một con số không được phép cộng thẳng: reach.**
> Người đi làm qua biển Chợ Bến Thành rồi qua biển Lý Tự Trọng–Pasteur cách
> 700m bị đếm **hai lần**. Cộng reach của các vị trí gần nhau ra một con số
> lớn hơn sự thật, và đó đúng là con số agency mang đi báo cáo cho nhãn hàng.
> Bảng gọi nó là **tổng reach thô**, kèm một dòng nói rõ reach thật phải khử
> trùng lặp theo vùng và sàn chưa làm được. Lượt xem và chi phí cộng bình
> thường.

Chốt tĩnh: `tests/js/` thêm ca cho phép tính của bảng tổng (chi phí theo số
ngày, CPM gộp, bỏ qua vị trí chưa báo giá), và một ca canh rằng chữ "reach
thô" cùng câu cảnh báo còn nguyên — xoá câu đó là đổi nghĩa con số.

### Lát 7 — Khai tử `/map`, dọn mã và CSS

- `webapp/app/map/page.tsx` → chuyển hướng **301** sang `/explore`. Vĩnh
  viễn, vì đường dẫn không quay lại. Không mất backlink nào.
- Gỡ `ScreenMap.tsx` (398), `BoLocThongMinh.tsx` (330) và CSS `.map-layout`,
  `.map-panel`, `.mp-*`, `.mt-*` — sau khi kiểm `resources/views/` không có
  tệp Blade nào dùng, như đã làm ở #145.
- `.mp-drag` biến mất nhưng `.drawer-drag` ở lại: ngăn kéo hai bậc giờ chỉ
  còn một chỗ dùng. Sửa chốt `sheet-keo-duoc.test.mjs` cho khớp, **không** nới
  nó thành không canh gì.
- `sitemap.xml` bỏ `/map`.

## Hai việc chủ dự án cần chốt, có thể chốt sau lát 2

| Việc | Hai ngả | Ảnh hưởng tới lát |
|---|---|---|
| **Kiểu xem mặc định** khi vào `/explore` không kèm tham số | Lưới cho mọi người · hoặc Lưới cho khách đến từ tìm kiếm và Bản đồ cho khách bấm trong site | Lát 2 |
| **Kế hoạch lưu ở đâu** | `localStorage` như hôm nay (mất khi đổi máy) · hoặc lưu máy chủ để chia sẻ link được, tức phải đăng nhập | Lát 6 |

Khuyến nghị của tôi: **mặc định Lưới** cho mọi người ở đợt đầu — một kiểu cho
tất cả thì đo được, và nếu phân nhánh theo nguồn truy cập thì không biết con
số nào thuộc về ai. Kế hoạch **giữ ở trình duyệt** ở đợt này; đưa lên máy chủ
là một PR riêng có CSDL, không nên gánh vào lát 6.

## Rủi ro

| Rủi ro | Chặn bằng |
|---|---|
| Danh sách rơi xuống phía trình duyệt → mất index | Ca chốt ở lát 2, chạy mỗi PR |
| Giới hạn 500 pin: kho lớn lên thì bản đồ cắt bớt mà danh sách thì không | `meta.truncated` đã có sẵn — hiện một dòng "còn n vị trí ngoài khung" |
| Hai kiểu xem thành hai bộ mã song song, đúng cái bệnh đang chữa | Cùng một `BoLocKho`, cùng một `ScreenCard`, chỉ khác lớp bọc |
| Trang nặng hơn vì Leaflet nằm trên đường chính | Bản đồ chỉ mount khi `view=map`; kiểu xem Lưới không tải Leaflet |
| Mobile hỏng trong lúc đổi khung | Không đụng `dung-keo-sheet.ts`; đo lại ở 390×844 trước mỗi merge |

## Bắt đầu

Lát 1 chạy được ngay. Hai câu hỏi ở mục trên chỉ chặn lát 2 và lát 6, không
chặn lát 1.

---

# Phần bổ sung: quy mô 10.000 vị trí

Viết 22/10/2026, sau khi bảy lát trên đã merge.

Chủ dự án cho biết kho thật có **hơn 10.000 billboard trải dài toàn quốc**,
vào qua API đối tác. Mọi con số trong plan trên đo trên **104 bản ghi** của
production hôm nay — gấp 96 lần là một bậc khác, không phải một con số lớn hơn.

## Thứ tôi KHÔNG biết, và nó quan trọng hơn thứ tôi biết

**Không có bộ dữ liệu 10.000 nào để đo.** Mọi chẩn đoán dưới đây là **giả
thuyết đọc từ mã nguồn**, không phải phép đo. Vài cái sẽ sai.

Nên lát đầu tiên không sửa gì cả — nó dựng chỗ để đo. Tối ưu một truy vấn
chưa ai bấm giờ là cách chắc chắn nhất để sửa đúng thứ không đau và bỏ qua
thứ đau.

### Lát 0 — dựng 10.000 bản ghi giả và bấm giờ

Một lệnh artisan `oohx:gia-lap-kho {so=10000}` đổ dữ liệu vào **CSDL tạm của
Docker**, không bao giờ chạm production (công thức ở
`docs/audit-5-vung-2026-09-23/REPRODUCE-CLAUDE.md`). Phân bố phải giống kho
thật, không phải phân bố đều:

- toạ độ **dồn cục quanh vài đô thị** — đó là hình dạng làm gom cụm khó, phân
  bố đều thì không;
- ~18% có giá (hôm nay 19/104);
- ~20% có `city` dạng `"Tỉnh > Phường"` (hôm nay 21/104);
- `district` rỗng hết, như hôm nay.

Rồi bấm giờ **sáu đường**, kèm `EXPLAIN`, và ghi số vào chính tệp này:

| Đường | Vì sao nó đáng ngờ |
|---|---|
| `/screens/map` khung cả nước | trần 500 + `countMapPins` chạy thêm một `COUNT(*)` trên cùng bộ lọc |
| `/screens/map` khung một quận | `whereHas('site')` có cho optimizer dùng `(lat,lon)` để **cắt** không |
| `/screens?sort=distance` trang 1 | Haversine trên mọi hàng khớp bộ lọc |
| `/screens?sort=distance` trang cuối | Haversine + `OFFSET` sâu |
| `/screens?q=…` | ba phép khớp chuỗi con, hai trong một `EXISTS` tương quan |
| `/filters` lần đầu (cache nguội) | các phép gộp trên toàn bảng |

**Tiêu chí:** đường nào trên 300ms thì vào danh sách sửa; dưới thì để yên và
ghi lại con số, kể cả khi nó trông xấu.

### Chỉ mục hiện có — không thiếu, nhưng thiếu đúng chỗ

Soát migration trước khi đoán, vì "thiếu index" là chẩn đoán dễ nói nhất và
hay sai nhất. Thực tế đã có:

| Bảng | Chỉ mục |
|---|---|
| `sites` | `(lat, lon)`, `city`, `(owner_id, status)`, `province_id`, `commune_id` |
| `screens` | `active`, `(active, site_id)`, `(owner_id, active, updated_at)`, `(owner_id, site_id)` |
| `screen_inventory` | `(screen_id, venue_type)`, `(screen_id, floor_cpm)`, `floor_cpm` |

Nên lọc theo tỉnh (khớp tiền tố trên `sites.city` — dùng được index) và lọc
theo loại địa điểm đều đã có đường. **Chỗ trống đúng là ba chỗ:** không chỉ
mục nào phục vụ được phép sắp theo Haversine, `whereHas` không cho `(lat,lon)`
lái truy vấn bản đồ, và phép khớp chuỗi con của ô tìm kiếm thì không index nào
giúp được.

## Giả thuyết 1 — cái sai NẶNG nhất không phải chậm, mà là SAI

`getMapPins` cắt bằng `orderBy('screens.id')` rồi `limit(500)`
(`CatalogController::MAX_MAP_PINS = 500`).

Ở 104 bản ghi, 500 không bao giờ chạm tới. Ở 10.000, một người mở bản đồ ở
khung cả nước nhận về **500 bản ghi có id nhỏ nhất** — tức 500 vị trí **nhập
vào sớm nhất**. Không phải 500 cái gần nhất, không phải 500 cái tiêu biểu,
không phải một mẫu ngẫu nhiên. Kho mới nhập **không tồn tại** trên bản đồ cho
tới khi người dùng thu nhỏ khung nhìn xuống.

`meta.truncated` có nói là đã cắt. Nó **không** nói rằng phần bị cắt có hệ
thống. Một người mua rà cả nước sẽ kết luận sai về độ phủ của sàn, và không
có gì trên màn hình nói cho họ biết.

> Đây là loại lỗi tệ nhất trong cả danh sách: nó không chậm, không đỏ, không
> có dòng log nào. Nó chỉ trả lời sai.

**Lát A — gom cụm ở máy chủ.** Dưới một mức thu phóng ngưỡng, endpoint trả
**ô lưới** chứ không trả pin: gom theo toạ độ đã làm tròn tới `n` chữ số thập
phân với `n` theo mức thu phóng, mỗi ô một tâm và một số đếm. Không còn gì bị
cắt, vì không còn trả về từng pin — và `countMapPins` biến mất, số đếm là tổng
các ô.

Trên ngưỡng đó thì trả pin như hôm nay. Trần 500 ở lại, nhưng ở mức thu phóng
gần thì 500 là một con số người ta không chạm tới.

Hệ quả phải xử: `gomTheoSite` (`webapp/lib/dung-ban-do.ts`) đang gom pin thành
cụm site ở phía trình duyệt. Khi máy chủ trả ô lưới thì client không gom nữa
mà vẽ thẳng — **hai chế độ**, và hình dạng phản hồi phải nói rõ nó đang ở chế
độ nào, không để client đoán theo số lượng.

## Giả thuyết 2 — phép sắp theo khoảng cách không có chỉ mục nào dùng được

`sort=distance` (#160) tính khoảng cách cho **mọi hàng khớp bộ lọc** rồi sắp.
Ở 104 hàng thì không đo được gì; ở 10.000 thì mỗi lượt là 10.000 phép lượng
giác cộng một `filesort`.

`sites` **đã có** chỉ mục `(lat, lon)`. Nó không giúp gì cho việc sắp theo
Haversine — không chỉ mục B-tree nào giúp được — nhưng nó phục vụ tốt một
**lọc thô bằng khung chữ nhật** đặt trước.

**Lát B — khung chữ nhật trước, khoảng cách sau.** Thêm `near_km` (mặc định ví
dụ 50): lọc `lat BETWEEN` và `lon BETWEEN` trước — dùng chỉ mục, cắt 10.000
xuống vài trăm — rồi mới tính khoảng cách trên phần còn lại. `khungQuanh` ở
`webapp/lib/vi-tri.ts` đã có đúng phép tính đó, kể cả cái sàn cho `cos(lat)`;
phía máy chủ cần bản sinh đôi của nó.

**Đổi ngữ nghĩa, nên cần chốt:** hôm nay `sort=distance` trả *toàn bộ* tập
khớp bộ lọc, gần nhất trước, nên nó **không bao giờ rỗng** — trang đầu luôn là
24 cái gần nhất, dù cái cuối cách 1.148km (số đo thật ngày 22/10). Có bán kính
thì nó rỗng được, và giao diện phải nói ra (lưới an toàn số 2 của lát định vị
đã có sẵn cách nói).

## Giả thuyết 3 — `whereHas` không cho optimizer bắt đầu từ `sites`

`mapPinQuery` lọc khung nhìn bằng `whereHas('site', …)`, sinh ra một
`EXISTS` tương quan đọc `sites` theo `screens.site_id` rồi mới kiểm hai khoảng
`lat` / `lon`. MySQL chạy từ `screens` và dò `sites` theo khoá chính cho
**từng** hàng — tức `(lat, lon)` chỉ được dùng để *kiểm tra*, không để *cắt*
tập.

Lọc theo địa lý mà quét bảng màn hình là ngược chiều: `sites` ít hàng hơn
`screens` và có chỉ mục không gian sẵn.

**Lát C — đổi `whereHas` thành `join`** cho riêng đường bản đồ, để optimizer
có thể bắt đầu từ `sites`. Lát 0 phải chạy `EXPLAIN` trước và sau; **nếu kế
hoạch truy vấn không đổi thì đừng đổi mã** — một lần viết lại không có số đo
đi kèm chỉ là thay một hình dạng bằng một hình dạng khác.

Cẩn thận một chỗ: #160 đã join `sites as sort_site` và làm cột `name` nhập
nhằng, trả 500. Thêm join thứ hai vào cùng truy vấn là đúng chỗ bẫy đó lặp lại.

## Giả thuyết 4 — ô tìm kiếm chữ

`q` chạy ba phép khớp chuỗi con, mỗi phép có ký tự đại diện đứng đầu: tên màn
hình, rồi địa chỉ và tỉnh của site trong một `EXISTS`. Ký tự đại diện đứng đầu
làm mọi chỉ mục B-tree vô dụng, và hai phép trong `EXISTS` chạy lại cho từng
hàng `screens`. Ở 10.000 có thể vẫn chấp nhận được; lát 0 trả lời.

**Lát D — chỉ làm nếu lát 0 đo ra quá 300ms.** Cách sửa là `FULLTEXT` trên
`screens.name` cộng `sites.address`, và nó **đổi cách khớp**: fulltext khớp
theo từ, khớp chuỗi con thì không. Gõ `nguyen` sẽ không còn khớp `nguyenvan`.
Đó là một quyết định về sản phẩm, không phải một lần tối ưu — và với tiếng
Việt có dấu thì cần kiểm bộ phân tách từ của MySQL trước khi hứa.

## Những con số của plan trên đã hết hạn

Bảy lát ở trên lập luận bằng dữ liệu 104 bản ghi. Ở 10.000 thì **phải đo
lại**, và vài quyết định có thể đảo chiều:

| Chỗ | Con số đã dùng | Vì sao nó có thể đảo |
|---|---|---|
| Bảng tổng: ô **quận/huyện** đổi thành **tỉnh thành** | `location.district` 0/104 | Kho đối tác có thể có quận; nếu có thì ô cũ đúng hơn |
| Ô **CPM gộp** để trống | `floor_cpm` 0/104 | như trên |
| Dải chip **cuộn ngang dưới 1024px** | 5 nhóm, 6 chip | Nhiều tỉnh hơn làm nhóm "Tỉnh thành" dài hơn, không đổi số chip — chỗ này giữ |
| **Mặc định Lưới** | — | Không liên quan tới số lượng — giữ |
| Phân trang 24/trang | 104 bản ghi = 5 trang | 10.000 = **417 trang** |

Dòng cuối đáng chú ý riêng. Ở 104 bản ghi, phân trang là một cách duyệt kho.
Ở 10.000 nó không còn là cách duyệt nào cả — bộ lọc và bản đồ mới là, và
`PhanTrangOnDinh::phanTrang()` dùng `LIMIT`/`OFFSET` thường, nên trang 400 bắt
MySQL bỏ qua 9.600 hàng đã sắp. Không ai bò tới đó trừ công cụ tìm kiếm, và
đúng chỗ đó là chỗ nó chậm nhất.

## Việc chủ dự án cần chốt

| Việc | Hai ngả |
|---|---|
| **`sort=distance` có bán kính không** | Giữ như hôm nay: không bao giờ rỗng, nhưng thẻ cuối trang đầu có thể cách 1.148km · hoặc thêm `near_km`: đúng chữ "quanh tôi" và dùng được chỉ mục, nhưng danh sách rỗng được ở vùng chưa có kho |
| **Thứ tự làm** | Lát 0 trước, rồi quyết theo số đo · hoặc làm thẳng lát A vì nó sửa một chỗ **sai**, không phải một chỗ chậm |

Khuyến nghị của tôi: **lát 0 trước, trừ lát A**. Lát A đáng làm kể cả khi số
đo nói mọi thứ đủ nhanh — nó không sửa tốc độ, nó sửa việc bản đồ trả lời sai.

## Rủi ro

| Rủi ro | Chặn bằng |
|---|---|
| Tối ưu mù — sửa thứ không đau | Lát 0 đi trước mọi lát khác trừ A |
| Dữ liệu giả không giống kho thật, số đo vô nghĩa | Toạ độ dồn cục quanh đô thị; tỉ lệ có giá và dạng `city` chép từ kho thật |
| Gom cụm máy chủ đổi hình dạng phản hồi, vỡ client | Phản hồi nói rõ chế độ; chốt đọc HTML và chốt JS canh cả hai chế độ |
| Thêm join làm cột nhập nhằng, trả 500 như #160 | Mọi cột trong truy vấn bản đồ viết đủ tiền tố bảng |
| Bán kính làm danh sách rỗng ở vùng chưa có kho | Lưới an toàn số 2 của lát định vị đã có cách nói |
| 10.000 bản ghi nhập vào mà `district` vẫn rỗng | Việc của plan nhập đối tác, không phải của plan này |
| Lệnh `oohx:gia-lap-kho` chạy nhầm vào production | Lệnh tự chặn khi `DB_HOST` không phải máy cục bộ, không chỉ dựa vào người gõ cẩn thận |
